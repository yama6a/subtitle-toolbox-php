<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\BinaryTestCase;

/**
 * Checks the diff, dual and hls commands.
 */
class BinaryDiffDualHlsTest extends BinaryTestCase
{
    public function testDiff(): void
    {
        copy(self::FILES . "diff/own_original.srt", "$this->dir/v1.srt");
        copy(self::FILES . "diff/own_edited.srt", "$this->dir/v2.srt");
        file_put_contents("$this->dir/v1.vtt", Subtitle::fromStringAutoDetectFormat($this->file("v1.srt"))->toString(Format::WebVtt));

        $this->assertSame([1, file_get_contents(self::FILES . "diff/own_report.txt"), ""], $this->runBinary(["diff", "v1.srt", "v2.srt"]));
        $this->assertSame([0, "", ""], $this->runBinary(["diff", "v1.srt", "v1.vtt"]));
        $this->assertSame(["oldFile" => "-", "newFile" => "v1.vtt"], array_slice(json_decode($this->runBinary(["diff", "-", "v1.vtt", "--json"], $this->file("v1.srt"))[1], true)[0], 0, 2));

        $options  = new SubtitleDiffOptions(timeTolerance: 0.5, ignoreFormatting: true, textOnly: true);
        $expected = SubtitleDiff::compare(Subtitle::fromStringAutoDetectFormat($this->file("v1.srt")), Subtitle::fromStringAutoDetectFormat($this->file("v2.srt")), $options);
        [$code, $stdout, $stderr] = $this->runBinary(["diff", "v1.srt", "v2.srt", "--json", "--time-tolerance", "00:00:00.5", "--ignore-formatting", "--text-only"]);
        $this->assertSame([1, ""], [$code, $stderr]);
        $this->assertCount(1, json_decode($stdout, true));
        $json = json_decode($stdout, true)[0];
        $this->assertSame(["v1.srt", "v2.srt", false], [$json["oldFile"], $json["newFile"], $json["equal"]]);
        $this->assertSame(array_map(fn ($difference): string => $difference->kind->value, $expected), array_column($json["differences"], "kind"));
        $old = $expected[0]->oldCue;
        $this->assertEquals(["start" => $old->getStart(), "end" => $old->getEnd(), "lines" => $old->getLines(), "forced" => false],
                            $json["differences"][0]["old"]);
        $this->assertSame($expected[0]->oldIndex, $json["differences"][0]["oldIndex"]);

        $this->assertSame(2, $this->runBinary(["diff", "v1.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["diff", "v1.srt", "v2.srt", "--time-tolerance", "-1"])[0]);
        $this->assertSame([3, "", "missing.srt: The file does not exist.\n"], $this->runBinary(["diff", "v1.srt", "missing.srt"]));
    }


    public function testDiffReadsTheNewFileWithNewFromAndNewTrack(): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $mkv      = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv");
        $expected = SubtitleDiff::toText(SubtitleDiff::compare($mkv->extract(3), $mkv->extract(8)));

        $this->assertStringContainsString("Der Zug nach Hamburg", $expected);
        $this->assertStringContainsString("Next stop: Central Station.", $expected);
        $this->assertSame([1, $expected, ""], $this->runBinary(["diff", "movie.mkv", "movie.mkv", "--track", "3", "--new-track", "8"]));

        $frames   = Subtitle::load(self::FIXTURES . "frames.sub", Format::MicroDvd, new ReadOptions(format: new MicroDvdReadOptions(25)));
        $expected = SubtitleDiff::toText(SubtitleDiff::compare(Subtitle::load(self::FIXTURES . "trip.srt", Format::SubRip), $frames));
        $this->assertSame([1, $expected, ""], $this->runBinary(["diff", "trip.srt", "frames.sub", "--new-from", "microdvd", "--input-fps", "25"]));
        $this->assertSame([3, ""], array_slice($this->runBinary(["diff", "trip.srt", "frames.sub", "--new-from", "subviewer"]), 0, 2));

        $this->assertSame(2, $this->runBinary(["diff", "trip.srt", "frames.sub", "--new-from", "docx"])[0]);
        $this->assertSame(2, $this->runBinary(["diff", "movie.mkv", "movie.mkv", "--track", "3", "--new-track", "x"])[0]);
    }


    public function testDiffDualAndHlsRejectKeepGoing(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");
        $error = fn (string $command): array => [2, "", "Error: Unknown option --keep-going.\nRun \"subtitle-toolbox help $command\" for the usage.\n"];

        $this->assertSame($error("diff"), $this->runBinary(["diff", "trip.srt", "trip.srt", "--keep-going"]));
        $this->assertSame($error("dual"), $this->runBinary(["dual", "--primary", "trip.srt", "--secondary", "shop.vtt", "--keep-going"]));
        $this->assertSame($error("hls"), $this->runBinary(["hls", "talk.vtt", "--output-dir", "out", "--keep-going"]));
        $this->assertFileDoesNotExist("$this->dir/out");
    }


    public function testDiffAppliesFromTrackAndEncodingToBothFiles(): void
    {
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/old.json");
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/new.json");
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $mkv      = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv");

        $this->assertSame([0, "", ""], $this->runBinary(["diff", "old.json", "new.json", "--from", "deepgram"]));
        $this->assertSame([0, "", ""], $this->runBinary(["diff", "movie.mkv", "movie.mkv", "--track", "3"]));
        $this->assertSame([1, SubtitleDiff::toText(SubtitleDiff::compare($mkv->extract(3), $mkv->extract(8))), ""],
                          $this->runBinary(["diff", "movie.mkv", "movie.mkv", "--track", "3", "--new-track", "8"]));
        $this->assertSame(1, $this->runBinary(["diff", "trip.srt", "new.json", "--new-from", "deepgram"])[0]);
        $this->assertSame(3, $this->runBinary(["diff", "trip.srt", "new.json"])[0]);
    }


    public function testDiffTakesOneOldFile(): void
    {
        mkdir("$this->dir/old");
        copy("$this->dir/trip.srt", "$this->dir/old/trip.srt");
        copy("$this->dir/shop.vtt", "$this->dir/old/shop.vtt");
        $usage = "\nRun \"subtitle-toolbox help diff\" for the usage.\n";

        $this->assertSame([2, "", "Error: The diff command takes one old file, got 2.$usage"], $this->runBinary(["diff", "old", "trip.srt"]));
        $this->assertSame([2, "", "Error: The diff command takes one old file, got 2.$usage"], $this->runBinary(["diff", "old/*", "trip.srt"]));
        $this->assertSame([0, "", ""], $this->runBinary(["diff", "old/t*", "trip.srt"]));
    }


    public function testDualTakesTheFormatAndTrackOfEachFileByName(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        $english = Subtitle::fromStringAutoDetectFormat($this->file("en.srt"));
        $german  = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv")->extract(3);
        $frames  = Subtitle::load(self::FIXTURES . "frames.sub", Format::MicroDvd, new ReadOptions(format: new MicroDvdReadOptions(25)));

        $this->assertSame([0, DualSubtitle::fromPair($english, $german, new DualSubtitleOptions())->toString(Format::SubRip), ""],
                          $this->runBinary(["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--secondary-track", "3"]));
        $this->assertSame([0, DualSubtitle::fromPair($german, $english, new DualSubtitleOptions())->toString(Format::SubRip), ""],
                          $this->runBinary(["dual", "--primary", "movie.mkv", "--primary-track", "3", "--secondary", "en.srt"]));
        $this->assertSame([0, DualSubtitle::fromPair($frames, $english, new DualSubtitleOptions())->toString(Format::MicroDvd), ""],
                          $this->runBinary(["dual", "--primary", "frames.sub", "--primary-from", "microdvd", "--secondary", "en.srt", "--input-fps", "25"]));
        $this->assertSame([3, ""], array_slice($this->runBinary(["dual", "--primary", "en.srt", "--secondary", "frames.sub", "--secondary-from", "subviewer"]), 0, 2));

        foreach (["from" => "srt", "track" => "3", "new-from" => "srt", "new-track" => "3"] as $option => $value) {
            $this->assertSame([2, "", "Error: Unknown option --$option.\nRun \"subtitle-toolbox help dual\" for the usage.\n"],
                              $this->runBinary(["dual", "--primary", "en.srt", "--secondary", "movie.mkv", "--$option", $value]));
        }
        preg_match_all('/^  --((?:primary|secondary)(?:-from|-track)?) /m', $this->runBinary(["dual", "--help"])[1], $matches);
        $this->assertSame(["primary", "secondary", "primary-from", "primary-track", "secondary-from", "secondary-track"], $matches[1]);
    }


    public function testDualTakesThePrimaryAndTheSecondaryFileByName(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "dual/station_de.srt", "$this->dir/de.srt");
        file_put_contents("$this->dir/taken.srt", "old");
        $usage = "\nRun \"subtitle-toolbox help dual\" for the usage.\n";
        $pair  = ["--primary", "en.srt", "--secondary", "de.srt"];
        $stack = file_get_contents(self::FILES . "dual/station_stack.srt");
        $before = $this->snapshot();

        foreach ([
            "Error: dual takes no file arguments. Pass --primary FILE and --secondary FILE." => ["en.srt", "de.srt"],
            "Error: dual takes no file arguments. Pass --primary FILE and --secondary FILE. " => ["en.srt", "--secondary", "de.srt"],
            "Error: Pass --primary FILE and --secondary FILE."                                => ["--primary", "en.srt"],
            "Error: --primary takes one file, got 2."                                         => ["--primary", "??.srt", "--secondary", "de.srt"],
            "Error: The output de.srt is a file that the command reads. Pass another output file or directory." => [...$pair, "-o", "de.srt"],
            "Error: The output en.srt is a file that the command reads. Pass another output file or directory." => [...$pair, "-o", "en.srt"],
            "Error: The output ./en.srt is a file that the command reads. Pass another output file or directory." => [...$pair, "--output-dir", "."],
            "Error: The output taken.srt exists. The tool never overwrites a file. Remove it, or pass another output file or directory." => [...$pair, "-o", "taken.srt"],
        ] as $error => $options) {
            $this->assertSame([2, "", rtrim($error) . $usage], $this->runBinary(["dual", ...$options, "--secondary-style", "i"]), $error);
            $this->assertSame($before, $this->snapshot(), $error);
        }

        $this->assertSame([0, $stack, ""], $this->runBinary(["dual", ...$pair, "--secondary-style", "i"]));
        $this->assertSame([0, $stack, ""], $this->runBinary(["dual", "--primary", "-", "--secondary", "de.srt", "--secondary-style", "i"], $this->file("en.srt")));
        $this->assertSame([0, "en.srt -> out/en.srt\n", ""], $this->runBinary(["dual", ...$pair, "--secondary-style", "i", "--output-dir", "out"]));
        $this->assertSame($stack, $this->file("out/en.srt"));
    }


    public function testDual(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "dual/station_de.srt", "$this->dir/de.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "dual/station_stack.srt"), ""],
                          $this->runBinary(["dual", "--primary", "en.srt", "--secondary", "de.srt", "--secondary-style", "i"]));
        $this->assertSame([0, "en.srt -> both.vtt\n", ""],
                          $this->runBinary(["dual", "--primary", "en.srt", "--secondary", "de.srt", "--secondary-style", "i", "--to", "vtt", "-o", "both.vtt"]));
        $this->assertFileEquals(self::FILES . "dual/station_stack.vtt", "$this->dir/both.vtt");
        $this->assertSame(
            [0, file_get_contents(self::FILES . "dual/station_top_bottom.ass"), ""],
            $this->runBinary(["dual", "--primary", "en.srt", "--secondary", "de.srt", "--mode", "top-bottom", "--secondary-style", 'font color="#ffff00"', "--to", "ass"])
        );

        $merged = DualSubtitle::fromPair(Subtitle::fromStringAutoDetectFormat($this->file("en.srt")), Subtitle::fromStringAutoDetectFormat($this->file("de.srt")),
                                      new DualSubtitleOptions(mode: DualSubtitleMode::TopBottom, snapTolerance: 0.5, secondaryAlignment: 7));
        $this->assertSame([0, $merged->toString(Format::SubRip), ""], $this->runBinary([
            "dual", "--primary", "en.srt", "--secondary", "de.srt", "--mode", "top-bottom", "--snap-tolerance", "00:00.5", "--secondary-alignment", "7",
        ]));

        foreach ([[], ["--mode", "side"], ["--secondary-alignment", "0"], ["--secondary-style", "em"], ["--snap-tolerance", "-1"]] as $options) {
            $this->assertSame(2, $this->runBinary(["dual", "--primary", "en.srt", ...($options === [] ? [] : ["--secondary", "de.srt"]), ...$options])[0], implode(" ", $options));
        }
    }


