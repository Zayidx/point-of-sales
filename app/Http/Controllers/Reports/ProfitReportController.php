<?php

namespace App\Http\Controllers\Reports;

use App\Exports\ReportTransactionsExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Profit;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ProfitReportController extends Controller
{
    public function index(Request $request, OutletAccessService $outletAccessService)
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'invoice' => ['nullable', 'string', 'max:100'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $warehouseIds = $outletAccessService->warehousesFor($request->user())->pluck('id');
        $activeOutlet = $outletAccessService->activeOutlet($request);
        if ($activeOutlet) {
            $warehouseIds = $outletAccessService->warehousesFor($request->user())
                ->where('outlet_id', $activeOutlet->id)
                ->pluck('id');
        }
        $filters = [
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'invoice' => $validated['invoice'] ?? null,
            'cashier_id' => $validated['cashier_id'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'warehouse_id' => $warehouseIds->contains((int) ($validated['warehouse_id'] ?? 0)) ? $validated['warehouse_id'] : null,
        ];

        $baseQuery = $this->applyFilters(
            Transaction::query()
                ->with(['cashier:id,name', 'customer:id,name', 'warehouse:id,code,name'])
                ->withSum('profits as total_profit', 'total')
                ->withSum('details as total_items', 'qty'),
            $filters,
            $warehouseIds
        )->orderByDesc('created_at');

        if ($request->boolean('export')) {
            return Excel::download(new ReportTransactionsExport(clone $baseQuery), 'laporan-keuntungan.xlsx');
        }

        $transactions = (clone $baseQuery)
            ->paginate(10)
            ->withQueryString();

        $transactionIds = (clone $baseQuery)->reorder()->select('transactions.id');

        $profitTotal = Profit::whereIn('transaction_id', $transactionIds)->sum('total');

        $revenueTotal = (clone $baseQuery)->sum('grand_total');

        $ordersCount = (clone $baseQuery)->count();

        $itemsSold = TransactionDetail::whereIn('transaction_id', $transactionIds)->sum('qty');

        $bestTransaction = (clone $baseQuery)->reorder()->orderByDesc('total_profit')->first();

        $summary = [
            'profit_total' => (int) $profitTotal,
            'revenue_total' => (int) $revenueTotal,
            'orders_count' => (int) $ordersCount,
            'items_sold' => (int) $itemsSold,
            'average_profit' => $ordersCount > 0 ? (int) round($profitTotal / $ordersCount) : 0,
            'margin' => $revenueTotal > 0 ? round(($profitTotal / $revenueTotal) * 100, 2) : 0,
            'best_invoice' => $bestTransaction?->invoice,
            'best_profit' => (int) ($bestTransaction?->total_profit ?? 0),
        ];

        return Inertia::render('Dashboard/Reports/Profit', [
            'transactions' => $transactions,
            'summary' => $summary,
            'filters' => $filters,
            'cashiers' => User::select('id', 'name')->orderBy('name')->get(),
            'customers' => Customer::select('id', 'name')->orderBy('name')->get(),
            'warehouses' => $outletAccessService->warehousesFor($request->user()),
        ]);
    }

    protected function applyFilters($query, array $filters, $warehouseIds = null)
    {
        return $query->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when($filters['invoice'] ?? null, fn ($q, $invoice) => $q->where('invoice', 'like', '%'.$invoice.'%'))
            ->when($filters['cashier_id'] ?? null, fn ($q, $cashier) => $q->where('cashier_id', $cashier))
            ->when($filters['customer_id'] ?? null, fn ($q, $customer) => $q->where('customer_id', $customer))
            ->when($filters['start_date'] ?? null, fn ($q, $start) => $q->whereDate('created_at', '>=', $start))
            ->when($filters['end_date'] ?? null, fn ($q, $end) => $q->whereDate('created_at', '<=', $end))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouse) => $q->where('warehouse_id', $warehouse));
    }
}
