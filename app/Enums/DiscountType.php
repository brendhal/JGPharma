<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasLabel
{
    case NONE = 'NONE';
    case SENIOR = 'SENIOR';
    case PWD = 'PWD';

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => 'Regular',
            self::SENIOR => 'Senior Citizen (20%)',
            self::PWD => 'PWD (20%)',
        };
    }

    /** Statutory percentage this discount grants. */
    public function percentage(): float
    {
        return match ($this) {
            self::NONE => 0.0,
            self::SENIOR => (float) config('pos.statutory_discounts.senior', 20),
            self::PWD => (float) config('pos.statutory_discounts.pwd', 20),
        };
    }

    /**
     * Whether the percentage is fixed by law rather than keyed by the cashier.
     *
     * Senior and PWD both carry their statutory rate; a regular sale takes
     * whatever discount the cashier types, if any.
     */
    public function isStatutory(): bool
    {
        return $this !== self::NONE;
    }
}
