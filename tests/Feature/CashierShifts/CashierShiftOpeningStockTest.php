<?php

namespace Tests\Feature\CashierShifts;

use App\Models\CashierShift;
use App\Models\Ingredient;
use App\Models\InventoryBalance;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftOpeningStockService;
use App\Services\CashierShiftService;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierShiftOpeningStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(TestDataSeeder::class);
    }

    public function test_opening_catalog_lists_frozen_stock_and_sauces_as_ingredients(): void
    {
        $catalog = app(CashierShiftOpeningStockService::class)->catalog();

        $this->assertCount(0, $catalog['products']);
        $this->assertNotEmpty($catalog['ingredients']);
        $this->assertContains('Dimsum frozen', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Lumpia frozen', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Chiquro frozen', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Saus mentai siap pakai', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Box kecil', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Minyak goreng', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Sumpit', $catalog['ingredients']->pluck('name')->all());
        $this->assertContains('Cup saus', $catalog['ingredients']->pluck('name')->all());
    }

    public function test_cashier_records_menu_and_weighted_ingredients_before_shift_opens(): void
    {
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $central = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $outlet = Outlet::where('code', 'GAL-BP')->firstOrFail();
        $cashier->outlets()->syncWithoutDetaching([$outlet->id => ['is_default' => true]]);
        $destination = Warehouse::where('outlet_id', $outlet->id)->where('code', '!=', 'PUSAT')->firstOrFail();
        $ingredient = Ingredient::where('code', 'TEST-SAUS-MENTAI')->firstOrFail();
        $ingredientSourceBefore = InventoryBalance::where('balance_key', "warehouse:{$central->id}:ingredient:{$ingredient->id}")->value('quantity');

        $response = $this->actingAs($cashier)->post(route('cashier-shifts.store'), [
            'opening_cash' => 50000,
            'warehouse_id' => $destination->id,
            'opening_items' => [
                ['item_type' => 'ingredient', 'item_id' => $ingredient->id, 'quantity' => 125.5],
            ],
            'redirect_to' => 'transactions',
        ]);

        $response->assertRedirect(route('transactions.index'));
        $shift = CashierShift::where('user_id', $cashier->id)->firstOrFail();
        $this->assertSame((float) $ingredientSourceBefore - 125.5, (float) InventoryBalance::where('balance_key', "warehouse:{$central->id}:ingredient:{$ingredient->id}")->value('quantity'));
        $this->assertSame('125.5000', $shift->openingItems()->where('ingredient_id', $ingredient->id)->value('quantity'));
        $this->assertDatabaseHas('inventory_ledgers', [
            'reference_type' => CashierShift::class,
            'reference_id' => $shift->id,
            'item_type' => 'ingredient',
            'item_id' => $ingredient->id,
            'movement_type' => 'warehouse_to_outlet',
            'quantity' => '-125.5000',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'balance_key' => "warehouse:{$destination->id}:ingredient:{$ingredient->id}",
            'quantity' => '125.5000',
        ]);

        app(CashierShiftService::class)->closeShift(
            $shift,
            $cashier,
            50000,
            closingIngredients: [[
                'opening_item_id' => $shift->openingItems()->where('ingredient_id', $ingredient->id)->value('id'),
                'actual_quantity' => 75.5,
            ]],
        );

        $this->assertSame('75.5000', $shift->openingItems()->where('ingredient_id', $ingredient->id)->value('closing_quantity'));
        $this->assertSame('4950.0000', InventoryBalance::where('balance_key', "warehouse:{$central->id}:ingredient:{$ingredient->id}")->value('quantity'));
        $this->assertSame('0.0000', InventoryBalance::where('balance_key', "warehouse:{$destination->id}:ingredient:{$ingredient->id}")->value('quantity'));
    }

    public function test_shift_does_not_open_when_carried_quantity_exceeds_central_stock(): void
    {
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $central = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $outlet = Outlet::where('code', 'GAL-BP')->firstOrFail();
        $cashier->outlets()->syncWithoutDetaching([$outlet->id => ['is_default' => true]]);
        $destination = Warehouse::where('outlet_id', $outlet->id)->where('code', '!=', 'PUSAT')->firstOrFail();
        $ingredient = Ingredient::where('code', 'TEST-FROZEN-DIMSUM')->firstOrFail();
        $sourceBefore = InventoryBalance::where('balance_key', "warehouse:{$central->id}:ingredient:{$ingredient->id}")->value('quantity');

        $this->actingAs($cashier)->from(route('transactions.index'))->post(route('cashier-shifts.store'), [
            'opening_cash' => 0,
            'warehouse_id' => $destination->id,
            'opening_items' => [['item_type' => 'ingredient', 'item_id' => $ingredient->id, 'quantity' => $sourceBefore + 1]],
        ])->assertSessionHasErrors('opening_items');

        $this->assertDatabaseMissing('cashier_shifts', ['user_id' => $cashier->id, 'status' => 'open']);
        $this->assertSame((float) $sourceBefore, (float) InventoryBalance::where('balance_key', "warehouse:{$central->id}:ingredient:{$ingredient->id}")->value('quantity'));
    }

    public function test_shift_cannot_open_until_available_carried_stock_is_counted(): void
    {
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();

        $this->actingAs($cashier)->from(route('transactions.index'))->post(route('cashier-shifts.store'), [
            'opening_cash' => 0,
        ])->assertSessionHasErrors('opening_items');

        $this->assertDatabaseMissing('cashier_shifts', ['user_id' => $cashier->id, 'status' => 'open']);
    }
}
