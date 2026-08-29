<?php

namespace App\Support;

/**
 * One way a cashier may ring a product up.
 *
 * All three kinds are the same thing underneath — a piece count wearing a
 * name. The whole selling unit is "BOX", a pack tier the product defines is
 * "PACK:7", and a single loose piece is "PIECE". Keeping them behind one
 * shape is what lets a product offer two pack sizes without the till, the
 * cart or the receipt knowing anything special about diapers.
 */
final readonly class SellingOption
{
    public const BOX = 'BOX';

    public const PIECE = 'PIECE';

    public function __construct(
        public string $key,
        public string $label,
        public int $pieces,
    ) {}

    /**
     * "Blister (10 Tab)" — the size is spelled out beside the name so a
     * cashier picking a unit never has to do the sum in their head.
     */
    public function describe(?string $pieceUnit): string
    {
        if ($this->pieces <= 1) {
            return $this->label;
        }

        return $this->label.' ('.$this->pieces.' '.($pieceUnit ?: 'pcs').')';
    }

    public function isWholeUnit(): bool
    {
        return $this->key === self::BOX;
    }
}
