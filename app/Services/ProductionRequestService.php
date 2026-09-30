<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductionRequest;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionRequestService
{
    public function submit(string $requestKey, Product $menu, Warehouse $warehouse, string|int $targetOutput, User $requester, ?string $notes = null): ProductionRequest
    {
        validator([
            'request_key' => $requestKey,
            'target_output' => $targetOutput,
        ], [
            'request_key' => ['required', 'string', 'max:120'],
            'target_output' => ['required', 'numeric', 'gt:0'],
        ])->validate();

        return DB::transaction(function () use ($requestKey, $menu, $warehouse, $targetOutput, $requester, $notes) {
            $existing = ProductionRequest::with('items')->where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->product_id !== $menu->id
                    || $existing->warehouse_id !== $warehouse->id
                    || $this->toScaledInteger((string) $existing->target_output, 4) !== $this->toScaledInteger((string) $targetOutput, 4)) {
                    throw ValidationException::withMessages(['request_key' => 'This request key has already been used.']);
                }

                return $existing;
            }

            $recipe = RecipeVersion::where('product_id', $menu->id)
                ->whereNull('unit_id')
                ->orderByDesc('version_number')
                ->with('items.ingredient')
                ->first();
            if (! $recipe) {
                throw ValidationException::withMessages(['product_id' => 'The selected menu does not have a recipe yet.']);
            }

            $targetScaled = $this->toScaledInteger((string) $targetOutput, 4);
            $yieldScaled = $this->toScaledInteger((string) $recipe->yield_quantity, 4);
            $request = ProductionRequest::create([
                'request_number' => 'PR-'.now()->format('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3))),
                'request_key' => $requestKey,
                'warehouse_id' => $warehouse->id,
                'product_id' => $menu->id,
                'recipe_version_id' => $recipe->id,
                'target_output' => $this->fromScaledInteger($targetScaled, 4),
                'status' => 'requested',
                'notes' => $notes,
                'requested_by' => $requester->id,
            ]);

            $estimatedTotalCents = 0;
            foreach ($recipe->items as $recipeItem) {
                $basePerYield = $this->toScaledInteger((string) $recipeItem->base_quantity, 4);
                $requiredScaled = intdiv(($basePerYield * $targetScaled) + intdiv($yieldScaled, 2), $yieldScaled);
                $balanceKey = "warehouse:{$warehouse->id}:ingredient:{$recipeItem->ingredient_id}";
                $balance = InventoryBalance::where('balance_key', $balanceKey)->lockForUpdate()->first();
                $availableScaled = $balance ? $this->toScaledInteger((string) $balance->quantity, 4) : 0;
                $unitCostCents = $balance ? $this->toScaledInteger((string) $balance->average_unit_cost, 2) : 0;
                $lineCostCents = intdiv(($requiredScaled * $unitCostCents) + 5000, 10000);
                $estimatedTotalCents += $lineCostCents;

                $request->items()->create([
                    'ingredient_id' => $recipeItem->ingredient_id,
                    'unit_id' => $recipeItem->ingredient->base_unit_id,
                    'required_quantity' => $this->fromScaledInteger($requiredScaled, 4),
                    'available_quantity' => $this->fromScaledInteger($availableScaled, 4),
                    'shortage_quantity' => $this->fromScaledInteger(max(0, $requiredScaled - $availableScaled), 4),
                    'unit_cost_snapshot' => $this->fromScaledInteger($unitCostCents, 2),
                    'estimated_cost' => $this->fromScaledInteger($lineCostCents, 2),
                ]);
            }

            $request->update(['estimated_material_cost' => $this->fromScaledInteger($estimatedTotalCents, 2)]);

            app(OperationalNotificationService::class)->notifyWarehouseUsers(
                event: 'production.requested',
                idempotencyKey: 'production-request:'.$request->id.':requested',
                title: 'Permintaan produksi baru',
                body: $menu->title.' · target '.$request->target_output,
                url: '/production-requests/'.$request->id,
                warehouseId: $warehouse->id,
                permissions: ['production-requests-access'],
            );

            return $request->load('items.ingredient', 'recipeVersion');
        }, attempts: 3);
    }

    public function review(ProductionRequest $request, User $reviewer, bool $approve, ?string $reason = null): ProductionRequest
    {
        if (! $approve && blank($reason)) {
            throw ValidationException::withMessages(['rejection_reason' => 'Provide a reason when rejecting a production request.']);
        }

        return DB::transaction(function () use ($request, $reviewer, $approve, $reason) {
            $locked = ProductionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['status' => 'Only requested production requests can be reviewed.']);
            }

            $locked->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $approve ? null : $reason,
            ]);

            app(OperationalNotificationService::class)->notifyUser(
                userId: (int) $locked->requested_by,
                event: $approve ? 'production.approved' : 'production.rejected',
                idempotencyKey: 'production-request:'.$locked->id.':'.$locked->status,
                title: $approve ? 'Permintaan produksi disetujui' : 'Permintaan produksi ditolak',
                body: $approve ? 'Permintaan '.$locked->request_number.' sudah disetujui.' : 'Permintaan '.$locked->request_number.' ditolak: '.$reason,
                url: '/production-requests/'.$locked->id,
                warehouseId: (int) $locked->warehouse_id,
            );

            return $locked->fresh(['items', 'recipeVersion']);
        }, attempts: 3);
    }

    private function toScaledInteger(string $value, int $scale): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($value), $parts)) {
            throw ValidationException::withMessages(['quantity' => 'Production quantities must use standard decimal notation.']);
        }

        $factor = 10 ** $scale;
        $fraction = str_pad(substr($parts[3] ?? '', 0, $scale), $scale, '0');
        $scaled = ((int) $parts[2] * $factor) + (int) $fraction;
        if ((int) substr($parts[3] ?? '', $scale, 1) >= 5) {
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
