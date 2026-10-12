<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class RetimingTest extends \PHPUnit\Framework\TestCase
{
    private const EDITING = __DIR__ . "/files/editing/";


    public function testShiftMovesAllCues(): void
    {
        $subtitle = TestSubtitles::fromTimes([[10, 12.5], [20.25, 22]]);

        $this->assertSame($subtitle, $subtitle->shift(-2.5));
        $this->assertSame([[7.5, 10.0], [17.75, 19.5]], TestSubtitles::times($subtitle));
    }


    public function testShiftOnlyMovesCuesFromGivenTime(): void
    {
        $subtitle = TestSubtitles::fromTimes([[10, 12.5], [20, 22], [30, 31]]);

        $subtitle->shift(1.25, 20);

        $this->assertSame([[10.0, 12.5], [21.25, 23.25], [31.25, 32.25]], TestSubtitles::times($subtitle));
    }


    public function testShiftClampsNegativeTimesToZeroAndKeepsCues(): void
    {
        $subtitle = TestSubtitles::fromTimes([[0.5, 1.5], [1, 3], [5, 6]]);

        $subtitle->shift(-2);

        $this->assertSame([[0.0, 0.0], [0.0, 1.0], [3.0, 4.0]], TestSubtitles::times($subtitle));
        $this->assertSame("text0", $subtitle->getCues()[0]->getText());
    }


    public function testScale(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1.5, 2.25]]);

        $this->assertSame($subtitle, $subtitle->scale(2));
        $this->assertSame([[3.0, 4.5]], TestSubtitles::times($subtitle));
    }


    public function testScaleByZeroThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The scale factor must be greater than 0, got 0.");
        TestSubtitles::fromTimes([[1, 2]])->scale(0);
    }


    public function testConvertFrameRateFromPalToFilm(): void
    {
        $subtitle = TestSubtitles::fromTimes([[100, 102.5]]);

        $this->assertSame($subtitle, $subtitle->convertFrameRate(25, 23.976));
        $this->assertSame([[104.271, 106.878]], TestSubtitles::times($subtitle));
    }


    public function testConvertFrameRateWithZeroFpsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The frame rate must be greater than 0, got 0.");
        TestSubtitles::fromTimes([[1, 2]])->convertFrameRate(25, 0);
    }


    public function testShiftWithoutARangeMovesEveryCueAndWordTimestamp(): void
    {
        $subtitle = Subtitle::load(self::EDITING . "own_ferry_drift.vtt", Format::WebVtt);

        $subtitle->shift(-1.5);

        $this->assertStringEqualsFile(self::EDITING . "own_ferry_drift_shifted_all.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testScaleMovesEveryCueAndWordTimestamp(): void
    {
        $subtitle = Subtitle::load(self::EDITING . "own_ferry_drift.vtt", Format::WebVtt);

        $subtitle->scale(1.001);

        $this->assertStringEqualsFile(self::EDITING . "own_ferry_drift_scaled.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testConvertFrameRateOfARealFile(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/files/sync/own_target_de_25fps.srt", Format::SubRip);

        $subtitle->convertFrameRate(25, 23.976);

        $this->assertStringEqualsFile(__DIR__ . "/files/sync/own_target_de_23976fps.srt", $subtitle->toString(Format::SubRip));
    }


    public function testSyncByPointsWithTwoPoints(): void
    {
        $subtitle = TestSubtitles::fromTimes([[10, 12], [3130, 3132], [6260, 6262]]);

        $this->assertSame($subtitle, $subtitle->syncByPoints([new SyncPoint(10, 12), new SyncPoint(6260, 6005)]));
        $this->assertSame(
            [[12.0, 13.918], [3003.706, 3005.623], [6005.0, 6006.918]],
            TestSubtitles::times($subtitle)
        );
    }


    public function testShiftWithARangeMovesTheCuesThatStartInIt(): void
    {
        $subtitle = Subtitle::load(self::EDITING . "own_ferry_drift.vtt", Format::WebVtt);

        $this->assertSame($subtitle, $subtitle->shift(2, fromTime: 6, toTime: 600));
        $this->assertStringEqualsFile(self::EDITING . "own_ferry_drift_shifted.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testShiftWithARangeSortsAMovedCueBeforeTheEarlierCues(): void
    {
        $subtitle = Subtitle::load(self::EDITING . "harbour_tour.vtt", Format::WebVtt);

        $subtitle->shift(-12.5, fromTime: 17.5);

        $this->assertStringEqualsFile(self::EDITING . "harbour_tour_shifted_back.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testShiftWithOnlyAnEndTimeMovesTheCuesThatStartBeforeIt(): void
    {
        $subtitle = TestSubtitles::fromTimes([[10, 12.5], [20, 22], [30, 31]]);

        $subtitle->shift(-1, toTime: 20);

        $this->assertSame([[9.0, 11.5], [20.0, 22.0], [30.0, 31.0]], TestSubtitles::times($subtitle));
    }


    public function testShiftRejectsAnEndTimeThatIsNotAfterTheStartTime(): void
    {
        foreach ([[600, 600], [900, 600]] as [$from, $to]) {
            $subtitle = TestSubtitles::fromTimes([[1, 2]]);
            try {
                $subtitle->shift(2, $from, $to);
                $this->fail("No exception.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame("The end time of the shift must be after its start time, got $from to $to.", $exception->getMessage());
            }
            $this->assertSame([[1.0, 2.0]], TestSubtitles::times($subtitle));
        }
    }


    public function testSyncByPointsMapsEachSegmentLinearlyAndMovesWordTimestamps(): void
    {
        $subtitle = Subtitle::load(self::EDITING . "own_ferry_drift.vtt", Format::WebVtt);

        $this->assertSame($subtitle, $subtitle->syncByPoints([new SyncPoint(10, 12), new SyncPoint(600, 610), new SyncPoint(1200, 1205)]));
        $this->assertStringEqualsFile(self::EDITING . "own_ferry_drift_synced.vtt", $subtitle->toString(Format::WebVtt));
    }


    public function testSyncByPointsWithOnePointShiftsAllCues(): void
    {
        $subtitle = TestSubtitles::fromTimes([[10, 12.5], [20.25, 22]]);

        $subtitle->syncByPoints([new SyncPoint(10, 12)]);

        $this->assertSame([[12.0, 14.5], [22.25, 24.0]], TestSubtitles::times($subtitle));
    }


    public function testSyncByPointsClampsNegativeTimesToZero(): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 2], [10, 11]]);

        $subtitle->syncByPoints([new SyncPoint(10, 5), new SyncPoint(20, 15)]);

        $this->assertSame([[0.0, 0.0], [5.0, 6.0]], TestSubtitles::times($subtitle));
    }


    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function invalidSyncPoints(): array
    {
        return [
            "empty"             => [[], "The sync points must not be empty."],
            "not a SyncPoint"   => [[new SyncPoint(1, 2), [3, 4]], "The sync point 1 must be a SyncPoint, got array."],
            "same old times"    => [[new SyncPoint(10, 12), new SyncPoint(10, 15)], "The old and new times of the sync points must both increase, got 10=12 before 10=15."],
            "old times reverse" => [[new SyncPoint(600, 610), new SyncPoint(10, 12)], "The old and new times of the sync points must both increase, got 600=610 before 10=12."],
            "new times reverse" => [[new SyncPoint(10, 20), new SyncPoint(30, 15)], "The old and new times of the sync points must both increase, got 10=20 before 30=15."],
            "same new times"    => [[new SyncPoint(10, 12), new SyncPoint(20, 30), new SyncPoint(40, 30)], "The old and new times of the sync points must both increase, got 20=30 before 40=30."],
        ];
    }


    /**
     * @param array<mixed> $points
     */
    #[DataProvider("invalidSyncPoints")]
    public function testSyncByPointsRejectsInvalidPoints(array $points, string $message): void
    {
        $subtitle = TestSubtitles::fromTimes([[1, 2]]);

        try {
            $subtitle->syncByPoints($points);
            $this->fail("No exception.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertSame([[1.0, 2.0]], TestSubtitles::times($subtitle));
    }


    public function testSyncPointRejectsATimeThatIsNotFinite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The new time of a sync point must be a finite number, got NAN.");
        new SyncPoint(1, NAN);
    }


    /**
     * @return array<string, array{Closure(Subtitle): mixed, string}>
     */
    public static function retimingsBeyondTheTimeLimit(): array
    {
        return [
            "scale"                 => [fn (Subtitle $s) => $s->scale(1e300), "The retimed cue 1 would end at 8.0E+300 s. Cue times must be below 100000 hours."],
            "frame rate"            => [fn (Subtitle $s) => $s->convertFrameRate(1, 1e-300), "The retimed cue 1 would end at 8.0E+300 s. Cue times must be below 100000 hours."],
            "shift"                 => [fn (Subtitle $s) => $s->shift(360000000), "The retimed cue 1 would end at 360000008 s. Cue times must be below 100000 hours."],
            "shift from a time"     => [fn (Subtitle $s) => $s->shift(359998500, 1000), "The retimed cue 5 would end at 360000003 s. Cue times must be below 100000 hours."],
            "sync by 2 points"      => [fn (Subtitle $s) => $s->syncByPoints([new SyncPoint(5, 5), new SyncPoint(10, 2000000)]),
                                        "The retimed cue 4 would end at 478798808 s. Cue times must be below 100000 hours."],
            "shift not finite"      => [fn (Subtitle $s) => $s->shift(INF), "The shift must be a finite number of seconds, got INF."],
        ];
    }


    #[DataProvider("retimingsBeyondTheTimeLimit")]
    public function testRetimingBeyondTheTimeLimitThrowsAndKeepsTheTimes(Closure $retime, string $message): void
    {
        $subtitle = Subtitle::load(self::EDITING . "own_ferry_drift.vtt", Format::WebVtt);
        $before   = $subtitle->toString(Format::WebVtt);

        try {
            $retime($subtitle);
            $this->fail("No exception.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertSame($before, $subtitle->toString(Format::WebVtt));
    }
}
