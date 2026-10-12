<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\OcrException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PgsFixtures;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

require_once __DIR__ . "/../files/pgs/generator/PgsFixtures.php";
require_once __DIR__ . "/../files/vobsub/generate.php";

class TesseractOcrEngineTest extends TestCase
{
    public const FAKE = __DIR__ . "/../files/ocr/fake-tesseract/tesseract";

    private const PGS    = __DIR__ . "/../files/pgs/";
    private const VOBSUB = __DIR__ . "/../files/vobsub/";


    protected function tearDown(): void
    {
        putenv("FAKE_TESSERACT_FAIL");
    }


    private static function image(int $width = 40, int $height = 20, int $screenHeight = 1080): CueImage
    {
        return new CueImage(PngEncoder::encode($width, $height, array_fill(0, $width * $height, 0xFFFFFFFF)), 3, 4,
                            $width, $height, 1920, $screenHeight);
    }


    /**
     * Each set: the parser call, the drawn text of each cue, the Tesseract language and the minimum character accuracy.
     *
     * @return array<string, array{Closure, list<list<string>>, string, float}>
     */
    public static function fixtureSets(): array
    {
        return [
            "PGS 1080p, 44 to 60 px"              => [
                fn (): Subtitle => (new PgsParser())->parse(file_get_contents(self::PGS . "text_1080p.sup"), new ReadOptions()),
                array_column(PgsFixtures::TEXT_CUES, 2),
                "eng",
                0.99,
            ],
            "VobSub 576p, 24 to 30 px"            => [
                fn (): Subtitle => (new VobSubParser())
                    ->parse(file_get_contents(self::VOBSUB . "text-pal.sub"), new ReadOptions(formats: [new VobSubReadOptions(file_get_contents(self::VOBSUB . "text-pal.idx"))])),
                array_column(TEXT_CUES, 2),
                "eng",
                0.99,
            ],
            "PGS 1080p, Cyrillic, 44 to 60 px" => [
                fn (): Subtitle => (new PgsParser())->parse(file_get_contents(self::PGS . "text_cyrillic_1080p.sup"), new ReadOptions()),
                array_column(PgsFixtures::CYRILLIC_CUES, 2),
                "rus",
                0.99,
            ],
        ];
    }


