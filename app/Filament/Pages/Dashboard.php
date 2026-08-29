<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The management overview.
 *
 * Cashiers have no business here — the tiles and tables on this page report
 * store-wide takings, stock levels and every colleague's transactions. Floor
 * staff are sent to their register instead of being shown a 403.
 */
class Dashboard extends BaseDashboard
{
    public static function canAccess(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    /**
     * Filament aborts with a 403 by default. A cashier reaching `/admin`
     * (a bookmark, or the intended URL captured before login) is bounced to
     * the POS terminal, which is the only place they need to be.
     */
    public function mountCanAuthorizeAccess(): void
    {
        if (static::canAccess()) {
            return;
        }

        $this->redirect(PointOfSale::getUrl());
    }
}
