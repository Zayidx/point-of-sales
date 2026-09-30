<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OperationsReportExport implements FromArray, WithHeadings
{
    public function __construct(private readonly array $rows) {}

    public function headings(): array
    {
        return ['kelompok', 'tanggal', 'referensi', 'lokasi', 'nama', 'jumlah', 'nilai', 'status'];
    }

    public function array(): array
    {
        return $this->rows;
    }
}
