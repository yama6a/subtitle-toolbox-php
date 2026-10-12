<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PgsColorsTest extends TestCase
{
    /**
     * The 8 WebVTT colors and 3 grey values in both matrices. 6 colors have no exact code in limited range video, so
     * they come back 1 step off in one channel.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function colorProvider(): array
    {
        $cases = [];
        foreach ([1080 => [0x00FFFF => 0x00FEFF, 0xFF0000 => 0xFF0100, 0xFFFF00 => 0xFEFF00, 0x0000FF => 0x0100FF],
                  576  => [0x00FFFF => 0x01FFFF, 0xFF0000 => 0xFE0000]] as $screenHeight => $nearest) {
            foreach ([0xFFFFFF, 0x00FF00, 0x00FFFF, 0xFF0000, 0xFFFF00, 0xFF00FF, 0x0000FF, 0x000000, 0x404040, 0x808080, 0xC0C0C0] as $rgb) {
                $cases[sprintf("%dp #%06x", $screenHeight, $rgb)] = [$screenHeight, $rgb, $nearest[$rgb] ?? $rgb];
            }
        }

        return $cases;
    }


    #[DataProvider("colorProvider")]
    public function testToRgbTurnsTheCodeOfToYcrcbBackIntoTheColor(int $screenHeight, int $rgb, int $expected): void
    {
        $matrix = PgsColors::matrix($screenHeight);

        $this->assertSame([$expected >> 16, $expected >> 8 & 0xFF, $expected & 0xFF],
                          PgsColors::toRgb(...PgsColors::toYcrcb($rgb, $matrix), matrix: $matrix));
    }


    public function testSearchesUpToTwoStepsFromTheRoundedCode(): void
    {
        $matrix = PgsColors::matrix(576);

        // Plain rounding gives the code 216, 142, 44, which decodes to 255, 254, 63.
        $this->assertSame([218, 142, 43], PgsColors::toYcrcb(0xFFFF40, $matrix));
        $this->assertSame([255, 255, 64], PgsColors::toRgb(218, 142, 43, $matrix));
    }


    public function testUsesBt601UpTo576LinesAndBt709Above(): void
    {
        $this->assertSame([0.299, 0.114], PgsColors::matrix(576));
        $this->assertSame([0.2126, 0.0722], PgsColors::matrix(577));
    }
}
