<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class TmPlayerFormatterTest extends TestCase
{
    public function testWritesStartTimesAndAnEmptyLineAtTheEndOfACueBeforeAGap(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 4, ["Where are you?", "Home."]))
            ->addCue(new SubtitleCue(4, 6, "Next"))
            ->addCue(new SubtitleCue(3725.4, 3727, "Later"))
            ->addCue(new SubtitleCue(3727.6, 3730, "Last"));

        $this->assertSame(
            "00:00:01:Where are you?|Home.\n00:00:04:Next\n00:00:06:\n01:02:05:Later\n01:02:07:\n01:02:08:Last\n",
            $subtitle->toString(Format::TmPlayer)
        );
    }


    public function testAShortCueEndsOneSecondAfterItsStart(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1.1, 1.3, "Short"))
            ->addCue(new SubtitleCue(5, 6, "Next"));

        $this->assertSame("00:00:01:Short\n00:00:02:\n00:00:05:Next\n", $subtitle->toString(Format::TmPlayer));
    }


    public function testStripsTagsAndSkipsCuesWithoutText(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Rain</i> &amp; <b>wind</b>"]))
            ->addCue(new SubtitleCue(2, 3, ["<b></b>"]))
            ->addCue(new SubtitleCue(3, 4, "Sun"));

        $this->assertSame("00:00:01:Rain & wind\n00:00:02:\n00:00:03:Sun\n", $subtitle->toString(Format::TmPlayer));
    }


    public function testAppliesTheOutputOptions(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(0, 1, "Hello"));

        $this->assertSame("\xEF\xBB\xBF00:00:00:Hello\r\n", $subtitle->toString(Format::TmPlayer, new WriteOptions(lineEnding: LineEnding::Crlf, bom: true)));
    }


    public function testATimeJustBelowTheLimitStaysBelowItAndReadsBack(): void
    {
        $output = Subtitle::fromString("WEBVTT\n\n99999:59:59.900 --> 99999:59:59.999\nx\n", Format::WebVtt)->toString(Format::TmPlayer);

        $this->assertSame("99999:59:59:x\n", $output);
        $this->assertSame(359999999.0, Subtitle::fromString($output, Format::TmPlayer)->getCues()[0]->getStart());
    }


    public function testALineFeedAndABarInsideALineReadBackAsOneLineEach(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["a\nb", "Rock | Pop"]))
            ->addCue(new SubtitleCue(3, 4, ["|"]));
        $output = $subtitle->toString(Format::TmPlayer);

        $this->assertSame("00:00:01:a|b|Rock \u{00A6} Pop\n00:00:02:\n00:00:03:\u{00A6}\n", $output);
        $cues = Subtitle::fromString($output, Format::TmPlayer)->getCues();
        $this->assertCount(2, $cues);
        $this->assertSame(["a", "b", "Rock \u{00A6} Pop"], $cues[0]->getLines());
    }
}
