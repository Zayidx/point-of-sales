<?php

namespace Tests\Feature\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_suppliers_but_only_finance_can_manage_them(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();

        $this->actingAs($manager)->get(route('suppliers.index'))->assertOk();
        $this->actingAs($manager)->post(route('suppliers.store'), [
            'name' => 'Pemasok Terlarang',
        ])->assertForbidden();

        $this->actingAs($finance)->post(route('suppliers.store'), [
            'name' => 'Pemasok Finance',
        ])->assertRedirect();
        $supplier = Supplier::where('name', 'Pemasok Finance')->firstOrFail();

        $this->actingAs($manager)->put(route('suppliers.update', $supplier), [
            'name' => 'Nama Diubah',
        ])->assertForbidden();
        $this->actingAs($manager)->delete(route('suppliers.destroy', $supplier))->assertForbidden();
    }
}
