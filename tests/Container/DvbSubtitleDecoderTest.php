<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PgsColors;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\SubtitleCue;

class DvbSubtitleDecoderTest extends TestCase
{
    private const RED     = 0xFF0000FF;
    private const GREEN   = 0x00FF00FF;
    private const YELLOW  = 0xFFFF00FF;
    private const MAGENTA = 0xFF00FFFF;
    private const BLACK   = 0x000000FF;
    private const GRAY    = 0x7F7F7FFF;


    public function testDecodes4BitCodesWithTheDefaultClutAndCopiesTheTopFieldToTheBottomField(): void
    {
        // Pixel codes 1, 1, a run of three 0, 2, 3 and 9, then the end of the string.
        $object  = "\x11\x11\x01\x23\x90\x00\xF0";
        $decoder = new DvbSubtitleDecoder();
        $decoder->add(self::page(0, [[0, 100, 50]]) . self::region(0, 8, 2, 2, [[7, 0, 0]]) . self::object(7, $object) . self::end(), 1.0);

        $row = [self::RED, self::RED, 0, 0, 0, self::GREEN, self::YELLOW, 0x7F0000FF];
        $this->assertSame([[1.0, 6.0, 100, 50, 8, 2, 720, 576, [...$row, ...$row]]], self::images($decoder->finish(5.0)));
    }


    public function testMaps2BitCodesAndKeepsTheRegionBelowANonModifyingColor(): void
    {
        // A 4-bit region filled with code 5. Codes 1, 2 and 3 with the default 2-to-4-bit map, then a new map and code 3.
        $object  = "\x10\x6C\x00" . "\x20\x12\x3A" . "\x10\xC0" . "\xF0";
        $decoder = new DvbSubtitleDecoder();
        $decoder->add(self::page(0, [[0, 0, 0]]) . self::region(0, 4, 1, 2, [[1, 0, 0]], background: 0x55) . self::object(1, $object, "", true), 0.0);

        $this->assertSame([[0.0, 5.0, 0, 0, 4, 1, 720, 576, [self::MAGENTA, self::BLACK, self::GRAY, 0x007F00FF]]],
                          self::images($decoder->finish(5.0)));
    }


    public function testReadsTheClutTheDisplayWindowAndOnlyThePagesOfTheService(): void
    {
        // 8-bit codes 1 and 2, then a run of two pixels of code 3, then the end of the string.
        $object  = "\x12\x01\x02\x00\x82\x03\x00\x00\xF0";
        $clut    = self::segment(0x12, "\x04\x0F" . "\x01\x21\xEB\x80\x80\x00" . "\x02\x21\x10\x80\x80\x80" . "\x03\x20" . pack("n", 0x31 << 10 | 0x0F << 6 | 0x05 << 2));
        $display = self::segment(0x14, "\x08" . pack("nnnnnn", 1919, 1079, 100, 1819, 200, 979));
        $decoder = new DvbSubtitleDecoder(1, 2);
        $decoder->add($display . self::page(0, [[0, 10, 20]]) . self::region(0, 4, 1, 3, [[1, 0, 0]], clut: 4) . self::segment(0x12, "\x04\x0F", 2) . $clut
                      . self::object(1, $object) . self::segment(0x13, pack("nCnn", 1, 0, 3, 0) . "\x12\x09\xF0", 3) . self::end(), 0.0);

        [$red, $green, $blue] = PgsColors::toRgb(0x31 << 2, 0xF0, 0x50, PgsColors::MATRIX_BT601);
        $this->assertSame([[0.0, 1.0, 110, 220, 4, 1, 1920, 1080, [0xFFFFFFFF, 0x0000007F, ...array_fill(0, 2, $red << 24 | $green << 16 | $blue << 8 | 0xFF)]]],
                          self::images($decoder->finish(1.0)));
    }


