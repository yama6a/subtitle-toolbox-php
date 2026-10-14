<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\MpegTs\AribFixtureWriter as W;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require_once __DIR__ . "/../../files/mpegts/generator/AribFixtures.php";

class AribCaptionReaderTest extends TestCase
{
    private const DIR = __DIR__ . "/../../files/mpegts/";


    public static function fixtures(): array
    {
        return array_map(fn (string $method): array => [$method], AribFixtures::FILES);
    }


    #[DataProvider("fixtures")]
    public function testFixturesMatchTheGenerator(string $method): void
    {
        $this->assertSame(file_get_contents(self::DIR . array_search($method, AribFixtures::FILES, true)), AribFixtures::$method());
    }


    public function testListsACaptionStreamWithTheLanguageOfItsManagementData(): void
    {
        $this->assertSame(
            [[ContainerFormat::MpegTs, 304, "arib_caption", Format::SubRip, "jpn", null, false, false]],
            array_map(fn (SubtitleTrack $t): array => [$t->container, $t->number, $t->codecId, $t->format, $t->language, $t->name, $t->default, $t->forced],
                      MpegTsReader::open(self::DIR . "arib_japanese.ts")->getSubtitleTracks()),
        );
    }


    public function testExtractsJapaneseCaptionsWithFuriganaGaijiAndAlignment(): void
    {
        $subtitle = MpegTsReader::open(self::DIR . "arib_japanese.ts")->extract(304);

        $this->assertSame(
            [
                [1.0, 4.0, ["<ruby>今日<rt>きょう</rt></ruby>はいい<ruby>天気<rt>てんき</rt></ruby>です。", "<font color=\"#ffff00\">明日は雨でしょう。</font>"],
                 Alignment::BottomCenter],
                [4.5, 6.0, ["｟拍手｠", "凜とした声〓"], Alignment::BottomCenter],
                [6.0, 8.0, ["はい、OKＯＫ"], Alignment::TopLeft],
            ],
            self::cues($subtitle),
        );
        $this->assertSame([Format::SubRip, "jpn"], [$subtitle->findFormat(), $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE)]);
        $this->assertSame(
            [["At 4.500 s: The glyph of the character 0x24 of the set DRCS-1 (16x18 dots, MD5 " . md5(hex2bin(W::GLYPH_FRAME))
              . ") has no known character. The reader wrote U+3013 for it.", ParseWarningAction::Repaired]],
            array_map(fn (ParseWarning $warning): array => [$warning->message, $warning->action], $subtitle->getParseWarnings()),
        );
    }


    public function testLoadReadsBrazilianCaptionsFromAnM2tsFile(): void
    {
        $subtitle = Subtitle::load(self::DIR . "arib_brazilian.m2ts");

        $this->assertSame(
            [
                [1.0, 3.5, ["\u{266A} Ação e emoção \u{266A}", "<font color=\"#00ffff\">Olá, você está aí?</font>"], Alignment::BottomCenter],
                [4.0, 5.5, ["Até amanhã, João."], Alignment::BottomCenter],
            ],
            self::cues($subtitle),
        );
        $this->assertSame("por", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testSkipsBytesBetweenM2tsPacketsToTheNextSyncByte(): void
    {
        $file = AribFixtures::brazilian();
        $file = substr($file, 0, 3 * 192) . "\x00\x01\x02" . substr($file, 3 * 192);

        $this->assertSame(self::cues(Subtitle::fromString(AribFixtures::brazilian())), self::cues(MpegTsReader::open(self::stream($file))->extract(304)));
    }


    public function testNumbersAStreamWhosePidATeletextPageHasByThePidToo(): void
    {
        $writer = new TsFixtureWriter();
        $writer->tables(1, AribFixtures::PMT_PID, 0x0200, [
            [0x0200, 0x06, TsFixtureWriter::teletextDescriptor([["jpn", TsFixtureWriter::SUBTITLE_PAGE, 304]])],
            [AribFixtures::CAPTION_PID, 0x06, "\x52\x01\x30\xFD\x03\x00\x08\x3D"],
        ]);
        $writer->privatePes(AribFixtures::CAPTION_PID, 90000, W::statement(W::text(W::CS . W::setup() . W::position(6, 7) . W::kanji("字"))));

        $reader = MpegTsReader::open(self::stream($writer->bytes()));

        $this->assertSame([[304, "dvb_teletext"], [304000, "arib_caption"]],
                          array_map(fn (SubtitleTrack $track): array => [$track->number, $track->codecId], $reader->getSubtitleTracks()));
        $this->assertSame([[0.0, 5.0, ["字"], Alignment::BottomCenter]], self::cues($reader->extract(304000)));
        $this->assertSame("und", $reader->getSubtitleTracks()[1]->language);
    }


    public function testListsOnlyCaptionStreamsOfProfileA(): void
    {
        $writer = new TsFixtureWriter();
        $writer->tables(1, AribFixtures::PMT_PID, 0x0200, [
            // Superimposed text with the component tag 0x38, and a caption tag with the data component of one-seg captions.
            [0x0131, 0x06, "\x52\x01\x38\xFD\x03\x00\x08\x3D"],
            [0x0132, 0x06, "\x52\x01\x30\xFD\x03\x00\x12\x3D"],
            [0x0133, 0x06, "\x52\x01\x30"],
        ]);
        $writer->videoPes(0x0200, 90000);

        $this->assertSame([], MpegTsReader::open(self::stream($writer->bytes()))->getSubtitleTracks());
    }


    /**
     * @return list<array{float, float, list<string>, Alignment}>
     */
    private static function cues(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->getAlignment()], $subtitle->getCues());
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
