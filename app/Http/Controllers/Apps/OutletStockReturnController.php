<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\OutletStockReturn;
use App\Models\ProductWarehouse;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\OutletStockReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutletStockReturnController extends Controller
{
    public function __construct(
        private readonly OutletStockReturnService $returns,
        private readonly OutletAccessService $outletAccess,
    ) {}

    public function index(Request $request): Response
    {
        $warehouseIds = $this->outletAccess->warehousesFor($request->user())->pluck('id');
        $branches = Warehouse::with('outlet:id,name,is_sales_enabled')
            ->whereIn('id', $warehouseIds)
            ->whereHas('outlet', fn ($query) => $query->where('is_sales_enabled', true))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'outlet_id']);
        $branchIds = $branches->pluck('id');

        return Inertia::render('Dashboard/OutletStockReturns/Index', [
            'returns' => OutletStockReturn::query()
                ->where(fn ($query) => $query->whereIn('source_warehouse_id', $warehouseIds)->orWhereIn('destination_warehouse_id', $warehouseIds))
                ->with(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'requester:id,name', 'receiver:id,name', 'items.product:id,title,sku'])
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'branches' => $branches->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'products' => ProductWarehouse::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('stock', '>', 0)
                    ->whereHas('product.units', fn ($query) => $query->wherePivot('is_base', true))
                    ->with('product:id,title,sku')
                    ->get()
                    ->map(fn (ProductWarehouse $stock) => ['id' => $stock->product_id, 'title' => $stock->product->title, 'sku' => $stock->product->sku, 'stock' => (int) $stock->stock])
                    ->values(),
            ])->values(),
            'canCreate' => $request->user()->can('outlet-stock-returns-create'),
            'canReceive' => $request->user()->can('outlet-stock-returns-receive'),
            'requestKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $warehouseIds = $this->outletAccess->warehousesFor($request->user())->pluck('id')->all();
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'max:120'],
            'source_warehouse_id' => ['required', 'integer', Rule::in($warehouseIds)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $source = Warehouse::findOrFail($validated['source_warehouse_id']);
        $this->returns->create($validated['request_key'], $source, $validated['items'], $request->user(), $validated['notes'] ?? null);

        return back()->with('success', 'Pengembalian stok berhasil diajukan kepada gudang pusat.');
    }

    public function receive(Request $request, OutletStockReturn $outletStockReturn): RedirectResponse
    {
        $allowedWarehouseIds = $this->outletAccess->warehousesFor($request->user())->pluck('id');
        abort_unless($allowedWarehouseIds->contains($outletStockReturn->destination_warehouse_id), 404);
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['required', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->returns->receive($outletStockReturn, $validated['items'], $request->user(), $validated['notes'] ?? null);

        return back()->with('success', 'Penerimaan pengembalian stok berhasil dikonfirmasi.');
    }
}
