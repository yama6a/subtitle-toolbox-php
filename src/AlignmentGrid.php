<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * The rows and columns of the numeric keypad layout of Alignment: bottom row 1 to 3, middle row 4 to 6, top row 7 to 9.
 * Each alignment is a screen cell of one third of the width and one third of the height. A CuePosition counts only
 * inside the cell of the cue alignment.
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


    /**
     * Tells if $position, rounded as the writers print it, lies in the screen cell of $alignment.
     */
    public static function contains(Alignment $alignment, CuePosition $position): bool
    {
        return self::columnAt(self::roundPercent($position->x)) === self::column($alignment)
            && self::rowAt(self::roundPercent($position->y)) === self::row($alignment);
    }


    /**
     * Returns the point in percent, rounded to 3 decimals, or null when it is the default point of $alignment or lies
     * outside its cell. The default point is the anchor of the TTML region that TtmlFormatter writes for the alignment:
     * 10, 50 or 90 percent on each axis. A parser stores no position for it, because the alignment says the same.
     */
    public static function positionAt(Alignment $alignment, float $x, float $y): ?CuePosition
    {
        $position  = new CuePosition(self::roundPercent($x), self::roundPercent($y));
        $isDefault = $position->x === [10.0, 50.0, 90.0][self::column($alignment) - 1] && $position->y === [90.0, 50.0, 10.0][self::row($alignment)];
        if ($isDefault || !self::contains($alignment, $position)) {
            return null;
        }

        return $position;
    }


    /**
     * Rounds a percentage from 0 to 100 to 3 decimals. floor() gives the same result on every PHP version, round() does not.
     */
    public static function roundPercent(float $value): float
    {
        return floor($value * 1000 + 0.5) / 1000;
    }


    /**
     * Returns the percentage rounded to 3 decimals without trailing zeros, such as "90" and "84.667".
     */
    public static function formatPercent(float $value): string
    {
        return rtrim(rtrim(sprintf("%.3F", self::roundPercent($value)), "0"), ".");
    }


    private static function columnAt(float $x): int
    {
        return match (true) {
            $x < 100 / 3 => self::LEFT,
            $x > 200 / 3 => self::RIGHT,
            default      => self::CENTER,
        };
    }


    private static function rowAt(float $y): int
    {
        return match (true) {
            $y < 100 / 3 => self::TOP_ROW,
            $y > 200 / 3 => self::BOTTOM_ROW,
            default      => self::MIDDLE_ROW,
        };
    }
}
