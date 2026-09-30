<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\OutletAccessService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesByMenuReportController extends Controller
{
    public function __invoke(Request $request, OutletAccessService $outletAccess): Response
    {
        $warehouses = $outletAccess->salesWarehousesFor($request->user());
        $warehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id)->all();
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'warehouse_id' => ['nullable', 'integer', Rule::in($warehouseIds)],
        ]);

        $selectedWarehouseIds = isset($validated['warehouse_id'])
            ? [(int) $validated['warehouse_id']]
            : $warehouseIds;
        $showRevenue = $request->user()->can('reports-access') || $request->user()->can('profits-access');

        $returns = DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->where('sales_returns.status', 'completed')
            ->select('sales_return_items.transaction_detail_id')
            ->selectRaw('SUM(sales_return_items.qty_return) as qty_returned')
            ->selectRaw('SUM(sales_return_items.subtotal) as amount_returned')
            ->groupBy('sales_return_items.transaction_detail_id');

        $items = DB::table('transaction_details')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->leftJoinSub($returns, 'completed_returns', fn ($join) => $join->on('completed_returns.transaction_detail_id', '=', 'transaction_details.id'))
            ->whereIn('transactions.warehouse_id', $selectedWarehouseIds)
            ->where('transactions.payment_status', 'paid')
            ->when($validated['start_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transactions.created_at', '>=', $date))
            ->when($validated['end_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transactions.created_at', '<=', $date))
            ->select('products.id as product_id', 'products.title', 'products.sku')
            ->selectRaw('SUM(CASE WHEN transaction_details.qty > COALESCE(completed_returns.qty_returned, 0) THEN (transaction_details.qty - COALESCE(completed_returns.qty_returned, 0)) * COALESCE(transaction_details.conversion_factor, 1) ELSE 0 END) as pcs_sold')
            ->selectRaw('SUM(CASE WHEN transaction_details.qty > COALESCE(completed_returns.qty_returned, 0) THEN transaction_details.qty - COALESCE(completed_returns.qty_returned, 0) ELSE 0 END) as packages_sold')
            ->selectRaw('COUNT(DISTINCT transactions.id) as transactions_count')
            ->when($showRevenue, fn (Builder $query) => $query->selectRaw('SUM(CASE WHEN transaction_details.price > COALESCE(completed_returns.amount_returned, 0) THEN transaction_details.price - COALESCE(completed_returns.amount_returned, 0) ELSE 0 END) as net_sales'))
            ->groupBy('products.id', 'products.title', 'products.sku')
            ->orderByDesc('pcs_sold')
            ->orderBy('products.title')
            ->limit(200)
            ->get()
            ->map(function (object $row) use ($showRevenue) {
                $item = [
                    'product_id' => (int) $row->product_id,
                    'title' => $row->title,
                    'sku' => $row->sku,
                    'pcs_sold' => (float) $row->pcs_sold,
                    'packages_sold' => (int) $row->packages_sold,
                    'transactions_count' => (int) $row->transactions_count,
                ];
                if ($showRevenue) {
                    $item['net_sales'] = (int) $row->net_sales;
                }

                return $item;
            })
            ->values();

        return Inertia::render('Dashboard/Reports/SalesByMenu', [
            'filters' => [
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'warehouse_id' => isset($validated['warehouse_id']) ? (string) $validated['warehouse_id'] : '',
            ],
            'warehouses' => $warehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name, 'outlet_name' => $warehouse->outlet?->name])->values(),
            'items' => $items,
            'showRevenue' => $showRevenue,
        ]);
    }
}
