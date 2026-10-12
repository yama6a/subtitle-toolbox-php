<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class ShotChangeTimingTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/shot-changes/";


    /** @param list<array{int, int}> $frames */
    private function fromFrames(float $fps, array $frames): Subtitle
    {
        return TestSubtitles::fromTimes(array_map(fn (array $cue): array => [$cue[0] / $fps, $cue[1] / $fps], $frames));
    }


    /** @return list<array{int, int}> */
    private function getFrames(Subtitle $subtitle, float $fps): array
    {
        return array_map(fn (SubtitleCue $cue): array => [(int)round($cue->getStart() * $fps), (int)round($cue->getEnd() * $fps)],
                         $subtitle->getCues());
    }


    public static function frameRates(): array
    {
        return ["23.976 fps" => [23.976], "24 fps" => [24.0], "25 fps" => [25.0], "29.97 fps" => [29.97]];
    }


    public function testSnapsTheCuesOfARealFileToFfmpegShotChanges(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_garden_24fps.srt"), Format::SubRip);
        $cues     = $subtitle->getCues();
        $this->assertCount(11, $cues);
        $this->assertSame([10.0, 12.0, "The garden gate stays open\nuntil the evening."],
                          [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([90.5, 91.208, "Rain at four."], [$cues[10]->getStart(), $cues[10]->getEnd(), $cues[10]->getText()]);

        $shotChanges = ShotChanges::fromFfmpegLog(file_get_contents(self::FILES . "own_ffmpeg_showinfo.log"));
        $report      = ShotChangeTiming::apply($subtitle, new ShotChangeOptions(frameRate: 24, shotChanges: $shotChanges));

        $this->assertEquals(new ShotChangeReport(3, 5), $report);
        $this->assertSame(file_get_contents(self::FILES . "own_garden_24fps_timed.srt"), $subtitle->toString(Format::SubRip));
    }


    // A scan of the whole shot list per cue takes about 4.5 s here.
    public function testRealFileRepeatedTwentyThousandTimesSnapsUnderTwoSeconds(): void
    {
        $copies   = 1820;
        $garden   = Subtitle::fromString(file_get_contents(self::FILES . "own_garden_24fps.srt"), Format::SubRip);
        $gardenShots = ShotChanges::fromFfmpegLog(file_get_contents(self::FILES . "own_ffmpeg_showinfo.log"));
        // Shots in the 18 s between 2 copies, 2 s or more away from any cue.
        $gardenShots = [...$gardenShots, 93.0, 95.0, 97.0, 99.0, 101.0, 103.0];
        $cues     = [];
        $shots    = [];
        for ($copy = 0; $copy < $copies; $copy++) {
            foreach ($garden->getCues() as $cue) {
                $cues[] = new SubtitleCue($cue->getStart() + 100 * $copy, $cue->getEnd() + 100 * $copy, $cue->getText());
            }
            foreach ($gardenShots as $shot) {
                $shots[] = $shot + 100 * $copy;
            }
        }
        $subtitle = (new Subtitle())->addCues($cues);
        $this->assertCount(20020, $subtitle);
        $this->assertCount(20020, $shots);

        $start  = microtime(true);
        $report = ShotChangeTiming::apply($subtitle, new ShotChangeOptions(frameRate: 24, shotChanges: $shots));
        $this->assertLessThan(2, microtime(true) - $start);

        $this->assertEquals(new ShotChangeReport(3 * $copies, 5 * $copies), $report);
        $timed    = $this->getFrames(Subtitle::fromString(file_get_contents(self::FILES . "own_garden_24fps_timed.srt"), Format::SubRip), 24);
        $expected = [];
        for ($copy = 0; $copy < $copies; $copy++) {
            foreach ($timed as [$startFrame, $endFrame]) {
                $expected[] = [$startFrame + 2400 * $copy, $endFrame + 2400 * $copy];
            }
        }
        $this->assertSame($expected, $this->getFrames($subtitle, 24));
    }


    public function testNetflixExamplesAt24Fps(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(62.708, 65));
        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [62.5]));
        $this->assertSame(62.5, $subtitle->getCues()[0]->getStart());

        $subtitle = (new Subtitle())->addCue(new SubtitleCue(67, 69.75));
        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [70.0]));
        $this->assertSame(69.917, $subtitle->getCues()[0]->getEnd());

        $subtitle = (new Subtitle())->addCue(new SubtitleCue(8, 10))->addCue(new SubtitleCue(10.292, 12));
        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24));
        $this->assertSame(10.208, $subtitle->getCues()[0]->getEnd());
    }


    #[DataProvider("frameRates")]
    public function testInTimeAfterAShotChangeMovesToTheShotChange(float $fps): void
    {
        $subtitle = $this->fromFrames($fps, [[1505, 1560]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions($fps, shotChanges: [1500 / $fps]));

        $this->assertSame([[1500, 1560]], $this->getFrames($subtitle, $fps));
    }


    #[DataProvider("frameRates")]
    public function testOutTimeBeforeAShotChangeEndsTwoFramesBeforeIt(float $fps): void
    {
        $subtitle = $this->fromFrames($fps, [[1620, 1674], [1700, 1760]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions($fps, shotChanges: [1680 / $fps]));

        $this->assertSame([[1620, 1678], [1700, 1760]], $this->getFrames($subtitle, $fps));
    }


    #[DataProvider("frameRates")]
    public function testChainingClosesAGapOf7FramesTo2Frames(float $fps): void
    {
        $subtitle = $this->fromFrames($fps, [[200, 240], [247, 300]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions($fps));

        $this->assertSame([[200, 245], [247, 300]], $this->getFrames($subtitle, $fps));
    }


    #[DataProvider("frameRates")]
    public function testChainGapsClosesGapsOf3ToSnapWindowMinus1Frames(float $fps): void
    {
        $window   = (new ShotChangeOptions($fps))->snapWindowFrames;
        $subtitle = $this->fromFrames($fps, [[100, 140], [142, 180], [183, 220], [220 + $window - 1, 300],
                                               [300 + $window, 400]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions($fps));

        $this->assertSame([[100, 140], [142, 181], [183, 218 + $window - 1], [220 + $window - 1, 300],
                           [300 + $window, 400]], $this->getFrames($subtitle, $fps));
    }


    public function testSnapWindowDefaultsToHalfASecond(): void
    {
        $windows = array_map(fn (float $fps): int => (new ShotChangeOptions($fps))->snapWindowFrames, [23.976, 24, 25, 29.97, 30, 60]);

        $this->assertSame([12, 12, 12, 15, 15, 30], $windows);
    }


    public function testTimesOutsideTheSnapWindowStay(): void
    {
        $subtitle = $this->fromFrames(24, [[513, 560], [600, 627]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [500 / 24, 640 / 24]));

        $this->assertSame([[513, 560], [600, 627]], $this->getFrames($subtitle, 24));
    }


    public function testWindowOf15FramesAt2997Fps(): void
    {
        $subtitle = $this->fromFrames(29.97, [[515, 560], [1016, 1060]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(29.97, shotChanges: [500 / 29.97, 1000 / 29.97]));

        $this->assertSame([[500, 560], [1016, 1060]], $this->getFrames($subtitle, 29.97));
    }


    public function testInTimeTakesTheLastShotChangeBeforeIt(): void
    {
        $subtitle = $this->fromFrames(24, [[110, 160]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [104 / 24, 100 / 24, 112 / 24]));

        $this->assertSame([[104, 160]], $this->getFrames($subtitle, 24));
    }


    public function testMoveThatMakesACueShorterThanMinDurationDoesNotHappen(): void
    {
        $subtitle = $this->fromFrames(24, [[100, 119], [200, 215]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [120 / 24, 198 / 24]));

        $this->assertSame([[100, 119], [198, 215]], $this->getFrames($subtitle, 24));
    }


    public function testMinDurationIsAnOption(): void
    {
        $subtitle = $this->fromFrames(24, [[100, 119]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [120 / 24], minDurationFrames: 10));

        $this->assertSame([[100, 118]], $this->getFrames($subtitle, 24));
    }


    public function testMoveThatMakesACueOverlapAnotherCueDoesNotHappen(): void
    {
        $subtitle = $this->fromFrames(24, [[100, 150], [153, 229], [230, 260]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [158 / 24, 228 / 24], chain: false));

        $this->assertSame([[100, 150], [153, 229], [230, 260]], $this->getFrames($subtitle, 24));
    }


    public function testChainDoesNotRunIntoTheNextShot(): void
    {
        $subtitle = $this->fromFrames(24, [[140, 152], [158, 200]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [153 / 24]));

        $this->assertSame([[140, 152], [158, 200]], $this->getFrames($subtitle, 24));
    }


    public function testChainOption(): void
    {
        $subtitle = $this->fromFrames(24, [[200, 240], [247, 300]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, chain: false));

        $this->assertSame([[200, 240], [247, 300]], $this->getFrames($subtitle, 24));
    }


    public function testMinGapFramesOption(): void
    {
        $subtitle = $this->fromFrames(24, [[200, 240], [247, 300], [400, 450]]);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions(24, shotChanges: [455 / 24], minGapFrames: 3));

        $this->assertSame([[200, 244], [247, 300], [400, 452]], $this->getFrames($subtitle, 24));
    }


    #[DataProvider("frameRates")]
    public function testAllTimesFallOnFrameBoundaries(float $fps): void
    {
        mt_srand(7);
        $subtitle = new Subtitle();
        $shots    = [];
        $time     = 1.0;
        $cues     = [];
        for ($index = 0; $index < 200; $index++) {
            $start  = $time + mt_rand(0, 900) / 1000;
            $time   = $start + mt_rand(500, 4000) / 1000;
            $shots[] = $start + mt_rand(-600, 600) / 1000;
            $cues[] = new SubtitleCue($start, $time, "cue $index");
        }
        $subtitle->addCues($cues);

        ShotChangeTiming::apply($subtitle, new ShotChangeOptions($fps, shotChanges: $shots));

        $previousEnd = 0.0;
        foreach ($subtitle->getCues() as $cue) {
            foreach ([$cue->getStart(), $cue->getEnd()] as $seconds) {
                $this->assertSame(round(round($seconds * $fps) / $fps, 3), $seconds);
            }
            $this->assertGreaterThan($cue->getStart(), $cue->getEnd());
            $this->assertGreaterThanOrEqual($previousEnd, $cue->getStart());
            $previousEnd = $cue->getEnd();
        }
    }


    public static function invalidOptions(): array
    {
        return [
            "frame rate 0"     => [fn () => new ShotChangeOptions(0)],
            "negative window"  => [fn () => new ShotChangeOptions(24, snapWindowFrames: -1)],
            "negative gap"     => [fn () => new ShotChangeOptions(24, minGapFrames: -1)],
            "negative minimum" => [fn () => new ShotChangeOptions(24, minDurationFrames: -1)],
        ];
    }


    #[DataProvider("invalidOptions")]
    public function testInvalidOptionsThrow(\Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }
}
