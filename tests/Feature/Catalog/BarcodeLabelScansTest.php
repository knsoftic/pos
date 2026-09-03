<?php

namespace Tests\Feature\Catalog;

use App\Support\Ean13;
use Tests\TestCase;

/**
 * Reads the printed label BACK, the way a scanner would.
 *
 * ================= WHY THIS AND NOT MORE SHAPE CHECKS =================
 * The existing tests prove the pattern is 95 modules with the right guards, and
 * that changing the first digit changes the bars. All true, and none of it
 * answers the only question a shopkeeper cares about:
 *
 *     DOES THE LABEL SCAN AS THE NUMBER PRINTED UNDER IT?
 *
 * A barcode that scans as a DIFFERENT valid product is the worst possible
 * failure here. Nothing errors, the label looks perfect, and the till quietly
 * rings up the wrong item and takes the wrong money off the wrong shelf. It
 * would be found weeks later, in a stock count that does not balance.
 *
 * So this decodes the drawn SVG — not the internal pattern, the actual rects
 * that reach the printer — using its own tables typed out from the spec. Using
 * {@see Ean13}'s constants would only prove the file agrees with itself, which
 * is exactly the thing a wrong lookup table also does.
 */
class BarcodeLabelScansTest extends TestCase
{
    /** Odd parity, left-hand. */
    private const L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /** Even parity, left-hand — this is the set that carries the first digit. */
    private const G = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /** Right-hand, the complement of L. */
    private const R = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    /**
     * Which L/G mix each leading digit uses. 0 = L, 1 = G.
     *
     * This table IS the first digit — it is never drawn as bars — and getting
     * it wrong is the classic way to produce a barcode that scans as another
     * product entirely.
     */
    private const PARITY = [
        '000000', '001011', '001101', '001110', '010011',
        '011001', '011100', '010101', '010110', '011010',
    ];

    public static function codes(): array
    {
        return [
            'a real retail code' => ['5901234123457'],
            'an in-store code, the kind this shop mints' => ['2313573091442'],
            'leading zero' => ['0012345678905'],
            'leading nine' => ['9012345678906'],
            // ⚠️ First digit 0 is the only one with no G digits at all, so a
            // renderer can be wrong everywhere else and still pass on it.
            'all zeros' => ['0000000000000'],
            'nines' => ['9999999999994'],
        ];
    }

    /** @dataProvider codes */
    public function test_the_printed_label_scans_as_the_number_under_it(string $code): void
    {
        $this->assertTrue(Ean13::isValid($code), "The fixture {$code} is not a valid EAN-13.");

        $this->assertSame($code, $this->readBack(Ean13::svg($code)));
    }

    public function test_two_codes_differing_only_in_the_undrawn_first_digit_read_differently(): void
    {
        // Same twelve visible digits, different leading digit. The bars for the
        // last six are IDENTICAL; only the parity of the first six differs. If
        // that is mishandled, both labels scan as the same product.
        $a = '0012345678905';
        $b = '9012345678906';

        $this->assertSame($a, $this->readBack(Ean13::svg($a)));
        $this->assertSame($b, $this->readBack(Ean13::svg($b)));
    }

    public function test_the_quiet_zone_is_really_blank(): void
    {
        // A scanner finds the start guard by the blank run before it. Bars in
        // the quiet zone make a label that reads on the bench and fails on a
        // crowded shelf, which is the hardest kind of fault to be told about.
        preg_match_all('/<rect x="(\d+)"/', Ean13::svg('5901234123457'), $m);

        $xs = array_map('intval', $m[1]);

        $this->assertNotEmpty($xs);
        $this->assertGreaterThanOrEqual(11, min($xs), 'Something is drawn inside the left quiet zone.');
    }

    public function test_the_human_readable_digits_match_the_bars(): void
    {
        $code = '2313573091442';
        $svg = Ean13::svg($code);

        // Printed as three groups: the leading digit outside the bars, then six
        // and six. Somebody reading the label back to a supplier over the phone
        // needs these to be the code, not a decoration.
        preg_match_all('/<text[^>]*>([^<]*)<\/text>/', $svg, $m);

        $this->assertSame($code, implode('', $m[1]));
        $this->assertSame($code, $this->readBack($svg), 'The digits and the bars must be the same code.');
    }

    // ═══════════════════════════════════════════════════ the decoder

    /** Rebuild the 95-module string from the drawn rects, then decode it. */
    protected function readBack(string $svg): string
    {
        $this->assertNotSame('', $svg, 'Nothing was drawn.');

        preg_match_all('/<rect x="(\d+)"/', $svg, $m);

        $dark = array_flip(array_map('intval', $m[1]));

        // The renderer offsets by an 11-module quiet zone.
        $bits = '';

        for ($i = 0; $i < 95; $i++) {
            $bits .= isset($dark[$i + 11]) ? '1' : '0';
        }

        $this->assertSame('101', substr($bits, 0, 3), 'start guard');
        $this->assertSame('01010', substr($bits, 45, 5), 'centre guard');
        $this->assertSame('101', substr($bits, 92, 3), 'end guard');

        $digits = '';
        $parity = '';

        for ($i = 0; $i < 6; $i++) {
            $chunk = substr($bits, 3 + ($i * 7), 7);

            if (($d = array_search($chunk, self::L, true)) !== false) {
                $parity .= '0';
            } elseif (($d = array_search($chunk, self::G, true)) !== false) {
                $parity .= '1';
            } else {
                $this->fail("Left digit {$i} is not a valid EAN-13 symbol: {$chunk}");
            }

            $digits .= $d;
        }

        for ($i = 0; $i < 6; $i++) {
            $chunk = substr($bits, 50 + ($i * 7), 7);
            $d = array_search($chunk, self::R, true);

            if ($d === false) {
                $this->fail("Right digit {$i} is not a valid EAN-13 symbol: {$chunk}");
            }

            $digits .= $d;
        }

        $first = array_search($parity, self::PARITY, true);

        $this->assertNotFalse($first, "The parity run {$parity} is not a legal EAN-13 leading digit.");

        return $first.$digits;
    }
}
