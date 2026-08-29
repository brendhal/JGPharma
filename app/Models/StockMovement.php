<?php

namespace App\Models;

use App\Enums\MovementType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBranch, HasFactory;

    /** Append-only: there is no updated_at, movements are never edited. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id',
        'product_id',
        'product_batch_id',
        'user_id',
        'type',
        'quantity',
        'balance_after',
        'reference_type',
        'reference_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Write-offs only: expired and damaged stock. */
    public function scopeDisposals(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('type', MovementType::disposalCases());
    }

    /** Pieces written off, as a positive number. */
    public function getPiecesDisposedAttribute(): int
    {
        return abs((int) $this->quantity);
    }

    /**
     * What the thrown-away stock cost.
     *
     * Priced against the batch it came out of when there is one, since two
     * deliveries of the same product rarely cost the same, and against the
     * product's standing cost when there is not.
     */
    public function getDisposalValueAttribute(): float
    {
        $product = $this->product;

        if (! $product) {
            return 0.0;
        }

        $packCost = $this->batch?->cost_price ?? $product->cost_price;

        return round($this->pieces_disposed * $product->costPerPiece((float) $packCost), 2);
    }

    /** "+500" / "-12" for the movements table. */
    public function getSignedQuantityAttribute(): string
    {
        return ($this->quantity > 0 ? '+' : '').number_format($this->quantity);
    }
}
