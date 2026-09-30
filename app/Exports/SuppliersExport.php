<?php

namespace App\Exports;

use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SuppliersExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Supplier::query()->orderBy('name');
    }

    public function headings(): array
    {
        return ['nama', 'telepon', 'email', 'alamat'];
    }

    public function map($supplier): array
    {
        return [$supplier->name, $supplier->phone, $supplier->email, $supplier->address];
    }
}
