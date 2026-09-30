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
}
