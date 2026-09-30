<?php

namespace App\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RecipesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        $latestVersions = DB::table('recipe_versions')
            ->selectRaw('product_id, MAX(version_number) as version_number')
            ->groupBy('product_id');

        return DB::table('recipe_items')
            ->join('recipe_versions', 'recipe_versions.id', '=', 'recipe_items.recipe_version_id')
            ->joinSub($latestVersions, 'latest_versions', fn ($join) => $join
                ->on('latest_versions.product_id', '=', 'recipe_versions.product_id')
                ->on('latest_versions.version_number', '=', 'recipe_versions.version_number'))
            ->join('products', 'products.id', '=', 'recipe_versions.product_id')
            ->join('ingredients', 'ingredients.id', '=', 'recipe_items.ingredient_id')
            ->join('units', 'units.id', '=', 'recipe_items.unit_id')
            ->select([
                'products.sku as menu_sku',
                'recipe_versions.yield_quantity as yield_quantity',
                'recipe_versions.notes as notes',
                'ingredients.code as ingredient_code',
                'recipe_items.quantity as quantity',
                'units.code as unit_code',
                'recipe_versions.version_number as version_number',
            ])
            ->orderBy('products.sku')
            ->orderByDesc('recipe_versions.version_number')
            ->orderBy('ingredients.code');
    }

    public function headings(): array
    {
        return ['sku_menu', 'hasil_per_batch', 'catatan', 'kode_bahan', 'jumlah', 'satuan'];
    }

    public function map($row): array
    {
        return [$row->menu_sku, $row->yield_quantity, $row->notes, $row->ingredient_code, $row->quantity, $row->unit_code];
    }
}
