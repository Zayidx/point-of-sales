<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Profit;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Services\AccountantDashboardSummaryService;
use App\Services\CashierShiftService;
use App\Services\ManagerDashboardSummaryService;
use App\Services\OutletAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(
        CashierShiftService $cashierShiftService,
        AccountantDashboardSummaryService $accountantDashboardSummary,
        OutletAccessService $outletAccessService,
        ManagerDashboardSummaryService $managerDashboardSummary,
    ) {
        $canViewFinancialDashboard = request()->user()->can('reports-access')
            || request()->user()->can('profits-access');
        $warehouseIds = $outletAccessService->warehousesFor(request()->user())->pluck('id');
        $activeOutlet = $outletAccessService->activeOutlet(request());
        $managerOperations = $canViewFinancialDashboard
            ? $managerDashboardSummary->summarize($outletAccessService->warehousesFor(request()->user()))
            : null;
        $accountingSummary = $canViewFinancialDashboard
            ? $accountantDashboardSummary->summarize($outletAccessService->warehousesFor(request()->user()))
            : null;
        if ($activeOutlet) {
            $warehouseIds = $outletAccessService->warehousesFor(request()->user())
                ->where('outlet_id', $activeOutlet->id)
                ->pluck('id');
        }
        $includeLegacy = Outlet::active()->count() <= 1;
        $scope = fn ($query, $column = 'warehouse_id') => $query->where(function ($q) use ($warehouseIds, $includeLegacy, $column) {
            $q->whereIn($column, $warehouseIds);
            if ($includeLegacy) {
                $q->orWhereNull($column);
            }
        });
        $scopeTransactions = fn ($query) => $scope($query);
        $scopeProfits = fn ($query) => $query->whereHas('transaction', fn ($q) => $scope($q));
        $scopeDetails = fn ($query) => $query->whereHas('transaction', fn ($q) => $scope($q));

        $totalCategories = Category::count();
        $totalProducts = Product::count();
        $totalTransactions = $scopeTransactions(Transaction::query())->count();
        $totalCustomers = Customer::count();
        $totalRevenue = $canViewFinancialDashboard ? $scopeTransactions(Transaction::query())->sum('grand_total') : 0;
        $totalProfit = $canViewFinancialDashboard ? $scopeProfits(Profit::query())->sum('total') : 0;
        $averageOrder = $canViewFinancialDashboard ? ($scopeTransactions(Transaction::query())->avg('grand_total') ?? 0) : 0;
        $todayTransactions = $scopeTransactions(Transaction::whereDate('created_at', Carbon::today()))->count();

        // New: Today's Sales and Profit
        $todaySales = $canViewFinancialDashboard ? $scopeTransactions(Transaction::whereDate('created_at', Carbon::today()))->sum('grand_total') : 0;
        $todayProfit = $canViewFinancialDashboard ? $scopeProfits(Profit::whereDate('created_at', Carbon::today()))->sum('total') : 0;

        // New: Monthly Target (from settings)
        $monthlyTarget = $canViewFinancialDashboard ? Setting::getForOutlet(
            'monthly_sales_target',
            $outletAccessService->activeOutlet(request()),
            0
        ) : 0;
        $currentMonthSales = $canViewFinancialDashboard ? $scopeTransactions(Transaction::whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year))
            ->sum('grand_total') : 0;

        $revenueTrend = $canViewFinancialDashboard ? $scopeTransactions(Transaction::selectRaw('DATE(created_at) as date, SUM(grand_total) as total'))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->take(12)
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'label' => Carbon::parse($row->date)->format('d M'),
                    'total' => (int) $row->total,
                ];
            })
            ->reverse()
            ->values() : collect();

        $topProducts = $scopeDetails(TransactionDetail::select('product_id', DB::raw('SUM(qty) as qty'), DB::raw('SUM(price) as total')))
            ->with('product:id,title,sku')
            ->groupBy('product_id')
            ->orderByDesc('qty')
            ->take(3)
            ->get()
            ->map(function ($detail) use ($canViewFinancialDashboard) {
                $data = [
                    'name' => $detail->product?->title ?? 'Produk terhapus',
                    'sku' => $detail->product?->sku ?? '-',
                    'qty' => (int) $detail->qty,
                ];

                if ($canViewFinancialDashboard) {
                    $data['total'] = (int) $detail->total;
                }

                return $data;
            });

        // New: Low Stock Products (stock < 10)
        $lowStockProducts = Product::query()
            ->joinSub(
                DB::table('product_warehouse')
                    ->select('product_id', DB::raw('SUM(stock) as warehouse_stock'))
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->groupBy('product_id'),
                'warehouse_totals',
                fn ($join) => $join->on('warehouse_totals.product_id', '=', 'products.id')
            )
            ->where(function ($q) use ($includeLegacy) {
                $q->where('warehouse_totals.warehouse_stock', '<', 10);
                if ($includeLegacy) {
                    $q->orWhere(function ($legacy) {
                        $legacy->whereNull('warehouse_totals.warehouse_stock')->where('products.stock', '<', 10);
                    });
                }
            })
            ->orderBy('warehouse_totals.warehouse_stock')
            ->take(5)
            ->get(['products.*', DB::raw('COALESCE(warehouse_totals.warehouse_stock, products.stock) as warehouse_stock')])
            ->map(function ($product) {
                return [
                    'name' => $product->title,
                    'stock' => (int) $product->warehouse_stock,
                    'image' => $product->image,
                ];
            });

        // New: Slow Moving Products (no sales in 30 days)
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $recentlySoldProductIds = $scopeDetails(TransactionDetail::where('transaction_details.created_at', '>=', $thirtyDaysAgo))
            ->distinct()
            ->pluck('product_id');

        $slowMovingProducts = Product::query()
            ->joinSub(
                DB::table('product_warehouse')
                    ->select('product_id', DB::raw('SUM(stock) as warehouse_stock'))
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->groupBy('product_id'),
                'warehouse_totals',
                fn ($join) => $join->on('warehouse_totals.product_id', '=', 'products.id')
            )
            ->whereNotIn('products.id', $recentlySoldProductIds)
            ->where('warehouse_totals.warehouse_stock', '>', 0)
            ->take(5)
            ->get(['products.*', 'warehouse_totals.warehouse_stock'])
            ->map(function ($product) {
                return [
                    'name' => $product->title,
                    'stock' => (int) $product->warehouse_stock,
                    'image' => $product->image,
                ];
            });

        $recentTransactions = $scopeTransactions(Transaction::with('cashier:id,name', 'customer:id,name'))
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($transaction) use ($canViewFinancialDashboard) {
                $data = [
                    'invoice' => $transaction->invoice,
                    'date' => Carbon::parse($transaction->created_at)->format('d M Y'),
                    'customer' => $transaction->customer?->name ?? '-',
                    'cashier' => $transaction->cashier?->name ?? '-',
                ];

                if ($canViewFinancialDashboard) {
                    $data['total'] = (int) $transaction->grand_total;
                }

                return $data;
            });

        $topCustomers = $scopeTransactions(Transaction::select('customer_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(grand_total) as total')))
            ->with('customer:id,name')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('total')
            ->take(5)
            ->get()
            ->map(function ($row) use ($canViewFinancialDashboard) {
                $data = [
                    'name' => $row->customer?->name ?? 'Pelanggan',
                    'orders' => (int) $row->orders,
                ];

                if ($canViewFinancialDashboard) {
                    $data['total'] = (int) $row->total;
                }

                return $data;
            });

        $topLocations = $scopeTransactions(Transaction::join('customers', 'transactions.customer_id', '=', 'customers.id'))
            ->select('customers.village_name', DB::raw('COUNT(*) as orders'))
            ->whereNotNull('customers.village_name')
            ->groupBy('customers.village_name')
            ->orderByDesc('orders')
            ->take(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->village_name ?? 'Lainnya',
                    'orders' => (int) $row->orders,
                ];
            });

        $activeShifts = CashierShift::query()
            ->with('user:id,name')
            ->open()
            ->whereIn('warehouse_id', $warehouseIds)
            ->latest('opened_at')
            ->take(5)
            ->get()
            ->map(function (CashierShift $shift) use ($cashierShiftService, $canViewFinancialDashboard) {
                $summary = $cashierShiftService->calculateSummary($shift);

                $data = [
                    'id' => $shift->id,
                    'opened_at' => optional($shift->opened_at)?->toISOString(),
                    'transactions_count' => $summary['transactions_count'],
                    'user' => [
                        'id' => $shift->user?->id,
                        'name' => $shift->user?->name,
                    ],
                ];

                if ($canViewFinancialDashboard) {
                    $data['opening_cash'] = (int) $shift->opening_cash;
                    $data['expected_cash'] = $summary['expected_cash'];
                    $data['cash_sales_total'] = $summary['cash_sales_total'];
                }

                return $data;
            })
            ->values();

        return Inertia::render('Dashboard/Index', [
            'totalCategories' => $totalCategories,
            'totalProducts' => $totalProducts,
            'totalTransactions' => $totalTransactions,
            'totalCustomers' => $totalCustomers,
            'revenueTrend' => $revenueTrend,
            'totalRevenue' => (int) $totalRevenue,
            'totalProfit' => (int) $totalProfit,
            'averageOrder' => (int) round($averageOrder),
            'todayTransactions' => (int) $todayTransactions,
            'todaySales' => (int) $todaySales,
            'todayProfit' => (int) $todayProfit,
            'monthlyTarget' => (int) $monthlyTarget,
            'currentMonthSales' => (int) $currentMonthSales,
            'topProducts' => $topProducts,
            'lowStockProducts' => $lowStockProducts,
            'slowMovingProducts' => $slowMovingProducts,
            'recentTransactions' => $recentTransactions,
            'topCustomers' => $topCustomers,
            'topLocations' => $topLocations,
            'activeShifts' => $activeShifts,
            'canAccessTransactions' => request()->user()->can('transactions-access'),
            'canViewFinancialDashboard' => $canViewFinancialDashboard,
            'managerOperations' => $managerOperations,
            'accountingSummary' => $accountingSummary,
        ]);
    }
}
