<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\ReferenceSyncFixtures;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class ReferenceSyncSplitTest extends TestCase
{
    use ReferenceSyncFixtures;


    public function testRealFileWithAdBreakParses(): void
    {
        $cues = $this->load("own_reference_en_tv_break.srt")->getCues();

        $this->assertCount(60, $cues);
        $this->assertSame([77.689, 79.205, "The bakery opens at six every morning."],
                          [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([348.029, 350.893], [$cues[27]->getStart(), $cues[27]->getEnd()]);
        $this->assertSame([579.717, 581.104], [$cues[28]->getStart(), $cues[28]->getEnd()]);
        $this->assertSame([950.651, 954.214, "Good morning, the usual please."],
                          [$cues[59]->getStart(), $cues[59]->getEnd(), $cues[59]->getText()]);
    }


    public function testFindsTheAdBreakSplit(): void
    {
        $reference = $this->load("own_reference_en_tv_break.srt");
        $target    = $this->load("own_target_de_25fps.srt");
        $before    = TestSubtitles::times($target);

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference, -180, 180, maxSplits: 2));

        $segments = $result->getSegments();
        $this->assertCount(2, $segments);
        $this->assertSame([0.0, 414.32], [$segments[0]["from"], $segments[0]["to"]]);
        $this->assertSame([414.32, INF], [$segments[1]["from"], $segments[1]["to"]]);
        $this->assertEqualsWithDelta(-2.3, $segments[0]["offset"], 0.02);
        $this->assertEqualsWithDelta(147.7, $segments[1]["offset"], 0.02);
        $this->assertEqualsWithDelta(25 / 23.976, $segments[1]["scale"], 0.00001);
        $this->assertSame($segments[0]["offset"], $result->offset);
        $this->assertGreaterThan(0.8, $result->score);
        $this->assertNotSame($before, TestSubtitles::times($target));
    }


    public function testApplyShiftsEachPartAndMatchesExpectedFile(): void
    {
        $reference = $this->load("own_reference_en_tv_break.srt");
        $target    = $this->load("own_target_de_25fps.srt");

        ReferenceSync::apply($target, new ReferenceSyncOptions($reference, -180, 180, maxSplits: 2));
        $this->assertSame(file_get_contents(__DIR__ . "/../files/sync/own_target_de_split_synced.srt"),
                          $target->toString(Format::SubRip));

        $referenceStarts = array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $reference->getCues());
        $matched         = 0;
        foreach ($target->getCues() as $cue) {
            $distance = min(array_map(fn (float $start): float => abs($start - $cue->getStart()), $referenceStarts));
            if ($distance < 1) {
                $this->assertLessThanOrEqual(0.0501, $distance);
                $matched++;
            }
        }
        $this->assertSame(57, $matched);
    }


    public function testAPenaltyAboveTheGainOfTheSplitKeepsOnePart(): void
    {
        $target = $this->load("own_target_de_25fps.srt");

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($this->load("own_reference_en_tv_break.srt"), -180, 180,
                                                                         maxSplits: 2, splitPenalty: 5));

        $this->assertCount(1, $result->getSegments());
        $this->assertStringEqualsFile(__DIR__ . "/../files/sync/own_target_de_split_penalty_synced.srt", $target->toString(Format::SubRip));
    }


    public function testWithoutSplitsTheAdBreakFileDoesNotSync(): void
    {
        $result = ReferenceSync::apply($this->load("own_target_de_25fps.srt"),
                                       new ReferenceSyncOptions($this->load("own_reference_en_tv_break.srt"), -180, 180));

        $this->assertCount(1, $result->getSegments());
        $this->assertLessThan(0.5, $result->score);
    }


    public function testHighPenaltyKeepsOnePart(): void
    {
        $result = ReferenceSync::apply($this->load("own_target_de_25fps.srt"),
                                       new ReferenceSyncOptions($this->load("own_reference_en_tv_break.srt"), -180, 180,
                                                                maxSplits: 2, splitPenalty: 0.5));

        $this->assertCount(1, $result->getSegments());
    }


    public function testFileWithoutSplitKeepsTheResultOfNoSplitSearch(): void
    {
        $reference = $this->load("own_reference_en.srt");
        $target    = $this->load("own_target_de_25fps.srt");

        $this->assertEquals(ReferenceSync::apply(clone $target, new ReferenceSyncOptions($reference)),
                            ReferenceSync::apply($target, new ReferenceSyncOptions($reference, maxSplits: 2)));
    }


    public function testUnrelatedFilesScoreBelowHalfWithSplits(): void
    {
        $result = ReferenceSync::apply($this->makeRandomSubtitle(300, 1),
                                       new ReferenceSyncOptions($this->makeRandomSubtitle(300, 2), maxSplits: 2));

        $this->assertLessThan(0.5, $result->score);
    }


    public function testSegmentsWithoutSplit(): void
    {
        $this->assertSame([["from" => 0.0, "to" => INF, "scale" => 1.04, "offset" => -2.5]],
                          (new ReferenceSyncReport(-2.5, 1.04, 0.9))->getSegments());
    }


    private static function retimeSegments(Subtitle $subtitle, ReferenceSyncReport $result): void
    {
        (new \ReflectionMethod(ReferenceSync::class, "retimeSegments"))->invoke(null, $subtitle, $result->getSegments());
    }


    public function testApplyEndsTheEarlierCueOneMillisecondBeforeTheLaterPart(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(10, 14, "one"))
                                    ->addCue(new SubtitleCue(20, 22, "two"))
                                    ->addCue(new SubtitleCue(25, 27, "three"));
        $result   = new ReferenceSyncReport(5, 1, 0.9, [["from" => 0.0, "offset" => 5.0], ["from" => 20.0, "offset" => -3.0]]);

        self::retimeSegments($subtitle, $result);

        $this->assertSame([[15.0, 16.999], [17.0, 19.0], [22.0, 24.0]], TestSubtitles::times($subtitle));
    }


    public function testApplySortsPartsThatSwapOrder(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(10, 12, "one"))
                                    ->addCue(new SubtitleCue(20, 22, "two"));
        $result   = new ReferenceSyncReport(15, 1, 0.9, [["from" => 0.0, "offset" => 15.0], ["from" => 20.0, "offset" => -15.0]]);

        self::retimeSegments($subtitle, $result);

        $this->assertSame(["two", "one"], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()));
        $this->assertSame([[5.0, 7.0], [25.0, 27.0]], TestSubtitles::times($subtitle));
    }


    public function testTwoThousandCuesWithTwoSplitsFinishUnderFifteenSeconds(): void
    {
        $reference = $this->makeRandomSubtitle(2000, 6);
        $cues      = $reference->getCues();
        $reference->shift(30, $cues[700]->getStart())->shift(-20, $cues[1400]->getStart());
        $target    = $this->makeRandomSubtitle(2000, 6)->scale(23.976 / 25)->shift(12.4);
        $splits    = array_map(fn (int $index): float => $target->getCues()[$index]->getStart(), [700, 1400]);

        $start  = microtime(true);
        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference, maxSplits: 2));

        $this->assertLessThan(15, microtime(true) - $start);
        $segments = $result->getSegments();
        $this->assertSame([0.0, $splits[0], $splits[1]], array_column($segments, "from"));
        $this->assertEqualsWithDelta(25 / 23.976, $result->scale, 0.00001);
        $this->assertEqualsWithDelta(-12.4 * 25 / 23.976, $segments[0]["offset"], 0.02);
        $this->assertEqualsWithDelta(-12.4 * 25 / 23.976 + 30, $segments[1]["offset"], 0.02);
        $this->assertEqualsWithDelta(-12.4 * 25 / 23.976 + 10, $segments[2]["offset"], 0.02);
        $this->assertEqualsWithDelta(1, $result->score, 0.001);
    }
}
