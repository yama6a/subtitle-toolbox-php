<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Format;
use SubtitleToolbox\SubtitleCue;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

class LyricsFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectly()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"), Format::Lyrics);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testUnsupportedXmlTagsAreStrippedAway()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), Format::Lyrics);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags_formatted.lrc"),
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testCentisecondsRoundUpIntoTheNextSecond(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(59.996, 61, "Text"));

        $this->assertSame("\u{feff}[01:00.00] Text\n", $subtitle->toString(Format::Lyrics));
    }


    public function testCommentsAreWrittenInPlace(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "First"))
            ->addCue(new SubtitleCue(3, 4, "Second"))
            ->setMetadata(Subtitle::METADATA_ARTIST, "Station Choir")
            ->addComment("Before second", 1)
            ->addComment("Two\nlines", 2);

        $this->assertSame(
            "\u{feff}[ar:Station Choir]\n[00:01.00] First\n[#:Before second]\n[00:03.00] Second\n[#:Two lines]\n",
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testWordTimestampsAreWrittenAsLrcWordTimestamps(): void
    {
        $subtitle = (new Subtitle())->addCue(
            new SubtitleCue(21.1, 25, "<i><00:00:21.100>Bread</i> <00:00:21.600>is <01:01:01.905>warm")
        );

        $this->assertSame(
            "\u{feff}[00:21.10] <00:21.10>Bread <00:21.60>is <61:01.91>warm\n",
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testTimestampWithoutTextIsWrittenBack(): void
    {
        $subtitle = Subtitle::fromString("[00:01.00] First\n[00:02.50]\n[00:04.00] Second\n", Format::Lyrics);

        $this->assertSame(
            "\u{feff}[00:01.00] First\n[00:02.50]\n[00:04.00] Second\n",
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testFullExampleRoundTrip(): void
    {
        $subtitle = Subtitle::fromString(
            "[ti:Morning Train]\n[ar:Station Choir]\n[offset:+500]\n" .
            "[00:12.00][01:15.30]The train leaves the station at seven\n" .
            "[00:17.20]\n" .
            "[00:21.10]<00:21.10>Bread <00:21.60>is <00:21.90>warm\n",
            Format::Lyrics);

        $this->assertSame(
            "\u{feff}[ti:Morning Train]\n[ar:Station Choir]\n" .
            "[00:11.50] The train leaves the station at seven\n" .
            "[00:16.70]\n" .
            "[00:20.60] <00:20.60>Bread <00:21.10>is <00:21.40>warm\n" .
            "[01:14.80] The train leaves the station at seven\n",
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testEscapedTextRoundTripsByteForByte(): void
    {
        $lrc      = "\u{feff}[ti:Fish & Chips]\n[#:a < b & c]\n[00:01.00] I <3 bread & jam\n" .
            "[00:03.00] <00:03.00>Fish & <00:03.50>chips &amp; <1:2>\n";
        $subtitle = Subtitle::fromString($lrc, Format::Lyrics);

        $this->assertSame($lrc, $subtitle->toString(Format::Lyrics));
    }


    public function testEntitiesFromOtherFormatsAreDecoded(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "I &lt;3 bread &amp; jam"))
            ->addCue(new SubtitleCue(3, 4, "<b><00:00:03.000>Fish</b> &amp; <00:00:03.500>chips"));

        $this->assertSame(
            "\u{feff}[00:01.00] I <3 bread & jam\n[00:03.00] <00:03.00>Fish & <00:03.50>chips\n",
            $subtitle->toString(Format::Lyrics)
        );
    }


    public function testSubtitleWithoutMetadataGetsNoIdTags(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Text"));

        $this->assertSame("\u{feff}[00:01.00] Text\n", $subtitle->toString(Format::Lyrics));
    }


    public function testALineFeedInsideALineBecomesASpace(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["a\nb"]))
            ->addCue(new SubtitleCue(3, 4, ["c"]));
        $cues = Subtitle::fromString($subtitle->toString(Format::Lyrics), Format::Lyrics)->getCues();

        $this->assertSame(["a b"], $cues[0]->getLines());
        $this->assertSame(["c"], $cues[1]->getLines());
    }


    public function testWordTimestampEarlierThanTheOneBeforeItIsLeftOut(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0, 5, "<00:00:03.000>a <00:00:02.000>b <00:00:04.000>c"));

        $this->assertSame("[00:00.00] <00:03.00>a b <00:04.00>c\n", $subtitle->toString(Format::Lyrics, new WriteOptions(bom: false)));
    }


    public function testTimesAreClampedToTheLastTimeTheParserReads(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(72000, 72001, "late <20:00:00.500>w"));
        $lrc      = $subtitle->toString(Format::Lyrics, new WriteOptions(bom: false));

        $this->assertSame("[999:59.99] late <999:59.99>w\n", $lrc);
        $this->assertCount(1, Subtitle::fromString($lrc, Format::Lyrics)->getCues());
    }


    public function testStoredIdTagsThatDoNotReadBackAreSkipped(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "First"))->addCue(new SubtitleCue(3, 4, "Second"));
        $subtitle->setFormatData("lrc", ["idTags" => ["offset" => "500", "a]\n[00:05.00]Injected\n[b" => "x", "my tag" => "y", "12" => "z", "by" => "me"]]);
        $lrc = $subtitle->toString(Format::Lyrics, new WriteOptions(bom: false));

        $this->assertSame("[by:me]\n[00:01.00] First\n[00:03.00] Second\n", $lrc);
    }
}
