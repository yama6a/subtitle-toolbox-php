<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require_once __DIR__ . "/../../files/mpegts/generator/TsFixtures.php";

class MpegTsReaderTest extends TestCase
{
    private const DIR = __DIR__ . "/../../files/mpegts/";

    private const PID = TsFixtures::TELETEXT_PID;


    public static function fixtures(): array
    {
        return array_map(fn (string $method): array => [$method], TsFixtures::FILES);
    }


    #[DataProvider("fixtures")]
    public function testFixturesMatchTheGenerator(string $method): void
    {
        $this->assertSame(file_get_contents(self::DIR . array_search($method, TsFixtures::FILES, true)), TsFixtures::$method());
    }


    public function testListsTheSubtitlePagesOfTheTeletextDescriptor(): void
    {
        $tracks = MpegTsReader::open(self::DIR . "teletext.ts")->getSubtitleTracks();

        $this->assertSame(
            [
                [ContainerFormat::MpegTs, 888, "dvb_teletext", Format::SubRip, "deu", null, false, false],
                [ContainerFormat::MpegTs, 777, "dvb_teletext", Format::SubRip, "deu", "hearing impaired", false, false],
                [ContainerFormat::MpegTs, 801, "dvb_teletext", Format::SubRip, "eng", null, false, false],
                [ContainerFormat::MpegTs, 802, "dvb_teletext", Format::SubRip, "pol", null, false, false],
                [ContainerFormat::MpegTs, 301, "dvb_teletext", Format::SubRip, "eng", null, false, false],
            ],
            array_map(fn (SubtitleTrack $t): array => [$t->container, $t->number, $t->codecId, $t->format, $t->language, $t->name, $t->default, $t->forced],
                      $tracks),
        );
    }


