<?php

namespace App\Exports;

use App\Models\Ingredient;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class IngredientsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Ingredient::query()->with(['category:id,name', 'baseUnit:id,code,name,symbol'])->orderBy('code');
    }

    public function headings(): array
    {
        return ['kode', 'nama', 'kategori', 'satuan_dasar', 'biaya_satuan', 'status'];
    }

    public function map($ingredient): array
    {
        return [$ingredient->code, $ingredient->name, $ingredient->category?->name, $ingredient->baseUnit?->code, $ingredient->default_unit_cost, $ingredient->is_active ? 'Aktif' : 'Nonaktif'];
    }
}
