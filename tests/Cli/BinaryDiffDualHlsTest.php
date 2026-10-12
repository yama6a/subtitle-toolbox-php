<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Checks the diff, dual and hls commands.
 */
class BinaryDiffDualHlsTest extends CliTestCase
{
    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function usageErrors(): array
    {
        return [
            "diff with one file"              => [["diff", "v1.srt"], "diff", "Pass two files, the old one and the new one."],
            "diff with - as the new file"     => [["diff", "v1.srt", "-"], "diff", "Standard input works only for the old file. Pass the new file by name."],
            "diff --time-tolerance -1"        => [["diff", "v1.srt", "v2.srt", "--time-tolerance", "-1"], "diff", "The option --time-tolerance must not be negative."],
            "diff --new-from docx"            => [["diff", "trip.srt", "frames.sub", "--new-from", "docx"], "diff",
                                                  "Unknown format \"docx\". Run \"subtitle-toolbox formats\" for the list."],
            "diff --new-track x"              => [["diff", "movie.mkv", "movie.mkv", "--track", "3", "--new-track", "x"], "diff",
                                                  "The option --new-track needs a whole number of 1 or more, got \"x\"."],
            "dual without --secondary"        => [["dual", "--primary", "en.srt"], "dual", "Pass --primary FILE and --secondary FILE."],
            "dual --mode side"                => [["dual", "--primary", "en.srt", "--secondary", "de.srt", "--mode", "side"], "dual",
                                                  "The option --mode must be stack or top-bottom, got \"side\"."],
            "dual --secondary-alignment 0"    => [["dual", "--primary", "en.srt", "--secondary", "de.srt", "--secondary-alignment", "0"], "dual",
                                                  "The option --secondary-alignment needs a whole number from 1 to 9, got \"0\"."],
            "dual --secondary-style em"       => [["dual", "--primary", "en.srt", "--secondary", "de.srt", "--secondary-style", "em"], "dual",
                                                  "The secondary style em must be a core markup tag such as i or font color=\"#ffff00\"."],
            "dual --snap-tolerance -1"        => [["dual", "--primary", "en.srt", "--secondary", "de.srt", "--snap-tolerance", "-1"], "dual",
                                                  "The option --snap-tolerance must not be negative."],
            "dual --from"                     => [["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--from", "srt"], "dual", "Unknown option --from."],
            "dual --track"                    => [["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--track", "3"], "dual", "Unknown option --track."],
            "dual --new-from"                 => [["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--new-from", "srt"], "dual",
                                                  "Unknown option --new-from."],
            "dual --new-track"                => [["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--new-track", "3"], "dual",
                                                  "Unknown option --new-track."],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("usageErrors")]
    public function testUsageError(array $arguments, string $command, string $message): void
    {
        $this->assertSame(self::usageError($command, $message), $this->runCli($arguments));
    }


    public function testDiffPrintsTheReportAndExitsWith1(): void
    {
        $this->copyDiffVersions();

        $this->assertSame([1, file_get_contents(self::FILES . "diff/own_report.txt"), ""], $this->runCli(["diff", "v1.srt", "v2.srt"]));
    }


    public function testDiffOfEqualFilesPrintsNothingAndExitsWith0(): void
    {
        $this->copyDiffVersions();

        $this->assertSame([0, "", ""], $this->runCli(["diff", "v1.srt", "v1.vtt"]));
    }


    public function testDiffJsonNamesStandardInputAsDash(): void
    {
        $this->copyDiffVersions();

        $this->assertSame(["oldFile" => "-", "newFile" => "v1.vtt"], array_slice(json_decode($this->runCli(["diff", "-", "v1.vtt", "--json"], $this->file("v1.srt"))[1], true)[0], 0, 2));
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function diffJsonReports(): array
    {
        return [
            "no option"           => [[], "own_report.json"],
            "--time-tolerance"    => [["--time-tolerance", "00:00:00.5"], "own_report_time_tolerance.json"],
            "--ignore-formatting" => [["--ignore-formatting"], "own_report_ignore_formatting.json"],
            "--text-only"         => [["--text-only"], "own_report_text_only.json"],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("diffJsonReports")]
    public function testDiffJsonReport(array $options, string $golden): void
    {
        $this->copyDiffVersions();

        $this->assertSame([1, file_get_contents(self::FILES . "diff/$golden"), ""], $this->runCli(["diff", "v1.srt", "v2.srt", "--json", ...$options]));
    }


    public function testDiffOfAMissingFileExitsWith3(): void
    {
        $this->copyDiffVersions();

        $this->assertSame([3, "", "missing.srt: The file does not exist.\n"], $this->runCli(["diff", "v1.srt", "missing.srt"]));
    }


    private function copyDiffVersions(): void
    {
        copy(self::FILES . "diff/own_original.srt", "$this->dir/v1.srt");
        copy(self::FILES . "diff/own_edited.srt", "$this->dir/v2.srt");
        file_put_contents("$this->dir/v1.vtt", Subtitle::fromStringAutoDetectFormat($this->file("v1.srt"))->toString(Format::WebVtt));
    }


    public function testDiffReadsTheNewFileWithNewFromAndNewTrack(): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $mkv      = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv");
        $expected = SubtitleDiff::toText(SubtitleDiff::compare($mkv->extract(3), $mkv->extract(8)));

        $this->assertStringContainsString("Der Zug nach Hamburg", $expected);
        $this->assertStringContainsString("Next stop: Central Station.", $expected);
        $this->assertSame([1, $expected, ""], $this->runCli(["diff", "movie.mkv", "movie.mkv", "--track", "3", "--new-track", "8"]));

        $frames   = Subtitle::load(self::FIXTURES . "frames.sub", Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(25)]));
        $expected = SubtitleDiff::toText(SubtitleDiff::compare(Subtitle::load(self::FIXTURES . "trip.srt", Format::SubRip), $frames));
        $this->assertSame([1, $expected, ""], $this->runCli(["diff", "trip.srt", "frames.sub", "--new-from", "microdvd", "--input-fps", "25"]));
        $this->assertSame([3, ""], array_slice($this->runCli(["diff", "trip.srt", "frames.sub", "--new-from", "subviewer"]), 0, 2));
    }


