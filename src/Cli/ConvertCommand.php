<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Cli\Edits\AssOutput;
use SubtitleToolbox\Cli\Edits\OptionGroup;
use SubtitleToolbox\Cli\Edits\EditPipeline;
use SubtitleToolbox\Cli\Edits\MaskingEdit;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class ConvertCommand extends WriteCommand
{
    private EditPipeline $edits;

    private ?AssOutput $assOutput = null;


    public function name(): string
    {
        return "convert";
    }


    public function summary(): string
    {
        return "Convert subtitle files to another format, and run OCR, text, structure and timing edits on the way.";
    }


    protected function usageLines(): array
    {
        return ["<input> [--to FORMAT] [-o FILE] [options]", "<input>... [--to FORMAT] --output-dir DIR [options]"];
    }


    protected function details(): string
    {
        return "Without --to, the output keeps the format of the input. " . self::OUTPUT_DETAILS . " " .
               "An input argument can be a file, a directory, a glob such as \"season1/*.srt\", or -. The tool never overwrites a file.";
    }


    /**
     * Returns the common options and the group list, the options of the group $topic, or every option for "all".
     */
    public function help(?string $topic = null): string
    {
        $groups = [];
        foreach (self::optionGroups() as $class) {
            $groups[$class::group()] = $class;
        }

        // In "convert in.srt -h out.srt" the word after -h is a file, not a group. A dot or a slash marks a file too.
        if ($topic !== null && $topic !== "all" && !isset($groups[$topic]) && (strpbrk($topic, "./\\") !== false || file_exists($topic))) {
            $topic = null;
        }

        $common = $this->helpHeader() . "\nOptions:\n" . self::optionList([...$this->commonOptions(), self::helpOption()]);
        if ($topic === null) {
            $list = HelpLayout::table(array_map(fn (string $name, string $class): array => [$name, $class::summary()], array_keys($groups), $groups));

            return "$common\nOption groups, in the order that convert runs their edits:\n$list\n" .
                   "Run \"" . Application::NAME . " convert --help GROUP\" for the options of a group,\n" .
                   "or \"" . Application::NAME . " convert --help all\" for all options.\n";
        }
        if ($topic === "all") {
            return $common . implode("", array_map(fn (string $class): string => "\n" . self::groupHelp($class), $groups));
        }
        if (!isset($groups[$topic])) {
            self::fail("Unknown option group \"$topic\". The groups are " . implode(", ", array_keys($groups)) . ", and all for every option.");
        }

        return self::groupHelp($groups[$topic]);
    }


    /**
     * @return list<class-string<OptionGroup>> the edits in the order that convert runs them, then the ASS writer settings
     */
    private static function optionGroups(): array
    {
        return [...EditPipeline::edits(), AssOutput::class];
    }


    /**
     * @param class-string<OptionGroup> $class
     */
    private static function groupHelp(string $class): string
    {
        return HelpLayout::wrap($class::group() . ": " . $class::summary()) . self::optionList($class::options());
    }


    /**
     * @return list<Option>
     */
    private function commonOptions(): array
    {
        return [...$this->outputOptions(), ...$this->inputOptions(), self::languageOption()];
    }


    private static function languageOption(): Option
    {
        return Option::value("language", "CODE", "Language for --case and --errors-fix, for example en, de-AT or tr. " .
                                               "Without it, --errors-fix takes the language metadata of the input.");
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps, --output-fps and --video-fps. A specific option wins over --fps.";
    }


    protected function commandOptions(): array
    {
        $options = [self::languageOption()];
        foreach (self::optionGroups() as $class) {
            array_push($options, ...$class::options());
        }

        return $options;
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->edits     = EditPipeline::fromArguments($arguments);
        $this->assOutput = AssOutput::fromArguments($arguments);
        if ($this->assOutput !== null && $this->toFormat !== Format::Ass) {
            self::fail("Pass --to ass with " . $this->assOutput->optionNames() . ".");
        }
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return parent::needsWordTimestamps($arguments)
            || array_filter(self::optionGroups(), fn (string $class): bool => $class::needsWordTimestamps($arguments)) !== [];
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1 && !$this->edits->takesManyInputs()) {
            self::fail("--mute-edl and --mute-filter take one input file, got " . count($inputs) . ".");
        }

        parent::checkInputs($inputs, $arguments);
        $format = $this->toFormat === null && $arguments->has("output") ? $this->inputReader->peekFormat($inputs[0]) : null;
        if ($format !== null) {
            $this->checkOutputExtension($format, $arguments);
        }
    }


    /**
     * Without --to, fails when the extension of --output names a writable format other than the input format.
     * Writing SubRip into movie.vtt would look like a conversion that did not happen.
     */
    private function checkOutputExtension(Format $inputFormat, Arguments $arguments): void
    {
        $output = $arguments->value("output");
        if ($this->toFormat !== null || $output === null || $output === self::DASH) {
            return;
        }
        $named     = Format::fromPath($output);
        $extension = strtolower(pathinfo($output, PATHINFO_EXTENSION));
        if ($named !== null && $named->canWrite() && !in_array($extension, $inputFormat->extensions(), true)) {
            self::fail("The output $output has the extension of the format $named->value, not of the input format $inputFormat->value. " .
                       "Pass --to $named->value to convert, or use the extension of the input format.");
        }
    }


    protected function loadSideFiles(Arguments $arguments, Console $console): void
    {
        parent::loadSideFiles($arguments, $console);
        $this->edits->loadSideFiles();
    }


    protected function sideOutputs(): array
    {
        return $this->edits->find(MaskingEdit::class)?->outputPaths() ?? [];
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $this->checkOutputExtension($format, $arguments);
        parent::process($input, $subtitle, $format, $arguments, $console);

        foreach ($this->edits->find(MaskingEdit::class)?->writeMuteFiles($this->outputFiles) ?? [] as $path) {
            $this->report($console, self::label($input) . " -> $path\n");
        }
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        return $this->edits->apply($subtitle, $console, self::label($input));
    }


    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        return $this->assOutput?->formatOptions($outputFormat);
    }

}
