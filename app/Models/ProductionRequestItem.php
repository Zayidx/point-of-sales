<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRequestItem extends Model
{
    protected $fillable = [
        'production_request_id', 'ingredient_id', 'unit_id', 'required_quantity',
        'available_quantity', 'shortage_quantity', 'unit_cost_snapshot', 'estimated_cost',
    ];

    protected function casts(): array
    {
        return [
            'required_quantity' => 'decimal:4',
            'available_quantity' => 'decimal:4',
            'shortage_quantity' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
