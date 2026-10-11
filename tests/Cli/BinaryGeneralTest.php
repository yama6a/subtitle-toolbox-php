<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Format;
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
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\WriteOptions;

/**
 * Runs the commands that every run shares: help, version, usage errors, exit codes and the formats list.
 */
class BinaryGeneralTest extends CliTestCase
{
    private const COMMANDS = ["convert", "retime", "info", "validate", "sync", "diff", "dual", "hls", "formats"];

    /** Option => the library parameter whose default the CLI uses when the option is absent: class, method, parameter. */
    private const LIBRARY_DEFAULTS = [
        "time-tolerance"           => [SubtitleDiffOptions::class, "__construct", "timeTolerance"],
        "mode"                     => [DualSubtitleOptions::class, "__construct", "mode"],
        "secondary-style"          => [DualSubtitleOptions::class, "__construct", "secondaryStyle"],
        "secondary-alignment"      => [DualSubtitleOptions::class, "__construct", "secondaryAlignment"],
        "snap-tolerance"           => [DualSubtitleOptions::class, "__construct", "snapTolerance"],
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
        "the format detected from the content", "as for --from", "as for --primary-from",
        "UTF-8", "stop at the first failure", "the input format", "the format of the primary file",
        "the frame rate of a MicroDVD or iTT input", "the BOM rule of the output format", "the end of the last cue",
        "half the --video-fps, rounded to the nearest frame", "tesseract when it is installed", "the subtitle fonts database of php-glyph-ocr",
        "the environment variable of the engine", "the engine detects it",
    ];


