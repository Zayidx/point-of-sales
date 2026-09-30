<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\RecipeVersion;
use App\Models\UnitConversion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeService
{
    public function createVersion(Product $menu, array $items, string|int $yieldQuantity = 1, ?string $notes = null, ?int $userId = null, ?int $unitId = null): RecipeVersion
    {
        validator(['yield_quantity' => $yieldQuantity, 'items' => $items], [
            'yield_quantity' => ['required', 'numeric', 'gt:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_id' => ['required', 'integer', 'exists:units,id'],
        ])->validate();

        return DB::transaction(function () use ($menu, $items, $yieldQuantity, $notes, $userId, $unitId) {
            $lockedMenu = Product::whereKey($menu->id)->lockForUpdate()->firstOrFail();
            if ($unitId !== null && ! $lockedMenu->units()->where('units.id', $unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Satuan porsi harus terdaftar pada menu yang dipilih.']);
            }
            if ($unitId !== null && ! RecipeVersion::where('product_id', $lockedMenu->id)->whereNull('unit_id')->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Simpan resep per PCS sebelum mengatur komposisi porsi.']);
            }
            $versionNumber = ((int) RecipeVersion::where('product_id', $lockedMenu->id)->max('version_number')) + 1;
            $recipe = RecipeVersion::create([
                'product_id' => $lockedMenu->id,
                'unit_id' => $unitId,
                'version_number' => $versionNumber,
                'yield_quantity' => $yieldQuantity,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($items as $line) {
                $ingredient = Ingredient::findOrFail($line['ingredient_id']);
                $factor = $this->conversionFactor((int) $line['unit_id'], (int) $ingredient->base_unit_id);
                $baseQuantity = round((float) $line['quantity'] * $factor, 4);
                if ($baseQuantity <= 0) {
                    throw ValidationException::withMessages(['items' => 'Recipe quantities must convert to a positive base quantity.']);
                }

                $recipe->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'],
                    'base_quantity' => number_format($baseQuantity, 4, '.', ''),
                ]);
            }

            return $recipe->load('items.ingredient', 'items.unit');
        }, attempts: 3);
    }

    private function conversionFactor(int $fromUnitId, int $baseUnitId): float
    {
        if ($fromUnitId === $baseUnitId) {
            return 1;
        }

        $factor = UnitConversion::where('from_unit_id', $fromUnitId)
            ->where('to_unit_id', $baseUnitId)
            ->value('conversion_factor');

        if (! $factor || (float) $factor <= 0) {
            throw ValidationException::withMessages(['items' => 'A direct conversion to the ingredient base unit is required.']);
        }

        return (float) $factor;
    }
}