    #[DataProvider("fixtureSets")]
    #[Group("tesseract")]
    public function testReadsTheFixtureSetWithTheMinimumAccuracy(Closure $parse, array $drawnLines, string $language,
                                                                 float $minimum): void
    {
        if (!TesseractOcrEngine::isInstalled()) {
            $this->markTestSkipped("Tesseract is not installed.");
        }

        $subtitle = $parse()->recognizeText(new TesseractOcrEngine(), $language);

        $errors = 0;
        $length = 0;
        foreach ($subtitle->getCues() as $index => $cue) {
            $expected = mb_str_split(implode("\n", Markup::plainLines($drawnLines[$index])));
            $actual   = mb_str_split(implode("\n", Markup::plainLines($cue->getLines())));
            $errors  += self::editDistance($expected, $actual);
            $length  += count($expected);
        }
        $this->assertCount(count($drawnLines), $subtitle->getCues());
        $this->assertGreaterThanOrEqual($minimum, round(1 - $errors / $length, 4));
    }


    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private static function editDistance(array $expected, array $actual): int
    {
        $previous = range(0, count($actual));
        foreach ($expected as $i => $expectedChar) {
            $current = [$i + 1];
            foreach ($actual as $j => $actualChar) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($expectedChar === $actualChar ? 0 : 1));
            }
            $previous = $current;
        }

        return $previous[count($actual)];
    }


    public function testCyrillicFixtureMatchesTheGenerator(): void
    {
        $this->assertSame(file_get_contents(self::PGS . "text_cyrillic_1080p.sup"), PgsFixtures::textCyrillic1080p());
    }


    public function testPassesTheLanguageAndTheModeAndBuildsLinesFromTheTsv(): void
    {
        $result = (new TesseractOcrEngine(new TesseractOcrOptions("deu+eng", 11, self::FAKE)))->recognize(self::image(), null);

        $this->assertSame(["deu+eng psm11", "60x40"], $result->lines);
        $this->assertEqualsWithDelta(0.8, $result->confidence, 1e-9);
    }


    public function testTheLanguageOfRecognizeWinsOverTheConstructor(): void
    {
        $result = (new TesseractOcrEngine(new TesseractOcrOptions("deu", program: self::FAKE)))->recognize(self::image(), "eng");

        $this->assertSame("eng psm6", $result->lines[0]);
    }


    public function testScalesSmallScreensTwiceAndAddsABorder(): void
    {
        $engine = new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE));

        $this->assertSame("60x40", $engine->recognize(self::image(40, 20, 1080), null)->lines[1]);
        $this->assertSame("100x60", $engine->recognize(self::image(40, 20, 576), null)->lines[1]);
        $this->assertSame("140x80", (new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE, scale: 3)))->recognize(self::image(), null)->lines[1]);
    }


    public function testDrawsLightTextDarkOnWhite(): void
    {
        $method = new \ReflectionMethod(TesseractOcrEngine::class, "toPgm");
        $pixels = [0x00000000, 0xFFFFFFFF, 0x000000FF, 0xFFFF00FF];
        $image  = new CueImage(PngEncoder::encode(4, 1, $pixels), 0, 0, 4, 1, 1920, 1080);

        $pgm = $method->invoke(new TesseractOcrEngine(), $image);

        $this->assertStringStartsWith("P5\n24 21\n255\n", $pgm);
        $this->assertSame([255, 0, 255, 29], array_values(unpack("C4", substr($pgm, 13 + 10 * 24 + 10, 4))));

        $pgm = $method->invoke(new TesseractOcrEngine(new TesseractOcrOptions(invert: false, threshold: 128)), $image);

        $this->assertSame([0, 255, 0, 255], array_values(unpack("C4", substr($pgm, 13 + 10 * 24 + 10, 4))));
    }


    public function testReadsWordsInTsvRowsAndEscapesMarkup(): void
    {
        $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\r\n" .
               "4\t1\t1\t1\t1\t0\t0\t0\t50\t10\t-1\t\r\n" .
               "5\t1\t1\t1\t1\t1\t0\t0\t10\t10\t95.5\tFish\r\n" .
               "5\t1\t1\t1\t1\t2\t0\t0\t10\t10\t-1\t \r\n" .
               "5\t1\t1\t1\t1\t3\t0\t0\t10\t10\t75.5\t&\r\n" .
               "5\t1\t2\t1\t1\t1\t0\t0\t10\t10\t50\t<chips>\r\n";

        $result = TesseractOcrEngine::fromTsv($tsv);

        $this->assertSame(["Fish &amp;", "&lt;chips&gt;"], $result->lines);
        $this->assertEqualsWithDelta(0.7366667, $result->confidence, 1e-6);
    }


    public function testAnImageWithoutTextGivesNoLinesAndNoConfidence(): void
    {
        $result = TesseractOcrEngine::fromTsv("level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\t" .
                                              "height\tconf\ttext\n1\t1\t0\t0\t0\t0\t0\t0\t24\t21\t-1\t\n");

        $this->assertSame([], $result->lines);
        $this->assertNull($result->confidence);
    }


    public function testIsInstalledOnlyForAProgramThatPrintsATesseractVersion(): void
    {
        $this->assertTrue(TesseractOcrEngine::isInstalled(self::FAKE));
        $this->assertFalse(TesseractOcrEngine::isInstalled(__DIR__ . "/no-such-program"));
        $this->assertFalse(TesseractOcrEngine::isInstalled(PHP_BINARY));
    }


    public function testMissingProgramThrowsWithInstallHints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot run OCR with Tesseract: the program \"" . __DIR__ . "/no-such-program\" " .
                                      "is missing. Install Tesseract with: apt install tesseract-ocr (Debian, Ubuntu), " .
                                      "apk add tesseract-ocr tesseract-ocr-data-eng (Alpine), dnf install tesseract (Fedora), brew install " .
                                      "tesseract (macOS), or the installer from https://github.com/UB-Mannheim/" .
                                      "tesseract/wiki (Windows).");

        (new TesseractOcrEngine(new TesseractOcrOptions(program: __DIR__ . "/no-such-program")))->recognize(self::image(), null);
    }


    public function testMissingLanguageThrowsWithTheInstalledLanguages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot run OCR with Tesseract in the language \"deu+fra+jpn\": the language " .
                                      "data of fra, jpn is missing. Install it, for example with apt install " .
                                      "tesseract-ocr-fra. The installed languages are: deu, eng, osd.");

        (new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE)))->recognize(self::image(), "deu+fra+jpn");
    }


    public function testFailingProgramThrowsWithItsError(): void
    {
        putenv("FAKE_TESSERACT_FAIL=1");

        $this->expectException(OcrException::class);
        $this->expectExceptionMessage("Tesseract exits with code 1 for the cue image at 3, 4: Error during processing.");

        (new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE)))->recognize(self::image(), null);
    }


    // Other test runs on the same machine share the temp directory, so the test checks only its own image file.
    public function testDeletesTheTemporaryImage(): void
    {
        $directory = sys_get_temp_dir() . "/subtitle-toolbox-ocr-test-" . bin2hex(random_bytes(8));
        mkdir($directory);
        $log = "$directory/image-path";
        putenv("FAKE_TESSERACT_LOG=$log");
        try {
            (new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE)))->recognize(self::image(), null);
            $image = trim(file_get_contents($log));
        } finally {
            putenv("FAKE_TESSERACT_LOG");
            if (is_file($log)) {
                unlink($log);
            }
            rmdir($directory);
        }

        $this->assertStringContainsString("stbox-ocr-", $image);
        $this->assertFileDoesNotExist($image);
    }


    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            "mode 14"       => [["pageSegmentationMode" => 14], "The page segmentation mode must be from 0 to 13, got 14."],
            "scale 0.5"     => [["scale" => 0.5], "The scale must be from 1 to 8, got 0.5."],
            "threshold 0"   => [["threshold" => 0], "The threshold must be from 1 to 255, got 0."],
            "threshold 256" => [["threshold" => 256], "The threshold must be from 1 to 255, got 256."],
        ];
    }


    #[DataProvider("invalidOptions")]
    public function testInvalidOptionThrows(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new TesseractOcrOptions(...$options);
    }
}
