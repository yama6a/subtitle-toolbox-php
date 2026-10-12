<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Turns timecode strings into seconds. The other members are internal helpers of the library.
 */
final class Timecode
{
    /**
     * Comparisons of computed seconds and scores add this margin, so that float rounding errors do not change the result.
     *
     * @internal
     */
    public const EPSILON = 1e-9;

    private const SECONDS_PER_MINUTE = 60;

    /**
     * The parsers, the Matroska reader, parse() and the CLI time options reject a time from this many hours.
     * Formatters split cue times into integer milliseconds, which overflow far above this bound.
     *
     * @internal
     */
    public const MAX_HOURS = 100000;

    private const CLOCK_REGEX  = '/^(?:(\d+):([0-5]\d)|(\d+)):([0-5]\d)(?:[.,](\d+))?$/';
    private const FRAMES_REGEX = '/^(\d+):([0-5]\d):([0-5]\d):(\d{2,})$/';

    // Drop-frame time code keeps every label in each tenth minute and drops labels in the other 9 minutes.
    private const DROP_CYCLE_MINUTES = 10;
    private const DROPPING_MINUTES   = self::DROP_CYCLE_MINUTES - 1;

    // A dropping minute skips 1 label for each 15 labels per second: 2 labels at 30, 4 at 60.
    private const LABELS_PER_DROPPED_LABEL = 15;

    /**
     * Returns the seconds of a timecode string. It accepts 3 shapes:
     * - h:mm:ss with an optional fraction after a period or a comma, for example 00:01:02,500 or 0:01:02.5.
     * - m:ss with an optional fraction, for example 01:02.5. The minutes can be 60 or more.
     * - h:mm:ss:ff, where ff counts frames after the last whole second. This shape needs $frameRate.
     * Minutes and seconds after a colon have 2 digits from 00 to 59. Signs, spaces and drop-frame timecodes are invalid.
     *
     * @throws InvalidArgumentException when $timecode has another shape, reaches MAX_HOURS, or counts frames without $frameRate.
     */
    public static function parse(string $timecode, ?FrameRate $frameRate = null): float
    {
        $seconds = self::parseOrReason($timecode, $frameRate);
        if (is_float($seconds)) {
            return $seconds;
        }

        throw new InvalidArgumentException("The timecode \"$timecode\" " . ($seconds ?? "is not h:mm:ss.mmm, m:ss.mmm or h:mm:ss:ff") . ".");
    }


    /**
     * Returns the seconds of a timecode string as parse() does. For a string of a valid shape that parse() rejects,
     * it returns the reason, such as "is not below 100000 hours". For a string of another shape, it returns null.
     *
     * @internal
     */
    public static function parseOrReason(string $timecode, ?FrameRate $frameRate): float|string|null
    {
        if (preg_match(self::CLOCK_REGEX, $timecode, $matches)) {
            $hours   = $matches[1] === "" ? "0" : $matches[1];
            $minutes = $matches[1] === "" ? $matches[3] : $matches[2];
            if ((float) $hours * 3600 + (float) $minutes * 60 < self::MAX_HOURS * 3600) {
                return self::toSeconds((int) $hours, (int) $minutes, (int) $matches[4], $matches[5] ?? "");
            }

            return "is not below " . self::MAX_HOURS . " hours";
        }
        if (preg_match(self::FRAMES_REGEX, $timecode, $matches)) {
            $labels = $frameRate === null ? null : self::labels($frameRate);

            return match (true) {
                $labels === null                       => "counts frames and needs a frame rate",
                (float) $matches[1] >= self::MAX_HOURS => "is not below " . self::MAX_HOURS . " hours",
                (float) $matches[4] >= $labels         => "has a frame number that is not below $labels",
                default                                => self::toSecondsFromFrames((int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[4], $frameRate),
            };
        }

        return null;
    }


    /**
     * @return array{int, int, int} hours, minutes, seconds
     * @internal
     */
    public static function seconds(float $seconds): array
    {
        return array_slice(self::split((int) round($seconds), 1), 0, 3);
    }


    /**
     * @return array{int, int, int, int} hours, minutes, seconds, centiseconds
     * @internal
     */
    public static function centiseconds(float $seconds): array
    {
        return self::split((int) round($seconds * 100), 100);
    }


    /**
     * @return array{int, int, int, int} hours, minutes, seconds, milliseconds
     * @internal
     */
    public static function milliseconds(float $seconds): array
    {
        return self::split(self::totalMilliseconds($seconds), 1000);
    }


    /**
     * Rounds seconds to whole milliseconds, the precision of cue times. For example 0.8333 becomes 0.833.
     *
     * @internal
     */
    public static function roundToMilliseconds(float $seconds): float
    {
        return round($seconds, 3);
    }


    /**
     * @internal
     */
    public static function totalMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }


