<?php

namespace Tests\Feature\Transactions;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\RecipeVersion;
use App\Models\Transaction;
use App\Models\TransactionTender;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $category = Category::create([
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

        $this->product = Product::create([
            'title' => 'Produk Test',
            'sku' => 'SKU-CO-'.uniqid(),
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 100,
            'image' => 'products/test.jpg',
            'barcode' => 'BC-CO-'.uniqid(),
            'description' => 'Deskripsi produk test',
            'tax_rate' => 0,
            'category_id' => $category->id,
        ]);
        $this->warehouse->products()->attach($this->product->id, ['stock' => 100]);

        app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            0,
            null,
            $this->warehouse->id,
        );

        BankAccount::create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Toko Test',
            'is_active' => true,
        ]);

        $this->actingAs($this->cashier);
    }

    private function payCash(int $amount = 10000, ?int $unitId = null): CheckoutContext
    {
        $cartData = [
            'product_id' => $this->product->id,
            'sell_price' => 10000,
            'qty' => 1,
        ];
        if ($unitId !== null) {
            $cartData['unit_id'] = $unitId;
        }
        $this->post(route('transactions.addToCart'), $cartData);

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
            cashAmount: $amount,
            paymentGateway: null,
            useTenders: false,
            tenderInput: [],
            outlet: null,
            bankAccountId: null,
        );
    }

    public function test_cash_below_total_throws_validation(): void
    {
        $context = $this->payCash(9999);

        try {
            app(CheckoutService::class)->execute($context);
            $this->fail('Expected ValidationException for cash below total.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cash', $e->errors());
            $this->assertSame(0, Transaction::count(), 'No transaction should be created on validation failure.');
        }
    }

    public function test_cash_equal_total_succeeds(): void
    {
        $context = $this->payCash(10000);
        $result = app(CheckoutService::class)->execute($context);

        $this->assertSame(10000, $result->transaction->grand_total);
        $this->assertSame('cash', $result->paymentMethod);
        $this->assertSame('paid', $result->paymentStatus);
        $this->assertFalse($result->needsDiscountApproval);
        $this->assertSame(99, $this->warehouse->products()->where('product_id', $this->product->id)->first()->pivot->stock);
    }

    public function test_recipe_menu_sale_consumes_frozen_stock_instead_of_menu_variant_stock(): void
    {
        $unit = Unit::create(['code' => 'GRAM', 'name' => 'Gram', 'symbol' => 'g']);
        $category = IngredientCategory::create(['name' => 'Produk Frozen']);
        $frozenDimsum = Ingredient::create([
            'code' => 'FROZEN-DIMSUM',
            'name' => 'Dimsum frozen',
            'ingredient_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'default_unit_cost' => 1600,
            'is_active' => true,
        ]);
        app(InventoryLedgerService::class)->recordOpeningStock([
            'idempotency_key' => 'checkout-recipe-frozen-opening',
            'item_type' => 'ingredient',
            'item_id' => $frozenDimsum->id,
            'location_type' => 'warehouse',
            'location_id' => $this->warehouse->id,
            'movement_type' => 'opening_stock',
            'quantity' => '10',
            'unit_id' => $unit->id,
            'unit_cost' => '1600',
        ]);
        $recipe = RecipeVersion::create([
            'product_id' => $this->product->id,
            'version_number' => 1,
            'yield_quantity' => 1,
        ]);
        $recipe->items()->create([
            'ingredient_id' => $frozenDimsum->id,
            'quantity' => 1,
            'unit_id' => $unit->id,
            'base_quantity' => 1,
        ]);

        app(CheckoutService::class)->execute($this->payCash());

        $this->assertSame('9.0000', InventoryBalance::where('item_id', $frozenDimsum->id)->value('quantity'));
        $this->assertSame(100, $this->warehouse->products()->where('product_id', $this->product->id)->first()->pivot->stock);
    }

    public function test_portion_recipe_consumes_box_chopsticks_and_chili_oil_once_per_four_piece_order(): void
    {
        $pcs = Unit::where('code', 'PCS')->firstOrFail();
        $gram = Unit::create(['code' => 'GRAM', 'name' => 'Gram', 'symbol' => 'g']);
        $p4 = Unit::create(['code' => 'P4', 'name' => 'Paket isi 4', 'symbol' => 'paket 4']);
        $this->product->units()->attach($pcs->id, [
            'is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 5000,
        ]);
        $this->product->units()->attach($p4->id, [
            'is_base' => false, 'conversion_factor' => 4, 'buy_price' => 20000, 'sell_price' => 16000,
        ]);

        $category = IngredientCategory::create(['name' => 'Persediaan Uji']);
        $makeStockedItem = function (string $code, string $name, Unit $unit, int $quantity) use ($category): Ingredient {
            $ingredient = Ingredient::create([
                'code' => 'PORTION-'.$code,
                'name' => $name,
                'ingredient_category_id' => $category->id,
                'base_unit_id' => $unit->id,
                'default_unit_cost' => 1,
                'is_active' => true,
            ]);
            app(InventoryLedgerService::class)->recordOpeningStock([
                'idempotency_key' => 'portion-test-opening-'.$code,
                'item_type' => 'ingredient',
                'item_id' => $ingredient->id,
                'location_type' => 'warehouse',
                'location_id' => $this->warehouse->id,
                'movement_type' => 'opening_stock',
                'quantity' => (string) $quantity,
                'unit_id' => $unit->id,
                'unit_cost' => '1',
            ]);

            return $ingredient;
        };

        $frozen = $makeStockedItem('FROZEN', 'Dimsum frozen', $pcs, 10);
        $mentai = $makeStockedItem('MENTAI', 'Saus mentai', $gram, 100);
        $box = $makeStockedItem('BOX', 'Box kecil', $pcs, 10);
        $chopsticks = $makeStockedItem('CHOPSTICKS', 'Sumpit', $pcs, 10);
        $chiliOil = $makeStockedItem('CHILI-OIL', 'Chili oil', $gram, 100);

        $baseRecipe = RecipeVersion::create(['product_id' => $this->product->id, 'version_number' => 1, 'yield_quantity' => 1]);
        foreach ([[$frozen, 1, $pcs], [$mentai, 5, $gram]] as [$ingredient, $quantity, $unit]) {
            $baseRecipe->items()->create([
                'ingredient_id' => $ingredient->id, 'quantity' => $quantity, 'unit_id' => $unit->id, 'base_quantity' => $quantity,
            ]);
        }
        $portionRecipe = RecipeVersion::create([
            'product_id' => $this->product->id, 'unit_id' => $p4->id, 'version_number' => 2, 'yield_quantity' => 1,
        ]);
        foreach ([[$box, 1, $pcs], [$chopsticks, 1, $pcs], [$chiliOil, 50, $gram]] as [$ingredient, $quantity, $unit]) {
            $portionRecipe->items()->create([
                'ingredient_id' => $ingredient->id, 'quantity' => $quantity, 'unit_id' => $unit->id, 'base_quantity' => $quantity,
            ]);
        }

        app(CheckoutService::class)->execute($this->payCash(16000, $p4->id));

        $this->assertSame('6.0000', InventoryBalance::where('item_id', $frozen->id)->value('quantity'));
        $this->assertSame('80.0000', InventoryBalance::where('item_id', $mentai->id)->value('quantity'));
        $this->assertSame('9.0000', InventoryBalance::where('item_id', $box->id)->value('quantity'));
        $this->assertSame('9.0000', InventoryBalance::where('item_id', $chopsticks->id)->value('quantity'));
        $this->assertSame('50.0000', InventoryBalance::where('item_id', $chiliOil->id)->value('quantity'));
    }

    public function test_split_tender_creates_two_rows_and_marks_parent_split(): void
    {
        $this->post(route('transactions.addToCart'), [
            'product_id' => $this->product->id,
            'sell_price' => 10000,
            'qty' => 1,
        ]);

        $bankAccount = BankAccount::active()->first();

        $context = new CheckoutContext(
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
            isCashPayment: false,
            cashAmount: 0,
            paymentGateway: null,
            useTenders: true,
            tenderInput: [
                ['method' => TransactionTender::METHOD_CASH, 'amount' => 5000, 'cash_received' => 5000],
                ['method' => TransactionTender::METHOD_BANK_TRANSFER, 'amount' => 5000, 'bank_account_id' => $bankAccount->id],
            ],
            outlet: null,
            bankAccountId: $bankAccount->id,
        );

        $result = app(CheckoutService::class)->execute($context);

        $this->assertSame('split', $result->paymentMethod);
        $this->assertSame('paid', $result->paymentStatus);
        $this->assertSame(2, $result->transaction->tenders()->count());
        $this->assertSame(10000, (int) $result->transaction->tenders()->sum('amount'));
        $this->assertSame(5000, (int) $result->transaction->cash);
    }

    public function test_empty_cart_aborts_422(): void
    {
        $context = new CheckoutContext(
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
            cashAmount: 10000,
            paymentGateway: null,
            useTenders: false,
            tenderInput: [],
            outlet: null,
            bankAccountId: null,
        );

        $this->expectException(HttpException::class);
        app(CheckoutService::class)->execute($context);
    }
}
