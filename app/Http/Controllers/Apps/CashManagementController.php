<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\CashHandover;
use App\Models\CashierShift;
use App\Models\CashPickup;
use App\Models\Warehouse;
use App\Services\CashManagementService;
use App\Services\OutletAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CashManagementController extends Controller
{
    public function __construct(
        private readonly CashManagementService $cashManagement,
        private readonly OutletAccessService $outletAccess,
    ) {}

    public function index(Request $request): Response
    {
        $warehouseIds = $this->outletAccess->warehousesFor($request->user())->pluck('id');
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('name')->get(['id', 'code', 'name']);
        $canReadHandovers = $request->user()->can('cash-handovers-access');
        $canReadPickups = $request->user()->can('cash-pickups-access');

        return Inertia::render('Dashboard/CashManagement/Index', [
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse) => [
                ...$warehouse->only(['id', 'code', 'name']),
                'cash_balance' => $this->cashManagement->balanceFor($warehouse->id),
            ])->values(),
            'closedShifts' => CashierShift::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('status', [CashierShift::STATUS_CLOSED, CashierShift::STATUS_FORCE_CLOSED])
                ->whereDoesntHave('cashHandover')
                ->with('warehouse:id,name')
                ->latest('closed_at')
                ->limit(20)
                ->get(['id', 'warehouse_id', 'closed_at', 'expected_cash', 'actual_cash']),
            'handovers' => $canReadHandovers ? CashHandover::whereIn('warehouse_id', $warehouseIds)
                ->with(['warehouse:id,name', 'cashier:id,name', 'confirmer:id,name'])
                ->latest()
                ->limit(40)
                ->get() : [],
            'pickups' => $canReadPickups ? CashPickup::whereIn('warehouse_id', $warehouseIds)
                ->with(['warehouse:id,name', 'requester:id,name', 'confirmer:id,name'])
                ->latest()
                ->limit(40)
                ->get() : [],
            'canSubmitHandover' => $request->user()->can('cash-handovers-create'),
            'canConfirmHandover' => $request->user()->can('cash-handovers-confirm'),
            'canRequestPickup' => $request->user()->can('cash-pickups-create'),
            'canConfirmPickup' => $request->user()->can('cash-pickups-confirm'),
            'requestKeys' => ['handover' => (string) Str::uuid(), 'pickup' => (string) Str::uuid()],
        ]);
    }

    public function submitHandover(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'max:120'],
            'cashier_shift_id' => ['required', 'integer', 'exists:cashier_shifts,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $shift = CashierShift::whereKey($validated['cashier_shift_id'])->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($this->outletAccess->warehousesFor($request->user())->contains('id', $shift->warehouse_id), 403);
        $this->cashManagement->submitHandover($validated['request_key'], $shift, $request->user(), $validated['notes'] ?? null);

        return back()->with('success', 'Serah terima kas berhasil diajukan ke gudang.');
    }

    public function confirmHandover(Request $request, CashHandover $cashHandover): RedirectResponse
    {
        $this->assertWarehouseScope($request, $cashHandover->warehouse_id);
        $validated = $request->validate([
            'received_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->cashManagement->confirmHandover($cashHandover, $request->user(), (int) $validated['received_amount'], $validated['notes'] ?? null);

        return back()->with('success', 'Penerimaan kas berhasil dikonfirmasi.');
    }

    public function requestPickup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'max:120'],
            'warehouse_id' => ['required', 'integer', Rule::in($this->outletAccess->warehousesFor($request->user())->pluck('id'))],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->cashManagement->requestPickup(
            $validated['request_key'],
            Warehouse::findOrFail($validated['warehouse_id']),
            (int) $validated['amount'],
            $request->user(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Pengajuan pengambilan kas berhasil dikirim.');
    }

    public function confirmPickup(Request $request, CashPickup $cashPickup): RedirectResponse
    {
        $this->assertWarehouseScope($request, $cashPickup->warehouse_id);
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        $proof = $validated['proof'];
        $proofPath = $proof->store('cash-pickups');

        try {
            $this->cashManagement->confirmPickup(
                $cashPickup,
                $request->user(),
                $proofPath,
                hash_file('sha256', $proof->getRealPath()),
                $validated['notes'] ?? null,
            );
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($proofPath);
            throw $exception;
        }

        return back()->with('success', 'Pengambilan kas berhasil dikonfirmasi.');
    }

    public function pickupProof(Request $request, CashPickup $cashPickup)
    {
        $this->assertWarehouseScope($request, $cashPickup->warehouse_id);
        abort_unless($cashPickup->proof_path && Storage::disk('local')->exists($cashPickup->proof_path), 404);

        return Storage::disk('local')->response($cashPickup->proof_path);
    }

    private function assertWarehouseScope(Request $request, int $warehouseId): void
    {
        abort_unless($this->outletAccess->warehousesFor($request->user())->contains('id', $warehouseId), 403);
    }
}
