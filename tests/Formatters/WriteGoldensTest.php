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
 * Compares the output of the formatters with the write goldens.
 * After an intended change, run tests/files/generate-write-goldens.php and review the diff.
 */
class WriteGoldensTest extends TestCase
{
    /**
     * @return array<string, array{string, Format, Format, string, ReadOptions}>
     */
    public static function goldens(): array
    {
        return WriteGoldens::cases();
    }


    #[DataProvider("goldens")]
    public function testTheOutputMatchesTheGolden(string $path, Format $inputFormat, Format $outputFormat, string $golden, ReadOptions $options): void
    {
        $this->assertMatchesGolden($path, $golden, WriteGoldens::render($path, $inputFormat, $outputFormat, $options));
    }


    /**
     * @return array<string, array{string, Format, Format, string, ReadOptions}>
     */
    public static function roundTrips(): array
    {
        return array_filter(WriteGoldens::cases(), fn (array $case): bool => $case[1] === $case[2]);
    }


    #[DataProvider("roundTrips")]
    public function testTheDefaultReadOptionsGiveTheSameRoundTrip(string $path, Format $inputFormat, Format $outputFormat, string $golden, ReadOptions $options): void
    {
        $this->assertMatchesGolden($path, $golden, WriteGoldens::render($path, $inputFormat, $outputFormat, new ReadOptions()));
    }


    private function assertMatchesGolden(string $path, string $golden, string $output): void
    {
        if (is_file(TestFiles::DIR . $golden)) {
            $this->assertStringEqualsFile(TestFiles::DIR . $golden, $output);

            return;
        }

        $this->assertSame(WriteGoldens::normalize(file_get_contents(TestFiles::DIR . $path)), WriteGoldens::normalize($output), "No golden file $golden, so the output must equal the input.");
    }
}