    public function testHlsWritesTheSegmentsAndThePlaylist(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");
        $expected = HlsWebVttSegmenter::segment(
            Subtitle::fromStringAutoDetectFormat($this->file("talk.vtt")),
            new HlsSegmentOptions(segmentDuration: 10, mpegts: 126000, fileNamePattern: "part%03d.vtt", mediaDuration: 150)
        );

        $this->assertSame([0, "talk.vtt -> out/index.m3u8, 15 segments\n", ""], $this->runBinary([
            "hls", "talk.vtt", "--output-dir", "out", "--segment", "00:10", "--mpegts", "126000", "--pattern", "part%03d.vtt",
            "--media-duration", "00:02:30", "--playlist", "index.m3u8",
        ]));
        $this->assertSame($expected->getPlaylist(), $this->file("out/index.m3u8"));
        foreach ($expected->getSegments() as $name => $content) {
            $this->assertSame($content, $this->file("out/$name"));
        }
        $this->assertCount(16, glob("$this->dir/out/*"));

        $this->assertSame(2, $this->runBinary(["hls", "talk.vtt", "--output-dir", "out", "--pattern", "part.vtt"])[0]);
        $this->assertCount(16, glob("$this->dir/out/*"));

        $this->assertSame([0, "stdin -> stdin/subs.m3u8, 15 segments\n", ""],
                          $this->runBinary(["hls", "-", "--output-dir", "stdin", "--segment", "10", "--media-duration", "150"], $this->file("talk.vtt")));
        $this->assertCount(16, glob("$this->dir/stdin/*"));
    }


