<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleReader;

class TtmlParserTest extends TestCase
{
    private const HEADER = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:tts=\"http://www.w3.org/ns/ttml#styling\""
                           . " xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\" xmlns:ttm=\"http://www.w3.org/ns/ttml#metadata\"";


    private function parse(string $body, string $rootAttributes = "", string $head = ""): Subtitle
    {
        return Subtitle::fromString(
            self::HEADER . " $rootAttributes><head>$head</head><body>$body</body></tt>",
            Format::Ttml);
    }


    private function parseParagraph(string $paragraph, string $rootAttributes = "", string $head = ""): array
    {
        $cue = $this->parse("<div>$paragraph</div>", $rootAttributes, $head)->getCues()[0];

        return [$cue->getStart(), $cue->getEnd(), $cue->getText()];
    }


    public function testIssueExample(): void
    {
        $subtitle = Subtitle::fromString(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\">\n"
            . "  <body>\n    <div>\n"
            . "      <p begin=\"00:00:01.500\" end=\"00:00:04.000\">Hello<br/><span tts:fontStyle=\"italic\">world</span></p>\n"
            . "      <p begin=\"5s\" dur=\"2500ms\">Second cue</p>\n"
            . "    </div>\n  </body>\n</tt>",
            Format::Ttml);
        $cues = $subtitle->getCues();

        $this->assertSame("en", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame([1.5, 4.0, ["Hello", "<i>world</i>"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertSame([5.0, 7.5, ["Second cue"]], [$cues[1]->getStart(), $cues[1]->getEnd(), $cues[1]->getLines()]);
    }


    public static function timeExpressionProvider(): array
    {
        return [
            "clock time"               => ["00:00:01.500", "", 1.5],
            "clock time, long hours"   => ["100:00:00", "", 360000.0],
            "clock time with frames"   => ["00:00:01:12", "ttp:frameRate=\"24\"", 1.5],
            "frames, default 30 fps"   => ["00:00:01:15", "", 1.5],
            "sub-frames"               => ["00:00:00:01.1", "ttp:frameRate=\"25\" ttp:subFrameRate=\"2\"", 0.06],
            "frame rate multiplier"    => ["24f", "ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"1000 1001\"", 1.001],
            "hours"                    => ["1.5h", "", 5400.0],
            "minutes"                  => ["2m", "", 120.0],
            "seconds"                  => ["1.5s", "", 1.5],
            "milliseconds"             => ["1500ms", "", 1.5],
            "frames"                   => ["36f", "ttp:frameRate=\"24\"", 1.5],
            "ticks"                    => ["15000000t", "ttp:tickRate=\"10000000\"", 1.5],
            "ticks at the frame rate"  => ["36t", "ttp:frameRate=\"24\"", 1.5],
            "ticks, default rate 1"    => ["2t", "", 2.0],
            "comma decimal separator"  => ["00:00:01,500", "", 1.5],
        ];
    }


    #[DataProvider("timeExpressionProvider")]
    public function testTimeExpressions(string $expression, string $rootAttributes, float $seconds): void
    {
        $this->assertSame($seconds, $this->parseParagraph("<p begin=\"$expression\" end=\"999999s\">x</p>", $rootAttributes)[0]);
    }


    public static function smpteTimeExpressionProvider(): array
    {
        $ntsc = "ttp:timeBase=\"smpte\" ttp:frameRate=\"30\" ttp:frameRateMultiplier=\"1000 1001\"";

        return [
            "29.97 fps non-drop, one hour"         => ["01:00:00:00", "$ntsc ttp:dropMode=\"nonDrop\"", 3603.6],
            "29.97 fps, non-drop is the default"   => ["01:00:00:00", $ntsc, 3603.6],
            "29.97 fps drop, after minute 1"       => ["00:01:00:02", "$ntsc ttp:dropMode=\"dropNTSC\"", 60.06],
            "29.97 fps drop, last frame before"    => ["00:00:59:29", "$ntsc ttp:dropMode=\"dropNTSC\"", 60.027],
            "29.97 fps drop, minute 10"            => ["00:10:00:00", "$ntsc ttp:dropMode=\"dropNTSC\"", 599.999],
            "29.97 fps drop, one hour"             => ["01:00:00:00", "$ntsc ttp:dropMode=\"dropNTSC\"", 3599.996],
            "Apple 999/1000 drop, minute 10"       => ["00:10:00:00", "ttp:timeBase=\"smpte\" ttp:frameRate=\"30\" ttp:frameRateMultiplier=\"999 1000\" ttp:dropMode=\"dropNTSC\"", 600.0],
            "drop PAL, minute 10"                  => ["01:10:00:04", "$ntsc ttp:dropMode=\"dropPAL\"", 4200.063],
            "23.976 fps"                           => ["00:00:01:00", "ttp:timeBase=\"smpte\" ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"1000 1001\"", 1.001],
            "25 fps, one hour"                     => ["01:00:00:00", "ttp:timeBase=\"smpte\" ttp:frameRate=\"25\"", 3600.0],
            "25 fps, frames"                       => ["00:00:01:05", "ttp:timeBase=\"smpte\" ttp:frameRate=\"25\" ttp:dropMode=\"dropNTSC\"", 1.2],
            "sub-frames"                           => ["00:00:00:01.1", "ttp:timeBase=\"smpte\" ttp:frameRate=\"25\" ttp:subFrameRate=\"2\"", 0.06],
            "media time base, frames"              => ["01:00:00:00", "ttp:frameRate=\"30\" ttp:frameRateMultiplier=\"1000 1001\"", 3600.0],
            "media time base, drop mode ignored"   => ["00:01:00:02", "ttp:timeBase=\"media\" ttp:frameRate=\"30\" ttp:dropMode=\"dropNTSC\"", 60.067],
        ];
    }


    #[DataProvider("smpteTimeExpressionProvider")]
    public function testSmpteTimeExpressions(string $expression, string $rootAttributes, float $seconds): void
    {
        $this->assertSame($seconds, $this->parseParagraph("<p begin=\"$expression\" end=\"99:00:00:00\">x</p>", $rootAttributes)[0]);
    }


    public function testInvalidSpanTimeKeepsTheText(): void
    {
        $this->assertSame([1.0, 2.0, "A B"], $this->parseParagraph("<p begin=\"1s\" end=\"2s\">A <span begin=\"x\">B</span></p>"));
    }


    public function testInvalidTimeExpressionThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->parseParagraph("<p begin=\"1.5\" end=\"2s\">x</p>");
    }


    public function testParentBeginOffsetsAndEnds(): void
    {
        $subtitle = $this->parse(
            "<div begin=\"10s\" end=\"20s\"><div begin=\"1s\">"
            . "<p begin=\"1s\" end=\"2s\">relative</p>"
            . "<p begin=\"2s\">parent end</p>"
            . "<p begin=\"3s\" end=\"30s\">clipped</p>"
            . "<p begin=\"1s\" end=\"5s\" dur=\"1s\">earlier of end and dur</p>"
            . "</div></div>"
        );
        $times = array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues());

        $this->assertSame([
            [12.0, 13.0, "relative"],
            [12.0, 13.0, "earlier of end and dur"],
            [13.0, 20.0, "parent end"],
            [14.0, 20.0, "clipped"],
        ], $times);
    }


    public function testBodyBeginOffset(): void
    {
        $subtitle = Subtitle::fromString(self::HEADER . "><body begin=\"5s\"><div><p begin=\"1s\" end=\"2s\">x</p></div></body></tt>", Format::Ttml);

        $this->assertSame([6.0, 7.0], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
    }


    public function testParagraphWithoutEndThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->parseParagraph("<p begin=\"1s\">x</p>");
    }


    public function testWhitespaceIsCollapsed(): void
    {
        $this->assertSame(
            "Hello <b>bold </b>world\nnext line",
            $this->parseParagraph("<p end=\"1s\">\n  Hello  <span tts:fontWeight=\"bold\"> bold </span>\n  world <br/>  next\tline\n</p>")[2]
        );
    }


    public function testPreservedSpaceTurnsNewlinesIntoLineBreaks(): void
    {
        $this->assertSame("first\nsecond", $this->parseParagraph("<p xml:space=\"preserve\" end=\"1s\">first\nsecond</p>")[2]);
    }


    public function testTextEntitiesStayEscaped(): void
    {
        $this->assertSame("a &lt;b&gt; &amp; \"c\"", $this->parseParagraph("<p end=\"1s\">a &lt;b&gt; &amp; \"c\"</p>")[2]);
    }


    public function testStylesMapToCoreMarkup(): void
    {
        $head = "<styling>"
                . "<style xml:id=\"base\" tts:fontWeight=\"bold\"/>"
                . "<style xml:id=\"chained\" style=\"base\" tts:color=\"yellow\"/>"
                . "<style xml:id=\"italic\" tts:fontStyle=\"oblique\"/>"
                . "</styling>";
        $text = $this->parseParagraph(
            "<p end=\"1s\" style=\"chained\">a <span style=\"italic\" tts:fontWeight=\"normal\">b</span>"
            . " <span tts:textDecoration=\"underline lineThrough\">c</span>"
            . " <span tts:textDecoration=\"underline\"><span tts:textDecoration=\"noUnderline\">d</span></span>"
            . " <span tts:color=\"rgb(0, 128, 255)\">e</span> <span tts:color=\"#00FF0080\">f</span>"
            . " <span tts:color=\"transparent\">g</span></p>",
            "",
            $head
        )[2];

        $this->assertSame(
            "<font color=\"#ffff00\"><b>a </b><i>b </i><b><u><s>c </s></u>d </b></font>"
            . "<font color=\"#0080ff\"><b>e </b></font><font color=\"#00ff00\"><b>f </b></font><b>g</b>",
            $text
        );
    }


    public function testWhiteTextGivesNoFontTag(): void
    {
        $head = "<styling><style xml:id=\"s1\" tts:color=\"white\" tts:fontSize=\"100%\"/></styling>";
        $text = $this->parseParagraph(
            "<p end=\"1s\" style=\"s1\">white <span tts:color=\"yellow\">yellow</span>"
            . " <span tts:color=\"rgb(255,255,255)\">rgb</span> <span tts:color=\"rgba(255,255,255,255)\">rgba</span>"
            . " <span tts:color=\"#FFFFFFFF\">hex</span></p>",
            "",
            $head
        )[2];

        $this->assertSame("white <font color=\"#ffff00\">yellow </font>rgb rgba hex", $text);
    }


    public function testAgentsBecomeSpeakerTags(): void
    {
        $head = "<metadata><ttm:agent xml:id=\"a1\" type=\"person\"><ttm:name type=\"full\">Anna</ttm:name></ttm:agent></metadata>";
        $text = $this->parseParagraph(
            "<p end=\"1s\" ttm:agent=\"a1\">Hi<br/>there <span ttm:agent=\"b2\">Bye</span></p>",
            "",
            $head
        )[2];

        $this->assertSame("<v Anna>Hi\nthere </v><v b2>Bye", $text);
    }


    public static function alignmentProvider(): array
    {
        return [
            "bottom center"              => ["tts:origin=\"10% 10%\" tts:extent=\"80% 80%\" tts:displayAlign=\"after\" tts:textAlign=\"center\"", Alignment::BottomCenter],
            "top left"                   => ["tts:displayAlign=\"before\" tts:textAlign=\"left\"", Alignment::TopLeft],
            "middle right"               => ["tts:displayAlign=\"center\" tts:textAlign=\"right\"", Alignment::MiddleRight],
            "small region at the bottom" => ["tts:origin=\"10% 80%\" tts:extent=\"80% 10%\" tts:displayAlign=\"before\" tts:textAlign=\"center\"", Alignment::BottomCenter],
            "pixels"                     => ["tts:origin=\"0px 400px\" tts:extent=\"640px 80px\" tts:textAlign=\"center\"", Alignment::BottomCenter],
            "cells"                      => ["tts:origin=\"0c 13c\" tts:extent=\"32c 2c\" tts:textAlign=\"center\"", Alignment::BottomCenter],
            "start depends on direction" => ["tts:displayAlign=\"after\" tts:textAlign=\"start\"", Alignment::BottomCenter],
            "no text alignment"          => ["tts:displayAlign=\"after\"", Alignment::BottomCenter],
            "unknown unit"               => ["tts:origin=\"10em 10em\" tts:textAlign=\"center\"", Alignment::BottomCenter],
        ];
    }


    #[DataProvider("alignmentProvider")]
    public function testRegionMapsToAlignment(string $regionAttributes, Alignment $alignment): void
    {
        $subtitle = $this->parse(
            "<div><p region=\"r1\" end=\"1s\">x</p></div>",
            "tts:extent=\"640px 480px\"",
            "<layout><region xml:id=\"r1\" $regionAttributes/></layout>"
        );

        $this->assertSame($alignment, $subtitle->getCues()[0]->getAlignment());
    }


    public function testParagraphTextAlignWinsOverRegion(): void
    {
        $subtitle = $this->parse(
            "<div region=\"r1\"><p tts:textAlign=\"left\" end=\"1s\">x</p></div>",
            "",
            "<layout><region xml:id=\"r1\"><style tts:displayAlign=\"after\"/><style tts:textAlign=\"center\"/></region></layout>"
        );

        $this->assertSame(Alignment::BottomLeft, $subtitle->getCues()[0]->getAlignment());
        $this->assertSame(["tts:textAlign" => "left", "region" => "r1"], $subtitle->getCues()[0]->findFormatData("ttml")["attributes"]);
    }


    public function testParagraphWithUnknownRegionHasNoAlignment(): void
    {
        $subtitle = $this->parse("<div><p region=\"missing\" tts:textAlign=\"center\" end=\"1s\">x</p></div>");

        $this->assertSame(Alignment::BottomCenter, $subtitle->getCues()[0]->getAlignment());
    }


    public function testTitleAndHeadGoToMetadataAndFormatData(): void
    {
        $subtitle = $this->parse(
            "<div><p end=\"1s\">x</p></div>",
            "xml:lang=\"de\" ttp:frameRate=\"25\"",
            "<metadata><ttm:title>Wetter</ttm:title><ttm:copyright>Sample</ttm:copyright></metadata>"
        );
        $fileData = $subtitle->findFormatData("ttml");

        $this->assertSame(["language" => "de", "title" => "Wetter"], $subtitle->getAllMetadata());
        $this->assertSame("http://www.w3.org/ns/ttml", $fileData["namespace"]);
        $this->assertSame(["ttp:frameRate" => "25"], $fileData["attributes"]);
        $this->assertSame("<head><metadata><ttm:copyright>Sample</ttm:copyright></metadata></head>", $fileData["head"]);
        $this->assertSame("http://www.w3.org/ns/ttml#styling", $fileData["namespaces"]["tts"]);
    }


    public function testDfxpNamespacesAreRead(): void
    {
        $subtitle = Subtitle::fromString(
            "<tt xmlns=\"http://www.w3.org/2006/10/ttaf1\" xmlns:tts=\"http://www.w3.org/2006/10/ttaf1#style\""
            . " xmlns:ttp=\"http://www.w3.org/2006/10/ttaf1#parameter\" ttp:frameRate=\"25\">"
            . "<body><div><p begin=\"00:00:01:05\" end=\"2s\"><span tts:fontWeight=\"bold\">x</span></p></div></body></tt>",
            Format::Ttml);

        $this->assertSame([1.2, "<b>x</b>"], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getText()]);
    }


    public function test2006NamespaceIsReadAndDetected(): void
    {
        $content = "<tt xmlns=\"http://www.w3.org/2006/04/ttaf1\" xmlns:tts=\"http://www.w3.org/2006/04/ttaf1#styling\" xml:lang=\"en\">\n"
                   . "  <body><div><p begin=\"00:00:01.00\" end=\"00:00:03.00\" tts:fontStyle=\"italic\">Hello</p></div></body></tt>";
        $cue     = Subtitle::fromString($content, Format::Ttml)->getCues()[0];

        $this->assertSame([1.0, 3.0, "<i>Hello</i>"], [$cue->getStart(), $cue->getEnd(), $cue->getText()]);
        $this->assertSame(Format::Ttml, SubtitleReader::detectFormat($content));
    }


    public function testExternalEntityIsNotLoaded(): void
    {
        $subtitle = Subtitle::fromString(
            "<?xml version=\"1.0\"?>\n<!DOCTYPE tt [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n"
            . "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"0s\" end=\"1s\">a&xxe;b</p></div></body></tt>",
            Format::Ttml);

        $this->assertSame("ab", $subtitle->getCues()[0]->getText());
        $this->assertStringNotContainsString("root:", serialize($subtitle));
    }


    public function testExternalDtdIsNotLoaded(): void
    {
        $subtitle = Subtitle::fromString(
            "<?xml version=\"1.0\"?>\n<!DOCTYPE tt SYSTEM \"http://127.0.0.1:1/missing.dtd\">\n"
            . "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"0s\" end=\"1s\">x</p></div></body></tt>",
            Format::Ttml);

        $this->assertSame("x", $subtitle->getCues()[0]->getText());
    }


    public static function invalidFileProvider(): array
    {
        return [
            "not well-formed"  => ["<tt xmlns=\"http://www.w3.org/ns/ttml\"><body>"],
            "other root"       => ["<html><body/></html>"],
            "other namespace"  => ["<tt xmlns=\"urn:example\"/>"],
        ];
    }


    #[DataProvider("invalidFileProvider")]
    public function testInvalidFileThrows(string $content): void
    {
        $this->expectException(ParsingException::class);
        Subtitle::fromString($content, Format::Ttml);
    }
}
