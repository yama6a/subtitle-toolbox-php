<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Tests\Support\ImageGoldens;
use SubtitleToolbox\Tests\Support\TestFiles;

/**
 * Compares the pixels of every cue of each PGS and VobSub fixture, and the PGS output of each VobSub track, with the
 * goldens in the expected/ folders. After an intended change, run tests/files/generate-image-goldens.php and review
 * the diff and the PNG files.
 */
class ImageGoldensTest extends TestCase
{
    /**
     * @return array<string, array{string, ?int, string}>
     */
    public static function goldens(): array
    {
        return ImageGoldens::cases();
    }


    /**
     * @return array<string, array{string, int, string}>
     */
    public static function writeGoldens(): array
    {
        return ImageGoldens::writeCases();
    }


    #[DataProvider("goldens")]
    public function testThePixelsOfEachCueMatchTheGolden(string $fixture, ?int $track, string $golden): void
    {
        $this->assertStringEqualsFile(TestFiles::DIR . "$golden.json", ImageGoldens::render(ImageGoldens::parse($fixture, $track)));
    }


    #[DataProvider("goldens")]
    public function testThePngOfTheFirstCueShowsThePixelsOfTheGolden(string $fixture, ?int $track, string $golden): void
    {
        $first = json_decode(file_get_contents(TestFiles::DIR . "$golden.json"), true)[0];
        $png   = file_get_contents(TestFiles::DIR . "$golden.png");

        $this->assertSame([$first["width"], $first["height"], $first["rgbaSha256"]],
                          [PngDecoder::decode($png)["width"], PngDecoder::decode($png)["height"], ImageGoldens::rgbaHash($png)]);
    }


    #[DataProvider("writeGoldens")]
    public function testEachVobSubTrackConvertsToTheGoldenSup(string $fixture, int $track, string $golden): void
    {
        $this->assertStringEqualsFile(TestFiles::DIR . $golden, ImageGoldens::parse($fixture, $track)->toString(Format::Pgs));
    }


    public function testEveryGoldenFileHasAFixture(): void
    {
        $expected = [];
        foreach (ImageGoldens::cases() as [$fixture, , $golden]) {
            array_push($expected, "$golden.json", "$golden.png", ...(str_ends_with($fixture, ".idx") ? ["$golden.to.sup"] : []));
        }
        $files = array_map(fn (string $path): string => substr($path, strlen(TestFiles::DIR)), [
            ...glob(TestFiles::DIR . "pgs/expected/*"), ...glob(TestFiles::DIR . "vobsub/expected/*"),
            ...glob(TestFiles::DIR . "mpegts/expected/*.{json,png}", GLOB_BRACE), ...glob(TestFiles::DIR . "mkv/expected/*.{json,png}", GLOB_BRACE),
        ]);

        $this->assertEqualsCanonicalizing($expected, $files);
    }
}
