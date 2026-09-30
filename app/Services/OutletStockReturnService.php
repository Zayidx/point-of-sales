<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\OutletStockReturn;
use App\Models\OutletStockReturnItem;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OutletStockReturnService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly InventoryLedgerService $inventoryLedger,
        private readonly StockMutationService $stockMutations,
        private readonly OutletAccessService $outletAccess,
    ) {}

    public function create(string $requestKey, Warehouse $source, array $items, User $actor, ?string $notes = null): OutletStockReturn
    {
        if (! $this->outletAccess->canUseWarehouse($actor, $source) || ! $source->outlet?->is_sales_enabled) {
            abort(403);
        }

        $destination = Warehouse::where('code', 'PUSAT')->where('is_active', true)->firstOrFail();
        if ((int) $source->id === (int) $destination->id) {
            throw ValidationException::withMessages(['source_warehouse_id' => 'Pengembalian harus berasal dari gudang outlet.']);
        }

        return DB::transaction(function () use ($requestKey, $source, $destination, $items, $actor, $notes) {
            $existing = OutletStockReturn::where('request_key', $requestKey)->first();
            if ($existing) {
                $requestedItems = collect($items)->mapWithKeys(fn (array $item) => [(int) $item['product_id'] => (int) $item['quantity']])->sortKeys();
                $savedItems = $existing->items()->get()->mapWithKeys(fn (OutletStockReturnItem $item) => [(int) $item->product_id => (int) $item->quantity_requested])->sortKeys();
                if ((int) $existing->source_warehouse_id !== (int) $source->id
                    || (int) $existing->requested_by !== (int) $actor->id
                    || $requestedItems->all() !== $savedItems->all()) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci pengajuan sudah digunakan untuk data lain.']);
                }

                return $existing;
            }

            $return = OutletStockReturn::create([
                'return_number' => 'OR-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'request_key' => $requestKey,
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'requested_by' => $actor->id,
                'status' => 'pending',
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $product = Product::with('units')->findOrFail($item['product_id']);
                if (! $product->baseUnit()) {
                    throw ValidationException::withMessages(['items' => "{$product->title} belum memiliki satuan dasar untuk dicatat di ledger."]);
                }

                $stock = ProductWarehouse::where('product_id', $product->id)
                    ->where('warehouse_id', $source->id)
                    ->lockForUpdate()
                    ->first();
                $pending = OutletStockReturnItem::where('product_id', $product->id)
                    ->whereHas('stockReturn', fn ($query) => $query->where('source_warehouse_id', $source->id)->where('status', 'pending'))
                    ->sum('quantity_requested');
                $available = (int) ($stock?->stock ?? 0) - (int) $pending;
                if ($available < (int) $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "Stok {$product->title} tidak cukup untuk diajukan. Tersedia: {$available}."]);
                }

                $return->items()->create([
                    'product_id' => $product->id,
                    'quantity_requested' => $item['quantity'],
                ]);
            }

            $this->auditLog->log(
                event: 'outlet_stock_return.requested',
                module: 'inventory',
                auditable: $return,
                description: "Pengembalian stok {$return->return_number} diajukan ke gudang pusat.",
                after: ['source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'status' => 'pending', 'items_count' => count($items)],
            );

            return $return;
        }, attempts: 3);
    }

    public function receive(OutletStockReturn $stockReturn, array $receivedItems, User $actor, ?string $notes = null): OutletStockReturn
    {
        return DB::transaction(function () use ($stockReturn, $receivedItems, $actor, $notes) {
            $locked = OutletStockReturn::whereKey($stockReturn->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Pengembalian ini sudah diproses.']);
            }

            $locked->load(['items.product.units', 'sourceWarehouse', 'destinationWarehouse']);
            $source = $locked->sourceWarehouse;
            $destination = $locked->destinationWarehouse;
            $expectedItemIds = $locked->items->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            $providedItemIds = collect($receivedItems)->keys()->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($expectedItemIds !== $providedItemIds) {
                throw ValidationException::withMessages(['items' => 'Masukkan jumlah diterima untuk setiap barang yang diajukan.']);
            }

            foreach ($locked->items as $item) {
                $received = (int) ($receivedItems[$item->id] ?? 0);
                if ($received < 0 || $received > $item->quantity_requested) {
                    throw ValidationException::withMessages(['items' => "Jumlah terima {$item->product->title} harus antara nol dan jumlah yang diajukan."]);
                }
                if ($received === 0) {
                    $item->update(['quantity_received' => 0]);

                    continue;
                }

                $product = $item->product;
                $unit = $product->baseUnit();
                $sourceStock = ProductWarehouse::where('product_id', $product->id)->where('warehouse_id', $source->id)->lockForUpdate()->first();
                $sourceBefore = (int) ($sourceStock?->stock ?? 0);
                if ($sourceBefore < $received) {
                    throw ValidationException::withMessages(['items' => "Stok {$product->title} di outlet berubah. Tersedia: {$sourceBefore}."]);
                }

                $this->inventoryLedger->ensureProductOpeningBalance($product, $source->id, $actor->id);
                ProductWarehouse::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $destination->id], ['stock' => 0]);
                $this->inventoryLedger->ensureProductOpeningBalance($product, $destination->id, $actor->id);
                $balance = InventoryBalance::where('balance_key', "warehouse:{$source->id}:product:{$product->id}")->first();
                $unitCost = (int) round((float) ($balance?->average_unit_cost ?: $product->buy_price));
                $item->update(['quantity_received' => $received, 'unit_cost_snapshot' => $unitCost]);

                foreach ([[$source, -$received], [$destination, $received]] as [$warehouse, $quantity]) {
                    $this->inventoryLedger->record([
                        'idempotency_key' => "outlet-return:{$locked->id}:item:{$item->id}:{$warehouse->id}",
                        'item_type' => 'product',
                        'item_id' => $product->id,
                        'location_type' => 'warehouse',
                        'location_id' => $warehouse->id,
                        'movement_type' => 'outlet_to_warehouse',
                        'quantity' => (string) $quantity,
                        'unit_id' => $unit->id,
                        'unit_cost' => (string) $unitCost,
                        'reference_type' => OutletStockReturn::class,
                        'reference_id' => $locked->id,
                        'reference_number' => $locked->return_number,
                        'notes' => $notes,
                        'created_by' => $actor->id,
                    ]);

                    $stock = ProductWarehouse::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouse->id], ['stock' => 0]);
                    $before = (int) $stock->stock;
                    if ($quantity < 0) {
                        $stock->decrement('stock', abs($quantity));
                    } else {
                        $stock->increment('stock', $quantity);
                    }
                    $after = (int) $stock->fresh()->stock;
                    $this->stockMutations->recordMutation(
                        product: $product,
                        warehouseId: $warehouse->id,
                        referenceType: 'outlet_stock_return',
                        referenceId: $locked->id,
                        mutationType: $quantity < 0 ? 'out' : 'in',
                        qty: abs($quantity),
                        stockBefore: $before,
                        stockAfter: $after,
                        notes: "Pengembalian {$locked->return_number}",
                        userId: $actor->id,
                    );
                }
            }

            $locked->update([
                'status' => 'received',
                'receiving_notes' => $notes,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $this->auditLog->log(
                event: 'outlet_stock_return.received',
                module: 'inventory',
                auditable: $locked,
                description: "Pengembalian stok {$locked->return_number} diterima gudang.",
                before: ['status' => 'pending'],
                after: ['status' => 'received', 'received_by' => $actor->id],
            );

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'requester', 'receiver']);
        }, attempts: 3);
    }
}
