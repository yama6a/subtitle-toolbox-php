<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SpeechReference
{
    /**
     * Returns a reference with one cue without text for the speech between the silences of an ffmpeg silencedetect log.
     * A time below the time before it counts as that time, so the cues never overlap.
     *
     * @throws InvalidArgumentException when $mediaDuration is not above 0 or not below Timecode::MAX_HOURS.
     * @throws ParsingException when the log has one line per channel or its silence_start and silence_end lines do not alternate.
     */
    public static function fromFfmpegSilencedetect(string $log, float $mediaDuration): Subtitle
    {
        OptionChecks::positiveFinite($mediaDuration, "The media duration must be greater than 0, got %s.");
        if (!Timecode::isBelowLimit($mediaDuration)) {
            throw new InvalidArgumentException("The media duration must be below " . Timecode::MAX_HOURS . " hours, got $mediaDuration s.");
        }

        $intervals    = [];
        $speechStart  = 0.0;
        $silenceStart = null;
        $previous     = 0.0;
        foreach (explode("\n", StringHelpers::normalizeEOLs($log)) as $index => $line) {
            if (!preg_match('/\bsilence_(start|end):\s*(-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)/', $line, $match)) {
                continue;
            }

            if (str_contains($line, "channel:")) {
                throw new ParsingException("Cannot read a silencedetect log with one line per channel. Run silencedetect without mono.",
                                           $index + 1);
            }

            $time     = min($mediaDuration, max($previous, (float)$match[2]));
            $previous = $time;
            if ($match[1] === "start" && $silenceStart === null) {
                $intervals[]  = [$speechStart, $time];
                $silenceStart = $time;
            } elseif ($match[1] === "end" && $silenceStart !== null) {
                $speechStart  = $time;
                $silenceStart = null;
            } else {
                throw new ParsingException("The line silence_$match[1] is out of order. The silence_start and silence_end lines must alternate.",
                                           $index + 1);
            }
        }

        if ($silenceStart === null) {
            $intervals[] = [$speechStart, $mediaDuration];
        }

        return self::fromIntervals(array_values(array_filter($intervals, fn (array $interval): bool => $interval[1] > $interval[0])));
    }


    /**
     * Returns a reference with one cue without text for each [start, end] pair in seconds from a voice activity detector.
     *
     * @param list<array{float|int, float|int}> $intervals
     * @throws InvalidArgumentException when an entry is not [start, end] with 0 <= start <= end, or ends at Timecode::MAX_HOURS or later.
     */
    public static function fromIntervals(array $intervals): Subtitle
    {
        $subtitle = new Subtitle();
        $cues     = [];
        foreach ($intervals as $index => $interval) {
            if (!is_array($interval) || !array_is_list($interval) || count($interval) !== 2 ||
                !is_numeric($interval[0]) || !is_numeric($interval[1]) || $interval[0] < 0 || $interval[1] < $interval[0]) {
                throw new InvalidArgumentException("Interval $index must be [start, end] in seconds with 0 <= start <= end.");
            }
            if (!Timecode::isBelowLimit((float)$interval[1])) {
                throw new InvalidArgumentException(
                    "Interval $index ends at " . OptionChecks::text((float)$interval[1]) . " s. Cue times must be below " . Timecode::MAX_HOURS . " hours."
                );
            }

            $cues[] = new SubtitleCue((float)$interval[0], (float)$interval[1]);
        }

        return $subtitle->addCues($cues);
    }
}
