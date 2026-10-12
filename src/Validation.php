<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Validation\TextChecks;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\Validation\ValidationViolation;

/**
 * @internal
 */
trait Validation
{
    /**
     * Checks every cue against the rules that are on and returns one violation per broken rule.
     *
     * @return list<ValidationViolation>
     */
    public function validate(ValidationRules $rules): array
    {
        $violations  = [];
        $previousEnd = null;

        if ($rules->requireCues && $this->getCues() === []) {
            $violations[] = new ValidationViolation(null, ValidationRule::RequireCues, 0, null);
        }

        foreach ($this->getCues() as $cueIndex => $cue) {
            $lineLengths = LineWrapper::visibleLineLengths($cue->getLines());
            $characters  = array_sum($lineLengths);
            $duration    = Timecode::roundToMilliseconds($cue->getEnd() - $cue->getStart());

            if ($rules->noNegativeDuration && $duration < 0) {
                $violations[] = new ValidationViolation($cueIndex, ValidationRule::NoNegativeDuration, $duration, null);
            }

            array_push($violations, ...self::validationTextViolations($cueIndex, $lineLengths, $rules),
                                 ...self::validationTimingViolations($cueIndex, $characters, $duration, $rules),
                                 ...TextChecks::check($cueIndex, $cue, $duration, $rules),
                                 ...self::validationGapViolations($cueIndex, $cue->getStart(), $previousEnd, $rules));
            $previousEnd = max($previousEnd ?? $cue->getEnd(), $cue->getEnd());
        }

        return $violations;
    }


    /**
     * Checks the empty cue, the line length and the line count rules.
     *
     * @param list<int> $lineLengths
     * @return list<ValidationViolation>
     */
    private static function validationTextViolations(int $cueIndex, array $lineLengths, ValidationRules $rules): array
    {
        $violations = [];
        if ($rules->noEmptyCues && array_sum($lineLengths) === 0) {
            $violations[] = new ValidationViolation($cueIndex, ValidationRule::NoEmptyCues, 0, null);
        }

        if ($rules->maxCharactersPerLine !== null) {
            foreach ($lineLengths as $length) {
                if ($length > $rules->maxCharactersPerLine) {
                    $violations[] = new ValidationViolation($cueIndex, ValidationRule::MaxCharactersPerLine,
                                                      $length, $rules->maxCharactersPerLine);
                }
            }
        }

        if ($rules->maxLinesPerCue !== null && count($lineLengths) > $rules->maxLinesPerCue) {
            $violations[] = new ValidationViolation($cueIndex, ValidationRule::MaxLinesPerCue,
                                              count($lineLengths), $rules->maxLinesPerCue);
        }

        return $violations;
    }


    /**
     * Checks the reading speed and the duration rules.
     *
     * @return list<ValidationViolation>
     */
    private static function validationTimingViolations(int $cueIndex, int $characters, float $duration, ValidationRules $rules): array
    {
        $violations = [];
        if ($rules->maxCharactersPerSecond !== null && $characters > 0) {
            $charactersPerSecond = LineWrapper::charactersPerSecond($characters, $duration);
            if ($charactersPerSecond > $rules->maxCharactersPerSecond) {
                $violations[] = new ValidationViolation($cueIndex, ValidationRule::MaxCharactersPerSecond,
                                                  $charactersPerSecond, $rules->maxCharactersPerSecond);
            }
        }

        // Cue times have millisecond precision, so a limit such as 5/6 s must match a cue of 0.833 s.
        if ($rules->minDuration !== null && $duration < Timecode::roundToMilliseconds($rules->minDuration)) {
            $violations[] = new ValidationViolation($cueIndex, ValidationRule::MinDuration,
                                              $duration, $rules->minDuration);
        }

        if ($rules->maxDuration !== null && $duration > Timecode::roundToMilliseconds($rules->maxDuration)) {
            $violations[] = new ValidationViolation($cueIndex, ValidationRule::MaxDuration,
                                              $duration, $rules->maxDuration);
        }

        return $violations;
    }


    /**
     * Checks the overlap and the gap rules against the latest end of the cues before.
     *
     * @return list<ValidationViolation>
     */
    private static function validationGapViolations(int $cueIndex, float $start, ?float $previousEnd, ValidationRules $rules): array
    {
        if ($previousEnd === null) {
            return [];
        }

        $violations = [];
        $gap        = Timecode::roundToMilliseconds($start - $previousEnd);
        if ($rules->noOverlap && $gap < 0) {
            $violations[] = new ValidationViolation($cueIndex, ValidationRule::NoOverlap, -$gap, null);
        }

        if ($rules->minGap !== null && $gap >= 0 && $gap < Timecode::roundToMilliseconds($rules->minGap)) {
            $violations[] = new ValidationViolation($cueIndex, ValidationRule::MinGap, $gap, $rules->minGap);
        }

        return $violations;
    }
}
