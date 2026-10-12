<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Subtitle;

class SubRipParserTest extends TestCase
{
    public function testValidSrtFileParses()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->toString(Format::SubRip)
        );
    }


    public function testExceededHoursThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/exceeded_hours.srt"), Format::SubRip);
    }


    public function testExceededMinutesThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/exceeded_minutes.srt"), Format::SubRip);
    }


    public function testExceededSecondsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/exceeded_seconds.srt"), Format::SubRip);
    }


    public function testExceededMilliSecondAccuracyThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/exceeded_milli_accuracy.srt"), Format::SubRip);
    }


    public function testMissingCueNumberThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("has no cue number");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/missing_cue_number.srt"), Format::SubRip);
    }


    public function testCueWithoutTextHasNoLines(): void
    {
        $cues = Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000", Format::SubRip)->getCues();

        $this->assertSame([1.0, 2.0, []], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
    }


    public function testMissingTimestampsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("has no timing line");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/missing_timestamps.srt"), Format::SubRip);
    }


    public function testSeveralEmptyLinesBetweenCuesStillSeparateCues(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n \n\n2\n00:00:03,000 --> 00:00:04,000\nSecond\n";

        $subtitle = Subtitle::fromString($raw, Format::SubRip);

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame("Second", $subtitle->getCues()[1]->getText());
    }


    public function testBlockWithOnlyACueNumberThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 has no timing line on its second line");
        Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n2", Format::SubRip);
    }


    public function testLenientTimestampsParse(): void
    {
        $raw = "1\n0:00:01.5 --> 00:00:02,25\nDot, one hour digit, short milliseconds\n\n" .
               "2\n00:00:03.000 --> 01:02:03.004\nDots\n";

        $cues = Subtitle::fromString($raw, Format::SubRip)->getCues();

        $this->assertSame(1.5, $cues[0]->getStart());
        $this->assertSame(2.25, $cues[0]->getEnd());
        $this->assertSame(3.0, $cues[1]->getStart());
        $this->assertSame(3723.004, $cues[1]->getEnd());
    }


    public function testCoordinatesGoToTheFormatData(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000 X1:100 X2:600 Y1:40 Y2:80\nThe train leaves soon\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(4.0, $cue->getEnd());
        $this->assertSame(["The train leaves soon"], $cue->getLines());
        $this->assertSame(["coordinates" => ["x1" => 100, "x2" => 600, "y1" => 40, "y2" => 80]], $cue->findFormatData("srt"));
    }


    public function testIncompleteCoordinatesThrowException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString("1\n00:00:01,000 --> 00:00:04,000 X1:100 X2:600\nText\n", Format::SubRip);
    }


    public function testAlignmentTagGoesToTheCueAlignment(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an8}<i>The train leaves soon</i>\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(Alignment::TopCenter, $cue->getAlignment());
        $this->assertSame(["<i>The train leaves soon</i>"], $cue->getLines());
    }


    public function testFirstAlignmentTagWinsAndAllAreRemoved(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an4}Middle and horiz{\\an6}ontally left\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(Alignment::MiddleLeft, $cue->getAlignment());
        $this->assertSame(["Middle and horizontally left"], $cue->getLines());
    }


    public function testLegacyAlignmentTagsAreConverted(): void
    {
        $expected = [1 => 1, 2 => 2, 3 => 3, 5 => 7, 6 => 8, 7 => 9, 9 => 4, 10 => 5, 11 => 6];
        foreach ($expected as $legacy => $alignment) {
            $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\a$legacy}Text\n";

            $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

            $this->assertSame($alignment, $cue->getAlignment()->value, "Legacy code $legacy");
            $this->assertSame(["Text"], $cue->getLines());
        }
    }


    public function testInvalidLegacyAlignmentStaysInTheText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\a4}Text\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(Alignment::BottomCenter, $cue->getAlignment());
        $this->assertSame(["{\\a4}Text"], $cue->getLines());
    }


    public function testAssStyleTagsBecomeCoreMarkup(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\b1}bold{\\b0} {\\i1}italic{\\i0} {\\u1}under{\\u0} {\\s1}struck{\\s0}\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(["<b>bold</b> <i>italic</i> <u>under</u> <s>struck</s>"], $cue->getLines());
    }


    public function testUnclosedAssStyleTagsAreClosedAtTheEndOfTheCue(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an8\\i1}one {\\b1}two\nthree{\\u0}\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(Alignment::TopCenter, $cue->getAlignment());
        $this->assertSame(["<i>one <b>two", "three</b></i>"], $cue->getLines());
    }


    public function testUnknownOverrideTagsStayInTheText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an8\\fad(200,200)}Sign {\\pos(10,20)}here {normal text}\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(Alignment::TopCenter, $cue->getAlignment());
        $this->assertSame(["{\\fad(200,200)}Sign {\\pos(10,20)}here {normal text}"], $cue->getLines());
    }


    public function testCarriageReturnCarriageReturnLineFeedIsOneLineEnding(): void
    {
        $raw = "1\r\r\n00:00:01,000 --> 00:00:02,000\r\r\nFirst\r\r\nline\r\r\n\r\r\n2\r\r\n00:00:03,000 --> 00:00:04,000\r\r\nSecond\r\r\n";

        $cues = Subtitle::fromString($raw, Format::SubRip)->getCues();

        $this->assertSame(2, count($cues));
        $this->assertSame(["First", "line"], $cues[0]->getLines());
    }


    public function testTextOutsideTagsIsEscaped(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\nI <3 bread & jam\n<i>Salt & pepper</i> 2 > 1\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(["I &lt;3 bread &amp; jam", "<i>Salt &amp; pepper</i> 2 &gt; 1"], $cue->getLines());
    }


    public function testEntityInTheFileIsLiteralText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\nThe sign says &amp; and &lt;b&gt;\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(["The sign says &amp;amp; and &amp;lt;b&amp;gt;"], $cue->getLines());
    }


    public function testTagsStayMarkup(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n<B>bold</B> <font color=\"#00aa00\">green</font> <foo>unknown</foo>\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(["<B>bold</B> <font color=\"#00aa00\">green</font> <foo>unknown</foo>"], $cue->getLines());
    }


    public function testUnknownTagWithoutAttributesAndEndTagIsText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n<b>Rye</b> & <b>spelt</b> <today>\n<i>open<br><foo>a\nb</FOO> <span class=\"x\">\n";

        $cue = Subtitle::fromString($raw, Format::SubRip)->getCues()[0];

        $this->assertSame(["<b>Rye</b> &amp; <b>spelt</b> &lt;today&gt;", "<i>open<br><foo>a", "b</FOO> <span class=\"x\">"], $cue->getLines());
    }


    public function testTimestampWithoutMillisecondsParses(): void
    {
        $raw = "1\n00:01:39 --> 00:01:41,000\nText\n\n2\n00:01:42,500 --> 00:01:44\nMore\n";

        $cues = Subtitle::fromString($raw, Format::SubRip)->getCues();

        $this->assertSame([99.0, 101.0], [$cues[0]->getStart(), $cues[0]->getEnd()]);
        $this->assertSame([102.5, 104.0], [$cues[1]->getStart(), $cues[1]->getEnd()]);
    }


    public function testTimestampWithSeparatorButNoMillisecondsThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        Subtitle::fromString("1\n00:01:39, --> 00:01:41,000\nText\n", Format::SubRip);
    }


    public static function inputsWithoutCues(): array
    {
        return [
            "empty"               => [""],
            "BOM only"            => ["\xEF\xBB\xBF"],
            "whitespace only"     => [" \n\t\r\n\n  "],
            "BOM and empty lines" => ["\xEF\xBB\xBF\r\n\r\n"],
        ];
    }


    #[DataProvider("inputsWithoutCues")]
    public function testAFileWithoutCuesReadsAsZeroCues(string $content): void
    {
        foreach ([new ReadOptions(), new ReadOptions(lenient: true)] as $options) {
            $subtitle = Subtitle::fromString($content, Format::SubRip, $options);

            $this->assertSame([], $subtitle->getCues());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }

    /**
     * Each case holds a timing line from 1 s to 2 s, whether strict mode reads it, and the arrow of the lenient warning.
     */
    public static function arrowVariants(): array
    {
        return [
            "no spaces"      => ["00:00:01,000-->00:00:02,000", true, null],
            "two spaces"     => ["00:00:01,000  -->  00:00:02,000", true, null],
            "tabs"           => ["00:00:01,000\t-->\t00:00:02,000", true, null],
            "short arrow"    => ["00:00:01,000 -> 00:00:02,000", false, "->"],
            "long arrow"     => ["00:00:01,000 ---> 00:00:02,000", false, "--->"],
            "long, no space" => ["00:00:01,000--->00:00:02,000", false, "--->"],
        ];
    }


    #[DataProvider("arrowVariants")]
    public function testStrictModeReadsOnlyTheArrowWithTwoDashes(string $timingLine, bool $strictReads): void
    {
        $content = "1\n$timingLine\nText\n\n2\n00:00:03,000 --> 00:00:04,000\nMore\n";
        if (!$strictReads) {
            $this->expectException(ParsingException::class);
            $this->expectExceptionMessage("Block #0 has no timing line on its second line.");
        }

        $cues = (new SubRipParser())->parse($content)->getCues();
        $this->assertSame([1.0, 2.0, ["Text"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertEquals($cues, iterator_to_array((new SubRipStreamReader())->read($this->stream($content)), false));
    }


    #[DataProvider("arrowVariants")]
    public function testLenientModeReadsAnyArrowAndWarnsForOtherLengths(string $timingLine, bool $strictReads, ?string $arrow): void
    {
        $content  = "1\n$timingLine\nText\n\n2\n00:00:03,000 --> 00:00:04,000\nMore\n";
        $options  = new ReadOptions(lenient: true);
        $subtitle = (new SubRipParser())->parse($content, $options);
        $reader   = new SubRipStreamReader($options);

        $cues = $subtitle->getCues();
        $this->assertCount(2, $cues);
        $this->assertSame([1.0, 2.0, ["Text"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $warnings = $subtitle->getParseWarnings();
        if ($arrow === null) {
            $this->assertSame([], $warnings);
        } else {
            $this->assertCount(1, $warnings);
            $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
            $this->assertSame("Block #0 has the arrow \"$arrow\" in its timing line. The parser read it as \"-->\".", $warnings[0]->message);
        }
        $this->assertEquals($cues, iterator_to_array($reader->read($this->stream($content)), false));
        $this->assertEquals($warnings, $reader->getWarnings());
    }


    public function testFormatterWritesTheArrowWithSpaces(): void
    {
        $subtitle = (new SubRipParser())->parse("1\n00:00:01,000 ---> 00:00:02,000\nText\n", new ReadOptions(lenient: true));

        $this->assertSame("\u{FEFF}1\n00:00:01,000 --> 00:00:02,000\nText\n", $subtitle->toString(Format::SubRip));
    }


    /**
     * Each case holds the text after the end time and the WebVTT cue settings the parser keeps.
     */
    public static function cueSettings(): array
    {
        return [
            "two settings"  => [" align:start position:0%", ["align" => "start", "position" => "0%"]],
            "two spaces"    => ["  align:start", ["align" => "start"]],
            "every setting" => [" vertical:rl line:0 position:10% size:80% align:end", ["vertical" => "rl", "line" => "0", "position" => "10%", "size" => "80%", "align" => "end"]],
        ];
    }


    #[DataProvider("cueSettings")]
    public function testStrictModeKeepsWebVttCueSettingsAfterTheEndTime(string $rest, array $settings): void
    {
        $content  = "1\n00:00:01,000 --> 00:00:03,500$rest\nText\n";
        $subtitle = (new SubRipParser())->parse($content);

        $cues = $subtitle->getCues();
        $this->assertSame([1.0, 3.5, ["Text"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertSame($settings, $cues[0]->findFormatData(Format::WebVtt->value));
        $this->assertSame([], $subtitle->getParseWarnings());
        $this->assertEquals($cues, iterator_to_array((new SubRipStreamReader())->read($this->stream($content)), false));
    }


    public function testStrictModeRejectsUnknownTextAfterTheEndTime(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The text \"region:top\" after the end time is not valid.");

        (new SubRipParser())->parse("1\n00:00:01,000 --> 00:00:03,500 align:start region:top\nText\n");
    }


    public function testLenientModeIgnoresUnknownTextAfterTheEndTimeAndWarns(): void
    {
        $content  = "1\n00:00:01,000 --> 00:00:03,500 align:start foo bar\nText\n\n2\n00:00:04,000 --> 00:00:05,000\nMore\n";
        $options  = new ReadOptions(lenient: true);
        $subtitle = (new SubRipParser())->parse($content, $options);
        $reader   = new SubRipStreamReader($options);

        $cues     = $subtitle->getCues();
        $warnings = $subtitle->getParseWarnings();
        $this->assertCount(2, $cues);
        $this->assertSame([1.0, 3.5, ["align" => "start"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->findFormatData(Format::WebVtt->value)]);
        $this->assertCount(1, $warnings);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
        $this->assertSame("Block #0 has the unknown text \"foo bar\" after the end time. The parser ignored it.", $warnings[0]->message);
        $this->assertEquals($cues, iterator_to_array($reader->read($this->stream($content)), false));
        $this->assertEquals($warnings, $reader->getWarnings());
    }


    private function stream(string $content)
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
