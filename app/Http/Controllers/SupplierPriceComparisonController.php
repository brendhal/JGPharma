<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\SupplierPriceComparison;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The sheet you put beside you when placing the month's orders.
 *
 * One line per product, one column per supplier, the cheapest price marked —
 * so an order can be split across suppliers on price rather than habit. The
 * second sheet turns the same figures round into an order guide: what to buy
 * from whom.
 *
 * Management only. Cost prices are what this whole report is made of.
 */
class SupplierPriceComparisonController extends Controller
{
    public function __invoke(Request $request, SupplierPriceComparison $comparison): View
    {
        $user = $request->user();

        abort_unless($user?->isManagement() ?? false, 403);

        $categoryId = $request->integer('category');
        $category = $categoryId > 0 ? Category::find($categoryId) : null;

        // The printable defaults to comparable lines only — a product with a
        // single supplier has nothing to compare and only lengthens the sheet.
        $multiOnly = ! $request->boolean('all');

        $data = $comparison->build([
            'months' => $request->input('months'),
            'category' => $category?->id,
            'search' => $request->string('search')->toString(),
            'multi_only' => $multiOnly,
        ]);

        return view('reports.supplier-price-comparison', [
            ...$data,
            'window' => $comparison->windowLabel($data['months']),
            'category' => $category,
            'search' => $request->string('search')->toString(),
            'multiOnly' => $multiOnly,
            'user' => $user,
            'store' => config('pos.store'),
        ]);
    }
}
