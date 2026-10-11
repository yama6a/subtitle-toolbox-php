<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Mp4;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require_once __DIR__ . "/../../files/mp4/generator/Mp4Fixtures.php";

class Mp4ReaderTest extends TestCase
{
    private const DIR = __DIR__ . "/../../files/mp4/";


    public static function fixtures(): array
    {
        return array_map(fn (string $method): array => [$method], Mp4Fixtures::FILES);
    }


    #[DataProvider("fixtures")]
    public function testFixturesMatchTheGenerator(string $method): void
    {
        $this->assertSame(file_get_contents(self::DIR . array_search($method, Mp4Fixtures::FILES, true)), Mp4Fixtures::$method());
    }


    public function testListsOnlyTheSubtitleTracks(): void
    {
        $tracks = Mp4Reader::open(self::DIR . "text_tracks.mp4")->getSubtitleTracks();

        $this->assertSame(
            [
                [ContainerFormat::Mp4, 2, "tx3g", Format::SubRip, "eng", "English", true, false],
                [ContainerFormat::Mp4, 3, "tx3g", Format::SubRip, "fr-CA", null, false, true],
                [ContainerFormat::Mp4, 4, "c608", null, "eng", null, false, false],
                [ContainerFormat::Mp4, 5, "enct", null, "deu", null, false, false],
            ],
            array_map(fn (SubtitleTrack $t): array => [$t->container, $t->number, $t->codecId, $t->format, $t->language, $t->name, $t->default, $t->forced],
                      $tracks),
        );
    }