    public function testHlsFailsBeforeAnyWriteWhenAPlaylistOrSegmentExists(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");
        mkdir("$this->dir/out");
        file_put_contents("$this->dir/out/sub99.vtt", "old");
        file_put_contents("$this->dir/out/index.m3u8", "old");
        $before = $this->snapshot();
        $usage  = "\nRun \"subtitle-toolbox help hls\" for the usage.\n";
        $exists = "exists. The tool never overwrites a file. Remove the playlist and the segments, or pass another --output-dir.$usage";

        foreach ([
            "Error: Pass --output-dir DIR.$usage"                       => ["talk.vtt"],
            "Error: The hls command takes one input file, got 2.$usage" => ["talk.vtt", "trip.srt", "--output-dir", "new"],
            "Error: out/sub99.vtt $exists"                              => ["talk.vtt", "--output-dir", "out"],
            "Error: out/index.m3u8 $exists"                             => ["talk.vtt", "--output-dir", "out/", "--playlist", "index.m3u8", "--pattern", "p%d.vtt"],
            "Error: The option --segment needs a finite number, got \"1e999\".$usage"
                                                                        => ["talk.vtt", "--output-dir", "new", "--segment", "1e999"],
            "Error: The option --local needs a finite number, got \"1e999\".$usage"
                                                                        => ["talk.vtt", "--output-dir", "new", "--local", "1e999"],
            "Error: The option --local needs seconds or a timecode such as 00:01:02.500, got \"1:2:3:4:5\".$usage"
                                                                        => ["talk.vtt", "--output-dir", "new", "--local", "1:2:3:4:5"],
        ] as $error => $arguments) {
            $this->assertSame([2, "", $error], $this->runBinary(["hls", ...$arguments]), $error);
            $this->assertSame($before, $this->snapshot(), $error);
        }
    }


