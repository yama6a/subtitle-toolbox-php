<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\ReadOptions;

class SbvFormatterTest extends TestCase
{
    public function testValidFileRoundTrips(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/valid.sbv"), Format::Sbv);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/sbv/valid.sbv"),
            $subtitle->toString(Format::Sbv)
        );
    }


    public function testHoursAreWrittenWithoutLeadingZero(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/two_digit_hours.sbv"), Format::Sbv);

        $this->assertSame(
            "0:00:01.500,0:00:04.000\nHello world\n\n1:00:05.000,1:00:07.250\nSecond cue\non two lines\n",
            $subtitle->toString(Format::Sbv)
        );
    }


    public function testSubRipConvertsToSbvWithoutMarkup(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/sbv/from_srt.sbv"),
            $subtitle->toString(Format::Sbv)
        );
    }


    public function testMarkupIsStrippedAndEntitiesAreDecoded(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Tom</i> &amp; <b>Jerry</b>", "&lt;3 caf&eacute; &quot;ok&quot; &#39;yes&#39;"]));

        $this->assertSame(
            "0:00:01.000,0:00:02.000\nTom & Jerry\n<3 café \"ok\" 'yes'\n",
            $subtitle->toString(Format::Sbv)
        );
    }


    public function testEscapedCoreTextIsWrittenAsPlainText(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "I &lt;3 bread &amp; jam"));

        $this->assertSame("0:00:01.000,0:00:02.000\nI <3 bread & jam\n", $subtitle->toString(Format::Sbv));
    }


    public function testLinesThatAreEmptyAfterStrippingAreDropped(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i></i>", "Text"]))
            ->addCue(new SubtitleCue(3, 4, ["<b> </b>"]))
            ->addCue(new SubtitleCue(5, 6, "Last"));

        $this->assertSame(
            "0:00:01.000,0:00:02.000\nText\n\n0:00:05.000,0:00:06.000\nLast\n",
            $subtitle->toString(Format::Sbv)
        );
    }


    public function testOutputHasNoUtf8Bom(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Text"));

        $this->assertStringStartsWith("0:00:01.000", $subtitle->toString(Format::Sbv));
    }


    public function testATextLineInTheShapeOfATimingLineReadsBackAsText(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["0:00:03.500,0:00:03.800", "0:00:03.5, then"]))
            ->addCue(new SubtitleCue(5, 6, ["c"]));
        $output = $subtitle->toString(Format::Sbv);

        foreach ([new ReadOptions(), new ReadOptions(lenient: true)] as $options) {
            $cues = Subtitle::fromString($output, Format::Sbv, $options)->getCues();
            $this->assertCount(2, $cues);
            $this->assertSame(["\u{2060}0:00:03.500,0:00:03.800", "\u{2060}0:00:03.5, then"], $cues[0]->getLines());
        }
    }
}
