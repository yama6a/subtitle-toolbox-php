<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Application;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class PgsFormatterTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";


    /**
     * @return list<array{int, int, int, string}> PTS, DTS, segment type and data of each segment
     */
    private static function segments(string $sup): array
    {
        $segments = [];
        for ($offset = 0; $offset < strlen($sup); $offset += 13 + $size) {
            ["magic" => $magic, "pts" => $pts, "dts" => $dts, "type" => $type, "size" => $size] =
                unpack("a2magic/Npts/Ndts/Ctype/nsize", $sup, $offset);
            self::assertSame("PG", $magic);
            $segments[] = [$pts, $dts, $type, substr($sup, $offset + 13, $size)];
        }

        return $segments;
    }


    private static function imageCue(float $start, float $end, int $width, int $height, array $pixels, bool $forced = false): SubtitleCue
    {
        $image = new CueImage(PngEncoder::encode($width, $height, $pixels), 100, 900, $width, $height, 1920, 1080, $forced);

        return TestSubtitles::imageCue($image, new SubtitleCue($start, $end));
    }


    private static function assertSameImage(CueImage $expected, CueImage $actual, string $message = ""): void
    {
        self::assertSame([$expected->x, $expected->y, $expected->width, $expected->height, $expected->screenWidth, $expected->screenHeight, $expected->forced],
                         [$actual->x, $actual->y, $actual->width, $actual->height, $actual->screenWidth, $actual->screenHeight, $actual->forced], $message);
        self::assertSame(PngDecoder::decode($expected->png)["pixels"], PngDecoder::decode($actual->png)["pixels"], $message);
    }


    private static function pgsRoundTrip(Subtitle $subtitle): Subtitle
    {
        return (new PgsParser())->parse($subtitle->toString(Format::Pgs), new ReadOptions());
    }


    /**
     * @return array<string, array{string}>
     */
    public static function pgsFixtures(): array
    {
        $files = [];
        foreach ([...glob(self::FILES . "pgs/*.sup"), ...glob(self::FILES . "fixing/*.sup")] as $path) {
            $files[basename(dirname($path)) . "/" . basename($path)] = [$path];
        }

        return $files;
    }


    #[DataProvider("pgsFixtures")]
    public function testPgsFixturesRoundTripWithTheSamePixelsPositionsAndTimes(string $path): void
    {
        $original = (new PgsParser())->parse(file_get_contents($path), new ReadOptions());
        $written  = self::pgsRoundTrip($original);

        $this->assertCount(count($original), $written);
        foreach ($original->getCues() as $index => $cue) {
            $copy = $written->getCues()[$index];

            $this->assertEqualsWithDelta($cue->getStart(), $copy->getStart(), 0.001);
            $this->assertEqualsWithDelta($cue->getEnd(), $copy->getEnd(), 0.001);
            self::assertSameImage(CueImage::fromCue($cue), CueImage::fromCue($copy), "cue $index");
            $this->assertSame($cue->isForced(), $copy->isForced());
            $this->assertSame($cue->getAlignment(), $copy->getAlignment());
        }
    }


    /**
     * The text fixtures have one object per display set, in the segment order of PgsFormatter.
     *
     * @return array<string, array{string}>
     */
    public static function singleObjectFixtures(): array
    {
        return array_filter(self::pgsFixtures(), fn (string $key): bool => !str_starts_with($key, "pgs/") || str_starts_with($key, "pgs/text"),
                            ARRAY_FILTER_USE_KEY);
    }


    #[DataProvider("singleObjectFixtures")]
    public function testWritesSingleObjectFixturesByteForByte(string $path): void
    {
        $sup = file_get_contents($path);

        $this->assertSame($sup, (new PgsParser())->parse($sup, new ReadOptions())->toString(Format::Pgs));
    }


    /**
     * @return array<string, array{string, int}>
     */
    public static function vobSubTracks(): array
    {
        return [
            "two-tracks-pal en"     => ["two-tracks-pal", 0],
            "two-tracks-pal de"     => ["two-tracks-pal", 1],
            "custom-colors-ntsc ja" => ["custom-colors-ntsc", 0],
            "custom-colors-ntsc fr" => ["custom-colors-ntsc", 2],
            "text-pal en"           => ["text-pal", 0],
        ];
    }


    /**
     * Limited range YCbCr has no code for some RGB colors of a DVD palette, so a channel may change by 1.
     */
    #[DataProvider("vobSubTracks")]
    public function testVobSubFixturesConvertToPgs(string $name, int $track): void
    {
        $original = (new VobSubParser())
            ->parse(file_get_contents(self::FILES . "vobsub/$name.sub"), new ReadOptions(formats: [new VobSubReadOptions(file_get_contents(self::FILES . "vobsub/$name.idx"), track: $track)]));
        $written  = self::pgsRoundTrip($original);

        $this->assertCount(count($original), $written);
        foreach ($original->getCues() as $index => $cue) {
            $copy   = $written->getCues()[$index];
            $before = CueImage::fromCue($cue);
            $after  = CueImage::fromCue($copy);

            $this->assertEqualsWithDelta($cue->getStart(), $copy->getStart(), 0.001);
            $this->assertEqualsWithDelta($cue->getEnd(), $copy->getEnd(), 0.001);
            $this->assertSame([$before->x, $before->y, $before->width, $before->height, $before->screenWidth, $before->screenHeight, $before->forced],
                              [$after->x, $after->y, $after->width, $after->height, $after->screenWidth, $after->screenHeight, $after->forced]);

            $afterPixels = PngDecoder::decode($after->png)["pixels"];
            $alphaErrors = 0;
            $colorError  = 0;
            foreach (PngDecoder::decode($before->png)["pixels"] as $pixel => $color) {
                $alphaErrors += ($color & 0xFF) === ($afterPixels[$pixel] & 0xFF) ? 0 : 1;
                for ($shift = 8; $shift < 32; $shift += 8) {
                    $colorError = max($colorError, abs(($color >> $shift & 0xFF) - ($afterPixels[$pixel] >> $shift & 0xFF)));
                }
            }
            $this->assertSame([0, 1], [$alphaErrors, max(1, $colorError)], "cue $index");
        }
    }


    public function testWritesOneDisplaySetToShowAndOneToClearEachCue(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(self::imageCue(2.5, 4.0, 3, 2, [0x00000000, 0xFFFFFFFF, 0xFFFFFFFF, 0x000000FF, 0x00000000, 0x00000000], true))
            ->addCue(self::imageCue(1.0, 2.0, 2, 1, [0xFFFF00FF, 0xFFFF00FF]));

        $segments = self::segments($subtitle->toString(Format::Pgs));

        $this->assertSame([[90000, 0x16], [90000, 0x17], [90000, 0x14], [90000, 0x15], [90000, 0x80],
                           [180000, 0x16], [180000, 0x17], [180000, 0x80],
                           [225000, 0x16], [225000, 0x17], [225000, 0x14], [225000, 0x15], [225000, 0x80],
                           [360000, 0x16], [360000, 0x17], [360000, 0x80]],
                          array_map(fn (array $segment): array => [$segment[0], $segment[2]], $segments));
        $this->assertSame([0], array_unique(array_column($segments, 1)));

        $this->assertSame(pack("nnCnCCCC", 1920, 1080, 0x10, 2, 0x80, 0, 0, 1) . pack("nCCnn", 0, 0, 0x40, 100, 900), $segments[8][3]);
        $this->assertSame(pack("nnCnCCCC", 1920, 1080, 0x10, 3, 0x00, 0, 0, 0), $segments[13][3]);
        $this->assertSame(pack("CCnnnn", 1, 0, 100, 900, 3, 2), $segments[9][3]);
        $this->assertSame(pack("CC", 0, 0) . pack("C5", 0, 16, 128, 128, 0) . pack("C5", 1, 235, 128, 128, 255)
                          . pack("C5", 2, 16, 128, 128, 255), $segments[10][3]);
        $this->assertSame(pack("nCCCnnn", 0, 0, 0xC0, 0, 4 + 11, 3, 2) . "\0\1\1\1\0\0" . "\2\0\2\0\0", $segments[11][3]);
    }


    public function testACueThatStartsAtTheEndOfThePreviousCueReplacesIt(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(self::imageCue(1.0, 2.0, 1, 1, [0xFFFFFFFF]))
            ->addCue(self::imageCue(2.0, 3.0, 1, 1, [0x000000FF]));

        $this->assertSame([[1.0, 2.0], [2.0, 3.0]],
                          array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], self::pgsRoundTrip($subtitle)->getCues()));
        $this->assertCount(2 * 5 + 3, self::segments($subtitle->toString(Format::Pgs)));
    }


    /**
     * @return array{SubtitleCue, SubtitleCue} the bottom cue from 1.0 to 3.5 s and the top cue, moved to 2.0 to 4.0 s
     */
    private static function overlappingFixtureCues(): array
    {
        $cues = Subtitle::load(self::FILES . "pgs/shapes_1080p.sup", Format::Pgs)->getCues();
        $cues[2]->setStart(2.0)->setEnd(4.0);

        return [$cues[0], $cues[2]];
    }


    /**
     * @return list<int> the pixels of $image inside the area of $part
     */
    private static function area(CueImage $image, CueImage $part): array
    {
        $pixels = PngDecoder::decode($image->png)["pixels"];
        $area   = [];
        for ($row = 0; $row < $part->height; $row++) {
            $offset = ($part->y - $image->y + $row) * $image->width + $part->x - $image->x;
            array_push($area, ...array_slice($pixels, $offset, $part->width));
        }

        return $area;
    }


    public function testOverlappingCuesShareTheDisplaySetsAndKeepBothImages(): void
    {
        [$bottom, $top] = self::overlappingFixtureCues();
        $subtitle       = (new Subtitle())->addCues([$bottom, $top]);

        $cues   = self::pgsRoundTrip($subtitle)->getCues();
        $images = array_map(fn (SubtitleCue $cue): CueImage => CueImage::fromCue($cue), $cues);

        $this->assertSame([[1.0, 2.0], [2.0, 3.5], [3.5, 4.0]],
                          array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $cues));
        $this->assertSame(CueImage::fromCue($bottom)->png, $images[0]->png);
        $this->assertSame(CueImage::fromCue($top)->png, $images[2]->png);
        foreach ([$bottom, $top] as $cue) {
            $image = CueImage::fromCue($cue);
            $this->assertSame(PngDecoder::decode($image->png)["pixels"], self::area($images[1], $image));
        }

        $sets = array_values(array_filter(self::segments($subtitle->toString(Format::Pgs)),
                                          fn (array $segment): bool => $segment[0] === 180000 && in_array($segment[2], [0x16, 0x17], true)));
        $this->assertSame(pack("nnCnCCCC", 1920, 1080, 0x10, 1, 0x80, 0, 0, 2)
                          . pack("nCCnn", 0, 0, 0, 640, 940) . pack("nCCnn", 1, 1, 0x40, 560, 50), $sets[0][3]);
        $this->assertSame(pack("C", 2) . pack("Cnnnn", 0, 640, 940, 640, 90) . pack("Cnnnn", 1, 560, 50, 800, 100), $sets[1][3]);
    }


    public function testOverlappingImagesOnTheScreenShareOneWindow(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(TestSubtitles::imageCue(new CueImage(PngEncoder::encode(2, 1, [0xFFFFFFFF, 0x00000000]), 10, 20, 2, 1, 720, 576),
                                             new SubtitleCue(1, 3)))
            ->addCue(TestSubtitles::imageCue(new CueImage(PngEncoder::encode(1, 2, [0x000000FF, 0x000000FF]), 11, 20, 1, 2, 720, 576),
                                             new SubtitleCue(1, 3)));

        $segments = self::segments($subtitle->toString(Format::Pgs));

        $this->assertSame([0x16, 0x17, 0x14, 0x15, 0x15, 0x80, 0x16, 0x17, 0x80], array_column($segments, 2));
        $this->assertSame(pack("nCCnn", 0, 0, 0, 10, 20) . pack("nCCnn", 1, 0, 0, 11, 20), substr($segments[0][3], 11));
        $this->assertSame(pack("C", 1) . pack("Cnnnn", 0, 10, 20, 2, 2), $segments[1][3]);
        $this->assertSame($segments[1][3], $segments[7][3]);
    }


    public function testAThirdOverlappingCueReplacesTheCueThatStartedFirst(): void
    {
        $subtitle = Subtitle::load(self::FILES . "pgs/shapes_576p.sup", Format::Pgs);
        [$first, $second] = $subtitle->getCues();
        $third            = (clone $second)->setStart(2.5)->setEnd(10);
        $second->setStart(2);
        $subtitle->addCue($third);

        $presentations = array_values(array_filter(self::segments($subtitle->toString(Format::Pgs)),
                                                   fn (array $segment): bool => in_array($segment[2], [0x16, 0x17], true)));

        $this->assertSame([[45000, 1], [180000, 2], [225000, 2], [810000, 1], [900000, 0]],
                          array_map(fn (array $segment): array => [$segment[0], ord($segment[3][10])],
                                    array_values(array_filter($presentations, fn (array $segment): bool => $segment[2] === 0x16))));
        foreach ([3, 5] as $window) {
            $this->assertSame(pack("C", 1) . pack("Cnnnn", 0, 160, 480, 400, 60), $presentations[$window][3]);
        }
    }


    /**
     * Each gray is a luma code that PgsParser decodes, so the pixels round-trip exactly.
     */
    public function testSplitsLargeObjectsIntoFragments(): void
    {
        $pixels = [];
        for ($index = 0; $index < 600 * 300; $index++) {
            $gray     = (int) round((($index * 2654435761) >> 7) % 200 * 255 / 219);
            $pixels[] = $gray << 24 | $gray << 16 | $gray << 8 | 0xFF;
        }
        $subtitle = (new Subtitle())->addCue(self::imageCue(1.0, 2.0, 600, 300, $pixels));

        $objects = array_values(array_filter(self::segments($subtitle->toString(Format::Pgs)),
                                             fn (array $segment): bool => $segment[2] === 0x15));

        $this->assertGreaterThan(2, count($objects));
        $this->assertSame([0x80, 0x00, 0x40], [ord($objects[0][3][3]), ord($objects[1][3][3]), ord(end($objects)[3][3])]);
        $this->assertSame(0xFFFF, strlen($objects[0][3]));
        self::assertSameImage(CueImage::fromCue($subtitle->getCues()[0]), CueImage::fromCue(self::pgsRoundTrip($subtitle)->getCues()[0]));
    }


    public function testReducesImagesWithMoreThan255Colors(): void
    {
        $pixels = [];
        for ($index = 0; $index < 30 * 40; $index++) {
            $pixels[] = $index % 10 === 0 ? 0x00000000 : ($index * 13 % 256) << 24 | ($index % 7 * 30) << 16 | 0x80FF;
        }
        $subtitle = (new Subtitle())->addCue(self::imageCue(1.0, 2.0, 30, 40, $pixels));

        $palette = array_values(array_filter(self::segments($subtitle->toString(Format::Pgs)),
                                             fn (array $segment): bool => $segment[2] === 0x14))[0][3];
        $written = PngDecoder::decode(CueImage::fromCue(self::pgsRoundTrip($subtitle)->getCues()[0])->png)["pixels"];

        $this->assertSame(2 + 256 * 5, strlen($palette));
        foreach ($pixels as $index => $pixel) {
            $this->assertSame($index % 10 === 0 ? 0 : 0xFF, $written[$index] & 0xFF);
        }
    }


    /**
     * @param list<int> $palette
     * @return array{SubtitleCue, SubtitleCue} the image as an indexed PNG and as an RGBA PNG
     */
    private static function indexedAndRgbaCues(float $start, float $end, int $width, array $palette, string $indexes): array
    {
        $height = intdiv(strlen($indexes), $width);
        $pixels = array_map(fn (string $entry): int => $palette[ord($entry)], str_split($indexes));

        return [TestSubtitles::imageCue(new CueImage(PngEncoder::encodeIndexed($width, $height, $indexes, $palette), 100, 900, $width, $height, 1920, 1080),
                                        new SubtitleCue($start, $end)),
                self::imageCue($start, $end, $width, $height, $pixels)];
    }


    public function testWritesIndexedPngFilesLikeTheSameRgbaPngFiles(): void
    {
        // Entry 3 repeats entry 1, entry 4 is unused, and the transparent color is not entry 0.
        [$firstIndexed, $firstRgba]   = self::indexedAndRgbaCues(1.0, 3.0, 3, [0x11111180, 0xFFFFFFFF, 0x00000000, 0xFFFFFFFF, 0xABCDEFFF],
                                                                  "\1\2\0\3\0\1\2\2\2");
        [$secondIndexed, $secondRgba] = self::indexedAndRgbaCues(2.0, 4.0, 2, [0xFF0000FF, 0xFFFFFFFF], "\1\0\0\1");

        $expected = (new Subtitle())->addCues([$firstRgba, $secondRgba])->toString(Format::Pgs);

        $this->assertSame($expected, (new Subtitle())->addCues([$firstIndexed, $secondIndexed])->toString(Format::Pgs));
        $this->assertSame($expected, (new Subtitle())->addCues([$firstIndexed, $secondRgba])->toString(Format::Pgs));
    }


    public function testWritesIndexedPngFilesWithMoreThan256ColorsTogetherLikeRgbaPngFiles(): void
    {
        $indexes = implode("", array_map("chr", range(0, 199)));
        [$firstIndexed, $firstRgba]   = self::indexedAndRgbaCues(1.0, 3.0, 20, range(0x000000FF, 0x0000C7FF, 0x100), $indexes);
        [$secondIndexed, $secondRgba] = self::indexedAndRgbaCues(2.0, 4.0, 20, range(0x00FF00FF, 0x00FFC7FF, 0x100), $indexes);

        $this->assertSame((new Subtitle())->addCues([$firstRgba, $secondRgba])->toString(Format::Pgs),
                          (new Subtitle())->addCues([$firstIndexed, $secondIndexed])->toString(Format::Pgs));
    }


    public function testWritesAFull3840x2160IndexedImageInLessThan64MbOfMemory(): void
    {
        $indexes  = str_repeat("\1", 3840 * 1080) . str_repeat("\2", 3840 * 1080);
        $image    = new CueImage(PngEncoder::encodeIndexed(3840, 2160, $indexes, [0, 0xFFFFFFFF, 0x000000FF]), 0, 0, 3840, 2160, 3840, 2160);
        $subtitle = (new Subtitle())->addCue(TestSubtitles::imageCue($image, new SubtitleCue(1.0, 2.0)));
        unset($indexes);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $subtitle->toString(Format::Pgs);

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before);
        ["palette" => $palette, "indexes" => $copy] = PngDecoder::decodeIndexed(CueImage::fromCue(self::pgsRoundTrip($subtitle)->getCues()[0])->png);
        $this->assertSame([0xFFFFFFFF, 0x000000FF], [$palette[ord($copy[0])], $palette[ord($copy[-1])]]);
    }


    public function testTheForcedFlagOfTheCueSetsTheForcedFlagOfTheObject(): void
    {
        $subtitle = (new PgsParser())->parse(file_get_contents(self::FILES . "pgs/shapes_1080p.sup"), new ReadOptions());
        $subtitle->getCues()[0]->setForced(true);
        $subtitle->getCues()[2]->setForced(false);

        $written = self::pgsRoundTrip($subtitle);

        $this->assertSame([true, false, false, true, false, false],
                          array_map(fn (SubtitleCue $cue): bool => $cue->isForced(), $written->getCues()));
        $this->assertCount(2, self::pgsRoundTrip($subtitle->withForcedCuesOnly()));
    }


    public function testShiftedFileKeepsTheBitmaps(): void
    {
        $original = (new PgsParser())->parse(file_get_contents(self::FILES . "pgs/text_1080p.sup"), new ReadOptions());
        $shifted  = (new PgsParser())->parse(file_get_contents(self::FILES . "pgs/text_1080p.sup"), new ReadOptions())->shift(-0.75);

        $written = self::pgsRoundTrip($shifted);

        $this->assertSame(0.25, $written->getCues()[0]->getStart());
        $this->assertSame(37.25, $written->getCues()[11]->getEnd());
        $this->assertSame(CueImage::fromCue($original->getCues()[5])->png, CueImage::fromCue($written->getCues()[5])->png);
    }


    public function testTheCommandLineToolWritesPgs(): void
    {
        $this->assertSame(PgsFormatter::class, FormatRegistry::formatterClass(Format::Pgs));

        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        $code    = (new Application(...$streams))->run(["subtitle-toolbox", "convert", self::FILES . "pgs/shapes_576p.sup", "--shift", "1"]);
        rewind($streams[1]);

        $this->assertSame(0, $code);
        $this->assertSame([1.5, 4.0, 5.0, 10.0], array_merge(...array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()],
                                                                     (new PgsParser())->parse(stream_get_contents($streams[1]), new ReadOptions())->getCues())));
    }


    /**
     * @return array<string, array{SubtitleCue, string}>
     */
    public static function invalidCues(): array
    {
        $image = new CueImage(PngEncoder::encode(2, 2, [0, 0, 0, 0]), 0, 0, 2, 2, 720, 576);

        return [
            "text cue"     => [new SubtitleCue(1, 2, "text"), "the cue holds no image, and PGS output cannot hold text"],
            "size"         => [TestSubtitles::imageCue(new CueImage($image->png, 0, 0, 3, 2, 720, 576), new SubtitleCue(1, 2)), "the PNG has 2x2 pixels"],
            "negative x"   => [TestSubtitles::imageCue(new CueImage($image->png, -1, 0, 2, 2, 720, 576), new SubtitleCue(1, 2)), "from 0 to 65535"],
            "negative time" => [TestSubtitles::imageCue($image, new SubtitleCue(-0.5, 2)), "ticks of 90 kHz"],
        ];
    }


    #[DataProvider("invalidCues")]
    public function testRejectsCuesThatPgsCannotHold(SubtitleCue $cue, string $message): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage($message);

        (new Subtitle())->addCue($cue)->toString(Format::Pgs);
    }
}
