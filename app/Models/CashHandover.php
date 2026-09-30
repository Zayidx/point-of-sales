<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashHandover extends Model
{
    protected $fillable = [
        'handover_number', 'request_key', 'cashier_shift_id', 'warehouse_id', 'cashier_id',
        'expected_cash', 'cashier_amount', 'received_amount', 'variance', 'status', 'cashier_notes',
        'warehouse_notes', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['expected_cash' => 'integer', 'cashier_amount' => 'integer', 'received_amount' => 'integer', 'variance' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
