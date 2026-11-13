<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class RecognizedTextTest extends TestCase
{
    public function testRejectsAConfidenceOfNan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The confidence must be from 0 to 1, got NAN.");

        new RecognizedText(["Text"], NAN);
    }


    public function testAcceptsTheLimitsOfTheConfidence(): void
    {
        $this->assertSame(0.0, (new RecognizedText(["Text"], 0.0))->confidence);
        $this->assertSame(1.0, (new RecognizedText(["Text"], 1.0))->confidence);
    }
}
