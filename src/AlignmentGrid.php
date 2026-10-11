<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * The rows and columns of the numeric keypad layout of Alignment: bottom row 1 to 3, middle row 4 to 6, top row 7 to 9.
 *
 * @internal
 */
final class AlignmentGrid
{
    public const BOTTOM_ROW = 0;
    public const MIDDLE_ROW = 1;
    public const TOP_ROW    = 2;

    public const LEFT   = 1;
    public const CENTER = 2;
    public const RIGHT  = 3;

    private const COLUMNS = 3;


    public static function of(int $row, int $column): Alignment
    {
        return Alignment::from(self::COLUMNS * $row + $column);
    }


    public static function row(Alignment $alignment): int
    {
        return intdiv($alignment->value - 1, self::COLUMNS);
    }


    public static function column(Alignment $alignment): int
    {
        return ($alignment->value - 1) % self::COLUMNS + 1;
    }
}
