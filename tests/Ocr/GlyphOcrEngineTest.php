<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use Closure;
use Composer\Autoload\ClassLoader;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\RecognitionResult;
use GlyphOcr\RecognizedChar;
use GlyphOcr\RecognizedLine;
use GlyphOcr\Recognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\OcrException;
use SubtitleToolbox\Format;
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

class GlyphOcrEngineTest extends TestCase
{
    private const PGS    = __DIR__ . "/../files/pgs/";
    private const VOBSUB = __DIR__ . "/../files/vobsub/";


    /**
     * Each set: the parser call, the golden OCR output, the drawn text of each cue and the minimum character accuracy.
     *
     * @return array<string, array{Closure, string, list<list<string>>, float}>
     */
    public static function fixtureSets(): array
    {
        return [
            "PGS 1080p, 44 to 60 px" => [
                fn (): Subtitle => (new PgsParser())->parse(file_get_contents(self::PGS . "text_1080p.sup"), new ReadOptions()),
                self::PGS . "text_1080p.ocr.srt",
                array_column(PgsFixtures::TEXT_CUES, 2),
                1.0,
            ],
            "VobSub 576p, 24 to 30 px" => [
                fn (): Subtitle => (new VobSubParser())
                    ->parse(file_get_contents(self::VOBSUB . "text-pal.sub"), new ReadOptions(formats: [new VobSubReadOptions(file_get_contents(self::VOBSUB . "text-pal.idx"))])),
                self::VOBSUB . "text-pal.ocr.srt",
                array_column(TEXT_CUES, 2),
                0.97,
            ],
        ];
    }


    #[DataProvider("fixtureSets")]
    public function testReadsTheFixtureSetAsTheGoldenFileWithTheMinimumAccuracy(Closure $parse, string $golden,
                                                                                  array $drawnLines, float $minimum): void
    {
        $subtitle = $parse()->recognizeText(new GlyphOcrEngine());

        $this->assertStringEqualsFile($golden, $subtitle->toString(Format::SubRip));

        $errors = 0;
        $length = 0;
        foreach ($subtitle->getCues() as $index => $cue) {
            $expected = implode("\n", Markup::plainLines($drawnLines[$index]));
            $errors  += levenshtein($expected, implode("\n", Markup::plainLines($cue->getLines())));
            $length  += strlen($expected);
            $this->assertTrue(CueImage::isImageCue($cue));
        }
        $this->assertCount(count($drawnLines), $subtitle->getCues());
        $this->assertGreaterThanOrEqual($minimum, round(1 - $errors / $length, 4));
    }


    public function testTextFixturesMatchTheGenerators(): void
    {
        $this->assertSame(file_get_contents(self::PGS . "text_1080p.sup"), PgsFixtures::text1080p());
        $this->assertSame([file_get_contents(self::VOBSUB . "text-pal.idx"), file_get_contents(self::VOBSUB . "text-pal.sub")],
                          textFixture());
    }


    private static function char(string $text, bool $italic = false, float $confidence = 1.0): RecognizedChar
    {
        return new RecognizedChar($text, $confidence, $italic, $text === " ", null, null);
    }


    /**
     * @param string $pattern one character per entry, where an upper case "I" marks an italic character
     */
    private static function line(string $text, string $pattern): RecognizedLine
    {
        return new RecognizedLine(array_map(fn (string $char, string $flag): RecognizedChar => self::char($char, $flag === "I"),
                                            str_split($text), str_split($pattern)));
    }


    public function testItalicWordsBecomeOneItalicRun(): void
    {
        $result = GlyphOcrEngine::toRecognizedText(new RecognitionResult([
            self::line("The wind turns", "II.IIIII.IIIII"),
            self::line("Snow is here.", "IIII.I...I..."),
            self::line("one more word", "...I.IIII.III"),
        ]));

        $this->assertSame(["<i>The wind turns</i>", "<i>Snow</i> is here.", "one <i>more word</i>"], $result->lines);
    }


    public function testEscapesMarkupCharactersAndUsesTheMeanConfidence(): void
    {
        $result = GlyphOcrEngine::toRecognizedText(new RecognitionResult([
            new RecognizedLine([self::char("<", false, 0.5), self::char("&", false, 1.0), self::char(" "),
                                self::char("b", false, 0.6)]),
        ]));

        $this->assertSame(["&lt;&amp; b"], $result->lines);
        $this->assertEqualsWithDelta(0.7, $result->confidence, 1e-9);
    }


    public function testAnImageWithoutInkGivesNoLines(): void
    {
        $image = new CueImage(PngEncoder::encode(4, 4, array_fill(0, 16, 0)), 0, 0, 4, 4, 720, 576);

        $result = (new GlyphOcrEngine())->recognize($image, null);

        $this->assertSame([], $result->lines);
        $this->assertSame(0.0, $result->confidence);
    }


