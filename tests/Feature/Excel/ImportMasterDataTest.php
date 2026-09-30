<?php

namespace Tests\Feature\Excel;

use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\RecipeVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ImportMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_can_import_ingredients_and_suppliers_from_validated_templates(): void
    {
        $this->seed();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();

        $this->actingAs($finance)->post(route('import.ingredients'), [
            'file' => UploadedFile::fake()->createWithContent(
                'ingredients.csv',
                "kode,nama,kategori,satuan_dasar,biaya_satuan,deskripsi\nBHN-CSV,Tepung Uji,Import Uji,GRAM,2.5,Untuk uji import\n",
            ),
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($finance)->post(route('import.suppliers'), [
            'file' => UploadedFile::fake()->createWithContent(
                'suppliers.csv',
                "nama,telepon,email,alamat\nPemasok CSV,08123456789,import@example.test,Bekasi\n",
            ),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('ingredients', ['code' => 'BHN-CSV', 'name' => 'Tepung Uji']);
        $this->assertDatabaseHas('suppliers', ['email' => 'import@example.test', 'name' => 'Pemasok CSV']);
        $this->assertSame(0, InventoryLedger::count());
    }

    public function test_manager_cannot_use_finance_master_data_import_routes(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)->post(route('import.ingredients'), [])->assertForbidden();
        $this->actingAs($manager)->post(route('import.suppliers'), [])->assertForbidden();
    }

    public function test_product_master_import_cannot_change_stock_outside_inventory_ledger(): void
    {
        $this->seed();
        $category = Category::create(['name' => 'CSV Uji', 'description' => 'Uji', 'image' => 'test.png']);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'test.png',
            'barcode' => 'BC-CSV-1',
            'sku' => 'SKU-CSV-1',
            'title' => 'Nama Lama',
            'description' => 'Deskripsi lama',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 7,
            'tax_rate' => 0,
        ]);
        $product->warehouses()->attach(
            Warehouse::where('code', 'PUSAT')->value('id'),
            ['stock' => 7],
        );
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "barcode,sku,nama,deskripsi,kategori,harga_beli,harga_jual,stok,min_stok,max_stok,tipe_pajak,tarif_pajak\nBC-CSV-1,SKU-CSV-1,Nama Baru,Deskripsi baru,CSV Uji,1500,2500,999,0,0,exclusive,0\n",
        );

        Excel::import(new ProductsImport, $file);

        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertSame(7, (int) $product->warehouses()->first()->pivot->stock);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }

    public function test_warehouse_can_import_opening_ingredient_stock_idempotently_but_finance_cannot(): void
    {
        $this->seed();
        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $ingredient = Ingredient::create([
            'code' => 'BHN-OPEN-1',
            'name' => 'Tepung Saldo Awal',
            'base_unit_id' => Unit::where('code', 'GRAM')->value('id'),
            'default_unit_cost' => 2.5,
            'is_active' => true,
        ]);
        $csv = "jenis_barang,kode_barang,kode_gudang,jumlah,biaya_satuan\nbahan,BHN-OPEN-1,PUSAT,250,2.5\n";

        $this->actingAs($finance)->post(route('import.opening-stock'), [])->assertForbidden();

        foreach ([1, 2] as $_) {
            $this->actingAs($warehouseUser)->post(route('import.opening-stock'), [
                'file' => UploadedFile::fake()->createWithContent('saldo-awal.csv', $csv),
            ])->assertRedirect()->assertSessionHas('success')->assertSessionHas('importErrors', []);
        }

        $this->assertDatabaseCount('inventory_ledgers', 1);
        $this->assertDatabaseHas('inventory_balances', [
            'item_type' => 'ingredient',
            'item_id' => $ingredient->id,
            'quantity' => '250.0000',
        ]);
    }

    public function test_warehouse_recipe_import_groups_rows_and_skips_identical_versions(): void
    {
        $this->seed();
        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $category = Category::create(['name' => 'Resep CSV', 'description' => 'Uji', 'image' => 'test.png']);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'test.png',
            'barcode' => 'BC-RECIPE-1',
            'sku' => 'SKU-RECIPE-1',
            'title' => 'Dimsum Uji',
            'description' => 'Uji impor resep',
            'buy_price' => 0,
            'sell_price' => 1000,
            'stock' => 0,
            'tax_rate' => 0,
        ]);
        Ingredient::create([
            'code' => 'BHN-RECIPE-1',
            'name' => 'Tepung Resep',
            'base_unit_id' => Unit::where('code', 'GRAM')->value('id'),
            'default_unit_cost' => 2.5,
            'is_active' => true,
        ]);
        $csv = "sku_menu,hasil_per_batch,catatan,kode_bahan,jumlah,satuan\nSKU-RECIPE-1,12,Uji resep,BHN-RECIPE-1,25,GRAM\n";

        foreach ([1, 2] as $_) {
            $this->actingAs($warehouseUser)->post(route('import.recipes'), [
                'file' => UploadedFile::fake()->createWithContent('resep.csv', $csv),
            ])->assertRedirect()->assertSessionHas('success')->assertSessionHas('importErrors', []);
        }

        $this->assertSame(1, RecipeVersion::where('product_id', $product->id)->count());
        $this->assertDatabaseHas('recipe_items', [
            'ingredient_id' => Ingredient::where('code', 'BHN-RECIPE-1')->value('id'),
            'quantity' => '25.0000',
            'base_quantity' => '25.0000',
        ]);
    }
}
