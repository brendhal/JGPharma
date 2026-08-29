<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'product_batch_id',
        'quantity',
        'unit_label',
        'unit_multiplier',
        'unit_price',
        'cost_price',
        'discount',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_multiplier' => 'integer',
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    /** Pieces this line took off the shelf. */
    public function getPiecesAttribute(): int
    {
        return (int) $this->quantity * max(1, (int) $this->unit_multiplier);
    }

    /** "3 Tab" or "2 Box", for receipts and sale detail. */
    public function getQuantityLabelAttribute(): string
    {
        return trim($this->quantity.' '.($this->unit_label ?: $this->product?->unit ?: ''));
    }

    public function getLineProfitAttribute(): float
    {
        // cost_price is stored per sold unit — per tablet on a loose line, per
        // box on a whole-pack one — so this holds for both.
        return (float) $this->subtotal - ((float) $this->cost_price * $this->quantity);
    }
}
