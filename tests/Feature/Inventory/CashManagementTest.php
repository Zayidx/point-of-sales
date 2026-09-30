<?php

namespace Tests\Feature\Inventory;

use App\Models\CashHandover;
use App\Models\CashPickup;
use App\Models\OperationalNotification;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseCashLedger;
use App\Services\CashierShiftService;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CashManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_handover_and_pickup_are_recorded_once_and_never_overdraw_warehouse(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $shiftService = app(CashierShiftService::class);
        $shift = $shiftService->openShift($cashier, $cashier, 50000, null, $warehouse->id);
        $shift = $shiftService->closeShift($shift, $cashier, 100000);
        $varianceNotification = OperationalNotification::where('event', 'cash.variance')
            ->where('user_id', $manager->id)
            ->first();
        $this->assertNotNull($varianceNotification);
        $this->assertSame($manager->id, $varianceNotification->user_id);
        $service = app(CashManagementService::class);

        $handover = $service->submitHandover('handover-request-1', $shift, $cashier);
        $sameHandover = $service->submitHandover('handover-request-1', $shift, $cashier);
        $this->assertSame($handover->id, $sameHandover->id);
        $service->confirmHandover($handover, $warehouseUser, 95000);
        $this->assertSame(45000, $handover->fresh()->variance);
        $this->assertDatabaseHas('operational_notifications', [
            'user_id' => $cashier->id,
            'event' => 'cash_handover.received',
            'idempotency_key' => 'cash-handover:'.$handover->id.':received:user:'.$cashier->id,
        ]);
        $this->assertSame(95000, $service->balanceFor($warehouse->id));

        $pickup = $service->requestPickup('pickup-request-1', $warehouse, 80000, $finance);
        $samePickup = $service->requestPickup('pickup-request-1', $warehouse, 80000, $finance);
        $this->assertSame($pickup->id, $samePickup->id);
        $service->confirmPickup($pickup, $warehouseUser, 'cash-pickups/bukti.pdf', str_repeat('a', 64), 'Diterima akuntan');
        $this->assertSame(15000, $service->balanceFor($warehouse->id));
        $this->assertSame('cash-pickups/bukti.pdf', $pickup->fresh()->proof_path);

        $oversizedPickup = $service->requestPickup('pickup-request-2', $warehouse, 20000, $finance);
        try {
            $service->confirmPickup($oversizedPickup, $warehouseUser, 'cash-pickups/oversized.pdf', str_repeat('b', 64));
            $this->fail('Saldo gudang yang tidak cukup seharusnya menolak pengambilan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(1, CashHandover::count());
        $this->assertSame(2, CashPickup::count());
        $this->assertSame(2, WarehouseCashLedger::count());
        $this->assertSame('requested', $oversizedPickup->fresh()->status);
    }

    public function test_cash_management_page_and_actions_follow_role_permissions(): void
    {
        $this->seed();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->actingAs($manager)
            ->get(route('cash-management.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/CashManagement/Index'));

        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->actingAs($cashier)->get(route('cash-management.index'))->assertOk();
        $warehouse = Warehouse::where('code', 'PUSAT')->firstOrFail();
        $this->actingAs($cashier)->post(route('cash-management.pickups.store'), [
            'request_key' => 'forbidden-pickup', 'warehouse_id' => $warehouse->id, 'amount' => 10000,
        ])->assertForbidden();

        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $this->actingAs($finance)->get(route('cash-management.index'))->assertOk();
        $this->actingAs($finance)->post(route('cash-management.pickups.store'), [
            'request_key' => 'finance-pickup', 'warehouse_id' => $warehouse->id, 'amount' => 10000,
        ])->assertRedirect();
        $pickup = CashPickup::where('request_key', 'finance-pickup')->firstOrFail();
        $this->actingAs($finance)->post(route('cash-management.pickups.confirm', $pickup))->assertForbidden();
        $warehouseUser = User::where('email', 'warehouse@gmail.com')->firstOrFail();
        Storage::fake('local');
        WarehouseCashLedger::create([
            'warehouse_id' => $warehouse->id,
            'reference_number' => 'CASH-OPENING-PROOF-TEST',
            'idempotency_key' => 'cash-opening-proof-test',
            'movement_type' => 'cash_adjustment',
            'direction' => 'in',
            'amount' => 20000,
            'reference_type' => 'test',
            'reference_id' => 1,
            'created_by' => $warehouseUser->id,
        ]);
        $this->actingAs($warehouseUser)
            ->post(route('cash-management.pickups.confirm', $pickup))
            ->assertSessionHasErrors('proof');
        $this->assertSame('requested', $pickup->fresh()->status);

        $proof = UploadedFile::fake()->create('bukti.pdf', 20, 'application/pdf');
        $this->actingAs($warehouseUser)
            ->post(route('cash-management.pickups.confirm', $pickup), [
                'proof' => $proof,
                'notes' => 'Uang diterima sesuai pengajuan.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $pickup->refresh();
        $this->assertSame('confirmed', $pickup->status);
        $this->assertNotEmpty($pickup->proof_path);
        Storage::disk('local')->assertExists($pickup->proof_path);
        $this->actingAs($warehouseUser)
            ->get(route('cash-management.pickups.proof', $pickup))
            ->assertOk();

        $this->actingAs(User::where('email', 'cashier@gmail.com')->firstOrFail())
            ->get(route('cash-management.pickups.proof', $pickup))
            ->assertForbidden();
    }

    public function test_operational_notification_can_only_be_acknowledged_by_its_recipient(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $finance = User::where('email', 'finance@gmail.com')->firstOrFail();
        $notification = OperationalNotification::create([
            'user_id' => $cashier->id,
            'event' => 'cash_handover.received',
            'idempotency_key' => 'test-handover-received',
            'title' => 'Kas diterima',
            'body' => 'Serah terima sudah dikonfirmasi.',
        ]);

        $this->actingAs($finance)
            ->post(route('notifications.operations.read', $notification))
            ->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($cashier)
            ->post(route('notifications.operations.read', $notification))
            ->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
    }
}
