<?php

namespace App\Models;

use App\Support\SellingOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-between way to sell a product: a blister of ten, a pack of six.
 *
 * Never a stock unit. The shelf is always counted in pieces; a row here only
 * says how many pieces leave that shelf when a customer asks for one of
 * these, which is why a product can have as many as it likes without the
 * inventory maths changing at all.
 */
class ProductPack extends Model
{
    protected $fillable = [
        'product_id',
        'label',
        'pieces',
    ];

    protected function casts(): array
    {
        return [
            'pieces' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function toSellingOption(): SellingOption
    {
        return new SellingOption(
            key: static::keyFor($this->id),
            label: $this->label,
            pieces: max(1, (int) $this->pieces),
        );
    }

    /** The token the till uses to name this tier in a cart line. */
    public static function keyFor(int $id): string
    {
        return 'PACK:'.$id;
    }
}
