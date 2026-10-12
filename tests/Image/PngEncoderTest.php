<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PngEncoderTest extends TestCase
{
    /**
     * @return list<int>
     */
    private function makePixels(int $width, int $height): array
    {
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixels[] = (($x * 7) % 256) << 24 | (($y * 13) % 256) << 16 | (($x + $y) % 256) << 8 | ($x % 2 ? 0xFF : 0x00);
            }
        }

        return $pixels;
    }


    /**
     * @return array<string, array{string, string}>
     */
    private function readChunks(string $png): array
    {
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));

        $chunks = [];
        $offset = 8;
        while ($offset < strlen($png)) {
            $length = unpack("N", $png, $offset)[1];
            $type   = substr($png, $offset + 4, 4);
            $data   = substr($png, $offset + 8, $length);
            $crc    = unpack("N", $png, $offset + 8 + $length)[1];

            $this->assertSame(crc32($type . $data), $crc, "CRC of chunk $type");
            $chunks[] = [$type, $data];
            $offset  += 12 + $length;
        }
        $this->assertSame(strlen($png), $offset);

        return $chunks;
    }


    private function inflateStoredBlocks(string $stream): string
    {
        $this->assertSame("\x78\x01", substr($stream, 0, 2));

        $data   = "";
        $offset = 2;
        do {
            $final   = ord($stream[$offset]) & 1;
            $length  = unpack("v", $stream, $offset + 1)[1];
            $inverse = unpack("v", $stream, $offset + 3)[1];

            $this->assertSame(0, ord($stream[$offset]) & 0b110, "block type is stored");
            $this->assertSame($length, ~$inverse & 0xFFFF);
            $data   .= substr($stream, $offset + 5, $length);
            $offset += 5 + $length;
        } while (!$final);

        $this->assertSame(hash("adler32", $data, true), substr($stream, $offset));

        return $data;
    }


    /**
     * @param list<int> $pixels
     */
    private function expectedScanlines(int $width, array $pixels): string
    {
        return implode("", array_map(fn (array $row): string => "\0" . pack("N*", ...$row), array_chunk($pixels, $width)));
    }


    public static function sizeProvider(): array
    {
        return [
            "one pixel"                  => [1, 1],
            "subtitle line"              => [64, 9],
            "more than one stored block" => [200, 100],
        ];
    }


    #[DataProvider("sizeProvider")]
    public function testUncompressedPngHasValidChunksAndTheExactPixels(int $width, int $height): void
    {
        $pixels = $this->makePixels($width, $height);
        $chunks = $this->readChunks(PngEncoder::encode($width, $height, $pixels, false));

        $this->assertSame(["IHDR", "IDAT", "IEND"], array_column($chunks, 0));
        $this->assertSame(pack("NNCCCCC", $width, $height, 8, 6, 0, 0, 0), $chunks[0][1]);
        $this->assertSame($this->expectedScanlines($width, $pixels), $this->inflateStoredBlocks($chunks[1][1]));
        $this->assertSame("", $chunks[2][1]);
    }


    #[DataProvider("sizeProvider")]
    public function testCompressedPngHoldsTheExactPixels(int $width, int $height): void
    {
        if (!function_exists("gzuncompress")) {
            $this->markTestSkipped("ext-zlib is not loaded");
        }

        $pixels = $this->makePixels($width, $height);
        $chunks = $this->readChunks(PngEncoder::encode($width, $height, $pixels));

        $this->assertSame(["IHDR", "IDAT", "IEND"], array_column($chunks, 0));
        $this->assertSame($this->expectedScanlines($width, $pixels), gzuncompress($chunks[1][1]));
    }


    #[DataProvider("sizeProvider")]
    public function testGdDecodesBothPngVariants(int $width, int $height): void
    {
        if (!function_exists("imagecreatefromstring")) {
            $this->markTestSkipped("ext-gd is not loaded");
        }

        $pixels = $this->makePixels($width, $height);
        foreach ([true, false] as $compress) {
            $image = imagecreatefromstring(PngEncoder::encode($width, $height, $pixels, $compress));

            $this->assertSame($width, imagesx($image));
            $this->assertSame($height, imagesy($image));
            foreach ([0, $width * $height - 1, intdiv($width * $height, 2)] as $index) {
                $rgba  = $pixels[$index];
                $color = imagecolorsforindex($image, imagecolorat($image, $index % $width, intdiv($index, $width)));

                $this->assertSame([
                    "red"   => $rgba >> 24 & 0xFF,
                    "green" => $rgba >> 16 & 0xFF,
                    "blue"  => $rgba >> 8 & 0xFF,
                    "alpha" => 127 - (($rgba & 0xFF) >> 1),
                ], $color);
            }
        }
    }


    public function testIndexedPngHoldsThePaletteAndTheEntryOfEachPixel(): void
    {
        $palette = [0x00000000, 0xFFFFFFFF, 0x10203080, 0xC0C0C0FF];
        $indexes = "";
        for ($pixel = 0; $pixel < 37 * 5; $pixel++) {
            $indexes .= chr($pixel * 7 % 4);
        }

        $png = PngEncoder::encodeIndexed(37, 5, $indexes, $palette);

        $this->assertSame(["width" => 37, "height" => 5, "palette" => $palette, "indexes" => $indexes], PngDecoder::decodeIndexed($png));
        $this->assertSame(array_map(fn (string $entry): int => $palette[ord($entry)], str_split($indexes)), PngDecoder::decode($png)["pixels"]);
        $this->assertSame("\x00\xFF\x80", array_column($this->readChunks($png), 1, 0)["tRNS"]);
    }


    public function testIndexedPngOfOpaqueColorsHasNoTransparencyChunk(): void
    {
        $png = PngEncoder::encodeIndexed(2, 1, "\0\1", [0x000000FF, 0xFFFFFFFF]);

        $this->assertSame(["IHDR", "PLTE", "IDAT", "IEND"], array_column($this->readChunks($png), 0));
        $this->assertSame([0x000000FF, 0xFFFFFFFF], PngDecoder::decode($png)["pixels"]);
    }


    public function testRejectsAPixelCountThatDoesNotMatchTheSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("from 3 pixels");

        PngEncoder::encode(2, 2, [0, 0, 0]);
    }


    public function testRejectsAnEmptySize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PngEncoder::encode(0, 1, []);
    }
}
