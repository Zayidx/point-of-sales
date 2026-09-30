<?php

namespace App\Http\Controllers\Apps;

use App\Exports\CustomersExport;
use App\Exports\IngredientsExport;
use App\Exports\ProductsExport;
use App\Exports\RecipesExport;
use App\Exports\SuppliersExport;
use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Imports\CustomersImport;
use App\Imports\IngredientsImport;
use App\Imports\OpeningStocksImport;
use App\Imports\ProductsImport;
use App\Imports\RecipesImport;
use App\Imports\SuppliersImport;
use App\Models\Outlet;
use App\Services\OutletAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class ImportExportController extends Controller
{
    public function __construct(private readonly OutletAccessService $outletAccessService) {}

    public function exportProducts()
    {
        return Excel::download(new ProductsExport, 'produk.xlsx');
    }

    public function exportCustomers()
    {
        return Excel::download(new CustomersExport, 'customer.xlsx');
    }

    public function exportIngredients()
    {
        return Excel::download(new IngredientsExport, 'bahan-baku.xlsx');
    }

    public function exportSuppliers()
    {
        return Excel::download(new SuppliersExport, 'pemasok.xlsx');
    }

    public function exportRecipes()
    {
        return Excel::download(new RecipesExport, 'resep-menu.xlsx');
    }

    public function exportTransactions(Request $request)
    {
        $warehouseIds = $request->user()->isSuperAdmin()
            ? null
            : $this->outletAccessService->warehousesFor($request->user())->pluck('id')->all();
        $includeLegacy = Outlet::active()->count() <= 1;

        return Excel::download(new TransactionsExport($request, $warehouseIds, $includeLegacy), 'transaksi.xlsx');
    }

    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new ProductsImport;
        Excel::import($import, $request->file('file'));

        $successCount = $import->getRowCount();

        return $this->importResult($import, "{$successCount} produk berhasil diproses.");
    }

    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new CustomersImport;
        Excel::import($import, $request->file('file'));

        return $this->importResult($import, 'Impor pelanggan selesai.');
    }

    public function importIngredients(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $import = new IngredientsImport;
        Excel::import($import, $request->file('file'));

        return $this->importResult($import, "{$import->getRowCount()} bahan baku berhasil diproses.");
    }

    public function importSuppliers(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $import = new SuppliersImport;
        Excel::import($import, $request->file('file'));

        return $this->importResult($import, "{$import->getRowCount()} baris pemasok berhasil diproses.");
    }

    public function importRecipes(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $import = new RecipesImport((int) $request->user()->id);
        Excel::import($import, $request->file('file'));

        return $this->importResult($import, "{$import->processedGroups()} resep berhasil diproses.");
    }

    public function importOpeningStock(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $fingerprint = hash_file('sha256', $request->file('file')->getRealPath());
        $allowedWarehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $import = new OpeningStocksImport($fingerprint, (int) $request->user()->id, $allowedWarehouseIds);
        Excel::import($import, $request->file('file'));

        return $this->importResult($import, "{$import->getRowCount()} saldo awal berhasil diproses.");
    }

    private function importResult(object $import, string $success): RedirectResponse
    {
        return back()
            ->with('success', $success)
            ->with('importErrors', method_exists($import, 'failureReport') ? $import->failureReport() : []);
    }

    public function downloadTemplate(Request $request, string $type)
    {
        $permissions = [
            'products' => 'products-import',
            'customers' => 'customers-import',
            'ingredients' => 'ingredients-import',
            'suppliers' => 'suppliers-import',
            'recipes' => 'recipes-import',
            'opening-stock' => 'inventory-opening-stock-import',
        ];
        abort_unless(isset($permissions[$type]) && $request->user()->can($permissions[$type]), 403);

        $headings = match ($type) {
            'products' => ['barcode', 'sku', 'nama', 'deskripsi', 'kategori', 'harga_beli', 'harga_jual', 'min_stok', 'max_stok', 'tipe_pajak', 'tarif_pajak'],
            'customers' => ['nama', 'telepon', 'alamat'],
            'ingredients' => ['kode', 'nama', 'kategori', 'satuan_dasar', 'biaya_satuan', 'deskripsi'],
            'suppliers' => ['nama', 'telepon', 'email', 'alamat'],
            'recipes' => ['sku_menu', 'hasil_per_batch', 'catatan', 'kode_bahan', 'jumlah', 'satuan'],
            'opening-stock' => ['jenis_barang', 'kode_barang', 'kode_gudang', 'jumlah', 'biaya_satuan'],
            default => abort(404),
        };

        return Excel::download(
            new class($headings) implements FromArray, WithHeadings
            {
                public function __construct(private array $headings) {}

                public function headings(): array
                {
                    return $this->headings;
                }

                public function array(): array
                {
                    return [];
                }
            },
            "template-{$type}.xlsx"
        );
    }
}
