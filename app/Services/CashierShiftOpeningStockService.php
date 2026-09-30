<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CashierShiftOpeningItem;
use App\Models\Ingredient;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftOpeningStockService
{
    public function catalog(): array
    {
        $central = Warehouse::query()->where('code', 'PUSAT')->where('is_active', true)->first();
        if (! $central) {
            return ['warehouse' => null, 'products' => [], 'ingredients' => []];
        }

        $products = ProductWarehouse::query()
            ->where('warehouse_id', $central->id)
            ->where('stock', '>', 0)
            ->whereDoesntHave('product.recipeVersions')
            ->with('product.units')
            ->get()
            ->map(function (ProductWarehouse $stock) {
                $product = $stock->product;

                return $product ? [
                    'id' => $product->id,
                    'item_type' => 'product',
                    'name' => $product->title,
                    'unit' => $product->baseUnit()?->code ?? 'PCS',
                    'available' => (int) $stock->stock,
                    'unit_id' => $product->baseUnit()?->id ?? Unit::where('code', 'PCS')->value('id'),
                ] : null;
            })
            ->filter()
            ->sortBy('name')
            ->values();

        $ingredientBalances = InventoryBalance::query()
            ->where('location_type', 'warehouse')
            ->where('warehouse_id', $central->id)
            ->where('item_type', 'ingredient')
            ->where('quantity', '>', 0)
            ->get()
            ->keyBy('item_id');
        $ingredients = Ingredient::query()
            ->whereIn('id', $ingredientBalances->keys())
            ->with('baseUnit:id,code,name,symbol')
            ->where('is_active', true)
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'item_type' => 'ingredient',
                'name' => $ingredient->name,
                'unit' => $ingredient->baseUnit?->name ?? $ingredient->baseUnit?->code ?? 'satuan',
                'available' => (float) $ingredientBalances->get($ingredient->id)?->quantity,
                'unit_id' => $ingredient->base_unit_id,
            ])
            ->sortBy('name')
            ->values();

        return [
            'warehouse' => $central->only(['id', 'code', 'name']),
            'products' => $products,
            'ingredients' => $ingredients,
        ];
    }

    /** @param array<int, array{item_type:string,item_id:int,quantity:int|float|string}> $items */
    public function issueToShift(CashierShift $shift, array $items, int $actorId): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['opening_items' => 'Input minimal satu barang bawaan sebelum membuka shift.']);
        }

        DB::transaction(function () use ($shift, $items, $actorId) {
            $central = Warehouse::query()->where('code', 'PUSAT')->where('is_active', true)->lockForUpdate()->first();
            $destination = Warehouse::query()->lockForUpdate()->findOrFail($shift->warehouse_id);
            if (! $central || $central->is($destination)) {
                throw ValidationException::withMessages(['opening_items' => 'Gudang Pusat dan gudang outlet belum dikonfigurasi dengan benar.']);
            }

            $seen = [];
            foreach ($items as $index => $item) {
                $type = $item['item_type'];
                $id = (int) $item['item_id'];
                $quantity = (string) $item['quantity'];
                $key = $type.':'.$id;
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(['opening_items.'.$index.'.item_id' => 'Barang yang sama hanya boleh dimasukkan satu kali.']);
                }
                $seen[$key] = true;

                if ($type === 'product') {
                    $this->moveProduct($shift, $central, $destination, $id, $quantity, $actorId);
                } else {
                    $this->moveIngredient($shift, $central, $destination, $id, $quantity, $actorId);
                }
            }
        }, attempts: 3);
    }

    private function moveProduct(CashierShift $shift, Warehouse $central, Warehouse $destination, int $productId, string $quantity, int $actorId): void
    {
        if ((float) $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['opening_items' => 'Jumlah menu harus berupa bilangan bulat PCS.']);
        }

        $product = Product::with('units')->findOrFail($productId);
        $source = ProductWarehouse::query()->where(['product_id' => $productId, 'warehouse_id' => $central->id])->lockForUpdate()->first();
        $available = (int) ($source?->stock ?? 0);
        $qty = (int) $quantity;
        if ($qty > $available) {
            throw ValidationException::withMessages(['opening_items' => "Stok {$product->title} di Gudang Pusat hanya {$available} PCS."]);
        }

        $target = ProductWarehouse::query()->firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $destination->id],
            ['stock' => 0],
        );
        $unit = $product->baseUnit() ?? Unit::where('code', 'PCS')->first();
        if (! $unit) {
            throw ValidationException::withMessages(['opening_items' => 'Satuan PCS belum tersedia.']);
        }
        $unitCost = (string) $product->buy_price;
        $ledger = app(InventoryLedgerService::class);
        $ledger->ensureProductOpeningBalance($product, $central->id, $actorId);
        $ledger->ensureProductOpeningBalance($product, $destination->id, $actorId);
        $sourceBalance = InventoryBalance::where('balance_key', "warehouse:{$central->id}:product:{$productId}")->first();
        $unitCost = (string) ($sourceBalance?->average_unit_cost ?? $product->buy_price);

        ProductWarehouse::whereKey($source->id)->decrement('stock', $qty);
        ProductWarehouse::whereKey($target->id)->increment('stock', $qty);

        foreach ([[$central, -$qty], [$destination, $qty]] as [$warehouse, $amount]) {
            $ledger->record([
                'idempotency_key' => "shift-opening:{$shift->id}:product:{$productId}:warehouse:{$warehouse->id}",
                'item_type' => 'product', 'item_id' => $productId, 'location_type' => 'warehouse',
                'location_id' => $warehouse->id, 'movement_type' => 'warehouse_to_outlet',
                'quantity' => (string) $amount, 'unit_id' => $unit->id, 'unit_cost' => $unitCost,
                'reference_type' => CashierShift::class, 'reference_id' => $shift->id,
                'reference_number' => 'SHIFT-'.$shift->id, 'created_by' => $actorId,
            ]);
        }

        CashierShiftOpeningItem::create([
            'cashier_shift_id' => $shift->id, 'item_type' => 'product', 'product_id' => $productId,
            'unit_id' => $unit->id, 'quantity' => $qty, 'unit_cost' => $unitCost,
        ]);
    }

    private function moveIngredient(CashierShift $shift, Warehouse $central, Warehouse $destination, int $ingredientId, string $quantity, int $actorId): void
    {
        $ingredient = Ingredient::with('baseUnit')->findOrFail($ingredientId);
        if (! $ingredient->is_active || ! $ingredient->base_unit_id) {
            throw ValidationException::withMessages(['opening_items' => "Bahan {$ingredient->name} belum memiliki satuan dasar aktif."]);
        }

        $qty = (float) $quantity;
        $sourceKey = "warehouse:{$central->id}:ingredient:{$ingredientId}";
        $sourceBalance = InventoryBalance::where('balance_key', $sourceKey)->lockForUpdate()->first();
        $available = (float) ($sourceBalance?->quantity ?? 0);
        if ($qty > $available) {
            $unit = $ingredient->baseUnit?->symbol ?? $ingredient->baseUnit?->code ?? '';
            throw ValidationException::withMessages(['opening_items' => "Stok {$ingredient->name} di Gudang Pusat hanya {$available} {$unit}."]);
        }

        $unitCost = (string) ($sourceBalance?->average_unit_cost ?? $ingredient->default_unit_cost);
        foreach ([[$central, -$qty], [$destination, $qty]] as [$warehouse, $amount]) {
            app(InventoryLedgerService::class)->record([
                'idempotency_key' => "shift-opening:{$shift->id}:ingredient:{$ingredientId}:warehouse:{$warehouse->id}",
                'item_type' => 'ingredient', 'item_id' => $ingredientId, 'location_type' => 'warehouse',
                'location_id' => $warehouse->id, 'movement_type' => 'warehouse_to_outlet',
                'quantity' => (string) $amount, 'unit_id' => $ingredient->base_unit_id, 'unit_cost' => $unitCost,
                'reference_type' => CashierShift::class, 'reference_id' => $shift->id,
                'reference_number' => 'SHIFT-'.$shift->id, 'created_by' => $actorId,
            ]);
        }

        CashierShiftOpeningItem::create([
            'cashier_shift_id' => $shift->id, 'item_type' => 'ingredient', 'ingredient_id' => $ingredientId,
            'unit_id' => $ingredient->base_unit_id, 'quantity' => $qty, 'unit_cost' => $unitCost,
        ]);
    }
}
