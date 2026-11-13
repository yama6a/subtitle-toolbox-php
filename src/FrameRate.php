<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\UnwritableContentException;

final class FrameRate
{
    private float $framesPerSecond;


    public function __construct(float $framesPerSecond)
    {
        self::check($framesPerSecond);

        $this->framesPerSecond = $framesPerSecond;
    }


    /**
     * Throws InvalidArgumentException with $message unless $framesPerSecond is finite and greater than 0.
     * %s in $message becomes the value.
     *
     * @internal
     */
    public static function check(float $framesPerSecond, string $message = "The frame rate must be greater than 0, got %s."): void
    {
        OptionChecks::positiveFinite($framesPerSecond, $message);
    }


    public function getFramesPerSecond(): float
    {
        return $this->framesPerSecond;
    }


    public function framesToSeconds(int $frames): float
    {
        return $frames / $this->framesPerSecond;
    }


    /**
     * Rounds to the nearest frame. A time on an exact half frame rounds away from zero, so 0.58 s at 25 fps is frame 15.
     * The time counts in whole milliseconds, and the product rounds to 9 decimals first.
     * So the float error of 0.58 * 25 = 14.499999999999998 does not decide the frame.
     *
     * @throws UnwritableContentException when the frame number is larger than the largest integer.
     */
    public function secondsToFrames(float $seconds): int
    {
        $milliseconds = round(Timecode::roundToMilliseconds($seconds) * 1000);
        $frames       = round(round($milliseconds * $this->framesPerSecond / 1000, 9));
        if (!(abs($frames) < PHP_INT_MAX)) {
            throw new UnwritableContentException(
                "The time $seconds s is frame $frames at $this->framesPerSecond fps. A frame number must be below " . PHP_INT_MAX . "."
            );
        }

        return (int) $frames;
    }


    /**
     * Returns the frame nearest to $seconds, for a writer that stores times as frame numbers.
     * The frame stays between 0 and the last frame whose time is below Timecode::MAX_HOURS.
     * So at 25 fps the time 359999999.999 s is frame 8999999999, which reads back, not 9000000000.
     * Use it in every writer that writes frame numbers. Timecode::unitsBelowLimit() is the same rule for fixed units.
     *
     * @throws UnwritableContentException when the frame number is larger than the largest integer.
     * @internal
     */
    public function framesBelowLimit(float $seconds): int
    {
        $frames = $this->secondsToFrames(min(max(0.0, $seconds), Timecode::LAST_TIME));
        $last   = floor(Timecode::LAST_TIME * $this->framesPerSecond);

        return $frames < $last ? $frames : (int) $last;
    }
}
