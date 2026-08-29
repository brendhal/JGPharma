<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Contracts\View\View;

class ReceiptController extends Controller
{
    /** Renders the 80mm thermal receipt for a finished sale. */
    public function __invoke(Sale $sale): View
    {
        abort_unless(
            auth()->user()->seesAllBranches() || auth()->user()->branch_id === $sale->branch_id,
            403
        );

        $sale->load(['items.product.medicine', 'items.batch', 'cashier', 'branch']);

        return view('reports.receipt', [
            'sale' => $sale,
            'store' => config('pos.store'),
        ]);
    }
}
