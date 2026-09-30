<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\OutletAccessService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'status' => $request->input('status'),
            'supplier' => $request->input('supplier'),
            'search' => $request->input('search'),
        ];

        $warehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id');
        $query = PurchaseOrder::with([
            'supplier:id,name',
            'items',
            'creator:id,name',
        ])->where(function ($query) use ($warehouseIds) {
            $query->whereIn('warehouse_id', $warehouseIds)->orWhereNull('warehouse_id');
        })->withCount('items as items_count')
            ->orderByDesc('created_at');

        $query->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['supplier'], fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($filters['search'], fn ($q, $s) => $q->where('document_number', 'like', "%{$s}%"));

        $orders = $query->paginate($this->perPage())->withQueryString();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Dashboard/PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'suppliers' => $suppliers,
        ]);
    }

    public function create()
    {
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $products = Product::orderBy('title')->get(['id', 'title', 'sku', 'buy_price', 'stock']);
        $ingredients = Ingredient::where('is_active', true)->with('baseUnit:id,name,symbol')->orderBy('name')->get(['id', 'code', 'name', 'default_unit_cost', 'base_unit_id']);
        $warehouses = $this->outletAccessService->warehousesFor(request()->user());

        return Inertia::render('Dashboard/PurchaseOrders/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
            'ingredients' => $ingredients,
            'warehouses' => $warehouses,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'in:product,ingredient'],
            'items.*.product_id' => ['nullable', 'required_if:items.*.item_type,product', 'exists:products,id'],
            'items.*.ingredient_id' => ['nullable', 'required_if:items.*.item_type,ingredient', 'exists:ingredients,id'],
            'items.*.qty_ordered' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if (collect($data['items'])->contains(fn (array $item) => $item['item_type'] === 'ingredient') && empty($data['warehouse_id'])) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Tujuan gudang wajib dipilih untuk pembelian bahan baku.',
            ]);
        }

        $order = $this->purchaseOrderService->createOrder($data, $data['items'], $request->user()->id);

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Purchase order berhasil dibuat.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->ensureOrderAccess($request, $purchaseOrder);
        $purchaseOrder->load([
            'supplier:id,name,phone,email,address',
            'warehouse:id,code,name',
            'items.product:id,title,sku,image',
            'items.ingredient:id,code,name,base_unit_id',
            'goodsReceivings' => function ($q) {
                $q->with(['items.product:id,title,sku', 'items.ingredient:id,code,name'])->orderByDesc('received_at');
            },
            'creator:id,name',
            'payable:id,purchase_order_id,total,paid,status,document_number',
        ]);

        return Inertia::render('Dashboard/PurchaseOrders/Show', [
            'order' => $purchaseOrder,
        ]);
    }

    public function placeOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->ensureOrderAccess($request, $purchaseOrder);
        if ($purchaseOrder->status !== 'draft') {
            return back()->with('error', 'Hanya PO dengan status draft yang bisa dipesan.');
        }

        $this->purchaseOrderService->placeOrder($purchaseOrder);

        return redirect()
            ->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order berhasil dipesan.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->ensureOrderAccess($request, $purchaseOrder);
        if (! in_array($purchaseOrder->status, ['draft', 'ordered', 'partial_received'])) {
            return back()->with('error', 'PO tidak dapat dibatalkan.');
        }

        $this->purchaseOrderService->cancelOrder($purchaseOrder);

        return redirect()
            ->route('purchase-orders.index')
            ->with('success', 'Purchase order dibatalkan.');
    }

    private function ensureOrderAccess(Request $request, PurchaseOrder $order): void
    {
        $warehouse = $order->warehouse_id ? $order->warehouse : null;
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 404);
    }
}
