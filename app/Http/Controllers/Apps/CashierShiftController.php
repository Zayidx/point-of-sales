<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseCashierShiftRequest;
use App\Http\Requests\ConfirmPasswordForForceCloseRequest;
use App\Http\Requests\StoreCashierShiftRequest;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\RecipeVersion;
use App\Models\ShiftCashMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\CashierShiftOpeningStockService;
use App\Services\CashierShiftService;
use App\Services\OutletAccessService;
use App\Services\ThermalPrintService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashierShiftController extends Controller
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService,
        private readonly CashierShiftOpeningStockService $openingStockService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'cashier_id' => $request->input('cashier_id'),
            'status' => $request->input('status'),
            'opened_from' => $request->input('opened_from'),
            'opened_to' => $request->input('opened_to'),
        ];

        $query = CashierShift::query()
            ->with(['user:id,name', 'openedBy:id,name', 'closedBy:id,name', 'warehouse:id,code,name'])
            ->when($filters['cashier_id'], fn (Builder $builder, $cashierId) => $builder->where('user_id', $cashierId))
            ->when($filters['status'], fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($filters['opened_from'], fn (Builder $builder, $date) => $builder->whereDate('opened_at', '>=', $date))
            ->when($filters['opened_to'], fn (Builder $builder, $date) => $builder->whereDate('opened_at', '<=', $date))
            ->latest('opened_at');

        $query = $this->cashierShiftService->visibleToUser($query, $request->user());

        $shifts = $query->paginate($this->perPage())->withQueryString();
        $shifts->through(fn (CashierShift $shift) => $this->transformShift($shift));

        $activeShift = $this->cashierShiftService->getActiveShiftForUser($request->user()->id);
        $cashiers = $this->visibleCashiers($request);

        $warehouses = $this->outletAccessService->salesWarehousesFor($request->user());

        return Inertia::render('Dashboard/CashierShifts/Index', [
            'shifts' => $shifts,
            'filters' => $filters,
            'cashiers' => $cashiers,
            'activeShift' => $activeShift ? $this->transformShift($activeShift) : null,
            'warehouses' => $warehouses,
            'canForceClose' => $request->user()->isSuperAdmin() || $request->user()->can('cashier-shifts-force-close'),
        ]);
    }

    public function show(Request $request, CashierShift $cashierShift): Response
    {
        $cashierShift = $this->resolveVisibleShift($request, $cashierShift);
        $cashierShift->load('stockCounts.product:id,title', 'openingItems.product:id,title', 'openingItems.ingredient:id,name', 'openingItems.unit:id,name,symbol');
        $consumptionAnalysis = $this->ingredientConsumptionAnalysis($cashierShift);
        $sharedStockWarehouse = $cashierShift->warehouse_id
            && $cashierShift->warehouse?->outlets()->where('outlets.is_sales_enabled', true)->count() > 1;

        return Inertia::render('Dashboard/CashierShifts/Show', [
            'cashierShift' => $this->transformShift($cashierShift),
            'closingIngredients' => $cashierShift->isOpen()
                ? $cashierShift->openingItems->where('item_type', 'ingredient')->map(fn ($item) => [
                    'id' => $item->id,
                    'ingredient_id' => $item->ingredient_id,
                    'name' => $item->ingredient?->name ?? 'Bahan',
                    'unit' => $item->unit?->name ?? $item->unit?->symbol ?? 'satuan',
                    'issued_quantity' => $item->quantity,
                ])->values()
                : [],
            'closingProducts' => $cashierShift->warehouse_id && ! $sharedStockWarehouse
                ? $cashierShift->warehouse->products()->orderBy('products.title')->get(['products.id', 'products.title', 'products.sku'])->map(fn ($product) => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'expected_stock' => (int) $product->pivot->stock,
                ])->values()
                : [],
            'consumptionAnalysis' => $consumptionAnalysis,
            'canForceClose' => $request->user()->isSuperAdmin() || $request->user()->can('cashier-shifts-force-close'),
        ]);
    }

    private function ingredientConsumptionAnalysis(CashierShift $shift): array
    {
        if ($shift->isOpen()) {
            return [];
        }

        $recipes = [];
        $soldUnits = [];
        foreach ($shift->transactions()->with('details')->get()->flatMap->details as $detail) {
            $baseKey = $detail->product_id.':base';
            $recipes[$baseKey] ??= RecipeVersion::query()->with('items.ingredient.baseUnit')
                ->where('product_id', $detail->product_id)->whereNull('unit_id')->orderByDesc('version_number')->first();
            $baseRecipe = $recipes[$baseKey];
            if ($baseRecipe) {
                $yield = max(0.0001, (float) $baseRecipe->yield_quantity);
                $output = (float) $detail->qty * (float) ($detail->conversion_factor ?? 1) / $yield;
                foreach ($baseRecipe->items as $item) {
                    $soldUnits[$item->ingredient_id] = ($soldUnits[$item->ingredient_id] ?? 0) + $output;
                }
            }

            if ($detail->unit_id) {
                $portionKey = $detail->product_id.':unit:'.$detail->unit_id;
                $recipes[$portionKey] ??= RecipeVersion::query()->with('items.ingredient.baseUnit')
                    ->where('product_id', $detail->product_id)->where('unit_id', $detail->unit_id)->orderByDesc('version_number')->first();
                $portionRecipe = $recipes[$portionKey];
                if ($portionRecipe) {
                    $yield = max(0.0001, (float) $portionRecipe->yield_quantity);
                    $output = (float) $detail->qty / $yield;
                    foreach ($portionRecipe->items as $item) {
                        $soldUnits[$item->ingredient_id] = ($soldUnits[$item->ingredient_id] ?? 0) + $output;
                    }
                }
            }
        }

        return $shift->openingItems
            ->where('item_type', 'ingredient')
            ->filter(fn ($item) => $item->closing_quantity !== null)
            ->map(function ($item) use ($soldUnits) {
                $consumed = max(0, (float) $item->quantity - (float) $item->closing_quantity);
                $sold = (float) ($soldUnits[$item->ingredient_id] ?? 0);

                return [
                    'name' => $item->ingredient?->name ?? 'Barang persediaan',
                    'unit' => $item->unit?->symbol ?? $item->unit?->name ?? '',
                    'consumed_quantity' => $consumed,
                    'sold_quantity' => $sold,
                    'average_per_sold' => $sold > 0 ? $consumed / $sold : null,
                ];
            })
            ->values()
            ->all();
    }

    public function store(StoreCashierShiftRequest $request): RedirectResponse
    {
        $warehouse = $request->validated('warehouse_id')
            ? Warehouse::find($request->validated('warehouse_id'))
            : $this->outletAccessService->salesWarehousesFor($request->user())->first();

        abort_unless($this->outletAccessService->canSellAtWarehouse($request->user(), $warehouse), 403);
        $outlet = $this->outletAccessService->activeOutlet($request);
        abort_unless(! $outlet || $outlet->is_sales_enabled, 403);
        $openingItems = $request->validated('opening_items', []);
        $openingCatalog = $this->openingStockService->catalog();
        if ($openingCatalog['warehouse'] && ($openingCatalog['products']->isNotEmpty() || $openingCatalog['ingredients']->isNotEmpty()) && $openingItems === []) {
            throw ValidationException::withMessages([
                'opening_items' => 'Catat barang yang dibawa dari Gudang Pusat sebelum membuka shift.',
            ]);
        }

        $shift = DB::transaction(function () use ($request, $warehouse, $outlet, $openingItems) {
            $shift = $this->cashierShiftService->openShift(
                cashier: $request->user(),
                actor: $request->user(),
                openingCash: (int) $request->validated('opening_cash'),
                notes: $request->validated('notes'),
                warehouseId: $warehouse?->id,
                outletId: $outlet?->id,
            );
            if ($openingItems !== []) {
                $this->openingStockService->issueToShift($shift, $openingItems, $request->user()->id);
            }

            return $shift;
        }, attempts: 3);

        $this->auditLogService->log(
            event: 'cashier_shift.opened',
            module: 'cashier_shifts',
            auditable: $shift,
            description: 'Shift kasir dibuka.',
            after: $this->shiftAuditPayload($shift),
            meta: [
                'cashier_id' => $shift->user_id,
                'opened_by' => $shift->opened_by,
            ],
        );

        $target = $request->input('redirect_to') === 'transactions'
            ? route('transactions.index')
            : route('cashier-shifts.show', $shift);

        return redirect($target)->with('success', 'Shift kasir berhasil dibuka.');
    }

    public function close(CloseCashierShiftRequest $request, CashierShift $cashierShift, ConfirmPasswordForForceCloseRequest $confirmPasswordRequest): RedirectResponse
    {
        $cashierShift = $this->resolveVisibleShift($request, $cashierShift);
        $before = $this->shiftAuditPayload($cashierShift);
        $forceClose = $cashierShift->user_id !== $request->user()->id;

        if ($forceClose && ! ($request->user()->isSuperAdmin() || $request->user()->can('cashier-shifts-force-close'))) {
            abort(403);
        }

        if ($forceClose && ! $confirmPasswordRequest->recentlyConfirmed()) {
            $request->session()->put('url.intended', $request->headers->get('referer') ?: route('cashier-shifts.show', $cashierShift));
            $request->session()->put('security.step_up_context', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'intended' => $request->headers->get('referer') ?: route('cashier-shifts.show', $cashierShift),
                'target' => $cashierShift->id,
            ]);

            $this->auditLogService->log(
                event: 'security.privileged_action_challenged',
                module: 'security',
                auditable: $cashierShift,
                description: 'Force close shift memerlukan konfirmasi password ulang.',
                meta: [
                    'severity' => 'high',
                    'route' => $request->route()?->getName(),
                ],
            );

            return redirect()->route('password.confirm');
        }

        $closedShift = $this->cashierShiftService->closeShift(
            shift: $cashierShift,
            actor: $request->user(),
            actualCash: (int) $request->validated('actual_cash'),
            closeNotes: $request->validated('close_notes'),
            forceClose: $forceClose,
            closingStock: $request->validated('closing_stock'),
            closingIngredients: $request->validated('closing_ingredients'),
        );

        $this->auditLogService->log(
            event: $forceClose ? 'cashier_shift.force_closed' : 'cashier_shift.closed',
            module: 'cashier_shifts',
            auditable: $closedShift,
            description: $forceClose ? 'Shift kasir ditutup paksa.' : 'Shift kasir ditutup.',
            before: $before,
            after: $this->shiftAuditPayload($closedShift),
            meta: [
                'cashier_id' => $closedShift->user_id,
                'closed_by' => $closedShift->closed_by,
            ],
        );

        return to_route('cashier-shifts.show', $closedShift)->with('success', 'Shift kasir berhasil ditutup.');
    }

    public function storeCashMovement(Request $request, CashierShift $cashierShift): RedirectResponse
    {
        $cashierShift = $this->resolveVisibleShift($request, $cashierShift);

        if ($cashierShift->user_id !== $request->user()->id && ! ($request->user()->isSuperAdmin() || $request->user()->can('cashier-shifts-force-close'))) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->cashierShiftService->recordCashMovement(
            shift: $cashierShift,
            actor: $request->user(),
            type: $validated['type'],
            amount: (int) $validated['amount'],
            note: $validated['note'] ?? null,
        );

        return back()->with('success', 'Pergerakan kas berhasil dicatat.');
    }

    public function printReport(Request $request, CashierShift $cashierShift, string $type = 'X')
    {
        abort_unless(in_array(strtoupper($type), ['X', 'Z'], true), 404);

        $cashierShift = $this->resolveVisibleShift($request, $cashierShift);

        $service = app(ThermalPrintService::class);
        $html = $service->generateShiftReportHtml($cashierShift, strtoupper($type));

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function resolveVisibleShift(Request $request, CashierShift $cashierShift): CashierShift
    {
        $query = CashierShift::query()
            ->with(['user:id,name', 'openedBy:id,name', 'closedBy:id,name', 'warehouse:id,code,name', 'outlet:id,code,name'])
            ->whereKey($cashierShift->id);

        $query = $this->cashierShiftService->visibleToUser($query, $request->user());

        return $query->firstOrFail();
    }

    private function visibleCashiers(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return User::query()->orderBy('name')->get(['id', 'name']);
        }

        if (! $user->can('cashier-shifts-force-close')) {
            return collect([$user->only(['id', 'name'])]);
        }

        if (Outlet::active()->count() <= 1) {
            return User::query()->orderBy('name')->get(['id', 'name']);
        }

        $outletIds = $this->outletAccessService->accessibleOutlets($user)->pluck('id')->all();

        return User::query()
            ->where(function (Builder $query) use ($user, $outletIds) {
                $query->whereKey($user->id);
                if ($outletIds !== []) {
                    $query->orWhereHas('outlets', fn (Builder $outlets) => $outlets->whereIn('outlets.id', $outletIds));
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function transformShift(CashierShift $shift): array
    {
        $summary = $this->cashierShiftService->calculateSummary($shift);

        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opened_at' => optional($shift->opened_at)?->toISOString(),
            'closed_at' => optional($shift->closed_at)?->toISOString(),
            'opening_cash' => (int) $shift->opening_cash,
            'expected_cash' => $shift->isOpen() ? $summary['expected_cash'] : (int) $shift->expected_cash,
            'actual_cash' => $shift->actual_cash !== null ? (int) $shift->actual_cash : null,
            'cash_difference' => $shift->isOpen()
                ? null
                : ($shift->cash_difference !== null ? (int) $shift->cash_difference : null),
            'cash_sales_total' => $shift->isOpen() ? $summary['cash_sales_total'] : (int) $shift->cash_sales_total,
            'non_cash_sales_total' => $shift->isOpen() ? $summary['non_cash_sales_total'] : (int) $shift->non_cash_sales_total,
            'cash_refund_total' => $shift->isOpen() ? $summary['cash_refund_total'] : (int) $shift->cash_refund_total,
            'non_cash_refund_total' => $shift->isOpen() ? $summary['non_cash_refund_total'] : (int) $shift->non_cash_refund_total,
            'cash_in_total' => $shift->isOpen() ? $summary['cash_in_total'] : ($summary['cash_in_total'] ?: 0),
            'cash_out_total' => $shift->isOpen() ? $summary['cash_out_total'] : ($summary['cash_out_total'] ?: 0),
            'cash_movements' => $shift->cashMovements()
                ->with('user:id,name')
                ->latest()
                ->get()
                ->map(fn (ShiftCashMovement $movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type,
                    'amount' => (int) $movement->amount,
                    'note' => $movement->note,
                    'user' => $movement->user?->name,
                    'created_at' => optional($movement->created_at)?->toISOString(),
                ])
                ->values()
                ->all(),
            'stock_counts' => $shift->relationLoaded('stockCounts')
                ? $shift->stockCounts->map(fn ($count) => [
                    'product_id' => $count->product_id,
                    'product' => $count->product?->title ?? 'Menu dihapus',
                    'expected_stock' => $count->expected_stock,
                    'actual_stock' => $count->actual_stock,
                    'variance' => $count->variance,
                ])->values()->all()
                : [],
            'opening_items' => $shift->relationLoaded('openingItems')
                ? $shift->openingItems->map(fn ($item) => [
                    'item_type' => $item->item_type,
                    'name' => $item->item_type === 'product' ? $item->product?->title : $item->ingredient?->name,
                    'quantity' => $item->quantity,
                    'closing_quantity' => $item->closing_quantity,
                    'unit' => $item->unit?->name ?? $item->unit?->symbol,
                ])->values()->all()
                : [],
            'transactions_count' => $shift->isOpen() ? $summary['transactions_count'] : (int) $shift->transactions_count,
            'sales_returns_count' => $shift->isOpen() ? $summary['sales_returns_count'] : (int) $shift->sales_returns_count,
            'notes' => $shift->notes,
            'close_notes' => $shift->close_notes,
            'warehouse' => $shift->warehouse ? [
                'id' => $shift->warehouse->id,
                'code' => $shift->warehouse->code,
                'name' => $shift->warehouse->name,
            ] : null,
            'outlet' => $shift->outlet ? [
                'id' => $shift->outlet->id,
                'code' => $shift->outlet->code,
                'name' => $shift->outlet->name,
            ] : null,
            'user' => $shift->user ? [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ] : null,
            'opened_by' => $shift->openedBy ? [
                'id' => $shift->openedBy->id,
                'name' => $shift->openedBy->name,
            ] : null,
            'closed_by' => $shift->closedBy ? [
                'id' => $shift->closedBy->id,
                'name' => $shift->closedBy->name,
            ] : null,
        ];
    }

    private function shiftAuditPayload(CashierShift $shift): array
    {
        return [
            'status' => $shift->status,
            'opening_cash' => (int) $shift->opening_cash,
            'expected_cash' => (int) ($shift->expected_cash ?? $shift->opening_cash),
            'actual_cash' => $shift->actual_cash !== null ? (int) $shift->actual_cash : null,
            'cash_difference' => $shift->cash_difference !== null ? (int) $shift->cash_difference : null,
            'transactions_count' => (int) ($shift->transactions_count ?? 0),
            'sales_returns_count' => (int) ($shift->sales_returns_count ?? 0),
        ];
    }
}
