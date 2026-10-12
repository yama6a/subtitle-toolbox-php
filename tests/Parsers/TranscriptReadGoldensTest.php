<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\ReadGoldens;
use SubtitleToolbox\Tests\Support\TestFiles;

/**
 * Compares the full parse result of each real transcript file with its golden file.
 * After an intended change, run tests/files/generate-read-goldens.php and review the diff.
 */
class TranscriptReadGoldensTest extends TestCase
{
    /**
     * @return array<string, array{string, Format, ReadOptions, string}>
     */
    public static function goldens(): array
    {
        return ReadGoldens::cases();
    }


    #[DataProvider("goldens")]
    public function testTheParseResultMatchesTheGolden(string $path, Format $format, ReadOptions $options, string $golden): void
    {
        $this->assertStringEqualsFile(TestFiles::DIR . $golden, ReadGoldens::render($path, $format, $options));
    }


    public function testEveryGoldenHasARealFile(): void
    {
        $this->assertEqualsCanonicalizing(array_column(ReadGoldens::cases(), 3), ReadGoldens::goldenFiles());
    }
}
