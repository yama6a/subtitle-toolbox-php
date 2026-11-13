<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnwritableContentException;

class FrameRateTest extends \PHPUnit\Framework\TestCase
{
    public function testFramesToSeconds(): void
    {
        $this->assertSame(10.0, (new FrameRate(25))->framesToSeconds(250));
        $this->assertSame(41.708, round((new FrameRate(23.976))->framesToSeconds(1000), 3));
    }


    public function testSecondsToFramesRoundsToNearestFrame(): void
    {
        $this->assertSame(250, (new FrameRate(25))->secondsToFrames(10.0));
        $this->assertSame(1000, (new FrameRate(23.976))->secondsToFrames(41.708));
        $this->assertSame(3, (new FrameRate(25))->secondsToFrames(0.1));
    }


    public function testGetFps(): void
    {
        $this->assertSame(23.976, (new FrameRate(23.976))->getFramesPerSecond());
    }


    public function testZeroFpsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The frame rate must be greater than 0, got 0.");
        new FrameRate(0);
    }


    public function testNegativeFpsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FrameRate(-25);
    }


    public function testSecondsToFramesRoundsAnExactHalfFrameUp(): void
    {
        // 0.58 * 25.0 is 14.499999999999998 in binary.
        $this->assertSame(15, (new FrameRate(25.0))->secondsToFrames(0.58));
        $this->assertSame(-15, (new FrameRate(25.0))->secondsToFrames(-0.58));
        $this->assertSame(8999999998, (new FrameRate(25.0))->secondsToFrames(359999999.9));
    }


    public function testSecondsToFramesThrowsForAFrameBeyondTheIntRange(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("The time 359999999 s is frame 3.59999999E+20 at 1000000000000 fps. A frame number must be below 9223372036854775807.");

        (new FrameRate(1e12))->secondsToFrames(359999999);
    }


    public function testFramesBelowLimitStopAtTheLastFrameBelowTheLimit(): void
    {
        $frameRate = new FrameRate(25.0);

        $this->assertSame(25, $frameRate->framesBelowLimit(1.0));
        $this->assertSame(0, $frameRate->framesBelowLimit(-1.0));
        $this->assertSame(8999999999, $frameRate->framesBelowLimit(359999999.999));
        $this->assertSame(8999999999, $frameRate->framesBelowLimit(1e20));
        $this->assertSame(10789199999, (new FrameRate(29.97))->framesBelowLimit(359999999.999));
    }
}
