<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\ExpenseCategory;
use App\Models\InventoryBalance;
use App\Models\OutletExpense;
use App\Models\OutletWasteRecord;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OutletOperationsService
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly InventoryLedgerService $inventoryLedgerService,
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordExpense(string $requestKey, CashierShift $shift, ExpenseCategory $category, array $data, User $actor): OutletExpense
    {
        return DB::transaction(function () use ($requestKey, $shift, $category, $data, $actor) {
            $lockedShift = CashierShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->assertActorShift($lockedShift, $actor);

            $existing = OutletExpense::where('request_key', $requestKey)->first();
            if ($existing) {
                $same = $existing->cashier_shift_id === $lockedShift->id
                    && $existing->expense_category_id === $category->id
                    && $existing->item_name === $data['item_name']
                    && (float) $existing->quantity === (float) $data['quantity']
                    && $existing->unit_price === (int) $data['unit_price']
                    && $existing->total === (int) $data['total']
                    && $existing->payment_source === $data['payment_source']
                    && $existing->notes === ($data['notes'] ?? null);
                if (! $same) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci pengiriman ini sudah digunakan untuk data berbeda.']);
                }

                return $existing;
            }

            $expenseNumber = 'EXP-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $movement = null;
            if ($data['payment_source'] === 'outlet_cash') {
                $movement = $this->cashierShiftService->recordCashMovement(
                    $lockedShift,
                    $actor,
                    'out',
                    (int) $data['total'],
                    "Biaya operasional: {$data['item_name']} ({$expenseNumber})",
                );
            }

            $expense = OutletExpense::create([
                'expense_number' => $expenseNumber,
                'request_key' => $requestKey,
                'warehouse_id' => $lockedShift->warehouse_id,
                'cashier_shift_id' => $lockedShift->id,
                'expense_category_id' => $category->id,
                'item_name' => $data['item_name'],
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'],
                'total' => $data['total'],
                'payment_source' => $data['payment_source'],
                'notes' => $data['notes'] ?? null,
                'receipt_path' => $data['receipt_path'] ?? null,
                'cash_movement_id' => $movement?->id,
                'created_by' => $actor->id,
            ]);

            $this->auditLogService->log(
                event: 'outlet_expense.created',
                module: 'outlet_operations',
                auditable: $expense,
                description: "Biaya operasional {$expense->expense_number} dicatat.",
                after: $expense->only(['expense_number', 'warehouse_id', 'expense_category_id', 'total', 'payment_source']),
            );

            return $expense;
        }, attempts: 3);
    }

    public function recordWaste(string $requestKey, CashierShift $shift, Product $product, int $quantity, string $reason, ?string $notes, User $actor): OutletWasteRecord
    {
        return DB::transaction(function () use ($requestKey, $shift, $product, $quantity, $reason, $notes, $actor) {
            $lockedShift = CashierShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->assertActorShift($lockedShift, $actor);

            $existing = OutletWasteRecord::where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->cashier_shift_id !== $lockedShift->id
                    || $existing->product_id !== $product->id
                    || (int) $existing->quantity !== $quantity
                    || $existing->reason !== $reason
                    || $existing->notes !== $notes) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci pengiriman ini sudah digunakan untuk data berbeda.']);
                }

                return $existing;
            }

            $baseUnit = $product->baseUnit();
            if (! $baseUnit) {
                throw ValidationException::withMessages(['product_id' => 'Menu harus memiliki satuan dasar sebelum dicatat sebagai waste.']);
            }

            $this->inventoryLedgerService->ensureProductOpeningBalance($product, $lockedShift->warehouse_id, $actor->id);
            $warehouseProduct = ProductWarehouse::where('product_id', $product->id)
                ->where('warehouse_id', $lockedShift->warehouse_id)
                ->lockForUpdate()
                ->first();
            $available = (int) ($warehouseProduct?->stock ?? 0);
            if ($available < $quantity) {
                throw ValidationException::withMessages(['quantity' => "Stok {$product->title} tidak mencukupi. Tersedia: {$available}."]);
            }

            $balanceKey = "warehouse:{$lockedShift->warehouse_id}:product:{$product->id}";
            $balance = InventoryBalance::where('balance_key', $balanceKey)->lockForUpdate()->firstOrFail();
            $unitCost = (int) $balance->average_unit_cost;
            $totalCost = $unitCost * $quantity;
            $number = 'WST-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $record = OutletWasteRecord::create([
                'waste_number' => $number,
                'request_key' => $requestKey,
                'warehouse_id' => $lockedShift->warehouse_id,
                'cashier_shift_id' => $lockedShift->id,
                'product_id' => $product->id,
                'unit_id' => $baseUnit->id,
                'quantity' => $quantity,
                'unit_cost_snapshot' => $unitCost,
                'total_cost' => $totalCost,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            $this->inventoryLedgerService->record([
                'idempotency_key' => "outlet-waste:{$record->id}",
                'item_type' => 'product',
                'item_id' => $product->id,
                'location_type' => 'warehouse',
                'location_id' => $lockedShift->warehouse_id,
                'movement_type' => 'waste',
                'quantity' => '-'.$quantity,
                'unit_id' => $baseUnit->id,
                'reference_type' => OutletWasteRecord::class,
                'reference_id' => $record->id,
                'notes' => $reason.($notes ? ': '.$notes : ''),
                'created_by' => $actor->id,
            ]);

            $product->decrement('stock', $quantity);
            $warehouseProduct?->decrement('stock', $quantity);
            $this->stockMutationService->recordMutation(
                product: $product,
                warehouseId: $lockedShift->warehouse_id,
                referenceType: 'outlet_waste',
                referenceId: $record->id,
                mutationType: 'out',
                qty: $quantity,
                stockBefore: $available,
                stockAfter: $available - $quantity,
                notes: $reason.($notes ? ': '.$notes : ''),
                userId: $actor->id,
            );
            $this->auditLogService->log(
                event: 'outlet_waste.created',
                module: 'outlet_operations',
                auditable: $record,
                description: "Waste {$record->waste_number} dicatat.",
                after: $record->only(['waste_number', 'warehouse_id', 'product_id', 'quantity', 'total_cost', 'reason']),
            );

            $highWasteThreshold = (int) config('operations.high_waste_cost_threshold', 50000);
            if ($totalCost >= $highWasteThreshold) {
                app(OperationalNotificationService::class)->notifyWarehouseUsers(
                    event: 'outlet.high_waste',
                    idempotencyKey: 'outlet-waste:'.$record->id.':high-cost',
                    title: 'Nilai waste melewati batas',
                    body: 'Waste '.$record->waste_number.' bernilai Rp'.number_format($totalCost, 0, ',', '.').'.',
                    url: '/outlet-operations',
                    warehouseId: (int) $lockedShift->warehouse_id,
                    permissions: ['outlet-operations-access'],
                );
            }

            return $record;
        }, attempts: 3);
    }

    private function assertActorShift(CashierShift $shift, User $actor): void
    {
        if (! $shift->isOpen() || (int) $shift->user_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['shift' => 'Pencatatan operasional hanya dapat dilakukan pada shift aktif milik Anda.']);
        }
    }
}