    public function testPhpWarningGoesToStandardErrorAndKeepsTheOutputClean(): void
    {
        file_put_contents(
            "$this->dir/warn.php",
            "<?php register_shutdown_function(static fn () => trigger_error(\"test warning 266\", E_USER_WARNING));\n"
        );
        $prepend = ["-d", "auto_prepend_file=$this->dir/warn.php", "-d", "log_errors=0"];
        $arguments = ["convert", "trip.srt", "--to", "vtt", "-o", "-"];

        [$code, $stdout, $stderr] = $this->runBinary($arguments, "", "-d", "display_errors=1", ...$prepend);
        $this->assertSame([0, $this->tripAs(Format::WebVtt)], [$code, $stdout]);
        $this->assertStringContainsString("test warning 266", $stderr);

        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], $this->runBinary($arguments, "", "-d", "display_errors=0", ...$prepend));
    }


    public function testEachHelpDefaultIsTheDefaultThatTheCommandUses(): void
    {
        $found = [];
        foreach (self::COMMANDS as $command) {
            $help = self::unwrapHelp($this->runCli([$command, "--help", ...($command === "convert" ? ["all"] : [])])[1]);
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


    public function testVersionPrintsTheVersionAlone(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["--version"]);

        $this->assertSame(0, $code);
        $this->assertSame(Version::get() . "\n", $stdout);
        $this->assertMatchesRegularExpression('/^(dev|\d+\.\d+\.\d+\S*)\n$/', $stdout);
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], $this->runCli(["-V"]));
    }


    public function testWithoutArgumentsPrintsTheHelp(): void
    {
        [$code, $stdout, $stderr] = $this->runCli([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Usage: subtitle-toolbox <command>", $stdout);
        foreach ([...self::COMMANDS, "help"] as $command) {
            $this->assertMatchesRegularExpression("/^  $command +\S/m", $stdout);
        }
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], $this->runCli(["--help"]));
        $this->assertSame([0, $stdout, ""], $this->runCli(["help"]));
        $this->assertSame([0, $stdout, ""], $this->runCli(["help", "help"]));
    }


    /**
     * @return array<string, array{string}>
     */
    public static function commands(): array
    {
        return array_combine(
            self::COMMANDS,
            array_map(fn (string $command): array => [$command], self::COMMANDS)
        );
    }


    #[DataProvider("commands")]
    public function testEveryCommandHasHelp(string $command): void
    {
        [$code, $stdout, $stderr] = $this->runCli([$command, "--help"]);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("Usage: subtitle-toolbox ", $stdout);
        $this->assertStringContainsString("  -h, --help ", $stdout);
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], $this->runCli(["help", $command]));
        $this->assertSame([0, $stdout, ""], $this->runCli([$command, "-h"]));
    }


    public function testHelpTextsDescribeWhatTheOptionsDo(): void
    {
        $convert = self::unwrapHelp($this->runCli(["convert", "--help"])[1]);
        $this->assertMatchesRegularExpression('/^  --lenient +Skip or repair broken cues and print a warning for each\. ' .
                                              'SCC, PGS, VobSub and chapter input ignore it\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --encoding NAME +.*It replaces code page detection\. A BOM, valid UTF-8 and UTF-16 input win over it\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --input-fps RATE +Frame rate of a MicroDVD input without a \{1\}\{1\}<fps> first line, and of CSV or TSV times in hh:mm:ss:ff\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --output-fps RATE +Frame rate of MicroDVD and iTT output\..*$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps, --output-fps and --video-fps\. A specific option wins over --fps\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --from FORMAT +Input format\./m', $convert);
        $this->assertMatchesRegularExpression('/^  --to FORMAT +Output format\./m', $convert);
        $this->assertStringNotContainsString("SubRip, WebVTT and SBV", $convert);

        $structure = self::unwrapHelp($this->runCli(["convert", "--help", "structure"])[1]);
        $this->assertMatchesRegularExpression('/^  --structure-split-long +.*at sentence ends, clause ends or spaces\.$/m', $structure);
        $this->assertMatchesRegularExpression('/^  --structure-merge-short +.*at most 0\.25 s away.*$/m', $structure);
        $info = self::unwrapHelp($this->runCli(["info", "--help"])[1]);
        $this->assertDoesNotMatchRegularExpression('/--output-fps|--video-fps/', $info);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Same as --input-fps\.$/m', $info);

        $validate = self::unwrapHelp($this->runCli(["validate", "--help"])[1]);
        $this->assertMatchesRegularExpression('/^  --video-fps RATE +Frame rate of the video, for the 2-frame gap of netflix-en\. Default: 23\.976\.$/m', $validate);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps and --video-fps\. A specific option wins over --fps\.$/m', $validate);
        $this->assertDoesNotMatchRegularExpression('/--output-fps/', $validate);
    }


    public function testUnknownCommandIsAUsageError(): void
    {
        $this->assertSame([2, "", "Error: Unknown command \"merge\".\nRun \"subtitle-toolbox help\" for the usage.\n"], $this->runCli(["merge"]));
        $this->assertSame([2, "", "Error: Unknown command \"merge\".\nRun \"subtitle-toolbox help\" for the usage.\n"], $this->runCli(["help", "merge"]));
    }


    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function usageErrors(): array
    {
        $convert = ["convert", "trip.srt", "--to", "srt", "-o", "-"];
        $dual    = ["dual", "--primary", "trip.srt", "--secondary", "shop.vtt"];
        $sync    = ["sync", "trip.srt", "--reference", "shop.vtt"];
        $fps     = "--video-fps sets the frame rate of the netflix-en gap rule. Pass --preset netflix-en, or leave out --video-fps.";

        return [
            "unknown option"      => [["convert", "trip.srt", "--to", "vtt", "--nope"], "convert", "Unknown option --nope."],
            "unknown format"      => [["convert", "trip.srt", "--to", "docx"], "convert", "Unknown format \"docx\". Run \"subtitle-toolbox formats\" for the list."],
            "read-only format"    => [["convert", "trip.srt", "--to", "vobsub"], "convert", "The format vobsub can be read but not written."],
            "write-only format"   => [["convert", "trip.srt", "--from", "txt", "--to", "vtt"], "convert", "The format txt can be written but not read."],
            "no input"            => [["convert", "--to", "vtt"], "convert", "Pass at least one input file, or - for standard input."],
            "line ending cr"      => [["convert", "trip.srt", "--to", "vtt", "--line-ending", "cr"], "convert", "The option --line-ending must be lf or crlf, got \"cr\"."],
            "bom and no bom"      => [["convert", "trip.srt", "--to", "vtt", "--bom", "--no-bom"], "convert", "Pass only one of --bom and --no-bom."],
            "output and output dir" => [["convert", "trip.srt", "--to", "vtt", "-o", "a.vtt", "--output-dir", "out"], "convert",
                                        "Pass only one of --output and --output-dir."],
            "video fps with bbc"  => [["validate", "trip.srt", "--preset", "bbc", "--video-fps", "25"], "validate", $fps],
            "video fps with rule" => [["validate", "trip.srt", "--max-cpl", "20", "--video-fps", "25"], "validate", $fps],
            "alignment 0"         => [[...$dual, "--secondary-alignment", "0"], "dual", "The option --secondary-alignment needs a whole number from 1 to 9, got \"0\"."],
            "alignment 10"        => [[...$dual, "--secondary-alignment", "10"], "dual", "The option --secondary-alignment needs a whole number from 1 to 9, got \"10\"."],
            "mode"                => [[...$dual, "--mode", "side"], "dual", "The option --mode must be stack or top-bottom, got \"side\"."],
            "snap tolerance"      => [[...$dual, "--snap-tolerance", "-1"], "dual", "The option --snap-tolerance must not be negative."],
            "max splits 11"       => [[...$sync, "--max-splits", "11"], "sync", "The option --max-splits needs a whole number from 0 to 10, got \"11\"."],
            "max splits fraction" => [[...$sync, "--max-splits", "1.5"], "sync", "The option --max-splits needs a whole number from 0 to 10, got \"1.5\"."],
            "mpegts negative"     => [["hls", "trip.srt", "--output-dir", "out", "--mpegts", "-1"], "hls", "The option --mpegts needs a whole number of 0 or more, got \"-1\"."],
            "snap frames"         => [[...$convert, "--video-fps", "25", "--snap-min-gap-frames", "-1"], "convert", "The option --snap-min-gap-frames needs a whole number of 0 or more, got \"-1\"."],
            "snap without rate"   => [[...$convert, "--snap-min-gap-frames", "2"], "convert", "Pass --video-fps RATE or --fps RATE with --snap-min-gap-frames."],
            "line ending"         => [[...$convert, "--line-ending", "cr"], "convert", "The option --line-ending must be lf or crlf, got \"cr\"."],
            "case"                => [[...$convert, "--case", "title"], "convert", "The option --case must be upper, lower or sentence, got \"title\"."],
            "mask"                => [[...$convert, "--mask-words", "notes.txt", "--mask", "bleep"], "convert", "The option --mask must be stars, first-letter, remove or none, got \"bleep\"."],
            "ass tag"             => [["convert", "trip.srt", "--to", "ass", "-o", "-", "--ass-karaoke-tag", "kx"], "convert", "The option --ass-karaoke-tag must be k, kf or ko, got \"kx\"."],
            "ass style"           => [["convert", "trip.srt", "--to", "ass", "-o", "-", "--ass-style", "Bogus=1"], "convert", "The option --ass-style is not valid. " .
                                      "The ASS style field \"Bogus\" does not exist. Use one of: Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, " .
                                      "BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, " .
                                      "MarginL, MarginR, MarginV, Encoding."],
            "preset"              => [["validate", "trip.srt", "--preset", "nope"], "validate", "The option --preset must be netflix-en or bbc, got \"nope\"."],
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


    public function testChoiceValuesIgnoreCase(): void
    {
        $pairs = [
            [["convert", "trip.srt", "--to", "srt", "-o", "-", "--line-ending", "crlf", "--case", "upper"],
             ["convert", "trip.srt", "--to", "srt", "-o", "-", "--line-ending", "CRLF", "--case", "Upper"]],
            [["dual", "--primary", "trip.srt", "--secondary", "shop.vtt", "--mode", "top-bottom"],
             ["dual", "--primary", "trip.srt", "--secondary", "shop.vtt", "--mode", "TOP-Bottom"]],
            [["validate", "trip.srt", "--preset", "bbc"], ["validate", "trip.srt", "--preset", "BBC"]],
        ];
        foreach ($pairs as [$lower, $mixed]) {
            $expected = $this->runCli($lower);
            $this->assertNotSame(2, $expected[0]);
            $this->assertSame($expected, $this->runCli($mixed));
        }
    }


    public function testValidateAndDiffResultsExitWith1(): void
    {
        $this->assertSame(1, $this->runCli(["validate", "trip.srt", "--max-cpl", "20"])[0]);
        $this->assertSame(1, $this->runCli(["diff", "trip.srt", "shop.vtt"])[0]);
        $this->assertSame(1, $this->runCli(["validate", "trip.srt", "--preset", "netflix-en", "--video-fps", "25"])[0]);
        $this->assertSame(1, $this->runCli(["validate", "trip.srt", "--preset", "bbc", "--fps", "25"])[0]);
    }


    public function testDirectoryWithoutSubtitlesIsAUsageError(): void
    {
        mkdir("$this->dir/empty");

        $this->assertSame(self::usageError("convert", "The directory empty holds no file with a known subtitle extension."),
                          $this->runCli(["convert", "empty", "--to", "vtt"]));
    }


    public function testFileErrorsExitWith3(): void
    {
        file_put_contents("$this->dir/blocker", "a file, not a directory");

        $this->assertSame([3, "", "missing.srt: The file does not exist.\n"], $this->runCli(["convert", "missing.srt", "--to", "vtt"]));
        $this->assertSame([3, "", "Error: Cannot create the directory " . realpath($this->dir) . "/blocker/out.\n"],
                          $this->runCli(["convert", "trip.srt", "--to", "vtt", "-o", "blocker/out/trip.vtt"]));
        $this->assertSame(3, $this->runCli(["validate", "trip.srt", "missing.srt", "--max-cpl", "20", "--keep-going"])[0]);
        $this->assertSame(3, $this->runCli(["diff", "trip.srt", "missing.srt"])[0]);
    }


    /**
     * @return array<string, array{list<string>, string, string}> arguments, file on standard input, start of standard error
     */
    public static function libraryMessagesInCliWords(): array
    {
        $tracks = "UnsupportedFormatException (Error #102): The MKV or WebM file has 6 subtitle tracks. %s\n" .
                  "  3: S_TEXT/UTF8, de, \"Deutsch (Forced)\", forced\n";
        $format = "UnknownFormatException (Error #106): Format detection found no subtitle format. %s\n";
        $frames = "The frame rate is unknown. Pass --fps or --input-fps, start the file with {1}{1}<fps>, or pass --lenient for 23.976 fps.\n";

        return [
            "track"            => [["convert", "movie.mkv", "--to", "srt", "-o", "-"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --track N with one of them:")],
            "track on stdin"   => [["convert", "-", "--from", "srt", "--to", "vtt", "-o", "-"], "movie.mkv",
                                   "stdin: UnsupportedFormatException (Error #102): The input is an MKV or WebM file. Pass --track N.\n"],
            "new track"       => [["diff", "trip.srt", "movie.mkv"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --new-track N with one of them:")],
            "reference track"  => [["sync", "trip.srt", "--reference", "movie.mkv"], "",
                                   "Error: movie.mkv: " . sprintf($tracks, "Write one of them to a subtitle file with convert --track N first:")],
            "from"             => [["convert", "call.json", "--to", "srt", "-o", "-"], "",
                                   "call.json: " . sprintf($format, "Pass --from FORMAT. Chapters and cloud speech-to-text JSON always need it, " .
                                                                    "for example --from deepgram.")],
            "new from"         => [["diff", "trip.srt", "call.json"], "",
                                   "call.json: " . sprintf($format, "Pass --new-from FORMAT. Chapters and cloud speech-to-text JSON " .
                                                                              "always need it, for example --new-from deepgram.")],
            "primary track"    => [["dual", "--primary", "movie.mkv", "--secondary", "trip.srt"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --primary-track N with one of them:")],
            "secondary track"  => [["dual", "--primary", "trip.srt", "--secondary", "movie.mkv"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --secondary-track N with one of them:")],
            "primary from"     => [["dual", "--primary", "call.json", "--secondary", "trip.srt"], "",
                                   "call.json: " . sprintf($format, "Pass --primary-from FORMAT. Chapters and cloud speech-to-text JSON " .
                                                                    "always need it, for example --primary-from deepgram.")],
            "secondary from"   => [["dual", "--primary", "trip.srt", "--secondary", "call.json"], "",
                                   "call.json: " . sprintf($format, "Pass --secondary-from FORMAT. Chapters and cloud speech-to-text " .
                                                                              "JSON always need it, for example --secondary-from deepgram.")],
            "reference format" => [["sync", "trip.srt", "--reference", "call.json"], "",
                                   "Error: call.json: " . sprintf($format, "Write it to a subtitle file with convert --from FORMAT first. " .
                                                                              "Chapters and cloud speech-to-text JSON always need --from, " .
                                                                              "for example --from deepgram.")],
            "microdvd output"  => [["convert", "trip.srt", "--to", "microdvd", "-o", "-"], "",
                                   "trip.srt: MicroDVD output needs the frame rate of the video. Pass --fps or --output-fps.\n"],
            "itt output"       => [["convert", "trip.srt", "--to", "itt", "-o", "-"], "",
                                   "trip.srt: iTT output needs the frame rate of the video. Pass --fps or --output-fps.\n"],
            "microdvd input"   => [["convert", "frames.sub", "--to", "srt", "-o", "-"], "", "frames.sub: ParsingException (Error #100): $frames"],
            "info microdvd"    => [["info", "frames.sub"], "", "frames.sub: ParsingException (Error #100): $frames"],
            "info from"        => [["info", "call.json"], "",
                                   "call.json: " . sprintf($format, "Pass --from FORMAT. Chapters and cloud speech-to-text JSON always need it, " .
                                                                    "for example --from deepgram.")],
            "csv frames"       => [["convert", "frames.csv", "--to", "srt", "-o", "-"], "",
                                   "frames.csv: ParsingException (Error #100): The time \"00:00:01:12\" counts frames. Pass --fps or --input-fps. (line 2)\n"],
            "scc line length"  => [["convert", "trip.srt", "--to", "scc", "-o", "-"], "",
                                   "trip.srt: Cue #1 at 2.5 s has a line with 57 characters, but SCC allows 32. Pass --structure-wrap --structure-max-cpl 32 --structure-max-lines 4.\n"],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("libraryMessagesInCliWords")]
    public function testLibraryMessagesNameCliOptions(array $arguments, string $stdin, string $stderr): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/call.json");
        copy(self::FILES . "csv/own_frame_times.csv", "$this->dir/frames.csv");

        [$code, $stdout, $actual] = $this->runCli($arguments, $stdin === "" ? "" : $this->file($stdin));

        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith($stderr, $actual);
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function sideFiles(): array
    {
        $convert  = ["convert", "trip.srt", "shop.vtt", "--to", "srt"];
        $sync     = ["sync", "trip.srt", "shop.vtt"];
        $parsing  = "ParsingException (Error #100): ";

        return [
            "reference missing"           => [[...$sync, "--reference", "missing.srt"], "missing.srt: The file does not exist.\n"],
            "reference broken"            => [[...$sync, "--reference", "bad.srt"], "bad.srt: $parsing"],
            "silence log missing"         => [[...$sync, "--silence-log", "missing.log", "--media-duration", "60"], "missing.log: The file does not exist.\n"],
            "silence log broken"          => [[...$sync, "--silence-log", "bad.log", "--media-duration", "60"], "bad.log: $parsing"],
            "word file missing"           => [[...$convert, "--mask-words", "missing.txt"], "missing.txt: The file does not exist.\n"],
            "replace list missing"        => [[...$convert, "--errors-fix", "--errors-replace-list", "missing.xml"], "missing.xml: The file does not exist.\n"],
            "replace list broken"         => [[...$convert, "--errors-fix", "--errors-replace-list", "bad.xml"], "bad.xml: $parsing"],
            "shot change file missing"    => [[...$convert, "--video-fps", "24", "--snap-shot-changes", "missing.txt"], "missing.txt: The file does not exist.\n"],
            "shot change file broken"     => [[...$convert, "--video-fps", "24", "--snap-shot-changes", "bad.txt"], "bad.txt: $parsing"],
            "glyph database missing"      => [[...$convert, "--ocr", "--ocr-database", "missing.nocr"], "missing.nocr: The file does not exist.\n"],
            "glyph database broken"       => [[...$convert, "--ocr", "--ocr-database", "bad.nocr"],
                                              "bad.nocr: Cannot read the glyph database - the data is not gzip-compressed!\n"],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("sideFiles")]
    public function testMissingOrBrokenSideFileStopsTheRunBeforeTheFirstInput(array $arguments, string $message): void
    {
        file_put_contents("$this->dir/bad.srt", "Not a subtitle.\n");
        file_put_contents("$this->dir/bad.log", "[silencedetect @ 0x1] channel: 0 | silence_start: 1.5\n");
        file_put_contents("$this->dir/bad.xml", "<OCRFixReplaceList><WholeWords>");
        file_put_contents("$this->dir/bad.txt", "Not a time.\n");
        file_put_contents("$this->dir/bad.nocr", "Not a glyph database.");

        [$code, $stdout, $stderr] = $this->runCli([...$arguments, "--output-dir", "out", "--keep-going"]);

        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith("Error: $message", $stderr);
        $this->assertSame(1, substr_count(str_replace(FileCommand::LENIENT_HINT . "\n", "", $stderr), "\n"), $stderr);
        $this->assertDirectoryDoesNotExist("$this->dir/out");
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function sideFileOptions(): array
    {
        $convert = ["convert", "trip.srt", "shop.vtt", "--to", "srt"];
        $sync    = ["sync", "trip.srt", "shop.vtt"];

        return [
            "--reference"           => [[...$sync, "--reference", "missing.srt"], "sync"],
            "--silence-log"         => [[...$sync, "--silence-log", "missing.log", "--media-duration", "60"], "sync"],
            "--mask-words"          => [[...$convert, "--mask-words", "missing.txt"], "convert"],
            "--errors-replace-list" => [[...$convert, "--errors-fix", "--errors-replace-list", "missing.xml"], "convert"],
            "--snap-shot-changes"   => [[...$convert, "--video-fps", "24", "--snap-shot-changes", "missing.txt"], "convert"],
            "--ocr-database"        => [[...$convert, "--ocr", "--ocr-database", "missing.nocr"], "convert"],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("sideFileOptions")]
    public function testUsageErrorComesBeforeAMissingSideFile(array $arguments, string $command): void
    {
        $this->assertSame(self::usageError($command, "2 input files need --output-dir DIR. One input file goes to standard output or to -o FILE."),
                          $this->runCli($arguments));
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function secondFiles(): array
    {
        return [
            "diff missing" => [["diff", "trip.srt", "missing.srt"], "missing.srt: The file does not exist.\n"],
            "diff broken"  => [["diff", "trip.srt", "bad.srt"], "bad.srt: ParsingException (Error #100): "],
            "dual missing" => [["dual", "--primary", "trip.srt", "--secondary", "missing.srt"], "missing.srt: The file does not exist.\n"],
            "dual broken"  => [["dual", "--primary", "trip.srt", "--secondary", "bad.srt"], "bad.srt: ParsingException (Error #100): "],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("secondFiles")]
    public function testFailureOfTheSecondFileNamesOnlyThatFile(array $arguments, string $message): void
    {
        file_put_contents("$this->dir/bad.srt", "Not a subtitle.\n");

        [$code, $stdout, $stderr] = $this->runCli($arguments);

        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith($message, $stderr);
        $this->assertStringNotContainsString("trip.srt", $stderr);
    }


    public function testFormatsListsTheRegistry(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(["formats"]);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\AName +Extensions +Read  Write\n/', $stdout);
        $this->assertMatchesRegularExpression('/^srt +\.srt +yes   yes$/m', $stdout);
        $this->assertMatchesRegularExpression('/^pgs +\.sup +yes   yes$/m', $stdout);
        $this->assertCount(count(Format::cases()) + 1, explode("\n", trim($stdout)));
        $this->assertSame("", $stderr);
    }
}
