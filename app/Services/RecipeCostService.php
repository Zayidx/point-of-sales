<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\RecipeVersion;
use App\Models\Warehouse;

class RecipeCostService
{
    public function estimate(Product $menu, Warehouse $warehouse): array
    {
        $recipe = RecipeVersion::where('product_id', $menu->id)
            ->whereNull('unit_id')
            ->orderByDesc('version_number')
            ->with('items.ingredient')
            ->first();

        if (! $recipe) {
            return ['recipe_version_id' => null, 'version_number' => null, 'unit_hpp' => '0.00', 'lines' => []];
        }

        $totalCents = 0;
        $lines = [];
        foreach ($recipe->items as $item) {
            $balanceKey = "warehouse:{$warehouse->id}:ingredient:{$item->ingredient_id}";
            $averageCostCents = $this->toScaledInteger((string) (InventoryBalance::where('balance_key', $balanceKey)->value('average_unit_cost') ?? '0.00'), 2);
            $quantityScaled = $this->toScaledInteger((string) $item->base_quantity, 4);
            $lineCostCents = intdiv(($quantityScaled * $averageCostCents) + 5000, 10000);
            $totalCents += $lineCostCents;
            $lines[] = [
                'ingredient_id' => $item->ingredient_id,
                'ingredient' => $item->ingredient->name,
                'base_quantity' => $item->base_quantity,
                'unit_cost' => $this->fromScaledInteger($averageCostCents, 2),
                'line_cost' => $this->fromScaledInteger($lineCostCents, 2),
            ];
        }

        $yieldScaled = $this->toScaledInteger((string) $recipe->yield_quantity, 4);
        $unitCostCents = intdiv(($totalCents * 10000) + intdiv($yieldScaled, 2), $yieldScaled);

        return [
            'recipe_version_id' => $recipe->id,
            'version_number' => $recipe->version_number,
            'unit_hpp' => $this->fromScaledInteger($unitCostCents, 2),
            'lines' => $lines,
        ];
    }

    private function toScaledInteger(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $factor = 10 ** $scale;
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $scaled = ((int) $whole * $factor) + (int) $fraction;
        if ((int) substr($value, strpos($value, '.') !== false ? strpos($value, '.') + $scale + 1 : strlen($value), 1) >= 5) {
            $scaled++;
        }

        return $scaled;
    }

    private function fromScaledInteger(int $value, int $scale): string
    {
        $factor = 10 ** $scale;

        return intdiv($value, $factor).'.'.str_pad((string) ($value % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
