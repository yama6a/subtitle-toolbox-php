<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

/**
 * Compares the output of the transcript formatters with the write goldens.
 * After an intended change, run tests/files/generate-write-goldens.php and review the diff.
 */
class TranscriptWriteGoldensTest extends TestCase
{
    /**
     * @return array<string, array{string, Format, Format, string}>
     */
    public static function goldens(): array
    {
        return WriteGoldens::cases();
    }


    #[DataProvider("goldens")]
    public function testTheOutputMatchesTheGolden(string $path, Format $inputFormat, Format $outputFormat, string $golden): void
    {
        $this->assertStringEqualsFile(TestFiles::DIR . $golden, WriteGoldens::render($path, $inputFormat, $outputFormat));
    }


    #[DataProvider("goldens")]
    public function testTheDefaultReadOptionsGiveTheSameRoundTrip(string $path, Format $inputFormat, Format $outputFormat, string $golden): void
    {
        if ($inputFormat !== $outputFormat) {
            $this->markTestSkipped("A conversion is no round trip.");
        }

        $this->assertStringEqualsFile(TestFiles::DIR . $golden, WriteGoldens::render($path, $inputFormat, $outputFormat, new ReadOptions()));
    }
}
