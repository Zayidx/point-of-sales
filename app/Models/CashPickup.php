<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashPickup extends Model
{
    protected $fillable = [
        'pickup_number', 'request_key', 'warehouse_id', 'amount', 'status', 'notes', 'proof_path', 'proof_hash', 'requested_by', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
