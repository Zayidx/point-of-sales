<?php

namespace App\Imports;

use App\Imports\Concerns\CollectsImportFailures;
use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class SuppliersImport implements SkipsOnFailure, ToModel, WithChunkReading, WithHeadingRow, WithValidation
{
    use CollectsImportFailures;

    private int $rowCount = 0;

    public function model(array $row): Supplier
    {
        $this->rowCount++;
        $email = filled($row['email'] ?? null) ? strtolower(trim($row['email'])) : null;
        $phone = filled($row['telepon'] ?? null) ? trim($row['telepon']) : null;
        $supplier = $email
            ? Supplier::firstOrNew(['email' => $email])
            : Supplier::firstOrNew(['phone' => $phone]);
        $supplier->fill([
            'name' => trim($row['nama']),
            'phone' => $phone,
            'email' => $email,
            'address' => $row['alamat'] ?? null,
        ]);

        return $supplier;
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:telepon'],
            'telepon' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'alamat' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }
}
