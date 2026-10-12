<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class TtmlFormatterTest extends TestCase
{
    private const TWO_PARAGRAPHS = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\">\n"
                                   . "  <body>\n    <div>\n"
                                   . "      <p begin=\"00:00:01.500\" end=\"00:00:04.000\">Hello<br/><span tts:fontStyle=\"italic\">world</span></p>\n"
                                   . "      <p begin=\"5s\" dur=\"2500ms\">Second cue</p>\n"
                                   . "    </div>\n  </body>\n</tt>";


    public function testTwoParagraphsAreWrittenBack(): void
    {
        $subtitle = Subtitle::fromString(self::TWO_PARAGRAPHS, Format::Ttml);

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttm=\"http://www.w3.org/ns/ttml#metadata\""
            . " xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\">\n"
            . "  <head/>\n"
            . "  <body>\n    <div>\n"
            . "      <p begin=\"00:00:01.500\" end=\"00:00:04.000\">Hello<br/><span tts:fontStyle=\"italic\">world</span></p>\n"
            . "      <p begin=\"00:00:05.000\" end=\"00:00:07.500\">Second cue</p>\n"
            . "    </div>\n  </body>\n</tt>\n",
            $subtitle->toString(Format::Ttml)
        );
    }


    public function testSingleQuotedColourBecomesAColourSpan(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<font color='#ff0000'>red</font>"))->toString(Format::Ttml);

        $this->assertStringContainsString("<p begin=\"00:00:01.000\" end=\"00:00:02.000\" region=\"bottomCenter\"><span tts:color=\"#ff0000\">red</span></p>", $output);
    }


    public function testSubtitleFromAnotherFormatGetsRegionsAgentsAndSpans(): void
    {
        $srt      = "1\n00:00:01,000 --> 00:00:02,000\n{\\an8}<v Fred>Hi & <b>bold <i>both</i></b>\n<font color=\"#ff0000\">red</font> <u>u</u> <s>s</s>\n\n"
                    . "2\n00:00:03,000 --> 00:00:04,000\nplain\n";
        $subtitle = Subtitle::fromString($srt, Format::SubRip)
                            ->setMetadata(Subtitle::METADATA_TITLE, "Trains & buses")
                            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en");

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttm=\"http://www.w3.org/ns/ttml#metadata\""
            . " xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\">\n"
            . "  <head>\n"
            . "    <ttm:title>Trains &amp; buses</ttm:title>\n"
            . "    <ttm:agent xml:id=\"agent1\" type=\"person\"><ttm:name type=\"full\">Fred</ttm:name></ttm:agent>\n"
            . "    <layout>\n"
            . "      <region xml:id=\"topCenter\" tts:origin=\"10% 10%\" tts:extent=\"80% 80%\" tts:displayAlign=\"before\" tts:textAlign=\"center\"/>\n"
            . "      <region xml:id=\"bottomCenter\" tts:origin=\"10% 10%\" tts:extent=\"80% 80%\" tts:displayAlign=\"after\" tts:textAlign=\"center\"/>\n"
            . "    </layout>\n"
            . "  </head>\n"
            . "  <body>\n    <div>\n"
            . "      <p begin=\"00:00:01.000\" end=\"00:00:02.000\" region=\"topCenter\" ttm:agent=\"agent1\">"
            . "Hi &amp; <span tts:fontWeight=\"bold\">bold <span tts:fontStyle=\"italic\">both</span></span><br/>"
            . "<span tts:color=\"#ff0000\">red</span> <span tts:textDecoration=\"underline\">u</span>"
            . " <span tts:textDecoration=\"lineThrough\">s</span></p>\n"
            . "      <p begin=\"00:00:03.000\" end=\"00:00:04.000\" region=\"bottomCenter\">plain</p>\n"
            . "    </div>\n  </body>\n</tt>\n",
            $subtitle->toString(Format::Ttml)
        );
    }


    public function testChangedAlignmentReplacesTheStoredRegion(): void
    {
        $path     = __DIR__ . "/../files/ttml/real/pysubs2_regions.ttml";
        $subtitle = Subtitle::load($path, Format::Ttml);
        $subtitle->getCues()[0]->setAlignment(Alignment::BottomCenter);
        $subtitle->getCues()[1]->setAlignment(Alignment::TopRight);

        $output    = $subtitle->toString(Format::Ttml);
        $unchanged = Subtitle::load($path, Format::Ttml)->toString(Format::Ttml);

        $this->assertStringContainsString('<p xml:id="subtitle1" begin="00:00:01.375" end="00:00:05.750" style="s1" region="bottomCenter">', $output);
        $this->assertStringContainsString('<p xml:id="subtitle2" begin="00:00:05.916" end="00:00:10.216" style="s1" tts:textAlign="right" region="topRight">', $output);
        $this->assertSame(
            array_slice(explode("\n", $unchanged), -11),
            array_slice(explode("\n", $output), -11)
        );
        $this->assertSame(
            [Alignment::BottomCenter, Alignment::TopRight, Alignment::TopCenter, Alignment::BottomCenter],
            array_map(fn (SubtitleCue $cue): Alignment => $cue->getAlignment(), array_slice(Subtitle::fromString($output, Format::Ttml)->getCues(), 0, 4))
        );
    }


    public function testOutputParsesBackToTheSameCues(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue((new SubtitleCue(1, 2, "<v Ann>one</v> <v Ben>two\n<b>bold <i>open"))->setAlignment(Alignment::MiddleLeft));
        $subtitle->addCue(new SubtitleCue(3, 4, "<i>mis<b>nested</i>tags</b> &lt;3 <c.yellow>class</c> <00:00:03.500>word"));

        $reparsed = Subtitle::fromString($subtitle->toString(Format::Ttml), Format::Ttml);
        $cues     = $reparsed->getCues();

        $this->assertSame("<v Ann>one </v><v Ben>two\n<b>bold <i>open</i></b>", $cues[0]->getText());
        $this->assertSame(Alignment::MiddleLeft, $cues[0]->getAlignment());
        $this->assertSame("<i>mis</i><b><i>nested</i>tags</b> &lt;3 <font color=\"#ffff00\">class</font> word", $cues[1]->getText());
        $this->assertSame(Alignment::BottomCenter, $cues[1]->getAlignment());
    }


    public function testAllXmlTagsAreStrippedAwayIfOptionIsSet(): void
    {
        $subtitle = Subtitle::fromString(self::TWO_PARAGRAPHS, Format::Ttml);

        $this->assertStringContainsString(
            "<p begin=\"00:00:01.500\" end=\"00:00:04.000\">Hello<br/>world</p>",
            $subtitle->toString(Format::Ttml, new WriteOptions(stripTags: true))
        );
    }


    public function testKeepsTextThatLooksLikeATag(): void
    {
        $output = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["a < b > c", "<i>a</i>< b > c"]))->toString(Format::Ttml);

        $this->assertStringContainsString(
            "<p begin=\"00:00:01.000\" end=\"00:00:02.000\" region=\"bottomCenter\">a &lt; b &gt; c<br/>"
            . "<span tts:fontStyle=\"italic\">a</span>&lt; b &gt; c</p>",
            $output
        );
    }


    public function testDfxpFileKeepsItsNamespaceAndAgents(): void
    {
        $dfxp     = "<tt xmlns=\"http://www.w3.org/2006/10/ttaf1\" xmlns:m=\"http://www.w3.org/2006/10/ttaf1#metadata\""
                    . " xmlns:ttp=\"http://www.w3.org/2006/10/ttaf1#parameter\" ttp:timeBase=\"smpte\" ttp:frameRate=\"25\">\n"
                    . "  <head>\n    <m:agent xml:id=\"x\"><m:name>Ann</m:name></m:agent>\n  </head>\n"
                    . "  <body><div><p begin=\"00:00:01:00\" end=\"00:00:02:00\" m:agent=\"x\">Hi</p></div></body>\n</tt>";
        $subtitle = Subtitle::fromString($dfxp, Format::Ttml);
        $output   = $subtitle->toString(Format::Ttml);

        $this->assertStringContainsString(
            "<tt xmlns=\"http://www.w3.org/2006/10/ttaf1\" xmlns:m=\"http://www.w3.org/2006/10/ttaf1#metadata\""
            . " xmlns:ttp=\"http://www.w3.org/2006/10/ttaf1#parameter\" xmlns:tts=\"http://www.w3.org/2006/10/ttaf1#style\""
            . " xml:lang=\"\" ttp:frameRate=\"25\">",
            $output
        );
        $this->assertStringContainsString("<p begin=\"00:00:01.000\" end=\"00:00:02.000\" m:agent=\"x\">Hi</p>", $output);
        $this->assertSame(1, substr_count($output, "<m:agent "));
    }


    public function testChangedTitleReplacesTheStoredTitle(): void
    {
        $ttml     = "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttm=\"http://www.w3.org/ns/ttml#metadata\">\n"
                    . "  <head>\n    <metadata>\n      <ttm:title>Old</ttm:title>\n    </metadata>\n  </head>\n"
                    . "  <body><div><p begin=\"0s\" end=\"1s\">x</p></div></body>\n</tt>";
        $subtitle = Subtitle::fromString($ttml, Format::Ttml)->setMetadata(Subtitle::METADATA_TITLE, "New");
        $output   = $subtitle->toString(Format::Ttml);

        $this->assertStringContainsString("<head>\n    <ttm:title>New</ttm:title>\n    <metadata>\n    </metadata>\n  </head>", $output);
        $this->assertStringNotContainsString("Old", $output);
    }


    public function testEmptySubtitle(): void
    {
        $this->assertStringContainsString("<body>\n    <div/>\n  </body>", (new Subtitle())->toString(Format::Ttml));
    }
}
