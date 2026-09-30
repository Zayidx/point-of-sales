<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\RecipeVersion;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\InventoryLedgerService;
use App\Services\RecipeCostService;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeCostServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_changes_create_a_new_version_and_keep_estimated_costs_historical(): void
    {
        $this->seed();
        $ingredient = $this->ingredient();
        $menu = $this->menu();
        $warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $service = app(RecipeService::class);
        $kg = Unit::where('code', 'KG')->firstOrFail();

        $first = $service->createVersion($menu, [[
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
            'unit_id' => $kg->id,
        ]]);

        $this->assertSame(1, $first->version_number);
        $this->assertSame('1000.0000', $first->items->first()->base_quantity);

        app(InventoryLedgerService::class)->record([
            'idempotency_key' => 'recipe-cost-initial-stock',
            'item_type' => 'ingredient',
            'item_id' => $ingredient->id,
            'location_type' => 'warehouse',
            'location_id' => $warehouse->id,
            'movement_type' => 'opening_stock',
            'quantity' => 1000,
            'unit_id' => $ingredient->base_unit_id,
            'unit_cost' => 2,
        ]);

        $costService = app(RecipeCostService::class);
        $firstEstimate = $costService->estimate($menu, $warehouse);
        $this->assertSame('2000.00', $firstEstimate['unit_hpp']);

        $second = $service->createVersion($menu, [[
            'ingredient_id' => $ingredient->id,
            'quantity' => 0.5,
            'unit_id' => $kg->id,
        ]]);

        $this->assertSame(2, $second->version_number);
        $this->assertSame(2, RecipeVersion::where('product_id', $menu->id)->count());
        $this->assertSame('1000.0000', $first->fresh()->items->first()->base_quantity);
        $this->assertSame('1000.0000', InventoryBalance::firstOrFail()->quantity);
        $this->assertSame('1000.00', $costService->estimate($menu, $warehouse)['unit_hpp']);
    }

    private function ingredient(): Ingredient
    {
        return Ingredient::create([
            'code' => 'ING-RECIPE',
            'name' => 'Daging Uji',
            'ingredient_category_id' => IngredientCategory::firstOrFail()->id,
            'base_unit_id' => Unit::where('code', 'GRAM')->value('id'),
        ]);
    }

    private function menu(): Product
    {
        return Product::create([
            'image' => '',
            'barcode' => 'MENU-RECIPE',
            'sku' => 'MENU-RECIPE',
            'title' => 'Dimsum Uji',
            'description' => '',
            'category_id' => Category::firstOrFail()->id,
            'buy_price' => 0,
            'sell_price' => 16000,
            'stock' => 0,
            'tax_rate' => 0,
        ]);
    }
}