    public function testDiffDualAndHlsRejectKeepGoing(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");

        $this->assertSame(self::usageError("diff", "Unknown option --keep-going."), $this->runCli(["diff", "trip.srt", "trip.srt", "--keep-going"]));
        $this->assertSame(self::usageError("dual", "Unknown option --keep-going."),
                          $this->runCli(["dual", "--primary", "trip.srt", "--secondary", "shop.vtt", "--keep-going"]));
        $this->assertSame(self::usageError("hls", "Unknown option --keep-going."), $this->runCli(["hls", "talk.vtt", "--output-dir", "out", "--keep-going"]));
        $this->assertFileDoesNotExist("$this->dir/out");
    }


    public function testDiffAppliesFromTrackAndEncodingToBothFiles(): void
    {
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/old.json");
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/new.json");
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $mkv      = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv");

        $this->assertSame([0, "", ""], $this->runCli(["diff", "old.json", "new.json", "--from", "deepgram"]));
        $this->assertSame([0, "", ""], $this->runCli(["diff", "movie.mkv", "movie.mkv", "--track", "3"]));
        $this->assertSame([1, SubtitleDiff::toText(SubtitleDiff::compare($mkv->extract(3), $mkv->extract(8))), ""],
                          $this->runCli(["diff", "movie.mkv", "movie.mkv", "--track", "3", "--new-track", "8"]));
        $this->assertSame(1, $this->runCli(["diff", "trip.srt", "new.json", "--new-from", "deepgram"])[0]);
        $this->assertSame(3, $this->runCli(["diff", "trip.srt", "new.json"])[0]);
    }


    public function testDiffIgnoresAnInheritedTrackForAFileWithoutTracks(): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $track3 = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv")->extract(3);
        $trip   = Subtitle::load(self::FIXTURES . "trip.srt", Format::SubRip);

