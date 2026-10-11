<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Containers;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\OptionsCopy;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleReader;
use SubtitleToolbox\WriteOptions;

/**
 * Changes each input subtitle with transform() and writes it to a file or to standard output.
 *
 * @internal
 */
abstract class WriteCommand extends FileCommand
{
    protected const OUTPUT_DETAILS = "Without -o or --output-dir, the output goes to standard output.";

    private const LINE_ENDINGS = ["lf" => LineEnding::Lf, "crlf" => LineEnding::Crlf];

    // The options that name a file the command reads besides its inputs. No output may overwrite one.
    private const READ_OPTIONS = ["reference", "silence-log", "mask-words", "snap-shot-changes", "errors-replace-list", "ocr-database"];

    protected ?Format $toFormat = null;

    protected ?string $output = null;

    protected bool $dataOnStdout = false;

    /** @var list<string> the real paths of the files that the command reads */
    private array $readPaths = [];

    /** @var array<string, string> input => real path of its output file, where checkInputs() could tell it */
    private array $plannedTargets = [];

    protected ?float $outputFps = null;

    private WriteOptions $writeOptions;

    private bool $sccFit = false;


    public function options(): array
    {
        return [...$this->commandOptions(), ...$this->outputOptions(), ...$this->inputOptions()];
    }


