<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'batch_number',
        'expiration_date',
        'quantity',
        'cost_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'quantity' => 'integer',
            'cost_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->subtotal = (float) $item->quantity * (float) $item->cost_price;
        });

        static::saved(fn (self $item) => $item->purchase?->recalculateTotal());
        static::deleted(fn (self $item) => $item->purchase?->recalculateTotal());
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
