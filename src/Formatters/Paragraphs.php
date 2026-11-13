<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

/**
 * @internal
 */
final class Paragraphs
{
    /**
     * Groups timed items into paragraphs. A gap of $gap seconds after the latest end so far starts a new paragraph.
     * $startsNew(previous value, value) can start one too.
     *
     * @param iterable<array{float, float, mixed}> $items start, end and value of each item, in time order
     * @param (callable(mixed, mixed): bool)|null $startsNew
     *
     * @return list<array{start: float, values: list<mixed>}>
     */
    public static function byGap(iterable $items, float $gap, ?callable $startsNew = null): array
    {
        $paragraphs = [];
        $latestEnd  = null;
        $previous   = null;
        foreach ($items as [$start, $end, $value]) {
            if ($latestEnd === null || round($start - $latestEnd, 3) >= $gap || ($startsNew !== null && $startsNew($previous, $value))) {
                $paragraphs[] = ["start" => $start, "values" => []];
            }
            $paragraphs[count($paragraphs) - 1]["values"][] = $value;
            $latestEnd = max($latestEnd ?? $end, $end);
            $previous  = $value;
        }

        return $paragraphs;
    }
}
