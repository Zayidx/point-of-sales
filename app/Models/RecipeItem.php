<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    protected $fillable = ['recipe_version_id', 'ingredient_id', 'quantity', 'unit_id', 'base_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'base_quantity' => 'decimal:4'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
