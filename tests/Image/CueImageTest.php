<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class CueImageTest extends TestCase
{
    public function testApplyToWritesTheFormatDataThatFromCueReads(): void
    {
        $png   = PngEncoder::encode(2, 1, [0xFF0000FF, 0x00000000]);
        $image = new CueImage($png, 640, 940, 2, 1, 1920, 1080, true);
        $cue   = new SubtitleCue(1, 2);

        $image->applyTo($cue);

        $this->assertSame([
            "png"          => $png,
            "x"            => 640,
            "y"            => 940,
            "width"        => 2,
            "height"       => 1,
            "screenWidth"  => 1920,
            "screenHeight" => 1080,
            "forced"       => true,
        ], $cue->findFormatData("image"));
        $this->assertEquals($image, CueImage::fromCue($cue));
        $this->assertSame([], $cue->getLines());
    }


    public function testIsImageCueChecksTheFormatData(): void
    {
        $image = new CueImage("png", 0, 0, 1, 1, 720, 576);

        $this->assertFalse(CueImage::isImageCue(new SubtitleCue(1, 2, "Text")));
        $this->assertTrue(CueImage::isImageCue(TestSubtitles::imageCue($image, new SubtitleCue(1, 2))));
        $this->assertTrue(CueImage::isImageCue(TestSubtitles::imageCue($image, new SubtitleCue(1, 2, "Text"))));
    }


    public function testFromCueDefaultsForcedToFalse(): void
    {
        $cue = (new SubtitleCue(1, 2))->setFormatData("image", [
            "png" => "png", "x" => 1, "y" => 2, "width" => 3, "height" => 4, "screenWidth" => 720, "screenHeight" => 480,
        ]);

        $this->assertFalse(CueImage::fromCue($cue)->forced);
    }


    public function testFromCueThrowsWithoutImage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("the cue holds no image");

        CueImage::fromCue(new SubtitleCue(1, 2, "Text"));
    }


    public function testFromCueThrowsOnMissingKey(): void
    {
        $cue = (new SubtitleCue(1, 2))->setFormatData("image", ["png" => "png", "x" => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("the image data has no integer \"y\"");

        CueImage::fromCue($cue);
    }


    public function testConstructorRejectsEmptySize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CueImage("png", 0, 0, 0, 1, 720, 576);
    }
}
