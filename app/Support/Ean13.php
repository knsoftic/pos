<?php

namespace App\Support;

/**
 * Draws an EAN-13 barcode as SVG (#27).
 *
 * WHY BY HAND, rather than pulling in a barcode package: the encoding is a
 * lookup table and ninety-five bars, it has not changed since 1977, and the
 * output has to be an inline SVG anyway so a label sheet prints crisply at any
 * size. A dependency here would be more code to audit than the thing it does.
 *
 * THE FORMAT, so the next reader does not have to go and find it:
 *
 *   95 modules wide: start guard (101), six left digits at 7 modules each,
 *   centre guard (01010), six right digits, end guard (101).
 *
 *   The FIRST digit is never drawn. It is encoded in the PARITY pattern of the
 *   six left-hand digits — which L/G mix is used — which is how thirteen digits
 *   fit into twelve digits' worth of bars. That is the one genuinely surprising
 *   thing about EAN-13, and the reason a naive renderer produces a barcode that
 *   scans as the wrong number.
 *
 *   The guard bars run longer than the data bars so a scanner can find the ends
 *   at any angle.
 */
final class Ean13
{
    /** Odd parity — the default left-hand set. */
    private const L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /** Even parity — the left-hand set that carries the first digit. */
    private const G = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /** Right-hand set. Always the same, always even-numbered bars. */
    private const R = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    /** Which of the six left digits use the G set, per first digit. */
    private const PARITY = [
        'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
        'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
    ];

    /** Is this string a well-formed EAN-13, check digit and all? */
    public static function isValid(?string $code): bool
    {
        if ($code === null || ! preg_match('/^\d{13}$/', $code)) {
            return false;
        }

        return (int) $code[12] === self::checkDigit(substr($code, 0, 12));
    }

    /**
     * The 13th digit: weight the first twelve 1,3,1,3… and take what is needed
     * to reach the next multiple of ten.
     */
    public static function checkDigit(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * The 95-module bit pattern, as a string of 0s and 1s.
     *
     * @return string 95 characters, or '' when the code is not a valid EAN-13.
     */
    public static function pattern(string $code): string
    {
        if (! self::isValid($code)) {
            return '';
        }

        $digits = array_map('intval', str_split($code));
        $parity = self::PARITY[$digits[0]];

        $bits = '101';

        // Digits 2–7. The first digit is carried by this parity mix, not drawn.
        for ($i = 1; $i <= 6; $i++) {
            $bits .= $parity[$i - 1] === 'L'
                ? self::L[$digits[$i]]
                : self::G[$digits[$i]];
        }

        $bits .= '01010';

        // Digits 8–13.
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::R[$digits[$i]];
        }

        return $bits.'101';
    }

    /**
     * Render the barcode as SVG.
     *
     * The viewBox is in MODULES, not pixels, so the caller sizes it with CSS and
     * it stays sharp on a 300dpi label printer and a phone screen alike.
     *
     * @param  int  $height  bar height in modules; 69 is the EAN-13 standard ratio.
     */
    public static function svg(string $code, int $height = 69, bool $withText = true): string
    {
        $bits = self::pattern($code);

        if ($bits === '') {
            return '';
        }

        // Quiet zones matter: a scanner needs blank space either side or it
        // cannot tell where the code begins. 11 modules left, 7 right.
        $quietLeft = 11;
        $quietRight = 7;
        $width = $quietLeft + 95 + $quietRight;
        $textRoom = $withText ? 10 : 0;
        $totalHeight = $height + $textRoom;

        // Guards run 5 modules longer so the ends stay findable.
        $guardExtra = 5;
        $guardPositions = array_merge(
            [0, 1, 2],                  // start
            [45, 46, 47, 48, 49],       // centre
            [92, 93, 94],               // end
        );

        $bars = '';

        for ($i = 0; $i < 95; $i++) {
            if ($bits[$i] !== '1') {
                continue;
            }

            $barHeight = in_array($i, $guardPositions, true) ? $height + $guardExtra - 5 : $height - 5;
            $x = $quietLeft + $i;

            $bars .= sprintf('<rect x="%d" y="0" width="1" height="%d" fill="#000"/>', $x, $barHeight);
        }

        $text = '';

        if ($withText) {
            // The first digit sits outside the bars, in the left quiet zone —
            // which is exactly where it belongs, since it is not drawn as bars.
            $text .= sprintf(
                '<text x="%d" y="%d" font-family="monospace" font-size="9" fill="#000">%s</text>',
                1, $totalHeight - 1, $code[0],
            );
            $text .= sprintf(
                '<text x="%d" y="%d" font-family="monospace" font-size="9" fill="#000" letter-spacing="1.5">%s</text>',
                $quietLeft + 5, $totalHeight - 1, substr($code, 1, 6),
            );
            $text .= sprintf(
                '<text x="%d" y="%d" font-family="monospace" font-size="9" fill="#000" letter-spacing="1.5">%s</text>',
                $quietLeft + 51, $totalHeight - 1, substr($code, 7, 6),
            );
        }

        return sprintf(
            '<svg viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Barcode %s" '
            .'preserveAspectRatio="xMidYMid meet"><rect width="%d" height="%d" fill="#fff"/>%s%s</svg>',
            $width, $totalHeight, $code, $width, $totalHeight, $bars, $text,
        );
    }
}
