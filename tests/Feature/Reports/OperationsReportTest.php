<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\OutletOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_open_scoped_operations_reports(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('reports.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Operations')
                ->has('summary.cash_handover')
                ->has('summary.production')
                ->has('salesByPayment')
                ->has('salesByOutlet')
                ->has('expenseCategories')
                ->has('inventory')
                ->has('ledger')
                ->has('productionOrders')
                ->has('wasteRecords')
                ->has('cashHandovers')
                ->has('warehouseCash')
                ->where('summary.revenue', 0));
    }

    public function test_operations_report_rejects_reversed_date_range(): void
    {
        $this->seed();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();

        $this->actingAs($finance)
            ->get(route('reports.operations.index', ['start_date' => '2026-06-02', 'end_date' => '2026-06-01']))
            ->assertSessionHasErrors('end_date');
    }

    public function test_manager_can_export_the_filtered_operational_report_to_excel(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->withoutExceptionHandling();
        $response = $this->actingAs($manager)->get(route('reports.operations.index', ['export' => 1]));
        $response->assertDownload('laporan-operasional.xlsx');
    }

    public function test_operations_report_summarizes_outlet_expenses_by_category(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $shift = app(CashierShiftService::class)->openShift($cashier, $cashier, 100000, null, $warehouse->id);
        $category = ExpenseCategory::where('name', 'Energi/Gas')->firstOrFail();
        app(OutletOperationsService::class)->recordExpense('operations-report-expense', $shift, $category, [
            'item_name' => 'Gas', 'quantity' => 1, 'unit_price' => 25000, 'total' => 25000,
            'payment_source' => 'outlet_cash',
        ], $cashier);
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('reports.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.expenses', 25000)
                ->where('expenseCategories.0.name', 'Energi/Gas')
                ->where('expenseCategories.0.total', 25000));
    }

    public function test_operations_report_uses_stored_gross_profit_to_derive_cogs(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $product = Product::create([
            'image' => 'product.png',
            'barcode' => 'REPORT-COGS-001',
            'sku' => 'REPORT-COGS-001',
            'title' => 'Produk Laporan',
            'description' => 'Produk untuk menguji perhitungan laporan',
            'buy_price' => 5000,
            'sell_price' => 12000,
            'category_id' => Category::firstOrFail()->id,
            'stock' => 10,
            'tax_rate' => 0,
        ]);
        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'warehouse_id' => $warehouse->id,
            'outlet_id' => $cashier->outlets()->value('outlets.id'),
            'invoice' => 'TRX-REPORT-COGS-001',
            'cash' => 10000,
            'change' => 0,
            'discount' => 2000,
            'shipping_cost' => 0,
            'grand_total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 12000,
        ]);
        $transaction->profits()->create(['total' => 5000]);

        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->actingAs($manager)
            ->get(route('reports.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.revenue', 10000)
                ->where('summary.cogs', 5000)
                ->where('summary.gross_profit', 5000)
                ->where('summary.net_operating_result', 5000)
                ->where('salesByOutlet.0.outlet_id', $cashier->outlets()->value('outlets.id')));
    }
}
