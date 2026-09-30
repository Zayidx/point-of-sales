<?php

namespace App\Http\Controllers;

use App\Models\OperationalNotification;
use App\Models\Product;
use App\Models\ProductNotificationRead;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Mark a single low-stock notification as read for the current user.
     */
    public function markLowStockRead(Request $request)
    {
        $request->validate([
            'product_id' => ['required', 'exists:products,id'],
        ]);

        ProductNotificationRead::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'product_id' => $request->product_id,
            ],
            []
        );

        return back()->with('status', 'notification-read');
    }

    /**
     * Mark all low-stock notifications as read for the current user.
     */
    public function markAllLowStockRead(Request $request)
    {
        $productIds = Product::where('stock', '<=', 0)->pluck('id')->all();

        if (count($productIds) === 0) {
            return back();
        }

        $payload = collect($productIds)->map(function ($productId) use ($request) {
            return [
                'user_id' => $request->user()->id,
                'product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        });

        ProductNotificationRead::upsert(
            $payload->toArray(),
            ['user_id', 'product_id'],
            ['updated_at']
        );

        return back()->with('status', 'notification-read-all');
    }

    public function markOperationalRead(Request $request, OperationalNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => now()]);

        return back()->with('status', 'notification-read');
    }

    public function markAllOperationalRead(Request $request)
    {
        OperationalNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $productIds = Product::where('stock', '<=', 0)->pluck('id')->all();
        if ($productIds !== []) {
            ProductNotificationRead::upsert(
                collect($productIds)->map(fn ($productId) => [
                    'user_id' => $request->user()->id,
                    'product_id' => $productId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
                ['user_id', 'product_id'],
                ['updated_at'],
            );
        }

        return back()->with('status', 'notification-read-all');
    }
}
