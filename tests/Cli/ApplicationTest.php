<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\Tests\Support\CountingFileStream;
use SubtitleToolbox\Tests\Support\RunsApplication;

class ApplicationTest extends TestCase
{
    use RunsApplication;

    /**
     * Each option whose name holds a no- word, with what it turns off.
     */
    private const OFF_SWITCHES = [
        "no-bom"        => "the UTF-8 BOM of the output format",
        "no-scale"      => "the scale search of sync",
        "no-snap-chain" => "the closing of gaps between cues",
    ];


    public function testOptionNamesFollowTheNamingRules(): void
    {
        $commands = (new \ReflectionProperty(Application::class, "commands"))->getValue(new Application());
        $names    = [];
        foreach ($commands as $command) {
            foreach ($command->options() as $option) {
                $where   = $command->name() . " --$option->name";
                $names[] = $option->name;
                if (preg_match('/(^|-)no-/', $option->name) === 1) {
                    $this->assertArrayHasKey($option->name, self::OFF_SWITCHES, "$where does not turn something off.");
                    $this->assertFalse($option->takesValue(), "$where takes a value.");
                    $this->assertStringStartsNotWith("Report", $option->description, "$where reports something.");
                }
                if (str_ends_with($option->name, "-frames") || $option->valueName === "FRAMES") {
                    $this->assertSame("FRAMES", $option->valueName, "$where must count frames.");
                    $this->assertStringEndsWith("-frames", $option->name, "$where counts frames.");
                }
                if ($option->valueName === "SECONDS") {
                    $this->assertStringEndsNotWith("-frames", $option->name, "$where is in seconds.");
                }
            }
        }
        $this->assertContains("check-overlaps", $names);
        $this->assertContains("snap-window-frames", $names);
        $this->assertEqualsCanonicalizing(array_keys(self::OFF_SWITCHES), array_values(array_unique(preg_grep('/(^|-)no-/', $names))));
    }


    public function testConvertsStandardInput(): void
    {
        $srt = file_get_contents(__DIR__ . "/../files/cli/trip.srt");

        $this->assertSame([0, Subtitle::fromStringAutoDetectFormat($srt)->toString(Format::WebVtt), ""], self::runApplication(["convert", "-", "--to", "vtt"], $srt));
    }


    public function testFormatOptionsAddNoReadOfTheInput(): void
    {
        $rows = array_slice(file(__DIR__ . "/../files/csv/own_frame_times.csv"), 1);
        $csv  = tempnam(sys_get_temp_dir(), "counting-") . ".csv";
        file_put_contents($csv, "Start,End,Text\n" . str_repeat(implode("", $rows), 20000));
        $srt      = __DIR__ . "/../files/cli/trip.srt";
        $expected = Subtitle::fromString((string) file_get_contents($csv), Format::Csv, new ReadOptions(formats: [new CsvReadOptions(frameRate: 25)]));

        CountingFileStream::register();
        try {
            $this->assertSame(0, self::runApplication(["convert", CountingFileStream::SCHEME . "://$srt", "--to", "vtt", "-o", "-"])[0]);
            $opensWithoutFormatOptions = CountingFileStream::$opens;

            CountingFileStream::reset();
            $this->assertSame([0, $expected->toString(Format::SubRip), ""],
                              self::runApplication(["convert", CountingFileStream::SCHEME . "://$csv", "--to", "srt", "-o", "-", "--input-fps", "25"]));
            $this->assertSame($opensWithoutFormatOptions, CountingFileStream::$opens);
            $this->assertLessThan(2 * filesize($csv), CountingFileStream::$bytesRead);

            CountingFileStream::reset();
            $this->assertSame(0, self::runApplication(["convert", CountingFileStream::SCHEME . "://$srt", "--to", "vtt", "-o", "-", "--word-timestamps"])[0]);
            $this->assertSame($opensWithoutFormatOptions, CountingFileStream::$opens);
        } finally {
            CountingFileStream::unregister();
            unlink($csv);
            unlink(substr($csv, 0, -4));
        }
    }