    public function testThePageTimeOutEndsACueThatARepeatedDisplaySetContinued(): void
    {
        $show    = self::region(0, 2, 1, 2, [[1, 0, 0]]) . self::object(1, "\x11\x11\x00\x00\xF0") . self::end();
        $decoder = new DvbSubtitleDecoder();
        $decoder->add(self::page(0, [[0, 0, 0]], timeOut: 3) . $show, 10.0);
        $decoder->add(self::page(0, [[0, 0, 0]], state: 1, timeOut: 3) . $show, 12.0);
        $decoder->add(self::page(1, [[0, 0, 0]], state: 2) . self::region(0, 2, 1, 2, [[1, 0, 0]]) . self::object(1, "\x11\x22\x00\x00\xF0") . self::end(), 20.0);
        $decoder->add(self::page(2, []) . self::end(), 21.5);

        $this->assertSame([[10.0, 15.0], [20.0, 21.5]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $decoder->finish(5.0)));
    }


    public function testShowsADisplaySetWithoutAnEndSegmentAtTheEndOfItsPacketAndSkipsTransparentRegions(): void
    {
        $decoder = new DvbSubtitleDecoder();
        $decoder->add("\x20\x00" . self::page(0, [[0, 0, 0], [1, 0, 500]]) . self::region(0, 720, 40, 2, []) . self::region(1, 2, 1, 2, [[1, 0, 0]])
                      . self::object(1, "\x11\x11\x00\x00\xF0") . "\xFF", 2.0);

        $this->assertSame([[2.0, 4.0, 0, 500, 2, 1, 720, 576, [self::RED, self::RED]]], self::images($decoder->finish(2.0)));
    }


    public function testThrowsForARegionLargerThanTheImageLimit(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Cannot read region 0: an image of 8000x1 pixels is larger than the limit");

        (new DvbSubtitleDecoder())->add(self::region(0, 8000, 1, 2, []), 0.0);
    }


    /**
     * @return list<array{float, float, int, int, int, int, int, int, list<int>}>
     */
    private static function images(array $cues): array
    {
        return array_map(function (SubtitleCue $cue): array {
            $image = CueImage::fromCue($cue);

            return [$cue->getStart(), $cue->getEnd(), $image->x, $image->y, $image->width, $image->height, $image->screenWidth, $image->screenHeight,
                    PngDecoder::decode($image->png)["pixels"]];
        }, $cues);
    }


    private static function segment(int $type, string $data, int $page = 1): string
    {
        return "\x0F" . pack("Cnn", $type, $page, strlen($data)) . $data;
    }


    /**
     * @param list<array{int, int, int}> $regions region id, x and y
     */
    private static function page(int $version, array $regions, int $state = 2, int $timeOut = 0): string
    {
        $data = chr($timeOut) . chr($version << 4 | $state << 2 | 0x03);
        foreach ($regions as [$id, $x, $y]) {
            $data .= pack("Cxnn", $id, $x, $y);
        }

        return self::segment(0x10, $data);
    }


    /**
     * @param int                         $depth   1 for 2-bit, 2 for 4-bit and 3 for 8-bit pixel codes
     * @param list<array{int, int, int}>  $objects object id, x and y
     * @param int                         $background the 4-bit code in the high nibble, the 2-bit code in bits 2 and 3
     */
    private static function region(int $id, int $width, int $height, int $depth, array $objects, int $clut = 0, int $background = 0): string
    {
        $data = pack("CCnnCCCC", $id, 0x0F, $width, $height, 0x60 | $depth << 2, $clut, 0, $background);
        foreach ($objects as [$objectId, $x, $y]) {
            $data .= pack("nnn", $objectId, $x, $y);
        }

        return self::segment(0x11, $data);
    }


    private static function object(int $id, string $top, string $bottom = "", bool $nonModifying = false): string
    {
        return self::segment(0x13, pack("nCnn", $id, $nonModifying ? 0x02 : 0x00, strlen($top), strlen($bottom)) . $top . $bottom);
    }


    private static function end(): string
    {
        return self::segment(0x80, "");
    }
}