    /**
     * Returns the subtitle to write, changed in place or new.
     */
    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        return $subtitle;
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps and --output-fps. A specific option wins over --fps.";
    }


    protected function toDescription(): string
    {
        return "Output format. Default: the input format.";
    }


    /**
     * @return list<Option>
     */
    protected function outputOptions(): array
    {
        return [
            Option::value("to", "FORMAT", $this->toDescription()),
            Option::value("output", "FILE", "Write the output of one input to this file in place of standard output. The file must not exist.", "o"),
            Option::value("output-dir", "DIR", "Write each output into this directory, with the base name of its input. Several inputs need it."),
            Option::value("output-fps", "RATE", "Frame rate of MicroDVD and iTT output. Default: the frame rate of a MicroDVD or iTT input."),
            Option::value("line-ending", "lf|crlf", "Line ending of the output. Default: lf."),
            Option::flag("bom", "Start the output with a UTF-8 BOM."),
            Option::flag("no-bom", "Write no UTF-8 BOM. Default: the BOM rule of the output format."),
            Option::flag("skip-image-cues", "Leave out image cues without text, for example from PGS or VobSub, in place of failing."),
            Option::flag("scc-fit", "Wrap, replace characters and delay or drop captions that SCC output cannot hold, in place of failing. Prints each change."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $to              = $arguments->value("to");
        $this->toFormat  = $to === null ? null : self::writableFormat($to);
        $this->output    = $arguments->value("output");
        $this->outputFps = $arguments->rate("output-fps");

        if ($this->output !== null && $arguments->has("output-dir")) {
            self::fail("Pass only one of --output and --output-dir.");
        }
        self::checkOutputDirectory($arguments->value("output-dir"));
        if ($this->output !== null && str_ends_with($this->output, "/")) {
            self::fail("The output $this->output ends with a slash. Pass a file name, or pass --output-dir $this->output.");
        }
        $lastPart = $this->output === null ? null : substr((string)strrchr("/$this->output", "/"), 1);
        if ($lastPart === "." || $lastPart === "..") {
            self::fail("The output $this->output ends with \"$lastPart\". Pass a file name, or pass --output-dir $this->output.");
        }
        if ($this->output !== null && $this->output !== self::DASH && is_dir(self::realTarget($this->output))) {
            self::fail("The output $this->output is a directory. Pass --output-dir $this->output.");
        }
        if ($this->output !== null && $this->toFormat !== null && $this->output !== self::DASH) {
            $named = Format::fromPath($this->output);
            if ($named !== null && !in_array(strtolower(pathinfo($this->output, PATHINFO_EXTENSION)), $this->toFormat->extensions(), true)) {
                self::fail("The extension of --output $this->output names the format $named->value, not the --to format {$this->toFormat->value}.");
            }
        }
        if ($arguments->has("bom") && $arguments->has("no-bom")) {
            self::fail("Pass only one of --bom and --no-bom.");
        }

        $this->sccFit       = $arguments->has("scc-fit");
        $lineEnding         = $arguments->choice("line-ending", array_keys(self::LINE_ENDINGS));
        $this->writeOptions = new WriteOptions(...self::given([
            "lineEnding"    => $lineEnding === null ? null : self::LINE_ENDINGS[$lineEnding],
            "bom"           => $arguments->has("bom") || $arguments->has("no-bom") ? $arguments->has("bom") : null,
            "skipImageCues" => $arguments->has("skip-image-cues"),
        ]));
    }


    /**
     * Plans every output file before the first read.
     * It fails when a file exists, is a file that the command reads, or is the output of two inputs.
     */
    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        $count = count($inputs);
        if ($this->output !== null && $count > 1) {
            self::fail("--output takes one input file, got $count. Pass --output-dir DIR for several files.");
        }
        if ($count > 1 && !$arguments->has("output-dir")) {
            self::fail("$count input files need --output-dir DIR. One input file goes to standard output or to -o FILE.");
        }
        if ($arguments->has("output-dir") && in_array(self::DASH, $inputs, true)) {
            self::fail("Standard input has no file name for --output-dir. Pass -o FILE.");
        }

        $this->readPaths    = self::realReadPaths([
            ...$this->readPaths($inputs, $arguments),
            ...array_filter(array_map($arguments->value(...), self::READ_OPTIONS)),
        ]);
        $this->dataOnStdout = $this->output === self::DASH || in_array(self::DASH, $inputs, true)
            || ($this->output === null && !$arguments->has("output-dir"));

        $this->plannedTargets = [];
        $writers              = [];
        foreach ($inputs as $input) {
            $target = $this->plannedTarget($input, $arguments);
            if ($target === null || $target === self::DASH) {
                continue;
            }
            $real = self::realTarget($target);
            if (isset($writers[$real])) {
                self::fail("$writers[$real] and $input would both write $target. Pass them in two runs.");
            }
            $this->checkNewFile($real, "The output $target");
            $writers[$real]               = $input;
            $this->plannedTargets[$input] = $real;
        }
        foreach ($this->sideOutputs() as $option => $path) {
            $real = self::realTarget($path);
            if (isset($writers[$real])) {
                self::fail("The --$option file $path is also the output of $writers[$real].");
            }
            $this->checkNewFile($real, "The --$option file $path");
            $writers[$real] = "--$option";
        }
    }


    /**
     * Fails when the output $name, with the real path $real, is a file that the command reads or exists.
     */
    private function checkNewFile(string $real, string $name): void
    {
        if (in_array($real, $this->readPaths, true)) {
            self::fail("$name is a file that the command reads. Pass another output file or directory.");
        }
        if (OutputFiles::exists($real)) {
            self::fail("$name exists. The tool never overwrites a file. Remove it, or pass another output file or directory.");
        }
    }


    /**
     * Returns the files that the command writes besides the subtitles, by option name.
     *
     * @return array<string, string>
     */
    protected function sideOutputs(): array
    {
        return [];
    }


    /**
     * Returns the output of $input, or null when only the read can tell it.
     */
    private function plannedTarget(string $input, Arguments $arguments): ?string
    {
        if ($input === self::DASH || $this->output !== null || !$arguments->has("output-dir")) {
            return $this->target($input, null, $arguments);
        }
        $outputFormat = $this->toFormat;
        if ($outputFormat === null) {
            $outputFormat = $this->peekFormat($input, $this->inputTrack);
            $outputFormat = $outputFormat?->canWrite() ? $outputFormat : null;
        }

        return $outputFormat === null ? null : $this->target($input, $outputFormat, $arguments);
    }


    /**
     * Returns the format that the read of $input will find, or null when only the read can tell.
     */
    private function peekFormat(string $input, ?int $track): ?Format
    {
        try {
            if (!is_file($input)) {
                return null;
            }
            if ($track !== null || Containers::detectFile($input) !== null) {
                $reader   = Containers::open($input);
                $tracks   = $reader->getSubtitleTracks();
                $track  ??= count($tracks) === 1 ? $tracks[0]->number : null;

                return $track === null ? null : $reader->trackFormat($track);
            }
            if ($this->fromFormat !== null) {
                return $this->fromFormat;
            }

            return SubtitleReader::detectFormat(StringHelpers::decode((string)file_get_contents($input), $this->readOptions->encoding)->content, $input);
        } catch (SubtitleToolboxException) {
            return null;
        }
    }


    /**
     * Returns the real paths of $paths that exist, with the other file of each VobSub .idx and .sub pair.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function realReadPaths(array $paths): array
    {
        $real = [];
        foreach ($paths as $path) {
            $realPath = $path === self::DASH ? false : realpath($path);
            if ($realPath === false) {
                continue;
            }
            $real[]    = $realPath;
            $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
            if ($extension !== "idx" && $extension !== "sub") {
                continue;
            }
            $pair = strtolower(pathinfo($realPath, PATHINFO_FILENAME)) . ($extension === "idx" ? ".sub" : ".idx");
            foreach (scandir(dirname($realPath)) ?: [] as $name) {
                if (strtolower($name) === $pair) {
                    $real[] = dirname($realPath) . DIRECTORY_SEPARATOR . $name;
                }
            }
        }

        return array_values(array_unique($real));
    }


    /**
     * Returns the files that the command reads. No output may be one of them.
     *
     * @param list<string> $inputs
     *
     * @return list<string>
     */
    protected function readPaths(array $inputs, Arguments $arguments): array
    {
        return $inputs;
    }


    protected function report(Console $console, string $line): void
    {
        $this->dataOnStdout ? $console->err($line) : $console->out($line);
    }


    private static function writableFormat(string $nameOrExtension): Format
    {
        $format = FormatArgument::find($nameOrExtension);
        if (!$format->canWrite()) {
            self::fail("The format $format->value can be read but not written.");
        }

        return $format;
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $outputFormat = $this->toFormat ?? ($format->canWrite() ? $format : null)
            ?? self::fail("The format $format->value can be read but not written. Pass --to with another format.");
        $target = $this->target($input, $outputFormat, $arguments);
        $this->checkTarget($input, $target);

        $subtitle = $this->transform($subtitle, $arguments, $console, $input);

        try {
            $content = $subtitle->toString($outputFormat, $this->formatterOptions($outputFormat, $arguments));
        } catch (ImageCueWithoutTextException) {
            self::fail("The file holds image cues without text. Run OCR on them first, or pass --skip-image-cues.");
        }
        if ($outputFormat === Format::Scc && $this->sccFit) {
            $report = (new SccFormatter())->formatWithReport($subtitle, $this->formatterOptions($outputFormat, $arguments));
            $content = $report->content;
            foreach ($report->changes as $change) {
                $console->err(self::label($input) . ": $change->message ({$change->action->value})\n");
            }
        }
        if ($outputFormat === Format::WebVtt) {
            $report = (new WebVttFormatter())->formatWithReport($subtitle, $this->formatterOptions($outputFormat, $arguments));
            foreach ($report->droppedColors as $dropped) {
                $console->err(self::label($input) . ": $dropped->message\n");
            }
        }

        if ($target === self::DASH) {
            $console->out($content);

            return;
        }

        $this->outputFiles->create($target, $content);
        $this->report($console, self::label($input) . " -> $target\n");
    }


    /**
     * Checks an output that checkInputs() could not plan, because only the read told the format of the input.
     */
    private function checkTarget(string $input, string $target): void
    {
        if ($target === self::DASH) {
            return;
        }
        $real = self::realTarget($target);
        if ($real === ($this->plannedTargets[$input] ?? null)) {
            return;
        }
        if (in_array($real, $this->plannedTargets, true)) {
            self::fail("The output $target is also the output of another input.");
        }
        $this->checkNewFile($real, "The output $target");
    }


    /**
     * Returns formatter options of the command for the output format.
     */
    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        return null;
    }


    /**
     * Returns the output of $input: standard output, the --output file, or a file in --output-dir.
     * The file in --output-dir has the base name of the input and the extension of $outputFormat.
     * $outputFormat is null only for standard output and --output.
     */
    private function target(string $input, ?Format $outputFormat, Arguments $arguments): string
    {
        if ($this->output !== null) {
            return $this->output;
        }
        $directory = $arguments->value("output-dir");
        if ($input === self::DASH || $directory === null || $outputFormat === null) {
            return self::DASH;
        }

        $fileName   = basename($input);
        $extensions = $outputFormat->extensions();
        if (!in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $extensions, true)) {
            $fileName = pathinfo($fileName, PATHINFO_FILENAME) . "." . $extensions[0];
        }

        return rtrim($directory, "/\\") . "/$fileName";
    }


    private function formatterOptions(Format $outputFormat, Arguments $arguments): WriteOptions
    {
        $format = match (true) {
            $this->outputFps !== null && $outputFormat === Format::MicroDvd => new MicroDvdWriteOptions(frameRate: $this->outputFps),
            $this->outputFps !== null && $outputFormat === Format::Itt      => new IttWriteOptions(frameRate: $this->outputFps),
            $this->sccFit && $outputFormat === Format::Scc                  => new SccWriteOptions(fit: true),
            default                                                         => $this->commandFormatterOptions($outputFormat, $arguments),
        };

        return OptionsCopy::with($this->writeOptions, ["formats" => $format === null ? [] : [$format]]);
    }
}
