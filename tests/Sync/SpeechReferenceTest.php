<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class SpeechReferenceTest extends TestCase
{
    private const LINE = "[silencedetect @ 0x55f1d2a8b340] ";


    private function loadLog(): string
    {
        return file_get_contents(__DIR__ . "/../files/sync/own_ffmpeg_silencedetect.log");
    }


    public function testRealLogBecomesSpeechCues(): void
    {
        $speech = SpeechReference::fromFfmpegSilencedetect($this->loadLog(), 840);
        $cues   = $speech->getCues();

        $this->assertCount(60, $cues);
        $this->assertSame([77.804, 79.067, ""], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([800.876, 803.915, ""], [$cues[59]->getStart(), $cues[59]->getEnd(), $cues[59]->getText()]);
    }


    public function testSyncToSpeechFindsOffsetAndScale(): void
    {
        $target = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sync/own_target_de_25fps.srt"), Format::SubRip);
        $speech = SpeechReference::fromFfmpegSilencedetect($this->loadLog(), 840);

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($speech));

        $this->assertEqualsWithDelta(25 / 23.976, $result->scale, 0.00001);
        $this->assertEqualsWithDelta(-2.3, $result->offset, 0.15);
        $this->assertGreaterThan(0.7, $result->score);
        $this->assertStringEqualsFile(__DIR__ . "/../files/sync/own_target_de_synced.srt", $target->toString(Format::SubRip));
    }


    public function testSilenceUntilTheEndAndSpeechAtTheEnd(): void
    {
        $log = self::LINE . "silence_start: -0.0213\n" .
               self::LINE . "silence_end: 2.5 | silence_duration: 2.5213\r\n" .
               self::LINE . "silence_start: 4.25\r" .
               self::LINE . "silence_end: 6 | silence_duration: 1.75\n";

        $this->assertSame([[2.5, 4.25], [6.0, 10.0]], TestSubtitles::times(SpeechReference::fromFfmpegSilencedetect($log, 10)));
        $this->assertSame([[2.5, 4.25], [6.0, 6.5]],
                          TestSubtitles::times(SpeechReference::fromFfmpegSilencedetect($log . self::LINE . "silence_start: 6.5\n", 10)));
    }


    public function testLogWithoutSilenceIsSpeechFromStartToEnd(): void
    {
        $this->assertSame([[0.0, 12.5]], TestSubtitles::times(SpeechReference::fromFfmpegSilencedetect("ffmpeg version 6.1.1\n", 12.5)));
    }


    public function testMonoLogThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("(line 2)");
        SpeechReference::fromFfmpegSilencedetect("Press [q] to stop\n" . self::LINE . "channel: 0 | silence_start: 1.5\n", 10);
    }


    public function testSilenceEndWithoutStartThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("(line 1)");
        SpeechReference::fromFfmpegSilencedetect(self::LINE . "silence_end: 1.5 | silence_duration: 1.5\n", 10);
    }


    public function testZeroDurationThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SpeechReference::fromFfmpegSilencedetect("", 0);
    }


    public function testFromIntervalsSortsAndKeepsTimes(): void
    {
        $speech = SpeechReference::fromIntervals([[5.5, 7], [1, 2.25], [3, 4]]);

        $this->assertSame([[1.0, 2.25], [3.0, 4.0], [5.5, 7.0]], TestSubtitles::times($speech));
    }


    public function testFromIntervalsWithoutIntervalsIsEmpty(): void
    {
        $this->assertSame([], SpeechReference::fromIntervals([])->getCues());
    }


    public function testInvalidIntervalThrows(): void
    {
        foreach ([[[2, 1]], [[1]], [["start" => 1, "end" => 2]], [[-1, 2]], [["a", 2]], [3]] as $intervals) {
            try {
                SpeechReference::fromIntervals($intervals);
                $this->fail("No exception for " . json_encode($intervals));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString("Interval 0", $exception->getMessage());
            }
        }
    }
}
