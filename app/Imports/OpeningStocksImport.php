<?php

namespace App\Imports;

use App\Imports\Concerns\CollectsImportFailures;
use App\Models\Ingredient;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\InventoryLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class OpeningStocksImport implements SkipsOnFailure, ToModel, WithChunkReading, WithHeadingRow, WithValidation
{
    use CollectsImportFailures;
    use RemembersRowNumber;

    private array $seen = [];

    private int $rowCount = 0;

    public function __construct(
        private readonly string $fileFingerprint,
        private readonly int $userId,
        private readonly array $allowedWarehouseIds,
    ) {}

    public function model(array $row): ?ProductWarehouse
    {
        $rowNumber = (int) $this->getRowNumber();
        $warehouse = Warehouse::where('code', trim($row['kode_gudang']))->first();
        if (! $warehouse || ! in_array((int) $warehouse->id, $this->allowedWarehouseIds, true)) {
            $this->addImportFailure($rowNumber, 'kode_gudang', ['Gudang tidak ditemukan atau berada di luar cakupan akses Anda.'], $row);

            return null;
        }

        $kind = strtolower(trim($row['jenis_barang']));
        $itemType = $kind === 'bahan' ? 'ingredient' : 'product';
        $itemCode = trim($row['kode_barang']);
        $item = $kind === 'bahan'
            ? Ingredient::where('code', $itemCode)->first()
            : Product::where('sku', $itemCode)->first();
        if (! $item) {
            $this->addImportFailure($rowNumber, 'kode_barang', ['Kode bahan/menu tidak ditemukan.'], $row);

            return null;
        }

        $unit = $kind === 'bahan' ? Unit::find($item->base_unit_id) : $item->baseUnit();
        if (! $unit) {
            $this->addImportFailure($rowNumber, 'kode_barang', ['Barang belum memiliki satuan dasar.'], $row);

            return null;
        }

        if ($kind === 'produk' && (float) $row['jumlah'] !== floor((float) $row['jumlah'])) {
            $this->addImportFailure($rowNumber, 'jumlah', ['Jumlah produk jadi harus berupa bilangan bulat.'], $row);

            return null;
        }

        $seenKey = implode(':', [$warehouse->id, $itemType, $item->id]);
        if (isset($this->seen[$seenKey])) {
            $this->addImportFailure($rowNumber, 'kode_barang', ['Barang yang sama hanya boleh muncul satu kali untuk setiap gudang.'], $row);

            return null;
        }
        $this->seen[$seenKey] = true;

        try {
            return DB::transaction(function () use ($row, $rowNumber, $warehouse, $itemType, $item, $unit, $kind) {
                $idempotencyKey = 'opening-import:'.$this->fileFingerprint.':'.$rowNumber;
                $existingImportRow = InventoryLedger::where('idempotency_key', $idempotencyKey)->exists();
                if ($kind === 'produk' && ! $existingImportRow) {
                    $pivot = ProductWarehouse::where('warehouse_id', $warehouse->id)
                        ->where('product_id', $item->id)
                        ->lockForUpdate()
                        ->first();
                    if ((int) ($pivot?->stock ?? 0) > 0) {
                        throw ValidationException::withMessages(['jumlah' => 'Saldo awal tidak dapat ditambahkan karena menu sudah memiliki stok di gudang ini.']);
                    }
                }

                app(InventoryLedgerService::class)->recordOpeningStock([
                    'idempotency_key' => $idempotencyKey,
                    'item_type' => $itemType,
                    'item_id' => (int) $item->id,
                    'location_type' => 'warehouse',
                    'location_id' => (int) $warehouse->id,
                    'movement_type' => 'opening_stock',
                    'quantity' => (string) $row['jumlah'],
                    'unit_id' => (int) $unit->id,
                    'unit_cost' => (string) ($row['biaya_satuan'] ?? 0),
                    'reference_number' => 'OS-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 16)),
                    'reference_type' => 'opening_stock_import',
                    'notes' => 'Impor saldo awal baris '.$rowNumber,
                    'created_by' => $this->userId,
                ]);

                if ($kind === 'produk' && ! $existingImportRow) {
                    $pivot = ProductWarehouse::firstOrNew([
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $item->id,
                    ]);
                    $pivot->stock = (int) ($pivot->stock ?? 0) + (int) $row['jumlah'];
                    $pivot->save();
                    $item->increment('stock', (int) $row['jumlah']);
                }

                $this->rowCount++;

                return null;
            }, attempts: 3);
        } catch (ValidationException $exception) {
            $this->addImportFailure($rowNumber, 'jumlah', collect($exception->errors())->flatten()->all(), $row);

            return null;
        }
    }

    public function rules(): array
    {
        return [
            'jenis_barang' => ['required', 'in:bahan,produk'],
            'kode_barang' => ['required', 'string', 'max:100'],
            'kode_gudang' => ['required', 'string', 'max:40'],
            'jumlah' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'biaya_satuan' => ['required', 'numeric', 'min:0', 'max:999999999999'],
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
