<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Checks the values of options classes. NAN and INF fail each number check, because a comparison such as NAN < 0 is
 * false. A failed check throws InvalidArgumentException with $message, where %s becomes the value.
 *
 * @internal
 */
final class OptionChecks
{
    public static function positiveFinite(float $value, string $message): void
    {
        if (!self::isPositiveFinite($value)) {
            self::fail($message, $value);
        }
    }


    public static function nonNegativeFinite(float $value, string $message): void
    {
        if (!self::isNonNegativeFinite($value)) {
            self::fail($message, $value);
        }
    }


    /**
     * Accepts INF, for a limit that INF turns off.
     */
    public static function notNegative(float $value, string $message): void
    {
        if (!($value >= 0)) {
            self::fail($message, $value);
        }
    }


    public static function between(float $value, float $minimum, float $maximum, string $message): void
    {
        if (!($value >= $minimum && $value <= $maximum)) {
            self::fail($message, $value);
        }
    }


    public static function finite(float $value, string $message): void
    {
        if (!is_finite($value)) {
            self::fail($message, $value);
        }
    }


    public static function notBlank(string $value, string $message): void
    {
        if (trim($value) === "") {
            throw new InvalidArgumentException($message);
        }
    }


    public static function isPositiveFinite(float $value): bool
    {
        return is_finite($value) && $value > 0;
    }


    public static function isNonNegativeFinite(float $value): bool
    {
        return is_finite($value) && $value >= 0;
    }


    // PHP 8.5 warns when a string holds NAN.
    public static function text(float $value): string
    {
        return is_nan($value) ? "NAN" : (string) $value;
    }


    private static function fail(string $message, float $value): never
    {
        throw new InvalidArgumentException(str_replace("%s", self::text($value), $message));
    }
}
