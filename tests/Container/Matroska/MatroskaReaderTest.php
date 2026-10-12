<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\PgsFixtures;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../../files/mkv/generator/MkvFixtures.php";

class MatroskaReaderTest extends TestCase
{
    private const DIR = __DIR__ . "/../../files/mkv/";


    public static function fixtures(): array
    {
        return array_map(fn (string $method): array => [$method], MkvFixtures::FILES);
    }


    #[DataProvider("fixtures")]
    public function testFixturesMatchTheGenerator(string $method): void
    {
        $this->assertSame(file_get_contents(self::DIR . array_search($method, MkvFixtures::FILES, true)), MkvFixtures::$method());
    }


    public static function producerFiles(): array
    {
        return [
            "mkvmerge S_TEXT/UTF8"      => ["mkvmerge.mkv", 2, "srt"],
            "mkvmerge S_TEXT/ASS"       => ["mkvmerge.mkv", 3, "ass"],
            "ffmpeg S_TEXT/UTF8"        => ["ffmpeg_srt.mkv", 2, "srt"],
            "ffmpeg D_WEBVTT/SUBTITLES" => ["ffmpeg_webvtt.webm", 2, "vtt"],
        ];
    }


    #[DataProvider("producerFiles")]
    public function testExtractsTheTracksOfMkvmergeAndFfmpegFilesAsTheGoldenFiles(string $file, int $track, string $extension): void
    {
        $subtitle = MatroskaReader::open(self::DIR . $file)->extract($track);

        $this->assertStringEqualsFile(self::DIR . "expected/$file.$track.$extension",
                                      $subtitle->toString($subtitle->getFormat(), new WriteOptions(bom: false)));
    }


