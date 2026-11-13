<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Timecode;

final class ShotChanges
{
    /**
     * Returns the sorted pts_time values of an ffmpeg log from the select and showinfo filters, in seconds.
     *
     * @return list<float>
     * @throws ParsingException when a time is not below Timecode::MAX_HOURS.
     */
    public static function fromFfmpegLog(string $log): array
    {
        $log = StringHelpers::normalizeEOLs($log);
        preg_match_all('/\bpts_time:\s*(\d+(?:\.\d+)?)/', $log, $matches, PREG_OFFSET_CAPTURE);

        $times = [];
        foreach ($matches[1] as [$text, $offset]) {
            $time = (float)$text;
            if (!Timecode::isBelowLimit($time)) {
                throw new ParsingException("The shot change time \"$text\" is not below " . Timecode::MAX_HOURS . " hours.",
                                           substr_count($log, "\n", 0, $offset) + 1);
            }
            $times[] = $time;
        }

        return self::sorted($times);
    }


    /**
     * Returns the sorted times of a text with one time per line, in seconds or as hh:mm:ss.mmm.
     *
     * @return list<float>
     * @throws ParsingException when a line holds no time, or a time that is not below Timecode::MAX_HOURS.
     */
    public static function fromText(string $text): array
    {
        $times = [];
        foreach (explode("\n", StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($text))) as $index => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            $time = null;
            if (preg_match('/^\d+(?:\.\d+)?$/', $line)) {
                $time = (float)$line;
            } elseif (preg_match('/^(\d+):([0-5]\d):([0-5]\d)(?:\.(\d+))?$/', $line, $parts)) {
                $time = Timecode::toSeconds((int) $parts[1], (int) $parts[2], (int) $parts[3], $parts[4] ?? "");
            }
            if ($time !== null && Timecode::isBelowLimit($time)) {
                $times[] = $time;
            } else {
                throw new ParsingException("The shot change time \"$line\" is not valid. Use seconds or hh:mm:ss.mmm.",
                                           $index + 1);
            }
        }

        return self::sorted($times);
    }


    /**
     * @param list<float> $times
     * @return list<float>
     */
    private static function sorted(array $times): array
    {
        sort($times);

        return array_values(array_unique($times, SORT_REGULAR));
    }
}
