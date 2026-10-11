<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Diff\CueDifference;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * @internal
 */
final class DiffCommand extends ReportCommand
{
    private ?SubtitleDiffOptions $diffOptions = null;

    private bool $different = false;


    public function name(): string
    {
        return "diff";
    }


    public function summary(): string
    {
        return "List the added, removed and changed cues between two subtitle files.";
    }


    protected function usageLines(): array
    {
        return ["<old> <new> [options]"];
    }


    protected function details(): string
    {
        return "The diff pairs cues by time and text, not by cue number. So one added cue does not shift the rest. Cue numbers start at 1. " .
               "The exit code is 1 when the files differ, as with the Unix diff command. The files can have different formats. " .
               "--from, --track and --encoding apply to both files. --new-from and --new-track replace --from and --track for the new file.";
    }


    protected function jsonDescription(): string
    {
        return "Print the differences as JSON: a list with one object for the pair of files.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("time-tolerance", "SECONDS", "A larger difference of a start or end time is a timing change. Default: 0.001."),
            Option::flag("ignore-formatting", "Compare the text without tags."),
            Option::flag("ignore-whitespace", "Compare the text without spaces, tabs and line breaks."),
            Option::flag("text-only", "Report no timing changes."),
        ];
    }


    protected function takesManyInputs(): bool
    {
        return false;
    }


    protected function fileOptionNames(): array
    {
        return ["from" => "from", "track" => "track", "secondFrom" => "new-from", "secondTrack" => "new-track"];
    }


    protected function secondFileInherits(): bool
    {
        return true;
    }


    protected function inputOptions(): array
    {
        return [...parent::inputOptions(), ...$this->secondFileOptions("new")];
    }


    protected function inputArguments(Arguments $arguments): array
    {
        if (count($arguments->positionals) !== 2) {
            self::fail("Pass two files, the old one and the new one.");
        }

        return [$arguments->positionals[0]];
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1) {
            self::fail("The diff command takes one old file, got " . count($inputs) . ".");
        }

        parent::checkInputs($inputs, $arguments);
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->different   = false;
        $this->diffOptions = new SubtitleDiffOptions(...self::withoutNulls([
            "timeTolerance"    => $arguments->nonNegativeSeconds("time-tolerance"),
            "ignoreFormatting" => $arguments->has("ignore-formatting"),
            "ignoreWhitespace" => $arguments->has("ignore-whitespace"),
            "textOnly"         => $arguments->has("text-only"),
        ]));
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $newPath     = $arguments->positionals[1];
        $new         = $this->loadSecondFile($newPath, $arguments, $console);
        $differences = SubtitleDiff::compare($subtitle, $new, $this->diffOptions);

        $this->different = $differences !== [];
        $this->emit($console, SubtitleDiff::toText($differences), [
            "oldFile"     => $input,
            "newFile"     => $newPath,
            "equal"       => $differences === [],
            "differences" => array_map(fn (CueDifference $difference): array => [
                "kind"     => $difference->kind->value,
                "oldIndex" => $difference->oldIndex,
                "newIndex" => $difference->newIndex,
                "old"      => self::cue($difference->oldCue),
                "new"      => self::cue($difference->newCue),
            ], $differences),
            "oldWarnings" => self::warningsJson($this->parseWarnings),
            "newWarnings" => self::warningsJson($new->getParseWarnings()),
        ]);
    }


    protected function exitCode(): int
    {
        return match (true) {
            $this->failed > 0 => Application::EXIT_FILE,
            $this->different  => Application::EXIT_RESULT,
            default           => Application::EXIT_OK,
        };
    }


    /**
     * @return array{start: float, end: float, lines: list<string>, forced: bool}|null
     */
    private static function cue(?SubtitleCue $cue): ?array
    {
        return $cue === null ? null : [
            "start"  => $cue->getStart(),
            "end"    => $cue->getEnd(),
            "lines"  => $cue->getLines(),
            "forced" => $cue->isForced(),
        ];
    }
}
