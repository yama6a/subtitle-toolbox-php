<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Ocr\FakeOcrEngine;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\RealFiles;
use SubtitleToolbox\Tests\Support\TestSubtitles;

require_once __DIR__ . "/../files/pgs/generator/PgsFixtures.php";
require_once __DIR__ . "/../Ocr/FakeOcrEngine.php";

class PgsParserTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/pgs/";

    private const TRANSPARENT  = 0x00000000;
    private const WHITE        = 0xFFFFFFFF;
    private const BLACK        = 0x000000FF;
    private const GRAY         = 0x80808080;
    private const YELLOW_BT709 = 0xFEFF00FF;
    private const YELLOW_BT601 = 0xFCFF0AFF;


    private static function realFilesDir(): string
    {
        return "pgs/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Pgs;
    }


    private function pixelAt(CueImage $image, int $x, int $y): int
    {
        return PngDecoder::decode($image->png)["pixels"][$y * $image->width + $x];
    }


    public static function fixtureFiles(): array
    {
        return array_map(fn (string $method): array => [$method], PgsFixtures::FILES);
    }


    #[DataProvider("fixtureFiles")]
    public function testFixturesMatchTheGenerator(string $method): void
    {
        $this->assertSame(file_get_contents(self::DIR . array_search($method, PgsFixtures::FILES, true)), PgsFixtures::$method());
    }


    #[DataProvider("fixtureFiles")]
    public function testDetectsTheFixtures(string $method): void
    {
        $content = file_get_contents(self::DIR . array_search($method, PgsFixtures::FILES, true));

        $this->assertSame(Format::Pgs, Format::detect($content));
        $this->assertEquals((new PgsParser())->parse($content, new ReadOptions())->getCues(), Subtitle::fromStringAutoDetectFormat($content)->getCues());
    }


    public function testReadsTimesPositionsSizesAndFlags(): void
    {
        $cues = $this->parseFile("shapes_1080p.sup")->getCues();

        $actual = array_map(function ($cue): array {
            $image = CueImage::fromCue($cue);

            return [$cue->getStart(), $cue->getEnd(), $image->x, $image->y, $image->width, $image->height,
                    $image->screenWidth, $image->screenHeight, $image->forced, $cue->getAlignment(), $cue->getLines()];
        }, $cues);

        $this->assertSame([
            [1.0, 3.5, 640, 940, 640, 90, 1920, 1080, false, Alignment::BottomCenter, []],
            [5.0, 7.0, 660, 60, 600, 970, 1920, 1080, false, Alignment::BottomCenter, []],
            [8.0, 9.5, 560, 50, 800, 100, 1920, 1080, true, Alignment::TopCenter, []],
            [9.5, 11.0, 560, 50, 800, 100, 1920, 1080, true, Alignment::TopCenter, []],
            [12.0, 16.0, 860, 900, 200, 60, 1920, 1080, false, Alignment::BottomCenter, []],
            [20.0, 25.0, 640, 940, 640, 90, 1920, 1080, false, Alignment::BottomCenter, []],
        ], $actual);
    }


    public function testDecodesRunLengthsAndTheBt709PaletteForHdVideo(): void
    {
        $image = CueImage::fromCue($this->parseFile("shapes_1080p.sup")->getCues()[0]);

        $this->assertSame(self::TRANSPARENT, $this->pixelAt($image, 0, 0));
        $this->assertSame(self::TRANSPARENT, $this->pixelAt($image, 639, 89));
        $this->assertSame(self::BLACK, $this->pixelAt($image, 10, 10));
        $this->assertSame(self::BLACK, $this->pixelAt($image, 629, 79));
        $this->assertSame(self::WHITE, $this->pixelAt($image, 13, 13));
        $this->assertSame(self::YELLOW_BT709, $this->pixelAt($image, 320, 45));
        $this->assertSame(self::BLACK, $this->pixelAt($image, 20, 74));
        $this->assertSame(self::GRAY, $this->pixelAt($image, 21, 74));
    }


    public function testUsesTheBt601PaletteForSdVideo(): void
    {
        $cues  = $this->parseFile("shapes_576p.sup")->getCues();
        $image = CueImage::fromCue($cues[0]);

        $this->assertCount(2, $cues);
        $this->assertSame([0.5, 3.0, 4.0, 9.0], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[1]->getStart(), $cues[1]->getEnd()]);
        $this->assertSame([160, 480, 400, 60, 720, 576], [$image->x, $image->y, $image->width, $image->height,
                                                          $image->screenWidth, $image->screenHeight]);
        $this->assertSame(self::YELLOW_BT601, $this->pixelAt($image, 200, 30));
        $this->assertSame(self::WHITE, $this->pixelAt($image, 13, 13));
    }


    public function testPlacesTwoObjectsOnOneTransparentImage(): void
    {
        $image = CueImage::fromCue($this->parseFile("shapes_1080p.sup")->getCues()[1]);

        $this->assertSame(self::TRANSPARENT, $this->pixelAt($image, 5, 5));
        $this->assertSame(self::BLACK, $this->pixelAt($image, 110, 10));
        $this->assertSame(self::YELLOW_BT709, $this->pixelAt($image, 300, 35));
        $this->assertSame(self::TRANSPARENT, $this->pixelAt($image, 300, 500));
        $this->assertSame(self::BLACK, $this->pixelAt($image, 10, 900));
        $this->assertSame(self::YELLOW_BT709, $this->pixelAt($image, 300, 930));
    }


    public function testDrawsALaterObjectOverAnEarlierObjectThatItOverlaps(): void
    {
        $black    = TestSubtitles::imageCue(new CueImage(PngEncoder::encode(3, 1, [0x000000FF, 0x000000FF, 0x000000FF]), 10, 20, 3, 1, 720, 576),
                                             new SubtitleCue(1, 3));
        $white    = TestSubtitles::imageCue(new CueImage(PngEncoder::encode(3, 1, [0x00000000, 0xFFFFFF80, 0xFFFFFFFF]), 11, 20, 3, 1, 720, 576),
                                             new SubtitleCue(1, 3));
        $subtitle = Subtitle::fromString((new Subtitle())->addCues([$black, $white])->toString(Format::Pgs), Format::Pgs);

        $image = CueImage::fromCue($subtitle->getCues()[0]);
        $this->assertSame([10, 20, 4, 1], [$image->x, $image->y, $image->width, $image->height]);
        $this->assertSame([0x000000FF, 0x000000FF, 0x808080FF, 0xFFFFFFFF],
                          array_map(fn (int $x): int => $this->pixelAt($image, $x, 0), [0, 1, 2, 3]));
    }


    public function testAppliesPaletteUpdatesToObjectsOfTheEpoch(): void
    {
        $cues = $this->parseFile("shapes_1080p.sup")->getCues();

        $before = CueImage::fromCue($cues[2]);
        $after  = CueImage::fromCue($cues[3]);

        $this->assertSame(self::WHITE, $this->pixelAt($before, 100, 20));
        $this->assertSame(self::GRAY, $this->pixelAt($after, 100, 20));
        $this->assertSame(self::YELLOW_BT709, $this->pixelAt($after, 400, 50));
        $this->assertSame(self::BLACK, $this->pixelAt($after, 10, 10));
    }


    public function testCropsObjects(): void
    {
        $image = CueImage::fromCue($this->parseFile("shapes_1080p.sup")->getCues()[4]);

        $this->assertSame(self::WHITE, $this->pixelAt($image, 0, 0));
        $this->assertSame(self::YELLOW_BT709, $this->pixelAt($image, 95, 25));
        $this->assertSame(self::WHITE, $this->pixelAt($image, 199, 59));
    }


    public function testWritesOneObjectAsAnIndexedPngOfTheUsedColors(): void
    {
        $cues = $this->parseFile("shapes_1080p.sup")->getCues();

        $png = CueImage::fromCue($cues[0])->png;
        $this->assertSame([PngDecoder::COLOR_PALETTE, "PLTE", 5 * 3], [ord($png[25]), substr($png, 37, 4), unpack("N", $png, 33)[1]]);
        $this->assertSame(PngDecoder::COLOR_RGBA, ord(CueImage::fromCue($cues[1])->png[25]));   // 2 objects
    }


    public function testAPaletteUpdateOfAnEntryThatNoPixelUsesKeepsTheCue(): void
    {
        $object = ["id" => 0, "window" => 0, "x" => 0, "y" => 0];
        $writer = (new PgsFixtureWriter())
            ->presentation(90000, 1920, 1080, 1, PgsFixtureWriter::STATE_EPOCH_START, 0, [$object])
            ->palette(90000, 0, 0, PgsFixtures::PALETTE)
            ->object(90000, 0, 0, 4, 2, "\1\1\3\3\3\3\0\0")
            ->end(90000)
            ->presentation(180000, 1920, 1080, 2, PgsFixtureWriter::STATE_NORMAL, 0, [$object], paletteUpdate: true)
            ->palette(180000, 0, 1, [PgsFixtures::BLACK => [126, 128, 128, 255]])
            ->end(180000)
            ->presentation(270000, 1920, 1080, 3, PgsFixtureWriter::STATE_NORMAL, 0, [])
            ->end(270000);

        $cues = (new PgsParser())->parse($writer->bytes(), new ReadOptions())->getCues();

        $this->assertSame([[1.0, 3.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $cues));
    }


    public function testReadsAFull3840x2160ObjectInLessThan64MbOfMemory(): void
    {
        $object  = ["id" => 0, "window" => 0, "x" => 0, "y" => 0];
        $content = (new PgsFixtureWriter())
            ->presentation(90000, 3840, 2160, 1, PgsFixtureWriter::STATE_EPOCH_START, 0, [$object])
            ->palette(90000, 0, 0, PgsFixtures::PALETTE)
            ->object(90000, 0, 0, 3840, 2160, str_repeat("\1", 3840 * 1080) . str_repeat("\3", 3840 * 1080))
            ->end(90000)
            ->bytes();

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $cues   = (new PgsParser())->parse($content, new ReadOptions())->getCues();

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before);
        ["palette" => $palette, "indexes" => $indexes] = PngDecoder::decodeIndexed(CueImage::fromCue($cues[0])->png);
        $this->assertSame([self::WHITE, self::YELLOW_BT709], [$palette[ord($indexes[0])], $palette[ord($indexes[-1])]]);
    }


    public function testLenientModeKeepsTheCuesBeforeACutOffSegment(): void
    {
        $content = substr(file_get_contents(self::DIR . "shapes_576p.sup"), 0, 1400);

        $subtitle = (new PgsParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertSame([[0.5, 3.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertEquals([new ParseWarning("The segment header at byte 1398 is cut off.", null, 8, [], ParseWarningAction::Skipped)],
                            $subtitle->getParseWarnings());

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The segment header at byte 1398 is cut off.");
        (new PgsParser())->parse($content, new ReadOptions());
    }


    public function testACutOffDisplaySetEndsTheShownCueAndShowsNothing(): void
    {
        $object  = ["id" => 0, "window" => 0, "x" => 0, "y" => 0];
        $content = (new PgsFixtureWriter())
            ->presentation(90000, 1920, 1080, 1, PgsFixtureWriter::STATE_EPOCH_START, 0, [$object])
            ->palette(90000, 0, 0, PgsFixtures::PALETTE)
            ->object(90000, 0, 0, 4, 2, "\1\1\2\2\3\3\3\3")
            ->end(90000)
            ->presentation(180000, 1920, 1080, 2, PgsFixtureWriter::STATE_EPOCH_START, 0, [$object])
            ->palette(180000, 0, 0, PgsFixtures::PALETTE)
            ->object(180000, 0, 0, 4, 2, "\3\3\3\3\1\1\2\2")
            ->end(180000)
            ->bytes();

        $cues = (new PgsParser())->parse(substr($content, 0, -20), new ReadOptions(lenient: true))->getCues();

        $this->assertSame([[1.0, 2.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $cues));
    }


    public function testLastCueDurationIsAnOption(): void
    {
        $cues = $this->parseFile("shapes_1080p.sup", new ReadOptions(lastCueDuration: 1.5))->getCues();

        $this->assertSame(21.5, end($cues)->getEnd());
    }


    public function testALastCueDurationOf0EndsTheLastCueAtItsStart(): void
    {
        $cues = $this->parseFile("shapes_1080p.sup", new ReadOptions(lastCueDuration: 0))->getCues();

        $this->assertSame(20.0, end($cues)->getEnd());
    }


    public function testRecognizesTextWithAnOcrEngine(): void
    {
        $subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents(self::DIR . "shapes_1080p.sup"));
        $engine   = new FakeOcrEngine(["Next stop: Main Station"]);

        $subtitle->recognizeText($engine, "eng");

        $this->assertCount(6, $engine->calls);
        $this->assertSame([640, 940, 640, 90], [$engine->calls[0]["image"]->x, $engine->calls[0]["image"]->y,
                                                $engine->calls[0]["image"]->width, $engine->calls[0]["image"]->height]);
        $this->assertTrue($engine->calls[2]["image"]->forced);
        $this->assertStringStartsWith("1\n00:00:01,000 --> 00:00:03,500\nNext stop: Main Station\n\n" .
                                      "2\n00:00:05,000 --> 00:00:07,000\nNext stop: Main Station\n\n",
                                      StringHelpers::removeUtf8Bom($subtitle->toString(Format::SubRip)));
    }


    public function testANewEpochDropsTheObjectsOfTheOldEpoch(): void
    {
        $writer = (new PgsFixtureWriter())
            ->presentation(90000, 1920, 1080, 1, PgsFixtureWriter::STATE_EPOCH_START, 0, [["id" => 0, "window" => 0, "x" => 0, "y" => 0]])
            ->palette(90000, 0, 0, PgsFixtures::PALETTE)
            ->object(90000, 0, 0, 4, 2, "\1\1\2\2\3\3\3\3")
            ->end(90000)
            ->presentation(180000, 1920, 1080, 2, PgsFixtureWriter::STATE_EPOCH_START, 0, [["id" => 0, "window" => 0, "x" => 0, "y" => 0]])
            ->palette(180000, 0, 0, PgsFixtures::PALETTE)
            ->end(180000);

        $cues = (new PgsParser())->parse($writer->bytes(), new ReadOptions())->getCues();

        $this->assertCount(1, $cues);
        $this->assertSame([1.0, 2.0], [$cues[0]->getStart(), $cues[0]->getEnd()]);
        $this->assertSame(Alignment::TopCenter, $cues[0]->getAlignment());
    }


    public function testClipsObjectsToTheirWindow(): void
    {
        $writer = (new PgsFixtureWriter())
            ->presentation(0, 720, 576, 1, PgsFixtureWriter::STATE_EPOCH_START, 0, [["id" => 0, "window" => 3, "x" => 100, "y" => 500]])
            ->windows(0, [3 => [101, 500, 2, 1]])
            ->palette(0, 0, 0, PgsFixtures::PALETTE)
            ->object(0, 0, 0, 4, 2, "\1\2\3\4\1\1\1\1")
            ->end(0);

        $image = CueImage::fromCue((new PgsParser())->parse($writer->bytes(), new ReadOptions())->getCues()[0]);

        $this->assertSame([101, 500, 2, 1], [$image->x, $image->y, $image->width, $image->height]);
        $this->assertSame(self::BLACK, $this->pixelAt($image, 0, 0));
        $this->assertSame(self::YELLOW_BT601, $this->pixelAt($image, 1, 0));
    }


    public function testParsesAnEmptyFile(): void
    {
        $this->assertSame([], (new PgsParser())->parse("", new ReadOptions())->getCues());
    }


    public static function brokenFiles(): array
    {
        $valid = (new PgsFixtureWriter())->end(0)->bytes();

        return [
            "no magic bytes"       => ["XG" . substr($valid, 2), "The segment at byte 0 does not start with the PG magic bytes."],
            "garbage after a set"  => [$valid . "junk", "The segment at byte 13 does not start with the PG magic bytes."],
            "cut off header"       => ["PG\0\0", "The segment header at byte 0 is cut off."],
            "cut off segment data" => [substr((new PgsFixtureWriter())->palette(0, 0, 0, PgsFixtures::PALETTE)->bytes(), 0, -1),
                                       "The segment at byte 0 has 27 bytes of data, but the file ends before."],
            "short bitmap"         => [(new PgsFixtureWriter())
                                           ->presentation(0, 720, 576, 1, PgsFixtureWriter::STATE_EPOCH_START, 0,
                                                          [["id" => 7, "window" => 0, "x" => 0, "y" => 0]])
                                           ->palette(0, 0, 0, PgsFixtures::PALETTE)
                                           ->segment(0, 0x15, "\0\7\0\xC0\0\0\7\0\4\0\2\1\1\0\0")
                                           ->end(0)
                                           ->bytes(),
                                       "Object 7 at 0 s has 2 pixels of run-length data, but its size of 4x2 needs 8."],
        ];
    }


    #[DataProvider("brokenFiles")]
    public function testThrowsOnBrokenFiles(string $content, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new PgsParser())->parse($content, new ReadOptions());
    }
}
