<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchInventory extends Model
{
    use BelongsToBranch, HasFactory;

    protected $table = 'branch_inventories';

    protected $fillable = [
        'branch_id',
        'product_id',
        'quantity',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'minimum_stock' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /* -------------------------------------------------- Scopes */

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereHas('product', fn (Builder $p) => $p->where('is_active', true))
            ->whereRaw('quantity <= coalesce(minimum_stock, (select minimum_stock from products where products.id = branch_inventories.product_id))')
            ->where('quantity', '>', 0);
    }

    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('quantity', '<=', 0);
    }

    /* -------------------------------------------------- Helpers */

    public function getReorderLevelAttribute(): int
    {
        return (int) ($this->minimum_stock ?? $this->product?->minimum_stock ?? 0);
    }

    /**
     * How much to put on a purchase order for this line.
     *
     * Enough to climb back to twice the reorder level — the usual min/max
     * restocking rule — so the product does not trip the same alert again on
     * the next sale. Never zero: a reorder line always arrives with something
     * sensible on it for the buyer to adjust.
     */
    public function getSuggestedReorderQuantityAttribute(): int
    {
        return max(1, ($this->reorder_level * 2) - $this->quantity);
    }

    /**
     * The same suggestion, in the unit a purchase order is written in.
     *
     * Shelves are counted in pieces but suppliers ship boxes, and a purchase
     * line's quantity is multiplied by the pack size when the delivery is
     * received. Handing the piece count straight to a PO would order a box
     * for every tablet. Rounded up, because half a box cannot be delivered.
     */
    public function getSuggestedReorderUnitsAttribute(): int
    {
        $perUnit = max(1, (int) ($this->product?->pieces_per_box ?? 1));

        return max(1, (int) ceil($this->suggested_reorder_quantity / $perUnit));
    }

    public function getStatusAttribute(): string
    {
        if ($this->quantity <= 0) {
            return 'Out of Stock';
        }

        return $this->quantity <= $this->reorder_level ? 'Low Stock' : 'Healthy';
    }
}