    public function testReadsTheWebmWebVttCodecsExceptMetadata(): void
    {
        $tracks = "";
        foreach (["SUBTITLES", "CAPTIONS", "DESCRIPTIONS", "METADATA"] as $index => $kind) {
            $tracks .= MkvFixtureWriter::trackEntry(["number" => $index + 1, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "D_WEBVTT/$kind"]);
        }
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::ebmlHeader("webm") . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT,
            MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, $tracks) .
            MkvFixtureWriter::cluster(0, [MkvFixtureWriter::blockGroup(2, 1000, "\r\nline:90%\r\nThe gate closes in five minutes.", 2000)])));
        rewind($stream);
        $mkv = MatroskaReader::open($stream);

        $this->assertSame([Format::WebVtt, Format::WebVtt, Format::WebVtt, null], array_map($mkv->trackFormat(...), [1, 2, 3, 4]));
        $this->assertSame("WEBVTT\n\n1\n00:00:01.000 --> 00:00:03.000 line:90%\nThe gate closes in five minutes.\n",
                          $mkv->extract(2)->toString(Format::WebVtt, new WriteOptions(bom: false)));
    }


    public function testListsOnlyTheSubtitleTracks(): void
    {
        $tracks = MatroskaReader::open(self::DIR . "text_tracks.mkv")->getSubtitleTracks();

        $this->assertSame(
            [
                [ContainerFormat::Matroska, 3, "S_TEXT/UTF8", Format::SubRip, "de", "Deutsch (Forced)", false, true],
                [ContainerFormat::Matroska, 4, "S_TEXT/ASS", Format::Ass, "eng", "English", true, false],
                [ContainerFormat::Matroska, 5, "S_TEXT/WEBVTT", Format::WebVtt, "fre", "Français", false, false],
                [ContainerFormat::Matroska, 6, "S_TEXT/SSA", Format::Ass, "spa", null, false, false],
                [ContainerFormat::Matroska, 7, "S_DVBSUB", null, "ita", null, false, false],
                [ContainerFormat::Matroska, 8, "S_TEXT/UTF8", Format::SubRip, "eng", null, false, false],
            ],
            array_map(fn (SubtitleTrack $t): array => [$t->container, $t->number, $t->codecId, $t->format, $t->language, $t->name, $t->default, $t->forced],
                      $tracks),
        );
    }


    public function testExtractsSubRipWithLanguageAndForcedFlag(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(3);

        $this->assertSame(
            [
                [1.0, 3.5, ["Der Zug nach Hamburg fährt um acht Uhr ab."], true],
                [4.0, 6.0, ["<i>Gleis 4</i>", "Bitte nicht einsteigen."], true],
                [9.8, 12.0, ["Die Bäckerei öffnet um sechs."], true],
                [15.0, 17.25, ["Morgen wird es sonnig", "und warm."], true],
            ],
            $this->cues($subtitle),
        );
        $this->assertSame("de", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testExtractsAssInReadOrderWithTheHeaderFromCodecPrivate(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(4);

        $this->assertSame(
            MkvFixtures::ASS_HEADER .
            "Dialogue: 1,0:00:01.00,0:00:06.00,Sign,,0,0,0,,{\\an8}PLATFORM 4\n" .
            "Dialogue: 0,0:00:01.00,0:00:04.00,Default,Guard,0,0,0,,The train to the coast leaves at eight.\n" .
            "Dialogue: 0,0:00:07.00,0:00:09.00,Default,,0,0,0,,The bakery on the corner\\Nis open {\\i1}every{\\i0} day.\n" .
            "Dialogue: 0,0:00:11.00,0:00:13.50,Default,Guard,0,0,0,,Bring an umbrella, it may rain later.\n",
            $subtitle->toString(Format::Ass, new WriteOptions(bom: false)),
        );
        $this->assertSame(Alignment::TopCenter, $subtitle->getCues()[0]->getAlignment());
        $this->assertFalse($subtitle->getCues()[0]->isForced());
    }


    public function testExtractsSsaAndAddsTheEventsSectionThatCodecPrivateLacks(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(6);

        $this->assertSame(
            [
                [3.0, 5.0, ["El tren sale a las ocho."], false],
                [13.0, 15.5, ["La panadería abre a las seis."], false],
            ],
            $this->cues($subtitle),
        );
        $this->assertStringContainsString(
            "Dialogue: Marked=0,0:00:03.00,0:00:05.00,Default,,0000,0000,0000,,El tren sale a las ocho.",
            $subtitle->toString(Format::Ass),
        );
    }


    public function testExtractsWebVttWithSettingsIdentifiersCommentsAndRelativeTimestamps(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(5);

        $this->assertSame(
            "WEBVTT - Gare\n\nSTYLE\n::cue {\n  color: yellow;\n}\n\nNOTE Fichier écrit pour les tests\n\n" .
            "annonce-1\n00:00:02.000 --> 00:00:05.000 line:10% align:start\nLe train pour Lyon part à huit heures.\n\n" .
            "NOTE Deuxième annonce\n\n" .
            "2\n00:00:06.000 --> 00:00:08.500\nLa boulangerie ouvre à six heures.\nLe pain est <00:00:07.500>encore chaud.\n\n" .
            "3\n00:00:12.000 --> 00:00:14.000\nDemain, il fera beau.\n",
            $subtitle->toString(Format::WebVtt, new WriteOptions(bom: false)),
        );
    }


    public function testBlocksWithoutDurationEndAtTheNextBlockAndTheLastAfterFiveSeconds(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(8);

        $this->assertSame(
            [
                [2.5, 8.0, ["Next stop: Central Station."], false],
                [8.0, 16.0, ["Doors open on the left."], false],
                [16.0, 21.0, ["End of the line."], false],
            ],
            $this->cues($subtitle),
        );
        $this->assertSame("eng", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testTheLastBlockWithoutDurationEndsAtTheRoundedLastCueDuration(): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::ebmlHeader());
        fwrite($stream, MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::info() . MkvFixtureWriter::element(
            MkvFixtureWriter::TRACKS,
            MkvFixtureWriter::trackEntry(["number" => 1, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8"]),
        ) . MkvFixtureWriter::cluster(0, [
            MkvFixtureWriter::blockGroup(1, 1000, "Platform 4.", 1000),
            MkvFixtureWriter::simpleBlock(1, 3000, "Mind the gap."),
        ])));
        rewind($stream);

        $subtitle = MatroskaReader::open($stream)->extract(1, new ReadOptions(lastCueDuration: 1.005));
        fclose($stream);

        $this->assertSame([[1.0, 2.0, ["Platform 4."], false], [3.0, 4.005, ["Mind the gap."], false]], $this->cues($subtitle));
    }


    public static function compressedTracks(): array
    {
        return [
            "zlib"                       => [3, [[1.0, 3.0, ["The ferry leaves at noon."]], [4.0, 6.0, ["Tickets are sold", "at the harbour."]]]],
            "zlib with CodecPrivate"     => [4, [[2.0, 5.0, ["The museum is closed on Mondays."]]]],
            "header stripping"           => [5, [[1.5, 3.5, ["Weather: sunny."]], [5.0, 7.5, ["Weather: cloudy, with light rain."]]]],
            "zlib, then header stripping" => [6, [[3.0, 4.5, ["Bakery: fresh bread at seven."]]]],
        ];
    }


    #[DataProvider("compressedTracks")]
    public function testDecodesContentEncodings(int $track, array $expected): void
    {
        $cues = MatroskaReader::open(self::DIR . "compressed.mkv")->extract($track)->getCues();

        $this->assertSame($expected, array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues));
    }


    public function testReadsUnknownSizesAndTheTimestampScale(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "unknown_sizes.mkv")->extract(2);

        $this->assertSame(
            [
                [1.235, 3.0, ["Tåget till Malmö är försenat."], false],
                [4.0, 5.5, ["Bageriet stänger klockan fem."], false],
                [7.0, 9.0, ["I morgon blir det soligt."], false],
            ],
            $this->cues($subtitle),
        );
    }


    public function testFindsTracksAfterTheClustersThroughTheSeekHead(): void
    {
        $mkv = MatroskaReader::open(self::DIR . "seek_head.mkv");

        $this->assertSame("S_TEXT/WEBVTT", $mkv->getSubtitleTracks()[0]->codecId);
        $this->assertSame([[1.0, 3.0, ["The library opens at nine."], false]], $this->cues($mkv->extract(2)));
    }


    public function testExtractsPgsAsThePgsParserReadsTheSupFile(): void
    {
        $mkv      = MatroskaReader::open(self::DIR . "pgs.mkv");
        $expected = (new PgsParser())->parse(PgsFixtures::shapes1080p(), new ReadOptions())->getCues();
        $toArray  = fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getAllFormatData(), $cue->isForced()];

        $this->assertSame(array_map($toArray, $expected), array_map($toArray, $mkv->extract(3)->getCues()));

        $forced = $mkv->extract(4)->getCues();
        $this->assertSame(
            array_map(fn (array $cue): array => [$cue[0], $cue[1], $cue[2]], array_map($toArray, $expected)),
            array_map(fn (array $cue): array => [$cue[0], $cue[1], $cue[2]], array_map($toArray, $forced)),
        );
        $this->assertSame(array_fill(0, count($forced), true), array_map(fn (SubtitleCue $cue): bool => $cue->isForced(), $forced));
    }


    public function testExtractsVobSubAsTheVobSubParserReadsTheIdxFile(): void
    {
        $mkv      = MatroskaReader::open(self::DIR . "vobsub.mkv");
        $expected = Subtitle::load(__DIR__ . "/../../files/vobsub/two-tracks-pal.idx", Format::VobSub);
        $toArray  = fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getAllFormatData(), $cue->isForced()];
        $subtitle = $mkv->extract(3);

        $this->assertCount(5, $expected);
        $this->assertSame(array_map($toArray, $expected->getCues()), array_map($toArray, $subtitle->getCues()));
        $this->assertSame([Format::VobSub, "eng"], [$subtitle->getFormat(), $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE)]);
    }


    public function testVobSubUnitsWithoutAStopCommandEndAtTheEndOfTheirBlock(): void
    {
        $mkv      = MatroskaReader::open(self::DIR . "vobsub.mkv");
        $expected = array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getAllFormatData()],
                              $mkv->extract(3)->getCues());
        $expected[2][1] = 10.5;
        $expected[4][1] = 21.5;

        $this->assertSame($expected, array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getAllFormatData()],
                                               $mkv->extract(4)->getCues()));
    }


    public function testThrowsForAVobSubTrackWithoutAPalette(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The .idx content has no palette line.");

        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::element(
            MkvFixtureWriter::TRACKS,
            MkvFixtureWriter::trackEntry(["number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_VOBSUB",
                                          "codecPrivate" => "size: 720x576\n"]),
        )));
        rewind($stream);

        MatroskaReader::open($stream)->extract(2);
    }


    public static function textTracks(): array
    {
        return ["S_TEXT/UTF8" => [3], "S_TEXT/ASS" => [4], "S_TEXT/WEBVTT" => [5], "S_TEXT/SSA" => [6], "S_TEXT/UTF8 without duration" => [8]];
    }


    #[DataProvider("textTracks")]
    public function testThrowsForAClusterTimestampOfMoreThan8Bytes(int $track): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The unsigned integer of the element 0xE7 at byte 1562 is longer than 8 bytes or not below 2^63.");

        MatroskaReader::open(self::DIR . "huge_timestamp.mkv")->extract($track);
    }


    public static function hugeTimes(): array
    {
        return [
            "cluster timestamp" => [MkvFixtureWriter::cluster(1 << 40, [MkvFixtureWriter::simpleBlock(2, 0, "x")]), "S_TEXT/UTF8", "1099511627776"],
            "block duration"    => [MkvFixtureWriter::cluster(0, [MkvFixtureWriter::blockGroup(2, 0, "x", 1 << 40)]), "S_TEXT/UTF8", "0"],
            "PGS block"         => [MkvFixtureWriter::cluster(1 << 40, [MkvFixtureWriter::simpleBlock(2, 0, "x")]), "S_HDMV/PGS", "1099511627776"],
        ];
    }


    #[DataProvider("hugeTimes")]
    public function testThrowsForATimeOf100000HoursOrMore(string $cluster, string $codecId, string $time): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessageMatches("/^ParsingException \\(Error #100\\): The block of track 2 at byte \\d+ has the time $time at a TimestampScale " .
                                             "of 1000000 ns\\. Times must be below 100000 hours\\.$/");

        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::element(
            MkvFixtureWriter::TRACKS,
            MkvFixtureWriter::trackEntry(["number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => $codecId]),
        ) . $cluster));
        rewind($stream);

        MatroskaReader::open($stream)->extract(2);
    }


    public static function brokenFiles(): array
    {
        $tracks    = fn (string $entry): string => MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, $entry);
        $encrypted = MkvFixtureWriter::element(0x6240, MkvFixtureWriter::uint(0x5031, 0) . MkvFixtureWriter::uint(0x5032, 1) .
                                                       MkvFixtureWriter::uint(0x5033, 1) . MkvFixtureWriter::element(0x5035, ""));

        return [
            "laced block"                   => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, [MkvFixtureWriter::simpleBlock(2, 0, "\x01\x02ab", MkvFixtureWriter::LACING_XIPH)])),
                "A block of track 2 uses lacing, which subtitle tracks do not use.",
            ],
            "block header too short"        => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, [MkvFixtureWriter::element(0xA3, "\x82\x00")])),
                "The block header at byte 86 is not valid.",
            ],
            "child larger than its parent"  => [
                fn () => self::brokenFile("", $tracks(MkvFixtureWriter::element(0xAE, MkvFixtureWriter::uint(0xD7, 2) . "\x86\x85ab"))),
                "The element at byte 55 does not fit into its parent element.",
            ],
            "unknown size outside Segment and Cluster" => [
                fn () => self::brokenFile("", MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::TRACKS, "")),
                "The element 0x1654AE6B at byte 57 has an unknown size, which only Segment and Cluster may have.",
            ],
            "no Tracks element"             => [
                fn () => self::brokenFile("", MkvFixtureWriter::info()),
                "The file has no Tracks element.",
            ],
            "zlib data that does not inflate" => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, [MkvFixtureWriter::blockGroup(2, 0, "not zlib", 1000)]),
                                          ["encodings" => MkvFixtureWriter::compression(0, 0)]),
                "The zlib data of track 2 is not valid or inflates to more than 64 MB.",
            ],
            "encrypted track"               => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, [MkvFixtureWriter::blockGroup(2, 0, "secret", 1000)]),
                                          ["encodings" => $encrypted]),
                "Track 2 uses encryption or a compression other than zlib and header stripping.",
            ],
            "invalid element header in a cluster" => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, []) . "\x00\x00\x00\x00"),
                "The EBML element header at byte 84 is not valid.",
            ],
            "block data cut by the file end" => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, [MkvFixtureWriter::blockGroup(2, 0, "The ferry leaves at noon.", 1000)]), cut: 10),
                "The element data at byte 92 needs 25 bytes, but the file ends before.",
            ],
            "CodecPrivate of 2^40 bytes"    => [
                fn () => self::brokenFile("", self::hugeCodecPrivate()),
                "The element data at byte 89 needs 1099511627776 bytes, but the file ends before.",
            ],
            "zlib block that inflates past 64 MB" => [
                fn () => self::brokenFile(MkvFixtureWriter::cluster(0, [MkvFixtureWriter::blockGroup(2, 0, self::zlibZeros((64 << 20) + 1), 1000)]),
                                          ["encodings" => MkvFixtureWriter::compression(0, 0)]),
                "The zlib data of track 2 is not valid or inflates to more than 64 MB.",
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

        MatroskaReader::open(fopen("php://output", "wb"));
    }


    /**
     * A Tracks element whose TrackEntry declares a CodecPrivate of 2^40 bytes and holds 3 of them, as in a damaged file.
     */
    private static function hugeCodecPrivate(): string
    {
        $declared = 1 << 40;
        $entry    = MkvFixtureWriter::uint(0xD7, 2) . MkvFixtureWriter::uint(0x83, MkvFixtureWriter::TRACK_SUBTITLE) .
                    MkvFixtureWriter::element(0x86, "S_TEXT/UTF8") . MkvFixtureWriter::id(0x63A2) . MkvFixtureWriter::size($declared) . "abc";
        $entry    = MkvFixtureWriter::id(0xAE) . MkvFixtureWriter::size($declared + strlen($entry)) . $entry;

        return MkvFixtureWriter::id(MkvFixtureWriter::TRACKS) . MkvFixtureWriter::size($declared + strlen($entry)) . $entry;
    }


    /**
     * Compresses $length zero bytes in steps of 1 MB, so the test does not hold the inflated data.
     */
    private static function zlibZeros(int $length): string
    {
        $context    = deflate_init(ZLIB_ENCODING_DEFLATE);
        $compressed = "";
        for ($left = $length; $left > 0; $left -= 1 << 20) {
            $compressed .= deflate_add($context, str_repeat("\0", min($left, 1 << 20)), ZLIB_NO_FLUSH);
        }

        return $compressed . deflate_add($context, "", ZLIB_FINISH);
    }


    /**
     * Extracts track 2 from a file with the given Tracks element, or one S_TEXT/UTF8 track 2 with the given fields, followed by $clusters.
     * $cut removes bytes from the end of the file.
     *
     * @param string|array<string, mixed> $tracks
     */
    private static function brokenFile(string $clusters, string|array $tracks = [], int $cut = 0): Subtitle
    {
        if (is_array($tracks)) {
            $tracks = MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, MkvFixtureWriter::trackEntry($tracks + [
                "number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8",
            ]));
        }
        $file   = MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, $tracks . $clusters);
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, substr($file, 0, strlen($file) - $cut));
        rewind($stream);

        return MatroskaReader::open($stream)->extract(2);
    }


    public function testLeavesAStreamOpen(): void
    {
        $stream = fopen(self::DIR . "seek_head.mkv", "rb");
        $mkv    = MatroskaReader::open($stream);
        $mkv->extract(2);
        unset($mkv);

        $this->assertIsResource($stream);
        fclose($stream);
    }


    public function testThrowsForAnUnsupportedCodec(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Track 7 has the codec S_DVBSUB.");

        MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(7);
    }


    public function testThrowsForAVideoTrack(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(1);
    }


    public function testTrackFormatIsNullForAnUnreadCodecAndThrowsForAVideoTrack(): void
    {
        $mkv = MatroskaReader::open(self::DIR . "text_tracks.mkv");

        $this->assertSame([Format::SubRip, Format::Ass, Format::WebVtt, Format::Ass, null],
                          array_map($mkv->trackFormat(...), [3, 4, 5, 6, 7]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The file has no subtitle track with the number 1.");

        $mkv->trackFormat(1);
    }


    public function testThrowsForBzlibCompression(): void
    {
        $this->expectException(ParsingException::class);

        MatroskaReader::open(self::DIR . "compressed.mkv")->extract(7);
    }


    public function testThrowsForAFileThatIsNotMatroska(): void
    {
        $this->expectException(ParsingException::class);

        MatroskaReader::open(__DIR__ . "/../../files/pgs/shapes_576p.sup");
    }


    public function testPeakMemoryStaysBelowFourMegabytesForLargeVideoBlocks(): void
    {
        $stream = tmpfile();
        $video  = str_repeat("\x55", 1 << 20);
        fwrite($stream, MkvFixtureWriter::ebmlHeader());
        fwrite($stream, MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::info() . MkvFixtureWriter::element(
            MkvFixtureWriter::TRACKS,
            MkvFixtureWriter::trackEntry(["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"]) .
            MkvFixtureWriter::trackEntry(["number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8"]),
        )));
        for ($second = 0; $second < 64; $second++) {
            fwrite($stream, MkvFixtureWriter::cluster($second * 1000, [
                MkvFixtureWriter::simpleBlock(1, 0, $video),
                MkvFixtureWriter::blockGroup(2, 100, "The bus leaves in $second minutes.", 800),
            ]));
        }
        unset($video);
        rewind($stream);

        memory_reset_peak_usage();
        $before   = memory_get_usage();
        $subtitle = MatroskaReader::open($stream)->extract(2);
        $peak     = memory_get_peak_usage() - $before;
        fclose($stream);

        $this->assertCount(64, $subtitle->getCues());
        $this->assertLessThan(4 << 20, $peak);
    }


    /**
     * @return list<array{float, float, list<string>, bool}>
     */
    private function cues(Subtitle $subtitle): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->isForced()],
            $subtitle->getCues(),
        );
    }


    public function testExtractSetsTheFormatOfTheTrackCodec(): void
    {
        $text = MatroskaReader::open(self::DIR . "text_tracks.mkv");

        $this->assertSame([Format::SubRip, Format::Ass, Format::WebVtt, Format::Ass],
                          array_map(fn (int $track): ?Format => $text->extract($track)->getFormat(), [3, 4, 5, 6]));
        $this->assertSame(Format::Pgs, MatroskaReader::open(self::DIR . "pgs.mkv")->extract(3)->getFormat());
    }
}
