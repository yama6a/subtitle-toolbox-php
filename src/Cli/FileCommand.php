<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Subtitle;

/**
 * Reads each input file, a glob or the files of a directory, and runs process() on it.
 *
 * @internal
 */
abstract class FileCommand extends Command
{
    public const DASH = "-";

    public const LENIENT_HINT = "Pass --lenient to skip or repair broken cues.";

    protected int $succeeded = 0;

    protected int $failed = 0;

    protected InputReader $inputReader;

    protected OutputFiles $outputFiles;

    /** @var list<ParseWarning> */
    protected array $parseWarnings = [];

    // The second file of diff and dual while it loads. A failure then names it instead of the input.
    private ?string $failureLabel = null;


    /**
     * @param (\Closure(string): void)|null $beforeCreate a test hook that runs before each output file is created, see OutputFiles
     */
    public function __construct(private readonly ?\Closure $beforeCreate = null)
    {
    }


    /**
     * @return list<Option>
     */
    abstract protected function commandOptions(): array;


    abstract protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void;


    public function options(): array
    {
        return [...$this->commandOptions(), ...$this->inputOptions()];
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("word-timestamps");
    }


    /**
     * Returns false for a command that reads exactly one input. It then has no --keep-going.
     */
    protected function takesManyInputs(): bool
    {
        return true;
    }


    protected function fpsDescription(): string
    {
        return "Same as --input-fps.";
    }


    /**
     * Returns the names of the format and track options of the input and of the second file.
     *
     * @return array{from: string, track: string, secondFrom: string, secondTrack: string}
     */
    protected function fileOptionNames(): array
    {
        return ["from" => "from", "track" => "track", "secondFrom" => "secondary-from", "secondTrack" => "secondary-track"];
    }


    /**
     * @return list<Option>
     */
    protected function inputOptions(): array
    {
        $options = [
            Option::value("from", "FORMAT", "Input format. Default: the format detected from the content. When detection finds no format, the file extension sets it. Chapters and cloud speech-to-text JSON need --from."),
            Option::value("encoding", "NAME", "Encoding of input that is not UTF-8, for example Windows-1252. It replaces code page detection. A BOM, valid UTF-8 and UTF-16 input win over it."),
            Option::flag("lenient", "Skip or repair broken cues and print a warning for each. SCC, PGS, VobSub and chapter input ignore it."),
            Option::value("input-fps", "RATE", "Frame rate of a MicroDVD input without a {1}{1}<fps> first line, and of CSV or TSV times in hh:mm:ss:ff."),
            Option::value("fps", "RATE", $this->fpsDescription()),
            Option::flag("word-timestamps", "Keep the word times of speech-to-text JSON, YouTube timed text and Podcasting 2.0 transcript input."),
            Option::value("scc-roll-up", "MODE", "How SCC, MCC and MP4 c608 input reads roll-up captions: screen gives one cue per screen, lines gives one cue per row. Default: screen."),
            Option::value("track", "NUMBER", "Subtitle track of an MKV, WebM or MP4 input. Needed when the file has 2 or more subtitle tracks. \"info\" lists them."),
        ];
        if ($this->takesManyInputs()) {
            $options[] = Option::flag("keep-going", "Go on with the next file after a file fails. Default: stop at the first failure.");
        }

        return $options;
    }


    protected function secondFileInherits(): bool
    {
        return false;
    }


