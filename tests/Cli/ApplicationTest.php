<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
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
            self::usageError("convert", "Pass --from-fps and --to-fps together."),
            self::runApplication(["convert", "-", "--from-fps", "25"])
        );
        $this->assertSame(
            self::usageError("convert", "Pass --shift with --shift-after."),
            self::runApplication(["convert", "-", "--scale", "2", "--shift-after", "1"])
        );
    }


    public function testRemovedCommandsAreUnknownAndNotInTheHelp(): void
    {
        foreach (["retime", "shift", "scale", "fps", "sync-fps", "fix", "strip-sdh", "snap"] as $command) {
            $this->assertSame(
                [2, "", "Error: Unknown command \"$command\".\nRun \"subtitle-toolbox help\" for the usage.\n"],
                self::runApplication([$command, "-", "--by", "1"])
            );
            $this->assertDoesNotMatchRegularExpression("/^  $command +\\S/m", self::runApplication(["--help"])[1]);
        }
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


    #[DataProvider("removedChapterAliases")]
    public function testTheOneXChapterAliasesAreUnknownFormats(string $alias): void
    {
        $input = __DIR__ . "/../files/chapters/ogm/real/mkvextract_simple.txt";
        foreach ([["--from", $alias], ["--from", "ogm-chapters", "--to", $alias]] as $options) {
            [$code, $stdout, $stderr] = self::runApplication(["convert", $input, ...$options]);

            $this->assertSame([2, ""], [$code, $stdout]);
            $this->assertStringContainsString("Unknown format \"$alias\". Run \"subtitle-toolbox formats\" for the list.", $stderr);
        }
    }


    /**
     * @return array<string, array{string}>
     */
    public static function removedChapterAliases(): array
    {
        return ["ytchapter" => ["ytchapter"], "podcast" => ["podcast"], "ogm" => ["ogm"]];
    }
}
