<?php

namespace App\Services;

use App\Models\OperationalNotification;
use App\Models\User;
use App\Models\Warehouse;

class OperationalNotificationService
{
    /** @param array<int, string> $permissions */
    public function notifyWarehouseUsers(
        string $event,
        string $idempotencyKey,
        string $title,
        string $body,
        ?string $url,
        ?int $warehouseId,
        array $permissions,
    ): void {
        $warehouse = $warehouseId ? Warehouse::with('outlet:id,is_sales_enabled')->find($warehouseId) : null;
        $scopeToOutlet = $warehouse?->outlet?->is_sales_enabled === true;
        $users = User::query()->where(function ($query) use ($warehouse, $permissions, $scopeToOutlet) {
            $query->whereHas('roles', fn ($roles) => $roles->where('name', 'super-admin'));

            if ($permissions !== []) {
                $query->orWhere(function ($withPermission) use ($warehouse, $permissions, $scopeToOutlet) {
                    $withPermission->whereHas('permissions', fn ($permission) => $permission->whereIn('name', $permissions))
                        ->orWhereHas('roles.permissions', fn ($permission) => $permission->whereIn('name', $permissions));

                    if ($scopeToOutlet && $warehouse?->outlet_id) {
                        $withPermission->whereHas('outlets', fn ($outlets) => $outlets->whereKey($warehouse->outlet_id));
                    }
                });
            }
        })->get(['id']);

        foreach ($users as $user) {
            OperationalNotification::firstOrCreate(
                ['idempotency_key' => $idempotencyKey.':user:'.$user->id],
                [
                    'user_id' => $user->id,
                    'warehouse_id' => $warehouseId,
                    'event' => $event,
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                ],
            );
        }
    }

    public function notifyUser(int $userId, string $event, string $idempotencyKey, string $title, string $body, ?string $url, ?int $warehouseId): void
    {
        OperationalNotification::firstOrCreate(
            ['idempotency_key' => $idempotencyKey.':user:'.$userId],
            [
                'user_id' => $userId,
                'warehouse_id' => $warehouseId,
                'event' => $event,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ],
        );
    }
}
