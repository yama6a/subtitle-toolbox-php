<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class SubRipFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectly()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->toString(Format::SubRip)
        );
    }


    public function testUnsupportedXmlTagsAreStrippedAway()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/strip_xml.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->toString(Format::SubRip)
        );
    }


    public function testAllXmlTagsAreStrippedAwayIfOptionIsSet()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/strip_xml.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/all_tags_stripped.srt"),
            $subtitle->toString(Format::SubRip, new WriteOptions(stripTags: true))
        );
    }


    public function testStrikethroughTagIsKept(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<s>struck</s> <c.loud>plain</c>"));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\n<s>struck</s> plain\n",
            $subtitle->toString(Format::SubRip)
        );
        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nstruck plain\n",
            $subtitle->toString(Format::SubRip, new WriteOptions(stripTags: true))
        );
    }


    public function testTimestampsAreWrittenInTheStandardForm(): void
    {
        $subtitle = Subtitle::fromString("1\n0:00:01.5 --> 0:00:02.25\nText\n", Format::SubRip);

        $this->assertSame(
            "\u{feff}1\n00:00:01,500 --> 00:00:02,250\nText\n",
            $subtitle->toString(Format::SubRip)
        );
    }


    public function testCoordinatesAreWrittenBack(): void
    {
        $raw      = "\u{feff}1\n00:00:01,000 --> 00:00:04,000 X1:100 X2:600 Y1:40 Y2:80\nText\n";
        $subtitle = Subtitle::fromString($raw, Format::SubRip);

        $this->assertSame($raw, $subtitle->toString(Format::SubRip));
    }


    public function testAlignmentIsWrittenAtTheStartOfTheFirstLine(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:01,000 --> 00:00:04,000\n<i>The train</i> {\\an8}leaves\nsoon\n", Format::SubRip);

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:04,000\n{\\an8}<i>The train</i> leaves\nsoon\n",
            $subtitle->toString(Format::SubRip)
        );
        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:04,000\n{\\an8}The train leaves\nsoon\n",
            $subtitle->toString(Format::SubRip, new WriteOptions(stripTags: true))
        );
    }


    public function testDefaultAlignmentIsNotWritten(): void
    {
        $subtitle = (new Subtitle())
            ->addCue((new SubtitleCue(1, 2, "Bottom center"))->setAlignment(Alignment::BottomCenter))
            ->addCue(new SubtitleCue(3, 4, "Default"));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nBottom center\n\n2\n00:00:03,000 --> 00:00:04,000\nDefault\n",
            $subtitle->toString(Format::SubRip)
        );
    }


    public function testLineThatBecomesEmptyIsDropped(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["Before", "<c.loud></c>", "After"]));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nBefore\nAfter\n",
            $subtitle->toString(Format::SubRip)
        );
    }


    public function testEntitiesAreDecoded(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["I &lt;3 bread &amp; jam", "<i>Salt &amp; pepper</i> 2 &gt; 1"]));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nI <3 bread & jam\n<i>Salt & pepper</i> 2 > 1\n",
            $subtitle->toString(Format::SubRip)
        );
        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nI <3 bread & jam\nSalt & pepper 2 > 1\n",
            $subtitle->toString(Format::SubRip, new WriteOptions(stripTags: true))
        );
    }


    public function testTextWithLessThanAndAmpersandSurvivesARoundTrip(): void
    {
        $raw = "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nI <3 bread & jam\n";

        $this->assertSame($raw, Subtitle::fromString($raw, Format::SubRip)->toString(Format::SubRip));
    }


    public function testLineThatIsNotUtf8KeepsItsBytes(): void
    {
        $raw = "\u{feff}1\n00:00:01,000 --> 00:00:02,000\ncaf\xE9 & <i>cr\xE8me</i>\n";

        $subtitle = Subtitle::fromString($raw, Format::SubRip);

        $this->assertSame(["caf\xE9 &amp; <i>cr\xE8me</i>"], $subtitle->getCues()[0]->getLines());
        $this->assertSame($raw, $subtitle->toString(Format::SubRip));
    }
}
