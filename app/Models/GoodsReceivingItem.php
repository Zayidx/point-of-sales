<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceivingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receiving_id',
        'purchase_order_item_id',
        'product_id',
        'ingredient_id',
        'qty_received',
        'qty_sent',
        'qty_accepted',
        'notes',
        'qc_status',
        'condition_notes',
        'proof_path',
    ];

    protected $casts = [
        'qty_received' => 'decimal:4',
        'qty_sent' => 'decimal:4',
        'qty_accepted' => 'decimal:4',
    ];

    public function goodsReceiving()
    {
        return $this->belongsTo(GoodsReceiving::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
