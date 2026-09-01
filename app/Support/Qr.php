<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Log;

/**
 * QR codes for receipts (#57, #154–#160).
 *
 * ================= WHAT THE CODE CARRIES, AND WHY NOT A URL =================
 * The receipt QR encodes the FACTS of the sale — shop, invoice number, date,
 * total, tax — not a link to look them up. Three reasons, in order of how often
 * they bite:
 *
 *   1. A shop's internet is its least reliable component. A QR that resolves to
 *      a URL is a QR that says nothing when the connection is down, which is
 *      exactly when somebody is arguing about a receipt.
 *   2. A receipt outlives a deployment. A link rots; the figures do not.
 *   3. Several tax authorities require the invoice details themselves in the
 *      code, and none of them accept a redirect.
 *
 * ================= SVG, NOT PNG =================
 * A thermal printer at 203 dpi and an A4 laser at 600 dpi are printing the same
 * receipt template. A raster QR sized for one is unscannable on the other; SVG
 * is sharp at both and needs no image extension installed on the server.
 *
 * ⚠️ Rendering NEVER throws. A receipt that failed to print because a QR could
 * not be drawn would be the worst possible trade — the sale has already
 * happened and the customer is waiting.
 */
class Qr
{
    /**
     * An `<svg>` element, ready to drop into a template — or null if it could
     * not be drawn.
     */
    public static function svg(string $content, int $size = 120): ?string
    {
        if (trim($content) === '') {
            return null;
        }

        try {
            $writer = new Writer(new ImageRenderer(
                // Margin 0: the receipt template controls the whitespace around
                // it, and a QR with two sets of quiet zone wastes paper that a
                // 58 mm roll does not have.
                new RendererStyle($size, 0),
                new SvgImageBackEnd,
            ));

            return $writer->writeString($content);
        } catch (\Throwable $e) {
            Log::warning('Could not render a QR code.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The lines a receipt QR carries.
     *
     * Deliberately plain `Label: value` text rather than JSON: a phone camera
     * shows the decoded string straight to the person holding it, and a wall of
     * braces helps nobody standing at a counter.
     *
     * @param  array<string, string|null>  $fields
     */
    public static function receiptContent(array $fields): string
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            if (blank($value)) {
                continue;
            }

            $lines[] = $label.': '.$value;
        }

        return implode("\n", $lines);
    }
}
