<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionRequest;
use App\Services\OutletAccessService;
use App\Services\ProductionOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductionOrderController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccess,
        private readonly ProductionOrderService $orders,
    ) {}

    public function index(Request $request): Response
    {
        $warehouseIds = $this->outletAccess->warehousesFor($request->user())->pluck('id');

        return Inertia::render('Dashboard/ProductionOrders/Index', [
            'orders' => ProductionOrder::query()
                ->whereIn('warehouse_id', $warehouseIds)
                ->with(['product:id,title', 'warehouse:id,name', 'items.ingredient:id,name,base_unit_id', 'items.ingredient.baseUnit:id,symbol', 'request:id,request_number,estimated_material_cost'])
                ->latest()
                ->paginate(15)
                ->withQueryString(),
            'canOperate' => $request->user()->can('production-orders-complete'),
        ]);
    }

    public function schedule(Request $request, ProductionRequest $productionRequest): RedirectResponse
    {
        $this->assertWarehouseScope($request, $productionRequest->warehouse_id);
        $this->orders->createFromApprovedRequest($productionRequest, $request->user());

        return to_route('production-orders.index')->with('success', 'Pesanan produksi berhasil dijadwalkan.');
    }

    public function start(Request $request, ProductionOrder $productionOrder): RedirectResponse
    {
        $this->assertWarehouseScope($request, $productionOrder->warehouse_id);
        $this->orders->start($productionOrder, $request->user());

        return back()->with('success', 'Produksi berhasil dimulai.');
    }

    public function complete(Request $request, ProductionOrder $productionOrder): RedirectResponse
    {
        $this->assertWarehouseScope($request, $productionOrder->warehouse_id);
        $validated = $request->validate([
            'actual_output' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'actual_consumption' => ['required', 'array', 'min:1'],
            'actual_consumption.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'actual_consumption.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ]);
        $this->orders->complete($productionOrder, $validated['actual_consumption'], $validated['actual_output'], $request->user());

        return back()->with('success', 'Produksi selesai dan stok bahan/menu telah diperbarui.');
    }

    private function assertWarehouseScope(Request $request, int $warehouseId): void
    {
        abort_unless($this->outletAccess->warehousesFor($request->user())->contains('id', $warehouseId), 403);
    }
}
