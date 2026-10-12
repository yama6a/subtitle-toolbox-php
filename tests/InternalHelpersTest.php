<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Hls\TimestampMap;
use SubtitleToolbox\Ocr\TesseractOcrEngine;

class InternalHelpersTest extends TestCase
{
    public function testSubtitleAndCueHaveNoPublicInternalHelpers(): void
    {
        $subtitleMethods = ["emptyCopy", "setLinesAndRemoveEmptied", "toUpperOrLower", "fromArrayLeavingOut"];

        foreach ($subtitleMethods as $method) {
            $this->assertFalse(method_exists(Subtitle::class, $method), "Subtitle::$method()");
        }
        $this->assertFalse(method_exists(SubtitleCue::class, "mapTimes"));
    }


    public function testPublicMembersThatOnlyTheLibraryUsesAreMarkedInternal(): void
    {
        $members = [[Comment::class, "withBeforeCueIndex"], [SubtitleTrack::class, "describe"], [TesseractOcrEngine::class, "isInstalled"],
                    [TimestampMap::class, "isHeader"], [TimestampMap::class, "toHeader"], [HlsSegmentOptions::class, "fileName"], [CueImage::class, "isImageCue"],
                    [Subtitle::class, "setFormat"], [Subtitle::class, "setSourceEncoding"], [Subtitle::class, "setParseWarnings"],
                    [Subtitle::class, "replaceCues"]];

        foreach ($members as [$class, $method]) {
            $comment = (new \ReflectionMethod($class, $method))->getDocComment();
            $this->assertIsString($comment, "$class::$method()");
            $this->assertStringContainsString("@internal", $comment, "$class::$method()");
        }
    }
}
