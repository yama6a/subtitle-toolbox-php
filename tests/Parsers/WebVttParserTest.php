<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttParserTest extends TestCase
{
    public function testValidVttFileParses()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"), Format::WebVtt);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"),
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testKeepsNotesAndStyles()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/vtt/with_styles_and_notes.vtt"), Format::WebVtt);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/with_styles_and_notes_formatted.vtt"),
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testWorksWithMissingHours()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/vtt/missing_hours.vtt"), Format::WebVtt);

        $this->assertSame($this->validWithHeaderText(), $subtitle->toString(Format::WebVtt));
    }


    public function testMissingEmptyLineAfterWebvttHeader()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The WEBVTT header has no empty line before the first cue");
        Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/vtt/no_empty_line_after_webvtt_header.vtt"), Format::WebVtt);
    }


    public function testMissingWebvttHeaderThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("file does not start with WEBVTT.");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/missing_webvtt_header.vtt"), Format::WebVtt);
    }


    public function testFourDigitHoursParse(): void
    {
        $cue = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/exceeded_hours.vtt"), Format::WebVtt)->getCues()[0];

        $this->assertSame([0.123, 4442400.456], [$cue->getStart(), $cue->getEnd()]);
    }


    public function testExceededMinutesThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/exceeded_minutes.vtt"), Format::WebVtt);
    }


    public function testExceededSecondsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/exceeded_seconds.vtt"), Format::WebVtt);
    }


    public function testExceededMilliSecondAccuracyThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/exceeded_milli_accuracy.vtt"), Format::WebVtt);
    }


    public function testMissingCueNumberWorksLikeWithNumbers()
    {
        $subtitle = Subtitle::fromString(
            file_get_contents(__DIR__ . "/../files/vtt/missing_cue_number.vtt"), Format::WebVtt);
        $this->assertSame($this->validWithHeaderText(), $subtitle->toString(Format::WebVtt));
    }


    public function testMissingTimestampsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not a WebVTT cue, comment, style or region");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/missing_timestamps.vtt"), Format::WebVtt);
    }


    public function testSeveralEmptyLinesBetweenCuesStillSeparateCues(): void
    {
        $raw = "WEBVTT\n\n\n00:01.000 --> 00:02.000\nFirst\n\n \n\n00:03.000 --> 00:04.000\nSecond\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame("Second", $subtitle->getCues()[1]->getText());
    }


    public function testSingleLineBlockThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 is not a WebVTT cue");
        Subtitle::fromString("WEBVTT\n\nstray line", Format::WebVtt);
    }


    public function testCueWithOnlyATimestampHasNoLines(): void
    {
        $cues = Subtitle::fromString("WEBVTT\n\n00:01.000 --> 00:02.000", Format::WebVtt)->getCues();

        $this->assertSame([1.0, 2.0, []], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
    }


    public function testLowercaseNoteAndStyleBlocksAreSkipped(): void
    {
        $raw = "WEBVTT\n\nnote a comment\n\nstyle\n::cue {}\n\n00:01.000 --> 00:02.000\nText\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(1, count($subtitle->getCues()));
        $this->assertSame("Text", $subtitle->getCues()[0]->getText());
    }


    public function testReadsHeaderRegionNoteIdentifierAndCueSettings(): void
    {
        $raw = "WEBVTT Episode 1\nKind: captions\nLanguage: en\n\n"
               . "REGION\nid:fred\nwidth:40%\nlines:3\nregionanchor:0%,100%\nviewportanchor:10%,90%\nscroll:up\n\n"
               . "NOTE Translated by Jane Doe\n\n"
               . "intro\n00:00:01.000 --> 00:00:04.000 region:fred align:left line:85%\n"
               . "<v Fred>Hi, I am Fred &amp; this is <c.yellow>Bob</c></v>\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);
        $cue      = $subtitle->getCues()[0];

        $this->assertSame([
            "header"      => "Episode 1",
            "headerLines" => ["Kind: captions", "Language: en"],
            "regions"     => [[
                "id"             => "fred",
                "width"          => "40%",
                "lines"          => "3",
                "regionanchor"   => "0%,100%",
                "viewportanchor" => "10%,90%",
                "scroll"         => "up",
            ]],
        ], $subtitle->findFormatData("vtt"));
        $this->assertEquals([new Comment("Translated by Jane Doe", 0)], $subtitle->getComments());
        $this->assertSame("intro", $cue->findIdentifier());
        $this->assertSame(["region" => "fred", "align" => "left", "line" => "85%"], $cue->findFormatData("vtt"));
        $this->assertSame(Alignment::BottomCenter, $cue->getAlignment());
        $this->assertSame("<v Fred>Hi, I am Fred &amp; this is <c.yellow>Bob</c></v>", $cue->getText());
    }


    public function testNoteStaysBeforeItsCueWhenTheCuesAreOutOfTimeOrder(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/real/own_note_before_earlier_cue.vtt"), Format::WebVtt);

        $this->assertSame(["The door opens.", "The shop is open.", "The shop closes."], array_map(fn (SubtitleCue $cue) => $cue->getText(), $subtitle->getCues()));
        $this->assertEquals([
            new Comment("The door opens before the shop.", 0),
            new Comment("The shop opens at nine.", 1),
            new Comment("End of the shop scene.", 3),
        ], $subtitle->getComments());
    }


    public function testCommentsKeepTheirPositionBetweenCues(): void
    {
        $raw = "WEBVTT\n\nNOTE\nfirst line\nsecond line\n\n00:01.000 --> 00:02.000\nOne\n\n"
               . "NOTE\tbetween\n\n00:03.000 --> 00:04.000\nTwo\n\nNOTE end of file\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertEquals([
            new Comment("first line\nsecond line", 0),
            new Comment("between", 1),
            new Comment("end of file", 2),
        ], $subtitle->getComments());
    }


    public function testIdentifierThatStartsWithNoteIsACueIdentifier(): void
    {
        $raw = "WEBVTT\n\nNOTE-1\n00:01.000 --> 00:02.000\nOne\n\nNotes1\n00:03.000 --> 00:04.000\nTwo\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame([], $subtitle->getComments());
        $this->assertSame("NOTE-1", $subtitle->getCues()[0]->findIdentifier());
        $this->assertSame("Notes1", $subtitle->getCues()[1]->findIdentifier());
    }


    public function testCueWithoutIdentifierHasNullIdentifier(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:01.000 --> 00:02.000\nOne\n", Format::WebVtt);

        $this->assertNull($subtitle->getCues()[0]->findIdentifier());
        $this->assertSame([], $subtitle->findFormatData("vtt"));
    }


    public function testStylesKeepTheExactCss(): void
    {
        $raw = "WEBVTT\n\nSTYLE\n::cue {\n\tcolor: lime;\n}\n\n00:01.000 --> 00:02.000\nOne\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(["styles" => ["::cue {\n\tcolor: lime;\n}"]], $subtitle->findFormatData("vtt"));
    }


    public function testStyleAndRegionBlocksAfterTheFirstCueAreIgnored(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\nOne\n\nSTYLE\n::cue { color: lime }\n\nREGION\nid:late\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(1, count($subtitle->getCues()));
        $this->assertSame([], $subtitle->findFormatData("vtt"));
    }


    public function testStyleKeywordWithTextIsNoStyleBlock(): void
    {
        $raw = "WEBVTT\n\nSTYLES\n::cue {}\n\n00:01.000 --> 00:02.000\nOne\n";

        $this->assertSame([], Subtitle::fromString($raw, Format::WebVtt)->findFormatData("vtt"));
    }


    public function testRegionSettingsWithoutValueAreIgnored(): void
    {
        $raw = "WEBVTT\n\nREGION\nid:fred width: :3 scroll:up foo:bar\n\n00:01.000 --> 00:02.000\nOne\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(["regions" => [["id" => "fred", "scroll" => "up"]]], $subtitle->findFormatData("vtt"));
    }


    public function testTimestampWithoutDotThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"00:00:01x000\" is not valid.");
        Subtitle::fromString("WEBVTT\n\n00:00:01x000 --> 00:00:04.000\nText\n", Format::WebVtt);
    }


    public function testTextBetweenStartTimeAndArrowThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not valid");
        Subtitle::fromString("WEBVTT\n\n00:00:01.000 align:left --> 00:00:04.000\nText\n", Format::WebVtt);
    }


    public function testArrowWithoutSpacesParses(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:01.000-->00:02.500\nText\n", Format::WebVtt);

        $this->assertSame(2.5, $subtitle->getCues()[0]->getEnd());
    }


    public function testTimingLineWithoutEmptyLineStartsANewCue(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\nOne\n00:03.000 --> 00:04.000\nTwo\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(["One", "Two"], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()));
    }


    public function testCueSettingsKeepTheExactValues(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000 \tvertical:rl  size:50.5%  foo:bar position:10%,line-left noColon\nOne\n";

        $cue = Subtitle::fromString($raw, Format::WebVtt)->getCues()[0];

        $this->assertSame(["vertical" => "rl", "size" => "50.5%", "position" => "10%,line-left"], $cue->findFormatData("vtt"));
        $this->assertSame(Alignment::BottomCenter, $cue->getAlignment());
    }


    #[DataProvider("alignmentProvider")]
    public function testCueSettingsMapToAlignment(string $settings, Alignment $alignment): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000 $settings\nOne\n";

        $this->assertSame($alignment, Subtitle::fromString($raw, Format::WebVtt)->getCues()[0]->getAlignment());
    }


    public static function alignmentProvider(): array
    {
        return [
            "no settings"              => ["", Alignment::BottomCenter],
            "line 0"                   => ["line:0", Alignment::TopCenter],
            "line 0 percent left"      => ["line:0% align:left", Alignment::TopLeft],
            "line 0 right"             => ["align:right line:0", Alignment::TopRight],
            "middle"                   => ["line:50%,center", Alignment::MiddleCenter],
            "middle left"              => ["line:50%,center align:left", Alignment::MiddleLeft],
            "bottom line"              => ["line:-1", Alignment::BottomCenter],
            "bottom right"             => ["line:100%,end align:right", Alignment::BottomRight],
            "align left"               => ["align:left", Alignment::BottomLeft],
            "align center"             => ["align:center", Alignment::BottomCenter],
            "align start is unclear"   => ["align:start", Alignment::BottomCenter],
            "line 85 percent unclear"  => ["line:85%", Alignment::BottomCenter],
            "vertical text is unclear" => ["vertical:rl line:0", Alignment::BottomCenter],
            "only position"            => ["position:10%", Alignment::BottomCenter],
        ];
    }


    public function testEntitiesForSpacesAndDirectionMarksAreDecoded(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\nA&nbsp;B &lrm;C&rlm; &amp; &lt;D&gt;\n";

        $this->assertSame(
            "A\u{00A0}B \u{200E}C\u{200F} &amp; &lt;D&gt;",
            Subtitle::fromString($raw, Format::WebVtt)->getCues()[0]->getText()
        );
    }


    public function testAmpersandThatStartsNoCharacterReferenceIsText(): void
    {
        $raw      = "WEBVTT\n\n00:01.000 --> 00:02.000\n<b>Rye</b> & <b>spelt</b> &foo; &#233; &eacute; &amp;\n";
        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame("<b>Rye</b> &amp; <b>spelt</b> &amp;foo; &#233; &eacute; &amp;", $subtitle->getCues()[0]->getText());
        $this->assertStringContainsString("\n<b>Rye</b> &amp; <b>spelt</b> &amp;foo; &#233; &eacute; &amp;\n", $subtitle->toString(Format::WebVtt));
        $this->assertSame("Rye & spelt &foo; é é &\n", $subtitle->toString(Format::PlainText));
    }


    public function testUnknownTagWithoutAttributesAndEndTagIsText(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\n<b>Rye</b> &amp; <b>spelt</b> <today>\n<c>open<foo>a</foo> <lang en>x</lang>\n";

        $subtitle = Subtitle::fromString($raw, Format::WebVtt);

        $this->assertSame(["<b>Rye</b> &amp; <b>spelt</b> &lt;today&gt;", "<c>open<foo>a</foo> <lang en>x</lang>"], $subtitle->getCues()[0]->getLines());
        $this->assertStringContainsString("\n<b>Rye</b> &amp; <b>spelt</b> &lt;today&gt;\n", $subtitle->toString(Format::WebVtt));
    }


    public function testInlineTimestampsAreKept(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:04.000\nOne <00:00:02.500>two <00:03.000>three\n";

        $this->assertSame(
            "One <00:00:02.500>two <00:03.000>three",
            Subtitle::fromString($raw, Format::WebVtt)->getCues()[0]->getText()
        );
    }


    public function testHeaderLineWithArrowThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The WEBVTT header has no empty line before the first cue");
        Subtitle::fromString("WEBVTT\nKind: captions\n00:01.000 --> 00:02.000\nText\n", Format::WebVtt);
    }


    private function validWithHeaderText(): string
    {
        return str_replace("WEBVTT\n", "WEBVTT - some title\n", file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"));
    }


    public function testWebVttWithCrCrLfLineEndingsKeepsCuesApart(): void
    {
        $raw = "WEBVTT\r\r\n\r\r\n00:01.000 --> 00:02.000\r\r\nFirst\r\r\n\r\r\n00:03.000 --> 00:04.000\r\r\nSecond\r\r\n";

        $cues = Subtitle::fromString($raw, Format::WebVtt)->getCues();

        $this->assertSame(2, count($cues));
        $this->assertSame("Second", $cues[1]->getText());
    }
}
