<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Timing\LeadInOut;

/**
 * @internal
 */
trait Fixes
{
    /**
     * Moves the end of each cue to at least $minGap seconds before the start of the next cue with a later start.
     * The end never moves before the start of its own cue.
     */
    public function fixOverlaps(float $minGap = 0): self
    {
        $this->fixesAssertGap($minGap);

        $groupStart = null;
        $laterStart = null;
        foreach (array_reverse($this->getCues()) as $cue) {
            if ($cue->getStart() !== $groupStart) {
                $laterStart = $groupStart;
                $groupStart = $cue->getStart();
            }

            if ($laterStart === null) {
                continue;
            }

            $latestEnd = Timecode::roundToMilliseconds($laterStart - $minGap);
            if ($cue->getEnd() > $latestEnd) {
                $cue->setEnd(max($cue->getStart(), $latestEnd));
            }
        }

        return $this;
    }


    /**
     * Makes each cue last at least $minDuration seconds, but ends it at least $minGap seconds before the next cue starts.
     */
    public function extendShortCues(float $minDuration, float $minGap = 0): self
    {
        OptionChecks::positiveFinite($minDuration, "The minimum duration must be greater than 0, got %s.");
        $this->fixesAssertGap($minGap);

        $cues = $this->getCues();
        foreach ($cues as $index => $cue) {
            $end = $cue->getStart() + $minDuration;
            if (isset($cues[$index + 1])) {
                $end = min($end, $cues[$index + 1]->getStart() - $minGap);
            }

            $hasSameStartAsPrevious = isset($cues[$index - 1]) && $cues[$index - 1]->getStart() === $cue->getStart();
            if (!$hasSameStartAsPrevious && Timecode::roundToMilliseconds($end) > $cue->getEnd()) {
                $cue->setEnd($end);
            }
        }

        return $this;
    }


    /**
     * Shows each cue $leadIn seconds earlier and $leadOut seconds longer, but at least $minGap seconds away from its neighbours.
     * The lead-out comes first, so it takes the space between two cues before the lead-in of the next cue.
     * A start or end inside another cue stays where it is.
     */
    public function addLeadInOut(float $leadIn, float $leadOut, float $minGap = 0): self
    {
        OptionChecks::nonNegativeFinite($leadIn, "The lead-in must not be negative, got %s.");
        OptionChecks::nonNegativeFinite($leadOut, "The lead-out must not be negative, got %s.");
        $this->fixesAssertGap($minGap);

        LeadInOut::apply($this->getCues(), $leadIn, $leadOut, $minGap);

        return $this;
    }


    /**
     * Ends each cue that lasts longer than $maxDuration seconds at $maxDuration seconds after its start.
     */
    public function limitLongCues(float $maxDuration): self
    {
        OptionChecks::positiveFinite($maxDuration, "The maximum duration must be greater than 0, got %s.");

        foreach ($this->cues as $cue) {
            $end = Timecode::roundToMilliseconds($cue->getStart() + $maxDuration);
            if ($end < $cue->getEnd()) {
                $cue->setEnd($end);
            }
        }

        return $this;
    }


    /**
     * Breaks the lines of each cue that has a line longer than $maxCharactersPerLine or more than $maxLinesPerCue lines.
     * Each dialogue turn keeps lines of its own.
     */
    public function wrapLines(int $maxCharactersPerLine, int $maxLinesPerCue = 2): self
    {
        if ($maxCharactersPerLine < 1 || $maxLinesPerCue < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharactersPerLine and $maxLinesPerCue.");
        }

        foreach ($this->getCues() as $cue) {
            if (!LineWrapper::fits($cue->getLines(), $maxCharactersPerLine, $maxLinesPerCue)) {
                $cue->setLines(LineWrapper::wrapTurns($cue->getLines(), $maxCharactersPerLine, $maxLinesPerCue));
            }
        }

        return $this;
    }


    /**
     * Joins the lines of each cue with a space. Each dialogue turn that starts with a dash line keeps a line of its own.
     */
    public function unwrapLines(): self
    {
        foreach ($this->getCues() as $cue) {
            $cue->setLines(LineWrapper::unwrapTurns($cue->getLines()));
        }

        return $this;
    }


    private function fixesAssertGap(float $minGap): void
    {
        OptionChecks::nonNegativeFinite($minGap, "The minimum gap must not be negative, got %s.");
    }
}
