<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OutletStockReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutletStockReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_outlet_return_moves_only_the_verified_quantity_and_preserves_global_stock(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $source = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $central = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $source->update(['is_active' => true]);
        $category = Category::firstOrCreate(['name' => 'Return test']);
        $unit = Unit::firstOrCreate(['code' => 'RETPCS'], ['name' => 'Pcs', 'symbol' => 'pcs']);
        $product = Product::create([
            'title' => 'Produk return test', 'sku' => 'RET-001', 'barcode' => 'RET-001',
            'buy_price' => 4000, 'sell_price' => 10000, 'stock' => 20, 'image' => '',
            'description' => '', 'category_id' => $category->id, 'tax_rate' => 0,
        ]);
        $product->units()->attach($unit->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 4000, 'sell_price' => 10000]);
        ProductWarehouse::create(['product_id' => $product->id, 'warehouse_id' => $source->id, 'stock' => 20]);

        $service = app(OutletStockReturnService::class);
        $request = $service->create('outlet-return-1', $source, [['product_id' => $product->id, 'quantity' => 5]], $cashier);
        $sameRequest = $service->create('outlet-return-1', $source, [['product_id' => $product->id, 'quantity' => 5]], $cashier);
        $this->assertSame($request->id, $sameRequest->id);
        $this->assertSame(20, (int) ProductWarehouse::where('product_id', $product->id)->where('warehouse_id', $source->id)->value('stock'));

        $service->receive($request, [$request->items()->first()->id => 3], $warehouseUser, 'Dua rusak saat pemeriksaan');

        $this->assertSame('received', $request->fresh()->status);
        $this->assertSame(3, $request->items()->first()->quantity_received);
        $this->assertSame(17, (int) ProductWarehouse::where('product_id', $product->id)->where('warehouse_id', $source->id)->value('stock'));
        $this->assertSame(3, (int) ProductWarehouse::where('product_id', $product->id)->where('warehouse_id', $central->id)->value('stock'));
        $this->assertSame(20, (int) $product->fresh()->stock);
        $this->assertSame('17.0000', InventoryBalance::where('balance_key', "warehouse:{$source->id}:product:{$product->id}")->value('quantity'));
        $this->assertSame('3.0000', InventoryBalance::where('balance_key', "warehouse:{$central->id}:product:{$product->id}")->value('quantity'));
        $this->assertSame(2, InventoryLedger::where('reference_type', $request::class)->where('reference_id', $request->id)->count());
    }

    public function test_outlet_return_page_and_receive_action_are_limited_by_role(): void
    {
        $this->seed();
        $this->actingAs(User::where('email', 'cashier@gmail.com')->firstOrFail())
            ->get(route('outlet-stock-returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/OutletStockReturns/Index')->where('canCreate', true)->where('canReceive', false));

        $this->actingAs(User::where('email', 'manager@gmail.com')->firstOrFail())
            ->get(route('outlet-stock-returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canCreate', false)->where('canReceive', false));

        $this->actingAs(User::where('email', 'finance@gmail.com')->firstOrFail())
            ->get(route('outlet-stock-returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canCreate', false)->where('canReceive', false));
    }
}
