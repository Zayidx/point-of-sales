<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_open_dashboard_without_a_setup_checklist(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('todaySales')
                ->where('canViewFinancialDashboard', true)
                ->where('canAccessTransactions', true)
                ->where('auth.permissions.dashboard-access', true)
                ->where('auth.permissions.reports-access', true)
                ->where('auth.permissions.profits-access', true)
                ->missing('auth.permissions.users-access')
                ->has('managerOperations.revenue_by_outlet', 0)
                ->where('managerOperations.total_revenue', 0)
                ->has('managerOperations.outlet_closing', 4)
                ->has('managerOperations.inventory.ingredients')
                ->has('managerOperations.inventory.finished_goods')
                ->where('managerOperations.gross_profit', 0)
                ->has('accountingSummary.pending_handovers')
                ->has('accountingSummary.revenue_by_outlet')
                ->has('accountingSummary.revenue')
                ->where('accountingSummary.cash_sales', 0)
                ->missing('setupChecklist'));

        $this->actingAs($manager)->get(route('reports.operations.index'))->assertOk();
        $this->actingAs($manager)->get(route('users.index'))->assertForbidden();
    }

    public function test_finance_dashboard_includes_accounting_metrics_for_accessible_warehouses(): void
    {
        $this->seed();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();

        $this->actingAs($finance)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewFinancialDashboard', true)
                ->has('accountingSummary.revenue_by_outlet')
                ->has('accountingSummary.cash_sales')
                ->has('accountingSummary.qris_sales')
                ->has('accountingSummary.online_sales')
                ->has('accountingSummary.operational_expenses')
                ->has('accountingSummary.purchases')
                ->has('accountingSummary.cogs')
                ->has('accountingSummary.gross_profit')
                ->has('accountingSummary.warehouse_cash')
                ->has('accountingSummary.pending_handovers.amount'));
    }

    public function test_warehouse_dashboard_hides_transaction_shortcut(): void
    {
        $this->seed();
        $warehouse = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $location = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        app(CashierShiftService::class)->openShift($warehouse, $warehouse, 10000, null, $location->id, $location->outlet_id);

        $this->actingAs($warehouse)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAccessTransactions', false)
                ->where('canViewFinancialDashboard', false)
                ->where('managerOperations', null)
                ->where('accountingSummary', null)
                ->has('revenueTrend', 0)
                ->where('todaySales', 0)
                ->where('todayProfit', 0)
                ->has('activeShifts', 1)
                ->missing('activeShifts.0.opening_cash')
                ->missing('activeShifts.0.expected_cash')
                ->missing('activeShifts.0.cash_sales_total'));
    }
}
