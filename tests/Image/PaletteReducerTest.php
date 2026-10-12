<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class PaletteReducerTest extends TestCase
{
    /**
     * @param array{palette: list<int>, indexes: string} $reduced
     * @return list<int>
     */
    private function expand(array $reduced): array
    {
        return array_map(fn (int $entry): int => $reduced["palette"][$entry], array_values(unpack("C*", $reduced["indexes"])));
    }


    public function testKeepsUpTo256ColorsExactlyWithTheTransparentColorFirst(): void
    {
        $pixels = [0xFFFFFFFF, 0x000000FF, 0x00000000, 0xFFFFFFFF];
        for ($color = 0; $color < 253; $color++) {
            $pixels[] = $color << 8 | 0x80;
        }

        $reduced = PaletteReducer::reduce($pixels);

        $this->assertCount(256, $reduced["palette"]);
        $this->assertSame([0x00000000, 0xFFFFFFFF, 0x000000FF], array_slice($reduced["palette"], 0, 3));
        $this->assertSame("\1\2\0\1", substr($reduced["indexes"], 0, 4));
        $this->assertSame($pixels, $this->expand($reduced));
    }


    public function testReducesMoreColorsTo255AndOneTransparentEntry(): void
    {
        $pixels = [];
        for ($index = 0; $index < 4000; $index++) {
            $pixels[] = $index % 7 === 0 ? ($index << 8) & 0xFFFFFF00 : (($index * 2654435761) & 0xFFFFFF00) | 0xFF;
        }

        $reduced = PaletteReducer::reduce($pixels);
        $error   = 0;
        foreach ($this->expand($reduced) as $index => $pixel) {
            if ($index % 7 === 0) {
                $this->assertSame(0x00000000, $pixel);
                continue;
            }
            for ($shift = 8; $shift < 32; $shift += 8) {
                $error = max($error, abs(($pixel >> $shift & 0xFF) - ($pixels[$index] >> $shift & 0xFF)));
            }
            $this->assertSame(0xFF, $pixel & 0xFF);
        }

        $this->assertCount(256, $reduced["palette"]);
        $this->assertSame(0x00000000, $reduced["palette"][0]);
        $this->assertLessThan(64, $error);
        $this->assertSame($reduced, PaletteReducer::reduce($pixels));
    }


    public function testReducesToASmallerPalette(): void
    {
        $pixels  = [0x00000000, 0xFF0000FF, 0xFE0000FF, 0x0000FFFF, 0x0000FEFF, 0xFF0000FF, 0xFE0000FF, 0x0000FFFF, 0x0000FEFF];
        $reduced = PaletteReducer::reduce($pixels, 3);

        $this->assertSame([0x00000000, 0x0000FFFF, 0xFF0000FF], $reduced["palette"]);
        $this->assertSame("\0\2\2\1\1\2\2\1\1", $reduced["indexes"]);
    }


    public function testReducesIndexedImagesLikeTheirPixels(): void
    {
        // Entries 1 and 3 of the first image have the same color. Both images use 0xFF000000, which is transparent.
        $first  = ["palette" => [0xFFFFFFFF, 0x11223344, 0xFF000000, 0x11223344], "indexes" => "\1\3\0\2\1\0"];
        $second = ["palette" => [0x55667788, 0xFF000000, 0xFFFFFFFF], "indexes" => "\0\1\2\2\0\5"];

        $pixels = [];
        foreach ([$first, $second] as ["palette" => $palette, "indexes" => $indexes]) {
            foreach (str_split($indexes) as $entry) {
                $pixels[] = $palette[ord($entry)] ?? 0;
            }
        }

        $this->assertSame(PaletteReducer::reduce($pixels), PaletteReducer::reduceIndexed([$first, $second]));
    }


    public function testReduceIndexedReturnsNullForMoreThan256Colors(): void
    {
        $first  = ["palette" => range(0x100, 0x100 * 200, 0x100), "indexes" => implode("", array_map("chr", range(0, 199)))];
        $second = ["palette" => range(0x100 * 201, 0x100 * 260, 0x100), "indexes" => implode("", array_map("chr", range(0, 59)))];

        $this->assertNull(PaletteReducer::reduceIndexed([$first, $second]));
        $this->assertCount(256, PaletteReducer::reduceIndexed([$first, ["palette" => $second["palette"], "indexes" => substr($second["indexes"], 0, 56)]])["palette"]);
    }


    public function testRejectsAPaletteSizeAbove256(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaletteReducer::reduce([0], 257);
    }
}
