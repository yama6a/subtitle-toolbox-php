<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Timecode;

/**
 * Reads TTML time expressions with the timing parameters of one document.
 *
 * @see https://www.w3.org/TR/ttml2/#timing-value-time-expression
 *
 * @internal
 */
final readonly class TtmlClock
{
    /**
     * @param float  $frameRate             frames per second, ttp:frameRate with the multiplier
     * @param float  $smpteFrameRate        frame labels per second of an SMPTE time code, ttp:frameRate without the multiplier
     * @param bool   $smpteTimeBase         true for ttp:timeBase="smpte"
     * @param string $dropMode              ttp:dropMode: "dropNTSC", "dropPAL" or "nonDrop"
     * @param float  $subFrameRate          sub-frames per frame
     * @param float  $tickRate              ticks per second
     * @param bool   $lastFieldIsHundredths true when lenient mode reads the last field of hh:mm:ss:ff as hundredths of a second
     */
    public function __construct(
        private float $frameRate = 30,
        private float $smpteFrameRate = 30,
        private bool $smpteTimeBase = false,
        private string $dropMode = "nonDrop",
        private float $subFrameRate = 1,
        private float $tickRate = 1,
        private bool $lastFieldIsHundredths = false,
    ) {
    }


    /**
     * Returns the seconds of a clock time or an offset time, with no upper bound.
     */
    public function seconds(string $expression): float
    {
        $expression = trim($expression);
        // Some tools write a comma as decimal separator, for example 00:00:01,500.
        if (preg_match("/^(\d{2,}):(\d{2}):(\d{2})(?:[.,](\d+)|:(\d{2})(?:\.(\d+))?)?$/", $expression, $matches)) {
            $seconds = Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4] ?? "");
            if (($matches[4] ?? "") !== "") {
                return $seconds;
            }
            if ($this->lastFieldIsHundredths && ($matches[5] ?? "") !== "") {
                return $seconds + (int) $matches[5] / 100;
            }
            $frames = ($matches[5] ?? "") === "" ? 0 : (int) $matches[5];
            $frames += ($matches[6] ?? "") === "" ? 0 : (int) $matches[6] / $this->subFrameRate;
            if ($this->smpteTimeBase) {
                return ($seconds * $this->smpteFrameRate + $frames - $this->droppedFrames((int) $matches[1], (int) $matches[2]))
                       / $this->frameRate;
            }

            return $seconds + $frames / $this->frameRate;
        }

        if (preg_match("/^(\d+(?:\.\d+)?)(h|ms|m|s|f|t)$/", $expression, $matches)) {
            $count = (float) $matches[1];

            return match ($matches[2]) {
                "h"  => $count * 3600,
                "m"  => $count * 60,
                "s"  => $count,
                "ms" => $count / 1000,
                "f"  => $count / $this->frameRate,
                "t"  => $count / $this->tickRate,
            };
        }

        throw new ParsingException("The time expression \"$expression\" is not valid.");
    }


    /**
     * Reads the time expressions that TTML does not allow but some tools write:
     * one-digit clock fields, m:ss without hours, hh:mm:ss:fff with milliseconds, a bare integer of milliseconds,
     * and a bare number with a fraction, which is in seconds.
     */
    public static function looseSeconds(string $expression): ?float
    {
        return match (true) {
            (bool) preg_match("/^(\d+):(\d{1,2}):(\d{1,2})(?:[.,](\d+))?$/", $expression, $matches)
                => Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4] ?? ""),
            (bool) preg_match("/^(\d+):(\d{1,2}):(\d{1,2}):(\d{3})$/", $expression, $matches)
                => Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]),
            (bool) preg_match("/^(\d{1,2}):(\d{1,2})(?:[.,](\d+))?$/", $expression, $matches)
                => Timecode::toSeconds(0, (int) $matches[1], (int) $matches[2], $matches[3] ?? ""),
            (bool) preg_match("/^\d{1,15}$/", $expression)
                => (int) $expression / 1000,
            (bool) preg_match("/^\d{1,15}\.\d{1,9}$/", $expression)
                => (float) $expression,
            default => null,
        };
    }


    /**
     * Counts the frame labels that drop-frame time code skips before hh:mm:00, as in SMPTE ST 12-1.
     *
     * @see https://www.w3.org/TR/ttml1/#time-expression-semantics-smpte
     */
    private function droppedFrames(int $hours, int $minutes): int
    {
        return match ($this->dropMode) {
            "dropNTSC" => ($hours * 54 + $minutes - intdiv($minutes, 10)) * 2,
            "dropPAL"  => ($hours * 27 + intdiv($minutes, 2) - intdiv($minutes, 20)) * 4,
            default    => 0,
        };
    }
}
