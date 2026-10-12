<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\OptionsCopy;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

/**
 * Changes each input subtitle with transform() and writes it to a file or to standard output.
 *
 * @internal
 */
abstract class WriteCommand extends FileCommand
{
    protected const OUTPUT_DETAILS = "Without -o or --output-dir, the output goes to standard output.";

    private const IMAGE_CUES_MESSAGE = "The file holds image cues without text. Run OCR on them first, or pass --skip-image-cues.";

    private const LINE_ENDINGS = ["lf" => LineEnding::Lf, "crlf" => LineEnding::Crlf];

    // The options that name a file the command reads besides its inputs. No output may overwrite one.
    private const READ_OPTIONS = ["reference", "silence-log", "mask-words", "snap-shot-changes", "errors-replace-list", "ocr-database"];

    protected ?Format $toFormat = null;

    private OutputPlan $outputPlan;

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

        $to               = $arguments->value("to");
        $this->toFormat   = $to === null ? null : self::writableFormat($to);
        $output           = $arguments->value("output");
        $this->outputFps  = $arguments->rate("output-fps");
        $this->outputPlan = new OutputPlan($output, $arguments->value("output-dir"), $this->toFormat);

        if ($arguments->has("bom") && $arguments->has("no-bom")) {
            self::fail("Pass only one of --bom and --no-bom.");
        }

        $this->sccFit       = $arguments->has("scc-fit");
        $lineEnding         = $arguments->choice("line-ending", array_keys(self::LINE_ENDINGS));
        $this->writeOptions = new WriteOptions(...self::withoutNulls([
            "lineEnding"    => $lineEnding === null ? null : self::LINE_ENDINGS[$lineEnding],
            "bom"           => $arguments->has("bom") || $arguments->has("no-bom") ? $arguments->has("bom") : null,
            "skipImageCues" => $arguments->has("skip-image-cues"),
        ]));
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        $readPaths = [...$this->readPaths($inputs, $arguments), ...array_filter(array_map($arguments->value(...), self::READ_OPTIONS))];
        $this->outputPlan->check($inputs, $readPaths, $this->sideOutputs(), $this->inputReader);
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
        $this->outputPlan->dataOnStdout() ? $console->err($line) : $console->out($line);
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
        $target = $this->outputPlan->target($input, $outputFormat);
        $this->outputPlan->checkTarget($input, $target);

        $subtitle = $this->transform($subtitle, $arguments, $console, $input);

        $options = $this->formatterOptions($outputFormat, $arguments);
        $label   = self::label($input);
        try {
            $content = match (true) {
                $outputFormat === Format::WebVtt               => $this->webVtt($subtitle, $options, $console, $label),
                $outputFormat === Format::Scc && $this->sccFit => $this->fittedScc($subtitle, $options, $console, $label),
                default                                        => $subtitle->toString($outputFormat, $options),
            };
        } catch (ImageCueWithoutTextException) {
            self::fail(self::IMAGE_CUES_MESSAGE);
        }

        if ($target === self::DASH) {
            $console->out($content);

            return;
        }

        $this->outputFiles->create($target, $content);
        $this->report($console, "$label -> $target\n");
    }


    private function webVtt(Subtitle $subtitle, WriteOptions $options, Console $console, string $label): string
    {
        $report = (new WebVttFormatter())->formatWithReport(self::textCues($subtitle, $options), $options);
        foreach ($report->droppedColors as $dropped) {
            $console->err("$label: $dropped->message\n");
        }

        return $report->content;
    }


    private function fittedScc(Subtitle $subtitle, WriteOptions $options, Console $console, string $label): string
    {
        $report = (new SccFormatter())->formatWithReport(self::textCues($subtitle, $options), $options);
        foreach ($report->changes as $change) {
            $console->err("$label: $change->message ({$change->action->value})\n");
        }

        return $report->content;
    }


    /**
     * Applies the image cue rule of Subtitle::toString(), which a formatter called with formatWithReport() skips.
     */
    private static function textCues(Subtitle $subtitle, WriteOptions $options): Subtitle
    {
        $isImageCue = fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue) && $cue->getLines() === [];
        if ($subtitle->findCues($isImageCue) === []) {
            return $subtitle;
        }
        if (!$options->skipImageCues) {
            self::fail(self::IMAGE_CUES_MESSAGE);
        }

        return (clone $subtitle)->removeCuesWhere($isImageCue);
    }


    /**
     * Returns formatter options of the command for the output format.
     */
    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        return null;
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
