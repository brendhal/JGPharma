<?php

namespace App\Models;

use App\Support\SellingOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'brand_id',
        'sku',
        'barcode',
        'name',
        'unit',
        'piece_unit',
        'pieces_per_box',
        'cost_price',
        'selling_price',
        'minimum_stock',
        'is_batch_tracked',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'minimum_stock' => 'integer',
            'pieces_per_box' => 'integer',
            'is_batch_tracked' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* -------------------------------------------------- Relationships */

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function medicine(): HasOne
    {
        return $this->hasOne(Medicine::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /** In-between selling sizes: a blister of 10, a pack of 6. Largest first. */
    public function packs(): HasMany
    {
        return $this->hasMany(ProductPack::class)->orderByDesc('pieces');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /* -------------------------------------------------- Scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMedicines(Builder $query): Builder
    {
        return $query->whereHas('medicine');
    }

    /** Matches a scanned code exactly, or a typed fragment of name/generic. */
    public function scopeSearchTerm(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('barcode', $term)
                ->orWhere('sku', $term)
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhereHas('medicine', fn (Builder $m) => $m->where('generic_name', 'like', "%{$term}%"));
        });
    }

    public function scopeLowStockIn(Builder $query, int $branchId): Builder
    {
        return $query->active()->whereHas('inventories', function (Builder $q) use ($branchId) {
            $q->where('branch_id', $branchId)
                ->whereRaw('branch_inventories.quantity <= coalesce(branch_inventories.minimum_stock, products.minimum_stock)');
        });
    }

    /* ---------------------------------------------- Units and packs */

    /** True when the pack can be broken open and sold loose. */
    public function isDivisible(): bool
    {
        return $this->pieces_per_box > 1;
    }

    /** True when there is a tier between the whole unit and a single piece. */
    public function hasPackTiers(): bool
    {
        return count($this->sellingOptions()) > 2;
    }

    /**
     * Every way this product may be rung up, largest first.
     *
     * Always the whole unit, because everything has one. Then whatever pack
     * tiers the product defines, biggest first — a case of diapers may offer
     * both a twelve and a six. Then the loose piece, but only when the pack
     * actually opens; nobody sells a third of a thermometer.
     *
     * @return array<string, SellingOption>
     */
    public function sellingOptions(): array
    {
        $whole = new SellingOption(
            key: SellingOption::BOX,
            label: $this->unit ?: 'Piece',
            pieces: max(1, (int) $this->pieces_per_box),
        );

        $options = [$whole->key => $whole];

        if (! $this->isDivisible()) {
            return $options;
        }

        foreach ($this->packs as $pack) {
            // A tier as big as the whole unit is the whole unit again, and a
            // tier of one is the loose piece. Both are already on the list.
            if ($pack->pieces >= $whole->pieces || $pack->pieces <= 1) {
                continue;
            }

            $option = $pack->toSellingOption();
            $options[$option->key] = $option;
        }

        uasort($options, fn (SellingOption $a, SellingOption $b): int => $b->pieces <=> $a->pieces);

        $piece = new SellingOption(
            key: SellingOption::PIECE,
            label: $this->piece_unit ?: 'Piece',
            pieces: 1,
        );

        return [...$options, $piece->key => $piece];
    }

    /**
     * Unit key => label, ready for a select. Each label carries its size so
     * the cashier can see "Box (20 Tab)" against "Tab" without doing sums.
     *
     * @return array<string, string>
     */
    public function sellingUnitOptions(): array
    {
        return array_map(
            fn (SellingOption $option): string => $option->describe($this->piece_unit),
            $this->sellingOptions(),
        );
    }

    /**
     * The option a key names, falling back to the whole unit.
     *
     * Keys go stale — a pack tier deleted while a cart line still points at
     * it — so an unknown key quietly sells the whole pack rather than
     * throwing at the till with a customer waiting.
     */
    public function sellingOption(?string $key): SellingOption
    {
        $options = $this->sellingOptions();

        return $options[$key] ?? $options[$this->defaultSellingUnit()];
    }

    /**
     * What one piece costs, from a per-pack cost.
     *
     * Cost is captured against the pack a delivery arrives in, while stock is
     * counted in pieces. Every place that multiplies a piece count by money
     * has to come through here, or a box of 500 tablets is valued five
     * hundred times over.
     */
    public function costPerPiece(?float $packCost = null): float
    {
        $packCost ??= (float) $this->cost_price;

        return $packCost / max(1, (int) $this->pieces_per_box);
    }

    /** Pieces that come off the shelf for one of the given unit. */
    public function piecesPer(?string $key): int
    {
        return $this->sellingOption($key)->pieces;
    }

    /**
     * Price of one of the given unit.
     *
     * Derived from the pack price by division, rounded UP to the centavo so a
     * broken box never sells for less than the box it came from.
     */
    public function priceFor(?string $key): float
    {
        $option = $this->sellingOption($key);

        if ($option->isWholeUnit()) {
            return (float) $this->selling_price;
        }

        $perPiece = (float) $this->selling_price / max(1, (int) $this->pieces_per_box);

        return ceil($perPiece * $option->pieces * 100) / 100;
    }

    /** The unit a fresh cart line should start on. */
    public function defaultSellingUnit(): string
    {
        return SellingOption::BOX;
    }

    /**
     * On-hand pieces read back the way a pharmacist counts them:
     * "4 Box + 49 Blister", "4 Box + 7 Tab", or "12 Piece" when nothing
     * breaks down.
     *
     * Works down the same ladder the till offers, taking as many whole ones
     * of each tier as the count allows. Tiers that come to nothing are left
     * out, so a clean five boxes reads "5 Box" and not "5 Box + 0 Blister
     * + 0 Tab".
     */
    public function formatPieces(int $pieces): string
    {
        $options = $this->sellingOptions();
        $remaining = max(0, $pieces);
        $parts = [];

        foreach ($options as $option) {
            $count = intdiv($remaining, $option->pieces);

            if ($count < 1) {
                continue;
            }

            $parts[] = $count.' '.$option->label;
            $remaining -= $count * $option->pieces;
        }

        if ($parts !== []) {
            return implode(' + ', $parts);
        }

        // Nothing on hand. Say so in the smallest unit it is sold in, since
        // "0 Box" hides that loose tablets are gone too.
        $smallest = $options[array_key_last($options)] ?? null;

        return '0 '.($smallest?->label ?: ($this->unit ?: 'Piece'));
    }

    /* -------------------------------------------------- Helpers */

    public function stockIn(?int $branchId = null): int
    {
        $branchId ??= Auth::user()?->branch_id;

        if (! $branchId) {
            return (int) $this->inventories()->sum('quantity');
        }

        return (int) ($this->inventories()->where('branch_id', $branchId)->value('quantity') ?? 0);
    }

    public function reorderLevelIn(?int $branchId = null): int
    {
        $branchId ??= Auth::user()?->branch_id;

        $override = $branchId
            ? $this->inventories()->where('branch_id', $branchId)->value('minimum_stock')
            : null;

        return (int) ($override ?? $this->minimum_stock);
    }

    public function isLowStockIn(?int $branchId = null): bool
    {
        return $this->stockIn($branchId) <= $this->reorderLevelIn($branchId);
    }

    /** "Amoxicillin 500mg (Amoxil) — Box (20 Tab)" */
    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->name,
            $this->brand?->name ? "({$this->brand->name})" : null,
        ]);

        return implode(' ', $parts);
    }

    /** What gets encoded into the printed QR sticker. */
    public function getQrPayloadAttribute(): string
    {
        return $this->barcode ?: $this->sku;
    }

    public function getIsMedicineAttribute(): bool
    {
        return $this->relationLoaded('medicine')
            ? $this->medicine !== null
            : $this->medicine()->exists();
    }
}
