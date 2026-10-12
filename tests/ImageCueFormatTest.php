<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\FakeOcrEngine;

require_once __DIR__ . "/Ocr/FakeOcrEngine.php";

class ImageCueFormatTest extends TestCase
{
    private function addImageCue(Subtitle $subtitle, float $start, float $end): Subtitle
    {
        $image = new CueImage("png", 0, 0, 1, 1, 720, 576);

        return $subtitle->addCue($image->toCue(new SubtitleCue($start, $end)));
    }


    private function subtitleWithImageCue(): Subtitle
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "First"))
            ->addCue(new SubtitleCue(5, 6, "Last"));
        $this->addImageCue($subtitle, 3, 4);

        return $subtitle->addComment("Before the image", 1)->addComment("Before the last cue", 2);
    }


    public function testTextFormatterThrowsOnImageCueWithoutText(): void
    {
        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #1 holds an image but no text, and srt needs text. Run OCR with recognizeText() first");

        $this->subtitleWithImageCue()->toString(Format::SubRip);
    }


    public function testSkipOptionDropsImageCuesWithoutTextAndKeepsTheSubtitle(): void
    {
        $subtitle = $this->subtitleWithImageCue();

        $output = $subtitle->toString(Format::WebVtt, new WriteOptions(skipImageCues: true));

        $expected = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "First"))
            ->addCue(new SubtitleCue(5, 6, "Last"))
            ->addComment("Before the image", 1)
            ->addComment("Before the last cue", 1)
            ->toString(Format::WebVtt);
        $this->assertSame($expected, $output);
        $this->assertCount(3, $subtitle->getCues());
        $this->assertSame(2, $subtitle->getComments()[1]->beforeCueIndex);
    }


    public function testImageCueWithTextIsFormattedAsText(): void
    {
        $subtitle = $this->subtitleWithImageCue();
        $subtitle->getCues()[1]->setLines("Read by OCR");

        $this->assertStringContainsString("Read by OCR", $subtitle->toString(Format::SubRip));
    }


    public function testFormatAfterRecognizeTextWritesTheEngineText(): void
    {
        $output = $this->subtitleWithImageCue()->recognizeText(new FakeOcrEngine(["Fixed text"]))->toString(Format::SubRip);

        $this->assertSame("\u{FEFF}1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n" .
                          "2\n00:00:03,000 --> 00:00:04,000\nFixed text\n\n" .
                          "3\n00:00:05,000 --> 00:00:06,000\nLast\n", $output);
    }


    public function testImageFormatGetsImageCuesWithoutText(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/files/pgs/shapes_576p.sup"), Format::Pgs);
        $output   = $subtitle->toString(Format::Pgs, new WriteOptions(skipImageCues: true));

        $this->assertSame($subtitle->toString(Format::Pgs), $output);
        $this->assertCount(count($subtitle->getCues()), Subtitle::fromString($output, Format::Pgs)->getCues());
    }
}
