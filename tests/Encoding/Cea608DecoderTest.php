<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\TestCase;

class Cea608DecoderTest extends TestCase
{
    public function testARowThatRollsAboveRow1LeavesTheScreen(): void
    {
        // Roll-up with 4 rows, a PAC to row 1, then A, a carriage return, B, a carriage return and C.
        $words = [0x94a7, 0x94a7, 0x9140, 0x9140, 0xc180, 0x94ad, 0x94ad, 0xc280, 0x94ad, 0x94ad, 0x4380];

        $states = (new Cea608Decoder(1))->decode([[0, $words]]);

        $this->assertSame([["row" => 1, "column" => 0, "text" => "C"]], end($states)["lines"]);
        foreach ($states as $state) {
            $this->assertSame([], array_filter(array_column($state["lines"], "row"), fn (int $row): bool => $row < 1));
        }
    }
}
