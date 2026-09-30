<?php

namespace App\Console\Commands;

use App\Models\CashierShift;
use App\Models\Outlet;
use App\Services\OperationalNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NotifyUnclosedOutletsCommand extends Command
{
    protected $signature = 'outlets:notify-unclosed';

    protected $description = 'Kirim pengingat jika shift cabang masih terbuka setelah jam operasional.';

    public function handle(OperationalNotificationService $notifications): int
    {
        $now = now();
        $outlets = Outlet::query()
            ->where('is_active', true)
            ->where('is_sales_enabled', true)
            ->whereNotNull('opening_hours')
            ->with([
                'warehouses' => fn ($query) => $query->where('is_active', true),
                'sharedWarehouses' => fn ($query) => $query->where('is_active', true),
            ])
            ->get();
        $sent = 0;

        foreach ($outlets as $outlet) {
            foreach ($this->businessDates($now) as $businessDate) {
                $slot = collect($outlet->opening_hours)->first(fn (array $hours) => $this->appliesOn($hours['days'] ?? '', $businessDate));
                $closeAt = $slot ? $this->closingAt($slot['time'] ?? '', $businessDate) : null;
                $graceMinutes = max(0, (int) config('operations.outlet_close_grace_minutes', 15));
                $notificationWindowMinutes = max($graceMinutes, (int) config('operations.outlet_close_notification_window_minutes', 120));
                if (! $closeAt
                    || $now->lt($closeAt->copy()->addMinutes($graceMinutes))
                    || $now->gt($closeAt->copy()->addMinutes($notificationWindowMinutes))) {
                    continue;
                }

                foreach ($outlet->warehouses->merge($outlet->sharedWarehouses)->unique('id') as $warehouse) {
                    $hasOpenShift = CashierShift::query()
                        ->where('warehouse_id', $warehouse->id)
                        ->where(function ($query) use ($outlet) {
                            $query->where('outlet_id', $outlet->id)
                                ->orWhere(function ($legacy) use ($outlet) {
                                    $legacy->whereNull('outlet_id')->whereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('outlet_id', $outlet->id));
                                });
                        })
                        ->where('status', CashierShift::STATUS_OPEN)
                        ->exists();
                    if (! $hasOpenShift) {
                        continue;
                    }

                    $notifications->notifyWarehouseUsers(
                        event: 'outlet.not_closed',
                        idempotencyKey: 'outlet-not-closed:'.$outlet->id.':'.$warehouse->id.':'.$businessDate->toDateString(),
                        title: 'Cabang belum ditutup',
                        body: 'Masih ada shift aktif di '.$outlet->name.' setelah jam operasional.',
                        url: '/cashier-shifts?status=open',
                        warehouseId: (int) $warehouse->id,
                        permissions: ['cashier-shifts-access', 'reports-access'],
                    );
                    $sent++;
                }
            }
        }

        $this->components->info("Pemeriksaan selesai. {$sent} pengingat dipastikan terkirim atau sudah tercatat.");

        return self::SUCCESS;
    }

    /** @return array<int, Carbon> */
    private function businessDates(Carbon $now): array
    {
        return [$now->copy()->startOfDay(), $now->copy()->subDay()->startOfDay()];
    }

    private function appliesOn(string $days, Carbon $date): bool
    {
        $normalized = mb_strtolower(trim(str_replace(['–', '—'], '-', $days)));
        if (str_contains($normalized, 'setiap hari')) {
            return true;
        }
        if (str_contains($normalized, 'senin') && str_contains($normalized, 'sabtu')) {
            return $date->dayOfWeekIso <= 6;
        }

        $dayNumbers = [
            'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4,
            'jumat' => 5, 'jum\'at' => 5, 'sabtu' => 6, 'minggu' => 7,
        ];

        foreach ($dayNumbers as $name => $number) {
            if (str_contains($normalized, $name) && $date->dayOfWeekIso === $number) {
                return true;
            }
        }

        return false;
    }

    private function closingAt(string $hours, Carbon $businessDate): ?Carbon
    {
        preg_match_all('/(\d{1,2})[.:](\d{2})/', $hours, $matches, PREG_SET_ORDER);
        if (count($matches) < 2) {
            return null;
        }

        $openMinutes = ((int) $matches[0][1] * 60) + (int) $matches[0][2];
        $closeMinutes = ((int) $matches[1][1] * 60) + (int) $matches[1][2];
        if ($openMinutes > 1439 || $closeMinutes > 1439) {
            return null;
        }

        $closeAt = $businessDate->copy()->setTime(intdiv($closeMinutes, 60), $closeMinutes % 60);
        if ($closeMinutes <= $openMinutes) {
            $closeAt->addDay();
        }

        return $closeAt;
    }
}
