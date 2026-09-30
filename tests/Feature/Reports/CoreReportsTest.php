<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoreReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_sales_and_profit_reports_with_database_aggregates(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Sales')
                ->where('summary.orders_count', 0)
                ->where('summary.items_sold', 0));

        $this->actingAs($manager)
            ->get(route('reports.profits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Profit')
                ->where('summary.orders_count', 0)
                ->where('summary.items_sold', 0)
                ->where('summary.best_invoice', null));
    }

    public function test_report_date_filters_reject_invalid_dates_and_reversed_ranges(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('reports.sales.index', ['start_date' => 'not-a-date']))
            ->assertSessionHasErrors('start_date');

        $this->actingAs($manager)
            ->get(route('reports.profits.index', ['start_date' => '2026-06-02', 'end_date' => '2026-06-01']))
            ->assertSessionHasErrors('end_date');
    }

    public function test_manager_can_export_filtered_sales_and_profit_reports(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('reports.sales.index', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'export' => 1]))
            ->assertDownload('laporan-penjualan.xlsx');

        $this->actingAs($manager)
            ->get(route('reports.profits.index', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'export' => 1]))
            ->assertDownload('laporan-keuntungan.xlsx');
    }

    public function test_sales_report_lists_only_users_with_the_cashier_role(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('reports.sales.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('cashiers', 4));
    }

    public function test_finance_sees_central_warehouse_instead_of_an_outlet_switcher(): void
    {
        $this->seed();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();

        $this->actingAs($finance)
            ->get(route('reports.sales.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.isFinance', true)
                ->where('auth.centralWarehouse.code', 'PUSAT')
                ->has('warehouses', 5));

        $warehouse = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $this->assertFalse($warehouse->can('purchase-orders-create'));
        $this->assertFalse($warehouse->can('purchase-orders-update'));
        $this->assertFalse($warehouse->can('suppliers-access'));
        $this->assertFalse($warehouse->can('warehouses-create'));
        $this->assertFalse($warehouse->can('warehouses-update'));
        $this->assertTrue($finance->can('purchase-orders-create'));
        $this->assertTrue($finance->can('purchase-orders-update'));
        $this->assertFalse($finance->can('outlet-operations-access'));
        $this->assertFalse($finance->can('cash-handovers-access'));
    }
}
