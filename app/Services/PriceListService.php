<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\PriceList;
use App\Models\Product;

class PriceListService
{
    private array $applicablePriceListCache = [];

    private array $priceByProductCache = [];

    public function getApplicablePriceList(?Customer $customer, ?Outlet $outlet = null): ?PriceList
    {
        $cacheKey = implode(':', [
            $outlet?->id ?? 0,
            $customer?->id ?? 0,
            (int) ($customer?->is_loyalty_member ?? false),
        ]);

        if (array_key_exists($cacheKey, $this->applicablePriceListCache)) {
            return $this->applicablePriceListCache[$cacheKey];
        }

        $lists = PriceList::active()
            ->with('items:id,price_list_id,product_id,price')
            ->where(function ($query) use ($outlet) {
                $query->whereNull('outlet_id');
                if ($outlet) {
                    $query->orWhere('outlet_id', $outlet->id);
                }
            })
            ->orderByDesc('outlet_id')->orderByDesc('priority')->get();

        foreach ($lists as $list) {
            if ($list->customer_scope === 'all') {
                return $this->applicablePriceListCache[$cacheKey] = $list;
            }
            if ($list->customer_scope === 'walk_in') {
                return $this->applicablePriceListCache[$cacheKey] = $list;
            }
            if ($list->customer_scope === 'registered' && $customer) {
                return $this->applicablePriceListCache[$cacheKey] = $list;
            }
            if ($list->customer_scope === 'member' && $customer?->is_loyalty_member) {
                return $this->applicablePriceListCache[$cacheKey] = $list;
            }
            if ($list->customer_scope === 'segment' && $customer && $list->customer_segment_id) {
                if ($customer->segments()->where('customer_segment_id', $list->customer_segment_id)->exists()) {
                    return $this->applicablePriceListCache[$cacheKey] = $list;
                }
            }
        }

        return $this->applicablePriceListCache[$cacheKey] = null;
    }

    public function getProductPrice(PriceList $priceList, int $productId): ?int
    {
        if ($priceList->relationLoaded('items')) {
            $this->priceByProductCache[$priceList->id] ??= $priceList->items
                ->keyBy('product_id')
                ->map(fn ($item) => (int) $item->price)
                ->all();

            return $this->priceByProductCache[$priceList->id][$productId] ?? null;
        }

        return $priceList->items()->where('product_id', $productId)->value('price');
    }

    public function getBasePrice(Product $product, ?Customer $customer, ?Outlet $outlet = null): int
    {
        if ($product->is_composite) {
            return (int) $product->components->sum(
                fn ($c) => (int) $c->sell_price * (float) $c->pivot->qty
            );
        }

        $priceList = $this->getApplicablePriceList($customer, $outlet);
        if (! $priceList) {
            return (int) $product->sell_price;
        }

        return (int) ($this->getProductPrice($priceList, $product->id) ?? $product->sell_price);
    }
}
