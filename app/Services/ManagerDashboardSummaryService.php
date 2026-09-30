<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashierShift;
use App\Models\CashierShiftStockCount;
use App\Models\InventoryBalance;
use App\Models\OutletExpense;
use App\Models\OutletWasteRecord;
use App\Models\ProductionOrder;
use App\Models\Profit;
use App\Models\StockOpnameItem;
use App\Models\Transaction;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManagerDashboardSummaryService
{
    /** @return array<string, mixed> */
    public function summarize(Collection $warehouses): array
    {
        $warehouseIds = $warehouses->pluck('id');
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();
        $today = Carbon::today();

        $revenueByOutlet = Transaction::query()
            ->leftJoin('warehouses', 'warehouses.id', '=', 'transactions.warehouse_id')
            ->leftJoin('outlets as sales_outlets', 'sales_outlets.id', '=', 'transactions.outlet_id')
            ->leftJoin('outlets as warehouse_outlets', 'warehouse_outlets.id', '=', 'warehouses.outlet_id')
            ->whereIn('transactions.warehouse_id', $warehouseIds)
            ->where('transactions.payment_status', 'paid')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw('COALESCE(transactions.outlet_id, warehouse_outlets.id, warehouses.id) as outlet_id, COALESCE(sales_outlets.name, warehouse_outlets.name, warehouses.name) as outlet_name, SUM(transactions.grand_total) as revenue')
            ->groupBy('transactions.outlet_id', 'warehouse_outlets.id', 'warehouses.id', 'sales_outlets.name', 'warehouse_outlets.name', 'warehouses.name')
            ->orderBy('outlet_name')
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (int) $row->outlet_id,
                'outlet' => $row->outlet_name,
                'revenue' => (int) $row->revenue,
            ]);
        $totalRevenue = (int) Transaction::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->sum('grand_total');

        $production = ProductionOrder::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(planned_output), 0) as planned, COALESCE(SUM(actual_output), 0) as actual')
            ->first();

        $grossProfit = (int) Profit::query()
            ->join('transactions', 'transactions.id', '=', 'profits.transaction_id')
            ->whereIn('transactions.warehouse_id', $warehouseIds)
            ->where('transactions.payment_status', 'paid')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->sum('profits.total');

        $inventory = InventoryBalance::query()
            ->where('location_type', 'warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('item_type, COALESCE(SUM(inventory_value), 0) as value, COALESCE(SUM(quantity), 0) as quantity')
            ->groupBy('item_type')
            ->get()
            ->keyBy('item_type');

        $stockVariance = (int) StockOpnameItem::query()
            ->join('stock_opnames', 'stock_opnames.id', '=', 'stock_opname_items.stock_opname_id')
            ->whereIn('stock_opnames.warehouse_id', $warehouseIds)
            ->where('stock_opnames.status', 'completed')
            ->whereBetween('stock_opnames.finalized_at', [$start, $end])
            ->sum(DB::raw('ABS(stock_opname_items.difference)'));
        $stockVariance += (int) CashierShiftStockCount::query()
            ->join('cashier_shifts', 'cashier_shifts.id', '=', 'cashier_shift_stock_counts.cashier_shift_id')
            ->whereIn('cashier_shifts.warehouse_id', $warehouseIds)
            ->whereBetween('cashier_shift_stock_counts.created_at', [$start, $end])
            ->sum(DB::raw('ABS(cashier_shift_stock_counts.variance)'));

        $latestShifts = CashierShift::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereDate('opened_at', $today)
            ->orderByDesc('opened_at')
            ->get(['outlet_id', 'status', 'closed_at'])
            ->unique('outlet_id')
            ->keyBy('outlet_id');

        $warehouseCash = DB::table('warehouse_cash_ledger')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as balance")
            ->value('balance');
        $pendingHandovers = CashHandover::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(expected_cash), 0) as amount')
            ->first();

        $closingOutlets = $warehouses
            ->flatMap(fn (Warehouse $warehouse) => collect([$warehouse->outlet])->merge($warehouse->outlets ?? collect()))
            ->filter(fn ($outlet) => $outlet?->is_sales_enabled)
            ->unique('id');
        $outletClosing = $closingOutlets
            ->map(function ($outlet) use ($latestShifts) {
                $shift = $latestShifts->get($outlet->id);
                $status = ! $shift ? 'not_opened' : ($shift->status === 'open' ? 'open' : 'closed');

                return [
                    'outlet' => $outlet->name,
                    'status' => $status,
                    'closed_at' => $shift?->closed_at?->toISOString(),
                ];
            })
            ->values();

        return [
            'total_revenue' => $totalRevenue,
            'revenue_by_outlet' => $revenueByOutlet,
            'expenses' => (int) OutletExpense::query()->whereIn('warehouse_id', $warehouseIds)->whereBetween('created_at', [$start, $end])->sum('total'),
            'gross_profit' => $grossProfit,
            'production' => [
                'orders' => (int) ($production->orders ?? 0),
                'planned_output' => (float) ($production->planned ?? 0),
                'actual_output' => (float) ($production->actual ?? 0),
            ],
            'inventory' => [
                'ingredients' => [
                    'value' => (int) ($inventory->get('ingredient')?->value ?? 0),
                    'quantity' => (float) ($inventory->get('ingredient')?->quantity ?? 0),
                ],
                'finished_goods' => [
                    'value' => (int) ($inventory->get('product')?->value ?? 0),
                    'quantity' => (float) ($inventory->get('product')?->quantity ?? 0),
                ],
            ],
            'waste_cost' => (int) OutletWasteRecord::query()->whereIn('warehouse_id', $warehouseIds)->whereBetween('created_at', [$start, $end])->sum('total_cost'),
            'stock_variance' => $stockVariance,
            'cash_variance' => (int) CashierShift::query()->whereIn('warehouse_id', $warehouseIds)->whereNotNull('closed_at')->whereBetween('closed_at', [$start, $end])->sum('cash_difference'),
            'warehouse_cash' => (int) $warehouseCash,
            'pending_handovers' => ['count' => (int) ($pendingHandovers->count ?? 0), 'amount' => (int) ($pendingHandovers->amount ?? 0)],
            'outlet_closing' => $outletClosing,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
        ];
    }
}
