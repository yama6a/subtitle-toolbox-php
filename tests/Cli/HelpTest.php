<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Ocr\TesseractOcrOptions;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Tests\Support\CliTestCase;
use SubtitleToolbox\Tests\Support\RunsApplication;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\WriteOptions;

/**
 * Reads the help pages: the command list, the options of each command, their defaults and the layout.
 */
class HelpTest extends TestCase
{
    use RunsApplication;

    /** Option => the library parameter whose default the CLI uses when the option is absent: class, method, parameter. */
    private const LIBRARY_DEFAULTS = [
        "time-tolerance"           => [SubtitleDiffOptions::class, "__construct", "timeTolerance"],
        "mode"                     => [DualSubtitleOptions::class, "__construct", "mode"],
        "secondary-style"          => [DualSubtitleOptions::class, "__construct", "secondaryStyle"],
        "secondary-alignment"      => [DualSubtitleOptions::class, "__construct", "secondaryAlignment"],
        "snap-tolerance"           => [DualSubtitleOptions::class, "__construct", "timeTolerance"],
        "segment"                  => [HlsSegmentOptions::class, "__construct", "segmentDuration"],
        "pattern"                  => [HlsSegmentOptions::class, "__construct", "fileNamePattern"],
        "mpegts"                   => [HlsSegmentOptions::class, "__construct", "mpegts"],
        "local"                    => [HlsSegmentOptions::class, "__construct", "local"],
        "min-offset"               => [ReferenceSyncOptions::class, "__construct", "minOffset"],
        "max-offset"               => [ReferenceSyncOptions::class, "__construct", "maxOffset"],
        "max-splits"               => [ReferenceSyncOptions::class, "__construct", "maxSplits"],
        "split-penalty"            => [ReferenceSyncOptions::class, "__construct", "splitPenalty"],
        "structure-max-word-gap"   => [ResegmentOptions::class, "__construct", "maxWordGap"],
        "structure-max-cpl"        => [CueLimits::class, "__construct", "maxCharactersPerLine"],
        "structure-max-lines"      => [CueLimits::class, "__construct", "maxLinesPerCue"],
        "snap-min-gap-frames"      => [ShotChangeOptions::class, "__construct", "minGapFrames"],
        "snap-min-duration-frames" => [ShotChangeOptions::class, "__construct", "minDurationFrames"],
        "timing-min-gap"           => [Subtitle::class, "fixOverlaps", "minGap"],
        "mask"                     => [ProfanityOptions::class, "__construct", "mask"],
        "mute-padding"             => [ProfanityOptions::class, "__construct", "padding"],
        "karaoke-style"            => [WordHighlightOptions::class, "__construct", "style"],
        "ass-karaoke-tag"          => [AssWriteOptions::class, "__construct", "karaokeTag"],
        "line-ending"              => [WriteOptions::class, "__construct", "lineEnding"],
        "ocr-language"             => [TesseractOcrOptions::class, "__construct", "language"],
        "scc-roll-up"              => [SccReadOptions::class, "__construct", "rollUp"],
    ];

    /** Option => a default that only the CLI has. */
    private const CLI_DEFAULTS = [
        "playlist"  => HlsCommand::DEFAULT_PLAYLIST,
        "video-fps" => ValidateCommand::DEFAULT_FPS,
    ];

    /** Defaults that the help describes in words. */
    private const DESCRIBED_DEFAULTS = [
        "the format detected from the content", "as for --from",
        "UTF-8", "stop at the first failure", "the input format", "the format of the primary file",
        "the frame rate of a MicroDVD, iTT or MCC input", "the BOM rule of the output format", "the end of the last cue",
        "half the --video-fps, rounded to the nearest frame", "tesseract when it is installed", "the subtitle fonts database of php-glyph-ocr",
        "the environment variable of the engine", "the engine detects it",
    ];


    /**
     * Returns the commands that the help lists, without help itself.
     *
     * @return list<string>
     */
    private static function commandNames(): array
    {
        preg_match_all('/^  ([a-z]+) {2,}\S/m', strstr(strstr(self::runApplication(["help"])[1], "Commands:\n"), "\n\n", true), $commands);

        return array_values(array_diff($commands[1], ["help"]));
    }


    public function testTheHelpListsEveryCommand(): void
    {
        $this->assertSame(["convert", "info", "validate", "sync", "diff", "translate", "dual", "hls", "formats"], self::commandNames());
    }


    public function testEachHelpDefaultIsTheDefaultThatTheCommandUses(): void
    {
        $found = [];
        foreach (self::commandNames() as $command) {
            $help = CliTestCase::unwrapHelp(self::runApplication([$command, "--help", ...($command === "convert" ? ["all"] : [])])[1]);
            preg_match_all('/^  (?:-\w, )?--([\w-]+)\N*? Default: (.+?)\.(?: |$)/m', $help, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $option, $default]) {
                $found[$option] = true;
                if (isset(self::LIBRARY_DEFAULTS[$option])) {
                    [$class, $method, $parameter] = self::LIBRARY_DEFAULTS[$option];
                    $this->assertSame(self::helpText(self::parameterDefault($class, $method, $parameter)), $default, "$command --$option");
                } elseif (isset(self::CLI_DEFAULTS[$option])) {
                    $this->assertSame(self::helpText(self::CLI_DEFAULTS[$option]), $default, "$command --$option");
                } else {
                    $this->assertContains($default, self::DESCRIBED_DEFAULTS, "$command --$option");
                }
            }
        }