    public function testInfoOfStandardInput(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["info", "-", "--json"], file_get_contents(__DIR__ . "/../files/cli/shop.vtt"));

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertSame(["file" => "-", "format" => "vtt"], array_slice(json_decode($stdout, true)[0], 0, 2));
    }


    public function testInfoPrintsADashForNumbersWithoutData(): void
    {
        [$code, $stdout] = self::runApplication(["info", "-"], "1\n00:00:01,000 --> 00:00:03,000\n<i> </i>\n");

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Span:                  2 s\n", $stdout);
        $this->assertStringContainsString("  Characters per second: -\n", $stdout);
        $this->assertStringContainsString("  Gaps:                  -\n", $stdout);
    }


    public function testUsageErrorsExitWith2(): void
    {
        $this->assertSame(
            self::usageError("retime", "Pass --shift SECONDS, --scale FACTOR, --sync OLD=NEW, or --from-fps RATE and --to-fps RATE."),
            self::runApplication(["retime", "-"])
        );
        $this->assertSame(
            self::usageError("retime", "Pass --from-fps and --to-fps together."),
            self::runApplication(["retime", "-", "--from-fps", "25"])
        );
        $this->assertSame(
            self::usageError("retime", "Pass --shift with --shift-after."),
            self::runApplication(["retime", "-", "--scale", "2", "--shift-after", "1"])
        );
    }


    public function testRemovedCommandsAreUnknownAndNotInTheHelp(): void
    {
        foreach (["shift", "scale", "fps", "sync-fps", "fix", "strip-sdh", "snap"] as $command) {
            $this->assertSame(
                [2, "", "Error: Unknown command \"$command\".\nRun \"subtitle-toolbox help\" for the usage.\n"],
                self::runApplication([$command, "-", "--by", "1"])
            );
            $this->assertDoesNotMatchRegularExpression("/^  $command +\\S/m", self::runApplication(["--help"])[1]);
        }
    }


    public function testConvertHelpListsTheOptionGroupsInTheRunOrder(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["convert", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Usage: subtitle-toolbox convert <input> [--to FORMAT] [-o FILE] [options]\n" .
                                      "       subtitle-toolbox convert <input>... [--to FORMAT] --output-dir DIR\n" .
                                      "                                [options]\n", $stdout);
        $this->assertMatchesRegularExpression('/^  --encoding NAME +/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --language CODE +/m', $stdout);
        $this->assertDoesNotMatchRegularExpression('/^  --(ocr|sdh|structure-wrap|shift|karaoke)\b/m', $stdout);
        preg_match_all('/^  ([a-z]+) {2,}[A-Z]/m', $stdout, $groups);
        $this->assertSame(["forced", "ocr", "errors", "sdh", "replace", "text", "structure", "retime", "snap", "timing", "masking", "karaoke", "ass"],
                          $groups[1]);
        $this->assertSame([0, $stdout, ""], self::runApplication(["help", "convert"]));
    }


    public function testConvertHelpOfOneGroupListsItsOptions(): void
    {
        $sdh = "sdh: Remove hearing-impaired annotations.\n" .
               "  --sdh                       Remove hearing-impaired annotations such as [DOOR\n" .
               "                              SLAMS], (laughs) and JOHN:. Remove a cue with no\n" .
               "                              text left.\n";

        $this->assertStringStartsWith($sdh, self::runApplication(["convert", "--help", "sdh"])[1]);
        $this->assertSame(self::runApplication(["convert", "--help", "sdh"]), self::runApplication(["convert", "-h", "sdh"]));
        $this->assertSame(self::runApplication(["convert", "--help", "sdh"]), self::runApplication(["help", "convert", "sdh"]));
        $this->assertSame(9, substr_count(CliTestCase::unwrapHelp(self::runApplication(["convert", "--help", "sdh"])[1]), "\n"));
    }


    public function testConvertHelpAllListsEachOptionOnceUnderItsGroup(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["convert", "--help", "all"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith(strstr(self::runApplication(["convert", "--help"])[1], "\nOption groups", true), $stdout);
        $this->assertStringContainsString("\nretime: Shift and scale the times, or change the frame rate.\n  --shift SECONDS ", $stdout);
        foreach ((new ConvertCommand())->options() as $option) {
            $this->assertSame(1, preg_match_all('/^  ' . preg_quote($option->synopsis(), "/") . ' /m', $stdout), "--$option->name");
        }
    }


    public function testConvertHelpBeforeAFileArgumentPrintsTheConvertHelp(): void
    {
        $help = self::runApplication(["convert", "--help"]);
        $cwd  = getcwd();
        chdir(__DIR__ . "/../..");
        try {
            $this->assertSame([0, ""], [$help[0], $help[2]]);
            $this->assertSame($help, self::runApplication(["convert", "in.srt", "-h", "out.srt"]));
            $this->assertSame($help, self::runApplication(["convert", "-h", "season1/movie"]));
            $this->assertSame($help, self::runApplication(["convert", "-h", "LICENSE"]));
            $this->assertSame($help, self::runApplication(["help", "convert", "out.srt"]));
            $this->assertSame(self::runApplication(["convert", "--help", "text"]), self::runApplication(["convert", "in.srt", "-h", "text"]));
            $this->assertSame(self::usageError("convert", "Unknown option group \"out\". The groups are forced, ocr, errors, sdh, replace, text, structure, " .
                                                          "retime, snap, timing, masking, karaoke, ass, and all for every option."),
                              self::runApplication(["convert", "in.srt", "-h", "out"]));
        } finally {
            chdir($cwd);
        }
    }


    public function testConvertHelpOfAnUnknownGroupListsTheGroups(): void
    {
        $expected = self::usageError("convert", "Unknown option group \"timings\". The groups are forced, ocr, errors, sdh, replace, text, structure, " .
                                                "retime, snap, timing, masking, karaoke, ass, and all for every option.");

        $this->assertSame($expected, self::runApplication(["convert", "--help", "timings"]));
        $this->assertSame($expected, self::runApplication(["help", "convert", "timings"]));
    }


    public function testRetimeHelpMatchesItsOptions(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["retime", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith(
            "Usage: subtitle-toolbox retime <input>... [--shift SECONDS] [--scale FACTOR]\n" .
            "                               [--sync OLD=NEW] [--from-fps RATE --to-fps RATE]\n" .
            "                               [options]\n\n" .
            "Shift and scale all cue times, or fit them to a video with another frame rate.\n\n" .
            "Pass one or more edits. retime applies them in this order: --sync, --shift,\n--scale, --from-fps and --to-fps.",
            $stdout
        );
        $stdout = CliTestCase::unwrapHelp($stdout);
        preg_match_all('/^  (?:-\w, )?--([\w-]+)/m', $stdout, $matches);
        $this->assertSame([
            "shift", "shift-after", "shift-before", "scale", "sync", "from-fps", "to-fps", "to", "output", "output-dir", "output-fps",
            "line-ending", "bom", "no-bom", "skip-image-cues", "scc-fit", "from", "encoding", "lenient", "input-fps", "fps", "word-timestamps", "scc-roll-up",
            "track", "keep-going", "help",
        ], $matches[1]);
        $this->assertMatchesRegularExpression('/^  --shift-after SECONDS +Shift only the cues that start at this time or later\.$/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --from-fps RATE +Frame rate of the video that the subtitle fits now\. Needs --to-fps\.$/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps and --output-fps\. A specific option wins over --fps\.$/m', $stdout);
        $this->assertSame(array_map(fn (Option $option): string => $option->name, (new RetimeCommand())->options()), array_slice($matches[1], 0, -1));
    }


    public function testEveryHelpPageFitsIn80ColumnsWithoutTrailingSpaces(): void
    {
        [, $help] = self::runApplication(["help"]);
        preg_match_all('/^  ([a-z]+) {2,}\S/m', strstr(strstr($help, "Commands:\n"), "\n\n", true), $commands);
        $this->assertCount(11, $commands[1]);

        $pages = ["help" => $help];
        foreach ($commands[1] as $command) {
            $pages["help $command"] = self::runApplication(["help", $command])[1];
        }
        $pages["convert --help all"] = self::runApplication(["convert", "--help", "all"])[1];

        foreach ($pages as $page => $text) {
            $this->assertNotSame("", $text, $page);
            foreach (explode("\n", $text) as $line) {
                $this->assertLessThanOrEqual(80, mb_strlen($line), "$page: $line");
                $this->assertSame(rtrim($line), $line, "$page: trailing space");
            }
        }
    }


    public function testHelpDescribesTheDiffExitCodeAndJson(): void
    {
        $this->assertStringContainsString("\nExit codes:\n" .
                                          "  0  Success.\n" .
                                          "  1  A file broke a validation rule, or diff found a difference.\n" .
                                          "  2  Invalid arguments.\n" .
                                          "  3  A file could not be read or written.\n\n" .
                                          "Options:\n" .
                                          "  -h, --help     Show this help.\n" .
                                          "  -V, --version  Print the version.\n",
                                          self::runApplication(["--help"])[1]);
        $this->assertMatchesRegularExpression('/^  --json +Print the differences as JSON: a list with one object for the pair of files\.$/m', CliTestCase::unwrapHelp(self::runApplication(["diff", "--help"])[1]));
        $this->assertMatchesRegularExpression('/^  --json +Print JSON: a list with one object for each input file\.$/m',
                                              CliTestCase::unwrapHelp(self::runApplication(["info", "--help"])[1]));
    }


    public function testFileErrorsExitWith3(): void
    {
        $this->assertSame([3, "", "stdin: UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                                  "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n"],
                          self::runApplication(["info", "-"], "hello"));
        $this->assertSame([3, "", "stdin: VobSub needs the path of the .idx file. Standard input does not work.\n"],
                          self::runApplication(["info", "-", "--from", "vobsub"], "hello"));
    }


    public function testChaptersAndCloudSpeechJsonNeedFrom(): void
    {
        $chapters = __DIR__ . "/../files/chapters/ffmetadata/real/m4b_audiobook.ffmeta";
        $deepgram = file_get_contents(__DIR__ . "/../files/deepgram/real/pool_utterances_diarize.json");

        $unknown = "UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                   "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n";

        $this->assertSame([3, "", "$chapters: $unknown"], self::runApplication(["info", $chapters]));
        $this->assertSame([3, "", "stdin: $unknown"], self::runApplication(["info", "-"], $deepgram));

        [$code, $stdout] = self::runApplication(["info", $chapters, "--from", "ffmeta-chapters"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("ffmeta-chapters", $stdout);

        [$code, $stdout] = self::runApplication(["info", "-", "--from", "deepgram"], $deepgram);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("deepgram", $stdout);
    }


    public function testOneXChapterFormatNamesStillWorkAsFromAndTo(): void
    {
        $files = __DIR__ . "/../files/chapters/";
        foreach ([
            "ytchapter" => ["youtube/real/video_description.txt", Format::YouTubeChapters],
            "podcast"   => ["podcast/real/spec_basic_example.json", Format::PodcastChapters],
            "ogm"       => ["ogm/real/mkvextract_simple.txt", Format::OgmChapters],
            "ffmeta"    => ["ffmetadata/real/m4b_audiobook.ffmeta", Format::FfMetadataChapters],
        ] as $alias => [$file, $format]) {
            $expected = Subtitle::load($files . $file, $format)->toString($format);
            $this->assertSame([0, $expected, ""], self::runApplication(["convert", $files . $file, "--from", $alias, "--to", $alias]), $alias);
        }

        $this->assertNull(Format::tryFrom("ytchapter"));
    }
}
