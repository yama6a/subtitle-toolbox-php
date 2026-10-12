<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;

require_once __DIR__ . "/TesseractOcrEngineTest.php";

class OcrEngineChooserTest extends TestCase
{
    private const FAKE    = TesseractOcrEngineTest::FAKE;
    private const MISSING = __DIR__ . "/no-such-program";


    private static function hideGlyphOcr(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(function (string $class) use ($loader): void {
                if (!str_starts_with($class, "GlyphOcr\\")) {
                    $loader->loadClass($class);
                }
            });
        }
    }


    public function testPrefersTesseractAndFallsBackToGlyphOcr(): void
    {
        $this->assertSame(OcrEngineName::Tesseract, OcrEngineChooser::choose(null, self::FAKE));
        $this->assertSame(OcrEngineName::Glyph, OcrEngineChooser::choose(null, self::MISSING));
        $this->assertSame(OcrEngineName::Glyph, OcrEngineChooser::choose(OcrEngineName::Glyph, self::FAKE));
        $this->assertSame(OcrEngineName::Tesseract, OcrEngineChooser::choose(OcrEngineName::Tesseract, self::FAKE));
    }


    public function testCreatesTheChosenEngine(): void
    {
        $tesseract = OcrEngineChooser::create(null, self::FAKE);
        $image     = new CueImage(PngEncoder::encode(1, 1, [0]), 0, 0, 1, 1, 1920, 1080);

        $this->assertInstanceOf(TesseractOcrEngine::class, $tesseract);
        $this->assertSame("eng psm6", $tesseract->recognize($image, null)->lines[0]);
        $this->assertSame("deu psm6", $tesseract->recognize($image, "deu")->lines[0]);
        $this->assertInstanceOf(GlyphOcrEngine::class, OcrEngineChooser::create(null, self::MISSING));
    }


    public function testTesseractOptionsTakeAnOcrLanguageCaseAsItsModelName(): void
    {
        $this->assertSame("deu", (new TesseractOcrOptions(OcrLanguage::German))->language);
        $this->assertSame("deu+eng", (new TesseractOcrOptions(language: "deu+eng"))->language);
    }


    public function testForcedTesseractThrowsWhenItIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot run OCR with Tesseract: the program \"" . self::MISSING . "\" is missing. " .
                                      "Install Tesseract with: apt install tesseract-ocr");

        OcrEngineChooser::choose(OcrEngineName::Tesseract, self::MISSING);
    }


    #[RunInSeparateProcess]
    public function testForcedGlyphOcrThrowsWhenThePackageIsMissing(): void
    {
        self::hideGlyphOcr();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot run OCR with php-glyph-ocr: the package yama6a/php-glyph-ocr is missing. " .
                                      "Install php-glyph-ocr with: composer require yama6a/php-glyph-ocr");

        OcrEngineChooser::choose(OcrEngineName::Glyph, self::FAKE);
    }


    #[RunInSeparateProcess]
    public function testThrowsWithBothInstallHintsWhenNeitherEngineIsInstalled(): void
    {
        self::hideGlyphOcr();
        $this->assertSame(OcrEngineName::Tesseract, OcrEngineChooser::choose(null, self::FAKE));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot run OCR: neither Tesseract nor the package yama6a/php-glyph-ocr is " .
                                      "installed. Install Tesseract with: apt install tesseract-ocr (Debian, Ubuntu), " .
                                      "apk add tesseract-ocr tesseract-ocr-data-eng (Alpine), dnf install tesseract (Fedora), brew install " .
                                      "tesseract (macOS), or the installer from https://github.com/UB-Mannheim/" .
                                      "tesseract/wiki (Windows). Or install php-glyph-ocr with: composer require " .
                                      "yama6a/php-glyph-ocr");

        OcrEngineChooser::choose(null, self::MISSING);
    }
}
