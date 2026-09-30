<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QrisCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_web_checkout_records_qris_manually_without_calling_a_gateway(): void
    {
        $cashier = $this->createCashier();
        $this->openShiftFor($cashier);
        $customer = Customer::create([
            'name' => 'QRIS Customer',
            'no_telp' => 62812300,
            'address' => 'Jl. QRIS No. 1',
        ]);
        $product = $this->createProduct();

        PaymentMethod::create(['code' => 'QRIS-1', 'name' => 'QRIS 1', 'type' => 'digital', 'is_active' => true]);

        Http::fake();

        $cart = Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'discount' => 0,
                'grand_total' => $cart->price,
                'cash' => 0,
                'change' => 0,
                'payment_gateway' => 'qris_1',
            ]);

        $transaction = Transaction::latest('id')->first();

        $response->assertRedirect(route('transactions.print', $transaction->invoice));
        $this->assertSame('qris_1', $transaction->payment_method);
        $this->assertSame('paid', $transaction->payment_status);
        $this->assertNull($transaction->qr_string);
        $this->assertNull($transaction->payment_reference);
        Http::assertNothingSent();
    }

    public function test_web_checkout_rejects_gateway_methods(): void
    {
        $cashier = $this->createCashier();
        $this->openShiftFor($cashier);
        $product = $this->createProduct();
        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        Http::fake();
        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'discount' => 0,
                'grand_total' => $product->sell_price,
                'cash' => 0,
                'change' => 0,
                'payment_gateway' => 'midtrans',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    public function test_status_endpoint_returns_payment_status(): void
    {
        $cashier = $this->createCashier();
        $transaction = Transaction::create([
            'invoice' => 'INV-QRIS-TEST',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'qris',
            'payment_status' => 'pending',
            'grand_total' => 60000,
            'total' => 60000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
        ]);

        $this->actingAs($cashier)
            ->getJson(route('transactions.status', $transaction->invoice))
            ->assertOk()
            ->assertJson(['payment_status' => 'pending']);

        $transaction->update(['payment_status' => 'paid']);

        $this->actingAs($cashier)
            ->getJson(route('transactions.status', $transaction->invoice))
            ->assertOk()
            ->assertJson(['payment_status' => 'paid']);
    }

    public function test_qris_image_endpoint_renders_png(): void
    {
        $cashier = $this->createCashier();
        $transaction = Transaction::create([
            'invoice' => 'INV-QRIS-PNG',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'qris',
            'payment_status' => 'pending',
            'grand_total' => 60000,
            'total' => 60000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
            'qr_string' => '00020101021226610014ID.CO.QRIS.WWW',
        ]);

        $response = $this->actingAs($cashier)
            ->get(route('transactions.qr', $transaction->invoice));

        $response->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
    }

    public function test_qris_image_endpoint_404_without_qr_string(): void
    {
        $cashier = $this->createCashier();
        $transaction = Transaction::create([
            'invoice' => 'INV-QRIS-NOQR',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'grand_total' => 60000,
            'total' => 60000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
        ]);

        $this->actingAs($cashier)
            ->get(route('transactions.qr', $transaction->invoice))
            ->assertNotFound();
    }

    private function createCashier(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);

        return $user;
    }

    private function openShiftFor(User $cashier): CashierShift
    {
        return CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }

    private function createProduct(): Product
    {
        $category = Category::create([
            'name' => 'Sembako QRIS',
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'title' => 'Produk QRIS',
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => 25,
            'tax_rate' => 0,
        ]);
    }
}