    public function testExtractsTx3gWithGapsUtf16AndLineBreaks(): void
    {
        $subtitle = Mp4Reader::open(self::DIR . "text_tracks.mp4")->extract(2);

        $this->assertSame(
            [
                [1.0, 3.5, ["The tram to the old town leaves from stop 3."]],
                [4.0, 7.0, ["Tickets are sold", "at the machine."]],
                [7.0, 9.0, ["Bold words here."]],
                [10.0, 12.25, ["Café at the corner, 2 € a cup."]],
                [12.25, 14.0, ["Line one", "Line two"]],
            ],
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues()),
        );
        $this->assertSame([Format::SubRip, "eng"], [$subtitle->getFormat(), $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE)]);
    }


    public function testReadsVersion1BoxesCo64Stz2AndTheForcedFlag(): void
    {
        $cues = Mp4Reader::open(self::DIR . "text_tracks.mp4")->extract(3)->getCues();

        $this->assertSame(
            [[1.0, 3.0, ["Le tram part à huit heures."], true], [3.0, 5.5, ["La boulangerie ouvre à six heures."], true]],
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->isForced()], $cues),
        );
    }


    public function testThrowsForAClosedCaptionTrack(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Track 4 has the codec c608. The reader extracts only tx3g.");

        Mp4Reader::open(self::DIR . "text_tracks.mp4")->extract(4);
    }


    public function testThrowsForAnEncryptedTrack(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Track 5 has the codec enct and is encrypted.");

        Mp4Reader::open(self::DIR . "text_tracks.mp4")->extract(5);
    }


    public function testThrowsForATx3gEntryWithASinfBox(): void
    {
        $file = Mp4FixtureWriter::file([
            ["id" => 1, "handler" => "sbtl", "perChunk" => 1, "samples" => [[1000, Mp4FixtureWriter::textSample("Hidden.")]],
             "entry" => Mp4FixtureWriter::tx3gEntry(0, "tx3g", Mp4FixtureWriter::box("sinf", Mp4FixtureWriter::box("frma", "tx3g")))],
        ], true);
        $reader = Mp4Reader::open(self::stream($file));

        $this->assertNull($reader->trackFormat(1));
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Track 1 has the codec tx3g and is encrypted.");

        $reader->extract(1);
    }


    public function testThrowsForAVideoTrack(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The file has no subtitle track with the number 1.");

        Mp4Reader::open(self::DIR . "text_tracks.mp4")->extract(1);
    }


    public function testThrowsForAFileThatIsNotMp4(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file is not an MP4 file.");

        Mp4Reader::open(self::DIR . "../mkv/pgs.mkv");
    }


    public function testThrowsForABoxThatDoesNotFitIntoTheFile(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The box \"moov\" at byte 28 does not fit into its parent box.");

        Mp4Reader::open(self::stream(Mp4FixtureWriter::ftyp() . pack("N", 4096) . "moov"));
    }


    public function testThrowsForASampleOutsideTheFile(): void
    {
        $file = Mp4FixtureWriter::ftyp() . Mp4FixtureWriter::moov([
            ["id" => 1, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "chunks" => [[1 << 30, [[1000, 20]]]]],
        ]);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A sample of track 1 at byte 1073741824 lies outside the file.");

        Mp4Reader::open(self::stream($file))->extract(1);
    }


    public function testThrowsForAOneByteSampleOutsideTheFile(): void
    {
        $file = Mp4FixtureWriter::ftyp() . Mp4FixtureWriter::moov([
            ["id" => 1, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "chunks" => [[1 << 30, [[1000, 1]]]]],
        ]);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A sample of track 1 at byte 1073741824 lies outside the file.");

        Mp4Reader::open(self::stream($file))->extract(1);
    }


    public function testThrowsForASampleCountThatDoesNotFitIntoTheFile(): void
    {
        $started = hrtime(true);
        try {
            Mp4Reader::open(self::DIR . "huge_sample_count.mp4")->extract(2);
            $this->fail("The reader accepted 4294967295 samples in a file of 1458 bytes.");
        } catch (ParsingException $e) {
            $this->assertStringContainsString("The stsz box of track 2 holds 4294967295 samples of 1 bytes, which do not fit into the file.", $e->getMessage());
        }

        $this->assertLessThan(0.5, (hrtime(true) - $started) / 1e9);
    }


    public static function brokenFiles(): array
    {
        return [
            "stts with fewer samples than stsz" => [
                fn () => self::oneTrack(["boxes" => ["stts" => Mp4FixtureWriter::fullBox("stts", 0, 0, pack("N", 0))]])->extract(1),
                "The stts box of track 1 has fewer samples than the sample size box.",
            ],
            "timescale of 0"                    => [
                fn () => self::oneTrack(["timescale" => 0]),
                "Track 1 has a timescale of 0.",
            ],
            "stco with fewer entries than its count" => [
                fn () => self::oneTrack(["boxes" => ["stco" => Mp4FixtureWriter::fullBox("stco", 0, 0, pack("NN", 5, 0))]])->extract(1),
                "The stco box of track 1 holds fewer entries than its count of 5.",
            ],
            "stz2 with a field size of 3 bits"  => [
                fn () => self::oneTrack(["boxes" => ["stsz" => Mp4FixtureWriter::fullBox("stz2", 0, 0, "\0\0\0\x03" . pack("N", 1) . "\0")]])->extract(1),
                "The stz2 box of track 1 is not valid.",
            ],
            "stz2 with fewer sizes than its count" => [
                fn () => self::oneTrack(["boxes" => ["stsz" => Mp4FixtureWriter::fullBox("stz2", 0, 0, "\0\0\0\x10" . pack("N", 2) . "\0\x05")]])->extract(1),
                "The stz2 box of track 1 is not valid.",
            ],
            "no stsz and no stz2"               => [
                fn () => self::oneTrack(["boxes" => ["stsz" => ""]])->extract(1),
                "Track 1 has no stsz or stz2 box.",
            ],
            "no stco and no co64"               => [
                fn () => self::oneTrack(["boxes" => ["stco" => ""]])->extract(1),
                "Track 1 has no stco box.",
            ],
            "tx3g text longer than the sample"  => [
                fn () => self::oneTrack([], pack("n", 50) . "The shop")->extract(1),
                "The text of the sample of track 1 at byte 529 is longer than the sample.",
            ],
            "tx3g text that is not UTF-8"       => [
                fn () => self::oneTrack([], Mp4FixtureWriter::textSample("Caf\xE9"))->extract(1),
                "The text of the sample of track 1 at byte 529 is not valid UTF-8.",
            ],
            "no moov box"                       => [
                fn () => Mp4Reader::open(self::stream(Mp4FixtureWriter::ftyp() . Mp4FixtureWriter::box("mdat", "abc"))),
                "The MP4 file has no moov box.",
            ],
            "mdhd too short"                    => [
                fn () => Mp4Reader::open(self::stream(Mp4FixtureWriter::ftyp() . Mp4FixtureWriter::box("moov", Mp4FixtureWriter::box("trak",
                    Mp4FixtureWriter::fullBox("tkhd", 0, 0, str_repeat("\0", 80)) . Mp4FixtureWriter::box("mdia",
                    Mp4FixtureWriter::fullBox("mdhd", 0, 0, "") . Mp4FixtureWriter::fullBox("hdlr", 0, 0, pack("N", 0) . "sbtl")))))),
                "The box \"mdhd\" at byte 152 is too short.",
            ],
        ];
    }


    #[DataProvider("brokenFiles")]
    public function testThrowsForABrokenFile(\Closure $read, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        $read();
    }


    public function testThrowsForAStreamThatIsNotSeekable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The stream must be seekable.");

        Mp4Reader::open(fopen("php://output", "wb"));
    }


    /**
     * Opens a file with one tx3g track 1 that holds $sample, with the given track fields.
     */
    private static function oneTrack(array $track = [], ?string $sample = null): Mp4Reader
    {
        return Mp4Reader::open(self::stream(Mp4FixtureWriter::file([$track + [
            "id" => 1, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "perChunk" => 1,
            "samples" => [[1000, $sample ?? Mp4FixtureWriter::textSample("The shop is closed.")]],
        ]], true)));
    }


    public function testLeavesAStreamOpen(): void
    {
        $stream = fopen(self::DIR . "one_track.mp4", "rb");
        $reader = Mp4Reader::open($stream);
        $reader->extract(2);
        unset($reader);

        $this->assertIsResource($stream);
        fclose($stream);
    }


    public function testDoesNotReadTheMediaDataOfOtherTracks(): void
    {
        $stream   = tmpfile();
        $video    = str_repeat("\x55", 1 << 20);
        $subtitle = Mp4FixtureWriter::textSample("The bus leaves in 5 minutes.");
        $ftyp     = Mp4FixtureWriter::ftyp();
        fwrite($stream, $ftyp . pack("N", 8 + 64 * strlen($video) + strlen($subtitle)) . "mdat");
        for ($second = 0; $second < 64; $second++) {
            fwrite($stream, $video);
        }
        $subtitleOffset = (int) ftell($stream);
        fwrite($stream, $subtitle);
        unset($video);
        $videoChunks = array_map(fn (int $second): array => [strlen($ftyp) + 8 + ($second << 20), [[1000, 1 << 20]]], range(0, 63));
        fwrite($stream, Mp4FixtureWriter::moov([
            ["id" => 1, "handler" => "vide", "entry" => Mp4FixtureWriter::box("mp4v", str_repeat("\0", 78)), "chunks" => $videoChunks],
            ["id" => 2, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "chunks" => [[$subtitleOffset, [[3000, strlen($subtitle)]]]]],
        ]));
        rewind($stream);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $cues   = Mp4Reader::open($stream)->extract(2)->getCues();

        $this->assertSame(["The bus leaves in 5 minutes."], $cues[0]->getLines());
        $this->assertLessThan(4 << 20, memory_get_peak_usage() - $before);
        fclose($stream);
    }


    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
