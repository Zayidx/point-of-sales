<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionRequest;
use App\Models\ProductWarehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionOrderService
{
    public function createFromApprovedRequest(ProductionRequest $request, User $actor): ProductionOrder
    {
        return DB::transaction(function () use ($request) {
            $locked = ProductionRequest::with('items')->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Hanya permintaan produksi yang disetujui dapat dijadwalkan.']);
            }

            $order = ProductionOrder::firstOrCreate(
                ['production_request_id' => $locked->id],
                [
                    'order_number' => 'PRO-'.now()->format('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3))),
                    'warehouse_id' => $locked->warehouse_id,
                    'product_id' => $locked->product_id,
                    'recipe_version_id' => $locked->recipe_version_id,
                    'planned_output' => $locked->target_output,
                    'status' => 'planned',
                ],
            );

            if ($order->wasRecentlyCreated) {
                foreach ($locked->items as $item) {
                    $order->items()->create([
                        'ingredient_id' => $item->ingredient_id,
                        'unit_id' => $item->unit_id,
                        'planned_quantity' => $item->required_quantity,
                        'unit_cost_snapshot' => $item->unit_cost_snapshot,
                    ]);
                }
            }

            return $order->load('items.ingredient');
        }, attempts: 3);
    }

    public function start(ProductionOrder $order, User $actor): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages(['status' => 'Pesanan produksi ini sudah dimulai atau selesai.']);
            }
            $locked->update(['status' => 'in_progress', 'started_by' => $actor->id, 'started_at' => now()]);

            return $locked->fresh('items');
        }, attempts: 3);
    }

    /**
     * Complete a production run using actual quantities. Inventory consumption,
     * output, and cost snapshots are committed atomically.
     */
    public function complete(ProductionOrder $order, array $actualConsumption, string|int $actualOutput, User $actor): ProductionOrder
    {
        validator(['actual_output' => $actualOutput], ['actual_output' => ['required', 'numeric', 'gt:0']])->validate();

        return DB::transaction(function () use ($order, $actualConsumption, $actualOutput, $actor) {
            $locked = ProductionOrder::with('items', 'product')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages(['status' => 'Produksi harus dimulai sebelum diselesaikan.']);
            }
            $baseUnit = $locked->product->baseUnit();
            if (! $baseUnit) {
                throw ValidationException::withMessages(['product' => 'Menu hasil produksi harus memiliki satuan dasar.']);
            }

            $provided = collect($actualConsumption)->keyBy('ingredient_id');
            $expectedIds = $locked->items->pluck('ingredient_id')->map(fn ($id) => (string) $id)->sort()->values();
            $providedIds = $provided->keys()->map(fn ($id) => (string) $id)->sort()->values();
            if ($expectedIds->all() !== $providedIds->all()) {
                throw ValidationException::withMessages(['actual_consumption' => 'Masukkan pemakaian aktual untuk setiap bahan resep.']);
            }

            $totalCostCents = 0;
            foreach ($locked->items as $item) {
                $quantity = $provided->get($item->ingredient_id)['quantity'] ?? null;
                validator(['quantity' => $quantity], ['quantity' => ['required', 'numeric', 'gt:0']])->validate();
                $scaledQuantity = $this->scaled((string) $quantity, 4);

                $balanceKey = "warehouse:{$locked->warehouse_id}:ingredient:{$item->ingredient_id}";
                $balance = InventoryBalance::where('balance_key', $balanceKey)->lockForUpdate()->first();
                if (! $balance || $this->scaled((string) $balance->quantity, 4) < $scaledQuantity) {
                    throw ValidationException::withMessages(['actual_consumption' => "Stok bahan {$item->ingredient->name} tidak mencukupi."]);
                }

                // Actual production cost uses the warehouse's weighted-average
                // cost at consumption time, not the estimate captured when the
                // production request was submitted.
                $costCents = $this->scaled((string) $balance->average_unit_cost, 2);
                $lineCost = intdiv($scaledQuantity * $costCents + 5000, 10000);
                $totalCostCents += $lineCost;

                app(InventoryLedgerService::class)->record([
                    'idempotency_key' => "production-order:{$locked->id}:ingredient:{$item->ingredient_id}",
                    'item_type' => 'ingredient',
                    'item_id' => $item->ingredient_id,
                    'location_type' => 'warehouse',
                    'location_id' => $locked->warehouse_id,
                    'movement_type' => 'production_consumption',
                    'quantity' => '-'.$this->decimal($scaledQuantity, 4),
                    'unit_id' => $item->unit_id,
                    'unit_cost' => $this->decimal($costCents, 2),
                    'reference_type' => ProductionOrder::class,
                    'reference_id' => $locked->id,
                    'created_by' => $actor->id,
                ]);

                $item->update([
                    'actual_quantity' => $this->decimal($scaledQuantity, 4),
                    'actual_cost' => $this->decimal($lineCost, 2),
                ]);
            }

            $outputScaled = $this->scaled((string) $actualOutput, 4);
            if ($outputScaled % 10000 !== 0) {
                throw ValidationException::withMessages(['actual_output' => 'Hasil menu harus berupa jumlah satuan utuh.']);
            }
            $actualCost = $this->decimal($totalCostCents, 2);
            $actualUnitCostCents = intdiv($totalCostCents * 10000 + intdiv($outputScaled, 2), $outputScaled);

            app(InventoryLedgerService::class)->record([
                'idempotency_key' => "production-order:{$locked->id}:output",
                'item_type' => 'product',
                'item_id' => $locked->product_id,
                'location_type' => 'warehouse',
                'location_id' => $locked->warehouse_id,
                'movement_type' => 'production_output',
                'quantity' => $this->decimal($outputScaled, 4),
                'unit_id' => $baseUnit->id,
                'unit_cost' => $this->decimal($actualUnitCostCents, 2),
                'reference_type' => ProductionOrder::class,
                'reference_id' => $locked->id,
                'created_by' => $actor->id,
            ]);

            // Keep the existing POS stock source synchronized while the legacy
            // checkout and transfer flows are migrated onto the ledger.
            $productWarehouse = ProductWarehouse::firstOrCreate(
                ['product_id' => $locked->product_id, 'warehouse_id' => $locked->warehouse_id],
                ['stock' => 0],
            );
            $productWarehouse = ProductWarehouse::whereKey($productWarehouse->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $stockBefore = (int) $productWarehouse->stock;
            $producedUnits = intdiv($outputScaled, 10000);
            $productWarehouse->increment('stock', $producedUnits);
            $product->increment('stock', $producedUnits);
            app(StockMutationService::class)->recordMutation(
                product: $product,
                warehouseId: $locked->warehouse_id,
                referenceType: 'production_order',
                referenceId: $locked->id,
                mutationType: 'in',
                qty: $producedUnits,
                stockBefore: $stockBefore,
                stockAfter: $stockBefore + $producedUnits,
                notes: 'Hasil aktual dari '.$locked->order_number,
                userId: $actor->id,
            );

            $locked->update([
                'status' => 'completed',
                'actual_output' => $this->decimal($outputScaled, 4),
                'actual_material_cost' => $actualCost,
                'actual_unit_cost' => $this->decimal($actualUnitCostCents, 2),
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ]);
            $locked->request()->update(['status' => 'completed']);

            $outputVariance = $outputScaled - $this->scaled((string) $locked->planned_output, 4);
            $varianceThreshold = $this->scaled((string) max(0, (int) config('operations.production_output_variance_threshold', 5)), 4);
            if ($outputVariance < 0 || abs($outputVariance) > $varianceThreshold) {
                $belowTarget = $outputVariance < 0;
                app(OperationalNotificationService::class)->notifyWarehouseUsers(
                    event: $belowTarget ? 'production.below_target' : 'production.variance',
                    idempotencyKey: 'production-order:'.$locked->id.':output-variance',
                    title: $belowTarget ? 'Hasil produksi di bawah target' : 'Selisih hasil produksi melewati batas',
                    body: 'Produksi '.$locked->order_number.' menghasilkan '.$this->decimal($outputScaled, 4).' dari target '.$locked->planned_output.'.',
                    url: '/production-orders/'.$locked->id,
                    warehouseId: (int) $locked->warehouse_id,
                    permissions: ['production-orders-access'],
                );
            }

            app(AuditLogService::class)->log(
                event: 'production.completed',
                module: 'production',
                auditable: $locked,
                description: 'Pesanan produksi '.$locked->order_number.' diselesaikan.',
                before: ['status' => 'in_progress'],
                after: [
                    'status' => 'completed',
                    'actual_output' => $this->decimal($outputScaled, 4),
                    'actual_material_cost' => $actualCost,
                    'actual_unit_cost' => $this->decimal($actualUnitCostCents, 2),
                ],
                meta: ['production_request_id' => $locked->production_request_id],
            );

            return $locked->fresh('items.ingredient', 'product');
        }, attempts: 3);
    }

    private function scaled(string $value, int $scale): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', trim($value), $parts)) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah harus berupa angka desimal yang valid.']);
        }
        $fraction = str_pad(substr($parts[2] ?? '', 0, $scale), $scale, '0');
        $scaled = ((int) $parts[1] * (10 ** $scale)) + (int) ($fraction ?: 0);
        if ((int) substr($parts[2] ?? '', $scale, 1) >= 5) {
            $scaled++;
        }

        return $scaled;
    }

    private function decimal(int $value, int $scale): string
    {
        $factor = 10 ** $scale;

        return intdiv($value, $factor).'.'.str_pad((string) ($value % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