        $this->assertSame([1, SubtitleDiff::toText(SubtitleDiff::compare($track3, $trip)), ""], $this->runCli(["diff", "movie.mkv", "trip.srt", "--track", "3"]));
        $this->assertSame(3, $this->runCli(["diff", "movie.mkv", "trip.srt", "--track", "3", "--new-track", "3"])[0]);
        $this->assertSame(3, $this->runCli(["diff", "trip.srt", "movie.mkv", "--track", "3"])[0]);
    }


    public function testDiffTakesOneOldFile(): void
    {
        mkdir("$this->dir/old");
        copy("$this->dir/trip.srt", "$this->dir/old/trip.srt");
        copy("$this->dir/shop.vtt", "$this->dir/old/shop.vtt");

        $this->assertSame(self::usageError("diff", "The diff command takes one old file, got 2."), $this->runCli(["diff", "old", "trip.srt"]));
        $this->assertSame(self::usageError("diff", "The diff command takes one old file, got 2."), $this->runCli(["diff", "old/*", "trip.srt"]));
        $this->assertSame([0, "", ""], $this->runCli(["diff", "old/t*", "trip.srt"]));
    }


    public function testDualTakesTheFormatAndTrackOfEachFileByName(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $english = Subtitle::fromStringAutoDetectFormat($this->file("en.srt"));
        $german  = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv")->extract(3);
        $frames  = Subtitle::load(self::FIXTURES . "frames.sub", Format::MicroDvd, new ReadOptions(formats: [new MicroDvdReadOptions(25)]));

        $this->assertSame([0, DualSubtitle::fromPair($english, $german, new DualSubtitleOptions())->toString(Format::SubRip), ""],
                          $this->runCli(["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--secondary-track", "3"]));
        $this->assertSame([0, DualSubtitle::fromPair($german, $english, new DualSubtitleOptions())->toString(Format::SubRip), ""],
                          $this->runCli(["dual", "--primary", "movie.mkv", "--primary-track", "3", "--secondary", "en.srt"]));
        $this->assertSame([0, DualSubtitle::fromPair($frames, $english, new DualSubtitleOptions())->toString(Format::MicroDvd), ""],
                          $this->runCli(["dual", "--primary", "frames.sub", "--primary-from", "microdvd", "--secondary", "en.srt", "--input-fps", "25"]));
        $this->assertSame([3, ""], array_slice($this->runCli(["dual", "--primary", "en.srt", "--secondary", "frames.sub", "--secondary-from", "subviewer"]), 0, 2));

        preg_match_all('/^  --((?:primary|secondary)(?:-from|-track)?) /m', $this->runCli(["dual", "--help"])[1], $matches);
        $this->assertSame(["primary", "secondary", "primary-from", "primary-track", "secondary-from", "secondary-track"], $matches[1]);
    }


    public function testDualTakesThePrimaryAndTheSecondaryFileByName(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "dual/station_de.srt", "$this->dir/de.srt");
        file_put_contents("$this->dir/taken.srt", "old");
        $pair  = ["--primary", "en.srt", "--secondary", "de.srt"];
        $stack = file_get_contents(self::FILES . "dual/station_stack.srt");
        $before = $this->snapshot();

        foreach ([
            "dual takes no file arguments. Pass --primary FILE and --secondary FILE." => ["en.srt", "de.srt"],
            "dual takes no file arguments. Pass --primary FILE and --secondary FILE. " => ["en.srt", "--secondary", "de.srt"],
            "Pass --primary FILE and --secondary FILE."                                => ["--primary", "en.srt"],
            "--primary takes one file, got 2."                                         => ["--primary", "??.srt", "--secondary", "de.srt"],
            "The output de.srt is a file that the command reads. Pass another output file or directory." => [...$pair, "-o", "de.srt"],
            "The output en.srt is a file that the command reads. Pass another output file or directory." => [...$pair, "-o", "en.srt"],
            "The output ./en.srt is a file that the command reads. Pass another output file or directory." => [...$pair, "--output-dir", "."],
            "The output taken.srt exists. The tool never overwrites a file. Remove it, or pass another output file or directory." => [...$pair, "-o", "taken.srt"],
        ] as $error => $options) {
            $this->assertSame(self::usageError("dual", rtrim($error)), $this->runCli(["dual", ...$options, "--secondary-style", "i"]), $error);
            $this->assertSame($before, $this->snapshot(), $error);
        }

        $this->assertSame([0, $stack, ""], $this->runCli(["dual", ...$pair, "--secondary-style", "i"]));
        $this->assertSame([0, $stack, ""], $this->runCli(["dual", "--primary", "-", "--secondary", "de.srt", "--secondary-style", "i"], $this->file("en.srt")));
        $this->assertSame([0, "en.srt -> out/en.srt\n", ""], $this->runCli(["dual", ...$pair, "--secondary-style", "i", "--output-dir", "out"]));
        $this->assertSame($stack, $this->file("out/en.srt"));
    }


    public function testDualStacksTheSecondaryUnderThePrimary(): void
    {
        $this->copyStationPair();

        $this->assertSame([0, file_get_contents(self::FILES . "dual/station_stack.srt"), ""],
                          $this->runCli(["dual", "--primary", "en.srt", "--secondary", "de.srt", "--secondary-style", "i"]));
    }


    public function testDualWritesWebVtt(): void
    {
        $this->copyStationPair();

        $this->assertSame([0, "en.srt -> both.vtt\n", ""],
                          $this->runCli(["dual", "--primary", "en.srt", "--secondary", "de.srt", "--secondary-style", "i", "--to", "vtt", "-o", "both.vtt"]));
        $this->assertFileEquals(self::FILES . "dual/station_stack.vtt", "$this->dir/both.vtt");
    }


    public function testDualTopBottomWritesAss(): void
    {
        $this->copyStationPair();

        $this->assertSame(
            [0, file_get_contents(self::FILES . "dual/station_top_bottom.ass"), ""],
            $this->runCli(["dual", "--primary", "en.srt", "--secondary", "de.srt", "--mode", "top-bottom", "--secondary-style", 'font color="#ffff00"', "--to", "ass"])
        );
    }


    public function testDualSnapToleranceAndSecondaryAlignment(): void
    {
        $this->copyStationPair();

        $this->assertSame([0, file_get_contents(self::FILES . "dual/station_top_bottom_aligned.srt"), ""], $this->runCli([
            "dual", "--primary", "en.srt", "--secondary", "de.srt", "--mode", "top-bottom", "--snap-tolerance", "00:00.5", "--secondary-alignment", "7",
        ]));
    }


    private function copyStationPair(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "dual/station_de.srt", "$this->dir/de.srt");
    }


    private function assertSameFilesAsGoldenFolder(string $folder, string $outputDir): void
    {
        $golden = self::FILES . "hls/expected/$folder";
        $names  = array_map("basename", glob("$golden/*"));
        $this->assertSame($names, array_map("basename", glob("$this->dir/$outputDir/*")));
        foreach ($names as $name) {
            $this->assertFileEquals("$golden/$name", "$this->dir/$outputDir/$name", $name);
        }
    }


    public function testHlsWritesTheSegmentsAndThePlaylist(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");
        $options = ["--segment", "00:10", "--mpegts", "126000", "--pattern", "part%03d.vtt", "--media-duration", "00:02:30", "--playlist", "index.m3u8"];

        $this->assertSame([0, "talk.vtt -> out/index.m3u8, 15 segments\n", ""], $this->runCli(["hls", "talk.vtt", "--output-dir", "out", ...$options]));
        $this->assertSameFilesAsGoldenFolder("node-webvtt-subs1", "out");

        $this->assertSame([0, "talk.vtt -> local/index.m3u8, 15 segments\n", ""],
                          $this->runCli(["hls", "talk.vtt", "--output-dir", "local", ...$options, "--local", "10"]));
        $this->assertSameFilesAsGoldenFolder("node-webvtt-subs1-local", "local");

        $this->assertSame(self::usageError("hls", "The file name pattern must hold exactly one %d and no other placeholder, got \"part.vtt\"."),
                          $this->runCli(["hls", "talk.vtt", "--output-dir", "out", "--pattern", "part.vtt"]));
        $this->assertCount(16, glob("$this->dir/out/*"));

        $this->assertSame([0, "stdin -> stdin/subs.m3u8, 15 segments\n", ""],
                          $this->runCli(["hls", "-", "--output-dir", "stdin", "--segment", "10", "--media-duration", "150"], $this->file("talk.vtt")));
        $this->assertCount(16, glob("$this->dir/stdin/*"));
    }


    public function testHlsFailsBeforeAnyWriteWhenAPlaylistOrSegmentExists(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");
        mkdir("$this->dir/out");
        file_put_contents("$this->dir/out/sub99.vtt", "old");
        file_put_contents("$this->dir/out/index.m3u8", "old");
        $before = $this->snapshot();
        $exists = "exists. The tool never overwrites a file. Remove the playlist and the segments, or pass another --output-dir.";

        foreach ([
            "Pass --output-dir DIR."                       => ["talk.vtt"],
            "The hls command takes one input file, got 2." => ["talk.vtt", "trip.srt", "--output-dir", "new"],
            "out/sub99.vtt $exists"                        => ["talk.vtt", "--output-dir", "out"],
            "out/index.m3u8 $exists"                       => ["talk.vtt", "--output-dir", "out/", "--playlist", "index.m3u8", "--pattern", "p%d.vtt"],
            "The option --segment needs a finite number, got \"1e999\"."
                                                           => ["talk.vtt", "--output-dir", "new", "--segment", "1e999"],
            "The option --local needs a finite number, got \"1e999\"."
                                                           => ["talk.vtt", "--output-dir", "new", "--local", "1e999"],
            "The option --local needs seconds or a timecode such as 00:01:02.500, got \"1:2:3:4:5\"."
                                                           => ["talk.vtt", "--output-dir", "new", "--local", "1:2:3:4:5"],
        ] as $error => $arguments) {
            $this->assertSame(self::usageError("hls", $error), $this->runCli(["hls", ...$arguments]), $error);
            $this->assertSame($before, $this->snapshot(), $error);
        }
    }


    public function testHlsChecksAPlaylistThroughAMissingDirectoryBeforeTheRead(): void
    {
        file_put_contents("$this->dir/keep.txt", "old");

        $this->assertSame(
            self::usageError("hls", "new/../keep.txt exists. The tool never overwrites a file. Remove the playlist and the segments, or pass another --output-dir."),
            $this->runCli(["hls", "missing.srt", "--output-dir", "new", "--playlist", "../keep.txt"])
        );
        $this->assertSame("old", $this->file("keep.txt"));
        $this->assertDirectoryDoesNotExist("$this->dir/new");

        unlink("$this->dir/keep.txt");
        $this->assertSame(0, $this->runCli(["hls", "trip.srt", "--output-dir", "new", "--playlist", "../keep.txt"])[0]);
        $this->assertStringStartsWith("#EXTM3U", $this->file("keep.txt"));
        $this->assertFileExists("$this->dir/new/sub0.vtt");
    }


    public function testHlsNeverOverwritesItsInputOrASegment(): void
    {
        mkdir("$this->dir/out");
        copy("$this->dir/trip.srt", "$this->dir/out/sub1.vtt");
        copy("$this->dir/trip.srt", "$this->dir/out/subs.m3u8");

        $this->assertSame(self::usageError("hls", "The playlist sub0.vtt has the name of a segment. Pass another --playlist or --pattern."),
                          $this->runCli(["hls", "trip.srt", "--output-dir", "new", "--playlist", "sub0.vtt"]));
        $this->assertSame(self::usageError("hls", "The playlist p007.vtt has the name of a segment. Pass another --playlist or --pattern."),
                          $this->runCli(["hls", "trip.srt", "--output-dir", "new", "--pattern", "p%03d.vtt", "--playlist", "p007.vtt"]));
        $this->assertDirectoryDoesNotExist("$this->dir/new");

        foreach (["out/sub1.vtt", "out/subs.m3u8"] as $input) {
            $this->assertSame(self::usageError("hls", "The output would overwrite the input $input. Pass another --output-dir, --playlist or --pattern."),
                              $this->runCli(["hls", $input, "--output-dir", "out/"]));
            $this->assertSame($this->file("trip.srt"), $this->file($input));
        }
        $this->assertCount(2, glob("$this->dir/out/*"));
    }


    public function testHlsChecksTheSegmentsInTheDirectoryOfThePattern(): void
    {
        copy("$this->dir/trip.srt", "$this->dir/outside7.vtt");
        $before = $this->snapshot();

        $this->assertSame(self::usageError("hls", "new/../outside7.vtt exists. The tool never overwrites a file. " .
                                                  "Remove the playlist and the segments, or pass another --output-dir."),
                          $this->runCli(["hls", "trip.srt", "--output-dir", "new", "--pattern", "../outside%d.vtt"]));
        $this->assertSame(self::usageError("hls", "The output would overwrite the input outside7.vtt. Pass another --output-dir, --playlist or --pattern."),
                          $this->runCli(["hls", "outside7.vtt", "--output-dir", "new", "--pattern", "../outside%d.vtt", "--playlist", "p.m3u8"]));
        $this->assertSame(self::usageError("hls", "The playlist ../outside3.vtt has the name of a segment. Pass another --playlist or --pattern."),
                          $this->runCli(["hls", "trip.srt", "--output-dir", "new", "--pattern", "../outside%d.vtt", "--playlist", "../outside3.vtt"]));
        $this->assertSame($before, $this->snapshot());

        $this->assertSame(0, $this->runCli(["hls", "trip.srt", "--output-dir", "new", "--pattern", "../seg/%d.vtt"])[0]);
        $this->assertFileExists("$this->dir/seg/0.vtt");
    }
}