    /**
     * Returns the format and track options of the second file, for a command that reads one.
     *
     * @return list<Option>
     */
    protected function secondFileOptions(string $file): array
    {
        $names = $this->fileOptionNames();

        return [
            Option::value($names["secondFrom"], "FORMAT", "Format of the $file file. Default: " .
                          ($this->secondFileInherits() ? "as for --$names[from]." : "the format detected from the content.")),
            Option::value($names["secondTrack"], "NUMBER", "Subtitle track of an MKV, WebM or MP4 $file file. Needed when the file has 2 or more subtitle tracks."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        $this->succeeded   = 0;
        $this->failed      = 0;
        $this->outputFiles = new OutputFiles($this->beforeCreate);
        $this->inputReader = new InputReader($arguments, $this->fileOptionNames(), $this->secondFileInherits(), $this->needsWordTimestamps($arguments));
    }


    /**
     * @param list<string> $inputs
     */
    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
    }


    /**
     * Loads the side files that all inputs share. It runs after checkInputs() and before the first input.
     */
    protected function loadSideFiles(Arguments $arguments, Console $console): void
    {
    }


    /**
     * Fails when $directory, the value of --output-dir, names a file.
     */
    public static function checkOutputDirectory(?string $directory): void
    {
        $real = $directory === null ? null : self::realTarget($directory);
        if ($real !== null && OutputFiles::exists($real) && !is_dir($real)) {
            self::fail("The --output-dir $directory is a file. Pass a directory.");
        }
    }


    /**
     * Returns the real path of a file that may not exist yet.
     * That is the real path of its nearest existing directory, plus the rest of the path.
     */
    public static function realTarget(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $parent = dirname($path);
        $name   = basename($path);
        if ($parent === $path || $name === "") {
            return $path;
        }
        $realParent = self::realTarget($parent);

        return match ($name) {
            "."     => $realParent,
            ".."    => dirname($realParent),
            default => rtrim($realParent, "/\\") . DIRECTORY_SEPARATOR . $name,
        };
    }


    /**
     * @return list<string>
     */
    protected function inputArguments(Arguments $arguments): array
    {
        return $arguments->positionals;
    }


    protected function batchSummary(int $total, int $skipped): ?string
    {
        $summary = "$total files: $this->succeeded succeeded, $this->failed failed";

        return $summary . ($skipped > 0 ? ", $skipped skipped." : ".");
    }


    protected function report(Console $console, string $line): void
    {
        $console->out($line);
    }


    protected function finish(Console $console): void
    {
    }


    protected function exitCode(): int
    {
        return $this->failed > 0 ? Application::EXIT_FILE : Application::EXIT_OK;
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        $this->prepare($arguments);

        $inputs = InputFiles::expand($this->inputArguments($arguments));
        if ($inputs === []) {
            self::fail("Pass at least one input file, or - for standard input.");
        }
        $this->checkInputs($inputs, $arguments);
        $this->loadSideFiles($arguments, $console);

        foreach ($inputs as $input) {
            $this->failureLabel = null;
            try {
                $read = $this->read($input, $arguments, $console);
                if ($read !== null) {
                    $this->process($input, $read[0], $read[1], $arguments, $console);
                }
                $this->succeeded++;
            } catch (FileFailure $failure) {
                throw $failure;
            } catch (\Throwable $exception) {
                $this->failed++;
                $names = $this->fileOptionNames();
                $console->err(($this->failureLabel ?? self::label($input)) . ": " . CliMessages::reword(self::throwableMessage($exception), "--$names[track]", "--$names[from]") . "\n");
                if ($this->inputReader->needsLenientHint()) {
                    $console->err(self::LENIENT_HINT . "\n");
                }
                if (!$arguments->has("keep-going")) {
                    break;
                }
            }
        }

        $this->finish($console);
        $skipped = count($inputs) - $this->succeeded - $this->failed;
        if ($skipped > 0) {
            $console->err("Stopped at the first failure. Pass --keep-going to process the other files.\n");
        }
        $summary = count($inputs) > 1 ? $this->batchSummary(count($inputs), $skipped) : null;
        if ($summary !== null) {
            $this->report($console, "$summary\n");
        }

        return $this->exitCode();
    }


    protected static function label(string $input): string
    {
        return $input === self::DASH ? "stdin" : $input;
    }


    /**
     * Returns the subtitle and its format, or null when listTracks() handled an MKV, WebM or MP4 input.
     *
     * @return array{Subtitle, Format}|null
     */
    protected function read(string $input, Arguments $arguments, Console $console): ?array
    {
        $this->parseWarnings = [];
        $subtitle            = $this->inputReader->read($input, $console, fn (string $path, string $input): bool => $this->listTracks($path, $input, $console));
        if ($subtitle === null) {
            return null;
        }
        $this->parseWarnings = $subtitle->getParseWarnings();
        CliMessages::printWarnings($console, self::label($input), $this->parseWarnings);

        return [$subtitle, $subtitle->findFormat()];
    }


    /**
     * Reads the subtitle of a side file, such as the reference of sync, and detects its format.
     * A file that is missing or does not parse stops the run.
     */
    protected function loadSideSubtitle(string $path, Console $console, ?int $track, string $trackOption): Subtitle
    {
        try {
            return $this->inputReader->readSideFile($path, $console, $track, $trackOption);
        } catch (\Throwable $exception) {
            return SideFile::fail($path, self::throwableMessage($exception) . ($this->inputReader->needsLenientHint() ? "\n" . self::LENIENT_HINT : ""));
        }
    }


    /**
     * Reads the second file of diff and dual with its format and track options. A failure names the second file.
     */
    protected function loadSecondFile(string $path, Arguments $arguments, Console $console): Subtitle
    {
        $this->failureLabel = $path;
        $subtitle           = $this->inputReader->readSecondFile($path, $console);
        $this->failureLabel = null;

        return $subtitle;
    }


    /**
     * Handles the MKV, WebM or MP4 file at $path without --track. $input is the argument that named it.
     * Returns false to read its only subtitle track.
     */
    protected function listTracks(string $path, string $input, Console $console): bool
    {
        return false;
    }
}
