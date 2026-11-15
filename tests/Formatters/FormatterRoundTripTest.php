<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\SpruceStlWriteOptions;
use SubtitleToolbox\Formatters\Options\SubViewerVersion;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\SpruceStlReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Each formatter writes a file that its parser reads back with the same text and times.
 */
final class FormatterRoundTripTest extends TestCase
{
    /**
     * @param string|list<string> $lines
     */
    private static function subtitle(string|array $lines, float $start = 1.0, float $end = 2.0): Subtitle
    {
        return (new Subtitle())->addCue(new SubtitleCue($start, $end, $lines))->addCue(new SubtitleCue(5, 6, "z"));
    }


    /**
     * @return list<list<string>>
     */
    private static function linesOf(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => $cue->getLines(), array_values($subtitle->getCues()));
    }


    public function testFramesAndSecondsBelowTheLimitReadBack(): void
    {
        $last  = (new Subtitle())->addCue(new SubtitleCue(359999998, 359999999.999, "a"));
        $csv   = $last->toString(Format::Csv, new WriteOptions(formats: [new CsvWriteOptions(timeFormat: CsvTimeFormat::Frames, frameRate: 25)]));
        $spruce = $last->toString(Format::SpruceStl, new WriteOptions(formats: [new SpruceStlWriteOptions(25)]));

        $this->assertStringContainsString("99999:59:59:24", $csv);
        $this->assertStringNotContainsString("100000", $csv);
        $this->assertStringContainsString("99999:59:59:24", $spruce);
        $this->assertStringNotContainsString("100000", $spruce);
        $this->assertCount(1, Subtitle::fromString($csv, Format::Csv, new ReadOptions(formats: [new CsvReadOptions(frameRate: 25)])));
        $this->assertCount(1, Subtitle::fromString($spruce, Format::SpruceStl, new ReadOptions(formats: [new SpruceStlReadOptions(25)])));

        $other = (new Subtitle())->addCue(new SubtitleCue(359999990, 359999999.6, "a"));
        $v1    = $other->toString(Format::SubViewer, new WriteOptions(formats: [new SubViewerWriteOptions(SubViewerVersion::V1)]));
        $this->assertStringContainsString("[99999:59:59]", $v1);
        $this->assertStringNotContainsString("[100000", $v1);
        $this->assertSame([359999990.0, 359999999.0], [Subtitle::fromString($v1, Format::SubViewer)->getCues()[0]->getStart(), Subtitle::fromString($v1, Format::SubViewer)->getCues()[0]->getEnd()]);
        $this->assertSame([99999, 59, 59], Timecode::seconds(359999999.6));
    }


    #[DataProvider("badStyles")]
    public function testSubViewerStyleReadsBack(string $style, ?string $written): void
    {
        $subtitle = self::subtitle("x");
        $subtitle->setFormatData("subviewer", ["style" => $style]);
        $output = $subtitle->toString(Format::SubViewer);

        $this->assertSame($written !== null, str_contains($output, "[SUBTITLE]\n$written\n"));
        $this->assertSame([["x"], ["z"]], self::linesOf(Subtitle::fromString($output, Format::SubViewer)));
    }


    public static function badStyles(): array
    {
        return [
            "plain text"     => ["hello", null],
            "time shape"     => ["[COLF]a,1:2:3", null],
            "line feed"      => ["[COLF]a\nb", "[COLF]a b"],
            "real style"     => ["[COLF]&HFFFFFF,[STYLE]bd,[SIZE]18,[FONT]Arial", "[COLF]&HFFFFFF,[STYLE]bd,[SIZE]18,[FONT]Arial"],
        ];
    }


    #[DataProvider("newlineEntities")]
    public function testEntityLineBreakReadsBackAsTwoLines(string $line): void
    {
        $sami = Subtitle::fromString(self::subtitle([$line])->toString(Format::Sami), Format::Sami);
        $this->assertSame([["a", "b"], ["z"]], self::linesOf($sami));

        $microDvd = self::subtitle($line)->toString(Format::MicroDvd, new WriteOptions(formats: [new MicroDvdWriteOptions(25)]));
        $this->assertStringContainsString("a|b\n", $microDvd);
        $this->assertSame([["a", "b"], ["z"]], self::linesOf(Subtitle::fromString($microDvd, Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(25)]))));

        $spruce = self::subtitle($line)->toString(Format::SpruceStl, new WriteOptions(formats: [new SpruceStlWriteOptions(25)]));
        $this->assertSame([["a", "b"], ["z"]], self::linesOf(Subtitle::fromString($spruce, Format::SpruceStl, new ReadOptions(formats: [new SpruceStlReadOptions(25)]))));

        $lyrics = self::subtitle($line)->toString(Format::Lyrics);
        $this->assertStringContainsString("[00:01.00] a b\n", $lyrics);
        $this->assertSame([["a b"], ["z"]], self::linesOf(Subtitle::fromString($lyrics, Format::Lyrics)));
    }


    public static function newlineEntities(): array
    {
        return ["decimal" => ["a&#10;b"], "hex" => ["a&#x0A;b"], "named" => ["a&NewLine;b"]];
    }


    #[DataProvider("assTexts")]
    public function testAssTextReadsBackAsWritten(string $text, string $expected): void
    {
        $output = self::subtitle([$text])->toString(Format::Ass);

        $this->assertSame([[$expected], ["z"]], self::linesOf(Subtitle::fromString($output, Format::Ass)));
    }


    public static function assTexts(): array
    {
        return [
            "N"        => ["a\\Nb", "a\\\u{2060}Nb"],
            "n"        => ["a\\nb", "a\\\u{2060}nb"],
            "h"        => ["a\\hb", "a\\\u{2060}hb"],
            "path"     => ["C:\\new", "C:\\\u{2060}new"],
        ];
    }


    public function testAssKeepsTheBackslashOfOverrideBlocks(): void
    {
        $output = self::subtitle(["{\\an8}a\\Nb"])->toString(Format::Ass);

        $this->assertStringContainsString("{\\an8}a\\\u{2060}Nb", $output);
    }


    public function testBomTimingLinesReadBack(): void
    {
        $srt = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["x", "\u{FEFF}00:00:03,500 --> 00:00:03,800"]))->addCue(new SubtitleCue(5, 6, "z"));
        $this->assertSame([["x", "\u{2060}\u{FEFF}00:00:03,500 --&gt; 00:00:03,800"], ["z"]], self::linesOf(Subtitle::fromString($srt->toString(Format::SubRip), Format::SubRip)));

        $sbv = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["x", "\u{FEFF}0:00:03.500,0:00:03.800"]))->addCue(new SubtitleCue(5, 6, "z"));
        $this->assertCount(2, Subtitle::fromString($sbv->toString(Format::Sbv), Format::Sbv));
    }


    #[DataProvider("blankWebVttLines")]
    public function testWebVttDropsLinesThatBecomeBlank(string $middle, bool $stripTags): void
    {
        $output = self::subtitle(["x", $middle, "y"])->toString(Format::WebVtt, new WriteOptions(stripTags: $stripTags));

        $this->assertSame([["x", "y"], ["z"]], self::linesOf(Subtitle::fromString($output, Format::WebVtt)));
    }


    public static function blankWebVttLines(): array
    {
        return [
            "p"        => ["<p></p>", false],
            "font"     => ['<font face="Arial"></font>', false],
            "bom"      => ["\u{FEFF}", false],
            "stripped" => ["<i></i>", true],
        ];
    }


    #[DataProvider("timestampLines")]
    public function testQuickTimeTextLineWithTimestampReadsBack(string $line): void
    {
        $output = self::subtitle(["x", $line])->toString(Format::QuickTimeText);

        $this->assertSame([["x", "\u{2060}$line"], ["z"]], self::linesOf(Subtitle::fromString($output, Format::QuickTimeText)));
    }


    public static function timestampLines(): array
    {
        return ["text after" => ["[00:00:03]y"], "units" => ["[00:00:03.50]"]];
    }


    public function testLyricsWritesOneIdTagForEachKeyInLowerCase(): void
    {
        $subtitle = self::subtitle("x");
        $subtitle->setMetadata(Subtitle::METADATA_TITLE, "Real");
        $subtitle->setFormatData("lrc", ["idTags" => ["TI" => "Other", "By" => "First", "by" => "Second"]]);

        $output = $subtitle->toString(Format::Lyrics);
        $read   = Subtitle::fromString($output, Format::Lyrics);

        $this->assertStringContainsString("[ti:Real]\n[By:First]\n[00:01.00]", $output);
        $this->assertSame("Real", $read->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame(["by" => "First"], $read->findFormatData("lrc")["idTags"]);
    }
}
