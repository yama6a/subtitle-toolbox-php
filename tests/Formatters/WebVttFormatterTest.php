<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class WebVttFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectly()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"), Format::WebVtt);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"),
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testUnsupportedXmlTagsAreStrippedAway()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/strip_xml.vtt"), Format::WebVtt);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"),
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testAllXmlTagsAreStrippedAwayIfOptionIsSet()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/vtt/strip_xml.vtt"), Format::WebVtt);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/all_xml_tags_stripped.vtt"),
            $subtitle->toString(Format::WebVtt, new WriteOptions(stripTags: true))
        );
    }


    private const ISSUE_EXAMPLE = "WEBVTT Episode 1\nKind: captions\nLanguage: en\n\n"
                                  . "REGION\nid:fred\nwidth:40%\nlines:3\nregionanchor:0%,100%\nviewportanchor:10%,90%\nscroll:up\n\n"
                                  . "STYLE\n::cue {\n  color: lime;\n}\n\n"
                                  . "NOTE Translated by Jane Doe\n\n"
                                  . "intro\n00:00:01.000 --> 00:00:04.000 region:fred align:left line:85%\n"
                                  . "<v Fred>Hi, I am Fred &amp; this is <c>Bob</c></v>\n\n"
                                  . "2\n00:00:05.000 --> 00:00:06.000\nSecond\n\n"
                                  . "NOTE\nend of\nfile\n";


    public function testIssueExampleRoundTripsByteForByte(): void
    {
        $subtitle = Subtitle::fromString(self::ISSUE_EXAMPLE, Format::WebVtt);

        $this->assertSame(
            "\xEF\xBB\xBF" . self::ISSUE_EXAMPLE,
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testCuesWithoutIdentifierGetTheirCueNumber(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue((new SubtitleCue(1, 2, "One"))->setIdentifier("intro"));
        $subtitle->addCue(new SubtitleCue(3, 4, "Two"));
        $subtitle->addCue((new SubtitleCue(5, 6, "Three"))->setIdentifier("bad --> identifier"));

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\nintro\n00:00:01.000 --> 00:00:02.000\nOne\n\n"
            . "2\n00:00:03.000 --> 00:00:04.000\nTwo\n\n"
            . "3\n00:00:05.000 --> 00:00:06.000\nThree\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testCommentsAreWrittenAsValidNoteBlocks(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "One"));
        $subtitle->addComment("a --> b", 0);
        $subtitle->addComment("", 1);

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\nNOTE a -> b\n\n1\n00:00:01.000 --> 00:00:02.000\nOne\n\nNOTE\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    #[DataProvider("alignmentProvider")]
    public function testAlignmentBecomesCueSettings(?int $alignment, string $timingLine): void
    {
        $subtitle = (new Subtitle())->addCue((new SubtitleCue(1, 2, "One"))->setAlignment($alignment));

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\n1\n$timingLine\nOne\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public static function alignmentProvider(): array
    {
        return [
            "default"       => [null, "00:00:01.000 --> 00:00:02.000"],
            "bottom left"   => [1, "00:00:01.000 --> 00:00:02.000 align:left"],
            "bottom center" => [2, "00:00:01.000 --> 00:00:02.000"],
            "bottom right"  => [3, "00:00:01.000 --> 00:00:02.000 align:right"],
            "middle left"   => [4, "00:00:01.000 --> 00:00:02.000 line:50%,center align:left"],
            "middle center" => [5, "00:00:01.000 --> 00:00:02.000 line:50%,center"],
            "top center"    => [8, "00:00:01.000 --> 00:00:02.000 line:0"],
            "top right"     => [9, "00:00:01.000 --> 00:00:02.000 line:0 align:right"],
        ];
    }


    public function testAlignmentRoundTripsThroughCueSettings(): void
    {
        $subtitle = new Subtitle();
        foreach (range(1, 9) as $alignment) {
            $subtitle->addCue((new SubtitleCue($alignment, $alignment + 1, "Cue"))->setAlignment($alignment));
        }

        $parsed = Subtitle::fromString($subtitle->toString(Format::WebVtt), Format::WebVtt);

        $this->assertSame(
            [1, null, 3, 4, 5, 6, 7, 8, 9],
            array_map(fn (SubtitleCue $cue): ?int => $cue->getAlignment(), $parsed->getCues())
        );
    }


    public function testCueSettingsFromFormatDataStayWhileTheAlignmentIsUnchanged(): void
    {
        $cue = (new SubtitleCue(1, 2, "One"))->setFormatData("vtt", ["size" => "50%", "unknown" => "x", "align" => "start"]);

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000 size:50% align:start\nOne\n",
            (new Subtitle())->addCue($cue)->toString(Format::WebVtt)
        );
    }


    public function testChangedAlignmentReplacesTheStoredPosition(): void
    {
        $path     = __DIR__ . "/../files/vtt/real/w3c_cue_settings.vtt";
        $subtitle = Subtitle::load($path, Format::WebVtt);
        $subtitle->getCues()[0]->setAlignment(8);
        $subtitle->getCues()[1]->setAlignment(null);

        $this->assertSame(
            str_replace(
                [" position:10%,line-left align:left size:35%", " position:90% align:right size:35%"],
                [" size:35% line:0", " size:35%"],
                Subtitle::load($path, Format::WebVtt)->toString(Format::WebVtt)
            ),
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testIssueExampleMovesTheCueToTheBottom(): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000 line:0\nHello\n", Format::WebVtt);
        $subtitle->getCues()[0]->setAlignment(2);

        $this->assertSame(
            "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\nHello\n",
            $subtitle->toString(Format::WebVtt, new WriteOptions(bom: false))
        );
    }


    public function testSubRipCueSettingsStayWhileTheAlignmentIsUnchanged(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000 position:10%\n{\\an8}Hello\n", Format::SubRip);

        $this->assertStringContainsString("00:00:01.000 --> 00:00:02.000 position:10%\n", $subtitle->toString(Format::WebVtt));

        $subtitle->getCues()[0]->setAlignment(7);
        $this->assertStringContainsString("00:00:01.000 --> 00:00:02.000 line:0 align:left\n", $subtitle->toString(Format::WebVtt));
    }


    public function testInlineTimestampsAreKept(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 4, "<i>One</i> <00:00:02.500>two <00:03.000><foo>three</foo>"));

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\n1\n00:00:01.000 --> 00:00:04.000\n<i>One</i> <00:00:02.500>two <00:03.000>three\n",
            $subtitle->toString(Format::WebVtt)
        );
        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\n1\n00:00:01.000 --> 00:00:04.000\nOne two three\n",
            $subtitle->toString(Format::WebVtt, new WriteOptions(stripTags: true))
        );
    }


    #[DataProvider("classSpanProvider")]
    public function testSpansWithClassesAreKept(string $text): void
    {
        $subtitle = Subtitle::fromString("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n$text\n", Format::WebVtt);

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\n$text\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public static function classSpanProvider(): array
    {
        return [
            "class"           => ["<c.colorE5E5E5>hello</c> world"],
            "italics"         => ["<i.quiet>hello</i> world"],
            "bold"            => ["<b.loud>hello</b> world"],
            "underline"       => ["<u.link>hello</u> world"],
            "ruby"            => ["<ruby.small>base <rt.top>text</rt></ruby> world"],
            "voice"           => ["<v.first.loud Fred>hello</v> world"],
            "language"        => ["<lang.x en>hello</lang> world"],
            "nested"          => ["<c.yellow.bg_blue><b.loud>hello</b></c> world"],
            "word timestamps" => ["the<00:00:01.199><c> train</c><c.colorE5E5E5><00:00:01.379><c> leaves</c></c>"],
        ];
    }


    #[DataProvider("droppedTagProvider")]
    public function testDroppedTagsLoseTheirOpeningAndClosingTag(string $text, string $expected): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, $text));

        $this->assertSame(
            "\xEF\xBB\xBFWEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\n$expected\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public static function droppedTagProvider(): array
    {
        return [
            "unknown tag with class"     => ["<foo.bar>hello</foo> world", "hello world"],
            "longer name than a span"    => ["<bold.x>hello</bold> <cite.y>world</cite>", "hello world"],
            "core tags without classes"  => ["<font face=\"Arial\">plain</font> <s>gone</s> <b>bold</b>", "plain gone <b>bold</b>"],
            "font color with a class"    => ["<font color=\"#ff0000\">red</font> <s>gone</s>", "<c.red>red</c> gone"],
        ];
    }


    public function testEmptySubtitleWritesOnlyTheHeader(): void
    {
        $this->assertSame("\xEF\xBB\xBFWEBVTT\n\n", (new Subtitle())->toString(Format::WebVtt));
    }


    public function testTimingArrowInCueTextIsEscaped(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["a --> b", "", "c"]));
        $output   = $subtitle->toString(Format::WebVtt, new WriteOptions(bom: false));

        $this->assertSame(file_get_contents(__DIR__ . "/../files/vtt/real/own_arrow_in_text.vtt"), $output);
        $this->assertSame(
            ["a --&gt; b", "c"],
            Subtitle::fromString($output, Format::WebVtt)->getCues()[0]->getLines()
        );
    }


    public function testTimingArrowIsEscapedWhenTagsAreStripped(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<i>a --> b</i>"));

        $this->assertSame(
            "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\na --&gt; b\n",
            $subtitle->toString(Format::WebVtt, new WriteOptions(stripTags: true, bom: false))
        );
    }
}
