<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;

class NoCuesTest extends TestCase
{
    private const BINARY_FORMATS = [Format::Cavena890, Format::EbuStl, Format::Pac, Format::Pgs, Format::VobSub];

    private const ASS_HEADER = "[Script Info]\nTitle: Harbour walk\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";


    /**
     * @return array<string, array{Format, string}>
     */
    public static function emptyTextFiles(): array
    {
        $cases = [];
        foreach (Format::cases() as $format) {
            if (!$format->canRead() || in_array($format, self::BINARY_FORMATS, true)) {
                continue;
            }
            $cases["$format->value, empty"]          = [$format, ""];
            $cases["$format->value, BOM and spaces"] = [$format, "\u{FEFF} \r\n\t\n"];
        }

        return $cases;
    }


    #[DataProvider("emptyTextFiles")]
    public function testEmptyContentGivesAnEmptySubtitleInBothModes(Format $format, string $content): void
    {
        foreach ([false, true] as $lenient) {
            $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: $lenient));

            $this->assertSame([], $subtitle->getCues());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    /**
     * @return array<string, array{Format, string}>
     */
    public static function headerOnlyFiles(): array
    {
        return [
            "WebVTT"   => [Format::WebVtt, "WEBVTT\n\n"],
            "LRC"      => [Format::Lyrics, "[ar:Harbour Band]\n[ti:Harbour walk]\n"],
            "ASS"      => [Format::Ass, self::ASS_HEADER],
            "CSV"      => [Format::Csv, "start,end,text\n"],
        ];
    }


    #[DataProvider("headerOnlyFiles")]
    public function testAHeaderWithoutCuesIsValid(Format $format, string $content): void
    {
        foreach ([false, true] as $lenient) {
            $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: $lenient));

            $this->assertSame([], $subtitle->getCues());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    /**
     * Each case holds the format, the content, the line of the strict error and the strict message.
     *
     * @return array<string, array{Format, string, int, string}>
     */
    public static function textWithoutCues(): array
    {
        return [
            "LRC without time tags"       => [Format::Lyrics, "[ti:Harbour walk]\nThe ferry leaves at nine.\nTickets are sold at the kiosk.\n", 2,
                                              "The file has text but no cues."],
            "SAMI without SYNC tags"      => [Format::Sami, "<SAMI>\n<HEAD><TITLE>Walk</TITLE></HEAD>\n<BODY>\n<P>The ferry leaves.</P>\n</BODY>\n</SAMI>\n", 4,
                                              "The file has text but no cues."],
            "ASS without Dialogue lines"  => [Format::Ass, self::ASS_HEADER . "The ferry leaves at nine.\n", 6, "The file has text but no cues."],
            "SubRip without timing lines" => [Format::SubRip, "The ferry leaves at nine.\nTickets are sold at the kiosk.\n", 1,
                                              "Block #0 has no cue number on its first line."],
            "WebVTT without timing lines" => [Format::WebVtt, "WEBVTT\n\nThe ferry leaves at nine.\n", 3,
                                              "Block #1 is not a WebVTT cue, comment, style or region."],
            "CSV without times"           => [Format::Csv, "start,end,text\nsoon,later,The ferry leaves at nine.\n", 2, "The time \"soon\" is not"],
        ];
    }


    #[DataProvider("textWithoutCues")]
    public function testStrictModeThrowsForTextWithoutCues(Format $format, string $content, int $line, string $message): void
    {
        try {
            Subtitle::fromString($content, $format);
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertSame($line, $exception->getLineNumber());
        }
    }


    #[DataProvider("textWithoutCues")]
    public function testLenientModeGivesAnEmptySubtitleWithOneWarning(Format $format, string $content, int $line, string $message): void
    {
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));

        $this->assertSame([], $subtitle->getCues());
        $this->assertCount(1, $subtitle->getParseWarnings());
        $this->assertSame(ParseWarningAction::Skipped, $subtitle->getParseWarnings()[0]->action);
        $this->assertSame($line, $subtitle->getParseWarnings()[0]->lineNumber);
        $this->assertStringContainsString($message, $subtitle->getParseWarnings()[0]->message);
    }


    public function testLrcWithCuesAndPlainTextLinesStaysValid(): void
    {
        $subtitle = Subtitle::fromString("A plain line\n[00:01.00]The ferry leaves.\n", Format::Lyrics);

        $this->assertCount(1, $subtitle->getCues());
    }


    public function testSamiWithSyncTagsAndNoCuesStaysValid(): void
    {
        $subtitle = Subtitle::fromString("<SAMI>\n<BODY>\n<SYNC Start=1000><P>&nbsp;</P>\n</BODY>\n</SAMI>\n", Format::Sami);

        $this->assertSame([], $subtitle->getCues());
    }


    public function testStreamReadersGiveNoCuesForEmptyContent(): void
    {
        foreach ([new SubRipStreamReader(), new WebVttStreamReader()] as $reader) {
            $stream = fopen("php://memory", "w+b");
            fwrite($stream, "\u{FEFF}\n \n");
            rewind($stream);

            $this->assertSame([], iterator_to_array($reader->read($stream)));
        }
    }
}
