<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Qr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LabelController extends Controller
{
    /** A printable sheet of QR stickers for one product. */
    public function sheet(Request $request, Product $product): View
    {
        $copies = (int) $request->integer('copies', 12);

        return view('reports.labels', [
            'product' => $product->load('medicine', 'brand'),
            'copies' => max(1, min($copies, 96)),
            // Rendered once and repeated: identical stickers, one render pass.
            'qrSvg' => Qr::svg($product->qr_payload, size: 120),
        ]);
    }

    /** The raw QR image, so labels can be embedded anywhere. */
    public function qr(Product $product): Response
    {
        return response(
            Qr::svg($product->qr_payload, size: 240, margin: 1),
            200,
            ['Content-Type' => 'image/svg+xml'],
        );
    }
}
