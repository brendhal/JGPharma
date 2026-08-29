<?php

namespace App\Support;

class Money
{
    /** "₱1,234.50" */
    public static function format(float|int|string|null $amount): string
    {
        return self::symbol().number_format((float) $amount, 2);
    }

    /** "1,234.50" — for receipts where the symbol is printed separately. */
    public static function plain(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2);
    }

    public static function symbol(): string
    {
        return (string) config('pos.currency.symbol', '₱');
    }
}
