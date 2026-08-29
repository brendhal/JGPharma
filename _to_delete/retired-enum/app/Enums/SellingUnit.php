<?php

namespace App\Enums;

use App\Models\Product;

/**
 * The three ways a counter sells a pack.
 *
 * A box of Neozep is two blisters of ten. A customer asks for the box, the
 * blister, or three loose tablets, and all three come off the same shelf
 * stock — which is why every quantity in the system is counted in pieces and
 * only converted at the moment of sale.
 */
enum SellingUnit: string
{
    case BOX = 'BOX';
    case PACK = 'PACK';
    case PIECE = 'PIECE';

    /** How many pieces one of these is worth for a given product. */
    public function piecesFor(Product $product): int
    {
        return match ($this) {
            self::BOX => max(1, (int) $product->pieces_per_box),
            self::PACK => max(1, (int) ($product->pieces_per_pack ?: $product->pieces_per_box)),
            self::PIECE => 1,
        };
    }

    /**
     * What the counter calls it. Falls back to the product's own wording so a
     * bottle reads "Bottle" and a tablet reads "Tab" rather than "Box"/"Piece".
     */
    public function labelFor(Product $product): string
    {
        return match ($this) {
            self::BOX => $product->unit ?: 'Box',
            self::PACK => 'Blister',
            self::PIECE => $product->piece_unit ?: 'Piece',
        };
    }
}
