<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

/**
 * Checks the special cases of lenient mode in the TTML and iTT parsers.
 */
class LenientTtmlTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/lenient/";

    private const SKIPPED  = ParseWarningAction::Skipped;
    private const REPAIRED = ParseWarningAction::Repaired;


    public function testTtmlWithMalformedXmlIsRepaired(): void
    {
        $subtitle = (new TtmlParser())->parse(file_get_contents(self::DIR . "malformed.ttml"), new ReadOptions(lenient: true));

        $this->assertEquals([
            [1, 3, "Salt &amp; pepper are on the table."],
            [4, 6, "Turn left\nat the bakery."],
            [7, 9, "Tickets cost 5 &amp;cur; at the door."],
            [10, 12, "The last bus leaves at ten."],
        ], TestSubtitles::describe($subtitle));

        // The text of the libxml error differs between libxml versions.
        $warnings = $subtitle->getParseWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(5, $warnings[0]->lineNumber);
        $this->assertNull($warnings[0]->blockIndex);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
        $this->assertStringStartsWith("The file is not well-formed XML. ", $warnings[0]->message);
        $this->assertStringEndsWith(". The parser repaired it.", $warnings[0]->message);
    }


    public function testTtmlWithMalformedXmlStillThrowsInStrictMode(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file is not well-formed XML.");
        (new TtmlParser())->parse(file_get_contents(self::DIR . "malformed.ttml"), new ReadOptions());
    }


    public function testTtmlWithHtmlEntitiesAndMalformedXmlGetsTwoWarnings(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body><div><p begin=\"1s\" end=\"2s\">Caf&eacute;&nbsp;ok</p>\n<p begin=\"3s\" end=\"4s\">A & B</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertEquals([[1, 2, "Caf\u{e9}\u{a0}ok"], [3, 4, "A &amp; B"]], TestSubtitles::describe($subtitle));
        $this->assertSame([2, 3], array_map(fn (ParseWarning $warning): ?int => $warning->lineNumber, $subtitle->getParseWarnings()));
    }


    #[DataProvider("unrecoverableTtml")]
    public function testTtmlThatLibxmlCannotRepairToATtRootStillThrows(string $content): void
    {
        $this->expectException(ParsingException::class);
        (new TtmlParser())->parse($content, new ReadOptions(lenient: true));
    }


    public static function unrecoverableTtml(): array
    {
        return [
            "no markup"       => ["Subtitles & captions"],
            "an HTML root"    => ["<html><body><p>Hello<br></body></html>"],
            "no root element" => ["<?xml version=\"1.0\"?>\n<!-- empty -->"],
        ];
    }


    public function testLenientModeSkipsAParagraphAfterItsTimedDivWithoutOwnEnd(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div begin=\"5s\" end=\"6s\">\n<p begin=\"2s\">Late</p>\n<p>On time</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertEquals([[5, 6, "On time"]], TestSubtitles::describe($subtitle));
        $this->assertSame([[2, 0, self::SKIPPED, "The paragraph begins at 7s, but its parent ends at 6s."]], TestSubtitles::warnings($subtitle));
    }


    #[DataProvider("looseTtmlTimes")]
    public function testLenientModeReadsLooseTtmlTimeExpressions(string $begin, float $seconds): void
    {
        $content  = "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div>\n<p begin=\"$begin\" end=\"00:00:30.000\">Text</p></div></body></tt>";
        $subtitle = (new TtmlParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertSame([[$seconds, 30.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame(
            [[2, 0, self::REPAIRED, "The time expression \"$begin\" is not valid. The parser read it as {$seconds}s."]],
            TestSubtitles::warnings($subtitle)
        );
    }


    #[DataProvider("looseTtmlTimes")]
    public function testStrictModeRejectsLooseTtmlTimeExpressions(string $begin): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time expression \"$begin\" is not valid.");
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"$begin\" end=\"00:00:30.000\">Text</p></div></body></tt>", new ReadOptions());
    }


    public static function looseTtmlTimes(): array
    {
        return [
            "one-digit seconds"         => ["00:00:7.250", 7.25],
            "one-digit hours"           => ["0:01:02.500", 62.5],
            "minutes and seconds"       => ["02:15.500", 135.5],
            "milliseconds without unit" => ["4200", 4.2],
            "frames with three digits"  => ["00:00:02:250", 2.25],
        ];
    }


    public function testLenientModeReadsBareSecondsWithAFractionOfAnAppleMusicFile(): void
    {
        $subtitle = (new TtmlParser())->parse(file_get_contents(self::DIR . "bare_seconds.ttml"), new ReadOptions(lenient: true));

        // The spec adds the begin of the div to the begin of the paragraph. The second div holds absolute paragraph times, which the parser detects.
        $this->assertSame(
            [[39.528, 41.664], [60.998, 62.794]],
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues())
        );
        $this->assertSame(["Walking home"], $subtitle->getCues()[0]->getLines());
        $this->assertContains(
            "The time expression \"19.764\" is not valid. The parser read it as 19.764s.",
            array_column(TestSubtitles::warnings($subtitle), 3)
        );
    }


    public function testStrictModeRejectsBareSecondsWithAFraction(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid.");
        (new TtmlParser())->parse(file_get_contents(self::DIR . "bare_seconds.ttml"), new ReadOptions());
    }


    public function testLenientModeReadsBareSecondsOnAParagraphWithADuration(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"1.5\" dur=\"2.5\">Text</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[1.5, 4.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
    }


    public function testLenientModeKeepsTheParagraphsOfADivWithABadTime(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body>\n<div begin=\"soon\"><p begin=\"1s\" end=\"2s\">Text</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[1.0, 2.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame(
            [[2, null, self::REPAIRED, "The time expression \"soon\" is not valid. The parser ignored the timing of the div."]],
            TestSubtitles::warnings($subtitle)
        );
    }


    public function testStrictModeRejectsADivWithABadTime(): void
    {
        $this->expectException(ParsingException::class);
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div begin=\"soon\"><p begin=\"1s\" end=\"2s\">Text</p></div></body></tt>", new ReadOptions());
    }


    public function testLenientModeWarnsForALooseTtmlTimeOnADivWithoutAParagraphIndex(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body>\n<div begin=\"1000\"><p begin=\"1s\" end=\"2s\">Text</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[2.0, 3.0]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([[2, null, self::REPAIRED, "The time expression \"1000\" is not valid. The parser read it as 1s."]], TestSubtitles::warnings($subtitle));
    }


    #[DataProvider("highTtmlFrameLabels")]
    public function testLenientModeGuessesTheFrameRateOfTtmlWithHighFrameLabels(string $begin, string $end, float $start, float $stop, int $label, int $fps): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body><div><p begin=\"$begin\" end=\"$end\">Text</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[$start, $stop]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([[2, null, self::REPAIRED,
            "The frame label $label in \"" . ($label === (int) substr($begin, -2) ? $begin : $end) . "\" is not below the default frame rate of 30, and the file has no ttp:frameRate. The parser read the frames at $fps fps."]],
            TestSubtitles::warnings($subtitle));
    }


    public static function highTtmlFrameLabels(): array
    {
        return [
            "50 fps" => ["00:00:01:45", "00:00:02:10", 1.9, 2.2, 45, 50],
            "60 fps" => ["00:00:01:30", "00:00:02:55", 1.5, 2.917, 55, 60],
        ];
    }


    public function testStrictModeRejectsTtmlFrameLabelsAboveTheDefaultFrameRate(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame label 45 in \"00:00:01:45\" is not below the default frame rate of 30, and the file has no ttp:frameRate. (line 2)");
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\">\n<body><div><p begin=\"00:00:01:45\" end=\"00:00:02:10\">Text</p></div></body></tt>", new ReadOptions());
    }


    public function testLenientModeReadsTtmlFrameLabelsOfSixtyOrMoreAsHundredths(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div>\n<p begin=\"00:00:01:75\" end=\"00:00:03:20\">Text</p>\n<p begin=\"00:00:04:05\" end=\"00:00:05.500\">More</p></div></body></tt>",
            new ReadOptions(lenient: true)
        );

        $this->assertSame([[1.75, 3.2], [4.05, 5.5]], array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $this->assertSame([[2, null, self::REPAIRED,
            "The frame label 75 in \"00:00:01:75\" is not below the default frame rate of 30, and the file has no ttp:frameRate. The parser read the last field as hundredths of a second."]],
            TestSubtitles::warnings($subtitle));
    }


    public function testStrictModeRejectsTtmlFrameLabelsOfSixtyOrMore(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The frame label 75 in \"00:00:01:75\" is not below the default frame rate of 30, and the file has no ttp:frameRate.");
        (new TtmlParser())->parse("<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"00:00:01:75\" end=\"00:00:03:00\">Text</p></div></body></tt>", new ReadOptions());
    }


    public function testTtmlFrameLabelsBelowThirtyKeepTheDefaultFrameRate(): void
    {
        $subtitle = (new TtmlParser())->parse(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"00:00:01:15\" end=\"00:00:02:29\">Text</p></div></body></tt>",
            new ReadOptions()
        );

        $this->assertSame([1.5, 2.967], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
    }


    public function testIttParserInheritsLenientMode(): void
    {
        $subtitle = (new IttParser())->parse(file_get_contents(self::DIR . "bad_begin.ttml"), new ReadOptions(lenient: true));

        $this->assertCount(3, $subtitle->getCues());
        $this->assertCount(2, $subtitle->getParseWarnings());
    }
}
