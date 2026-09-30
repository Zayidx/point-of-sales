<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseCashLedger extends Model
{
    protected $table = 'warehouse_cash_ledger';

    protected $fillable = [
        'warehouse_id', 'reference_number', 'idempotency_key', 'movement_type', 'direction', 'amount',
        'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'reference_id' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
