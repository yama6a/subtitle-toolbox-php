<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class CuePositionTest extends TestCase
{
    public function testKeepsTheEdges(): void
    {
        $position = new CuePosition(0, 100);

        $this->assertSame([0.0, 100.0], [$position->x, $position->y]);
    }


    /**
     * @return array<string, array{float, float, string}>
     */
    public static function badPoints(): array
    {
        return [
            "x below 0"  => [-0.5, 50, "The position x must be a number from 0 to 100, got -0.5."],
            "x over 100" => [100.001, 50, "The position x must be a number from 0 to 100, got 100.001."],
            "y NAN"      => [50, NAN, "The position y must be a number from 0 to 100, got NAN."],
            "y INF"      => [50, INF, "The position y must be a number from 0 to 100, got INF."],
            "y below 0"  => [50, -INF, "The position y must be a number from 0 to 100, got -INF."],
        ];
    }


    #[DataProvider("badPoints")]
    public function testThrowsForAPointOutsideTheVideo(float $x, float $y, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CuePosition($x, $y);
    }
}
