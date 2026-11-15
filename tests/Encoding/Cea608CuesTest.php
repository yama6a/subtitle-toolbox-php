<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\TestCase;

class Cea608CuesTest extends TestCase
{
    public function testAFrameOfAnotherRateOnAHalfFrameRoundsUpOnEveryPhpVersion(): void
    {
        // Frame 6 at 23.976 fps is 7.5 frames at 29.97 fps, and frame 15 at 59.94 fps is 7.5 frames too.
        $this->assertSame(8, Cea608Cues::secondsToFrame(6 * 1001 / 24000));
        $this->assertSame(33, Cea608Cues::secondsToFrame(26 * 1001 / 24000));
        $this->assertSame(8, Cea608Cues::secondsToFrame(15 * 1001 / 60000));
    }


    public function testEveryMccFrameOfAnHourLandsOnTheNearestFrame(): void
    {
        foreach ([[24000, 1001], [60000, 1001], [25, 1]] as [$numerator, $denominator]) {
            $wrong = 0;
            for ($frame = 0; $frame < 3600 * $numerator / $denominator; $frame++) {
                $exact = $frame * 30000 * $denominator;
                $wrong += Cea608Cues::secondsToFrame($frame * $denominator / $numerator) === intdiv(2 * $exact + 1001 * $numerator, 2 * 1001 * $numerator) ? 0 : 1;
            }
            $this->assertSame(0, $wrong, "$numerator/$denominator fps");
        }
    }
}
