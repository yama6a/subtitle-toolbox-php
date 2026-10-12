<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class PngEncoder
{
    private const STORED_BLOCK_MAX = 65535;


    /**
     * Encodes $pixels, a row-major list of 0xRRGGBBAA integers, as an 8-bit RGBA PNG string.
     *
     * @param list<int> $pixels
     * @param bool $compress false writes uncompressed deflate blocks, which is also the fallback without ext-zlib
     */
    public static function encode(int $width, int $height, array $pixels, bool $compress = true): string
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("Cannot encode a PNG of {$width}x{$height} pixels: " .
                                               "the width and the height must be at least 1.");
        }
        if (count($pixels) !== $width * $height) {
            throw new InvalidArgumentException("Cannot encode a PNG of {$width}x{$height} pixels from " .
                                               count($pixels) . " pixels: the counts must match.");
        }

        $pixels    = array_values($pixels);
        $scanlines = "";
        for ($row = 0; $row < $height; $row++) {
            $scanlines .= "\0" . pack("N*", ...array_slice($pixels, $row * $width, $width));
        }

        return self::png(pack("NNCCCCC", $width, $height, 8, PngDecoder::COLOR_RGBA, 0, 0, 0), "", $scanlines, $compress);
    }


    /**
     * Encodes one palette entry byte per pixel, row-major, as an 8-bit indexed PNG string.
     *
     * @param list<int> $palette up to 256 colors as 0xRRGGBBAA
     *
     * @internal
     */
    public static function encodeIndexed(int $width, int $height, string $indexes, array $palette): string
    {
        $colors = "";
        $alphas = "";
        foreach ($palette as $color) {
            $colors .= substr(pack("N", $color), 0, 3);
            $alphas .= chr($color & 0xFF);
        }
        $alphas = rtrim($alphas, "\xFF");

        return self::png(pack("NNCCCCC", $width, $height, 8, PngDecoder::COLOR_PALETTE, 0, 0, 0),
                         self::chunk("PLTE", $colors) . ($alphas === "" ? "" : self::chunk("tRNS", $alphas)),
                         "\0" . implode("\0", str_split($indexes, $width)), true);
    }


    private static function png(string $header, string $paletteChunks, string $scanlines, bool $compress): string
    {
        // Level 6, not 9: a 1,500-cue PGS film parses about 4 times faster, for 2.5 KB instead of 1 KB per image.
        $imageData = $compress && function_exists("gzcompress")
            ? gzcompress($scanlines, 6)
            : self::storeUncompressed($scanlines);

        return PngDecoder::SIGNATURE
            . self::chunk("IHDR", $header)
            . $paletteChunks
            . self::chunk("IDAT", $imageData)
            . self::chunk("IEND", "");
    }


    private static function chunk(string $type, string $data): string
    {
        return pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
    }


    private static function storeUncompressed(string $data): string
    {
        $blocks = str_split($data, self::STORED_BLOCK_MAX);
        $stream = "\x78\x01";
        foreach ($blocks as $index => $block) {
            $length  = strlen($block);
            $stream .= chr($index === count($blocks) - 1 ? 1 : 0) . pack("vv", $length, ~$length & 0xFFFF) . $block;
        }

        return $stream . hash("adler32", $data, true);
    }
}
