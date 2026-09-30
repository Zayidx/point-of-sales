<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashierShift;
use App\Models\CashPickup;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseCashLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashManagementService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function submitHandover(string $requestKey, CashierShift $shift, User $cashier, ?string $notes = null): CashHandover
    {
        return DB::transaction(function () use ($requestKey, $shift, $cashier, $notes) {
            $lockedShift = CashierShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedShift->user_id !== (int) $cashier->id || $lockedShift->isOpen()) {
                throw ValidationException::withMessages(['shift' => 'Serah terima hanya dapat diajukan oleh pemilik shift yang sudah ditutup.']);
            }

            $existing = CashHandover::where('cashier_shift_id', $lockedShift->id)->first();
            if ($existing) {
                if ($existing->cashier_id !== $cashier->id) {
                    throw ValidationException::withMessages(['shift' => 'Shift ini sudah memiliki serah terima kas.']);
                }

                return $existing;
            }

            $expectedCash = (int) ($lockedShift->expected_cash ?? 0);
            $cashierAmount = (int) ($lockedShift->actual_cash ?? $expectedCash);
            $handover = CashHandover::create([
                'handover_number' => 'CH-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))),
                'request_key' => $requestKey,
                'cashier_shift_id' => $lockedShift->id,
                'warehouse_id' => $lockedShift->warehouse_id,
                'cashier_id' => $cashier->id,
                'expected_cash' => $expectedCash,
                'cashier_amount' => $cashierAmount,
                'status' => 'pending',
                'cashier_notes' => $notes,
            ]);

            $this->auditLogService->log(
                event: 'cash_handover.submitted',
                module: 'cash_management',
                auditable: $handover,
                description: "Serah terima {$handover->handover_number} diajukan.",
                after: $handover->only(['handover_number', 'warehouse_id', 'expected_cash', 'cashier_amount', 'status']),
            );

            return $handover;
        }, attempts: 3);
    }

    public function confirmHandover(CashHandover $handover, User $warehouseUser, int $receivedAmount, ?string $notes = null): CashHandover
    {
        if ($receivedAmount < 0) {
            throw ValidationException::withMessages(['received_amount' => 'Nominal penerimaan tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($handover, $warehouseUser, $receivedAmount, $notes) {
            $locked = CashHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Serah terima ini sudah diproses.']);
            }
            $this->lockWarehouse($locked->warehouse_id);
            $variance = $receivedAmount - (int) $locked->expected_cash;

            $locked->update([
                'status' => 'received',
                'received_amount' => $receivedAmount,
                'variance' => $variance,
                'warehouse_notes' => $notes,
                'confirmed_by' => $warehouseUser->id,
                'confirmed_at' => now(),
            ]);

            if ($receivedAmount > 0) {
                $this->recordLedger(
                    warehouseId: $locked->warehouse_id,
                    movementType: 'outlet_cash_received',
                    direction: 'in',
                    amount: $receivedAmount,
                    referenceType: CashHandover::class,
                    referenceId: $locked->id,
                    referenceNumber: $locked->handover_number,
                    idempotencyKey: "cash-handover:{$locked->id}",
                    notes: $notes,
                    actorId: $warehouseUser->id,
                );
            }

            app(OperationalNotificationService::class)->notifyUser(
                userId: (int) $locked->cashier_id,
                event: 'cash_handover.received',
                idempotencyKey: 'cash-handover:'.$locked->id.':received',
                title: 'Serah terima kas sudah diterima',
                body: 'Gudang telah mengonfirmasi '.$locked->handover_number.'.',
                url: '/cash-management',
                warehouseId: (int) $locked->warehouse_id,
            );

            $this->auditLogService->log(
                event: 'cash_handover.confirmed',
                module: 'cash_management',
                auditable: $locked,
                description: "Serah terima {$locked->handover_number} dikonfirmasi.",
                before: ['status' => 'pending'],
                after: $locked->only(['status', 'received_amount', 'variance', 'confirmed_by']),
            );

            if ($variance !== 0) {
                app(OperationalNotificationService::class)->notifyWarehouseUsers(
                    event: 'cash.variance',
                    idempotencyKey: 'cash-handover:'.$locked->id.':variance',
                    title: 'Ada selisih kas outlet',
                    body: 'Serah terima '.$locked->handover_number.' memiliki selisih Rp'.number_format(abs($variance), 0, ',', '.').'.',
                    url: '/cash-management',
                    warehouseId: (int) $locked->warehouse_id,
                    permissions: ['cash-handovers-access', 'cash-pickups-access'],
                );
            }

            return $locked->fresh(['warehouse', 'cashier', 'confirmer']);
        }, attempts: 3);
    }

    public function requestPickup(string $requestKey, Warehouse $warehouse, int $amount, User $accountant, ?string $notes = null): CashPickup
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pengambilan harus lebih besar dari nol.']);
        }

        return DB::transaction(function () use ($requestKey, $warehouse, $amount, $accountant, $notes) {
            Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $existing = CashPickup::where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->warehouse_id !== $warehouse->id || $existing->amount !== $amount || $existing->requested_by !== $accountant->id) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci pengajuan ini sudah digunakan untuk data berbeda.']);
                }

                return $existing;
            }

            $pickup = CashPickup::create([
                'pickup_number' => 'CP-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))),
                'request_key' => $requestKey,
                'warehouse_id' => $warehouse->id,
                'amount' => $amount,
                'status' => 'requested',
                'notes' => $notes,
                'requested_by' => $accountant->id,
            ]);

            $this->auditLogService->log(
                event: 'cash_pickup.requested',
                module: 'cash_management',
                auditable: $pickup,
                description: "Pengambilan kas {$pickup->pickup_number} diajukan.",
                after: $pickup->only(['pickup_number', 'warehouse_id', 'amount', 'status']),
            );

            return $pickup;
        }, attempts: 3);
    }

    public function confirmPickup(
        CashPickup $pickup,
        User $warehouseUser,
        string $proofPath,
        string $proofHash,
        ?string $notes = null,
    ): CashPickup {
        if (! preg_match('/^[a-f0-9]{64}$/i', $proofHash)) {
            throw ValidationException::withMessages(['proof' => 'Hash bukti serah-terima tidak valid.']);
        }

        return DB::transaction(function () use ($pickup, $warehouseUser, $notes, $proofPath, $proofHash) {
            $locked = CashPickup::whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['status' => 'Pengambilan kas ini sudah diproses.']);
            }
            $this->lockWarehouse($locked->warehouse_id);
            $balance = $this->balanceFor($locked->warehouse_id);
            if ($locked->amount > $balance) {
                throw ValidationException::withMessages(['amount' => "Saldo kas gudang tidak mencukupi. Saldo saat ini: {$balance}."]);
            }

            $locked->update([
                'status' => 'confirmed',
                'confirmed_by' => $warehouseUser->id,
                'confirmed_at' => now(),
                'notes' => $notes ?? $locked->notes,
                'proof_path' => $proofPath,
                'proof_hash' => $proofHash,
            ]);
            $this->recordLedger(
                warehouseId: $locked->warehouse_id,
                movementType: 'accountant_cash_pickup',
                direction: 'out',
                amount: $locked->amount,
                referenceType: CashPickup::class,
                referenceId: $locked->id,
                referenceNumber: $locked->pickup_number,
                idempotencyKey: "cash-pickup:{$locked->id}",
                notes: $notes ?? $locked->notes,
                actorId: $warehouseUser->id,
            );

            $this->auditLogService->log(
                event: 'cash_pickup.confirmed',
                module: 'cash_management',
                auditable: $locked,
                description: "Pengambilan kas {$locked->pickup_number} dikonfirmasi.",
                before: ['status' => 'requested', 'warehouse_cash_balance' => $balance],
                after: [
                    'status' => 'confirmed',
                    'warehouse_cash_balance' => $balance - $locked->amount,
                    'proof_hash' => $proofHash,
                ],
            );

            return $locked->fresh(['warehouse', 'requester', 'confirmer']);
        }, attempts: 3);
    }

    public function balanceFor(int $warehouseId): int
    {
        $inflow = (int) WarehouseCashLedger::where('warehouse_id', $warehouseId)->where('direction', 'in')->sum('amount');
        $outflow = (int) WarehouseCashLedger::where('warehouse_id', $warehouseId)->where('direction', 'out')->sum('amount');

        return $inflow - $outflow;
    }

    private function lockWarehouse(int $warehouseId): Warehouse
    {
        return Warehouse::whereKey($warehouseId)->lockForUpdate()->firstOrFail();
    }

    private function recordLedger(int $warehouseId, string $movementType, string $direction, int $amount, string $referenceType, int $referenceId, string $referenceNumber, string $idempotencyKey, ?string $notes, int $actorId): void
    {
        WarehouseCashLedger::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'warehouse_id' => $warehouseId,
                'reference_number' => $referenceNumber,
                'movement_type' => $movementType,
                'direction' => $direction,
                'amount' => $amount,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'created_by' => $actorId,
            ],
        );
    }
}
