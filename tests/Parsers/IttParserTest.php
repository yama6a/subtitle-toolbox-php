<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

class IttParserTest extends TestCase
{
    private const SMPTE_24FPS_DOCUMENT = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                                         . "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:ttp=\"http://www.w3.org/ns/ttml#parameter\"\n"
                                         . "    xmlns:tts=\"http://www.w3.org/ns/ttml#styling\" xml:lang=\"en\"\n"
                                         . "    ttp:timeBase=\"smpte\" ttp:frameRate=\"24\" ttp:frameRateMultiplier=\"999 1000\" ttp:dropMode=\"nonDrop\">\n"
                                         . "  <head>\n"
                                         . "    <styling><style xml:id=\"normal\" tts:fontFamily=\"sansSerif\" tts:color=\"white\" tts:fontSize=\"100%\"/></styling>\n"
                                         . "    <layout>\n"
                                         . "      <region xml:id=\"top\" tts:origin=\"0% 0%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\" tts:displayAlign=\"before\"/>\n"
                                         . "      <region xml:id=\"bottom\" tts:origin=\"0% 85%\" tts:extent=\"100% 15%\" tts:textAlign=\"center\" tts:displayAlign=\"after\"/>\n"
                                         . "    </layout>\n"
                                         . "  </head>\n"
                                         . "  <body region=\"bottom\" style=\"normal\">\n"
                                         . "    <div><p begin=\"00:00:01:12\" end=\"00:00:04:00\">Hello<br/><span tts:fontStyle=\"italic\">world</span></p></div>\n"
                                         . "  </body>\n"
                                         . "</tt>";


    public function testSmpteTimesUseTheFrameRateAndMultiplier(): void
    {
        $subtitle = Subtitle::fromString(self::SMPTE_24FPS_DOCUMENT, Format::Itt);
        $cue      = $subtitle->getCues()[0];

        $this->assertSame(1.502, $cue->getStart());
        $this->assertSame([4.004, ["Hello", "<i>world</i>"], Alignment::BottomCenter], [$cue->getEnd(), $cue->getLines(), $cue->getAlignment()]);
        $this->assertSame(
            ["timeBase" => "smpte", "frameRate" => "24", "frameRateMultiplier" => "999 1000", "dropMode" => "nonDrop"],
            $subtitle->findFormatData(IttParser::FORMAT_DATA_KEY)
        );
    }


    public function testKeepsTheTtmlFormatData(): void
    {
        $ttml = Subtitle::fromString(self::SMPTE_24FPS_DOCUMENT, Format::Ttml);
        $itt  = Subtitle::fromString(self::SMPTE_24FPS_DOCUMENT, Format::Itt);

        $this->assertSame($ttml->findFormatData(TtmlParser::FORMAT_DATA_KEY), $itt->findFormatData(TtmlParser::FORMAT_DATA_KEY));
        $this->assertSame([], $ttml->findFormatData(IttParser::FORMAT_DATA_KEY));
    }


    public function testReadsParametersWithAnotherPrefix(): void
    {
        $subtitle = Subtitle::fromString(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\" xmlns:p=\"http://www.w3.org/ns/ttml#parameter\" xmlns:x=\"urn:example\""
            . " p:timeBase=\"smpte\" p:frameRate=\"25\" x:frameRate=\"50\"><body><div><p begin=\"00:00:01:05\" end=\"00:00:02:00\">a</p></div></body></tt>",
            Format::Itt);

        $this->assertSame(["timeBase" => "smpte", "frameRate" => "25"], $subtitle->findFormatData(IttParser::FORMAT_DATA_KEY));
        $this->assertSame(1.2, $subtitle->getCues()[0]->getStart());
    }


    public function testFileWithoutTimingParametersHasNoFormatData(): void
    {
        $subtitle = Subtitle::fromString(
            "<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div><p begin=\"1s\" end=\"2s\">a</p></div></body></tt>",
            Format::Itt);

        $this->assertSame([], $subtitle->findFormatData(IttParser::FORMAT_DATA_KEY));
    }
}
