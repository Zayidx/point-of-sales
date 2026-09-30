<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\InventoryBalance;
use App\Models\OutletExpense;
use App\Models\OutletWasteRecord;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\OutletOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutletOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_expense_reduces_expected_shift_cash_once(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $shift = app(CashierShiftService::class)->openShift($cashier, $cashier, 200000, null, $warehouse->id);
        $category = ExpenseCategory::where('name', 'Energi/Gas')->firstOrFail();
        $service = app(OutletOperationsService::class);
        $payload = [
            'item_name' => 'Gas LPG',
            'quantity' => 1,
            'unit_price' => 25000,
            'total' => 25000,
            'payment_source' => 'outlet_cash',
            'notes' => 'Operasional dapur',
        ];

        $expense = $service->recordExpense('expense-test-1', $shift, $category, $payload, $cashier);
        $again = $service->recordExpense('expense-test-1', $shift, $category, $payload, $cashier);

        $this->assertSame($expense->id, $again->id);
        $this->assertSame(1, OutletExpense::count());
        $this->assertSame(1, $shift->cashMovements()->count());
        $this->assertSame(175000, app(CashierShiftService::class)->calculateSummary($shift)['expected_cash']);
    }

    public function test_waste_records_loss_and_reduces_pos_and_ledger_stock(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $shift = app(CashierShiftService::class)->openShift($cashier, $cashier, 0, null, $warehouse->id);
        $category = Category::firstOrCreate(['name' => 'Menu waste']);
        $unit = Unit::firstOrCreate(['code' => 'WSTPCS'], ['name' => 'Pcs', 'symbol' => 'pcs']);
        $product = Product::create([
            'title' => 'Dimsum waste test', 'sku' => 'WASTE-001', 'barcode' => 'WASTE-001',
            'buy_price' => 4000, 'sell_price' => 10000, 'stock' => 20, 'image' => '',
            'description' => '', 'category_id' => $category->id, 'tax_rate' => 0,
        ]);
        $product->units()->attach($unit->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 4000, 'sell_price' => 10000]);
        ProductWarehouse::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock' => 20]);

        $record = app(OutletOperationsService::class)->recordWaste('waste-test-1', $shift, $product, 3, 'rusak', 'Kemasan terbuka', $cashier);

        $this->assertSame(3, (int) $record->quantity);
        $this->assertSame(12000, $record->total_cost);
        $this->assertSame(1, OutletWasteRecord::count());
        $this->assertSame(17, (int) $product->fresh()->stock);
        $this->assertSame(17, (int) ProductWarehouse::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('stock'));
        $this->assertSame('17.0000', InventoryBalance::where('balance_key', "warehouse:{$warehouse->id}:product:{$product->id}")->value('quantity'));
    }

    public function test_sidebar_operation_page_respects_role_permissions(): void
    {
        $this->seed();

        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->actingAs($manager)
            ->get(route('outlet-operations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/OutletOperations/Index')->where('canCreate', false));

        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->actingAs($cashier)->get(route('outlet-operations.index'))->assertOk();
        $this->actingAs(User::where('email', 'finance@gmail.com')->firstOrFail())
            ->get(route('outlet-operations.index'))
            ->assertOk();
    }
}
