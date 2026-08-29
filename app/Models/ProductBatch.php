<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBatch extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'product_id',
        'batch_number',
        'expiration_date',
        'quantity',
        'cost_price',
        'received_date',
        'supplier_id',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'received_date' => 'date',
            'quantity' => 'integer',
            'cost_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /* -------------------------------------------------- Scopes */

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeExpiringWithin(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('pos.expiry_alert_days', 30);

        return $query->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '>=', today())
            ->whereDate('expiration_date', '<=', today()->addDays($days));
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '<', today());
    }

    /** First-expiring first-out: what the POS should sell next. */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('expiration_date is null')
            ->orderBy('expiration_date')
            ->orderBy('id');
    }

    /* -------------------------------------------------- Helpers */

    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->expiration_date
            ? (int) today()->diffInDays($this->expiration_date, false)
            : null;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->days_to_expiry !== null && $this->days_to_expiry < 0;
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        $days = $this->days_to_expiry;

        return $days !== null
            && $days >= 0
            && $days <= (int) config('pos.expiry_alert_days', 30);
    }

    /**
     * What the stock still sitting in this batch cost.
     *
     * `quantity` is a piece count and `cost_price` is what a whole pack cost,
     * so the two cannot simply be multiplied.
     */
    public function getStockValueAttribute(): float
    {
        $perPiece = $this->product
            ? $this->product->costPerPiece((float) $this->cost_price)
            : (float) $this->cost_price;

        return round((float) $this->quantity * $perPiece, 2);
    }
}
