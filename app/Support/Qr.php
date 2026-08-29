<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders the QR codes printed on shelf stickers.
 *
 * SVG on purpose: it stays crisp on a cheap thermal or laser printer and
 * needs no image extension installed on the server.
 */
class Qr
{
    public static function svg(string $data, int $size = 160, int $margin = 0): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle($size, $margin),
                new SvgImageBackEnd(),
            )
        );

        return $writer->writeString($data);
    }
}
