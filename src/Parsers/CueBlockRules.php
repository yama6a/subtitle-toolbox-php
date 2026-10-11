<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Closure;

/**
 * The rules that split a block of non-empty lines into cues, for the formats that SubtitleParser splits at empty lines.
 *
 * @internal
 */
final readonly class CueBlockRules
{
    /**
     * @param Closure(string): bool $isTimingLine  returns true for the timing line of a cue
     * @param bool                  $hasCueNumbers true for a format with a cue number line before the timing line, such as SubRip
     */
    public function __construct(
        private Closure $isTimingLine,
        private bool $hasCueNumbers,
    ) {
    }


    /**
     * Splits a block before each timing line, and before the cue number line in front of it.
     * Each part is keyed by its 0-based offset in the block.
     * A part that starts at a timing line has no cue number.
     *
     * @param list<string> $block
     *
     * @return array<int, list<string>>
     */
    public function splitAtTimingLines(array $block): array
    {
        $starts     = [0];
        $lastTiming = null;
        foreach ($block as $offset => $line) {
            if (!($this->isTimingLine)($line)) {
                continue;
            }

            $start = $offset;
            if ($this->hasCueNumbers && $offset > 0 && is_numeric($block[$offset - 1])
                && ($lastTiming === null || $offset - 1 > $lastTiming)) {
                $start = $offset - 1;
            }
            if ($start > 0) {
                $starts[] = $start;
            }
            $lastTiming = $offset;
        }

        $parts = [];
        foreach ($starts as $i => $start) {
            $end = $starts[$i + 1] ?? count($block);
            if ($end > $start) {
                $parts[$start] = array_slice($block, $start, $end - $start);
            }
        }

        return $parts;
    }


    /**
     * Returns true for a block of cue text: no timing line, and no cue number or time on its first line.
     * A first line that looks like a broken time keeps the block apart, so the parser still skips it with a warning.
     *
     * @param list<string> $block
     */
    public function isCueTextBlock(array $block): bool
    {
        return !$this->hasTimingLine($block)
            && !($this->hasCueNumbers && is_numeric($block[0]))
            && preg_match('/^\d+\s*[:：]\d|-+>/u', $block[0]) !== 1;
    }


    /**
     * @param list<string> $block
     */
    public function hasTimingLine(array $block): bool
    {
        return array_filter($block, $this->isTimingLine) !== [];
    }
}
