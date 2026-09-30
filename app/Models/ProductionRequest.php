<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionRequest extends Model
{
    public const STATUSES = ['requested', 'approved', 'rejected', 'purchasing', 'sent_to_warehouse', 'received', 'in_production', 'completed', 'cancelled'];

    protected $fillable = [
        'request_number', 'request_key', 'warehouse_id', 'product_id', 'recipe_version_id',
        'target_output', 'estimated_material_cost', 'status', 'notes', 'requested_by',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'target_output' => 'decimal:4',
            'estimated_material_cost' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionRequestItem::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
