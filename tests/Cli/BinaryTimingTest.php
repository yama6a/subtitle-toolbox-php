<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SyncPoint;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;
use SubtitleToolbox\WriteOptions;

/**
 * Checks the retime and sync commands and the snap to shot changes.
 */
class BinaryTimingTest extends CliTestCase
{
    public function testRetimeShiftWritesOneInputToStandardOutput(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $expected = (string)file_get_contents(self::FILES . "editing/own_ferry_drift_shifted_all.vtt");

        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "ferry.vtt", "--shift", "-1.5"]));
        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "ferry.vtt", "--shift=-1.5"]));
        $this->assertSame([0, "ferry.vtt -> shifted.vtt\n", ""], $this->runCli(["convert", "ferry.vtt", "--shift", "-1.5", "-o", "shifted.vtt"]));
        $this->assertSame($expected, $this->file("shifted.vtt"));
    }


    public function testRetimeShiftAfter(): void
    {
        [$code, $stdout] = $this->runCli(["convert", "shop.vtt", "--shift", "5", "--shift-after", "12"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:10.000 --> 00:00:12.000", $stdout);
        $this->assertStringContainsString("00:00:18.000 --> 00:00:20.500", $stdout);
    }


    public function testRetimeTakesTimecodes(): void
    {
        $trip = Subtitle::fromString($this->file("trip.srt"), Format::SubRip);

        $this->assertSame([0, (clone $trip)->shift(-2.5)->toString(Format::SubRip), ""], $this->runCli(["convert", "trip.srt", "--shift=-00:00:02,500"]));
        $this->assertSame([0, (clone $trip)->shift(-2.5)->toString(Format::SubRip), ""], $this->runCli(["convert", "trip.srt", "--shift", "-00:00:02,500"]));
        $this->assertSame([0, (clone $trip)->shift(2.5)->toString(Format::SubRip), ""], $this->runCli(["convert", "trip.srt", "--shift", "2.5"]));
        $this->assertSame([0, (clone $trip)->shift(1, 3723.456)->toString(Format::SubRip), ""],
                          $this->runCli(["convert", "trip.srt", "--shift", "1", "--shift-after", "01:02:03.456"]));
        $this->assertSame([0, (clone $trip)->shift(1, 3)->toString(Format::SubRip), ""],
                          $this->runCli(["convert", "trip.srt", "--shift", "1", "--shift-after", "00:03"]));
        $this->assertSame([0, (clone $trip)->extendShortCues(1.2)->toString(Format::SubRip), ""],
                          $this->runCli(["convert", "trip.srt", "--to", "srt", "--timing-min-duration", "00:00:01.2"]));
    }


    public function testInvalidTimeIsAUsageErrorThatQuotesTheValue(): void
    {
        $this->assertSame(
            self::usageError("convert", "The option --shift needs seconds or a timecode such as 00:01:02.500, got \"1:2:3:4:5\"."),
            $this->runCli(["convert", "trip.srt", "--shift", "1:2:3:4:5"])
        );
    }


    public function testTimeOf100000HoursOrMoreIsAUsageError(): void
    {
        $this->assertSame(
            [2, "", "Error: The option --shift needs a time below 100000 hours, got \"1e300\".\nRun \"stbox help convert\" for the usage.\n"],
            $this->runCli(["convert", "trip.srt", "--shift", "1e300"])
        );
        $this->assertSame(2, $this->runCli(["convert", "trip.srt", "--shift", "100000:00:00"])[0]);
        $this->assertSame(2, $this->runCli(["convert", "trip.srt", "--shift", "-360000000"])[0]);
        $this->assertSame(2, $this->runCli(["convert", "trip.srt", "--to", "srt", "--timing-min-duration", "1e300"])[0]);
        $this->assertSame(2, $this->runCli(["hls", "trip.srt", "--output-dir", "out", "--local", "1e300"])[0]);
        $this->assertFileDoesNotExist("$this->dir/out");
    }


    public function testTimingLeadInAndLeadOut(): void
    {
        copy(self::FILES . "fixes/own_asr_tight_timing.srt", "$this->dir/asr.srt");
        $asr = Subtitle::fromString($this->file("asr.srt"), Format::SubRip);

        $this->assertSame([0, (string)file_get_contents(self::FILES . "fixes/own_asr_tight_timing_lead.srt"), ""], $this->runCli([
            "convert", "asr.srt", "--to", "srt", "--timing-lead-in", "0.2", "--timing-lead-out", "00:00:00.300", "--timing-min-gap", "0.083",
        ]));
        $this->assertSame([0, (clone $asr)->addLeadInOut(0, 0.5)->toString(Format::SubRip), ""],
                          $this->runCli(["convert", "asr.srt", "--to", "srt", "--timing-lead-out", "0.5"]));
        $this->assertSame([0, (clone $asr)->addLeadInOut(0.5, 0, 0.1)->toString(Format::SubRip), ""],
                          $this->runCli(["convert", "asr.srt", "--to", "srt", "--timing-lead-in", "0.5", "--timing-min-gap", "0.1"]));
        $this->assertSame(
            self::usageError("convert", "The option --timing-lead-in must not be negative."),
            $this->runCli(["convert", "asr.srt", "--to", "srt", "--timing-lead-in=-0.2"])
        );
    }


    public function testTimingMaxDuration(): void
    {
        copy(self::FILES . "fixes/own_long_cues.srt", "$this->dir/long.srt");
        copy(self::FILES . "fixes/own_asr_tight_timing.srt", "$this->dir/asr.srt");
        $expected = Subtitle::fromString($this->file("asr.srt"), Format::SubRip)->extendShortCues(3)->addLeadInOut(0, 1)->limitLongCues(2.5);

        $this->assertSame([0, (string)file_get_contents(self::FILES . "fixes/own_long_cues_limited.srt"), ""],
                          $this->runCli(["convert", "long.srt", "--to", "srt", "--timing-max-duration", "7"]));
        $this->assertSame([0, (string)file_get_contents(self::FILES . "fixes/own_long_cues_limited.srt"), ""],
                          $this->runCli(["convert", "long.srt", "--to", "srt", "--timing-max-duration", "00:00:07,000"]));
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runCli([
            "convert", "asr.srt", "--to", "srt", "--timing-max-duration", "2.5", "--timing-lead-out", "1", "--timing-min-duration", "3",
        ]));
        $this->assertSame(
            self::usageError("convert", "The option --timing-max-duration must be greater than 0."),
            $this->runCli(["convert", "long.srt", "--to", "srt", "--timing-max-duration", "0"])
        );
    }


    public function testRetimeSyncFirstAndLastEqualsSyncByPoints(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $expected = Subtitle::load(self::FILES . "editing/own_ferry_drift.vtt", Format::WebVtt)->syncByPoints([new SyncPoint(5, 12.5), new SyncPoint(1500, 6065)])->toString(Format::WebVtt);

        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "ferry.vtt", "--sync", "first=00:00:12.5", "--sync", "last=01:41:05"]));
        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "ferry.vtt", "--sync=#1=12.5", "--sync", "#5=6065"]));
    }


    public function testRetimeSyncWithOneAndThreePoints(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $ferry = Subtitle::load(self::FILES . "editing/own_ferry_drift.vtt", Format::WebVtt);

        $this->assertSame([0, (clone $ferry)->shift(2)->toString(Format::WebVtt), ""], $this->runCli(["convert", "ferry.vtt", "--sync", "10=12"]));
        $this->assertSame([0, (clone $ferry)->shift(2)->toString(Format::WebVtt), ""], $this->runCli(["convert", "ferry.vtt", "--to", "vtt", "--sync", "10=12"]));
        $this->assertSame(
            [0, (string)file_get_contents(self::FILES . "editing/own_ferry_drift_synced.vtt"), ""],
            $this->runCli(["convert", "ferry.vtt", "--sync", "#2=00:12", "--sync", "00:10:00=610", "--sync", "1200=00:20:05,000"])
        );
    }


    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function timingUsageErrors(): array
    {
        $time   = "needs seconds or a timecode such as 00:01:02.500, got";
        $convert = ["convert", "trip.srt"];
        $garden = ["convert", "garden.srt", "--to", "srt", "-o", "-"];
        $sync   = "Pass one of --reference FILE and --silence-log FILE.";

        return [
            "--shift soon"              => [[...$convert, "--shift", "soon"], "convert", "The option --shift $time \"soon\"."],
            "--timing-min-duration negative" => [["convert", "trip.srt", "--to", "srt", "--timing-min-duration", "-00:00:01"], "convert",
                                                 "The option --timing-min-duration must be greater than 0."],
            "--timing-min-gap frames"   => [["convert", "trip.srt", "--to", "srt", "--timing-fix-overlaps", "--timing-min-gap", "00:00:01:12"], "convert",
                                            "The option --timing-min-gap $time \"00:00:01:12\"."],
            "--timing-lead-out soon"    => [["convert", "asr.srt", "--to", "srt", "--timing-lead-out", "soon"], "convert", "The option --timing-lead-out $time \"soon\"."],
            "--sync with --shift"       => [[...$convert, "--sync", "10=12", "--shift", "2"], "convert", "Pass --sync without --shift and --scale."],
            "--sync with --scale"       => [[...$convert, "--sync", "10=12", "--scale", "2"], "convert", "Pass --sync without --shift and --scale."],
            "--sync without ="          => [[...$convert, "--sync", "10"], "convert", "The option --sync needs OLD=NEW, got \"10\"."],
            "--sync bad old"            => [[...$convert, "--sync", "soon=12"], "convert", "The option --sync $time \"soon\"."],
            "--sync bad new"            => [[...$convert, "--sync", "first=1:2:3:4:5"], "convert", "The option --sync $time \"1:2:3:4:5\"."],
            "--sync cue 0"              => [[...$convert, "--sync", "#0=12"], "convert", "The option --sync needs a cue number from 1 after #, got \"#0=12\"."],
            "--sync same old"           => [[...$convert, "--sync", "10=12", "--sync", "10=15"], "convert",
                                            "The --sync points must increase in both times, got \"10=12\" before \"10=15\"."],
            "--sync old reverse"        => [[...$convert, "--sync", "600=610", "--sync", "10=12"], "convert",
                                            "The --sync points must increase in both times, got \"600=610\" before \"10=12\"."],
            "--sync new reverse"        => [[...$convert, "--sync", "10=20", "--sync", "first=5", "--sync", "30=15"], "convert",
                                            "The --sync points must increase in both times, got \"10=20\" before \"30=15\"."],
            "--scale 0"                 => [["convert", "shop.vtt", "--scale", "0"], "convert", "The option --scale must be greater than 0."],
            "--from-fps without --to-fps" => [["convert", "frames.sub", "--input-fps", "25", "--from-fps", "25"], "convert", "Pass --from-fps and --to-fps together."],
            "sync without reference"    => [["sync", "de.srt"], "sync", $sync],
            "sync offsets reversed"     => [["sync", "de.srt", "--reference", "en.srt", "--min-offset", "10", "--max-offset", "-10"], "sync",
                                            "The minimum offset must not be greater than the maximum offset, got 10 and -10."],
            "sync --max-splits two"     => [["sync", "de.srt", "--reference", "en.srt", "--max-splits", "two"], "sync",
                                            "The option --max-splits needs a whole number from 0 to 10, got \"two\"."],
            "--silence-log without duration" => [["sync", "de.srt", "--silence-log", "silence.log"], "sync", "Pass --media-duration with --silence-log."],
            "--media-duration alone"    => [["sync", "de.srt", "--media-duration", "840"], "sync", $sync],
            "--reference and --silence-log" => [["sync", "de.srt", "--reference", "trip.srt", "--silence-log", "silence.log", "--media-duration", "840"], "sync", $sync],
            "--video-fps alone"         => [[...$garden, "--video-fps", "24"], "convert", "Pass --snap-shot-changes FILE with --video-fps."],
            "--snap-shot-changes without rate" => [[...$garden, "--snap-shot-changes", "scenes.txt"], "convert",
                                                   "Pass --video-fps RATE or --fps RATE with --snap-shot-changes."],
            "--snap-window-frames with input fps" => [[...$garden, "--input-fps", "24", "--snap-window-frames", "6"], "convert",
                                                      "Pass --video-fps RATE or --fps RATE with --snap-window-frames."],
            "--no-snap-chain without shot changes" => [[...$garden, "--video-fps", "24", "--no-snap-chain"], "convert",
                                                       "Pass --snap-shot-changes FILE. With --no-snap-chain and no shot changes, snapping changes nothing."],
            "--snap-window-frames -1"   => [[...$garden, "--video-fps", "24", "--snap-window-frames", "-1"], "convert",
                                            "The option --snap-window-frames needs a whole number of 0 or more, got \"-1\"."],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("timingUsageErrors")]
    public function testUsageError(array $arguments, string $command, string $message): void
    {
        $this->assertSame(self::usageError($command, $message), $this->runCli($arguments));
    }


    public function testRetimeSyncPointsThatDoNotFitTheFileFailTheFile(): void
    {
        $this->assertSame(
            [3, "", "trip.srt: The --sync point \"#4=12\" names cue 4, but the subtitle has 3 cues.\n"],
            $this->runCli(["convert", "trip.srt", "--sync", "#4=12"])
        );
        $this->assertSame(
            [3, "", "trip.srt: The --sync points must increase in both times, got \"10=12\" before \"last=20\".\n"],
            $this->runCli(["convert", "trip.srt", "--sync", "10=12", "--sync", "last=20"])
        );
    }


    public function testRetimeBeyondTheTimeLimitFailsTheFileWithoutAWarning(): void
    {
        $message = "trip.srt: The retimed cue 1 would end at 3.0E+300 s. Cue times must be below 100000 hours.\n";

        $this->assertSame([3, "", $message], $this->runCli(["convert", "trip.srt", "--scale", "1e300"]));
        $this->assertSame([3, "", $message], $this->runCli(["convert", "trip.srt", "--from-fps", "1", "--to-fps", "1e-300"]));
    }


    public function testRetimeShiftAfterSortsAMovedCueBeforeTheEarlierCues(): void
    {
        copy(self::FILES . "editing/harbour_tour.vtt", "$this->dir/harbour.vtt");
        $expected = (string)file_get_contents(self::FILES . "editing/harbour_tour_shifted_back.vtt");

        $this->assertSame([0, $expected, ""], $this->runBinary(["convert", "harbour.vtt", "--shift", "-12.5", "--shift-after", "17.5"]));
    }


    public function testRetimeShiftBefore(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");
        $expected = (string)file_get_contents(self::FILES . "editing/own_ferry_drift_shifted.vtt");

        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "ferry.vtt", "--shift", "2", "--shift-after", "6", "--shift-before", "00:10:00"]));
        $this->assertSame([0, $expected, ""], $this->runCli(["convert", "ferry.vtt", "--to", "vtt", "--shift", "2", "--shift-after", "6", "--shift-before", "600"]));
        $this->assertSame(
            self::usageError("convert", "The option --shift-before must be after --shift-after."),
            $this->runCli(["convert", "ferry.vtt", "--shift", "2", "--shift-after", "600", "--shift-before", "600"])
        );
        $this->assertSame(
            self::usageError("convert", "Pass --shift with --shift-before."),
            $this->runCli(["convert", "ferry.vtt", "--scale", "2", "--shift-before", "600"])
        );
    }


    public function testRetimeScale(): void
    {
        copy(self::FILES . "editing/own_ferry_drift.vtt", "$this->dir/ferry.vtt");

        $this->assertSame([0, (string)file_get_contents(self::FILES . "editing/own_ferry_drift_scaled.vtt"), ""],
                          $this->runCli(["convert", "ferry.vtt", "--scale", "1.001"]));
    }


    public function testRetimeChangesTheFrameRate(): void
    {
        copy(self::FILES . "sync/own_target_de_25fps.srt", "$this->dir/de.srt");

        $this->assertSame([0, (string)file_get_contents(self::FILES . "sync/own_target_de_23976fps.srt"), ""],
                          $this->runCli(["convert", "de.srt", "--from-fps", "25", "--to-fps", "23.976"]));
    }


    public function testRetimeAppliesShiftThenScaleThenFrameRate(): void
    {
        $trip     = $this->file("trip.srt");
        $expected = Subtitle::fromString($trip, Format::SubRip)->shift(-1.5)->scale(1.001)->convertFrameRate(25, 23.976)->toString(Format::SubRip);
        $reversed = Subtitle::fromString($trip, Format::SubRip)->convertFrameRate(25, 23.976)->scale(1.001)->shift(-1.5)->toString(Format::SubRip);

        $this->assertNotSame($reversed, $expected);
        $this->assertSame([0, $expected, ""], $this->runCli([
            "convert", "trip.srt", "--from-fps", "25", "--to-fps", "23.976", "--scale", "1.001", "--shift", "-1.5",
        ]));
    }


    public function testRetimeChangesTheFrameRateAndTheFormat(): void
    {
        $this->assertSame([0, (string)file_get_contents(self::FILES . "cli/frames_23976.vtt"), ""],
                          $this->runCli(["convert", "frames.sub", "--input-fps", "25", "--from-fps", "25", "--to-fps", "23.976", "--to", "vtt"]));
    }


    public function testSyncToAReferenceSubtitle(): void
    {
        foreach (["own_target_de_25fps.srt" => "de.srt", "own_reference_en.srt" => "en.srt", "own_reference_en_tv_break.srt" => "tv.srt"] as $from => $to) {
            copy(self::FILES . "sync/$from", "$this->dir/$to");
        }

        [$code, $stdout, $stderr] = $this->runCli(["sync", "de.srt", "--reference", "en.srt"]);
        $this->assertSame([0, "de.srt: scale 1.04271, offset -2.3 s, score 0.89\n"], [$code, $stderr]);
        $this->assertStringEqualsFile(self::FILES . "sync/own_target_de_synced.srt", $stdout);

        [$code, $stdout, $stderr] = $this->runCli(["sync", "de.srt", "--reference", "tv.srt", "--min-offset", "-00:03:00", "--max-offset", "03:00",
                                                      "--max-splits", "2", "-o", "synced.srt"]);
        $this->assertSame([0, "de.srt -> synced.srt\n"], [$code, $stdout]);
        $this->assertSame("de.srt: scale 1.04271, offset -2.31 s, score 0.89\nde.srt: from 0 s: offset -2.31 s\n" .
                          "de.srt: from 414.32 s: offset 147.7 s\n", $stderr);
        $this->assertFileEquals(self::FILES . "sync/own_target_de_split_synced.srt", "$this->dir/synced.srt");

        [$code, , $stderr] = $this->runCli(["sync", "de.srt", "--reference", "trip.srt", "--no-scale"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("de.srt: scale 1, ", $stderr);
        $this->assertStringEndsWith("de.srt: the score is below 0.5, so the files likely do not match.\n", $stderr);

        $this->assertSame([3, "", "Error: missing.srt: The file does not exist.\n"], $this->runCli(["sync", "de.srt", "--reference", "missing.srt"]));
    }


    public function testSyncToTheSpeech(): void
    {
        copy(self::FILES . "sync/own_target_de_25fps.srt", "$this->dir/de.srt");
        copy(self::FILES . "sync/own_ffmpeg_silencedetect.log", "$this->dir/silence.log");

        $this->assertSame([0, (string)file_get_contents(self::FILES . "sync/own_target_de_synced.srt"), "de.srt: scale 1.04271, offset -2.3 s, score 0.78\n"],
                          $this->runCli(["sync", "de.srt", "--silence-log", "silence.log", "--media-duration", "00:14:00"]));

        file_put_contents("$this->dir/mono.log", "[silencedetect @ 0x1] channel: 0 | silence_start: 1.5\n");
        $this->assertSame(3, $this->runCli(["sync", "de.srt", "--silence-log", "mono.log", "--media-duration", "840"])[0]);
    }


    public function testSnapToShotChanges(): void
    {
        foreach (["own_garden_24fps.srt" => "garden.srt", "own_ffmpeg_showinfo.log" => "scenes.log", "own_scenes.txt" => "scenes.txt"] as $from => $to) {
            copy(self::FILES . "shot-changes/$from", "$this->dir/$to");
        }
        $timed = file_get_contents(self::FILES . "shot-changes/own_garden_24fps_timed.srt");

        $this->assertSame([0, $timed, ""], $this->runCli(["convert", "garden.srt", "--to", "srt", "-o", "-", "--video-fps", "24", "--snap-shot-changes", "scenes.log"]));
        $this->assertSame([0, $timed, ""], $this->runCli(["convert", "garden.srt", "--to", "srt", "-o", "-", "--fps", "24", "--snap-shot-changes", "scenes.txt"]));

        foreach ([
            "own_garden_24fps_window_6.srt"        => ["--snap-window-frames", "6"],
            "own_garden_24fps_min_gap_3.srt"       => ["--snap-min-gap-frames", "3"],
            "own_garden_24fps_min_duration_12.srt" => ["--snap-min-duration-frames", "12"],
            "own_garden_24fps_no_chain.srt"        => ["--no-snap-chain"],
        ] as $golden => $option) {
            $this->assertSame([0, file_get_contents(self::FILES . "shot-changes/$golden"), ""], $this->runCli([
                "convert", "garden.srt", "--to", "srt", "-o", "-", "--video-fps", "24", "--snap-shot-changes", "scenes.txt", ...$option,
            ]), $golden);
        }

        $chained = Subtitle::fromString($this->file("garden.srt"));
        ShotChangeTiming::apply($chained, new ShotChangeOptions(24));
        $this->assertSame([0, $chained->toString(Format::SubRip), ""], $this->runCli(["convert", "garden.srt", "--to", "srt", "-o", "-", "--fps", "24", "--snap-min-gap-frames", "2"]));

        $this->assertSame([3, "", "Error: missing.txt: The file does not exist.\n"],
                          $this->runCli(["convert", "garden.srt", "--to", "srt", "-o", "-", "--video-fps", "24", "--snap-shot-changes", "missing.txt"]));
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
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runCli([
            "convert", "garden.sub", "--video-fps", "24", "--input-fps", "25", "--snap-shot-changes", "scenes.log", "--to", "srt", "-o", "-",
        ]));
        $this->assertSame([0, $readAt24->toString(Format::SubRip), ""], $this->runCli([
            "convert", "garden.sub", "--fps", "24", "--snap-shot-changes", "scenes.log", "--to", "srt", "-o", "-",
        ]));
    }
}