    public function testExtractsTheStatesOfAPageAsCuesFromTheFirstPtsOfTheProgram(): void
    {
        $subtitle = MpegTsReader::open(self::DIR . "teletext.ts")->extract(888);

        $this->assertSame(
            [
                [0.5, 3.0, ["Der Zug fährt um acht Uhr.", "<font color=\"#ffff00\">- Wir müssen uns beeilen!</font>"]],
                [3.0, 5.0, ["<font color=\"#00ffff\">- Ja?</font> - Nein.", "<font color=\"#00ff00\">Grüße aus der Straße.</font>"]],
                [6.0, 10.0, ["Es ist 20 Uhr."]],
                [11.0, 12.0, ["Ende."]],
            ],
            self::cues($subtitle),
        );
        $this->assertSame([Format::SubRip, "deu"], [$subtitle->findFormat(), $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE)]);
    }


    public function testKeepsTheRowsOfAPageWithoutTheEraseFlag(): void
    {
        $this->assertSame(
            [[1.0, 2.0, ["(Leise Musik)"]], [2.0, 4.0, ["(Leise Musik)", "Hallo?"]]],
            self::cues(MpegTsReader::open(self::DIR . "teletext.ts")->extract(777)),
        );
    }


    public function testTakesTheCharacterSetFromX28M29OrTheLanguage(): void
    {
        $reader = MpegTsReader::open(self::DIR . "teletext.ts");

        $this->assertSame(
            [[[1.5, 4.5, ["Привет, мир."]]], [[2.5, 5.5, ["Dzień dobry, Łódź!"]]], [[6.0, 9.0, ["Καλημέρα"]]]],
            [self::cues($reader->extract(801)), self::cues($reader->extract(802)), self::cues($reader->extract(301))],
        );
    }


    public function testALastPageWithoutALaterPacketLastsTheLastCueDuration(): void
    {
        $writer = self::writer([["eng", TsFixtureWriter::SUBTITLE_PAGE, 888]]);
        $writer->teletextPes(self::PID, 90000, [TsFixtureWriter::header(888, true), TsFixtureWriter::row(888, 22, "\x0B\x0BBye.")]);

        $this->assertSame([[0.0, 2.5, ["Bye."]]], self::cues(MpegTsReader::open(self::stream($writer->bytes()))->extract(888, new ReadOptions(lastCueDuration: 2.5))));
    }


    public function testCountsTimesAcrossThePtsWrap(): void
    {
        $writer = self::writer([["eng", TsFixtureWriter::SUBTITLE_PAGE, 888]]);
        $writer->teletextPes(self::PID, (1 << 33) - 90000, [TsFixtureWriter::header(888, true), TsFixtureWriter::row(888, 22, "\x0B\x0BBefore."),
                                                           TsFixtureWriter::fillingHeader(8)]);
        $writer->teletextPes(self::PID, 90000, [TsFixtureWriter::header(888, true), TsFixtureWriter::fillingHeader(8)]);

        $this->assertSame([[0.0, 2.0, ["Before."]]], self::cues(MpegTsReader::open(self::stream($writer->bytes()))->extract(888)));
    }


    public function testAHeaderWithTwoBitErrorsEndsThePageOfItsMagazine(): void
    {
        $damaged    = TsFixtureWriter::header(850, true);
        $damaged[3] = chr(ord($damaged[3]) ^ 0x03);
        $writer     = self::writer([["eng", TsFixtureWriter::SUBTITLE_PAGE, 888]]);
        $writer->teletextPes(self::PID, 90000, [TsFixtureWriter::header(888, true), TsFixtureWriter::row(888, 21, "\x0B\x0BKept."), $damaged,
                                                TsFixtureWriter::row(850, 22, "\x0B\x0BOther page.")]);
        $writer->teletextPes(self::PID, 180000, [TsFixtureWriter::fillingHeader(8)]);

        $this->assertSame([[0.0, 1.0, ["Kept."]]], self::cues(MpegTsReader::open(self::stream($writer->bytes()))->extract(888)));
    }


    public function testNumbersAPageThatAnEarlierStreamHasByThePidToo(): void
    {
        $writer = new TsFixtureWriter();
        $writer->tables(1, TsFixtures::PMT_PID, self::PID, [
            [self::PID, 0x06, TsFixtureWriter::teletextDescriptor([["deu", TsFixtureWriter::SUBTITLE_PAGE, 150]])],
            [0x0201, 0x06, TsFixtureWriter::teletextDescriptor([["fra", TsFixtureWriter::SUBTITLE_PAGE, 150]])],
        ]);
        $writer->teletextPes(0x0201, 90000, [TsFixtureWriter::header(150, true), TsFixtureWriter::row(150, 22, "\x0B\x0BBonjour.")]);
        $reader = MpegTsReader::open(self::stream($writer->bytes()));

        $this->assertSame([150, 513150], array_map(fn (SubtitleTrack $track): int => $track->number, $reader->getSubtitleTracks()));
        $this->assertSame([[0.0, 5.0, ["Bonjour."]]], self::cues($reader->extract(513150)));
    }


    public function testListsTheDvbSubtitleServicesOfTheSubtitlingDescriptor(): void
    {
        $this->assertSame(
            [[257, "dvb_subtitle", Format::Pgs, "deu", null], [258, "dvb_subtitle", Format::Pgs, "eng", "hearing impaired"]],
            array_map(fn (SubtitleTrack $t): array => [$t->number, $t->codecId, $t->format, $t->language, $t->name],
                      MpegTsReader::open(self::DIR . "dvb_subtitles.ts")->getSubtitleTracks()),
        );
    }


    /**
     * ffmpeg encodes the PGS and VobSub fixtures in dvb_subtitles.ts. Its PGS decoder ignores cropping, so the PGS cues from
     * the fifth on differ, and its VobSub decoder crops transparent borders and moves some times.
     */
    public function testDecodesThePixelsAndTimesOfTheSourceSubtitles(): void
    {
        $reader = MpegTsReader::open(self::DIR . "dvb_subtitles.ts");
        $pgs    = Subtitle::fromString(file_get_contents(self::DIR . "../pgs/shapes_1080p.sup"), Format::Pgs)->getCues();
        $vobsub = Subtitle::fromString(file_get_contents(self::DIR . "../vobsub/two-tracks-pal.sub"), Format::VobSub, new ReadOptions(
            formats: [new VobSubReadOptions(file_get_contents(self::DIR . "../vobsub/two-tracks-pal.idx"))],
        ))->getCues();

        foreach (array_slice($reader->extract(257)->getCues(), 0, 4) as $index => $cue) {
            $this->assertSame([$pgs[$index]->getStart(), $pgs[$index]->getEnd()], [$cue->getStart(), $cue->getEnd()]);
            $this->assertSame([], self::pixelDifferences(CueImage::fromCue($pgs[$index]), CueImage::fromCue($cue)), "cue $index");
        }
        foreach ($reader->extract(258)->getCues() as $index => $cue) {
            $this->assertSame([], self::pixelDifferences(CueImage::fromCue($vobsub[$index]), CueImage::fromCue($cue)), "cue $index");
        }
    }


    public function testNumbersADvbSubtitleServiceByItsPidWithoutTakingTheNumberOfATeletextPage(): void
    {
        $writer = new TsFixtureWriter();
        $writer->tables(1, TsFixtures::PMT_PID, self::PID, [
            [150, 0x06, TsFixtureWriter::subtitlingDescriptor([["deu", 0x10, 1, 1], ["eng", 0x24, 2, 2]])],
            [self::PID, 0x06, TsFixtureWriter::teletextDescriptor([["deu", TsFixtureWriter::SUBTITLE_PAGE, 150]])],
            [0x0801, 0x06, TsFixtureWriter::subtitlingDescriptor([["fra", 0x14, 1, 1]])],
        ]);
        $region = "\x0F\x11\0\2\0\x10" . pack("CCnnCCCCnnn", 0, 0x0F, 2, 1, 0x68, 0, 0, 0, 1, 0, 0);
        $writer->subtitlePes(150, 90000, "\x0F\x10\0\2\0\x08\0\x0B\0\xFF\0\0\0\0" . $region . "\x0F\x13\0\2\0\x0D\0\1\0\0\5\0\0\x11\x11\x00\x00\xF0");
        $reader = MpegTsReader::open(self::stream($writer->bytes()));

        $this->assertSame([[150, "deu", null], [150000, "deu", null], [150001, "eng", "hearing impaired"], [2049, "fra", null]],
                          array_map(fn (SubtitleTrack $t): array => [$t->number, $t->language, $t->name], $reader->getSubtitleTracks()));
        $this->assertSame([[0.0, 5.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $reader->extract(150001)->getCues()));
        $this->assertSame([], $reader->extract(150000)->getCues());
    }


    public function testSkipsBytesBetweenPacketsToTheNextSyncByte(): void
    {
        $file = TsFixtures::onePage();
        $file = substr($file, 0, 3 * 188) . "\x00\x01\x02" . substr($file, 3 * 188);

        $this->assertSame(self::cues(Subtitle::fromString(TsFixtures::onePage())), self::cues(MpegTsReader::open(self::stream($file))->extract(888)));
    }


    public function testLoadReadsAFileWithOneSubtitlePage(): void
    {
        $this->assertSame(
            [[1.0, 3.5, ["The ferry leaves at noon."]], [3.5, 6.0, ["Tickets cost 4 euros.", "<font color=\"#ffff00\">- Cash only?</font>"]]],
            self::cues(Subtitle::load(self::DIR . "one_page.ts")),
        );
    }


    public function testThrowsForAPageInTheArabicSet(): void
    {
        $writer = self::writer([["ara", TsFixtureWriter::SUBTITLE_PAGE, 888]]);
        $writer->teletextPes(self::PID, 90000, [TsFixtureWriter::header(888, true, 7), TsFixtureWriter::designation(888, 28, 10, 7),
                                                TsFixtureWriter::row(888, 22, "\x0B\x0BABC")]);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Teletext page 888 uses the Arabic or Hebrew character set. The reader reads Latin, Cyrillic and Greek pages.");

        MpegTsReader::open(self::stream($writer->bytes()))->extract(888);
    }


    public function testThrowsForANumberThatIsNotASubtitlePage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The file has no subtitle track with the number 100.");

        MpegTsReader::open(self::DIR . "teletext.ts")->trackFormat(100);
    }


    public function testThrowsForAFileThatIsNotMpegTs(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file is not an MPEG-TS file.");

        MpegTsReader::open(self::DIR . "../mp4/one_track.mp4");
    }


    /**
     * @param list<array{string, int, int}> $pages
     */
    private static function writer(array $pages): TsFixtureWriter
    {
        $writer = new TsFixtureWriter();
        $writer->tables(1, TsFixtures::PMT_PID, self::PID, [[self::PID, 0x06, TsFixtureWriter::teletextDescriptor($pages)]]);

        return $writer;
    }


    /**
     * Returns the screen positions where the images differ by more than 1 in a channel. Transparent pixels match any color.
     *
     * @return list<string>
     */
    private static function pixelDifferences(CueImage $expected, CueImage $actual): array
    {
        $screen = [];
        foreach ([$expected, $actual] as $side => $image) {
            foreach (PngDecoder::decode($image->png)["pixels"] as $index => $pixel) {
                $screen[($image->y + intdiv($index, $image->width)) . "," . ($image->x + $index % $image->width)][$side] = $pixel;
            }
        }

        $differences = [];
        foreach ($screen as $position => $pixels) {
            [$first, $second] = [$pixels[0] ?? 0, $pixels[1] ?? 0];
            foreach ([24, 16, 8, 0] as $shift) {
                if (($first & 0xFF) + ($second & 0xFF) > 0 && abs(($first >> $shift & 0xFF) - ($second >> $shift & 0xFF)) > 1) {
                    $differences[] = $position;
                    break;
                }
            }
        }

        return $differences;
    }


    /**
     * @return list<array{float, float, list<string>}>
     */
    private static function cues(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues());
    }


    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen("php://temp", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
