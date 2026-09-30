<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OutletAccessService
{
    public function canUseWarehouse(User $user, ?Warehouse $warehouse): bool
    {
        if (! $warehouse) {
            // Legacy installs allowed shifts without a warehouse assignment.
            return $this->legacySingleOutletBypass();
        }

        if (! $warehouse->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $outletIds = $user->outlets()->pluck('outlets.id');

        // Backward compatibility: old single-outlet installs have no assignments yet.
        if ($outletIds->isEmpty()) {
            return $this->legacySingleOutletBypass();
        }

        return (bool) ($warehouse->outlet_id && $outletIds->contains($warehouse->outlet_id))
            || $warehouse->outlets()->whereIn('outlets.id', $outletIds)->exists();
    }

    public function canSellAtWarehouse(User $user, ?Warehouse $warehouse): bool
    {
        if (! $warehouse) {
            return $this->legacySingleOutletBypass();
        }

        if (! $this->canUseWarehouse($user, $warehouse)) {
            return false;
        }

        if (! $warehouse->outlet_id && $warehouse->outlets()->doesntExist()) {
            return $this->legacySingleOutletBypass();
        }

        if ($warehouse?->outlet?->is_sales_enabled) {
            return true;
        }

        return $warehouse?->outlets()
            ->whereIn('outlets.id', $user->outlets()->pluck('outlets.id'))
            ->where('outlets.is_sales_enabled', true)
            ->exists() ?? false;
    }

    public function salesWarehousesFor(User $user): Collection
    {
        return $this->warehousesFor($user)
            ->filter(fn (Warehouse $warehouse) => (bool) $warehouse->outlet?->is_sales_enabled
                || $warehouse->outlets->contains(fn (Outlet $outlet) => $outlet->is_sales_enabled))
            ->values();
    }

    public function warehousesFor(User $user): Collection
    {
        $query = Warehouse::query()->active()->orderBy('sort_order')->orderBy('code');
        if (! $user->isSuperAdmin()) {
            $outletIds = $user->outlets()->pluck('outlets.id');
            if ($outletIds->isNotEmpty()) {
                $query->where(function ($warehouseQuery) use ($outletIds) {
                    $warehouseQuery->whereIn('outlet_id', $outletIds)
                        ->orWhereHas('outlets', fn ($outletQuery) => $outletQuery->whereIn('outlets.id', $outletIds));
                });
            }
        }

        return $query->with(['outlet:id,name,is_sales_enabled', 'outlets:id,code,name,is_sales_enabled'])
            ->get(['id', 'code', 'name', 'outlet_id', 'is_active']);
    }

    public function defaultOutlet(User $user): ?Outlet
    {
        if ($user->isSuperAdmin()) {
            return Outlet::active()->orderBy('code')->first();
        }

        return $user->outlets()
            ->where('outlets.is_active', true)
            ->orderByDesc('user_outlets.is_default')
            ->orderBy('outlets.code')
            ->first();
    }

    public function accessibleOutlets(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return Outlet::active()->orderBy('code')->get();
        }

        return $user->outlets()
            ->where('outlets.is_active', true)
            ->orderByDesc('user_outlets.is_default')
            ->orderBy('outlets.code')
            ->get();
    }

    public function activeOutlet(Request $request): ?Outlet
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $shiftOutlet = CashierShift::query()
            ->with(['warehouse.outlet', 'outlet'])
            ->open()
            ->where('user_id', $user->id)
            ->latest('opened_at')
            ->first()?->outlet;

        if ($shiftOutlet) {
            return $shiftOutlet;
        }

        $outlets = $this->accessibleOutlets($user);
        $selectedId = (int) $request->session()->get('active_outlet_id');

        return $outlets->firstWhere('id', $selectedId) ?? $outlets->first() ?? $this->defaultOutlet($user);
    }

    public function hasActiveShiftInOtherOutlet(User $user, Outlet $outlet): bool
    {
        return (bool) CashierShift::query()
            ->where('user_id', $user->id)
            ->open()
            ->where(function ($query) use ($outlet) {
                $query->where('outlet_id', '!=', $outlet->id)
                    ->orWhere(function ($legacy) use ($outlet) {
                        $legacy->whereNull('outlet_id')
                            ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('outlet_id', '!=', $outlet->id));
                    });
            })
            ->exists();
    }

    public function hasWarehouseHistory(Warehouse $warehouse): bool
    {
        $warehouseId = $warehouse->id;

        foreach ([
            'transactions',
            'carts',
            'stock_mutations',
            'cashier_shifts',
            'purchase_orders',
            'goods_receivings',
            'supplier_returns',
            'stock_opnames',
            'product_batches',
            'inventory_balances',
            'inventory_ledgers',
        ] as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'warehouse_id')
                && DB::table($table)->where('warehouse_id', $warehouseId)->exists()) {
                return true;
            }
        }

        return Schema::hasTable('stock_transfers')
            && (DB::table('stock_transfers')->where('source_warehouse_id', $warehouseId)->exists()
                || DB::table('stock_transfers')->where('destination_warehouse_id', $warehouseId)->exists());
    }

    public function hasOpenShift(Warehouse $warehouse): bool
    {
        return Schema::hasTable('cashier_shifts')
            && DB::table('cashier_shifts')
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 'open')
                ->exists();
    }

    private function legacySingleOutletBypass(): bool
    {
        return config('security.outlet.legacy_single_outlet_bypass', true)
            && Outlet::active()->count() <= 1;
    }
}
