<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Sale extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'invoice_number',
        'sale_date',
        'subtotal',
        'discount',
        'total_amount',
        'amount_paid',
        'change_amount',
        'payment_method',
        'payment_reference',
        'status',
        'customer_name',
        'discount_type',
        'discount_id_number',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => SaleStatus::class,
            'discount_type' => DiscountType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sale) {
            $sale->invoice_number ??= self::nextInvoiceNumber();
            $sale->user_id ??= auth()->id();
            $sale->sale_date ??= now();
        });
    }

    /* -------------------------------------------------- Relationships */

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* -------------------------------------------------- Scopes */

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::COMPLETED);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('sale_date', today());
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('sale_date', [$from, $to]);
    }

    /* -------------------------------------------------- Helpers */

    public static function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';

        $last = static::withoutGlobalScopes()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function getItemCountAttribute(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    /** Gross profit on this ticket, using cost snapshotted at sale time. */
    public function getGrossProfitAttribute(): float
    {
        return (float) $this->items->sum(
            fn (SaleItem $item) => $item->subtotal - ($item->cost_price * $item->quantity)
        );
    }

    public function isVoidable(): bool
    {
        return $this->status === SaleStatus::COMPLETED;
    }
}
