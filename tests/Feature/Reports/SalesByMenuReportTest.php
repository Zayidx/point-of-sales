<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesByMenuReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_sees_pieces_sold_only_for_their_outlet_and_no_financial_amounts(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $branch = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $otherBranch = Warehouse::where('code', 'WH-GAL-HER')->firstOrFail();
        $product = $this->createProduct('MENU-REPORT-1', 'Dimsum isi 6', $cashier, $branch, 2, 6);
        $this->createProduct('MENU-REPORT-2', 'Lumpia', $cashier, $otherBranch, 3, 3);

        $this->actingAs($cashier)
            ->get(route('reports.menu-sales.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Reports/SalesByMenu')
                ->where('showRevenue', false)
                ->has('items', 1)
                ->where('items.0.title', 'Dimsum isi 6')
                ->where('items.0.pcs_sold', 12)
                ->where('items.0.packages_sold', 2)
                ->missing('items.0.net_sales'));

        $this->actingAs($cashier)
            ->get(route('reports.menu-sales.index', ['warehouse_id' => $otherBranch->id]))
            ->assertSessionHasErrors('warehouse_id');
    }

    public function test_finance_sees_menu_sales_across_accessible_outlets_and_net_sales(): void
    {
        $this->seed();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $branch = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $otherBranch = Warehouse::where('code', 'WH-GAL-HER')->firstOrFail();
        $this->createProduct('MENU-FIN-1', 'Dimsum', $cashier, $branch, 2, 6);
        $this->createProduct('MENU-FIN-2', 'Lumpia', $cashier, $otherBranch, 3, 3);

        $this->actingAs($finance)
            ->get(route('reports.menu-sales.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('showRevenue', true)
                ->has('items', 2)
                ->where('items.0.net_sales', 24000)
                ->where('items.1.net_sales', 36000));
    }

    private function createProduct(string $sku, string $title, User $cashier, Warehouse $warehouse, int $qty, int $conversion): Product
    {
        $product = Product::create([
            'image' => 'report.png',
            'barcode' => $sku,
            'sku' => $sku,
            'title' => $title,
            'description' => 'Menu laporan',
            'category_id' => Category::firstOrFail()->id,
            'buy_price' => 1000,
            'sell_price' => 12000,
            'stock' => 0,
            'tax_rate' => 0,
        ]);
        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'warehouse_id' => $warehouse->id,
            'outlet_id' => $warehouse->outlet_id,
            'invoice' => 'TRX-'.$sku,
            'cash' => 40000,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => $qty * 12000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => $qty,
            'conversion_factor' => $conversion,
            'price' => $qty * 12000,
        ]);

        return $product;
    }
}
