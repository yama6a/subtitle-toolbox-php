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
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleReader;

/**
 * Reads one file of a FileCommand with the input options, such as --from, --track and --encoding, and picks its format.
 *
 * @internal
 */
final class InputReader
{
    private const IGNORE_LENIENT = [Format::Scc, Format::Pgs, Format::VobSub, Format::FfMetadataChapters, Format::OgmChapters,
                                    Format::PodcastChapters, Format::YouTubeChapters];

    private readonly ?Format $fromFormat;

    private readonly ?Format $secondFormat;

    private readonly ?int $track;

    private readonly ?int $secondTrack;

    private readonly bool $secondTrackInherited;

    private readonly ReadOptions $readOptions;

    private bool $lenientHint = false;


    /**
     * @param array{from: string, track: string, secondFrom: string, secondTrack: string} $names see FileCommand::fileOptionNames()
     */
    public function __construct(Arguments $arguments, private readonly array $names, bool $secondFileInherits, bool $wordTimestamps)
    {
        $inputFps            = $arguments->rate("input-fps");
        $sccRollUp           = $arguments->choice("scc-roll-up", array_column(SccRollUp::cases(), "value"));
        $this->track         = $arguments->positiveInt($names["track"]);
        $explicitSecondTrack = $arguments->positiveInt($names["secondTrack"]);
        $this->secondTrack   = $explicitSecondTrack ?? ($secondFileInherits ? $this->track : null);

        $this->secondTrackInherited = $explicitSecondTrack === null && $this->secondTrack !== null;

        $from               = $arguments->value($names["from"]);
        $this->fromFormat   = $from === null ? null : FormatArgument::readable($from);
        $second             = $arguments->value($names["secondFrom"]);
        $this->secondFormat = $second === null ? ($secondFileInherits ? $this->fromFormat : null) : FormatArgument::readable($second);

        $this->readOptions = new ReadOptions(
            encoding: $arguments->value("encoding"),
            lenient: $arguments->has("lenient"),
            formats: self::formatOptions($inputFps, $wordTimestamps, $sccRollUp === null ? null : SccRollUp::from($sccRollUp)),
        );
    }


    /**
     * Returns true when the last read failed on a parse error that --lenient can skip or repair.
     */
    public function needsLenientHint(): bool
    {
        return $this->lenientHint;
    }


    /**
     * Reads the input path, or standard input for "-". Returns null when $listTracks handled an MKV, WebM or MP4 input.
     *
     * @param \Closure(string, string): bool $listTracks gets the path of the file and the input argument, see FileCommand::listTracks()
     */
    public function read(string $input, Console $console, \Closure $listTracks): ?Subtitle
    {
        $this->lenientHint = false;
        if ($input === FileCommand::DASH) {
            return $this->readStdin($console, $listTracks);
        }
        if (!is_file($input)) {
            Command::fail("The file does not exist.");
        }

        return $this->readPath($input, $input, $listTracks);
    }


    /**
     * Reads a side file, such as the reference of sync, without --from and --track.
     */
    public function readSideFile(string $path, Console $console): Subtitle
    {
        $this->lenientHint = false;

        return $this->readOtherFile($path, $console);
    }


    /**
     * Reads the second file of diff and dual with its format and track options. A failure names these options.
     */
    public function readSecondFile(string $path, Console $console): Subtitle
    {
        $track = $this->secondTrackInherited && Containers::detectFile($path) === null ? null : $this->secondTrack;

        return $this->readOtherFile($path, $console, $this->secondFormat, $track, "--" . $this->names["secondTrack"], "--" . $this->names["secondFrom"]);
    }


    /**
     * Returns the format that the read of $input will find, or null when only the read can tell.
     */
    public function peekFormat(string $input): ?Format
    {
        try {
            if (!is_file($input)) {
                return null;
            }
            $track = $this->track;
            if ($track !== null || Containers::detectFile($input) !== null) {
                $reader   = Containers::open($input);
                $tracks   = $reader->getSubtitleTracks();
                $track  ??= count($tracks) === 1 ? $tracks[0]->number : null;

                return $track === null ? null : $reader->trackFormat($track);
            }
            if ($this->fromFormat !== null) {
                return $this->fromFormat;
            }

            return $this->detectFormat((string)file_get_contents($input), $input);
        } catch (SubtitleToolboxException) {
            return null;
        }
    }


    /**
     * Reads a file other than the input. Detects the format unless $format or $track is given.
     * $trackOption and $fromOption name the options that set $track and $format.
     */
    private function readOtherFile(string $path, Console $console, ?Format $format = null, ?int $track = null,
                                   ?string $trackOption = null, ?string $fromOption = null): Subtitle
    {
        if (!is_file($path)) {
            Command::fail("The file does not exist.");
        }

        try {
            $subtitle = $this->loadFile($path, $format, $track);
        } catch (SubtitleToolboxException $exception) {
            return Command::fail(CliMessages::reword($exception->getMessage(), $trackOption, $fromOption));
        }
        CliMessages::printWarnings($console, $path, $subtitle->getParseWarnings());

        return $subtitle;
    }


    /**
     * Returns null when $listTracks handled an MKV, WebM or MP4 file.
     *
     * @param \Closure(string, string): bool $listTracks
     */
    private function readPath(string $path, string $input, \Closure $listTracks): ?Subtitle
    {
        if ($this->track === null && $listTracks($path, $input)) {
            return null;
        }

        return $this->loadFile($path, $this->fromFormat, $this->track);
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
    private static function formatOptions(?float $inputFps, bool $wordTimestamps, ?SccRollUp $sccRollUp): array
    {
        return array_values(array_filter([
            $inputFps === null ? null : new MicroDvdReadOptions($inputFps),
            $inputFps === null ? null : new CsvReadOptions(frameRate: $inputFps),
            $wordTimestamps ? new TranscriptReadOptions(wordTimestamps: true) : null,
            $sccRollUp === null ? null : new SccReadOptions(rollUp: $sccRollUp),
        ]));
    }


    /**
     * @param \Closure(string, string): bool $listTracks
     */
    private function readStdin(Console $console, \Closure $listTracks): ?Subtitle
    {
        $content = $console->readStdin();
        if ($this->track !== null || Containers::detect($content) !== null) {
            // The track list and loadTrack() need a file.
            $path = tempnam(sys_get_temp_dir(), Application::NAME . "-");
            try {
                file_put_contents($path, $content);

                return $this->readPath($path, FileCommand::DASH, $listTracks);
            } finally {
                @unlink($path);
            }
        }
        if ($this->fromFormat === Format::VobSub) {
            Command::fail("VobSub needs the path of the .idx file. Standard input does not work.");
        }

        $format = $this->fromFormat;

        return $format === null
            ? $this->parse(fn (): Subtitle => Subtitle::fromStringAutoDetectFormat($content, $this->readOptions), fn (): ?Format => $this->detectFormat($content, null))
            : $this->parse(fn (): Subtitle => Subtitle::fromString($content, $format, $this->readOptions), fn (): Format => $format);
    }
}
