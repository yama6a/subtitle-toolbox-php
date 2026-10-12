<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Application;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Sync\ReferenceSyncOptions;

class RobustnessLimitsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function runApplication(array $arguments): array
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        $code    = (new Application(...$streams))->run(["stbox", ...$arguments]);
        rewind($streams[1]);
        rewind($streams[2]);

        return [$code, stream_get_contents($streams[1]), stream_get_contents($streams[2])];
    }


    private static function pgsSegment(int $type, string $data): string
    {
        return "PG" . pack("NNCn", 0, 0, $type, strlen($data)) . $data;
    }


    public function testReferenceSyncOptionsAcceptTheLimitsAndRejectMore(): void
    {
        $reference = new Subtitle();
        $options   = new ReferenceSyncOptions($reference, 79200, 86400, maxSplits: 10);
        $this->assertSame([79200.0, 86400.0, 10], [$options->minOffset, $options->maxOffset, $options->maxSplits]);

        $calls = [
            "The maximum number of splits must be from 0 to 10, got 11."                   => fn () => new ReferenceSyncOptions($reference, maxSplits: 11),
            "The maximum offset must be from -86400 to 86400 seconds, got 86401."           => fn () => new ReferenceSyncOptions($reference, 0, 86401),
            "The minimum offset must be from -86400 to 86400 seconds, got -INF."            => fn () => new ReferenceSyncOptions($reference, -INF),
            "The minimum and the maximum offset must be at most 7200 seconds apart, got -3600 and 3601." => fn () => new ReferenceSyncOptions($reference, -3600, 3601),
            "The split penalty must be a finite number of 0 or more, got NAN."           => fn () => new ReferenceSyncOptions($reference, splitPenalty: NAN),
        ];
        foreach ($calls as $message => $call) {
            try {
                $call();
                $this->fail("No exception for: $message");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringEndsWith($message, $exception->getMessage());
            }
        }
    }


    public function testSyncCommandRejectsHugeLimitsAsAUsageError(): void
    {
        $sync = ["sync", self::FILES . "cli/trip.srt", "--reference", self::FILES . "cli/shop.vtt", "-o", "-"];

        $this->assertSame([2, "", "Error: The option --max-splits needs a whole number from 0 to 10, got \"99999999999999999999\".\n" .
                                  "Run \"stbox help sync\" for the usage.\n"],
                          self::runApplication([...$sync, "--max-splits", "99999999999999999999"]));
        [$code, , $error] = self::runApplication([...$sync, "--max-offset", "100000"]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString("The maximum offset must be from -86400 to 86400 seconds", $error);
    }


    public function testTrackNameThatIsNotUtf8KeepsItsValidCharacters(): void
    {
        $track = new SubtitleTrack(ContainerFormat::Matroska, 3, "S_TEXT/UTF8", Format::SubRip, "fr", "Fran\xE7ais", true, false);

        $this->assertSame("S_TEXT/UTF8, fr, \"Fran\u{FFFD}ais\", default", $track->describe());
    }


    public function testFixListPrintsTextThatIsNotUtf8WithReplacementCharacters(): void
    {
        $path = self::FILES . "fixing/latin1_unclosed_italic.srt";

        [$code, , $error] = self::runApplication(["convert", $path, "--encoding", "UTF-8", "--errors-fix", "--errors-list-fixes", "--to", "srt"]);

        $this->assertSame(0, $code);
        $this->assertSame("$path: cue 1: unbalancedTags: \"<i>Caf\u{FFFD} au lait.\" -> \"<i>Caf\u{FFFD} au lait.</i>\"\n", $error);
    }


    public function testHlsSegmentsOfAVeryLongCueNeedNoMemoryForAllSegments(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->addCue(new SubtitleCue(10, 3599999, "Long"));

        $hls = HlsWebVttSegmenter::segment($subtitle);

        $this->assertSame(600000, $hls->getSegmentCount());
        $first = [];
        foreach ($hls->getSegments() as $name => $vtt) {
            $first[$name] = $vtt;
            if (count($first) === 2) {
                break;
            }
        }
        $this->assertSame(["sub0.vtt", "sub1.vtt"], array_keys($first));
        $this->assertStringEndsWith("\n\n1\n00:00:01.000 --> 00:00:02.000\nHello\n", $first["sub0.vtt"]);
        $this->assertStringEndsWith("\n\n2\n00:00:10.000 --> 999:59:59.000\nLong\n", $first["sub1.vtt"]);
    }


    public function testHlsMemoryDoesNotGrowWithTheNumberOfSegments(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(10, 360000, "Long"));
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $count = 0;
        foreach (HlsWebVttSegmenter::segment($subtitle)->getSegments() as $vtt) {
            $count++;
        }

        $this->assertSame(60000, $count);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
    }


    public function testImagesLargerThanTheLimitThrowBeforeTheyAreDecoded(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . pack("N", 13) . "IHDR" . pack("NNCCCCC", 8000, 1, 8, 6, 0, 0, 0) . "\0\0\0\0";
        foreach ([fn () => new CueImage("png", 0, 0, 7681, 1, 1920, 1080), fn () => new CueImage("png", 0, 0, 3841, 2160, 3840, 2160),
                  fn () => PngDecoder::decode($png)] as $call) {
            try {
                $call();
                $this->fail("No exception");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString("pixels is larger than the limit of 7680 pixels per side and 8294400 pixels in total.",
                                                  $exception->getMessage());
            }
        }
        $this->assertSame(7680, (new CueImage("png", 0, 0, 7680, 1080, 7680, 1080))->width);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Cannot read object 7: an image of 8000x1 pixels is larger than the limit");
        (new PgsParser())->parse(self::pgsSegment(0x15, "\0\7\0\xC0\0\0\4" . pack("nn", 8000, 1)), new ReadOptions());
    }


    public function testA3840x2160ImageIsAccepted(): void
    {
        $image = new CueImage("png", 0, 0, 3840, 2160, 3840, 2160);

        $this->assertSame([3840, 2160], [$image->width, $image->height]);
        $this->assertNull(CueImage::sizeLimitError(3840, 2160));
        $this->assertNotNull(CueImage::sizeLimitError(3840, 2161));
    }


    public function testPgsRunsAfterTheLastPixelOfAnObjectTakeNoMemory(): void
    {
        $runs = str_repeat("\0\x7F\xFF\1", 5000);
        $pgs  = self::pgsSegment(0x16, "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\7\0\0\0\0\0\0") .
                self::pgsSegment(0x14, "\0\0\1\x10\x80\x80\xFF") .
                self::pgsSegment(0x15, "\0\7\0\xC0" . substr(pack("N", 4 + strlen($runs)), 1) . "\0\4\0\2" . $runs) .
                self::pgsSegment(0x80, "");
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $cues = (new PgsParser())->parse($pgs, new ReadOptions())->getCues();

        $this->assertSame([4, 2], [CueImage::fromCue($cues[0])->width, CueImage::fromCue($cues[0])->height]);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }
}
