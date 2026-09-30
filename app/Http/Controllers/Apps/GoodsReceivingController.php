<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\GoodsReceivingService;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class GoodsReceivingController extends Controller
{
    public function __construct(
        private readonly GoodsReceivingService $goodsReceivingService,
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'search' => $request->input('search'),
            'purchase_order_id' => $request->input('purchase_order_id'),
        ];

        $warehouseIds = app(OutletAccessService::class)->warehousesFor($request->user())->pluck('id');
        $query = GoodsReceiving::with([
            'purchaseOrder:id,document_number,status',
            'supplier:id,name',
            'receiver:id,name',
        ])->whereIn('warehouse_id', $warehouseIds)->orderByDesc('received_at');

        $query->when($filters['search'], fn ($q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->when($filters['purchase_order_id'], fn ($q, $id) => $q->where('purchase_order_id', $id));

        $receivings = $query->paginate($this->perPage())->withQueryString();

        return Inertia::render('Dashboard/GoodsReceivings/Index', [
            'receivings' => $receivings,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request)
    {
        $purchaseOrderId = $request->input('purchase_order_id');

        $orders = PurchaseOrder::with([
            'supplier:id,name',
            'items.product:id,title,sku',
            'items.ingredient:id,code,name,base_unit_id',
            'items.ingredient.baseUnit:id,name,symbol',
        ])->whereIn('status', ['ordered', 'partial_received'])
            ->where(function ($query) use ($request) {
                $ids = $this->outletAccessService->warehousesFor($request->user())->pluck('id');
                $query->whereIn('warehouse_id', $ids)->orWhereNull('warehouse_id');
            })
            ->orderByDesc('created_at')
            ->get();

        if ($purchaseOrderId) {
            $orders = $orders->where('id', $purchaseOrderId);
        }

        return Inertia::render('Dashboard/GoodsReceivings/Create', [
            'orders' => $orders,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'request_key' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'exists:purchase_order_items,id'],
            'items.*.qty_sent' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'items.*.qty_received' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'items.*.qty_accepted' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.qc_status' => ['required', 'in:good,damaged,short,wrong_item,unusable'],
            'items.*.condition_notes' => ['nullable', 'string', 'max:1000'],
            'items.*.photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.batch_number' => ['nullable', 'string', 'max:255'],
            'items.*.expired_at' => ['nullable', 'date'],
        ]);

        $order = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);

        $isRetry = GoodsReceiving::where('request_key', $data['request_key'])
            ->where('purchase_order_id', $order->id)
            ->exists();

        if ($order->warehouse_id) {
            $warehouse = Warehouse::findOrFail($order->warehouse_id);
            abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);
        }

        if (! $isRetry && ! in_array($order->status, ['ordered', 'partial_received'])) {
            return back()->with('error', 'Hanya PO berstatus dipesan atau diterima sebagian yang dapat diproses.');
        }

        $seen = [];
        foreach ($isRetry ? [] : $data['items'] as $item) {
            if (in_array($item['purchase_order_item_id'], $seen)) {
                return back()->with('error', 'Item PO duplikat dalam satu penerimaan.');
            }
            $seen[] = $item['purchase_order_item_id'];

            $poItem = $order->items->firstWhere('id', $item['purchase_order_item_id']);
            if (! $poItem) {
                return back()->with('error', 'Item tidak ditemukan di PO.');
            }
            $outstanding = $poItem->qty_ordered - $poItem->qty_received;
            if ($item['qty_sent'] > $outstanding || $item['qty_received'] > $outstanding || $item['qty_received'] > $item['qty_sent']) {
                return back()->with('error', "Qty diterima melebihi sisa item {$poItem->product_id}.");
            }

            $accepted = $item['qty_accepted'] ?? (in_array($item['qc_status'], ['good', 'short'], true) ? $item['qty_received'] : 0);
            if ((float) $accepted > (float) $item['qty_received']) {
                return back()->withErrors(['items' => 'Jumlah lolos QC tidak boleh melebihi jumlah yang diterima.']);
            }
        }

        foreach ($data['items'] as &$item) {
            $photo = $item['photo'] ?? null;
            $item['proof_hash'] = $photo ? hash_file('sha256', $photo->getRealPath()) : null;
            $item['proof_path'] = $photo ? $photo->store('goods-receivings') : null;
            unset($item['photo']);
            $item['qty_accepted'] ??= in_array($item['qc_status'], ['good', 'short'], true) ? $item['qty_received'] : 0;
        }
        unset($item);

        try {
            $receiving = $this->goodsReceivingService->receive(
                order: $order,
                items: $data['items'],
                notes: $data['notes'] ?? null,
                userId: $request->user()->id,
                requestKey: $data['request_key'],
            );

            $attachedProofs = $receiving->items()->pluck('proof_path')->filter()->all();
            collect($data['items'])->pluck('proof_path')->filter()->diff($attachedProofs)->each(fn (string $path) => Storage::disk('local')->delete($path));
        } catch (\Throwable $exception) {
            collect($data['items'])->pluck('proof_path')->filter()->each(fn (string $path) => Storage::disk('local')->delete($path));
            throw $exception;
        }

        return redirect()
            ->route('goods-receivings.show', $receiving)
            ->with('success', 'Penerimaan barang berhasil dicatat.');
    }

    public function show(GoodsReceiving $goodsReceiving)
    {
        $warehouse = $goodsReceiving->warehouse_id ? $goodsReceiving->warehouse : null;
        abort_unless($this->outletAccessService->canUseWarehouse(request()->user(), $warehouse), 404);
        $goodsReceiving->load([
            'purchaseOrder:id,document_number,status',
            'supplier:id,name',
            'items.product:id,title,sku',
            'items.ingredient:id,code,name,base_unit_id',
            'items.ingredient.baseUnit:id,name,symbol',
            'items.purchaseOrderItem:id,unit_price',
            'receiver:id,name',
        ]);

        return Inertia::render('Dashboard/GoodsReceivings/Show', [
            'receiving' => $goodsReceiving,
        ]);
    }

    public function proof(GoodsReceiving $goodsReceiving, GoodsReceivingItem $item)
    {
        abort_unless($item->goods_receiving_id === $goodsReceiving->id && $item->proof_path, 404);
        abort_unless($this->outletAccessService->canUseWarehouse(request()->user(), $goodsReceiving->warehouse), 404);

        abort_unless(Storage::disk('local')->exists($item->proof_path), 404);

        return Storage::disk('local')->response($item->proof_path);
    }
}
