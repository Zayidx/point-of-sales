<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CashierShiftStockCount;
use App\Models\InventoryBalance;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftService
{
    public function __construct(private readonly OutletAccessService $outletAccessService) {}

    public function getActiveShiftForUser(int $userId): ?CashierShift
    {
        return CashierShift::query()
            ->with(['user:id,name', 'openedBy:id,name'])
            ->open()
            ->where('user_id', $userId)
            ->latest('opened_at')
            ->first();
    }

    public function requireActiveShiftForUser(int $userId, bool $lockForUpdate = false): CashierShift
    {
        $query = CashierShift::query()
            ->open()
            ->where('user_id', $userId)
            ->latest('opened_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $shift = $query->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => 'Shift kasir belum dibuka.',
            ]);
        }

        return $shift;
    }

    public function openShift(
        User $cashier,
        User $actor,
        int $openingCash,
        ?string $notes = null,
        ?int $warehouseId = null,
        ?int $outletId = null,
    ): CashierShift {
        $outletId ??= app(OutletAccessService::class)->defaultOutlet($cashier)?->id;

        $existing = CashierShift::query()
            ->open()
            ->where('user_id', $cashier->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'opening_cash' => 'Kasir ini masih memiliki shift aktif.',
            ]);
        }

        return CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $actor->id,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'expected_cash' => $openingCash,
            'notes' => $notes,
            'warehouse_id' => $warehouseId,
            'outlet_id' => $outletId,
            'status' => CashierShift::STATUS_OPEN,
        ]);
    }

    public function calculateSummary(CashierShift $shift): array
    {
        $transactions = Transaction::query()
            ->where('cashier_shift_id', $shift->id);

        $salesReturns = SalesReturn::query()
            ->where('cashier_shift_id', $shift->id)
            ->where('status', 'completed');

        $cashSalesTotal = (int) (clone $transactions)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        // Split payments: cash tender portions must count toward the drawer,
        // the parent row's payment_method ('split') puts grand_total in the non-cash bucket.
        $splitCashTenderTotal = (int) TransactionTender::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('cashier_shift_id', $shift->id)
                ->where('payment_method', 'split')
                ->where('payment_status', 'paid'))
            ->where('method', TransactionTender::METHOD_CASH)
            ->sum('amount');

        $cashSalesTotal += $splitCashTenderTotal;

        $nonCashSalesTotal = (int) (clone $transactions)
            ->where('payment_method', '!=', 'cash')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        // Split payments: non-cash portion = grand_total minus cash tenders.
        $nonCashSalesTotal -= $splitCashTenderTotal;

        $cashRefundTotal = (int) (clone $salesReturns)
            ->where('return_type', 'refund_cash')
            ->sum('refund_amount');

        $nonCashRefundTotal = (int) (clone $salesReturns)
            ->where('return_type', '!=', 'refund_cash')
            ->sum(DB::raw('COALESCE(credited_amount, 0)'));

        $transactionsCount = (int) (clone $transactions)->count();
        $salesReturnsCount = (int) (clone $salesReturns)->count();

        $cashInTotal = (int) $shift->cashMovements()
            ->where('type', ShiftCashMovement::TYPE_IN)
            ->sum('amount');
        $cashOutTotal = (int) $shift->cashMovements()
            ->where('type', ShiftCashMovement::TYPE_OUT)
            ->sum('amount');

        $expectedCash = (int) $shift->opening_cash + $cashSalesTotal - $cashRefundTotal + $cashInTotal - $cashOutTotal;

        return [
            'cash_sales_total' => $cashSalesTotal,
            'non_cash_sales_total' => $nonCashSalesTotal,
            'cash_refund_total' => $cashRefundTotal,
            'non_cash_refund_total' => $nonCashRefundTotal,
            'cash_in_total' => $cashInTotal,
            'cash_out_total' => $cashOutTotal,
            'transactions_count' => $transactionsCount,
            'sales_returns_count' => $salesReturnsCount,
            'expected_cash' => $expectedCash,
        ];
    }

    public function recordCashMovement(CashierShift $shift, User $actor, string $type, int $amount, ?string $note = null): ShiftCashMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift sudah ditutup, tidak dapat mencatat pergerakan kas.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal harus lebih besar dari nol.',
            ]);
        }

        if (! in_array($type, [ShiftCashMovement::TYPE_IN, ShiftCashMovement::TYPE_OUT], true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipe pergerakan kas tidak valid.',
            ]);
        }

        return ShiftCashMovement::create([
            'cashier_shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'note' => $note,
            'user_id' => $actor->id,
        ]);
    }

    public function closeShift(
        CashierShift $shift,
        User $actor,
        int $actualCash,
        ?string $closeNotes = null,
        bool $forceClose = false,
        ?array $closingStock = null,
        ?array $closingIngredients = null,
    ): CashierShift {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
            ]);
        }

        return DB::transaction(function () use ($shift, $actor, $actualCash, $closeNotes, $forceClose, $closingStock, $closingIngredients) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
                ]);
            }

            $summary = $this->calculateSummary($lockedShift);
            $cashDifference = $actualCash - $summary['expected_cash'];

            $stockVariance = 0;
            if ($closingStock !== null) {
                $sharedSalesOutlets = $lockedShift->warehouse_id
                    ? $lockedShift->warehouse()->first()?->outlets()->where('outlets.is_sales_enabled', true)->count() > 1
                    : false;
                if ($sharedSalesOutlets) {
                    throw ValidationException::withMessages([
                        'closing_stock' => 'Stok gudang bersama dihitung oleh petugas gudang, bukan per shift outlet.',
                    ]);
                }

                $expectedRows = $lockedShift->warehouse_id
                    ? DB::table('product_warehouse')->where('warehouse_id', $lockedShift->warehouse_id)->orderBy('product_id')->get(['product_id', 'stock'])
                    : collect();
                $provided = collect($closingStock)->keyBy(fn (array $item) => (int) $item['product_id']);
                $expectedIds = $expectedRows->pluck('product_id')->map(fn ($id) => (int) $id)->all();
                $providedIds = $provided->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();

                if ($providedIds !== $expectedIds) {
                    throw ValidationException::withMessages([
                        'closing_stock' => 'Hitung stok untuk seluruh menu yang tercatat di gudang sebelum menutup shift.',
                    ]);
                }

                foreach ($expectedRows as $row) {
                    $productId = (int) $row->product_id;
                    $actual = (int) $provided->get($productId)['actual_stock'];
                    $expected = (int) $row->stock;
                    $variance = $actual - $expected;
                    $stockVariance += abs($variance);
                    CashierShiftStockCount::updateOrCreate(
                        ['cashier_shift_id' => $lockedShift->id, 'product_id' => $productId],
                        ['expected_stock' => $expected, 'actual_stock' => $actual, 'variance' => $variance],
                    );
                }

                $this->returnClosingStockToCentral($lockedShift, $provided, $expectedRows, $actor);
            }

            if ($closingIngredients !== null) {
                $this->returnClosingIngredientsToCentral($lockedShift, $closingIngredients, $actor);
            }

            $lockedShift->update([
                'actual_cash' => $actualCash,
                'expected_cash' => $summary['expected_cash'],
                'cash_sales_total' => $summary['cash_sales_total'],
                'non_cash_sales_total' => $summary['non_cash_sales_total'],
                'cash_refund_total' => $summary['cash_refund_total'],
                'non_cash_refund_total' => $summary['non_cash_refund_total'],
                'transactions_count' => $summary['transactions_count'],
                'sales_returns_count' => $summary['sales_returns_count'],
                'cash_difference' => $cashDifference,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'close_notes' => $closeNotes,
                'status' => $forceClose
                    ? CashierShift::STATUS_FORCE_CLOSED
                    : CashierShift::STATUS_CLOSED,
            ]);

            if ($cashDifference !== 0 && $lockedShift->warehouse_id) {
                app(OperationalNotificationService::class)->notifyWarehouseUsers(
                    event: 'cash.variance',
                    idempotencyKey: 'cashier-shift:'.$lockedShift->id.':cash-variance',
                    title: 'Ada selisih kas saat closing',
                    body: 'Shift #'.$lockedShift->id.' memiliki selisih Rp'.number_format(abs($cashDifference), 0, ',', '.').'.',
                    url: '/cashier-shifts/'.$lockedShift->id,
                    warehouseId: (int) $lockedShift->warehouse_id,
                    permissions: ['cashier-shifts-access'],
                );
            }

            if ($stockVariance > 0 && $lockedShift->warehouse_id) {
                app(OperationalNotificationService::class)->notifyWarehouseUsers(
                    event: 'stock.variance',
                    idempotencyKey: 'cashier-shift:'.$lockedShift->id.':stock-variance',
                    title: 'Ada selisih stok saat closing',
                    body: 'Shift #'.$lockedShift->id.' mencatat selisih absolut '.$stockVariance.' unit.',
                    url: '/cashier-shifts/'.$lockedShift->id,
                    warehouseId: (int) $lockedShift->warehouse_id,
                    permissions: ['cashier-shifts-access'],
                );
            }

            return $lockedShift->fresh(['user:id,name', 'openedBy:id,name', 'closedBy:id,name']);
        });
    }

    private function returnClosingIngredientsToCentral(CashierShift $shift, array $closingIngredients, User $actor): void
    {
        $openingItems = $shift->openingItems()
            ->where('item_type', 'ingredient')
            ->lockForUpdate()
            ->get();
        $provided = collect($closingIngredients)->keyBy(fn (array $item) => (int) $item['opening_item_id']);
        $expectedIds = $openingItems->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $providedIds = $provided->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($providedIds !== $expectedIds) {
            throw ValidationException::withMessages([
                'closing_ingredients' => 'Hitung sisa seluruh bahan yang dibawa sebelum menutup shift.',
            ]);
        }

        $central = Warehouse::query()->where('code', 'PUSAT')->where('is_active', true)->lockForUpdate()->first();
        if (! $central) {
            throw ValidationException::withMessages(['closing_ingredients' => 'Gudang Pusat tidak ditemukan.']);
        }

        $ledger = app(InventoryLedgerService::class);
        foreach ($openingItems as $openingItem) {
            $actual = (float) $provided->get($openingItem->id)['actual_quantity'];
            $issued = (float) $openingItem->quantity;
            if ($actual > $issued) {
                throw ValidationException::withMessages([
                    'closing_ingredients' => 'Sisa '.$openingItem->ingredient?->name.' tidak boleh melebihi jumlah yang dibawa saat shift dibuka.',
                ]);
            }

            $outletBalanceKey = "warehouse:{$shift->warehouse_id}:ingredient:{$openingItem->ingredient_id}";
            $outletBalance = InventoryBalance::where('balance_key', $outletBalanceKey)->lockForUpdate()->first();
            $systemQuantity = (float) ($outletBalance?->quantity ?? 0);
            if ($actual > $systemQuantity) {
                throw ValidationException::withMessages([
                    'closing_ingredients' => 'Sisa fisik '.$openingItem->ingredient?->name.' melebihi stok tercatat di outlet.',
                ]);
            }

            $ingredient = $openingItem->ingredient;
            $unitCost = (string) ($outletBalance?->average_unit_cost ?? $openingItem->unit_cost);
            $adjustment = $actual - $systemQuantity;
            if (abs($adjustment) >= 0.00005) {
                $ledger->record([
                    'idempotency_key' => "shift-closing:{$shift->id}:ingredient:{$ingredient->id}:count-adjustment",
                    'item_type' => 'ingredient', 'item_id' => $ingredient->id, 'location_type' => 'warehouse',
                    'location_id' => $shift->warehouse_id, 'movement_type' => 'stock_adjustment',
                    'quantity' => (string) $adjustment, 'unit_id' => $openingItem->unit_id, 'unit_cost' => $unitCost,
                    'reference_type' => CashierShift::class, 'reference_id' => $shift->id,
                    'reference_number' => 'SHIFT-'.$shift->id, 'notes' => 'Penyesuaian hasil timbang saat penutupan shift.',
                    'created_by' => $actor->id,
                ]);
            }

            if ($actual > 0) {
                foreach ([[$shift->warehouse_id, -$actual], [$central->id, $actual]] as [$warehouseId, $quantity]) {
                    $ledger->record([
                        'idempotency_key' => "shift-closing:{$shift->id}:ingredient:{$ingredient->id}:warehouse:{$warehouseId}",
                        'item_type' => 'ingredient', 'item_id' => $ingredient->id, 'location_type' => 'warehouse',
                        'location_id' => $warehouseId, 'movement_type' => 'outlet_to_warehouse',
                        'quantity' => (string) $quantity, 'unit_id' => $openingItem->unit_id, 'unit_cost' => $unitCost,
                        'reference_type' => CashierShift::class, 'reference_id' => $shift->id,
                        'reference_number' => 'SHIFT-'.$shift->id, 'notes' => 'Pengembalian sisa bahan ke Gudang Pusat.',
                        'created_by' => $actor->id,
                    ]);
                }
            }

            $openingItem->update(['closing_quantity' => $actual]);
        }
    }

    private function returnClosingStockToCentral(CashierShift $shift, $provided, $expectedRows, User $actor): void
    {
        $source = Warehouse::findOrFail($shift->warehouse_id);
        $central = Warehouse::where('code', 'PUSAT')->where('is_active', true)->first();
        if (! $central || $source->is($central)) {
            return;
        }

        foreach ($expectedRows as $row) {
            $productId = (int) $row->product_id;
            $quantity = (int) $provided->get($productId)['actual_stock'];
            $available = (int) $row->stock;
            if ($quantity > $available) {
                throw ValidationException::withMessages([
                    'closing_stock' => 'Jumlah sisa '.$productId.' melebihi stok tercatat di outlet.',
                ]);
            }
            if ($quantity === 0) {
                continue;
            }

            $product = Product::with('units')->findOrFail($productId);
            $unit = $product->baseUnit();
            if ($unit) {
                $ledger = app(InventoryLedgerService::class);
                $ledger->ensureProductOpeningBalance($product, $source->id, $actor->id);
                $ledger->ensureProductOpeningBalance($product, $central->id, $actor->id);
                $sourceBalance = InventoryBalance::where('balance_key', "warehouse:{$source->id}:product:{$productId}")->first();
                $centralBalance = InventoryBalance::where('balance_key', "warehouse:{$central->id}:product:{$productId}")->first();
                foreach ([[$source, -$quantity, $sourceBalance], [$central, $quantity, $centralBalance]] as [$warehouse, $amount, $balance]) {
                    $ledger->record([
                        'idempotency_key' => "shift-closing-return:{$shift->id}:product:{$productId}:warehouse:{$warehouse->id}",
                        'item_type' => 'product',
                        'item_id' => $productId,
                        'location_type' => 'warehouse',
                        'location_id' => $warehouse->id,
                        'movement_type' => 'outlet_to_warehouse',
                        'quantity' => (string) $amount,
                        'unit_id' => $unit->id,
                        'unit_cost' => (string) ($balance?->average_unit_cost ?? $product->buy_price),
                        'reference_type' => CashierShift::class,
                        'reference_id' => $shift->id,
                        'reference_number' => 'SHIFT-'.$shift->id,
                        'notes' => 'Sisa stok outlet dikembalikan saat tutup shift.',
                        'created_by' => $actor->id,
                    ]);
                }
            }

            $sourceStock = ProductWarehouse::where('product_id', $productId)->where('warehouse_id', $source->id)->lockForUpdate()->firstOrFail();
            if ((int) $sourceStock->stock < $quantity) {
                throw ValidationException::withMessages(['closing_stock' => "Stok {$product->title} outlet berubah. Hitung ulang sebelum menutup shift."]);
            }
            $sourceBefore = (int) $sourceStock->stock;
            $sourceStock->decrement('stock', $quantity);
            $centralStock = ProductWarehouse::firstOrCreate(['product_id' => $productId, 'warehouse_id' => $central->id], ['stock' => 0]);
            $centralBefore = (int) $centralStock->stock;
            $centralStock->increment('stock', $quantity);
            $mutations = app(StockMutationService::class);
            $mutations->recordMutation($product, $source->id, 'cashier_shift_return', $shift->id, 'out', $quantity, $sourceBefore, $sourceBefore - $quantity, 'Sisa stok dikembalikan ke PUSAT', $actor->id);
            $mutations->recordMutation($product, $central->id, 'cashier_shift_return', $shift->id, 'in', $quantity, $centralBefore, $centralBefore + $quantity, 'Sisa stok outlet diterima kembali', $actor->id);
        }
    }

    public function summarizeForDisplay(?CashierShift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        $summary = $this->calculateSummary($shift);

        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opening_cash' => (int) $shift->opening_cash,
            'opened_at' => optional($shift->opened_at)?->toISOString(),
            'notes' => $shift->notes,
            'warehouse' => $shift->warehouse ? [
                'id' => $shift->warehouse->id,
                'code' => $shift->warehouse->code,
                'name' => $shift->warehouse->name,
            ] : null,
            'user' => $shift->user ? [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ] : null,
            ...$summary,
        ];
    }

    public function visibleToUser(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->can('cashier-shifts-force-close')) {
            if (Outlet::active()->count() <= 1) {
                return $query;
            }

            $warehouseIds = $this->outletAccessService->warehousesFor($user)->pluck('id')->all();
            $outletIds = $this->outletAccessService->accessibleOutlets($user)->pluck('id')->all();

            return $query->where(function (Builder $query) use ($user, $warehouseIds, $outletIds) {
                $query->where('user_id', $user->id);
                if ($outletIds !== []) {
                    $query->orWhereIn('outlet_id', $outletIds);
                } elseif ($warehouseIds !== []) {
                    $query->orWhereIn('warehouse_id', $warehouseIds);
                }
            });
        }

        return $query->where('user_id', $user->id);
    }
}
