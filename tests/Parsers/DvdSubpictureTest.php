<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\PngDecoder;

class DvdSubpictureTest extends TestCase
{
    private const PALETTE = [0x000000, 0xFFFFFF, 0xFF0000, 0x0000FF];

    // Pixel value n uses palette entry n with full contrast.
    private const COLORS = "\x03\x32\x10\x04\xFF\xFF";

    private const STA_DSP = "\x01";
    private const STP_DSP = "\x02";


    /**
     * Builds a unit: the size, the control sequence offset, the bitmap and the control sequences.
     * Each sequence gets its CMD_END byte, and the last sequence points to itself.
     *
     * @param list<array{int, string}> $sequences the delay in units of 1024/90000 s and the commands
     */
    private static function unit(string $bitmap, array $sequences): string
    {
        $offset  = 4 + strlen($bitmap);
        $control = "";
        foreach ($sequences as $index => [$delay, $commands]) {
            $length   = 4 + strlen($commands) + 1;
            $next     = $index === count($sequences) - 1 ? $offset : $offset + $length;
            $control .= pack("nn", $delay, $next) . $commands . "\xFF";
            $offset  += $length;
        }

        return pack("nn", 4 + strlen($bitmap) + strlen($control), 4 + strlen($bitmap)) . $bitmap . $control;
    }


    /**
     * SET_DAREA at x 100 and y 200, and SET_DSPXA with the fields at the given byte offsets.
     */
    private static function display(int $width, int $height, int $topField, int $bottomField): string
    {
        [$x1, $x2, $y1, $y2] = [100, 99 + $width, 200, 199 + $height];

        return "\x05" . chr($x1 >> 4) . chr(($x1 & 0x0F) << 4 | $x2 >> 8) . chr($x2 & 0xFF)
                      . chr($y1 >> 4) . chr(($y1 & 0x0F) << 4 | $y2 >> 8) . chr($y2 & 0xFF)
             . "\x06" . pack("nn", $topField, $bottomField);
    }


    /**
     * @return list<array{int, int}> runs of [0xRRGGBBAA, pixel count]
     */
    private static function runs(string $png): array
    {
        $runs = [];
        foreach (PngDecoder::decode($png)["pixels"] as $pixel) {
            if ($runs !== [] && $runs[array_key_last($runs)][0] === $pixel) {
                $runs[array_key_last($runs)][1]++;
            } else {
                $runs[] = [$pixel, 1];
            }
        }

        return $runs;
    }


    public function testFstaDspStartsAForcedImage(): void
    {
        // A 2x2 image of pixel value 1. Each line is the 1-nibble code 9: 2 pixels of value 1.
        $unit = self::unit("\x90\x90", [[45, self::COLORS . self::display(2, 2, 4, 5) . "\x00"]]);

        ["startDelay" => $start, "stopDelay" => $stop, "image" => $image] = DvdSubpicture::decode($unit, self::PALETTE, null, 720, 576);

        $this->assertSame([45 * 1024 / 90000, null], [$start, $stop]);
        $this->assertSame([100, 200, 2, 2, 720, 576, true],
                          [$image->x, $image->y, $image->width, $image->height, $image->screenWidth, $image->screenHeight, $image->forced]);
        $this->assertSame([[0xFFFFFFFF, 4]], self::runs($image->png));
    }


    public function testStpDspInALaterSequenceSetsTheStopDelay(): void
    {
        $unit = self::unit("\x90\x90", [[0, self::COLORS . self::display(2, 2, 4, 5) . self::STA_DSP], [200, self::STP_DSP]]);

        ["startDelay" => $start, "stopDelay" => $stop, "image" => $image] = DvdSubpicture::decode($unit, self::PALETTE, null, 720, 576);

        $this->assertSame([0.0, 200 * 1024 / 90000, false], [$start, $stop, $image->forced]);
    }


    public function testAUnitWithoutDisplayAreaHasNoImage(): void
    {
        $unit = self::unit("", [[10, self::STP_DSP]]);

        $this->assertSame(["startDelay" => 0.0, "stopDelay" => 10 * 1024 / 90000, "image" => null],
                          DvdSubpicture::decode($unit, self::PALETTE, null, 720, 576));
    }


    public function testSkipsTheParametersOfChgColcon(): void
    {
        // CHG_COLCON with a 2-byte size of 6 that counts itself. Its 4 parameter bytes hold 0x01 and 0xFF.
        $unit = self::unit("\x90\x90", [[0, self::COLORS . self::display(2, 2, 4, 5) . "\x07\x00\x06\x01\xFF\x01\xFF" . self::STP_DSP]]);

        ["startDelay" => $start, "stopDelay" => $stop, "image" => $image] = DvdSubpicture::decode($unit, self::PALETTE, null, 720, 576);

        $this->assertSame([0.0, 0.0], [$start, $stop]);
        $this->assertNotNull($image);
    }


    public function testDecodesRunLengthCodesOfOneToFourNibbles(): void
    {
        // Codes 5 (1 white), 1E (7 red), 0A3 (40 blue), 0190 (100 black) and 0001 (white to the end of the line).
        $unit = self::unit("\x51\xE0\xA3\x01\x90\x00\x01", [[0, self::COLORS . self::display(160, 1, 4, 4) . self::STA_DSP]]);

        $image = DvdSubpicture::decode($unit, self::PALETTE, null, 720, 576)["image"];

        $this->assertSame([[0xFFFFFFFF, 1], [0xFF0000FF, 7], [0x0000FFFF, 40], [0x000000FF, 100], [0xFFFFFFFF, 12]], self::runs($image->png));
    }


    public function testCustomColorsReplaceThePaletteAndTheContrast(): void
    {
        $unit = self::unit("\x90\x90", [[0, self::COLORS . self::display(2, 2, 4, 5) . self::STA_DSP]]);

        $image = DvdSubpicture::decode($unit, self::PALETTE, [0x00000000, 0x11223380, 0, 0], 720, 576)["image"];

        $this->assertSame([[0x11223380, 4]], self::runs($image->png));
    }


    public function testABitmapThatEndsEarlyThrows(): void
    {
        // The top field offset points to the end of the unit, so line 0 has no run-length code.
        $size = strlen(self::unit("", [[0, self::COLORS . self::display(2, 2, 0, 0) . self::STA_DSP]]));
        $unit = self::unit("", [[0, self::COLORS . self::display(2, 2, $size, $size) . self::STA_DSP]]);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The bitmap of the subtitle packet ends before line 0 is complete.");

        DvdSubpicture::decode($unit, self::PALETTE, null, 720, 576);
    }
}
