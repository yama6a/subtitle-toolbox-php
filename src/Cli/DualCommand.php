<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class DualCommand extends WriteCommand
{
    private const MODES = ["stack" => DualSubtitleMode::Stack, "top-bottom" => DualSubtitleMode::TopBottom];

    private ?DualSubtitleOptions $dualOptions = null;


    public function name(): string
    {
        return "dual";
    }


    public function summary(): string
    {
        return "Merge two subtitles in two languages into one file that shows both.";
    }


    protected function usageLines(): array
    {
        return ["--primary FILE --secondary FILE [options]"];
    }


    protected function details(): string
    {
        return "stack adds the lines of each secondary cue below the primary cue that it overlaps most. " .
               "top-bottom keeps both cues and moves the secondary one to the top. SubRip, WebVTT, ASS and TTML write the position. " .
               "The output takes the format of the primary file unless --to sets it. " .
               self::OUTPUT_DETAILS;
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("primary", "FILE", "The subtitle whose cues set the times, or - for standard input."),
            Option::value("secondary", "FILE", "The subtitle in the second language."),
            Option::value("mode", "MODE", "stack or top-bottom. Default: stack."),
            Option::value("secondary-style", "TAG", "Tag around each secondary line: b, i, u, s or 'font color=\"#ffff00\"'. Default: none."),
            Option::value("secondary-alignment", "1-9", "Position of the secondary cues for top-bottom, as on a numeric keypad. Default: 8."),
            Option::value("snap-tolerance", "SECONDS", "top-bottom moves a secondary time to a primary time this close. Default: 0.25."),
        ];
    }


    protected function toDescription(): string
    {
        return "Output format. Default: the format of the primary file.";
    }


    protected function outputOptions(): array
    {
        return array_map(
            fn (Option $option): Option => $option->name === "output-dir"
                ? Option::value("output-dir", "DIR", "Write the output into this directory, with the base name of the primary file.")
                : $option,
            parent::outputOptions()
        );
    }


    protected function fileOptionNames(): array
    {
        return ["from" => "primary-from", "track" => "primary-track", "secondFrom" => "secondary-from", "secondTrack" => "secondary-track"];
    }


    protected function takesManyInputs(): bool
    {
        return false;
    }


    protected function inputOptions(): array
    {
        $options = [];
        foreach (parent::inputOptions() as $option) {
            $options[] = match ($option->name) {
                "from"  => Option::value("primary-from", "FORMAT", "Format of the primary file. Default: the format detected from the content. When detection finds no format, the file extension sets it."),
                "track" => Option::value("primary-track", "NUMBER", "Subtitle track of an MKV, WebM or MP4 primary file. Needed when the file has several."),
                default => $option,
            };
        }

        return [...$options, ...$this->secondFileOptions("secondary")];
    }


    protected function inputArguments(Arguments $arguments): array
    {
        if ($arguments->positionals !== []) {
            self::fail("dual takes no file arguments. Pass --primary FILE and --secondary FILE.");
        }
        if (!$arguments->has("primary") || !$arguments->has("secondary")) {
            self::fail("Pass --primary FILE and --secondary FILE.");
        }

        return [$arguments->value("primary")];
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1) {
            self::fail("--primary takes one file, got " . count($inputs) . ".");
        }

        parent::checkInputs($inputs, $arguments);
    }


    protected function readPaths(array $inputs, Arguments $arguments): array
    {
        return [...$inputs, $arguments->value("secondary")];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $mode              = $arguments->choice("mode", array_keys(self::MODES));
        $this->dualOptions = new DualSubtitleOptions(...self::given([
            "mode"               => $mode === null ? null : self::MODES[$mode],
            "snapTolerance"      => $arguments->nonNegativeSeconds("snap-tolerance"),
            "secondaryStyle"     => $arguments->value("secondary-style"),
            "secondaryAlignment" => $arguments->int("secondary-alignment", 1, 9),
        ]));
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $secondary = $this->loadSecondFile($arguments->value("secondary"), $arguments, $console);

        parent::process($input, DualSubtitle::fromPair($subtitle, $secondary, $this->dualOptions), $format, $arguments, $console);
    }
}
