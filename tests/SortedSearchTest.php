<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SortedSearchTest extends TestCase
{
    /**
     * @return array<string, array{list<float|int>, float|int, int, int}>
     */
    public static function searches(): array
    {
        return [
            "empty list"                   => [[], 1.0, 0, 0],
            "before the first value"       => [[1.0, 2.0, 3.0], 0.5, 0, 0],
            "at the first value"           => [[1.0, 2.0, 3.0], 1.0, 0, 1],
            "between 2 values"             => [[1.0, 2.0, 3.0], 2.5, 2, 2],
            "at the last value"            => [[1.0, 2.0, 3.0], 3.0, 2, 3],
            "after the last value"         => [[1.0, 2.0, 3.0], 3.5, 3, 3],
            "equal values"                 => [[1.0, 2.0, 2.0, 2.0, 3.0], 2.0, 1, 4],
            "all values equal"             => [[2.0, 2.0, 2.0], 2.0, 0, 3],
            "whole numbers"                => [[10, 20, 20, 30], 20, 1, 3],
            "whole number in a float list" => [[0.5, 1.0, 1.5], 1, 1, 2],
            "negative values"              => [[-3.0, -1.0, 0.0], -1.0, 1, 2],
        ];
    }


    /**
     * @param list<float|int> $sorted
     */
    #[DataProvider("searches")]
    public function testFirstAtOrAfter(array $sorted, float|int $value, int $atOrAfter, int $after): void
    {
        $this->assertSame($atOrAfter, SortedSearch::firstAtOrAfter($sorted, $value));
    }


    /**
     * @param list<float|int> $sorted
     */
    #[DataProvider("searches")]
    public function testFirstAfter(array $sorted, float|int $value, int $atOrAfter, int $after): void
    {
        $this->assertSame($after, SortedSearch::firstAfter($sorted, $value));
    }


    public function testFindsTheSameIndexAsALinearScanOnEveryValueOfALongList(): void
    {
        mt_srand(753);
        $sorted = [];
        for ($i = 0; $i < 500; $i++) {
            $sorted[] = mt_rand(0, 200) / 4;
        }
        sort($sorted);

        for ($value = -1.0; $value <= 51.0; $value += 0.25) {
            $atOrAfter = count(array_filter($sorted, fn (float $entry): bool => $entry < $value));
            $after     = count(array_filter($sorted, fn (float $entry): bool => $entry <= $value));
            $this->assertSame($atOrAfter, SortedSearch::firstAtOrAfter($sorted, $value), "value $value");
            $this->assertSame($after, SortedSearch::firstAfter($sorted, $value), "value $value");
        }
    }
}
