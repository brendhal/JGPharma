<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The A4 purchase order handed to a supplier.
 *
 * It is a request for goods, not a quotation: what to send and how much of
 * it, with no buying prices on the page. The supplier prices the delivery on
 * their own invoice, and the shop's costs stay the shop's business.
 *
 * Still management only, same as the purchasing screens it is opened from,
 * and the branch scope on Purchase does the rest — a manager who cannot see
 * another store's order gets a 404 from the route binding.
 */
class PurchaseOrderController extends Controller
{
    public function __invoke(Request $request, Purchase $purchase): View
    {
        $user = $request->user();

        abort_unless($user?->isManagement() ?? false, 403);

        $purchase->load([
            'items.product.medicine',
            'supplier',
            'branch',
            'creator',
        ]);

        return view('reports.purchase-order', [
            'purchase' => $purchase,
            'user' => $user,
            'store' => config('pos.store'),
        ]);
    }
}
