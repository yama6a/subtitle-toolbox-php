<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Containers;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\SccRollUp;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleReader;

/**
 * Reads each input file, a glob or the files of a directory, and runs process() on it.
 *
 * @internal
 */
abstract class FileCommand extends Command
{
    public const DASH = "-";

    public const LENIENT_HINT = "Pass --lenient to skip or repair broken cues.";

    private const IGNORE_LENIENT = [Format::Scc, Format::Pgs, Format::VobSub, Format::FfMetadataChapters, Format::OgmChapters,
                                    Format::PodcastChapters, Format::YouTubeChapters];

    protected ?Format $fromFormat = null;

    protected ?Format $secondFormat = null;

    protected ?float $inputFps = null;

    protected ?int $inputTrack = null;

    private ?int $secondTrack = null;

    private bool $secondTrackInherited = false;

    protected bool $wordTimestamps = false;

    private ?SccRollUp $sccRollUp = null;

    protected int $succeeded = 0;

    protected int $failed = 0;

    protected ReadOptions $readOptions;

    protected OutputFiles $outputFiles;

    /** @var list<ParseWarning> */
    protected array $parseWarnings = [];

    // The second file of diff and dual while it loads. A failure then names it instead of the input.
    private ?string $failureLabel = null;

    private bool $lenientHint = false;


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
            Option::value("scc-roll-up", "MODE", "How SCC input reads roll-up captions: screen gives one cue per screen, lines gives one cue per row. Default: screen."),
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
            Option::value($names["secondFrom"], "FORMAT", "Format of the $file file. Default: as for --$names[from]."),
            Option::value($names["secondTrack"], "NUMBER", "Subtitle track of an MKV, WebM or MP4 $file file. Needed when the file has 2 or more subtitle tracks."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        $this->succeeded      = 0;
        $this->failed         = 0;
        $this->outputFiles    = new OutputFiles();
        $this->inputFps       = $arguments->rate("input-fps");
        $this->wordTimestamps = $this->needsWordTimestamps($arguments);
        $sccRollUp            = $arguments->choice("scc-roll-up", array_column(SccRollUp::cases(), "value"));
        $this->sccRollUp      = $sccRollUp === null ? null : SccRollUp::from($sccRollUp);
        $names                = $this->fileOptionNames();
        $this->inputTrack     = $arguments->positiveInt($names["track"]);
        $explicitSecondTrack  = $arguments->positiveInt($names["secondTrack"]);
        $this->secondTrack    = $explicitSecondTrack ?? ($this->secondFileInherits() ? $this->inputTrack : null);

        $this->secondTrackInherited = $explicitSecondTrack === null && $this->secondTrack !== null;

        $from               = $arguments->value($names["from"]);
        $this->fromFormat   = $from === null ? null : FormatArgument::readable($from);
        $second             = $arguments->value($names["secondFrom"]);
        $this->secondFormat = $second === null ? ($this->secondFileInherits() ? $this->fromFormat : null) : FormatArgument::readable($second);

        $this->readOptions = new ReadOptions(
            encoding: $arguments->value("encoding"),
            lenient: $arguments->has("lenient"),
            formats: $this->formatOptions(),
        );
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
    protected static function checkOutputDirectory(?string $directory): void
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
            $this->lenientHint  = false;
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
                if ($this->lenientHint) {
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
        $track               = $this->inputTrack;
        if ($input !== self::DASH) {
            if (!is_file($input)) {
                self::fail("The file does not exist.");
            }
            $subtitle = $this->readPath($input, $input, $track, $console);
        } else {
            $subtitle = $this->readStdin($track, $console);
        }
        if ($subtitle === null) {
            return null;
        }
        $this->parseWarnings = $subtitle->getParseWarnings();
        CliMessages::printWarnings($console, self::label($input), $this->parseWarnings);

        return [$subtitle, $subtitle->getFormat()];
    }


    /**
     * Reads the subtitle of a side file, such as the reference of sync, and detects its format.
     * A file that is missing or does not parse stops the run.
     */
    protected function loadSideSubtitle(string $path, Console $console): Subtitle
    {
        $this->lenientHint = false;
        try {
            return $this->loadOtherFile($path, $console);
        } catch (\Throwable $exception) {
            return self::failSideFile($path, self::throwableMessage($exception) . ($this->lenientHint ? "\n" . self::LENIENT_HINT : ""));
        }
    }


    /**
     * Reads the second file of diff and dual with its format and track options. A failure names the second file.
     */
    protected function loadSecondFile(string $path, Arguments $arguments, Console $console): Subtitle
    {
        $names              = $this->fileOptionNames();
        $this->failureLabel = $path;
        $track              = $this->secondTrackInherited && Containers::detectFile($path) === null ? null : $this->secondTrack;
        $subtitle           = $this->loadOtherFile($path, $console, $this->secondFormat, $track,
                                                   "--" . $names["secondTrack"], "--" . $names["secondFrom"]);
        $this->failureLabel = null;

        return $subtitle;
    }


    /**
     * Reads a file other than the input without --from and --track.
     * Detects the format unless $format or $track is given.
     * $trackOption and $fromOption name the options that set $track and $format.
     */
    private function loadOtherFile(string $path, Console $console, ?Format $format = null, ?int $track = null,
                                   ?string $trackOption = null, ?string $fromOption = null): Subtitle
    {
        if (!is_file($path)) {
            self::fail("The file does not exist.");
        }

        try {
            $subtitle = $this->loadFile($path, $format, $track);
        } catch (SubtitleToolboxException $exception) {
            return self::fail(CliMessages::reword($exception->getMessage(), $trackOption, $fromOption));
        }
        CliMessages::printWarnings($console, $path, $subtitle->getParseWarnings());

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


    /**
     * Returns null when listTracks() handled an MKV, WebM or MP4 file.
     */
    private function readPath(string $path, string $input, ?int $track, Console $console): ?Subtitle
    {
        if ($track === null && $this->listTracks($path, $input, $console)) {
            return null;
        }

        return $this->loadFile($path, $this->fromFormat, $track);
    }


    private function loadFile(string $path, ?Format $format, ?int $track): Subtitle
    {
        if ($track !== null) {
            return Subtitle::loadTrack($path, $track, $this->readOptions);
        }
        if ($format !== null) {
            return $this->parse(fn (): Subtitle => Subtitle::load($path, $format, $this->readOptions), fn (): Format => $format);
        }
        if (Containers::detectFile($path) !== null) {
            return Subtitle::loadAutoDetectFormat($path, $this->readOptions);
        }

        return $this->parse(fn (): Subtitle => Subtitle::loadAutoDetectFormat($path, $this->readOptions),
                            fn (): ?Format => $this->detectFormat((string) @file_get_contents($path), $path));
    }


    /**
     * Runs $read. After a ParsingException, it calls $format for the format of the input and sets the --lenient hint.
     *
     * @param callable(): Subtitle $read
     * @param callable(): ?Format  $format
     */
    private function parse(callable $read, callable $format): Subtitle
    {
        try {
            return $read();
        } catch (ParsingException $exception) {
            $detected          = $this->readOptions->lenient ? null : $format();
            $this->lenientHint = $detected !== null && !in_array($detected, self::IGNORE_LENIENT, true);

            throw $exception;
        }
    }


    private function detectFormat(string $content, ?string $path): ?Format
    {
        try {
            return SubtitleReader::detectFormat(StringHelpers::decode($content, $this->readOptions->encoding)->content, $path);
        } catch (SubtitleToolboxException) {
            return null;
        }
    }


    /**
     * Returns the read options of --input-fps for MicroDVD and CSV, of the word timestamps for the transcript formats and of --scc-roll-up for SCC.
     * Each parser uses only the object of its own class.
     *
     * @return list<FormatReadOptions>
     */
    private function formatOptions(): array
    {
        return array_values(array_filter([
            $this->inputFps === null ? null : new MicroDvdReadOptions($this->inputFps),
            $this->inputFps === null ? null : new CsvReadOptions(frameRate: $this->inputFps),
            $this->wordTimestamps ? new TranscriptReadOptions(wordTimestamps: true) : null,
            $this->sccRollUp === null ? null : new SccReadOptions(rollUp: $this->sccRollUp),
        ]));
    }


    private function readStdin(?int $track, Console $console): ?Subtitle
    {
        $content = $console->readStdin();
        if ($track !== null || Containers::detect($content) !== null) {
            // The track list and loadTrack() need a file.
            $path = tempnam(sys_get_temp_dir(), Application::NAME . "-");
            try {
                file_put_contents($path, $content);

                return $this->readPath($path, self::DASH, $track, $console);
            } finally {
                @unlink($path);
            }
        }
        if ($this->fromFormat === Format::VobSub) {
            self::fail("VobSub needs the path of the .idx file. Standard input does not work.");
        }

        $format = $this->fromFormat;

        return $format === null
            ? $this->parse(fn (): Subtitle => Subtitle::fromStringAutoDetectFormat($content, $this->readOptions), fn (): ?Format => $this->detectFormat($content, null))
            : $this->parse(fn (): Subtitle => Subtitle::fromString($content, $format, $this->readOptions), fn (): Format => $format);
    }
}