    /**
     * Returns the SMPTE time code of the frame nearest to $seconds.
     *
     * @return array{int, int, int, int} hours, minutes, seconds, frames
     * @throws InvalidArgumentException when $dropFrame is true and the frame rate is not about 29.97 or 59.94 fps.
     * @internal
     */
    public static function frames(float $seconds, FrameRate $frameRate, bool $dropFrame = false): array
    {
        return self::frameNumber($frameRate->secondsToFrames($seconds), $frameRate, $dropFrame);
    }


    /**
     * Returns the SMPTE time code of a frame number.
     * A second holds the frame rate rounded to a whole number of frame labels, so 23.976 fps counts 24 labels.
     * Drop-frame time code skips the first labels of each minute except every tenth minute.
     * So it stays in step with the clock. It skips 2 labels at 29.97 fps and 4 at 59.94 fps.
     *
     * @return array{int, int, int, int} hours, minutes, seconds, frames
     * @throws InvalidArgumentException when $dropFrame is true and the frame rate is not about 29.97 or 59.94 fps.
     * @internal
     */
    public static function frameNumber(int $frame, FrameRate $frameRate, bool $dropFrame = false): array
    {
        $labels = self::labels($frameRate);
        if ($dropFrame) {
            if ($labels !== 30 && $labels !== 60) {
                throw new InvalidArgumentException("Drop-frame time code needs 29.97 or 59.94 fps, got {$frameRate->getFramesPerSecond()}.");
            }

            $dropped   = intdiv($labels, self::LABELS_PER_DROPPED_LABEL);
            $perCycle  = self::DROP_CYCLE_MINUTES * self::SECONDS_PER_MINUTE * $labels - self::DROPPING_MINUTES * $dropped;
            $perMinute = self::SECONDS_PER_MINUTE * $labels - $dropped;
            $rest      = $frame % $perCycle;
            $frame    += self::DROPPING_MINUTES * $dropped * intdiv($frame, $perCycle)
                         + ($rest >= $dropped ? $dropped * intdiv($rest - $dropped, $perMinute) : 0);
        }

        return self::split($frame, $labels);
    }


    /**
     * Returns whole clock seconds and the frames after the last whole second.
     * The frame count restarts at each clock second. At a whole frame rate the result equals frames().
     *
     * @return array{int, int, int, int} hours, minutes, seconds, frames
     * @internal
     */
    public static function clockSecondsAndFrames(float $seconds, FrameRate $frameRate): array
    {
        $milliseconds = max(0, self::totalMilliseconds($seconds));
        $whole        = intdiv($milliseconds, 1000);
        $frame        = $frameRate->secondsToFrames($milliseconds % 1000 / 1000);
        if ($frame >= self::labels($frameRate)) {
            $whole++;
            $frame = 0;
        }

        return [...self::seconds($whole), $frame];
    }


    /**
     * Returns the seconds of a time in parts. $fraction holds the decimal digits after the point.
     * So "5" adds 0.5 s and "005" adds 0.005 s. The result is the float nearest to the decimal value.
     *
     * @internal
     */
    public static function toSeconds(int $hours, int $minutes, int $seconds, string $fraction = ""): float
    {
        $whole = $hours * 3600 + $minutes * 60 + $seconds;

        return $fraction === "" ? (float) $whole : (float) "$whole.$fraction";
    }


    /**
     * Returns the seconds of a time code that counts frames after the last whole second.
     * For example, 00:00:01:12 at 25 fps is 1.48 s.
     *
     * @internal
     */
    public static function toSecondsFromFrames(int $hours, int $minutes, int $seconds, int $frames, FrameRate $frameRate): float
    {
        return $hours * 3600 + $minutes * 60 + $seconds + $frameRate->framesToSeconds($frames);
    }


    /**
     * Formats the whole seconds as m:ss below 1 hour and as h:mm:ss from 1 hour.
     * For example, 62.9 becomes "1:02" and 3725 becomes "1:02:05".
     *
     * @internal
     */
    public static function shortClock(float $seconds): string
    {
        [$hours, $minutes, $wholeSeconds] = self::seconds(floor($seconds));

        return $hours > 0 ? sprintf("%d:%02d:%02d", $hours, $minutes, $wholeSeconds) : sprintf("%d:%02d", $minutes, $wholeSeconds);
    }


    /**
     * Returns the frame labels in one second: the frame rate rounded to a whole number, so 23.976 fps gives 24.
     */
    private static function labels(FrameRate $frameRate): int
    {
        return (int) round($frameRate->getFramesPerSecond());
    }


    /**
     * @return array{int, int, int, int}
     */
    private static function split(int $total, int $perSecond): array
    {
        // SubtitleCue accepts a negative time, but a time code has no sign, so it starts at 0.
        $total   = max(0, $total);
        $seconds = intdiv($total, $perSecond);

        return [intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60, $total % $perSecond];
    }
}
