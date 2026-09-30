<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutletStockReturn extends Model
{
    protected $fillable = [
        'return_number', 'request_key', 'source_warehouse_id', 'destination_warehouse_id',
        'requested_by', 'received_by', 'status', 'notes', 'receiving_notes', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OutletStockReturnItem::class);
    }
}
