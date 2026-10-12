<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";

    // Fixtures that version 1 did not detect and that detect now.
    private const DETECTED_SINCE_VERSION_1 = [
        "lenient/text_before_first_cue.srt" => "srt",
    ];

    private const NOT_AUTO_DETECTED = [
        Format::YouTubeChapters, Format::PodcastChapters, Format::FfMetadataChapters, Format::OgmChapters,
        Format::AwsTranscribe, Format::Deepgram, Format::AssemblyAi, Format::GoogleSpeech,
    ];


    /**
     * @return array<string, array{string, class-string, list<string>}>
     */
    public static function classDirectories(): array
    {
        return [
            "parsers"    => ["Parsers", Parsers\SubtitleParser::class, self::registered(FormatRegistry::parserClass(...))],
            "formatters" => ["Formatters", Formatters\SubtitleFormatter::class, self::registered(FormatRegistry::formatterClass(...))],
        ];
    }


    /**
     * @param \Closure(Format): ?string $classOf
     * @return list<string>
     */
    private static function registered(\Closure $classOf): array
    {
        return array_values(array_filter(array_map($classOf, Format::cases())));
    }


    #[DataProvider("classDirectories")]
    public function testEveryParserAndFormatterClassHasAFormat(string $directory, string $baseClass, array $registered): void
    {
        $classes = [];
        foreach (glob(__DIR__ . "/../src/$directory/*.php") as $path) {
            $class = "SubtitleToolbox\\$directory\\" . basename($path, ".php");
            if ((new \ReflectionClass($class))->isInstantiable() && is_subclass_of($class, $baseClass)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        $registered = array_unique($registered);
        sort($registered);

        $this->assertNotEmpty($classes);
        $this->assertSame($classes, $registered);
    }


    public function testCasesFollowTheRowsOfTheRegistry(): void
    {
        $rows = (new \ReflectionClassConstant(FormatRegistry::class, "FORMATS"))->getValue();

        $this->assertCount(33, Format::cases());
        $this->assertSame(array_keys($rows), array_map(fn (Format $format): string => $format->value, Format::cases()));
    }


    public function testEveryDetectorSignatureNamesAFormat(): void
    {
        $signatures = (new \ReflectionClassConstant(FormatDetector::class, "SIGNATURES"))->getValue();

        foreach (array_keys($signatures) as $name) {
            $this->assertNotNull(Format::tryFrom($name), $name);
        }
    }


    public function testFromAndTryFromTakeTheFormatName(): void
    {
        $this->assertSame(Format::SubRip, Format::from("srt"));
        $this->assertSame(Format::MicroDvd, Format::from("microdvd"));
        $this->assertSame(Format::PodcastTranscript, Format::from("podcast-transcript"));
        $this->assertNull(Format::tryFrom("nope"));
        $this->assertNull(Format::tryFrom("SRT"));
    }


    public function testFromPath(): void
    {
        $this->assertSame(Format::MicroDvd, Format::fromPath("season1/Movie.SUB"));
        $this->assertSame(Format::Json, Format::fromPath("chapters.json"));
        $this->assertSame(Format::PlainText, Format::fromPath("notes.txt"));
        $this->assertSame(Format::Ass, Format::fromPath("signs.ssa"));
        $this->assertSame(Format::Ttml, Format::fromPath("movie.dfxp"));
        $this->assertSame(Format::Tsv, Format::fromPath("script.TSV"));
        $this->assertSame(Format::YouTubeTimedText, Format::fromPath("video.en.json3"));
        $this->assertNull(Format::fromPath("README"));
        $this->assertNull(Format::fromPath("report.doc"));
    }


    public function testFfMetadataLoadsByItsExtensionButIsNotDetected(): void
    {
        $this->assertSame(Format::FfMetadataChapters, Format::fromPath("chapters.ffmeta"));
        $this->assertFalse(Format::FfMetadataChapters->isAutoDetected());
        $this->assertNull(Format::detect(file_get_contents(self::FILES . "chapters/ffmetadata/real/m4b_audiobook.ffmeta")));
    }


    public function testExtensions(): void
    {
        $this->assertSame(["ass", "ssa"], Format::Ass->extensions());
        $this->assertSame(["txt"], Format::Mpl2->extensions());
        $this->assertSame(["txt"], Format::OgmChapters->extensions());

        foreach (Format::cases() as $format) {
            $this->assertNotEmpty($format->extensions(), $format->value);
        }
    }


    public function testReadAndWriteSupport(): void
    {
        $readOnly = array_filter(Format::cases(), fn (Format $format): bool => !$format->canWrite());
        $this->assertSame(
            ["vobsub"],
            self::sortedNames($readOnly)
        );

        $writeOnly = array_filter(Format::cases(), fn (Format $format): bool => !$format->canRead());
        $this->assertSame(["txt"], self::sortedNames($writeOnly));
    }


    public function testChaptersAndCloudSpeechJsonAreNotAutoDetected(): void
    {
        $notDetected = array_filter(Format::cases(), fn (Format $format): bool => !$format->isAutoDetected());

        $this->assertSame(self::sortedNames(self::NOT_AUTO_DETECTED), self::sortedNames($notDetected));
    }


    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function fixtures(): array
    {
        $fixtures = [];
        foreach (json_decode(file_get_contents(self::FILES . "format/fixture-formats.json"), true) as $path => $formats) {
            $fixtures[$path] = [$path, $formats["fromPath"], self::DETECTED_SINCE_VERSION_1[$path] ?? $formats["detect"]];
        }

        return $fixtures;
    }


    #[DataProvider("fixtures")]
    public function testFromPathGivesTheFormatOfVersion1(string $path, ?string $byPath): void
    {
        $this->assertSame($byPath, Format::fromPath($path)?->value);
    }


    #[DataProvider("fixtures")]
    public function testDetectGivesTheFormatOfVersion1ExceptForFormatsThatAreNotAutoDetected(string $path, ?string $byPath, ?string $detected): void
    {
        $format = Format::detect(file_get_contents(self::FILES . $path));

        $expected = $detected === null || !Format::from($detected)->isAutoDetected() ? null : $detected;
        $this->assertSame($expected, $format?->value);
    }


    public function testRoundTripGivesTheOutputOfVersion1(): void
    {
        $content  = file_get_contents(self::FILES . "srt/real/own_styled.srt");
        $expected = file_get_contents(self::FILES . "format/own_styled.1x.srt");

        $this->assertSame($expected, Subtitle::fromString($content, Format::SubRip)->toString(Format::SubRip));
        $this->assertSame($expected, Subtitle::fromStringAutoDetectFormat($content)->toString(Format::SubRip));
    }


    public function testFormatsCommandPrintsTheFormatTable(): void
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        $code    = (new Cli\Application(...$streams))->run(["subtitle-toolbox", "formats"]);
        rewind($streams[1]);

        $this->assertSame(0, $code);
        $this->assertSame(file_get_contents(self::FILES . "format/formats.txt"), stream_get_contents($streams[1]));
    }


    public function testSubtitleHasNoMethodsOfVersion1(): void
    {
        foreach (["parse", "format", "detectParser"] as $method) {
            $this->assertFalse(method_exists(Subtitle::class, $method), $method);
        }
    }


    public function testNoPublicMethodTakesAFormatNameOrAClassName(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . "/../src")) as $file) {
            $relative = substr($file->getPathname(), strlen(__DIR__ . "/../src/"));
            if ($file->getExtension() !== "php" || str_starts_with($relative, "Cli/")) {
                continue;
            }
            $class = "SubtitleToolbox\\" . str_replace("/", "\\", substr($relative, 0, -4));
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                // findFormatData() and setFormatData() take a format data key string such as "sami", not a Format.
                if (in_array($method->getName(), ["findFormatData", "setFormatData"], true)) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $type = (string) $parameter->getType();
                    if (preg_match('/(?:^|\|)\??string(?:$|\|)/', $type) === 1
                        && preg_match('/(?:format|parser|formatter)(?:class|name)?$/i', $parameter->getName()) === 1) {
                        $found[] = "$class::{$method->getName()}(\${$parameter->getName()})";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($found)));
    }


    /**
     * @param array<Format> $formats
     *
     * @return list<string>
     */
    private static function sortedNames(array $formats): array
    {
        $names = array_map(fn (Format $format): string => $format->value, array_values($formats));
        sort($names);

        return $names;
    }
}