    public function testPassesTheDatabaseAndTheOptionsToTheRecognizer(): void
    {
        $subtitle = (new PgsParser())->parse(file_get_contents(self::PGS . "text_1080p.sup"), new ReadOptions());
        $image    = CueImage::fromCue($subtitle->getCues()[0]);

        $result = (new GlyphOcrEngine(new GlyphOcrOptions(new GlyphDatabase(), unknownText: "#")))->recognize($image, "eng");

        $this->assertCount(1, $result->lines);
        $this->assertMatchesRegularExpression("/^#+( #+)+$/", $result->lines[0]);
        $this->assertSame(0.0, $result->confidence);
    }


    public function testOptionsMatchTheRecognizerDefaultsAndTypes(): void
    {
        $options    = new GlyphOcrOptions();
        $recognizer = (new \ReflectionMethod(Recognizer::class, "__construct"))->getParameters();
        $ours       = (new \ReflectionMethod(GlyphOcrOptions::class, "__construct"))->getParameters();
        foreach ($recognizer as $index => $parameter) {
            if ($parameter->getName() !== "database") {
                $this->assertSame($parameter->getDefaultValue(), $options->{$parameter->getName()}, $parameter->getName());
                $this->assertSame((string) $parameter->getType(), (string) $ours[$index]->getType(), $parameter->getName());
            }
        }
        $this->assertSame(array_column($recognizer, "name"), array_column($ours, "name"));
    }


    /**
     * @return array<string, array{string, int|float}>
     */
    public static function rangeEnds(): array
    {
        return [
            "ink threshold 0"     => ["inkThreshold", 0],
            "ink threshold 1"     => ["inkThreshold", 1],
            "ink threshold 765"   => ["inkThreshold", 765],
            "ink threshold 766"   => ["inkThreshold", 766],
            "space width 0"       => ["spaceWidth", 0],
            "space width 1"       => ["spaceWidth", 1],
            "wrong pixels -1"     => ["maxWrongPixels", -1],
            "wrong pixels 0"      => ["maxWrongPixels", 0],
            "italic slant -0.1"   => ["italicSlant", -0.1],
            "italic slant 0"      => ["italicSlant", 0.0],
            "italic slant 1"      => ["italicSlant", 1.0],
            "italic slant 1.1"    => ["italicSlant", 1.1],
            "line height 0"       => ["minLineHeight", 0],
            "line height 1"       => ["minLineHeight", 1],
        ];
    }


    #[DataProvider("rangeEnds")]
    public function testOptionsAcceptTheValuesThatTheRecognizerAccepts(string $name, int|float $value): void
    {
        $accepts = function (Closure $create): bool {
            try {
                $create();

                return true;
            } catch (\Exception) {
                return false;
            }
        };

        $this->assertSame(
            $accepts(fn () => new Recognizer(new GlyphDatabase(), ...[$name => $value])),
            $accepts(fn () => new GlyphOcrOptions(...[$name => $value]))
        );
    }


    /**
     * @return array<string, array{Closure(): GlyphOcrOptions, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            "ink threshold 0"   => [fn () => new GlyphOcrOptions(inkThreshold: 0), "The ink threshold must be from 1 to 765, got 0."],
            "ink threshold 766" => [fn () => new GlyphOcrOptions(inkThreshold: 766), "The ink threshold must be from 1 to 765, got 766."],
            "space width 0"     => [fn () => new GlyphOcrOptions(spaceWidth: 0), "The space width must be at least 1, got 0."],
            "wrong pixels -1"   => [fn () => new GlyphOcrOptions(maxWrongPixels: -1), "The number of wrong pixels must be at least 0, got -1."],
            "italic slant -0.1" => [fn () => new GlyphOcrOptions(italicSlant: -0.1), "The italic slant must be from 0 to 1, got -0.1."],
            "line height 0"     => [fn () => new GlyphOcrOptions(minLineHeight: 0), "The minimum line height must be at least 1, got 0."],
        ];
    }


    #[DataProvider("invalidOptions")]
    public function testInvalidOptionThrows(Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }


    public function testImageThatIsNoPngThrows(): void
    {
        $this->expectException(OcrException::class);
        $this->expectExceptionMessage("The recognizer fails on the cue image at 3, 4: Cannot decode the PNG");

        (new GlyphOcrEngine())->recognize(new CueImage("no png", 3, 4, 1, 1, 720, 576), null);
    }


    #[RunInSeparateProcess]
    public function testThrowsWithAnInstallHintWhenThePackageIsMissing(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(function (string $class) use ($loader): void {
                if (!str_starts_with($class, "GlyphOcr\\")) {
                    $loader->loadClass($class);
                }
            });
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("the package yama6a/php-glyph-ocr is missing. " .
                                      "Install it with: composer require yama6a/php-glyph-ocr");

        new GlyphOcrEngine();
    }
}
