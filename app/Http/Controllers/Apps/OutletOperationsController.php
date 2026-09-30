<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\ExpenseCategory;
use App\Models\OutletExpense;
use App\Models\OutletWasteRecord;
use App\Models\Product;
use App\Services\OutletAccessService;
use App\Services\OutletOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutletOperationsController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccess,
        private readonly OutletOperationsService $operations,
    ) {}

    public function index(Request $request): Response
    {
        $warehouseIds = $this->outletAccess->warehousesFor($request->user())->pluck('id');
        $activeShift = CashierShift::query()->open()->where('user_id', $request->user()->id)->latest('opened_at')->first();

        return Inertia::render('Dashboard/OutletOperations/Index', [
            'activeShift' => $activeShift?->only(['id', 'warehouse_id', 'opened_at']),
            'expenses' => OutletExpense::query()
                ->whereIn('warehouse_id', $warehouseIds)
                ->with(['category:id,name', 'warehouse:id,name', 'shift:id,user_id'])
                ->latest()
                ->paginate(15, ['*'], 'expenses_page')
                ->withQueryString(),
            'wasteRecords' => OutletWasteRecord::query()
                ->whereIn('warehouse_id', $warehouseIds)
                ->with(['product:id,title', 'warehouse:id,name'])
                ->latest()
                ->paginate(15, ['*'], 'waste_page')
                ->withQueryString(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->whereHas('units', fn ($query) => $query->where('product_units.is_base', true))->orderBy('title')->get(['id', 'title']),
            'requestKeys' => ['expense' => (string) Str::uuid(), 'waste' => (string) Str::uuid()],
            'canCreate' => (bool) $activeShift && $request->user()->can('outlet-operations-create'),
        ]);
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $shift = $this->requireActiveShift($request);
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'max:120'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'item_name' => ['required', 'string', 'max:160'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'payment_source' => ['required', Rule::in(['outlet_cash', 'non_cash'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        abort_unless($request->user()->can('outlet-operations-create'), 403);
        $quantity = (float) $validated['quantity'];
        $total = (int) round($quantity * (int) $validated['unit_price']);
        $receiptPath = $request->file('receipt_image')?->store('outlet-expenses', 'public');

        $this->operations->recordExpense(
            $validated['request_key'],
            $shift,
            ExpenseCategory::findOrFail($validated['expense_category_id']),
            [
                ...$validated,
                'total' => $total,
                'receipt_path' => $receiptPath,
            ],
            $request->user(),
        );

        return back()->with('success', 'Biaya operasional berhasil dicatat.');
    }

    public function storeWaste(Request $request): RedirectResponse
    {
        $shift = $this->requireActiveShift($request);
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'max:120'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', Rule::in(['rusak', 'basi', 'jatuh', 'tidak_layak_makan', 'tidak_layak_jual'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($request->user()->can('outlet-operations-create'), 403);

        $this->operations->recordWaste(
            $validated['request_key'],
            $shift,
            Product::findOrFail($validated['product_id']),
            (int) $validated['quantity'],
            $validated['reason'],
            $validated['notes'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Waste berhasil dicatat dan stok telah dikurangi.');
    }

    private function requireActiveShift(Request $request): CashierShift
    {
        return CashierShift::query()
            ->open()
            ->where('user_id', $request->user()->id)
            ->latest('opened_at')
            ->firstOrFail();
    }
}
