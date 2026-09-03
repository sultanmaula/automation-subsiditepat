<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCode
{
    /**
     * QR sebagai SVG inline.
     *
     * Sengaja SVG dan dibuat sendiri, bukan menumpang layanan gambar pihak
     * ketiga: nota harus tetap benar walau dicetak ulang berbulan-bulan lagi,
     * dan vektor tetap tajam di printer thermal beresolusi rendah.
     */
    public static function svg(string $text, int $size = 120): string
    {
        $writer = new Writer(
            new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()),
        );

        // Buang deklarasi XML di depan supaya SVG bisa ditanam langsung di HTML.
        return preg_replace('/<\?xml[^>]*\?>\s*/', '', $writer->writeString($text));
    }
}
