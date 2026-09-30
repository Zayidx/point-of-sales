<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashierShiftStockCount;
use App\Models\InventoryBalance;
use App\Models\OutletExpense;
use App\Models\OutletWasteRecord;
use App\Models\ProductionOrder;
use App\Models\Profit;
use App\Models\PurchaseOrderItem;
use App\Models\StockOpnameItem;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\WarehouseCashLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountantDashboardSummaryService
{
    /** @return array<string, mixed> */
    public function summarize(Collection $warehouses): array
    {
        $warehouseIds = $warehouses->pluck('id');
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $sales = Transaction::query()
            ->whereIn('transactions.warehouse_id', $warehouseIds)
            ->where('transactions.payment_status', 'paid')
            ->whereBetween('transactions.created_at', [$start, $end]);

        $revenueByOutlet = (clone $sales)
            ->leftJoin('warehouses', 'warehouses.id', '=', 'transactions.warehouse_id')
            ->leftJoin('outlets as sales_outlets', 'sales_outlets.id', '=', 'transactions.outlet_id')
            ->leftJoin('outlets as warehouse_outlets', 'warehouse_outlets.id', '=', 'warehouses.outlet_id')
            ->selectRaw('COALESCE(transactions.outlet_id, warehouse_outlets.id, warehouses.id) as outlet_id, COALESCE(sales_outlets.name, warehouse_outlets.name, warehouses.name) as outlet, SUM(transactions.grand_total) as revenue')
            ->groupBy('transactions.outlet_id', 'warehouse_outlets.id', 'warehouses.id', 'sales_outlets.name', 'warehouse_outlets.name', 'warehouses.name')
            ->orderBy('outlet')
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (int) $row->outlet_id,
                'outlet' => $row->outlet,
                'revenue' => (int) $row->revenue,
            ]);

        $paidTenders = TransactionTender::query()
            ->where('payment_status', TransactionTender::STATUS_PAID)
            ->whereHas('transaction', fn ($query) => $query
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereBetween('created_at', [$start, $end]))
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        $legacyPayments = (clone $sales)
            ->whereDoesntHave('tenders')
            ->selectRaw('payment_method as method, SUM(grand_total) as total')
            ->groupBy('payment_method')
            ->get();
        $paymentTotals = $paidTenders->map(fn ($row) => (int) $row->total)->all();
        foreach ($legacyPayments as $row) {
            $paymentTotals[$row->method] = ($paymentTotals[$row->method] ?? 0) + (int) $row->total;
        }

        $paymentSales = [
            'cash' => (int) ($paymentTotals['cash'] ?? 0),
            'qris' => (int) (($paymentTotals['qris'] ?? 0) + ($paymentTotals['qris_1'] ?? 0) + ($paymentTotals['qris_2'] ?? 0) + ($paymentTotals['qris_3'] ?? 0) + ($paymentTotals['midtrans'] ?? 0) + ($paymentTotals['xendit'] ?? 0)),
            'online' => (int) (($paymentTotals['online'] ?? 0) + ($paymentTotals['gofood'] ?? 0)),
        ];
        $paymentSales['other'] = (int) array_sum($paymentTotals) - array_sum($paymentSales);

        $grossProfit = (int) Profit::query()
            ->join('transactions', 'transactions.id', '=', 'profits.transaction_id')
            ->whereIn('transactions.warehouse_id', $warehouseIds)
            ->where('transactions.payment_status', 'paid')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->sum('profits.total');
        $revenue = (int) (clone $sales)->sum('grand_total');

        $inventory = InventoryBalance::query()
            ->where('location_type', 'warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('item_type, COALESCE(SUM(inventory_value), 0) as value, COALESCE(SUM(quantity), 0) as quantity')
            ->groupBy('item_type')
            ->get()
            ->keyBy('item_type');

        $production = ProductionOrder::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(planned_output), 0) as planned, COALESCE(SUM(actual_output), 0) as actual')
            ->first();

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

        $cash = WarehouseCashLedger::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as balance")
            ->value('balance');
        $pendingHandovers = CashHandover::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(expected_cash), 0) as amount')
            ->first();

        $purchases = (int) PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.warehouse_id', $warehouseIds)
            ->whereNotIn('purchase_orders.status', ['cancelled', 'rejected'])
            ->whereBetween('purchase_orders.ordered_at', [$start, $end])
            ->sum(DB::raw('purchase_order_items.qty_ordered * purchase_order_items.unit_price'));

        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'revenue' => $revenue,
            'revenue_by_outlet' => $revenueByOutlet,
            'cash_sales' => $paymentSales['cash'],
            'qris_sales' => $paymentSales['qris'],
            'online_sales' => $paymentSales['online'],
            'other_payment_sales' => $paymentSales['other'],
            'operational_expenses' => (int) OutletExpense::query()->whereIn('warehouse_id', $warehouseIds)->whereBetween('created_at', [$start, $end])->sum('total'),
            'purchases' => $purchases,
            'cogs' => $revenue - $grossProfit,
            'gross_profit' => $grossProfit,
            'waste_cost' => (int) OutletWasteRecord::query()->whereIn('warehouse_id', $warehouseIds)->whereBetween('created_at', [$start, $end])->sum('total_cost'),
            'production_variance' => (float) ($production->actual ?? 0) - (float) ($production->planned ?? 0),
            'stock_variance' => $stockVariance,
            'warehouse_cash' => (int) $cash,
            'pending_handovers' => [
                'count' => (int) ($pendingHandovers->count ?? 0),
                'amount' => (int) ($pendingHandovers->amount ?? 0),
            ],
            'inventory' => [
                'ingredients' => ['value' => (int) ($inventory->get('ingredient')?->value ?? 0), 'quantity' => (float) ($inventory->get('ingredient')?->quantity ?? 0)],
                'finished_goods' => ['value' => (int) ($inventory->get('product')?->value ?? 0), 'quantity' => (float) ($inventory->get('product')?->quantity ?? 0)],
            ],
        ];
    }
}
