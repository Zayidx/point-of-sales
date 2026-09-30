<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class InventoryLedgerService
{
    /** Initialize ledger stock from the legacy balance exactly once on first ledger use. */
    public function ensureProductOpeningBalance(Product $product, int $warehouseId, ?int $userId = null): void
    {
        $unit = $product->baseUnit();
        if (! $unit) {
            return;
        }

        $balanceKey = "warehouse:{$warehouseId}:product:{$product->id}";
        $inserted = DB::table('inventory_balances')->insertOrIgnore([
            'balance_key' => $balanceKey,
            'location_type' => 'warehouse',
            'warehouse_id' => $warehouseId,
            'item_type' => 'product',
            'item_id' => $product->id,
            'quantity' => 0,
            'average_unit_cost' => 0,
            'inventory_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $inserted) {
            return;
        }

        $legacyBalance = ProductWarehouse::where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first()?->stock ?? 0;

        if ((int) $legacyBalance > 0) {
            $this->record([
                'idempotency_key' => "legacy-opening:warehouse:{$warehouseId}:product:{$product->id}",
                'item_type' => 'product',
                'item_id' => $product->id,
                'location_type' => 'warehouse',
                'location_id' => $warehouseId,
                'movement_type' => 'opening_stock',
                'quantity' => (string) $legacyBalance,
                'unit_id' => $unit->id,
                'unit_cost' => (string) $product->buy_price,
                'notes' => 'Saldo awal disalin dari stok gudang legacy saat ledger mulai digunakan.',
                'created_by' => $userId,
            ]);
        }
    }

    public function record(array $movement): InventoryLedger
    {
        $this->validateMovement($movement);

        return DB::transaction(function () use ($movement) {
            $existing = InventoryLedger::where('idempotency_key', $movement['idempotency_key'])->first();
            if ($existing) {
                $this->assertSameRequest($existing, $movement);

                return $existing;
            }

            $itemType = $movement['item_type'];
            $item = $itemType === 'ingredient'
                ? Ingredient::findOrFail($movement['item_id'])
                : Product::findOrFail($movement['item_id']);
            $unitId = $movement['unit_id'] ?? ($itemType === 'ingredient' ? $item->base_unit_id : null);
            if (! $unitId) {
                throw ValidationException::withMessages(['unit_id' => 'Satuan wajib dipilih untuk barang ini.']);
            }
            if ($itemType === 'ingredient' && (int) $unitId !== (int) $item->base_unit_id) {
                throw ValidationException::withMessages(['unit_id' => 'Mutasi bahan baku harus menggunakan satuan dasarnya.']);
            }

            $locationType = $movement['location_type'];
            $locationId = (int) $movement['location_id'];
            $warehouseId = $locationType === 'warehouse' ? $locationId : null;
            $outletId = $locationType === 'outlet' ? $locationId : null;
            $balanceKey = implode(':', [$locationType, $locationId, $itemType, $item->id]);

            DB::table('inventory_balances')->insertOrIgnore([
                'balance_key' => $balanceKey,
                'location_type' => $locationType,
                'warehouse_id' => $warehouseId,
                'outlet_id' => $outletId,
                'item_type' => $itemType,
                'item_id' => $item->id,
                'quantity' => 0,
                'average_unit_cost' => 0,
                'inventory_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = InventoryBalance::where('balance_key', $balanceKey)->lockForUpdate()->firstOrFail();
            $existing = InventoryLedger::where('idempotency_key', $movement['idempotency_key'])->first();
            if ($existing) {
                $this->assertSameRequest($existing, $movement);

                return $existing;
            }

            $quantity = $this->toScaledInteger((string) $movement['quantity'], 4);
            $before = $this->toScaledInteger((string) $balance->quantity, 4);
            $after = $before + $quantity;
            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stok tidak mencukupi untuk mutasi ini.']);
            }

            $providedUnitCost = $this->toScaledInteger((string) ($movement['unit_cost'] ?? '0'), 2);
            $unitCostCents = $quantity < 0 && $providedUnitCost <= 0
                ? $this->toScaledInteger((string) $balance->average_unit_cost, 2)
                : $providedUnitCost;
            $totalCostCents = intdiv(abs($quantity) * $unitCostCents + 5000, 10000);
            $inbound = $quantity > 0;
            $valueBeforeCents = $this->toScaledInteger((string) $balance->inventory_value, 2);
            $valueAfterCents = $inbound ? $valueBeforeCents + $totalCostCents : max(0, $valueBeforeCents - $totalCostCents);
            if ($inbound && $after > 0) {
                $averageCostCents = intdiv($valueAfterCents * 10000 + intdiv($after, 2), $after);
            } elseif ($after === 0) {
                $averageCostCents = 0;
                $valueAfterCents = 0;
            } else {
                $averageCostCents = $this->toScaledInteger((string) $balance->average_unit_cost, 2);
            }

            $ledger = InventoryLedger::create([
                'reference_number' => $movement['reference_number'] ?? 'INV-'.strtoupper(bin2hex(random_bytes(6))),
                'idempotency_key' => $movement['idempotency_key'],
                'location_type' => $locationType,
                'warehouse_id' => $warehouseId,
                'outlet_id' => $outletId,
                'item_type' => $itemType,
                'item_id' => $item->id,
                'ingredient_id' => $itemType === 'ingredient' ? $item->id : null,
                'product_id' => $itemType === 'product' ? $item->id : null,
                'movement_type' => $movement['movement_type'],
                'quantity' => $this->fromScaledInteger($quantity, 4),
                'unit_id' => $unitId,
                'unit_cost' => $this->fromScaledInteger($unitCostCents, 2),
                'total_cost' => $this->fromScaledInteger($totalCostCents, 2),
                'reference_type' => $movement['reference_type'] ?? null,
                'reference_id' => $movement['reference_id'] ?? null,
                'notes' => $movement['notes'] ?? null,
                'created_by' => $movement['created_by'] ?? null,
            ]);

            $balance->update([
                'quantity' => $this->fromScaledInteger($after, 4),
                'average_unit_cost' => $this->fromScaledInteger($averageCostCents, 2),
                'inventory_value' => $this->fromScaledInteger($valueAfterCents, 2),
            ]);

            return $ledger;
        }, attempts: 3);
    }

    /** Record an initial warehouse balance once, while allowing the same import row to be retried. */
    public function recordOpeningStock(array $movement): InventoryLedger
    {
        validator($movement, [
            'movement_type' => ['required', 'in:opening_stock'],
            'location_type' => ['required', 'in:warehouse'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ])->validate();

        return DB::transaction(function () use ($movement) {
            $balanceKey = implode(':', [
                'warehouse',
                (int) $movement['location_id'],
                $movement['item_type'],
                (int) $movement['item_id'],
            ]);
            DB::table('inventory_balances')->insertOrIgnore([
                'balance_key' => $balanceKey,
                'location_type' => 'warehouse',
                'warehouse_id' => (int) $movement['location_id'],
                'item_type' => $movement['item_type'],
                'item_id' => (int) $movement['item_id'],
                'quantity' => 0,
                'average_unit_cost' => 0,
                'inventory_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            InventoryBalance::where('balance_key', $balanceKey)->lockForUpdate()->firstOrFail();
            $existing = InventoryLedger::where('idempotency_key', $movement['idempotency_key'])->first();
            if ($existing) {
                $this->assertSameRequest($existing, $movement);

                return $existing;
            }

            $hasHistory = InventoryLedger::query()
                ->where('location_type', 'warehouse')
                ->where('warehouse_id', (int) $movement['location_id'])
                ->where('item_type', $movement['item_type'])
                ->where('item_id', (int) $movement['item_id'])
                ->exists();
            if ($hasHistory) {
                throw ValidationException::withMessages(['opening_stock' => 'Saldo awal hanya dapat diimpor untuk barang dan gudang tanpa riwayat mutasi.']);
            }

            return $this->record($movement);
        }, attempts: 3);
    }

    private function validateMovement(array $movement): void
    {
        validator($movement, [
            'idempotency_key' => ['required', 'string', 'max:120'],
            'item_type' => ['required', 'in:ingredient,product'],
            'item_id' => ['required', 'integer', 'min:1'],
            'location_type' => ['required', 'in:warehouse,outlet'],
            'location_id' => ['required', 'integer', 'min:1'],
            'movement_type' => ['required', 'in:'.implode(',', InventoryLedger::MOVEMENT_TYPES)],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'unit_id' => ['sometimes', 'integer', 'exists:units,id'],
            'reference_number' => ['sometimes', 'string', 'max:80'],
            'reference_type' => ['nullable', 'string', 'max:100'],
            'reference_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        validator($movement, [
            'location_id' => ['required', 'exists:'.($movement['location_type'] === 'warehouse' ? 'warehouses' : 'outlets').',id'],
        ])->validate();
    }

    private function assertSameRequest(InventoryLedger $existing, array $movement): void
    {
        foreach (['item_type', 'item_id', 'location_type', 'location_id', 'movement_type', 'quantity'] as $field) {
            $actual = match ($field) {
                'location_id' => $existing->location_type === 'warehouse' ? $existing->warehouse_id : $existing->outlet_id,
                default => $existing->{$field},
            };
            $matches = $field === 'quantity'
                ? $this->toScaledInteger((string) $actual, 4) === $this->toScaledInteger((string) $movement[$field], 4)
                : (string) $actual === (string) $movement[$field];
            if (! $matches) {
                throw new LogicException('The idempotency key was already used for a different inventory movement.');
            }
        }
    }

    private function toScaledInteger(string $value, int $scale): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($value), $parts)) {
            throw ValidationException::withMessages(['quantity' => 'Nilai persediaan harus menggunakan format angka desimal yang valid.']);
        }

        $factor = 10 ** $scale;
        $fraction = str_pad(substr($parts[3] ?? '', 0, $scale), $scale, '0');
        $scaled = ((int) $parts[2] * $factor) + (int) ($fraction === '' ? 0 : $fraction);
        $roundingDigit = (int) substr($parts[3] ?? '', $scale, 1);
        if ($roundingDigit >= 5) {
            $scaled++;
        }

        return ($parts[1] ?? '') === '-' ? -$scaled : $scaled;
    }

    private function fromScaledInteger(int $value, int $scale): string
    {
        $factor = 10 ** $scale;
        $absolute = abs($value);
        $formatted = intdiv($absolute, $factor).'.'.str_pad((string) ($absolute % $factor), $scale, '0', STR_PAD_LEFT);

        return $value < 0 ? '-'.$formatted : $formatted;
    }
}
