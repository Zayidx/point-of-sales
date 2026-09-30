<?php

namespace App\Imports;

use App\Imports\Concerns\CollectsImportFailures;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Unit;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class IngredientsImport implements SkipsOnFailure, ToModel, WithChunkReading, WithHeadingRow, WithValidation
{
    use CollectsImportFailures;

    private int $rowCount = 0;

    public function model(array $row): Ingredient
    {
        $this->rowCount++;
        $categoryId = null;
        if (filled($row['kategori'] ?? null)) {
            $categoryId = IngredientCategory::firstOrCreate(['name' => trim($row['kategori'])])->id;
        }

        $ingredient = Ingredient::where('code', trim($row['kode']))->first()
            ?? new Ingredient(['code' => trim($row['kode']), 'is_active' => true]);
        $ingredient->fill([
            'name' => trim($row['nama']),
            'ingredient_category_id' => $categoryId,
            'base_unit_id' => Unit::where('code', strtoupper(trim($row['satuan_dasar'])))->value('id'),
            'default_unit_cost' => $row['biaya_satuan'] ?? 0,
            'description' => $row['deskripsi'] ?? null,
        ]);

        return $ingredient;
    }

    public function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:40'],
            'nama' => ['required', 'string', 'max:150'],
            'satuan_dasar' => ['required', 'string', 'exists:units,code'],
            'biaya_satuan' => ['nullable', 'numeric', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
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
