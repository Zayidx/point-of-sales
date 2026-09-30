<?php

namespace Tests\Feature\Console;

use App\Models\CashierShift;
use App\Models\OperationalNotification;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class NotifyUnclosedOutletsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_shift_after_closing_time_notifies_scoped_manager_once(): void
    {
        $this->seed();
        $this->travelTo(Carbon::parse('2026-09-30 22:30:00', 'Asia/Jakarta'));
        $outlet = Outlet::where('code', 'GAL-BP')->firstOrFail();
        $outlet->update(['opening_hours' => [['days' => 'Setiap hari', 'time' => '14.00-22.15']]]);
        $warehouse = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        CashierShift::create([
            'user_id' => $cashier->id,
            'warehouse_id' => $warehouse->id,
            'outlet_id' => $outlet->id,
            'opened_by' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => CashierShift::STATUS_OPEN,
        ]);

        Artisan::call('outlets:notify-unclosed');
        Artisan::call('outlets:notify-unclosed');

        $this->assertSame(1, OperationalNotification::query()
            ->where('user_id', $manager->id)
            ->where('event', 'outlet.not_closed')
            ->count());
        $this->assertDatabaseHas('operational_notifications', [
            'user_id' => $manager->id,
            'warehouse_id' => $warehouse->id,
            'event' => 'outlet.not_closed',
            'idempotency_key' => 'outlet-not-closed:'.$outlet->id.':'.$warehouse->id.':2026-09-30:user:'.$manager->id,
        ]);
    }

    public function test_shift_is_not_flagged_before_closing_grace_period(): void
    {
        $this->seed();
        $this->travelTo(Carbon::parse('2026-09-30 22:20:00', 'Asia/Jakarta'));
        $outlet = Outlet::where('code', 'GAL-BP')->firstOrFail();
        $outlet->update(['opening_hours' => [['days' => 'Setiap hari', 'time' => '14.00-22.15']]]);
        $warehouse = Warehouse::where('code', 'WH-GAL-BP')->firstOrFail();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        CashierShift::create([
            'user_id' => $cashier->id,
            'warehouse_id' => $warehouse->id,
            'outlet_id' => $outlet->id,
            'opened_by' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => CashierShift::STATUS_OPEN,
        ]);

        Artisan::call('outlets:notify-unclosed');

        $this->assertSame(0, OperationalNotification::where('event', 'outlet.not_closed')->count());
    }
}
