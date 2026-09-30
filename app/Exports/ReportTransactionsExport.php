<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReportTransactionsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $transactions) {}

    public function query(): Builder
    {
        return $this->transactions;
    }

    public function headings(): array
    {
        return ['Invoice', 'Tanggal', 'Cabang/Gudang', 'Kasir', 'Pelanggan', 'Jumlah Item', 'Pendapatan', 'Diskon', 'Laba'];
    }

    public function map($transaction): array
    {
        return [
            $transaction->invoice,
            $transaction->created_at?->format('Y-m-d H:i:s'),
            $transaction->warehouse?->name ?? '',
            $transaction->cashier?->name ?? '',
            $transaction->customer?->name ?? 'Umum',
            (float) ($transaction->total_items ?? 0),
            (int) $transaction->grand_total,
            (int) ($transaction->discount ?? 0),
            (int) ($transaction->total_profit ?? 0),
        ];
    }
}
