<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Timecode;

/**
 * Reads the h:mm:ss.fff variants that lenient mode repairs: 1 or 2 digit minutes and seconds below 60,
 * no fraction, or a fraction of up to 4 digits that the parser rounds to milliseconds.
 *
 * @internal
 */
final class LooseTime
{
    /**
     * Returns the seconds of a time such as "0:0:1", "0:00:01,5000" or "0:00:01:50", rounded to milliseconds, or null for another string.
     * $fractionSeparators lists the characters that can come before the fraction, for example ".,:".
     * With $hoursOptional, it also reads a time without hours, such as "07:03,920".
     */
    public static function toSeconds(string $time, string $fractionSeparators, bool $hoursOptional = false): ?float
    {
        $separators = preg_quote($fractionSeparators, '/');
        $hours      = $hoursOptional ? '(?:(0*\d{1,5}):)?' : '(0*\d{1,5}):';
        if (!preg_match('/^' . $hours . '([0-5]?\d):([0-5]?\d)(?:[' . $separators . '](\d{1,4}))?$/', $time, $matches)) {
            return null;
        }

        return Timecode::roundToMilliseconds(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4] ?? ""));
    }


    /**
     * Returns true when $time and $fraction are the 2 parts of a time that a comma split, such as "0:00:01" and "50" of "0:00:01,50".
     */
    public static function isSplitAtFraction(string $time, string $fraction): bool
    {
        return preg_match('/^\s*\d+:\d{1,2}:\d{1,2}$/', $time) === 1 && preg_match('/^\d{1,4}\s*$/', $fraction) === 1;
    }
}
