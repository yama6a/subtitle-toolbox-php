<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class StructureEdit extends Edit
{
    private const EDITS = ["structure-resegment", "structure-unwrap", "structure-merge-short", "structure-split-long", "structure-wrap", "structure-merge-duplicates", "structure-merge-same-time"];

    // The edits that read --structure-max-cpl and --structure-max-lines.
    private const LIMITED_EDITS = ["structure-wrap", "structure-resegment", "structure-merge-short", "structure-split-long"];


    /**
     * @param array<string, true> $edits the names of the edit options that $arguments hold
     */
    private function __construct(
        private readonly ?ResegmentOptions $resegment,
        private readonly array $edits,
        private readonly CueLimits $limits,
    ) {
    }


    public static function group(): string
    {
        return "structure";
    }


    public static function summary(): string
    {
        return "Rebuild, join, split and wrap cues.";
    }


    public static function options(): array
    {
        $limits = new CueLimits();
        $merge  = new MergeShortCuesOptions();

        return [
            Option::flag("structure-resegment", "Build new cues from the word timestamps. Each cue holds one sentence, or what fits --structure-max-cpl and --structure-max-lines."),
            Option::value("structure-max-word-gap", "SECONDS", "--structure-resegment ends a cue at a pause of this length. Default: 0.6."),
            Option::flag("structure-unwrap", "Join the lines of each cue with a space. Each dialogue dash line starts a line of its own."),
            Option::flag("structure-merge-short", "Join cues shorter than $limits->minDuration s with a neighbor at most $merge->maxGap s away. The joined cue must fit $limits->maxDuration s, --structure-max-cpl and --structure-max-lines."),
            Option::flag("structure-split-long", "Split cues longer than $limits->maxDuration s, or longer than --structure-max-lines lines of --structure-max-cpl characters. Split at sentence ends, clause ends or spaces."),
            Option::flag("structure-wrap", "Break lines longer than --structure-max-cpl characters."),
            Option::value("structure-max-cpl", "CHARS", "Maximum characters per line for --structure-wrap, --structure-resegment, --structure-merge-short and --structure-split-long. Default: 42."),
            Option::value("structure-max-lines", "LINES", "Maximum number of lines per cue for --structure-wrap, --structure-resegment, --structure-merge-short and --structure-split-long. Default: 2."),
            Option::flag("structure-merge-duplicates", "Join same-text cues that overlap or touch."),
            Option::flag("structure-merge-same-time", "Join cues with the same start and end into one cue with the lines of all of them."),
        ];
    }


    public static function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("structure-resegment");
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "structure-resegment", ["structure-max-word-gap"]);
        self::needsOneOf($arguments, self::LIMITED_EDITS, "structure-max-cpl");
        self::needsOneOf($arguments, self::LIMITED_EDITS, "structure-max-lines");
        $wordGap = $arguments->positiveSeconds("structure-max-word-gap");
        $limits  = new CueLimits(...Command::withoutNulls([
            "maxCharactersPerLine" => $arguments->positiveInt("structure-max-cpl"),
            "maxLinesPerCue"       => $arguments->positiveInt("structure-max-lines"),
        ]));
        $edits = array_filter(self::EDITS, $arguments->has(...));
        if ($edits === []) {
            return null;
        }

        return new self(
            $arguments->has("structure-resegment")
                ? new ResegmentOptions(ResegmentMode::ByWords, $limits, ...Command::withoutNulls(["maxWordGap" => $wordGap]))
                : null,
            array_fill_keys($edits, true),
            $limits,
        );
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $limits = $this->limits;
        if ($this->resegment !== null) {
            Resegmenter::apply($subtitle, $this->resegment);
        }
        if (isset($this->edits["structure-unwrap"])) {
            $subtitle->unwrapLines();
        }
        if (isset($this->edits["structure-merge-same-time"])) {
            $subtitle->mergeSameTimeCues();
        }
        if (isset($this->edits["structure-merge-short"])) {
            $subtitle->mergeShortCues(new MergeShortCuesOptions($limits));
        }
        if (isset($this->edits["structure-split-long"])) {
            Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong, $limits));
        }
        if (isset($this->edits["structure-wrap"])) {
            $subtitle->wrapLines($limits->maxCharactersPerLine, $limits->maxLinesPerCue);
        }
        if (isset($this->edits["structure-merge-duplicates"])) {
            $subtitle->removeDuplicateCues();
        }

        return $subtitle;
    }
}
