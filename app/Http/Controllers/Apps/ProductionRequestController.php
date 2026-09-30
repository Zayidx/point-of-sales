<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionRequest;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\ProductionRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductionRequestController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService,
        private readonly ProductionRequestService $productionRequestService,
    ) {}

    public function index(Request $request): Response
    {
        $warehouses = $this->outletAccessService->warehousesFor($request->user());
        $warehouseIds = $warehouses->pluck('id');
        $requests = ProductionRequest::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->with(['menu:id,title', 'warehouse:id,name', 'items.ingredient:id,name', 'requestedBy:id,name', 'reviewedBy:id,name'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Dashboard/ProductionRequests/Index', [
            'requests' => $requests,
            'warehouses' => $warehouses,
            'menus' => Product::query()->orderBy('title')->get(['id', 'title']),
            'formDefaults' => ['request_key' => (string) Str::uuid()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'max:120'],
            'warehouse_id' => ['required', 'integer', Rule::in($this->outletAccessService->warehousesFor($request->user())->pluck('id'))],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'target_output' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->productionRequestService->submit(
            $validated['request_key'],
            Product::findOrFail($validated['product_id']),
            Warehouse::findOrFail($validated['warehouse_id']),
            $validated['target_output'],
            $request->user(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Permintaan produksi berhasil dikirim.');
    }

    public function review(Request $request, ProductionRequest $productionRequest): RedirectResponse
    {
        abort_unless(
            $this->outletAccessService->warehousesFor($request->user())->contains('id', $productionRequest->warehouse_id),
            403,
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('decision') === 'reject')],
        ]);
        abort_unless($request->user()->can('production-requests-'.$validated['decision']), 403);

        $this->productionRequestService->review(
            $productionRequest,
            $request->user(),
            $validated['decision'] === 'approve',
            $validated['reason'] ?? null,
        );

        return back()->with('success', $validated['decision'] === 'approve' ? 'Permintaan disetujui.' : 'Permintaan ditolak.');
    }
}
