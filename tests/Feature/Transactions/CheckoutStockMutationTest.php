<?php

namespace Tests\Feature\Transactions;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CheckoutService;
use App\Services\InventoryLedgerService;
use App\Support\Checkout\CheckoutContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutStockMutationTest extends TestCase
{
    use RefreshDatabase;

    protected $cashier;

    protected $warehouse;

    protected $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->category = Category::create([
            'name' => 'Kategori Test',
            'image' => 'categories/test.jpg',
            'description' => 'Kategori untuk test',
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            0,
            null,
            $this->warehouse->id,
        );

        $this->actingAs($this->cashier);
    }

    private function makeProduct(array $overrides = [])
    {
        return Product::create(array_merge([
            'title' => 'Produk '.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 100,
            'image' => 'products/test.jpg',
            'barcode' => 'BC-'.uniqid(),
            'description' => 'Deskripsi',
            'tax_rate' => 0,
            'category_id' => $this->category->id,
        ], $overrides));
    }

    private function context(int $cash = 100000): CheckoutContext
    {
        return new CheckoutContext(
            userId: $this->cashier->id,
            customer: null,
            voucher: null,
            manualDiscount: 0,
            shippingCost: 0,
            requestedRedeemPoints: 0,
            isPayLater: false,
            dueDate: null,
            orderType: null,
            note: null,
            customerNpwp: null,
            isCashPayment: true,
            cashAmount: $cash,
            paymentGateway: null,
            useTenders: false,
            tenderInput: [],
            outlet: null,
            bankAccountId: null,
        );
    }

    public function test_checkout_records_stock_mutation_out(): void
    {
        $product = $this->makeProduct();
        $this->warehouse->products()->attach($product->id, ['stock' => 100]);

        $this->post(route('transactions.addToCart'), [
            'product_id' => $product->id,
            'sell_price' => 10000,
            'qty' => 3,
        ]);

        $result = app(CheckoutService::class)->execute($this->context());

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'reference_type' => 'transaction',
            'reference_id' => $result->transaction->id,
            'mutation_type' => 'out',
            'qty' => 3,
            'stock_before' => 100,
            'stock_after' => 97,
        ]);
    }

    public function test_checkout_keeps_finished_goods_ledger_in_sync_with_legacy_pos_stock(): void
    {
        $product = $this->makeProduct();
        $piece = Unit::create(['name' => 'Pcs', 'code' => 'PCS-'.uniqid(), 'symbol' => 'pcs']);
        $product->units()->attach($piece->id, [
            'is_base' => true,
            'conversion_factor' => 1,
            'buy_price' => 5000,
            'sell_price' => 10000,
        ]);
        $this->warehouse->products()->attach($product->id, ['stock' => 100]);

        $this->post(route('transactions.addToCart'), [
            'product_id' => $product->id,
            'sell_price' => 10000,
            'qty' => 3,
        ]);
        $result = app(CheckoutService::class)->execute($this->context());

        $this->assertDatabaseHas('inventory_ledgers', [
            'idempotency_key' => "legacy-opening:warehouse:{$this->warehouse->id}:product:{$product->id}",
            'movement_type' => 'opening_stock',
            'quantity' => '100.0000',
        ]);
        $this->assertDatabaseHas('inventory_ledgers', [
            'idempotency_key' => "sale:{$result->transaction->id}:product:{$product->id}",
            'movement_type' => 'sale',
            'quantity' => '-3.0000',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'balance_key' => "warehouse:{$this->warehouse->id}:product:{$product->id}",
            'quantity' => '97.0000',
        ]);
    }

    public function test_profit_respects_unit_conversion_factor(): void
    {
        $product = $this->makeProduct();
        $this->warehouse->products()->attach($product->id, ['stock' => 100]);

        $box = Unit::create([
            'name' => 'Box',
            'code' => 'BOX-'.uniqid(),
            'symbol' => 'bx',
        ]);
        $product->units()->attach($box->id, ['conversion_factor' => 12, 'buy_price' => 5000, 'sell_price' => 130000]);

        $this->post(route('transactions.addToCart'), [
            'product_id' => $product->id,
            'sell_price' => 130000,
            'qty' => 2,
            'unit_id' => $box->id,
        ]);

        $result = app(CheckoutService::class)->execute($this->context());

        // HPP harus mengikuti konversi: 2 box x 12 pcs x 5000 = 120000.
        // Sell side tetap base price (20000) — gap pricing multi-satuan terpisah.
        $this->assertSame(20000 - 120000, (int) $result->transaction->profits()->sum('total'));

        // Stok terdecrement dalam satuan dasar: 2 x 12 = 24
        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $product->id,
            'mutation_type' => 'out',
            'qty' => 24,
            'stock_before' => 100,
            'stock_after' => 76,
        ]);
    }

    public function test_pos_hpp_uses_warehouse_weighted_average_cost_instead_of_latest_product_buy_price(): void
    {
        $product = $this->makeProduct(['buy_price' => 5000, 'sell_price' => 10000]);
        $piece = Unit::create(['name' => 'Pcs', 'code' => 'WAC-PCS-'.uniqid(), 'symbol' => 'pcs']);
        $product->units()->attach($piece->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000]);
        $this->warehouse->products()->attach($product->id, ['stock' => 100]);
        $ledger = app(InventoryLedgerService::class);
        $ledger->ensureProductOpeningBalance($product, $this->warehouse->id, $this->cashier->id);
        $ledger->record([
            'idempotency_key' => 'test-wac-receipt-'.$product->id,
            'item_type' => 'product', 'item_id' => $product->id,
            'location_type' => 'warehouse', 'location_id' => $this->warehouse->id,
            'movement_type' => 'purchase_receipt', 'quantity' => 100,
            'unit_id' => $piece->id, 'unit_cost' => 9000,
        ]);
        $product->increment('stock', 100);
        ProductWarehouse::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->increment('stock', 100);

        $this->post(route('transactions.addToCart'), ['product_id' => $product->id, 'sell_price' => 10000, 'qty' => 1]);
        $result = app(CheckoutService::class)->execute($this->context());

        $this->assertSame('7000.00', InventoryBalance::where('balance_key', "warehouse:{$this->warehouse->id}:product:{$product->id}")->value('average_unit_cost'));
        $this->assertSame(3000, (int) $result->transaction->profits()->sum('total'));
    }

    public function test_composite_sale_records_mutation_per_component(): void
    {
        $componentA = $this->makeProduct(['sell_price' => 4000]);
        $this->warehouse->products()->attach($componentA->id, ['stock' => 50]);
        $componentB = $this->makeProduct(['sell_price' => 6000]);
        $this->warehouse->products()->attach($componentB->id, ['stock' => 50]);

        $composite = $this->makeProduct([
            'title' => 'Paket Hemat',
            'is_composite' => true,
            'sell_price' => 15000,
        ]);

        $composite->components()->attach([
            $componentA->id => ['qty' => 2],
            $componentB->id => ['qty' => 1],
        ]);

        $this->post(route('transactions.addToCart'), [
            'product_id' => $composite->id,
            'sell_price' => 15000,
            'qty' => 1,
        ]);

        $result = app(CheckoutService::class)->execute($this->context());

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $componentA->id,
            'reference_type' => 'transaction',
            'reference_id' => $result->transaction->id,
            'mutation_type' => 'out',
            'qty' => 2,
            'stock_before' => 50,
            'stock_after' => 48,
        ]);

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $componentB->id,
            'reference_type' => 'transaction',
            'reference_id' => $result->transaction->id,
            'mutation_type' => 'out',
            'qty' => 1,
            'stock_before' => 50,
            'stock_after' => 49,
        ]);
    }
}