        $this->assertSame([], array_diff(array_keys([...self::LIBRARY_DEFAULTS, ...self::CLI_DEFAULTS]), array_keys($found)));
    }


    /**
     * @param class-string $class
     */
    private static function parameterDefault(string $class, string $method, string $parameter): mixed
    {
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $reflection) {
            if ($reflection->getName() === $parameter) {
                return $reflection->getDefaultValue();
            }
        }

        throw new \LogicException("$class::$method() has no parameter $parameter.");
    }


    /**
     * Returns a default as the help writes it. An enum case is its value, or its name in lower case for an enum whose
     * value the user cannot type, such as LineEnding::Lf.
     */
    private static function helpText(mixed $value): string
    {
        return match (true) {
            $value === null           => "none",
            $value instanceof \BackedEnum => ctype_print((string)$value->value) && $value->value !== "" ? (string)$value->value : strtolower($value->name),
            is_int($value), is_float($value) => Command::number($value),
            default                   => (string)$value,
        };
    }


    public function testWithoutArgumentsPrintsTheHelp(): void
    {
        [$code, $stdout, $stderr] = self::runApplication([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Usage: stbox <command>", $stdout);
        foreach ([...self::commandNames(), "help"] as $command) {
            $this->assertMatchesRegularExpression("/^  $command +\S/m", $stdout);
        }
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], self::runApplication(["--help"]));
        $this->assertSame([0, $stdout, ""], self::runApplication(["help"]));
        $this->assertSame([0, $stdout, ""], self::runApplication(["help", "help"]));
    }


    /**
     * @return array<string, array{string}>
     */
    public static function commands(): array
    {
        return array_combine(self::commandNames(), array_map(fn (string $command): array => [$command], self::commandNames()));
    }


    #[DataProvider("commands")]
    public function testEveryCommandHasHelp(string $command): void
    {
        [$code, $stdout, $stderr] = self::runApplication([$command, "--help"]);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("Usage: stbox ", $stdout);
        $this->assertStringContainsString("  -h, --help ", $stdout);
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], self::runApplication(["help", $command]));
        $this->assertSame([0, $stdout, ""], self::runApplication([$command, "-h"]));
    }


    /**
     * @return array<string, array{list<string>, string}> the help arguments and a pattern for the line of one option
     */
    public static function optionDescriptions(): array
    {
        return [
            "convert --lenient"       => [["convert"], '/^  --lenient +Skip or repair broken cues and print a warning for each\. SCC, PGS, VobSub and chapter input ignore it\.$/m'],
            "convert --encoding"      => [["convert"], '/^  --encoding NAME +.*It replaces code page detection\. A BOM, valid UTF-8 and UTF-16 input win over it\.$/m'],
            "convert --input-fps"     => [["convert"], '/^  --input-fps RATE +Frame rate of a MicroDVD input without a \{1\}\{1\}<fps> first line, and of CSV or TSV times in hh:mm:ss:ff\.$/m'],
            "convert --output-fps"    => [["convert"], '/^  --output-fps RATE +Frame rate of MicroDVD, iTT and MCC output\..*$/m'],
            "convert --fps"           => [["convert"], '/^  --fps RATE +Sets --input-fps, --output-fps and --video-fps\. A specific option wins over --fps\.$/m'],
            "convert --from"          => [["convert"], '/^  --from FORMAT +Input format\./m'],
            "convert --to"            => [["convert"], '/^  --to FORMAT +Output format\./m'],
            "structure --split-long"  => [["convert", "structure"], '/^  --structure-split-long +.*at sentence ends, clause ends or spaces\.$/m'],
            "structure --merge-short" => [["convert", "structure"], '/^  --structure-merge-short +.*at most 0\.25 s away.*$/m'],
            "info --fps"              => [["info"], '/^  --fps RATE +Same as --input-fps\.$/m'],
            "validate --video-fps"    => [["validate"], '/^  --video-fps RATE +Frame rate of the video, for the 2-frame gap of netflix-en\. Default: 23\.976\.$/m'],
            "validate --fps"          => [["validate"], '/^  --fps RATE +Sets --input-fps and --video-fps\. A specific option wins over --fps\.$/m'],
        ];
    }


    /**
     * @param list<string> $help
     */
    #[DataProvider("optionDescriptions")]
    public function testHelpDescribesWhatTheOptionDoes(array $help, string $pattern): void
    {
        [$command, $topic] = [$help[0], $help[1] ?? null];

        $this->assertMatchesRegularExpression($pattern, CliTestCase::unwrapHelp(self::runApplication([$command, "--help", ...($topic === null ? [] : [$topic])])[1]));
    }


    public function testHelpListsNoOptionThatTheCommandLacks(): void
    {
        $this->assertStringNotContainsString("SubRip, WebVTT and SBV", self::runApplication(["convert", "--help"])[1]);
        $this->assertDoesNotMatchRegularExpression('/--output-fps|--video-fps/', self::runApplication(["info", "--help"])[1]);
        $this->assertDoesNotMatchRegularExpression('/--output-fps/', self::runApplication(["validate", "--help"])[1]);
    }


    public function testConvertHelpListsTheOptionGroupsInTheRunOrder(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["convert", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Usage: stbox convert <input> [--to FORMAT] [-o FILE] [options]\n" .
                                      "       stbox convert <input>... [--to FORMAT] --output-dir DIR [options]\n", $stdout);
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


    public function testEveryHelpPageFitsIn80ColumnsWithoutTrailingSpaces(): void
    {
        $pages = ["help" => self::runApplication(["help"])[1]];
        foreach ([...self::commandNames(), "help"] as $command) {
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
}
