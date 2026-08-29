<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Purchase extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'supplier_id',
        'reference_number',
        'purchase_date',
        'total_amount',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'total_amount' => 'decimal:2',
            'status' => PurchaseStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchase) {
            $purchase->reference_number ??= self::nextReferenceNumber();
            $purchase->created_by ??= auth()->id();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextReferenceNumber(): string
    {
        $prefix = 'PO-'.now()->format('Ymd').'-';

        $last = static::withoutGlobalScopes()
            ->where('reference_number', 'like', $prefix.'%')
            ->orderByDesc('reference_number')
            ->value('reference_number');

        $sequence = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function recalculateTotal(): void
    {
        $this->total_amount = $this->items()->sum('subtotal');
        $this->saveQuietly();
    }

    public function isReceived(): bool
    {
        return $this->status === PurchaseStatus::RECEIVED;
    }
}
