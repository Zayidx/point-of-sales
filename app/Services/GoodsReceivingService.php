<?php

namespace App\Services;

use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\Ingredient;
use App\Models\Payable;
use App\Models\ProductBatch;
use App\Models\ProductWarehouse;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoodsReceivingService
{
    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService,
        private readonly InventoryLedgerService $inventoryLedgerService
    ) {}

    public function generateDocumentNumber(): string
    {
        $prefix = 'GR-'.now()->format('Ymd').'-';
        $last = GoodsReceiving::where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function receive(PurchaseOrder $order, array $items, ?string $notes, int $userId, string $requestKey): GoodsReceiving
    {
        $normalizedItems = collect($items)->map(fn (array $item) => [
            'purchase_order_item_id' => (int) $item['purchase_order_item_id'],
            'qty_received' => (string) $item['qty_received'],
            'qty_accepted' => (string) ($item['qty_accepted'] ?? $item['qty_received']),
            'qc_status' => $item['qc_status'] ?? 'good',
            'condition_notes' => $item['condition_notes'] ?? null,
            'notes' => $item['notes'] ?? null,
            'proof_hash' => $item['proof_hash'] ?? null,
        ])->sortBy('purchase_order_item_id')->values()->all();
        $payloadHash = hash('sha256', json_encode([
            'purchase_order_id' => $order->id,
            'notes' => $notes,
            'items' => $normalizedItems,
        ], JSON_THROW_ON_ERROR));

        // ponytail: retry only on document_number unique collisions (concurrent receipts pick the same next number)
        return retry(3, function () use ($order, $items, $notes, $userId, $requestKey, $payloadHash) {
            return DB::transaction(function () use ($order, $items, $notes, $userId, $requestKey, $payloadHash) {
                // ponytail: lock the order + its items so concurrent receipts cannot double-consume the same PO item
                $order = PurchaseOrder::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $existing = GoodsReceiving::where('request_key', $requestKey)->first();
                if ($existing) {
                    if ($existing->payload_hash !== $payloadHash || (int) $existing->purchase_order_id !== (int) $order->id) {
                        throw ValidationException::withMessages(['request_key' => 'Kunci penerimaan sudah digunakan untuk pengiriman berbeda.']);
                    }

                    return $existing;
                }

                $user = User::findOrFail($userId);
                $warehouse = $order->warehouse_id ? Warehouse::findOrFail($order->warehouse_id) : null;
                abort_unless($this->outletAccessService->canUseWarehouse($user, $warehouse), 403);

                if (! in_array($order->status, ['ordered', 'partial_received'])) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'Hanya PO berstatus ordered/partial yang dapat diterima.',
                    ]);
                }

                $receiving = GoodsReceiving::create([
                    'purchase_order_id' => $order->id,
                    'request_key' => $requestKey,
                    'payload_hash' => $payloadHash,
                    'supplier_id' => $order->supplier_id,
                    'warehouse_id' => $order->warehouse_id,
                    'document_number' => $this->generateDocumentNumber(),
                    'notes' => $notes,
                    'received_by' => $userId,
                    'received_at' => now(),
                ]);

                foreach ($items as $item) {
                    $poItem = $order->items->firstWhere('id', $item['purchase_order_item_id']);
                    if (! $poItem) {
                        throw ValidationException::withMessages([
                            'items' => 'Item tidak ditemukan di PO.',
                        ]);
                    }
                    $qtyReceived = (float) $item['qty_received'];
                    $qtySent = (float) $item['qty_sent'];
                    $qtyAccepted = (float) ($item['qty_accepted'] ?? $qtyReceived);

                    $outstanding = $poItem->qty_ordered - $poItem->qty_received;
                    if ($qtySent > $outstanding || $qtyReceived > $outstanding || $qtyReceived > $qtySent) {
                        throw ValidationException::withMessages([
                            'items' => "Qty diterima melebihi sisa item {$poItem->product_id}.",
                        ]);
                    }

                    if ($qtyAccepted < 0 || $qtyAccepted > $qtyReceived) {
                        throw ValidationException::withMessages(['items' => 'Jumlah lolos QC harus berada di antara nol dan jumlah diterima.']);
                    }
                    if ($poItem->product_id && (floor($qtyReceived) !== $qtyReceived || floor($qtyAccepted) !== $qtyAccepted)) {
                        throw ValidationException::withMessages(['items' => 'Jumlah produk jadi harus berupa bilangan bulat.']);
                    }

                    GoodsReceivingItem::create([
                        'goods_receiving_id' => $receiving->id,
                        'purchase_order_item_id' => $poItem->id,
                        'product_id' => $poItem->product_id,
                        'ingredient_id' => $poItem->ingredient_id,
                        'qty_received' => $qtyReceived,
                        'qty_sent' => $qtySent,
                        'qty_accepted' => $qtyAccepted,
                        'notes' => $item['notes'] ?? null,
                        'qc_status' => $item['qc_status'] ?? 'good',
                        'condition_notes' => $item['condition_notes'] ?? null,
                        'proof_path' => $item['proof_path'] ?? null,
                    ]);

                    $poItem->increment('qty_received', $qtyReceived);

                    if ($qtyAccepted <= 0) {
                        continue;
                    }

                    if ($poItem->ingredient_id) {
                        if (! $order->warehouse_id) {
                            throw ValidationException::withMessages(['warehouse_id' => 'Penerimaan bahan baku harus memiliki tujuan gudang.']);
                        }

                        $ingredient = Ingredient::with('baseUnit')->findOrFail($poItem->ingredient_id);
                        $this->inventoryLedgerService->record([
                            'idempotency_key' => "goods-receiving:{$receiving->id}:ingredient:{$ingredient->id}:po-item:{$poItem->id}",
                            'item_type' => 'ingredient',
                            'item_id' => $ingredient->id,
                            'location_type' => 'warehouse',
                            'location_id' => (int) $order->warehouse_id,
                            'movement_type' => 'purchase_receipt',
                            'quantity' => (string) $qtyAccepted,
                            'unit_id' => $ingredient->base_unit_id,
                            'unit_cost' => (string) $poItem->unit_price,
                            'reference_type' => GoodsReceiving::class,
                            'reference_id' => $receiving->id,
                            'reference_number' => $receiving->document_number,
                            'notes' => $item['notes'] ?? 'Penerimaan bahan baku dari PO '.$order->document_number,
                            'created_by' => $userId,
                        ]);

                        continue;
                    }

                    $product = $poItem->product;
                    $stockBefore = (int) $product->stock;
                    // Increment legacy stock
                    $product->increment('stock', (int) $qtyAccepted);
                    // Increment warehouse pivot stock
                    if ($order->warehouse_id) {
                        ProductWarehouse::firstOrCreate(
                            ['product_id' => $product->id, 'warehouse_id' => $order->warehouse_id],
                            ['stock' => 0]
                        )->increment('stock', (int) $qtyAccepted);
                    }

                    // Create batch record
                    if (! empty($item['batch_number']) && $order->warehouse_id) {
                        ProductBatch::create([
                            'product_id' => $product->id,
                            'warehouse_id' => $order->warehouse_id,
                            'batch_number' => $item['batch_number'],
                            'expired_at' => $item['expired_at'] ?? null,
                            'received_at' => now(),
                            'stock' => (int) $qtyAccepted,
                        ]);
                    }

                    $this->stockMutationService->recordPurchaseInbound(
                        product: $product,
                        goodsReceiving: $receiving,
                        qty: (int) $qtyAccepted,
                        stockBefore: $stockBefore,
                        stockAfter: (int) $product->stock,
                        notes: 'Penerimaan dari PO '.$order->document_number,
                        userId: $userId,
                    );
                }

                $hasDiscrepancy = collect($items)->contains(fn (array $item) => (float) $item['qty_sent'] !== (float) $item['qty_received']
                    || (float) ($item['qty_accepted'] ?? $item['qty_received']) !== (float) $item['qty_received']
                    || ($item['qc_status'] ?? 'good') !== 'good'
                );
                if ($hasDiscrepancy && $order->warehouse_id) {
                    app(OperationalNotificationService::class)->notifyWarehouseUsers(
                        event: 'goods_receipt.discrepancy',
                        idempotencyKey: 'goods-receiving:'.$receiving->id.':discrepancy',
                        title: 'Ada selisih penerimaan barang',
                        body: 'Penerimaan '.$receiving->document_number.' memerlukan tindak lanjut.',
                        url: '/goods-receivings/'.$receiving->id,
                        warehouseId: (int) $order->warehouse_id,
                        permissions: ['purchase-orders-access', 'goods-receivings-access'],
                    );
                }

                $this->updateOrderStatus($order);

                if ($receiving->supplier_id) {
                    $this->createOrUpdatePayable($order, $receiving, $userId);
                }

                $this->auditLogService->log(
                    event: 'goods_receiving.created',
                    module: 'purchase',
                    auditable: $receiving,
                    description: 'Barang diterima dari PO '.$order->document_number,
                    after: [
                        'document_number' => $receiving->document_number,
                        'purchase_order_id' => $order->id,
                        'total_items' => count($items),
                    ],
                    meta: ['goods_receiving_id' => $receiving->id],
                );

                return $receiving;
            });
        }, 0, function ($e) {
            return $e instanceof QueryException && str_contains($e->getMessage(), 'document_number');
        });
    }

    private function updateOrderStatus(PurchaseOrder $order): void
    {
        $allFullyReceived = $order->items()->whereColumn('qty_received', '<', 'qty_ordered')->doesntExist();

        $status = $allFullyReceived ? 'completed' : 'partial_received';
        $updates = ['status' => $status];

        if ($status === 'completed') {
            $updates['completed_at'] = now();
        }

        $order->update($updates);
    }

    private function createOrUpdatePayable(PurchaseOrder $order, GoodsReceiving $receiving, int $userId): void
    {
        $total = GoodsReceivingItem::query()
            ->join('goods_receivings', 'goods_receivings.id', '=', 'goods_receiving_items.goods_receiving_id')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'goods_receiving_items.purchase_order_item_id')
            ->where('goods_receivings.purchase_order_id', $order->id)
            ->sum(\DB::raw('goods_receiving_items.qty_accepted * purchase_order_items.unit_price'));

        if ($total <= 0) {
            return;
        }

        $payable = Payable::firstOrNew(['purchase_order_id' => $order->id]);
        $paid = (int) ($payable->paid ?? 0);
        $payable->fill([
            'supplier_id' => $order->supplier_id,
            'document_number' => $receiving->document_number,
            'total' => $total,
            'paid' => $paid,
            'due_date' => now()->addDays(30),
            'status' => $paid >= $total ? 'paid' : 'unpaid',
            'note' => 'Otomatis dari penerimaan PO '.$order->document_number,
        ]);
        $payable->save();

        if ($payable->wasRecentlyCreated) {
            $this->auditLogService->log(
                event: 'payable.created_from_receiving',
                module: 'payable',
                auditable: $payable,
                description: 'Hutang otomatis dari penerimaan PO '.$order->document_number,
                after: [
                    'payable_id' => $payable->id,
                    'supplier_id' => $payable->supplier_id,
                    'total' => $payable->total,
                    'document_number' => $payable->document_number,
                    'purchase_order_id' => $order->id,
                ],
                meta: ['goods_receiving_id' => $receiving->id],
            );
        }
    }
}
