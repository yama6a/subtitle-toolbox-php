<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;
use SubtitleToolbox\SyncPoint;
use SubtitleToolbox\Tests\Support\BinaryTestCase;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;
use SubtitleToolbox\WriteOptions;

/**
 * Checks the retime and sync commands and the snap to shot changes.
 */
class BinaryTimingTest extends BinaryTestCase
{
    public function testRetimeShiftWritesOneInputToStandardOutput(): void
    {
        $expected = Subtitle::fromString($this->file("trip.srt"), Format::SubRip)->shift(-0.5)->toString(Format::SubRip);

        $this->assertSame([0, $expected, ""], $this->runBinary(["retime", "trip.srt", "--shift", "-0.5"]));
        $this->assertSame([0, $expected, ""], $this->runBinary(["retime", "trip.srt", "--shift=-0.5"]));
        $this->assertSame([0, "trip.srt -> shifted.srt\n", ""], $this->runBinary(["retime", "trip.srt", "--shift", "-0.5", "-o", "shifted.srt"]));
        $this->assertSame($expected, $this->file("shifted.srt"));
        $this->assertSame(2, $this->runBinary(["retime", "trip.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["retime", "trip.srt", "--shift", "soon"])[0]);
    }


    public function testRetimeShiftAfter(): void
    {
        [$code, $stdout] = $this->runBinary(["retime", "shop.vtt", "--shift", "5", "--shift-after", "12"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:10.000 --> 00:00:12.000", $stdout);
        $this->assertStringContainsString("00:00:18.000 --> 00:00:20.500", $stdout);
    }


    public function testRetimeTakesTimecodes(): void
    {
        $trip = Subtitle::fromString($this->file("trip.srt"), Format::SubRip);

        $this->assertSame([0, (clone $trip)->shift(-2.5)->toString(Format::SubRip), ""], $this->runBinary(["retime", "trip.srt", "--shift=-00:00:02,500"]));
        $this->assertSame([0, (clone $trip)->shift(-2.5)->toString(Format::SubRip), ""], $this->runBinary(["retime", "trip.srt", "--shift", "-00:00:02,500"]));
        $this->assertSame([0, (clone $trip)->shift(2.5)->toString(Format::SubRip), ""], $this->runBinary(["retime", "trip.srt", "--shift", "2.5"]));
        $this->assertSame([0, (clone $trip)->shift(1, 3723.456)->toString(Format::SubRip), ""],
                          $this->runBinary(["retime", "trip.srt", "--shift", "1", "--shift-after", "01:02:03.456"]));
        $this->assertSame([0, (clone $trip)->shift(1, 3)->toString(Format::SubRip), ""],
                          $this->runBinary(["retime", "trip.srt", "--shift", "1", "--shift-after", "00:03"]));
        $this->assertSame([0, (clone $trip)->extendShortCues(1.2)->toString(Format::SubRip), ""],
                          $this->runBinary(["convert", "trip.srt", "--to", "srt", "--timing-min-duration", "00:00:01.2"]));
    }


    public function testInvalidTimeIsAUsageErrorThatQuotesTheValue(): void
    {
        $this->assertSame(
            [2, "", "Error: The option --shift needs seconds or a timecode such as 00:01:02.500, got \"1:2:3:4:5\".\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            $this->runBinary(["retime", "trip.srt", "--shift", "1:2:3:4:5"])
        );
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "srt", "--timing-min-duration", "-00:00:01"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "srt", "--timing-fix-overlaps", "--timing-min-gap", "00:00:01:12"])[0]);
    }


    public function testTimingLeadInAndLeadOut(): void
    {
        copy(self::FILES . "fixes/own_asr_tight_timing.srt", "$this->dir/asr.srt");
        $asr = Subtitle::fromString($this->file("asr.srt"), Format::SubRip);

        $this->assertSame([0, (string)file_get_contents(self::FILES . "fixes/own_asr_tight_timing_lead.srt"), ""], $this->runBinary([
            "convert", "asr.srt", "--to", "srt", "--timing-lead-in", "0.2", "--timing-lead-out", "00:00:00.300", "--timing-min-gap", "0.083",
        ]));
        $this->assertSame([0, (clone $asr)->addLeadInOut(0, 0.5)->toString(Format::SubRip), ""],
                          $this->runBinary(["convert", "asr.srt", "--to", "srt", "--timing-lead-out", "0.5"]));
        $this->assertSame([0, (clone $asr)->addLeadInOut(0.5, 0, 0.1)->toString(Format::SubRip), ""],
                          $this->runBinary(["convert", "asr.srt", "--to", "srt", "--timing-lead-in", "0.5", "--timing-min-gap", "0.1"]));
        $this->assertSame(
            [2, "", "Error: The option --timing-lead-in must not be negative.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "asr.srt", "--to", "srt", "--timing-lead-in=-0.2"])
        );
        $this->assertSame(2, $this->runBinary(["convert", "asr.srt", "--to", "srt", "--timing-lead-out", "soon"])[0]);
    }


    public function testTimingMaxDuration(): void
    {
        copy(self::FILES . "fixes/own_long_cues.srt", "$this->dir/long.srt");
        copy(self::FILES . "fixes/own_asr_tight_timing.srt", "$this->dir/asr.srt");
        $expected = Subtitle::fromString($this->file("asr.srt"), Format::SubRip)->extendShortCues(3)->addLeadInOut(0, 1)->limitLongCues(2.5);

        $this->assertSame([0, (string)file_get_contents(self::FILES . "fixes/own_long_cues_limited.srt"), ""],
                          $this->runBinary(["convert", "long.srt", "--to", "srt", "--timing-max-duration", "7"]));
        $this->assertSame([0, (string)file_get_contents(self::FILES . "fixes/own_long_cues_limited.srt"), ""],
                          $this->runBinary(["convert", "long.srt", "--to", "srt", "--timing-max-duration", "00:00:07,000"]));
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary([
            "convert", "asr.srt", "--to", "srt", "--timing-max-duration", "2.5", "--timing-lead-out", "1", "--timing-min-duration", "3",
        ]));
        $this->assertSame(
            [2, "", "Error: The option --timing-max-duration must be greater than 0.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "long.srt", "--to", "srt", "--timing-max-duration", "0"])
        );
    }


    public function testRetimeSyncFirstAndLastEqualsSyncByPoints(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $expected = Subtitle::load(self::FILES . "editing/own_ferry_drift.vtt", Format::WebVtt)->syncByPoints([new SyncPoint(5, 12.5), new SyncPoint(1500, 6065)])->toString(Format::WebVtt);

        $this->assertSame([0, $expected, ""], $this->runBinary(["retime", "ferry.vtt", "--sync", "first=00:00:12.5", "--sync", "last=01:41:05"]));
        $this->assertSame([0, $expected, ""], $this->runBinary(["retime", "ferry.vtt", "--sync=#1=12.5", "--sync", "#5=6065"]));
    }


    public function testRetimeSyncWithOneAndThreePoints(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $ferry = Subtitle::load(self::FILES . "editing/own_ferry_drift.vtt", Format::WebVtt);

        $this->assertSame([0, (clone $ferry)->shift(2)->toString(Format::WebVtt), ""], $this->runBinary(["retime", "ferry.vtt", "--sync", "10=12"]));
        $this->assertSame([0, (clone $ferry)->shift(2)->toString(Format::WebVtt), ""], $this->runBinary(["convert", "ferry.vtt", "--to", "vtt", "--sync", "10=12"]));
        $this->assertSame(
            [0, (string)file_get_contents(self::FILES . "editing/own_ferry_drift_synced.vtt"), ""],
            $this->runBinary(["retime", "ferry.vtt", "--sync", "#2=00:12", "--sync", "00:10:00=610", "--sync", "1200=00:20:05,000"])
        );
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidSyncOptions(): array
    {
        return [
            "with shift"    => [["--sync", "10=12", "--shift", "2"], "Pass --sync without --shift and --scale."],
            "with scale"    => [["--sync", "10=12", "--scale", "2"], "Pass --sync without --shift and --scale."],
            "no equals"     => [["--sync", "10"], "The option --sync needs OLD=NEW, got \"10\"."],
            "bad old"       => [["--sync", "soon=12"], "The option --sync needs seconds or a timecode such as 00:01:02.500, got \"soon\"."],
            "bad new"       => [["--sync", "first=1:2:3:4:5"], "The option --sync needs seconds or a timecode such as 00:01:02.500, got \"1:2:3:4:5\"."],
            "cue 0"         => [["--sync", "#0=12"], "The option --sync needs a cue number from 1 after #, got \"#0=12\"."],
            "same old"      => [["--sync", "10=12", "--sync", "10=15"], "The --sync points must increase in both times, got \"10=12\" before \"10=15\"."],
            "old reverse"   => [["--sync", "600=610", "--sync", "10=12"], "The --sync points must increase in both times, got \"600=610\" before \"10=12\"."],
            "new reverse"   => [["--sync", "10=20", "--sync", "first=5", "--sync", "30=15"], "The --sync points must increase in both times, got \"10=20\" before \"30=15\"."],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("invalidSyncOptions")]
    public function testInvalidRetimeSyncIsAUsageError(array $options, string $message): void
    {
        $this->assertSame(
            [2, "", "Error: $message\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            $this->runBinary(["retime", "trip.srt", ...$options])
        );
    }


    public function testRetimeSyncPointsThatDoNotFitTheFileFailTheFile(): void
    {
        $this->assertSame(
            [3, "", "trip.srt: The --sync point \"#4=12\" names cue 4, but the subtitle has 3 cues.\n"],
            $this->runBinary(["retime", "trip.srt", "--sync", "#4=12"])
        );
        $this->assertSame(
            [3, "", "trip.srt: The --sync points must increase in both times, got \"10=12\" before \"last=20\".\n"],
            $this->runBinary(["retime", "trip.srt", "--sync", "10=12", "--sync", "last=20"])
        );
    }


    public function testRetimeShiftBefore(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $expected = (string)file_get_contents(self::FILES . "editing/own_ferry_drift_shifted.vtt");

        $this->assertSame([0, $expected, ""], $this->runBinary(["retime", "ferry.vtt", "--shift", "2", "--shift-after", "6", "--shift-before", "00:10:00"]));
        $this->assertSame([0, $expected, ""], $this->runBinary(["convert", "ferry.vtt", "--to", "vtt", "--shift", "2", "--shift-after", "6", "--shift-before", "600"]));
        $this->assertSame(
            [2, "", "Error: The option --shift-before must be after --shift-after.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            $this->runBinary(["retime", "ferry.vtt", "--shift", "2", "--shift-after", "600", "--shift-before", "600"])
        );
        $this->assertSame(
            [2, "", "Error: Pass --shift with --shift-before.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            $this->runBinary(["retime", "ferry.vtt", "--scale", "2", "--shift-before", "600"])
        );
    }


    public function testRetimeScale(): void
    {
        [$code, $stdout] = $this->runBinary(["retime", "shop.vtt", "--scale", "2"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:20.000 --> 00:00:24.000", $stdout);
        $this->assertSame(2, $this->runBinary(["retime", "shop.vtt", "--scale", "0"])[0]);
    }


    public function testRetimeAppliesShiftThenScaleThenFrameRate(): void
    {
        $trip     = $this->file("trip.srt");
        $expected = Subtitle::fromString($trip, Format::SubRip)->shift(-1.5)->scale(1.001)->convertFrameRate(25, 23.976)->toString(Format::SubRip);
        $reversed = Subtitle::fromString($trip, Format::SubRip)->convertFrameRate(25, 23.976)->scale(1.001)->shift(-1.5)->toString(Format::SubRip);

        $this->assertNotSame($reversed, $expected);
        $this->assertSame([0, $expected, ""], $this->runBinary([
            "retime", "trip.srt", "--from-fps", "25", "--to-fps", "23.976", "--scale", "1.001", "--shift", "-1.5",
        ]));
    }


    public function testRetimeChangesTheFrameRateAndTheFormat(): void
    {
        $expected = Subtitle::fromString($this->file("frames.sub"), Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(25)]))
            ->convertFrameRate(25, 23.976)
            ->toString(Format::WebVtt);

        [$code, $stdout, $stderr] = $this->runBinary(["retime", "frames.sub", "--input-fps", "25", "--from-fps", "25", "--to-fps", "23.976", "--to", "vtt"]);

        $this->assertSame([0, $expected, ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("00:00:01.043 --> 00:00:03.128\nHello from the frames.\n", $stdout);
        $this->assertSame(2, $this->runBinary(["retime", "frames.sub", "--input-fps", "25", "--from-fps", "25"])[0]);
    }


    public function testSyncToAReferenceSubtitle(): void
    {
        foreach (["own_target_de_25fps.srt" => "de.srt", "own_reference_en.srt" => "en.srt", "own_reference_en_tv_break.srt" => "tv.srt"] as $from => $to) {
            copy(self::FILES . "sync/$from", "$this->dir/$to");
        }

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "en.srt"]);
        $this->assertSame([0, "de.srt: scale 1.04271, offset -2.3 s, score 0.89\n"], [$code, $stderr]);
        $this->assertStringEqualsFile(self::FILES . "sync/own_target_de_synced.srt", $stdout);

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "tv.srt", "--min-offset", "-00:03:00", "--max-offset", "03:00",
                                                      "--max-splits", "2", "-o", "synced.srt"]);
        $this->assertSame([0, "de.srt -> synced.srt\n"], [$code, $stdout]);
        $this->assertSame("de.srt: scale 1.04271, offset -2.31 s, score 0.89\nde.srt: from 0 s: offset -2.31 s\n" .
                          "de.srt: from 414.32 s: offset 147.7 s\n", $stderr);
        $this->assertFileEquals(self::FILES . "sync/own_target_de_split_synced.srt", "$this->dir/synced.srt");

        [$code, , $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "trip.srt", "--no-scale"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("de.srt: scale 1, ", $stderr);
        $this->assertStringEndsWith("de.srt: the score is below 0.5, so the files likely do not match.\n", $stderr);

        $this->assertSame([3, "", "Error: missing.srt: The file does not exist.\n"], $this->runBinary(["sync", "de.srt", "--reference", "missing.srt"]));
        $this->assertSame(2, $this->runBinary(["sync", "de.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["sync", "de.srt", "--reference", "en.srt", "--min-offset", "10", "--max-offset", "-10"])[0]);
        $this->assertSame(2, $this->runBinary(["sync", "de.srt", "--reference", "en.srt", "--max-splits", "two"])[0]);
    }


    public function testSyncToTheSpeech(): void
    {
        copy(self::FILES . "sync/own_target_de_25fps.srt", "$this->dir/de.srt");
        copy(self::FILES . "sync/own_ffmpeg_silencedetect.log", "$this->dir/silence.log");
        $expected = Subtitle::fromStringAutoDetectFormat($this->file("de.srt"));
        ReferenceSync::apply($expected, new ReferenceSyncOptions(SpeechReference::fromFfmpegSilencedetect($this->file("silence.log"), 840)));

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--silence-log", "silence.log", "--media-duration", "00:14:00"]);
        $this->assertSame([0, $expected->toString(Format::SubRip), "de.srt: scale 1.04271, offset -2.3 s, score 0.78\n"],
                          [$code, $stdout, $stderr]);

        foreach ([["--silence-log", "silence.log"], ["--media-duration", "840"], ["--reference", "trip.srt", "--silence-log", "silence.log",
                  "--media-duration", "840"]] as $options) {
            $this->assertSame(2, $this->runBinary(["sync", "de.srt", ...$options])[0], implode(" ", $options));
        }
        file_put_contents("$this->dir/mono.log", "[silencedetect @ 0x1] channel: 0 | silence_start: 1.5\n");
        $this->assertSame(3, $this->runBinary(["sync", "de.srt", "--silence-log", "mono.log", "--media-duration", "840"])[0]);
    }


    public function testSnapToShotChanges(): void
    {
        foreach (["own_garden_24fps.srt" => "garden.srt", "own_ffmpeg_showinfo.log" => "scenes.log", "own_scenes.txt" => "scenes.txt"] as $from => $to) {
            copy(self::FILES . "shot-changes/$from", "$this->dir/$to");
        }
        $timed = file_get_contents(self::FILES . "shot-changes/own_garden_24fps_timed.srt");

        $this->assertSame([0, $timed, ""], $this->runBinary(["convert", "garden.srt", "--to", "srt", "-o", "-", "--video-fps", "24", "--snap-shot-changes", "scenes.log"]));
        $this->assertSame([0, $timed, ""], $this->runBinary(["convert", "garden.srt", "--to", "srt", "-o", "-", "--fps", "24", "--snap-shot-changes", "scenes.txt"]));

        $expected = Subtitle::fromStringAutoDetectFormat($this->file("garden.srt"));
        ShotChangeTiming::apply($expected, new ShotChangeOptions(frameRate: 24, shotChanges: ShotChanges::fromText($this->file("scenes.txt")),
                                                                 snapWindowFrames: 6, minGapFrames: 3, chain: false, minDurationFrames: 12));
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary([
            "convert", "garden.srt", "--to", "srt", "-o", "-", "--video-fps", "24", "--snap-shot-changes", "scenes.txt", "--snap-window-frames", "6",
            "--snap-min-gap-frames", "3", "--no-snap-chain", "--snap-min-duration-frames", "12",
        ]));

        $chained = Subtitle::fromStringAutoDetectFormat($this->file("garden.srt"));
        ShotChangeTiming::apply($chained, new ShotChangeOptions(24));
        $this->assertSame([0, $chained->toString(Format::SubRip), ""], $this->runBinary(["convert", "garden.srt", "--to", "srt", "-o", "-", "--fps", "24", "--snap-min-gap-frames", "2"]));

        foreach ([["--video-fps", "24"], ["--snap-shot-changes", "scenes.txt"], ["--input-fps", "24", "--snap-window-frames", "6"],
                  ["--video-fps", "24", "--no-snap-chain"], ["--video-fps", "24", "--snap-window-frames", "-1"]] as $options) {
            $this->assertSame(2, $this->runBinary(["convert", "garden.srt", "--to", "srt", "-o", "-", ...$options])[0], implode(" ", $options));
        }
        $this->assertSame([3, "", "Error: missing.txt: The file does not exist.\n"],
                          $this->runBinary(["convert", "garden.srt", "--to", "srt", "-o", "-", "--video-fps", "24", "--snap-shot-changes", "missing.txt"]));
    }


    public function testSnapTakesTheVideoAndInputFrameRatesApart(): void
    {
        copy(self::FILES . "shot-changes/own_ffmpeg_showinfo.log", "$this->dir/scenes.log");
        $garden = file_get_contents(self::FILES . "shot-changes/own_garden_24fps.srt");
        file_put_contents("$this->dir/garden.sub", Subtitle::fromString($garden, Format::SubRip)->toString(
            Format::MicroDvd,
            new WriteOptions(formats: [new MicroDvdWriteOptions(frameRate: 25)]),
        ));
        $shotChanges = ShotChanges::fromFfmpegLog($this->file("scenes.log"));

        $expected = Subtitle::fromString($this->file("garden.sub"), Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(25)]));
        ShotChangeTiming::apply($expected, new ShotChangeOptions(frameRate: 24, shotChanges: $shotChanges));
        $readAt24 = Subtitle::fromString($this->file("garden.sub"), Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(24)]));
        ShotChangeTiming::apply($readAt24, new ShotChangeOptions(frameRate: 24, shotChanges: $shotChanges));

        $this->assertNotSame($readAt24->toString(Format::SubRip), $expected->toString(Format::SubRip));
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary([
            "convert", "garden.sub", "--video-fps", "24", "--input-fps", "25", "--snap-shot-changes", "scenes.log", "--to", "srt", "-o", "-",
        ]));
        $this->assertSame([0, $readAt24->toString(Format::SubRip), ""], $this->runBinary([
            "convert", "garden.sub", "--fps", "24", "--snap-shot-changes", "scenes.log", "--to", "srt", "-o", "-",
        ]));
    }
}
