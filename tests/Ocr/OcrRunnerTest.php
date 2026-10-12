<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

require_once __DIR__ . "/FakeOcrEngine.php";

class OcrRunnerTest extends TestCase
{
    private function makeImage(int $x): CueImage
    {
        return new CueImage(PngEncoder::encode(1, 1, [0xFFFFFFFF]), $x, 940, 1, 1, 1920, 1080);
    }


    private function subtitleWithImageCues(): Subtitle
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(TestSubtitles::imageCue($this->makeImage(10), new SubtitleCue(1, 2)));
        $subtitle->addCue(new SubtitleCue(3, 4, "Text cue"));
        $subtitle->addCue(TestSubtitles::imageCue($this->makeImage(30), new SubtitleCue(5, 6, "Already read")));
        $subtitle->addCue(TestSubtitles::imageCue($this->makeImage(40), new SubtitleCue(7, 8)));

        return $subtitle;
    }


    public function testRunSetsTheLinesOfImageCuesWithoutTextAndKeepsTheImages(): void
    {
        $subtitle = $this->subtitleWithImageCues();
        $engine   = new FakeOcrEngine(["<i>Line one</i>", "Line two"], 0.75);

        $results = (new OcrRunner($engine))->run($subtitle, "eng")->texts;

        $cues = $subtitle->getCues();
        $this->assertSame(["<i>Line one</i>", "Line two"], $cues[0]->getLines());
        $this->assertSame(["Text cue"], $cues[1]->getLines());
        $this->assertSame(["Already read"], $cues[2]->getLines());
        $this->assertSame(["<i>Line one</i>", "Line two"], $cues[3]->getLines());
        $this->assertTrue(CueImage::isImageCue($cues[0]));
        $this->assertSame(10, CueImage::fromCue($cues[0])->x);

        $this->assertSame([0, 3], array_keys($results));
        $this->assertSame(0.75, $results[3]->confidence);
        $this->assertCount(2, $engine->calls);
        $this->assertSame("eng", $engine->calls[0]["language"]);
        $this->assertSame(40, $engine->calls[1]["image"]->x);
    }


    public function testRecognizeTextRunsTheEngineAndReturnsTheSubtitle(): void
    {
        $subtitle = $this->subtitleWithImageCues();
        $engine   = new FakeOcrEngine();

        $this->assertSame($subtitle, $subtitle->recognizeText($engine));

        $this->assertSame(["Fixed text"], $subtitle->getCues()[0]->getLines());
        $this->assertNull($engine->calls[0]["language"]);
    }


    public function testAnOcrLanguageCaseAndItsModelNameReachTheEngineAsTheSameString(): void
    {
        $engine = new FakeOcrEngine();

        $this->subtitleWithImageCues()->recognizeText($engine, OcrLanguage::German);
        $this->subtitleWithImageCues()->recognizeText($engine, "deu");
        (new OcrRunner($engine))->run($this->subtitleWithImageCues(), OcrLanguage::ChineseSimplified);
        (new OcrRunner($engine))->run($this->subtitleWithImageCues(), "chi_sim");
        (new OcrRunner($engine))->run($this->subtitleWithImageCues(), "my_custom_model");

        $this->assertSame(["deu", "deu", "deu", "deu", "chi_sim", "chi_sim", "chi_sim", "chi_sim", "my_custom_model",
                           "my_custom_model"], array_column($engine->calls, "language"));
    }


    public function testRunWithoutImageCuesDoesNotCallTheEngine(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Text"));
        $engine   = new FakeOcrEngine();

        $this->assertSame([], (new OcrRunner($engine))->run($subtitle)->texts);
        $this->assertSame([], $engine->calls);
    }


    public function testResultRejectsConfidenceOutsideZeroToOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecognizedText(["Text"], 1.5);
    }


    public function testResultRejectsLinesThatAreNotStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecognizedText([42]);
    }
}
