<?php

namespace App\Http\Controllers\Reports;

use App\Exports\OperationsReportExport;
use App\Http\Controllers\Controller;
use App\Models\CashHandover;
use App\Models\CashierShiftStockCount;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\OutletExpense;
use App\Models\OutletWasteRecord;
use App\Models\ProductionOrder;
use App\Models\Profit;
use App\Models\PurchaseOrderItem;
use App\Models\StockOpnameItem;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Models\WarehouseCashLedger;
use App\Services\OutletAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OperationsReportController extends Controller
{
    public function index(Request $request, OutletAccessService $outletAccess): Response|BinaryFileResponse
    {
        $warehouses = $outletAccess->warehousesFor($request->user());
        $warehouseIds = $warehouses->pluck('id');
        $filters = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $filters['warehouse_id'] ??= null;
        if (isset($filters['warehouse_id']) && ! $warehouseIds->contains((int) $filters['warehouse_id'])) {
            $filters['warehouse_id'] = null;
        }
        $filters['start_date'] ??= now()->startOfMonth()->toDateString();
        $filters['end_date'] ??= now()->toDateString();
        $scopeIds = $filters['warehouse_id'] ? collect([(int) $filters['warehouse_id']]) : $warehouseIds;
        $dateScope = fn (Builder $query, string $column = 'created_at') => $query
            ->whereDate($column, '>=', $filters['start_date'])
            ->whereDate($column, '<=', $filters['end_date']);

        $sales = $dateScope(Transaction::query()->whereIn('transactions.warehouse_id', $scopeIds), 'transactions.created_at');
        $revenue = (int) (clone $sales)->sum('grand_total');
        $orders = (int) (clone $sales)->count();
        $grossProfit = (int) Profit::query()
            ->join('transactions', 'transactions.id', '=', 'profits.transaction_id')
            ->whereIn('transactions.warehouse_id', $scopeIds)
            ->whereDate('transactions.created_at', '>=', $filters['start_date'])
            ->whereDate('transactions.created_at', '<=', $filters['end_date'])
            ->sum('profits.total');
        $cogs = $revenue - $grossProfit;

        $expenses = $dateScope(OutletExpense::query()->whereIn('outlet_expenses.warehouse_id', $scopeIds), 'outlet_expenses.created_at');
        $expenseTotal = (int) (clone $expenses)->sum('total');
        $expenseCategories = (clone $expenses)
            ->join('expense_categories', 'expense_categories.id', '=', 'outlet_expenses.expense_category_id')
            ->selectRaw('expense_categories.name, SUM(outlet_expenses.total) as total')
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc('total')
            ->get();

        $waste = $dateScope(OutletWasteRecord::query()->whereIn('warehouse_id', $scopeIds));
        $wasteTotal = (int) (clone $waste)->sum('total_cost');
        $purchaseTotal = (int) PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.warehouse_id', $scopeIds)
            ->whereNotIn('purchase_orders.status', ['cancelled', 'rejected'])
            ->whereDate('purchase_orders.ordered_at', '>=', $filters['start_date'])
            ->whereDate('purchase_orders.ordered_at', '<=', $filters['end_date'])
            ->sum(\DB::raw('purchase_order_items.qty_ordered * purchase_order_items.unit_price'));

        $cashHandovers = $dateScope(CashHandover::query()->whereIn('warehouse_id', $scopeIds));
        $cashSummary = (clone $cashHandovers)->selectRaw("COUNT(*) as total_count, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count, COALESCE(SUM(expected_cash), 0) as expected_cash, COALESCE(SUM(cashier_amount), 0) as cashier_amount, COALESCE(SUM(received_amount), 0) as received_amount, COALESCE(SUM(variance), 0) as variance")->first();

        $warehouseCash = $dateScope(WarehouseCashLedger::query()->whereIn('warehouse_id', $scopeIds));
        $warehouseCashSummary = (clone $warehouseCash)
            ->selectRaw("warehouse_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) as inflow, SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) as outflow")
            ->groupBy('warehouse_id')
            ->with('warehouse:id,name,code')
            ->get()
            ->map(fn (WarehouseCashLedger $row) => [
                'warehouse' => $row->warehouse?->name,
                'inflow' => (int) $row->inflow,
                'outflow' => (int) $row->outflow,
                'balance_change' => (int) $row->inflow - (int) $row->outflow,
            ]);

        $inventory = InventoryBalance::query()->where('location_type', 'warehouse')->whereIn('warehouse_id', $scopeIds)
            ->selectRaw('item_type, SUM(quantity) as quantity, SUM(inventory_value) as value')
            ->groupBy('item_type')->get()
            ->map(fn (InventoryBalance $row) => ['item_type' => $row->item_type, 'quantity' => (float) $row->quantity, 'value' => (int) $row->value]);

        $ledger = $dateScope(InventoryLedger::query()->whereIn('inventory_ledgers.warehouse_id', $scopeIds), 'inventory_ledgers.created_at')
            ->leftJoin('products', 'products.id', '=', 'inventory_ledgers.product_id')
            ->leftJoin('ingredients', 'ingredients.id', '=', 'inventory_ledgers.ingredient_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'inventory_ledgers.warehouse_id')
            ->select(['inventory_ledgers.id', 'inventory_ledgers.reference_number', 'inventory_ledgers.movement_type', 'inventory_ledgers.quantity', 'inventory_ledgers.total_cost', 'inventory_ledgers.created_at', 'warehouses.name as warehouse_name'])
            ->selectRaw('COALESCE(products.title, ingredients.name) as item_name')
            ->latest('inventory_ledgers.created_at')->limit(50)->get();

        $production = $dateScope(ProductionOrder::query()->whereIn('warehouse_id', $scopeIds), 'completed_at');
        $productionSummary = (clone $production)->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(planned_output), 0) as planned_output, COALESCE(SUM(actual_output), 0) as actual_output, COALESCE(SUM(actual_material_cost), 0) as material_cost')->first();
        $productionOrders = (clone $production)->with(['product:id,title', 'warehouse:id,name'])->latest('completed_at')->limit(20)->get();

        $stockVariance = (int) StockOpnameItem::query()
            ->join('stock_opnames', 'stock_opnames.id', '=', 'stock_opname_items.stock_opname_id')
            ->whereIn('stock_opnames.warehouse_id', $scopeIds)
            ->where('stock_opnames.status', 'completed')
            ->whereDate('stock_opnames.finalized_at', '>=', $filters['start_date'])
            ->whereDate('stock_opnames.finalized_at', '<=', $filters['end_date'])
            ->sum(\DB::raw('ABS(stock_opname_items.difference)'));
        $stockVariance += (int) CashierShiftStockCount::query()
            ->join('cashier_shifts', 'cashier_shifts.id', '=', 'cashier_shift_stock_counts.cashier_shift_id')
            ->whereIn('cashier_shifts.warehouse_id', $scopeIds)
            ->whereDate('cashier_shift_stock_counts.created_at', '>=', $filters['start_date'])
            ->whereDate('cashier_shift_stock_counts.created_at', '<=', $filters['end_date'])
            ->sum(\DB::raw('ABS(cashier_shift_stock_counts.variance)'));

        $salesByPayment = (clone $sales)->selectRaw('payment_method, COUNT(*) as orders_count, SUM(grand_total) as total')
            ->groupBy('payment_method')->orderByDesc('total')->get();
        $salesByOutlet = (clone $sales)->leftJoin('warehouses', 'warehouses.id', '=', 'transactions.warehouse_id')
            ->leftJoin('outlets as sales_outlets', 'sales_outlets.id', '=', 'transactions.outlet_id')
            ->leftJoin('outlets as warehouse_outlets', 'warehouse_outlets.id', '=', 'warehouses.outlet_id')
            ->selectRaw('transactions.outlet_id as outlet_id, warehouses.name as warehouse_name, COALESCE(sales_outlets.name, warehouse_outlets.name, warehouses.name) as outlet_name, COUNT(*) as orders_count, SUM(transactions.grand_total) as total')
            ->groupBy('warehouses.name', 'transactions.outlet_id', 'sales_outlets.name', 'warehouse_outlets.name')
            ->orderByDesc('total')->get();
        $wasteRecords = (clone $waste)->with(['product:id,title', 'warehouse:id,name'])->latest()->limit(20)->get();
        $cashHandoverRows = (clone $cashHandovers)->with(['warehouse:id,name', 'cashier:id,name'])->latest()->limit(20)->get();

        if ($request->boolean('export')) {
            $rows = [];
            foreach ([
                'Penjualan' => $revenue,
                'HPP' => $cogs,
                'Laba kotor' => $grossProfit,
                'Biaya operasional' => $expenseTotal,
                'Biaya waste' => $wasteTotal,
                'Hasil operasional' => $grossProfit - $expenseTotal - $wasteTotal,
                'Pembelian' => $purchaseTotal,
                'Selisih stok' => $stockVariance,
            ] as $label => $value) {
                $rows[] = ['Ringkasan', '', '', '', $label, '', $value, ''];
            }
            foreach ($salesByOutlet as $row) {
                $rows[] = ['Penjualan per cabang', '', '', $row->outlet_name, '', $row->orders_count, $row->total, ''];
            }
            foreach ($salesByPayment as $row) {
                $rows[] = ['Penjualan per pembayaran', '', '', '', $row->payment_method, $row->orders_count, $row->total, ''];
            }
            foreach ($expenseCategories as $row) {
                $rows[] = ['Biaya operasional', '', '', '', $row->name, '', $row->total, ''];
            }
            foreach ($inventory as $row) {
                $rows[] = ['Persediaan '.$row->item_type, '', '', '', '', $row->quantity, $row->value, ''];
            }
            foreach ($productionOrders as $row) {
                $rows[] = ['Produksi', optional($row->completed_at)->toDateString(), $row->order_number, $row->warehouse?->name, $row->product?->title, $row->actual_output, $row->actual_material_cost, $row->status];
            }
            foreach ($wasteRecords as $row) {
                $rows[] = ['Waste', optional($row->created_at)->toDateString(), $row->waste_number, $row->warehouse?->name, $row->product?->title, $row->quantity, $row->total_cost, $row->reason];
            }
            foreach ($ledger as $row) {
                $rows[] = ['Mutasi stok', optional($row->created_at)->toDateString(), $row->reference_number, $row->warehouse_name, $row->item_name, $row->quantity, $row->total_cost, $row->movement_type];
            }
            foreach ($cashHandoverRows as $row) {
                $rows[] = ['Serah terima kas', optional($row->created_at)->toDateString(), $row->handover_number, $row->warehouse?->name, $row->cashier?->name, $row->received_amount, $row->variance, $row->status];
            }
            foreach ($warehouseCashSummary as $row) {
                $rows[] = ['Kas gudang', '', '', $row['warehouse'], '', $row['inflow'] - $row['outflow'], $row['balance_change'], ''];
            }

            return Excel::download(new OperationsReportExport($rows), 'laporan-operasional.xlsx');
        }

        return Inertia::render('Dashboard/Reports/Operations', [
            'filters' => $filters,
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse) => $warehouse->only(['id', 'code', 'name']))->values(),
            'summary' => [
                'revenue' => $revenue,
                'orders' => $orders,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'expenses' => $expenseTotal,
                'waste_cost' => $wasteTotal,
                'net_operating_result' => $grossProfit - $expenseTotal - $wasteTotal,
                'purchase_total' => $purchaseTotal,
                'stock_variance' => $stockVariance,
                'cash_handover' => [
                    'total_count' => (int) ($cashSummary->total_count ?? 0),
                    'pending_count' => (int) ($cashSummary->pending_count ?? 0),
                    'expected_cash' => (int) ($cashSummary->expected_cash ?? 0),
                    'cashier_amount' => (int) ($cashSummary->cashier_amount ?? 0),
                    'received_amount' => (int) ($cashSummary->received_amount ?? 0),
                    'variance' => (int) ($cashSummary->variance ?? 0),
                ],
                'production' => [
                    'orders_count' => (int) ($productionSummary->orders_count ?? 0),
                    'planned_output' => (float) ($productionSummary->planned_output ?? 0),
                    'actual_output' => (float) ($productionSummary->actual_output ?? 0),
                    'material_cost' => (int) ($productionSummary->material_cost ?? 0),
                ],
            ],
            'salesByPayment' => $salesByPayment,
            'salesByOutlet' => $salesByOutlet,
            'expenseCategories' => $expenseCategories,
            'inventory' => $inventory,
            'ledger' => $ledger,
            'productionOrders' => $productionOrders,
            'wasteRecords' => $wasteRecords,
            'cashHandovers' => $cashHandoverRows,
            'warehouseCash' => $warehouseCashSummary,
        ]);
    }
}
