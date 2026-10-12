<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class RepeatedBomTest extends TestCase
{
    private const BOM = "\u{FEFF}";


    /**
     * @return array<string, array{Format, string}>
     */
    public static function files(): array
    {
        return [
            "SubRip" => [Format::SubRip, "1\n00:00:01,000 --> 00:00:02,000\nThe ferry leaves.\n\n2\n00:00:03,000 --> 00:00:04,000\nIt is late.\n"],
            "WebVTT" => [Format::WebVtt, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nThe ferry leaves.\n\n00:00:03.000 --> 00:00:04.000\nIt is late.\n"],
            "SBV"    => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n0:00:03.000,0:00:04.000\nIt is late.\n"],
            "CSV"    => [Format::Csv, "start,end,text\n00:00:01.000,00:00:02.000,The ferry leaves.\n00:00:03.000,00:00:04.000,It is late.\n"],
            "ASS"    => [Format::Ass, "[Script Info]\nScriptType: v4.00+\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" .
                                      "Dialogue: 0,0:00:01.00,0:00:02.00,Default,,0,0,0,,The ferry leaves.\nDialogue: 0,0:00:03.00,0:00:04.00,Default,,0,0,0,,It is late.\n"],
        ];
    }


    #[DataProvider("files")]
    public function testTwoLeadingBomsReadLikeOne(Format $format, string $content): void
    {
        $once  = Subtitle::fromString(self::BOM . $content, $format);
        $twice = Subtitle::fromString(self::BOM . self::BOM . $content, $format);

        $this->assertCount(2, $twice->getCues());
        $this->assertEquals($once->getCues(), $twice->getCues());
    }


    #[DataProvider("files")]
    public function testDetectionFindsTheFormatAfterTwoBoms(Format $format, string $content): void
    {
        $this->assertSame(Format::detect(self::BOM . $content), Format::detect(self::BOM . self::BOM . $content));
        $this->assertSame($format === Format::Csv ? null : $format, Format::detect(self::BOM . self::BOM . $content));
    }


    /**
     * @return array<string, array{Format, string}>
     */
    public static function joinedFiles(): array
    {
        return [
            "SubRip" => [Format::SubRip, "1\n00:00:01,000 --> 00:00:02,000\nThe ferry leaves.\n\n" . self::BOM . "1\n00:00:03,000 --> 00:00:04,000\nIt is late.\n"],
            "WebVTT" => [Format::WebVtt, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nThe ferry leaves.\n\n" . self::BOM . "00:00:03.000 --> 00:00:04.000\nIt is late.\n"],
            "SBV"    => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n" . self::BOM . "0:00:03.000,0:00:04.000\nIt is late.\n"],
        ];
    }


    #[DataProvider("joinedFiles")]
    public function testABomAtTheStartOfALineIsDropped(Format $format, string $content): void
    {
        $cues = Subtitle::fromString($content, $format)->getCues();

        $this->assertSame([[1.0, 2.0, "The ferry leaves."], [3.0, 4.0, "It is late."]], TestSubtitles::describe($cues));
    }


    public function testStreamReadersDropABomAtTheStartOfALine(): void
    {
        $files = self::joinedFiles();
        foreach ([[new SubRipStreamReader(), $files["SubRip"][1]], [new WebVttStreamReader(), $files["WebVTT"][1]]] as [$reader, $content]) {
            $stream = fopen("php://memory", "w+b");
            fwrite($stream, self::BOM . self::BOM . $content);
            rewind($stream);

            $this->assertSame([[1.0, 2.0, "The ferry leaves."], [3.0, 4.0, "It is late."]], TestSubtitles::describe(iterator_to_array($reader->read($stream))));
        }
    }


    public function testLenientModeGivesNoWarningForTwoBoms(): void
    {
        $subtitle = Subtitle::fromString(self::BOM . self::BOM . self::files()["WebVTT"][1], Format::WebVtt, new ReadOptions(lenient: true));

        $this->assertSame([], $subtitle->getParseWarnings());
    }

}