    public function testHlsChecksAPlaylistThroughAMissingDirectoryBeforeTheRead(): void
    {
        file_put_contents("$this->dir/keep.txt", "old");

        $this->assertSame(
            [2, "", "Error: new/../keep.txt exists. The tool never overwrites a file. Remove the playlist and the segments, or pass another " .
                    "--output-dir.\nRun \"subtitle-toolbox help hls\" for the usage.\n"],
            $this->runBinary(["hls", "missing.srt", "--output-dir", "new", "--playlist", "../keep.txt"])
        );
        $this->assertSame("old", $this->file("keep.txt"));
        $this->assertDirectoryDoesNotExist("$this->dir/new");

        unlink("$this->dir/keep.txt");
        $this->assertSame(0, $this->runBinary(["hls", "trip.srt", "--output-dir", "new", "--playlist", "../keep.txt"])[0]);
        $this->assertStringStartsWith("#EXTM3U", $this->file("keep.txt"));
        $this->assertFileExists("$this->dir/new/sub0.vtt");
    }


    public function testHlsNeverOverwritesItsInputOrASegment(): void
    {
        mkdir("$this->dir/out");
        copy("$this->dir/trip.srt", "$this->dir/out/sub1.vtt");
        copy("$this->dir/trip.srt", "$this->dir/out/subs.m3u8");
        $usage = "\nRun \"subtitle-toolbox help hls\" for the usage.\n";

        $this->assertSame([2, "", "Error: The playlist sub0.vtt has the name of a segment. Pass another --playlist or --pattern.$usage"],
                          $this->runBinary(["hls", "trip.srt", "--output-dir", "new", "--playlist", "sub0.vtt"]));
        $this->assertSame([2, "", "Error: The playlist p007.vtt has the name of a segment. Pass another --playlist or --pattern.$usage"],
                          $this->runBinary(["hls", "trip.srt", "--output-dir", "new", "--pattern", "p%03d.vtt", "--playlist", "p007.vtt"]));
        $this->assertDirectoryDoesNotExist("$this->dir/new");

        foreach (["out/sub1.vtt", "out/subs.m3u8"] as $input) {
            $this->assertSame([2, "", "Error: The output would overwrite the input $input. Pass another --output-dir, --playlist or --pattern.$usage"],
                              $this->runBinary(["hls", $input, "--output-dir", "out/"]));
            $this->assertSame($this->file("trip.srt"), $this->file($input));
        }
        $this->assertCount(2, glob("$this->dir/out/*"));
    }
}
