<?php

namespace App\Imports;

use App\Imports\Concerns\CollectsImportFailures;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\RecipeVersion;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\RecipeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithLimit;

class RecipesImport implements ToCollection, WithHeadingRow, WithLimit
{
    use CollectsImportFailures;

    private int $processedGroups = 0;

    public function __construct(private readonly int $userId) {}

    public function limit(): int
    {
        return 2001;
    }

    public function collection(Collection $rows): void
    {
        if ($rows->count() > 2000) {
            $this->addImportFailure(2002, 'baris', ['Berkas melebihi batas 2.000 baris data.'], []);

            return;
        }

        $groups = [];
        foreach ($rows as $index => $row) {
            $rowNumber = (int) $index + 2;
            $values = collect($row)->map(fn ($value) => is_string($value) ? trim($value) : $value)->all();
            if (collect($values)->every(fn ($value) => blank($value))) {
                continue;
            }

            $validation = Validator::make($values, [
                'sku_menu' => ['required', 'string', 'max:100'],
                'hasil_per_batch' => ['required', 'numeric', 'gt:0', 'max:1000000'],
                'kode_bahan' => ['required', 'string', 'max:40'],
                'jumlah' => ['required', 'numeric', 'gt:0', 'max:1000000'],
                'satuan' => ['required', 'string', 'max:30'],
                'catatan' => ['nullable', 'string', 'max:2000'],
            ]);
            if ($validation->fails()) {
                foreach ($validation->errors()->all() as $message) {
                    $this->addImportFailure($rowNumber, 'baris', [$message], $values);
                }

                continue;
            }

            $product = Product::where('sku', $values['sku_menu'])->first();
            $ingredient = Ingredient::where('code', $values['kode_bahan'])->first();
            $unit = Unit::where('code', strtoupper($values['satuan']))->first();
            if (! $product || ! $ingredient || ! $unit) {
                $missing = collect([
                    ! $product ? 'SKU menu tidak ditemukan.' : null,
                    ! $ingredient ? 'Kode bahan baku tidak ditemukan.' : null,
                    ! $unit ? 'Kode satuan tidak ditemukan.' : null,
                ])->filter()->values()->all();
                $this->addImportFailure($rowNumber, 'referensi', $missing, $values);

                continue;
            }

            $factor = (int) $unit->id === (int) $ingredient->base_unit_id
                ? 1
                : UnitConversion::where('from_unit_id', $unit->id)
                    ->where('to_unit_id', $ingredient->base_unit_id)
                    ->value('conversion_factor');
            if (! $factor || (float) $factor <= 0) {
                $this->addImportFailure($rowNumber, 'satuan', ['Konversi langsung ke satuan dasar bahan belum tersedia.'], $values);

                continue;
            }

            $productId = (int) $product->id;
            $yield = number_format((float) $values['hasil_per_batch'], 4, '.', '');
            $notes = filled($values['catatan'] ?? null) ? $values['catatan'] : null;
            $group = &$groups[$productId];
            if (! isset($group)) {
                $group = [
                    'product' => $product,
                    'yield' => $yield,
                    'notes' => $notes,
                    'items' => [],
                    'rows' => [],
                ];
            }

            if ($group['yield'] !== $yield || $group['notes'] !== $notes) {
                $this->addImportFailure($rowNumber, 'hasil_per_batch/catatan', ['Nilai hasil dan catatan harus sama pada semua baris resep menu yang sama.'], $values);
                unset($group);

                continue;
            }

            if (isset($group['items'][(int) $ingredient->id])) {
                $this->addImportFailure($rowNumber, 'kode_bahan', ['Bahan yang sama hanya boleh muncul satu kali pada setiap resep menu.'], $values);
                unset($group);

                continue;
            }

            $group['items'][(int) $ingredient->id] = [
                'ingredient_id' => (int) $ingredient->id,
                'quantity' => number_format((float) $values['jumlah'], 4, '.', ''),
                'unit_id' => (int) $unit->id,
                'base_quantity' => number_format(round((float) $values['jumlah'] * (float) $factor, 4), 4, '.', ''),
            ];
            $group['rows'][] = $rowNumber;
            unset($group);
        }

        if ($this->manualFailures !== []) {
            return;
        }

        try {
            DB::transaction(function () use ($groups) {
                foreach ($groups as $group) {
                    $items = array_values($group['items']);
                    $latest = RecipeVersion::query()
                        ->with('items')
                        ->where('product_id', $group['product']->id)
                        ->orderByDesc('version_number')
                        ->first();

                    if ($latest && $this->matchesLatest($latest, $group, $items)) {
                        continue;
                    }

                    app(RecipeService::class)->createVersion(
                        $group['product'],
                        array_map(fn ($item) => [
                            'ingredient_id' => $item['ingredient_id'],
                            'quantity' => $item['quantity'],
                            'unit_id' => $item['unit_id'],
                        ], $items),
                        $group['yield'],
                        $group['notes'],
                        $this->userId,
                    );
                    $this->processedGroups++;
                }
            }, attempts: 3);
        } catch (ValidationException $exception) {
            foreach ($groups as $group) {
                foreach ($group['rows'] as $rowNumber) {
                    $this->addImportFailure($rowNumber, 'resep', ['Penyimpanan versi resep dibatalkan karena data kelompok ini tidak valid.']);
                }
            }
        }
    }

    private function matchesLatest(RecipeVersion $latest, array $group, array $items): bool
    {
        if ((string) $latest->yield_quantity !== (string) $group['yield'] || $latest->notes !== $group['notes']) {
            return false;
        }

        $existing = $latest->items->keyBy('ingredient_id');
        if ($existing->count() !== count($items)) {
            return false;
        }

        foreach ($items as $item) {
            $saved = $existing->get($item['ingredient_id']);
            if (! $saved || (int) $saved->unit_id !== (int) $item['unit_id']
                || (string) $saved->quantity !== (string) $item['quantity']
                || (string) $saved->base_quantity !== (string) $item['base_quantity']) {
                return false;
            }
        }

        return true;
    }

    public function processedGroups(): int
    {
        return $this->processedGroups;
    }
}
