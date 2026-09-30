<?php

namespace Tests\Feature\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_can_adjust_ingredient_stock_with_reason_and_idempotency(): void
    {
        $this->seed();
        $ingredient = $this->makeIngredient();
        $warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $user = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $payload = [
            'warehouse_id' => $warehouse->id,
            'quantity' => 250.5,
            'reason' => 'Saldo awal hasil hitung fisik',
            'adjustment_key' => 'fc646f30-040d-4a4d-9320-92dbfcc34ea2',
        ];

        $this->actingAs($user)->post(route('ingredients.adjust', $ingredient), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('ingredients.adjust', $ingredient), $payload)->assertRedirect();

        $this->assertSame('250.5000', InventoryBalance::where('item_id', $ingredient->id)->value('quantity'));
        $this->assertSame(1, InventoryLedger::where('item_id', $ingredient->id)->count());
    }

    public function test_manager_can_view_scoped_ingredient_balances_but_cannot_adjust_them(): void
    {
        $this->seed();
        $ingredient = $this->makeIngredient();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('ingredients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Ingredients/Index')
                ->where('canAdjustStock', false)
                ->has('ingredients.data.0.stock_by_warehouse'));

        $this->post(route('ingredients.adjust', $ingredient), [
            'warehouse_id' => Warehouse::where('code', 'PUSAT')->value('id'),
            'quantity' => 5,
            'reason' => 'Tidak boleh',
            'adjustment_key' => 'e5d47087-b286-478a-9da9-79fe51ac67e8',
        ])->assertForbidden();
    }

    private function makeIngredient(): Ingredient
    {
        return Ingredient::create([
            'code' => 'TEST-INGREDIENT',
            'name' => 'Bahan Uji',
            'ingredient_category_id' => IngredientCategory::firstOrFail()->id,
            'base_unit_id' => Unit::where('code', 'GRAM')->firstOrFail()->id,
            'default_unit_cost' => 3.5,
            'is_active' => true,
        ]);
    }
}
