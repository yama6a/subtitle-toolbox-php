<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class TimingFixEdit extends Edit
{
    private function __construct(
        private readonly bool $overlaps,
        private readonly ?float $minDuration,
        private readonly ?float $minGap,
        private readonly ?float $leadIn,
        private readonly ?float $leadOut,
        private readonly ?float $maxDuration,
    ) {
    }


    public static function group(): string
    {
        return "timing";
    }


    public static function summary(): string
    {
        return "Fix overlaps, short cues and long cues, and add lead-in and lead-out.";
    }


    public static function options(): array
    {
        return [
            Option::flag("timing-fix-overlaps", "End each cue at least --timing-min-gap seconds before the next cue starts."),
            Option::value("timing-min-duration", "SECONDS", "Show each cue for at least this time where the next cue allows it."),
            Option::value("timing-lead-in", "SECONDS", "Start each cue this time earlier where the previous cue allows it."),
            Option::value("timing-lead-out", "SECONDS", "End each cue this time later where the next cue allows it. The lead-out comes before the lead-in."),
            Option::value("timing-max-duration", "SECONDS", "End each cue at most this time after its start. Runs after the other timing fixes."),
            Option::value("timing-min-gap", "SECONDS", "Gap between cues for --timing-fix-overlaps, --timing-min-duration and the lead options. Default: 0."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needsOneOf($arguments, ["timing-fix-overlaps", "timing-min-duration", "timing-lead-in", "timing-lead-out"], "timing-min-gap");
        $minDuration = $arguments->positiveSeconds("timing-min-duration");
        $minGap      = $arguments->nonNegativeSeconds("timing-min-gap");
        $leadIn      = $arguments->nonNegativeSeconds("timing-lead-in");
        $leadOut     = $arguments->nonNegativeSeconds("timing-lead-out");
        $maxDuration = $arguments->positiveSeconds("timing-max-duration");
        if (!$arguments->has("timing-fix-overlaps") && $minDuration === null && $leadIn === null && $leadOut === null && $maxDuration === null) {
            return null;
        }

        return new self($arguments->has("timing-fix-overlaps"), $minDuration, $minGap, $leadIn, $leadOut, $maxDuration);
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->overlaps) {
            $subtitle->fixOverlaps(...Command::withoutNulls(["minGap" => $this->minGap]));
        }
        if ($this->minDuration !== null) {
            $subtitle->extendShortCues($this->minDuration, ...Command::withoutNulls(["minGap" => $this->minGap]));
        }
        if ($this->leadIn !== null || $this->leadOut !== null) {
            $subtitle->addLeadInOut($this->leadIn ?? 0, $this->leadOut ?? 0, ...Command::withoutNulls(["minGap" => $this->minGap]));
        }
        if ($this->maxDuration !== null) {
            $subtitle->limitLongCues($this->maxDuration);
        }

        return $subtitle;
    }
}
