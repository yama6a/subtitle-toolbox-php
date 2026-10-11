<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Formatters\SccFitChange;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\WriteOptions;

/**
 * Checks where the commands write: standard output, -o, --output-dir and batches.
 */
class BinaryOutputTest extends CliTestCase
{
    public function testConvertWritesTheFormatOfToIntoTheOutputFile(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "trip.vtt"]);

        $this->assertSame([0, "trip.srt -> trip.vtt\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("trip.vtt"));
    }


    public function testConvertToTsvWritesTabs(): void
    {
        $this->assertSame([0, "trip.srt -> trip.tsv\n", ""], $this->runCli(["convert", "trip.srt", "--to", "tsv", "-o", "trip.tsv"]));
        $tsv = $this->file("trip.tsv");
        $this->assertStringStartsWith(self::BOM . "start\tend\ttext\n00:00:01.000\t", $tsv);
        $this->assertSame(0, substr_count($tsv, ","));

        $this->assertSame([0, "trip.tsv -> trip.csv\n", ""], $this->runCli(["convert", "trip.tsv", "--to", "csv", "-o", "trip.csv"]));
        $this->assertStringStartsWith(self::BOM . "start,end,text\n00:00:01.000,", $this->file("trip.csv"));
        $this->assertSame(0, substr_count($this->file("trip.csv"), "\t"));
    }


    public function testConvertNeedsToAlsoWithAnOutputFile(): void
    {
        $usage = "\nRun \"subtitle-toolbox help convert\" for the usage.\n";
        $toTip = "Error: Pass --to FORMAT, also when the format stays the same, for example --to srt.$usage";

        $this->assertSame([2, "", $toTip], $this->runCli(["convert", "trip.srt", "-o", "trip.vtt"]));
        $this->assertSame([2, "", $toTip], $this->runCli(["convert", "trip.srt", "trip.vtt"]));
        $this->assertSame([2, "", $toTip], $this->runCli(["convert", "trip.srt", "--strip-tags"]));
        $this->assertFileDoesNotExist("$this->dir/trip.vtt");

        $this->assertSame([0, $this->tripAs(Format::SubRip), ""], $this->runCli(["convert", "trip.srt", "--to", "srt"]));
    }


    public function testTheOutputExtensionNeverPicksTheFormat(): void
    {
        copy(__DIR__ . "/../files/whisper/real/openai_whisper_german.json", "$this->dir/lecture.json");

        $this->assertSame(
            [2, "", "Error: The extension of --output trip.vtt names the format vtt, not the --to format srt.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runCli(["convert", "trip.srt", "--to", "srt", "-o", "trip.vtt"])
        );
        $this->assertSame(2, $this->runCli(["retime", "trip.srt", "--shift", "1", "--to", "vtt", "-o", "trip.ass"])[0]);
        $this->assertSame(2, $this->runCli(["dual", "--primary", "trip.srt", "--secondary", "shop.vtt", "--to", "ass", "-o", "both.srt"])[0]);
        $this->assertSame([], array_diff(scandir($this->dir), [".", "..", ...array_map("basename", glob(self::FIXTURES . "*")), "lecture.json"]));

        $this->assertSame([0, "lecture.json -> out.json\n", ""], $this->runCli(["convert", "lecture.json", "--to", "json", "-o", "out.json"]));
        $this->assertSame(Subtitle::fromStringAutoDetectFormat($this->file("lecture.json"))->toString(Format::Json), $this->file("out.json"));
        $this->assertSame(0, $this->runCli(["convert", "trip.srt", "--to", "mpl2", "-o", "trip.txt"])[0]);
        $this->assertSame($this->tripAs(Format::Mpl2), $this->file("trip.txt"));
        $this->assertSame(0, $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "trip.bak"])[0]);
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("trip.bak"));

        // Without --to, retime keeps the input format, whatever the extension of -o.
        $this->assertSame(0, $this->runCli(["retime", "trip.srt", "--shift", "0", "-o", "kept.vtt"])[0]);
        $this->assertSame($this->tripAs(Format::SubRip), $this->file("kept.vtt"));
    }


    public function testConvertNeverOverwritesAFile(): void
    {
        file_put_contents("$this->dir/trip.vtt", "old");
        $usage = "\nRun \"subtitle-toolbox help convert\" for the usage.\n";

        $this->assertSame(
            [2, "", "Error: The output trip.vtt exists. The tool never overwrites a file. Remove it, or pass another output file or directory.$usage"],
            $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "trip.vtt"])
        );
        $this->assertSame("old", $this->file("trip.vtt"));
        $this->assertSame(
            [2, "", "Error: The output trip.srt is a file that the command reads. Pass another output file or directory.$usage"],
            $this->runCli(["convert", "trip.srt", "--to", "srt", "-o", "trip.srt"])
        );
        $this->assertSame(file_get_contents(self::FIXTURES . "trip.srt"), $this->file("trip.srt"));
    }


    public function testAnOutputThroughAMissingDirectoryIsCheckedAtThePathItNames(): void
    {
        file_put_contents("$this->dir/keep.vtt", "old");

        $this->assertSame(
            [2, "", "Error: The output new/../keep.vtt exists. The tool never overwrites a file. Remove it, or pass another output file or " .
                    "directory.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "new/../keep.vtt"])
        );
        $this->assertSame("old", $this->file("keep.vtt"));

        unlink("$this->dir/keep.vtt");
        $this->assertSame([0, "trip.srt -> new/../keep.vtt\n", ""], $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "new/../keep.vtt"]));
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("keep.vtt"));
        $this->assertDirectoryDoesNotExist("$this->dir/new");
    }


    public function testAnOutputThatEndsWithASlashIsAUsageError(): void
    {
        $this->assertSame(
            [2, "", "Error: The output newdir/ ends with a slash. Pass a file name, or pass --output-dir newdir/.\n" .
                    "Run \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runCli(["convert", "trip.srt", "--to", "srt", "-o", "newdir/"])
        );
        $this->assertDirectoryDoesNotExist("$this->dir/newdir");
    }


    public function testAnOutputThatEndsWithADotPartIsAUsageError(): void
    {
        foreach (["newdir/." => ".", "newdir/.." => "..", "." => ".", ".." => ".."] as $output => $lastPart) {
            $this->assertSame(
                [2, "", "Error: The output $output ends with \"$lastPart\". Pass a file name, or pass --output-dir $output.\n" .
                        "Run \"subtitle-toolbox help convert\" for the usage.\n"],
                $this->runCli(["convert", "trip.srt", "--to", "srt", "-o", $output])
            );
        }
        $this->assertDirectoryDoesNotExist("$this->dir/newdir");
    }


    public function testAnOutputThatIsADirectoryNamesOutputDir(): void
    {
        mkdir("$this->dir/out");

        $this->assertSame(
            [2, "", "Error: The output out is a directory. Pass --output-dir out.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runCli(["convert", "trip.srt", "--to", "srt", "-o", "out"])
        );
        $this->assertSame([], glob("$this->dir/out/*"));
    }


    public function testAnOutputDirThatIsAFileIsAUsageError(): void
    {
        foreach ([["convert", "trip.srt", "--to", "srt"], ["hls", "trip.srt"]] as $call) {
            $this->assertSame(
                [2, "", "Error: The --output-dir trip.srt is a file. Pass a directory.\nRun \"subtitle-toolbox help $call[0]\" for the usage.\n"],
                $this->runCli([...$call, "--output-dir", "trip.srt"])
            );
            $this->assertSame(file_get_contents(self::FIXTURES . "trip.srt"), $this->file("trip.srt"));
        }
    }


    public function testConvertWritesOneInputToStandardOutput(): void
    {
        $shop = Subtitle::fromStringAutoDetectFormat($this->file("shop.vtt"));

        $this->assertSame([0, $shop->toString(Format::SubRip), ""], $this->runCli(["convert", "shop.vtt", "--to", "srt"]));
        $this->assertSame([0, $shop->toString(Format::SubRip), ""], $this->runCli(["convert", "shop.vtt", "--to", "srt", "-o", "-"]));
        $this->assertFileDoesNotExist("$this->dir/shop.srt");

        $this->assertSame([0, "shop.vtt -> out.srt\n", ""], $this->runCli(["convert", "shop.vtt", "--to", "srt", "-o", "out.srt"]));
        $this->assertSame($shop->toString(Format::SubRip), $this->file("out.srt"));
    }


    public function testSeveralInputsNeedAnOutputDirectory(): void
    {
        mkdir("$this->dir/season1");
        rename("$this->dir/trip.srt", "$this->dir/season1/trip.srt");
        rename("$this->dir/shop.vtt", "$this->dir/season1/shop.vtt");
        $shop  = $this->file("season1/shop.vtt");
        $usage = "\nRun \"subtitle-toolbox help convert\" for the usage.\n";

        $this->assertSame(
            [2, "", "Error: 2 input files need --output-dir DIR. One input file goes to standard output or to -o FILE.$usage"],
            $this->runCli(["convert", "season1/shop.vtt", "season1/trip.srt", "--to", "ass"])
        );
        $this->assertSame(
            [2, "", "Error: --output takes one input file, got 2. Pass --output-dir DIR for several files.$usage"],
            $this->runCli(["convert", "season1/*", "--to", "ass", "-o", "both.ass"])
        );
        $this->assertSame(
            [2, "", "Error: The output season1/shop.vtt is a file that the command reads. Pass another output file or directory.$usage"],
            $this->runCli(["convert", "season1/trip.srt", "season1/shop.vtt", "--to", "vtt", "--output-dir", "season1"])
        );
        $this->assertSame(["shop.vtt", "trip.srt"], array_values(array_diff(scandir("$this->dir/season1"), [".", ".."])));
        $this->assertFileDoesNotExist("$this->dir/both.ass");

        $this->assertSame(
            [0, "season1/shop.vtt -> out/shop.ass\nseason1/trip.srt -> out/trip.ass\n2 files: 2 succeeded, 0 failed.\n", ""],
            $this->runCli(["convert", "season1/shop.vtt", "season1/trip.srt", "--to", "ass", "--output-dir", "out"])
        );
        $this->assertSame(Subtitle::fromStringAutoDetectFormat($shop)->toString(Format::Ass), $this->file("out/shop.ass"));
        $this->assertSame($this->tripAs(Format::Ass), $this->file("out/trip.ass"));
    }


    public function testInPlaceAndForceAreRemoved(): void
    {
        copy(self::FILES . "dual/station_de.srt", "$this->dir/de.srt");
        foreach ([
            ["convert", "trip.srt", "--to", "srt"],
            ["retime", "trip.srt", "--shift", "1"],
            ["sync", "trip.srt", "--reference", "de.srt"],
            ["dual", "--primary", "trip.srt", "--secondary", "de.srt"],
            ["hls", "trip.srt", "--output-dir", "out"],
        ] as $call) {
            foreach (["in-place", "force"] as $option) {
                $this->assertSame([2, "", "Error: Unknown option --$option.\nRun \"subtitle-toolbox help $call[0]\" for the usage.\n"],
                                  $this->runCli([...$call, "--$option"]));
                $this->assertDoesNotMatchRegularExpression("/--$option\\b/", $this->runCli([$call[0], "--help", "all"])[1]);
            }
        }
        $this->assertSame(file_get_contents(self::FIXTURES . "trip.srt"), $this->file("trip.srt"));
        $this->assertDirectoryDoesNotExist("$this->dir/out");
    }


    public function testTwoInputsWithOneOutputFailBeforeAnyWrite(): void
    {
        mkdir("$this->dir/a");
        mkdir("$this->dir/b");
        copy("$this->dir/trip.srt", "$this->dir/a/trip.srt");
        copy("$this->dir/shop.vtt", "$this->dir/b/trip.vtt");
        copy("$this->dir/shop.vtt", "$this->dir/b/trip.txt");
        $usage = "\nRun \"subtitle-toolbox help convert\" for the usage.\n";

        $this->assertSame(
            [2, "", "Error: a/trip.srt and b/trip.vtt would both write out/trip.vtt. Pass them in two runs.$usage"],
            $this->runCli(["convert", "a/trip.srt", "b/trip.vtt", "--to", "vtt", "--output-dir", "out"])
        );
        // .txt is no SubRip extension, so --to srt renames b/trip.txt to trip.srt.
        $this->assertSame(
            [2, "", "Error: trip.srt and b/trip.txt would both write out/trip.srt. Pass them in two runs.$usage"],
            $this->runCli(["convert", "trip.srt", "b/trip.txt", "--to", "srt", "--output-dir", "out"])
        );
        $this->assertDirectoryDoesNotExist("$this->dir/out");

        $this->assertSame([0, "trip.srt -> out/trip.vtt\n", ""],
                          $this->runCli(["convert", "trip.srt", "./trip.srt", "a/../trip.srt", "--to", "vtt", "--output-dir", "out"]));
    }


    public function testStandardInputTakesNoOutputDir(): void
    {
        $this->assertSame(
            [2, "", "Error: Standard input has no file name for --output-dir. Pass -o FILE.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runCli(["convert", "-", "--to", "vtt", "--output-dir", "out"], $this->file("trip.srt"))
        );
        $this->assertSame(2, $this->runCli(["retime", "trip.srt", "-", "--shift", "1", "--output-dir", "out"], $this->file("trip.srt"))[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/out");
    }


    public function testConvertNeverOverwritesTheSubFileOfAVobSubInput(): void
    {
        copy(self::FILES . "vobsub/text-pal.idx", "$this->dir/text.idx");
        copy(self::FILES . "vobsub/text-pal.sub", "$this->dir/text.sub");

        foreach ([["-o", "text.sub"], ["--output-dir", "."]] as $output) {
            [$code, , $stderr] = $this->runCli(["convert", "text.idx", "--to", "microdvd", "--fps", "25", "--skip-image-cues", ...$output]);
            $this->assertSame([2, "Error: The output " . ($output[0] === "-o" ? "" : "./") . "text.sub is a file that the command reads. Pass another " .
                                  "output file or directory.\nRun \"subtitle-toolbox help convert\" for the usage.\n"], [$code, $stderr]);
            $this->assertFileEquals(self::FILES . "vobsub/text-pal.sub", "$this->dir/text.sub");
        }
    }


    public function testBatchGoesOnWithKeepGoingAndPrintsASummary(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["convert", "*.srt", "--to", "vtt", "--output-dir", "out", "--keep-going"]);

        $this->assertSame(3, $code);
        $this->assertSame("latin1.srt -> out/latin1.vtt\ntrip.srt -> out/trip.vtt\n3 files: 2 succeeded, 1 failed.\n", $stdout);
        $this->assertSame("broken.srt: ParsingException (Error #100): Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\". (line 6)\n" . FileCommand::LENIENT_HINT . "\n", $stderr);
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("out/trip.vtt"));
        $this->assertFileDoesNotExist("$this->dir/out/broken.vtt");
    }


    public function testContentThatTheOutputFormatCannotHoldFailsThatFileWithExitCode3(): void
    {
        file_put_contents("$this->dir/five.srt", "1\n00:00:01,000 --> 00:00:03,000\nA\nB\nC\nD\nE\n");
        file_put_contents("$this->dir/one.srt", "1\n00:00:01,000 --> 00:00:03,000\nHello\n");
        try {
            Subtitle::load("$this->dir/five.srt", Format::SubRip)->toString(Format::Scc);
            $this->fail("SCC holds at most 4 lines.");
        } catch (UnwritableContentException) {
        }

        $this->assertSame(
            [3, "one.srt -> out/one.scc\n2 files: 1 succeeded, 1 failed.\n",
             "five.srt: Cue #0 at 1 s has 5 lines, but SCC allows 4. Pass --structure-wrap --structure-max-cpl 32 --structure-max-lines 4.\n"],
            $this->runCli(["convert", "five.srt", "one.srt", "--to", "scc", "--output-dir", "out", "--keep-going"])
        );
        $this->assertFileDoesNotExist("$this->dir/out/five.scc");
    }


    public function testTheSccHintNamesSplitLongWhenWrappingCannotFitTheCue(): void
    {
        copy(__DIR__ . "/../files/stl/real/bakery_teletext_25fps.stl", "$this->dir/bakery.stl");
        $wrap = ["--structure-wrap", "--structure-max-cpl", "32", "--structure-max-lines", "4"];

        [$code, $stdout, $stderr] = $this->runCli(["convert", "bakery.stl", "--from", "stl", "--to", "scc", ...$wrap, "-o", "-"]);
        $this->assertSame(3, $code);
        $this->assertSame("", $stdout);
        $this->assertStringEndsWith(". Pass --structure-split-long --structure-wrap --structure-max-cpl 32 --structure-max-lines 4.\n", $stderr);

        [$code, $stdout, $stderr] = $this->runCli(["convert", "bakery.stl", "--from", "stl", "--to", "scc", "--structure-split-long", ...$wrap, "-o", "-"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Scenarist_SCC V1.0\n", $stdout);
    }


    public function testSccFitPrintsEachChangeOnStandardError(): void
    {
        copy(self::FILES . "scc/fit/own_ferry.srt", "$this->dir/ferry.srt");
        $report  = (new SccFormatter())->formatWithReport(Subtitle::load("$this->dir/ferry.srt", Format::SubRip),
                                                          new WriteOptions(formats: [new SccWriteOptions(fit: true)]));
        $changes = implode("", array_map(fn (SccFitChange $change): string => "ferry.srt: $change->message ({$change->action->value})\n", $report->changes));

        $this->assertSame(3, $this->runCli(["convert", "ferry.srt", "--to", "scc", "-o", "-"])[0]);
        $this->assertSame([0, file_get_contents(self::FILES . "scc/fit/own_ferry.scc"), $changes],
                          $this->runCli(["convert", "ferry.srt", "--to", "scc", "-o", "-", "--scc-fit"]));
        $this->assertStringContainsString("ferry.srt: Cue #3 at 5 s is left out.", $changes);
        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "-", "--scc-fit"]));
    }


    public function testWebVttOutputPrintsEachDroppedColorOnStandardError(): void
    {
        copy(self::FILES . "srt/real/own_font_colors.srt", "$this->dir/ferry.srt");

        $this->assertSame(
            [0, file_get_contents(self::FILES . "vtt/real/own_font_colors_from_srt.vtt"),
             "ferry.srt: Cue #3 at 4 s: dropped the color \"#123456\", because WebVTT has classes for 8 colors only.\n"],
            $this->runCli(["convert", "ferry.srt", "--to", "vtt", "-o", "-"])
        );
    }


    public function testBatchStopsAtTheFirstFailureWithoutKeepGoing(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["convert", "broken.srt", "trip.srt", "shop.vtt", "--to", "srt", "--output-dir", "out"]);

        $this->assertSame(3, $code);
        $this->assertSame("3 files: 0 succeeded, 1 failed, 2 skipped.\n", $stdout);
        $this->assertStringEndsWith("Stopped at the first failure. Pass --keep-going to process the other files.\n", $stderr);
        $this->assertDirectoryDoesNotExist("$this->dir/out");
    }


    /**
     * @return array<string, array{list<string>, string}> the command with its options, and the output extension of trip.srt
     */
    public static function writeCommands(): array
    {
        return [
            "convert" => [["convert", "--to", "vtt"], "vtt"],
            "retime"  => [["retime", "--shift", "1"], "srt"],
            "sync"    => [["sync", "--reference", "ref.srt"], "srt"],
        ];
    }


    /**
     * Runs $call[0] with $inputs, then the options of $call, then $options.
     *
     * @param list<string> $call
     * @param list<string> $inputs
     *
     * @return array{int, string, string}
     */
    private function runWrite(array $call, array $inputs, string ...$options): array
    {
        return $this->runCli([$call[0], ...$inputs, ...array_slice($call, 1), ...$options]);
    }


    /**
     * @param list<string> $call
     */
    #[DataProvider("writeCommands")]
    public function testOneInputGoesToStandardOutputOrToTheOutputFile(array $call, string $extension): void
    {
        copy(self::FILES . "sync/own_reference_en.srt", "$this->dir/ref.srt");
        $before = $this->snapshot();

        [$code, $expected] = $this->runWrite($call, ["trip.srt"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("The train leaves at noon.", $expected);
        $this->assertSame([0, $expected], array_slice($this->runWrite($call, ["trip.srt"], "-o", "-"), 0, 2));
        $this->assertSame([0, $expected], array_slice($this->runCli([$call[0], "-", ...array_slice($call, 1)], $this->file("trip.srt")), 0, 2));
        $this->assertSame($before, $this->snapshot());

        $this->assertSame([0, "trip.srt -> one.$extension\n"], array_slice($this->runWrite($call, ["trip.srt"], "-o", "one.$extension"), 0, 2));
        $this->assertSame($expected, $this->file("one.$extension"));
        $this->assertSame(0, $this->runCli([$call[0], "-", ...array_slice($call, 1), "-o", "new/stdin.$extension"], $this->file("trip.srt"))[0]);
        $this->assertSame($expected, $this->file("new/stdin.$extension"));
    }


    /**
     * @param list<string> $call
     */
    #[DataProvider("writeCommands")]
    public function testSeveralInputsGoIntoTheOutputDirectory(array $call, string $extension): void
    {
        copy(self::FILES . "sync/own_reference_en.srt", "$this->dir/ref.srt");
        $trip = $this->runWrite($call, ["trip.srt"])[1];
        $shop = $this->runWrite($call, ["shop.vtt"])[1];

        $this->assertSame(
            [0, "trip.srt -> out/trip.$extension\nshop.vtt -> out/shop.vtt\n2 files: 2 succeeded, 0 failed.\n"],
            array_slice($this->runWrite($call, ["trip.srt", "shop.vtt"], "--output-dir", "out"), 0, 2)
        );
        $this->assertSame($trip, $this->file("out/trip.$extension"));
        $this->assertSame($shop, $this->file("out/shop.vtt"));
        $this->assertCount(2, glob("$this->dir/out/*"));
    }


    /**
     * @param list<string> $call
     */
    #[DataProvider("writeCommands")]
    public function testAnOutputThatCannotBeCreatedFailsBeforeAnyWrite(array $call, string $extension): void
    {
        copy(self::FILES . "sync/own_reference_en.srt", "$this->dir/ref.srt");
        mkdir("$this->dir/a");
        mkdir("$this->dir/b");
        mkdir("$this->dir/taken");
        copy("$this->dir/trip.srt", "$this->dir/a/trip.srt");
        copy("$this->dir/trip.srt", "$this->dir/b/trip.srt");
        file_put_contents("$this->dir/taken.$extension", "old");
        file_put_contents("$this->dir/taken/shop.vtt", "old");
        $before = $this->snapshot();
        $usage  = "\nRun \"subtitle-toolbox help $call[0]\" for the usage.\n";
        $exists = "exists. The tool never overwrites a file. Remove it, or pass another output file or directory.$usage";
        $reads  = "is a file that the command reads. Pass another output file or directory.$usage";

        $cases = [
            "Error: 2 input files need --output-dir DIR. One input file goes to standard output or to -o FILE.$usage" => [["trip.srt", "shop.vtt"]],
            "Error: --output takes one input file, got 2. Pass --output-dir DIR for several files.$usage" => [["trip.srt", "shop.vtt"], "-o", "x.$extension"],
            "Error: Standard input has no file name for --output-dir. Pass -o FILE.$usage"                 => [["-"], "--output-dir", "out"],
            "Error: The output taken.$extension $exists"                                                   => [["trip.srt"], "-o", "taken.$extension"],
            "Error: The output taken/shop.vtt $exists"                                                     => [["trip.srt", "shop.vtt"], "--output-dir", "taken"],
            "Error: a/trip.srt and b/trip.srt would both write out/trip.$extension. Pass them in two runs.$usage" => [["a/trip.srt", "b/trip.srt"], "--output-dir", "out"],
            "Error: The output shop.vtt $reads"                                                            => [["shop.vtt"], "-o", "shop.vtt"],
            "Error: The output ./shop.vtt $reads"                                                          => [["shop.vtt"], "--output-dir", "."],
        ];
        if ($call[0] === "sync") {
            $cases["Error: The output ref.srt $reads"] = [["trip.srt"], "-o", "ref.srt"];
        }
        foreach ($cases as $error => $case) {
            $this->assertSame([2, "", $error], $this->runWrite($call, $case[0], ...array_slice($case, 1)), $error);
            $this->assertSame($before, $this->snapshot(), $error);
        }
    }
}
