<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class InventoryLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private Ingredient $ingredient;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $unit = Unit::where('code', 'GRAM')->firstOrFail();
        $category = IngredientCategory::firstOrFail();
        $this->ingredient = Ingredient::create([
            'code' => 'TEST-PRAWN',
            'name' => 'Udang Uji',
            'ingredient_category_id' => $category->id,
            'base_unit_id' => $unit->id,
        ]);
        $this->warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
    }

    public function test_receipts_and_consumption_update_balance_with_weighted_average_cost(): void
    {
        $service = app(InventoryLedgerService::class);
        $service->record($this->movement('recv-one', 1000, 2));
        $service->record($this->movement('recv-two', 1000, 4));
        $service->record($this->movement('use-one', -500));

        $balance = InventoryBalance::firstOrFail();
        $this->assertSame('1500.0000', $balance->quantity);
        $this->assertSame('3.00', $balance->average_unit_cost);
        $this->assertSame('4500.00', $balance->inventory_value);
        $this->assertSame(3, InventoryLedger::count());
    }

    public function test_repeating_same_idempotency_key_does_not_apply_stock_twice(): void
    {
        $service = app(InventoryLedgerService::class);
        $movement = $this->movement('repeat-receipt', 700, 5);

        $first = $service->record($movement);
        $second = $service->record($movement);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('700.0000', InventoryBalance::firstOrFail()->quantity);
        $this->assertSame(1, InventoryLedger::count());
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_movement(): void
    {
        $service = app(InventoryLedgerService::class);
        $service->record($this->movement('reused-key', 700, 5));

        $this->expectException(LogicException::class);
        $service->record($this->movement('reused-key', 800, 5));
    }

    public function test_insufficient_stock_fails_without_writing_a_ledger_row(): void
    {
        $this->expectException(ValidationException::class);

        app(InventoryLedgerService::class)->record($this->movement('too-much', -1));

        $this->assertSame(0, InventoryLedger::count());
    }

    public function test_missing_warehouse_pivot_does_not_copy_the_global_product_total_as_opening_stock(): void
    {
        $product = Product::create([
            'category_id' => Category::firstOrFail()->id,
            'image' => '', 'barcode' => 'LEDGER-PRODUCT', 'sku' => 'LEDGER-PRODUCT',
            'title' => 'Menu Uji Ledger', 'description' => '', 'buy_price' => 1000,
            'sell_price' => 2000, 'stock' => 80, 'tax_rate' => 0,
        ]);
        $piece = Unit::where('code', 'PCS')->firstOrFail();
        $product->units()->attach($piece->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 0, 'sell_price' => 2000]);
        $otherWarehouse = Warehouse::factory()->create();
        ProductWarehouse::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'stock' => 80]);

        app(InventoryLedgerService::class)->ensureProductOpeningBalance($product, $otherWarehouse->id);

        $balance = InventoryBalance::where('balance_key', "warehouse:{$otherWarehouse->id}:product:{$product->id}")->firstOrFail();
        $this->assertSame('0.0000', $balance->quantity);
        $this->assertSame(0, InventoryLedger::where('warehouse_id', $otherWarehouse->id)->count());
    }

    private function movement(string $key, float $quantity, float $unitCost = 0): array
    {
        return [
            'idempotency_key' => $key,
            'item_type' => 'ingredient',
            'item_id' => $this->ingredient->id,
            'location_type' => 'warehouse',
            'location_id' => $this->warehouse->id,
            'movement_type' => $quantity > 0 ? 'purchase_receipt' : 'production_consumption',
            'quantity' => $quantity,
            'unit_id' => $this->ingredient->base_unit_id,
            'unit_cost' => $unitCost,
        ];
    }
}
