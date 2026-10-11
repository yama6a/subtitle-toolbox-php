<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\GoogleTranslateOptions;
use SubtitleToolbox\Translation\OpenAiCompatibleOptions;
use SubtitleToolbox\Validation\ValidationRules;

class OptionsCopyTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function copiedClasses(): array
    {
        return [
            "ValidationRules"      => [ValidationRules::class],
            "WriteOptions"         => [WriteOptions::class],
            "ReadOptions"          => [ReadOptions::class],
            "CommonErrorOptions"   => [CommonErrorOptions::class],
            "ShotChangeOptions"    => [ShotChangeOptions::class],
            "GlyphOcrOptions"      => [GlyphOcrOptions::class],
            "ReferenceSyncOptions" => [ReferenceSyncOptions::class],
            "CsvWriteOptions"      => [CsvWriteOptions::class],
            "MicroDvdWriteOptions" => [MicroDvdWriteOptions::class],
            "VobSubReadOptions"    => [VobSubReadOptions::class],
        ];
    }


    /**
     * @return array<string, array{class-string}>
     */
    public static function optionsClasses(): array
    {
        $source  = realpath(__DIR__ . "/../src");
        $classes = [];
        $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, "Options.php")) {
                continue;
            }

            $relative = substr($path, strlen($source) + 1, -4);
            $class    = "SubtitleToolbox\\" . str_replace("/", "\\", $relative);
            if ((new ReflectionClass($class))->isInstantiable()) {
                $classes[$relative] = [$class];
            }
        }

        return $classes;
    }


    /**
     * @param class-string $class
     */
    #[DataProvider("optionsClasses")]
    public function testCopiesEveryOptionsClassWithoutChanges(string $class): void
    {
        $required = [
            ShotChangeOptions::class      => [25.0],
            ResegmentOptions::class       => [ResegmentMode::SplitLong],
            ProfanityOptions::class       => [["heck"]],
            GoogleTranslateOptions::class => ["key"],
            DeepLOptions::class           => ["key"],
            OpenAiCompatibleOptions::class => ["http://localhost/v1", "llama3"],
            ReferenceSyncOptions::class   => [new Subtitle()],
        ];

        $options = new $class(...($required[$class] ?? []));

        $this->assertEquals($options, OptionsCopy::with($options, []));
    }


    /**
     * @param class-string $class
     */
    #[DataProvider("copiedClasses")]
    public function testEveryPublicPropertyIsAConstructorParameter(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $parameters = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getConstructor()?->getParameters() ?? []);
        $properties = array_map(fn (ReflectionProperty $property): string => $property->getName(),
            array_filter($reflection->getProperties(ReflectionProperty::IS_PUBLIC), fn (ReflectionProperty $property): bool => !$property->isStatic()));

        $this->assertNotSame([], $properties);
        $this->assertSame([], array_values(array_diff($properties, $parameters)));
    }


    public function testKeepsEveryFieldOfTheBase(): void
    {
        $write = OptionsCopy::with(new WriteOptions(LineEnding::Crlf, true, true, true), ["formats" => [new MicroDvdWriteOptions(frameRate: 25.0)]]);
        $this->assertEquals(new WriteOptions(LineEnding::Crlf, true, true, true, [new MicroDvdWriteOptions(frameRate: 25.0)]), $write);

        $read = OptionsCopy::with(new ReadOptions("Windows-1252", true, 2.0), ["formats" => [new MicroDvdReadOptions(25.0)]]);
        $this->assertEquals(new ReadOptions("Windows-1252", true, 2.0, [new MicroDvdReadOptions(25.0)]), $read);

        $rules = OptionsCopy::with(ValidationRules::structure(), ["maxLinesPerCue" => 2]);
        $this->assertEquals(new ValidationRules(maxLinesPerCue: 2, noOverlap: true, requireCues: true, noUnsortedCues: true, noNegativeDuration: true), $rules);
    }
}
