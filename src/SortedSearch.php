<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * Binary searches in a list of numbers in ascending order. Each returns count($sorted) when no value matches.
 *
 * @internal
 */
final class SortedSearch
{
    /**
     * Returns the index of the first value at or after $value.
     *
     * @param list<float|int> $sorted
     */
    public static function firstAtOrAfter(array $sorted, float|int $value): int
    {
        return self::search($sorted, fn (float|int $entry): bool => $entry < $value);
    }


    /**
     * Returns the index of the first value after $value.
     *
     * @param list<float|int> $sorted
     */
    public static function firstAfter(array $sorted, float|int $value): int
    {
        return self::search($sorted, fn (float|int $entry): bool => $entry <= $value);
    }


    /**
     * @param list<float|int>           $sorted
     * @param callable(float|int): bool $isBefore true for the values before the result, false from the result on
     */
    private static function search(array $sorted, callable $isBefore): int
    {
        $low  = 0;
        $high = count($sorted);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($isBefore($sorted[$middle])) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
