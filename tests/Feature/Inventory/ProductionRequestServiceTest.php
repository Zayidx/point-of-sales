<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedgerService;
use App\Services\ProductionOrderService;
use App\Services\ProductionRequestService;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_calculates_material_needs_shortages_and_allows_single_approval(): void
    {
        $this->seed();
        [$menu, $ingredient, $warehouse] = $this->fixtures();
        $gram = Unit::where('code', 'GRAM')->firstOrFail();
        app(RecipeService::class)->createVersion($menu, [[
            'ingredient_id' => $ingredient->id,
            'quantity' => 100,
            'unit_id' => $gram->id,
        ]]);
        app(InventoryLedgerService::class)->record([
            'idempotency_key' => 'production-request-stock',
            'item_type' => 'ingredient',
            'item_id' => $ingredient->id,
            'location_type' => 'warehouse',
            'location_id' => $warehouse->id,
            'movement_type' => 'opening_stock',
            'quantity' => 50,
            'unit_id' => $gram->id,
            'unit_cost' => 10,
        ]);

        $requester = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $reviewer = User::where('email', 'finance@gmail.com')->firstOrFail();
        $service = app(ProductionRequestService::class);
        $request = $service->submit('req-001', $menu, $warehouse, 2, $requester);

        $this->assertSame('requested', $request->status);
        $this->assertSame('200.0000', $request->items->first()->required_quantity);
        $this->assertSame('50.0000', $request->items->first()->available_quantity);
        $this->assertSame('150.0000', $request->items->first()->shortage_quantity);
        $this->assertSame('2000.00', $request->estimated_material_cost);
        $this->assertSame($request->id, $service->submit('req-001', $menu, $warehouse, 2, $requester)->id);

        $approved = $service->review($request, $reviewer, true);
        $this->assertSame('approved', $approved->status);
        $this->expectException(ValidationException::class);
        $service->review($request, $reviewer, true);
    }

    public function test_role_permissions_separate_request_creation_from_approval(): void
    {
        $this->seed();

        $this->assertTrue(Role::findByName('warehouse')->hasPermissionTo('production-requests-create'));
        $this->assertFalse(Role::findByName('warehouse')->hasPermissionTo('production-requests-approve'));
        $this->assertTrue(Role::findByName('finance')->hasPermissionTo('production-requests-approve'));
        $this->assertFalse(Role::findByName('finance')->hasPermissionTo('production-requests-create'));

        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $this->actingAs($warehouseUser)
            ->get(route('production-requests.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/ProductionRequests/Index'));

        $this->actingAs(User::where('email', 'cashier@gmail.com')->firstOrFail())
            ->get(route('production-requests.index'))
            ->assertForbidden();
    }

    public function test_warehouse_can_open_ingredient_and_recipe_master_data(): void
    {
        $this->seed();
        $warehouse = User::where('email', 'warehouse@gmail.com')->firstOrFail();

        $this->actingAs($warehouse)->get(route('ingredients.index'))->assertOk();
        $this->actingAs($warehouse)->get(route('recipes.index'))->assertOk();

        $this->actingAs(User::where('email', 'manager@gmail.com')->firstOrFail())
            ->get(route('production-orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/ProductionOrders/Index')->where('canOperate', false));
        $this->actingAs(User::where('email', 'cashier@gmail.com')->firstOrFail())
            ->get(route('production-orders.index'))
            ->assertForbidden();
    }

    public function test_approved_request_can_be_completed_with_actual_usage_and_historical_hpp(): void
    {
        $this->seed();
        [$menu, $ingredient, $warehouse] = $this->fixtures();
        $gram = Unit::where('code', 'GRAM')->firstOrFail();
        $piece = Unit::where('code', 'PCS')->firstOrFail();
        $menu->units()->attach($piece->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 0, 'sell_price' => 20000]);
        app(RecipeService::class)->createVersion($menu, [['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit_id' => $gram->id]]);
        app(InventoryLedgerService::class)->record([
            'idempotency_key' => 'production-order-opening-stock', 'item_type' => 'ingredient',
            'item_id' => $ingredient->id, 'location_type' => 'warehouse', 'location_id' => $warehouse->id,
            'movement_type' => 'opening_stock', 'quantity' => 500, 'unit_id' => $gram->id, 'unit_cost' => 10,
        ]);

        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $request = app(ProductionRequestService::class)->submit('run-001', $menu, $warehouse, 2, $warehouseUser);
        app(InventoryLedgerService::class)->record([
            'idempotency_key' => 'production-order-price-change', 'item_type' => 'ingredient',
            'item_id' => $ingredient->id, 'location_type' => 'warehouse', 'location_id' => $warehouse->id,
            'movement_type' => 'purchase_receipt', 'quantity' => 500, 'unit_id' => $gram->id, 'unit_cost' => 30,
        ]);
        app(ProductionRequestService::class)->review($request, $finance, true);
        $service = app(ProductionOrderService::class);
        $order = $service->createFromApprovedRequest($request, $finance);
        $this->assertSame('planned', $order->status);
        $this->assertSame($order->id, $service->createFromApprovedRequest($request, $finance)->id);
        $service->start($order, $warehouseUser);
        $completed = $service->complete($order, [['ingredient_id' => $ingredient->id, 'quantity' => 180]], 1, $warehouseUser);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('180.0000', $completed->items->first()->actual_quantity);
        $this->assertSame('3600.00', $completed->actual_material_cost);
        $this->assertSame('3600.00', $completed->actual_unit_cost);
        $this->assertSame('820.0000', InventoryBalance::where('balance_key', "warehouse:{$warehouse->id}:ingredient:{$ingredient->id}")->value('quantity'));
        $this->assertSame('1.0000', InventoryBalance::where('balance_key', "warehouse:{$warehouse->id}:product:{$menu->id}")->value('quantity'));
        $this->assertDatabaseHas('product_warehouse', ['product_id' => $menu->id, 'warehouse_id' => $warehouse->id, 'stock' => 1]);
        $this->assertSame(1, (int) $menu->fresh()->stock);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(1, ProductionOrder::count());
        $this->assertDatabaseHas('operational_notifications', ['user_id' => $finance->id, 'warehouse_id' => $warehouse->id, 'event' => 'production.below_target']);
        $this->assertDatabaseHas('operational_notifications', ['user_id' => User::where('email', 'manager@gmail.com')->value('id'), 'warehouse_id' => $warehouse->id, 'event' => 'production.below_target']);
    }

    public function test_output_above_threshold_notifies_finance_and_manager(): void
    {
        $this->seed();
        config()->set('operations.production_output_variance_threshold', 2);
        [$menu, $ingredient, $warehouse] = $this->fixtures();
        $gram = Unit::where('code', 'GRAM')->firstOrFail();
        $piece = Unit::where('code', 'PCS')->firstOrFail();
        $menu->units()->attach($piece->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 0, 'sell_price' => 20000]);
        app(RecipeService::class)->createVersion($menu, [['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit_id' => $gram->id]]);
        app(InventoryLedgerService::class)->record([
            'idempotency_key' => 'production-output-variance-opening', 'item_type' => 'ingredient',
            'item_id' => $ingredient->id, 'location_type' => 'warehouse', 'location_id' => $warehouse->id,
            'movement_type' => 'opening_stock', 'quantity' => 1000, 'unit_id' => $gram->id, 'unit_cost' => 10,
        ]);
        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $request = app(ProductionRequestService::class)->submit('output-variance-request', $menu, $warehouse, 2, $warehouseUser);
        app(ProductionRequestService::class)->review($request, $finance, true);
        $service = app(ProductionOrderService::class);
        $order = $service->createFromApprovedRequest($request, $finance);
        $service->start($order, $warehouseUser);
        $service->complete($order, [['ingredient_id' => $ingredient->id, 'quantity' => 200]], 5, $warehouseUser);

        $this->assertDatabaseHas('operational_notifications', ['user_id' => $finance->id, 'warehouse_id' => $warehouse->id, 'event' => 'production.variance']);
        $this->assertDatabaseHas('operational_notifications', ['user_id' => $manager->id, 'warehouse_id' => $warehouse->id, 'event' => 'production.variance']);
    }

    private function fixtures(): array
    {
        $menu = Product::create([
            'image' => '', 'barcode' => 'PR-TEST', 'sku' => 'PR-TEST', 'title' => 'Menu PR',
            'description' => '', 'category_id' => Category::firstOrFail()->id,
            'buy_price' => 0, 'sell_price' => 20000, 'stock' => 0, 'tax_rate' => 0,
        ]);
        $ingredient = Ingredient::create([
            'code' => 'ING-PR', 'name' => 'Bahan PR',
            'ingredient_category_id' => IngredientCategory::firstOrFail()->id,
            'base_unit_id' => Unit::where('code', 'GRAM')->value('id'),
        ]);

        return [$menu, $ingredient, Warehouse::where('code', 'PUSAT')->firstOrFail()];
    }
}
