<?php

namespace Tests\Feature\Setup;

use App\Models\Cart;
use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\IngredientCategory;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\OutletAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeededInstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_data_and_accounts_are_created_by_database_seeder(): void
    {
        $this->seed();

        $this->assertSame('Dimsum Weigu', Setting::get('store_name'));
        $this->assertTrue(Setting::getBool('app_setup_completed'));
        $this->assertSame(4, Outlet::where('is_sales_enabled', true)->count());
        $this->assertSame(5, Warehouse::active()->count());
        $this->assertSame(5, Warehouse::count());
        $this->assertSame('Gudang Bersama Dimsum Weigu', Warehouse::where('code', 'PUSAT')->value('name'));
        $this->assertSame(4, Category::count());
        $this->assertSame(6, IngredientCategory::count());
        $this->assertSame(6, ExpenseCategory::count());
        $this->assertSame(5, PaymentMethod::count());
        $this->assertSame(10, Unit::count());
        $this->assertSame(4, UnitConversion::count());
        $this->assertNotEmpty(Outlet::where('code', 'PEK-JAYA')->value('map_url'));
        $this->assertSame(2, count(Outlet::where('code', 'PEK-JAYA')->firstOrFail()->opening_hours));

        foreach ([
            'manager@gmail.com' => ['manager', 'manager123'],
            'finance@gmail.com' => ['finance', 'finance123'],
            'cashier@gmail.com' => ['cashier', 'cashier123'],
            'cashier2@gmail.com' => ['cashier', 'cashier123'],
            'cashier3@gmail.com' => ['cashier', 'cashier123'],
            'cashier4@gmail.com' => ['cashier', 'cashier123'],
            'warehouse@gmail.com' => ['warehouse', 'warehouse123'],
        ] as $email => [$role, $password]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertTrue($user->hasRole($role));
            $this->assertTrue($user->hasVerifiedEmail());
        }

        foreach ([
            'cashier@gmail.com' => ['GAL-BP', 'WH-GAL-BP'],
            'cashier2@gmail.com' => ['GAL-HER', 'WH-GAL-HER'],
            'cashier3@gmail.com' => ['PEK-JAYA', 'WH-PEK-JAYA'],
            'cashier4@gmail.com' => ['JL-RAYA-PEK', 'WH-RAYA-PEK'],
        ] as $email => [$outletCode, $warehouseCode]) {
            $cashier = User::where('email', $email)->firstOrFail();
            $this->assertSame([$outletCode], $cashier->outlets()->pluck('code')->all());
            $this->assertSame([$warehouseCode], app(OutletAccessService::class)
                ->salesWarehousesFor($cashier)->pluck('code')->all());
        }
    }

    public function test_seeded_roles_are_scoped_to_their_expected_permissions(): void
    {
        $this->seed();

        $this->assertFalse(Role::findByName('manager')->hasPermissionTo('products-create'));
        $this->assertTrue(Role::findByName('finance')->hasPermissionTo('payables-pay'));
        $this->assertTrue(Role::findByName('warehouse')->hasPermissionTo('stock-transfers-receive'));
        $this->assertTrue(Role::findByName('cashier')->hasPermissionTo('pos-access'));
    }

    public function test_manager_can_log_in_to_the_indonesian_dashboard_with_view_only_access(): void
    {
        $this->seed();

        $this->post('/login', [
            'email' => 'manager@gmail.com',
            'password' => 'manager123',
        ] + $this->botGuardPayload())->assertRedirect('/dashboard');

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('locale.current', 'id')
                ->where('locale.available', ['id'])
                ->where('auth.user.email', 'manager@gmail.com'));

        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->assertTrue($manager->can('dashboard-access'));
        $this->assertTrue($manager->can('reports-access'));
        $this->assertFalse($manager->can('products-edit'));
        $this->assertFalse($manager->can('products-create'));
    }

    public function test_manager_dashboard_permission_does_not_grant_store_configuration_access(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        foreach (['settings.store', 'settings.loyalty', 'settings.target'] as $route) {
            $this->actingAs($manager)->get(route($route))->assertForbidden();
        }
    }

    public function test_setup_wizard_is_removed_and_homepage_is_available_without_setup_flag(): void
    {
        $this->get('/setup')->assertNotFound();
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Welcome'));
    }

    public function test_homepage_reads_outlet_map_and_hours_from_seeded_master_data(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('outlets.2.name', 'Cabang 3 · Pekayon Jaya')
                ->where('outlets.2.map', 'https://maps.app.goo.gl/vBkAyD4WVhMoQUvm6')
                ->where('outlets.2.hours.1.days', 'Minggu')
                ->where('business.name', 'Dimsum Weigu'));
    }

    public function test_database_seeder_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(8, User::count());
        $this->assertSame(4, Outlet::where('is_sales_enabled', true)->count());
        $this->assertSame(5, Warehouse::count());
        $this->assertSame(5, PaymentMethod::count());
    }

    public function test_cashier_stock_moves_from_central_to_outlet_and_back_at_shift_close(): void
    {
        $this->seed();
        $central = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $service = app(CashierShiftService::class);
        $transferService = app(\App\Services\StockTransferService::class);
        $firstCashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $secondCashier = User::where('email', 'cashier2@gmail.com')->firstOrFail();
        $outletWarehouse = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $secondOutletWarehouse = Warehouse::where('code', 'WH-GAL-HER')->firstOrFail();

        $firstShift = $service->openShift($firstCashier, $firstCashier, 100000, warehouseId: $outletWarehouse->id);
        $secondShift = $service->openShift($secondCashier, $secondCashier, 100000, warehouseId: $secondOutletWarehouse->id);

        $this->assertNotSame($firstShift->warehouse_id, $secondShift->warehouse_id);
        $this->assertNotSame($firstShift->outlet_id, $secondShift->outlet_id);

        $product = Product::create([
            'image' => 'shared-stock.png',
            'barcode' => 'SHARED-STOCK-001',
            'sku' => 'SHARED-STOCK-001',
            'title' => 'Produk Gudang Bersama',
            'description' => 'Stok diambil dari pusat ke outlet',
            'category_id' => Category::firstOrFail()->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 7,
            'tax_rate' => 0,
        ]);
        $product->warehouses()->attach($central->id, ['stock' => 7]);
        $unit = Unit::firstOrCreate(['code' => 'TESTPC'], ['name' => 'Pcs Uji', 'symbol' => 'pcs']);
        $product->units()->attach($unit->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000]);
        $transfer = $transferService->createDraft([
            'source_warehouse_id' => $central->id,
            'destination_warehouse_id' => $outletWarehouse->id,
        ], [['product_id' => $product->id, 'qty' => 7]], User::where('email', 'warehouse@gmail.com')->value('id'));
        $transferService->send($transfer, User::where('email', 'warehouse@gmail.com')->value('id'));
        $transferService->receive($transfer, User::where('email', 'warehouse@gmail.com')->value('id'));

        Cart::create([
            'cashier_id' => $firstCashier->id,
            'warehouse_id' => $outletWarehouse->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 10000,
            'conversion_factor' => 1,
        ]);

        $this->actingAs($firstCashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 10000])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, (int) $product->warehouses()->where('warehouse_id', $outletWarehouse->id)->first()->pivot->stock);
        $this->assertSame($firstShift->outlet_id, Transaction::firstOrFail()->outlet_id);
        $service->closeShift($firstShift, $firstCashier, 110000, closingStock: [['product_id' => $product->id, 'actual_stock' => 6]]);
        $this->assertSame(0, (int) $product->warehouses()->where('warehouse_id', $outletWarehouse->id)->first()->pivot->stock);
        $this->assertSame(6, (int) $product->warehouses()->where('warehouse_id', $central->id)->first()->pivot->stock);
        $this->assertSame(5, Warehouse::active()->count());
    }
}
