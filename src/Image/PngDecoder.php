<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use SubtitleToolbox\Dependency;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Reads non-interlaced PNG files of every color type and bit depth. Spec: https://www.w3.org/TR/png-3/
 */
final class PngDecoder
{
    /** @internal */
    public const SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const IHDR_LENGTH = 13;

    // A chunk has 4 bytes of length and 4 bytes of type before its data, and a 4-byte CRC after it.
    private const CHUNK_HEADER   = 8;
    private const CHUNK_OVERHEAD = 12;

    private const FILTER_NONE    = 0;
    private const FILTER_SUB     = 1;
    private const FILTER_UP      = 2;
    private const FILTER_AVERAGE = 3;
    private const FILTER_PAETH   = 4;

    private const COLOR_GRAY       = 0;
    private const COLOR_RGB        = 2;
    /** @internal */
    public const COLOR_PALETTE = 3;
    private const COLOR_GRAY_ALPHA = 4;
    /** @internal */
    public const COLOR_RGBA = 6;

    /** Color type => channels and the allowed bit depths. */
    private const COLOR_TYPES = [
        self::COLOR_GRAY       => [1, [1, 2, 4, 8, 16]],
        self::COLOR_RGB        => [3, [8, 16]],
        self::COLOR_PALETTE    => [1, [1, 2, 4, 8]],
        self::COLOR_GRAY_ALPHA => [2, [8, 16]],
        self::COLOR_RGBA       => [4, [8, 16]],
    ];


    /**
     * Decodes a PNG string to its size and a row-major list of 0xRRGGBBAA integers, the input of PngEncoder::encode().
     *
     * @return array{width: int, height: int, pixels: list<int>}
     */
    public static function decode(string $png): array
    {
        $chunks = self::readChunks($png);
        $header = self::readHeader($chunks);
        $rows   = self::readRows($chunks, $header);
        ["width" => $width, "height" => $height, "depth" => $depth, "color" => $color, "channels" => $channels] = $header;
        if ($color === self::COLOR_RGBA && $depth === 8) {
            return ["width" => $width, "height" => $height, "pixels" => array_values(unpack("N*", implode("", $rows)))];
        }

        $palette = $color === self::COLOR_PALETTE ? self::readPalette($chunks["PLTE"][0] ?? "", $chunks["tRNS"][0] ?? "") : [];
        if ($color === self::COLOR_PALETTE && $depth === 8) {
            $rgbaOf = [];
            for ($entry = 0; $entry < 256; $entry++) {
                $rgbaOf[chr($entry)] = pack("N", $palette[$entry] ?? 0);
            }

            return ["width" => $width, "height" => $height, "pixels" => array_values(unpack("N*", strtr(implode("", $rows), $rgbaOf)))];
        }

        $key     = isset($chunks["tRNS"]) && $color !== self::COLOR_PALETTE
            ? array_values(unpack("n*", $chunks["tRNS"][0]))
            : null;

        $pixels = [];
        foreach ($rows as $row) {
            $samples = array_chunk(array_slice(self::samples($row, $depth), 0, $width * $channels), $channels);
            foreach ($samples as $sample) {
                $pixels[] = self::toRgba($sample, $color, $depth, $palette, $key);
            }
        }

        return ["width" => $width, "height" => $height, "pixels" => $pixels];
    }


    /**
     * Decodes an 8-bit indexed PNG string to its size, its palette and one palette entry byte per pixel, row-major.
     * Returns null for a PNG of another color type or bit depth. decode() reads an entry past the palette as 0x00000000.
     *
     * @return array{width: int, height: int, palette: list<int>, indexes: string}|null palette colors as 0xRRGGBBAA
     *
     * @internal
     */
    public static function decodeIndexed(string $png): ?array
    {
        $chunks = self::readChunks($png);
        $header = self::readHeader($chunks);
        if ($header["color"] !== self::COLOR_PALETTE || $header["depth"] !== 8) {
            return null;
        }

        return ["width"   => $header["width"], "height" => $header["height"],
                "palette" => self::readPalette($chunks["PLTE"][0] ?? "", $chunks["tRNS"][0] ?? ""),
                "indexes" => implode("", self::readRows($chunks, $header))];
    }


    /**
     * Inflates the IDAT chunks and removes the filters.
     *
     * @param array<string, list<string>> $chunks
     * @param array{width: int, height: int, depth: int, color: int, channels: int} $header
     * @return list<string> the rows without their filter type byte
     */
    private static function readRows(array $chunks, array $header): array
    {
        ["width" => $width, "height" => $height, "depth" => $depth, "channels" => $channels] = $header;

        $rowLength = intdiv($width * $channels * $depth + 7, 8);
        $dataSize  = ($rowLength + 1) * $height;
        Dependency::check("gzuncompress", "Cannot decode a PNG: PHP has no ext-zlib. Use a PHP build with zlib, " .
                                          "for example one compiled with --with-zlib.");
        $scanlines = @gzuncompress(implode("", $chunks["IDAT"] ?? []), $dataSize);
        if ($scanlines === false) {
            throw new InvalidArgumentException("Cannot decode the PNG: its IDAT chunks hold no valid zlib data, " .
                                               "or more data than {$width}x{$height} pixels need.");
        }

        if (strlen($scanlines) < $dataSize) {
            throw new InvalidArgumentException("Cannot decode a PNG of {$width}x{$height} pixels from " . strlen($scanlines) .
                                               " bytes of image data: it needs $dataSize.");
        }

        return self::unfilter($scanlines, $rowLength, $height, max(1, intdiv($channels * $depth, 8)));
    }


    /**
     * Reads and checks the IHDR chunk.
     *
     * @param array<string, list<string>> $chunks
     * @return array{width: int, height: int, depth: int, color: int, channels: int}
     */
    private static function readHeader(array $chunks): array
    {
        $header = $chunks["IHDR"][0] ?? "";
        if (strlen($header) !== self::IHDR_LENGTH) {
            throw new InvalidArgumentException("Cannot decode the PNG: it has no valid IHDR chunk.");
        }

        ["width" => $width, "height" => $height, "depth" => $depth, "color" => $color, "compression" => $compression,
         "filter" => $filter, "interlace" => $interlace] = unpack("Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace", $header);
        [$channels, $depths] = self::COLOR_TYPES[$color] ?? [0, []];
        if ($width < 1 || $height < 1 || !in_array($depth, $depths, true) || $compression !== 0 || $filter !== 0 || $interlace !== 0) {
            throw new InvalidArgumentException("Cannot decode a PNG of {$width}x{$height} pixels with color type $color, bit depth " .
                                               "$depth and interlace method $interlace: only non-interlaced PNG files are supported.");
        }

        CueImage::checkSize($width, $height, "decode the PNG");

        return ["width" => $width, "height" => $height, "depth" => $depth, "color" => $color, "channels" => $channels];
    }


    /**
     * @return array<string, list<string>> chunk type => data of each chunk of that type, in file order
     */
    private static function readChunks(string $png): array
    {
        if (!str_starts_with($png, self::SIGNATURE)) {
            throw new InvalidArgumentException("Cannot decode the PNG: the data does not start with the PNG signature.");
        }

        $chunks = [];
        $length = strlen($png);
        $offset = strlen(self::SIGNATURE);
        while ($offset < $length) {
            if ($length - $offset < self::CHUNK_OVERHEAD || $length - $offset - self::CHUNK_OVERHEAD < unpack("N", $png, $offset)[1]) {
                throw new InvalidArgumentException("Cannot decode the PNG: the chunk at byte $offset is cut off.");
            }

            ["size" => $size, "type" => $type] = unpack("Nsize/a4type", $png, $offset);
            $chunks[$type][] = substr($png, $offset + self::CHUNK_HEADER, $size);
            $offset         += self::CHUNK_OVERHEAD + $size;
            if ($type === "IEND") {
                break;
            }
        }

        return $chunks;
    }


    /**
     * @return list<string> the rows without their filter type byte
     */
    private static function unfilter(string $scanlines, int $rowLength, int $height, int $bytesPerPixel): array
    {
        $rows     = [];
        $previous = array_fill(0, $rowLength, 0);
        for ($index = 0; $index < $height; $index++) {
            $offset = $index * ($rowLength + 1);
            $type   = ord($scanlines[$offset]);
            $row    = substr($scanlines, $offset + 1, $rowLength);
            if ($type === self::FILTER_NONE) {
                $rows[]   = $row;
                $previous = null;
                continue;
            }
            if ($type > self::FILTER_PAETH) {
                throw new InvalidArgumentException("Cannot decode the PNG: row $index has the unknown filter type $type.");
            }

            $previous ??= array_values(unpack("C*", $rows[$index - 1]));
            $bytes      = array_values(unpack("C*", $row));
            for ($position = 0; $position < $rowLength; $position++) {
                $left  = $position >= $bytesPerPixel ? $bytes[$position - $bytesPerPixel] : 0;
                $up    = $previous[$position];
                $upper = $position >= $bytesPerPixel ? $previous[$position - $bytesPerPixel] : 0;

                $bytes[$position] = ($bytes[$position] + match ($type) {
                    self::FILTER_SUB     => $left,
                    self::FILTER_UP      => $up,
                    self::FILTER_AVERAGE => ($left + $up) >> 1,
                    self::FILTER_PAETH   => self::paeth($left, $up, $upper),
                }) & 0xFF;
            }
            $rows[]   = pack("C*", ...$bytes);
            $previous = $bytes;
        }

        return $rows;
    }


    private static function paeth(int $left, int $up, int $upperLeft): int
    {
        $estimate = $left + $up - $upperLeft;
        $toLeft   = abs($estimate - $left);
        $toUp     = abs($estimate - $up);
        $toCorner = abs($estimate - $upperLeft);

        return match (true) {
            $toLeft <= $toUp && $toLeft <= $toCorner => $left,
            $toUp <= $toCorner                       => $up,
            default                                  => $upperLeft,
        };
    }


    /**
     * @return list<int>
     */
    private static function samples(string $row, int $depth): array
    {
        if ($depth >= 8) {
            return array_values(unpack($depth === 8 ? "C*" : "n*", $row));
        }

        $samples = [];
        $mask    = (1 << $depth) - 1;
        foreach (unpack("C*", $row) as $byte) {
            for ($shift = 8 - $depth; $shift >= 0; $shift -= $depth) {
                $samples[] = ($byte >> $shift) & $mask;
            }
        }

        return $samples;
    }


    /**
     * @return list<int> entry => 0xRRGGBBAA
     */
    private static function readPalette(string $entries, string $alphas): array
    {
        $palette = [];
        foreach (str_split($entries, 3) as $index => $entry) {
            if (strlen($entry) === 3) {
                $palette[] = unpack("N", $entry . ($alphas[$index] ?? "\xFF"))[1];
            }
        }

        return $palette;
    }


    /**
     * @param list<int> $sample
     * @param list<int> $palette
     * @param list<int>|null $key the tRNS samples that mark a transparent gray or RGB pixel
     */
    private static function toRgba(array $sample, int $color, int $depth, array $palette, ?array $key): int
    {
        if ($color === self::COLOR_PALETTE) {
            return $palette[$sample[0]] ?? 0;
        }

        $transparent = $key !== null && $sample === array_slice($key, 0, count($sample));
        $bytes       = $depth === 16
            ? array_map(fn (int $value): int => $value >> 8, $sample)
            : array_map(fn (int $value): int => intdiv($value * 255, (1 << $depth) - 1), $sample);

        [$red, $green, $blue, $alpha] = match ($color) {
            self::COLOR_GRAY       => [$bytes[0], $bytes[0], $bytes[0], 255],
            self::COLOR_GRAY_ALPHA => [$bytes[0], $bytes[0], $bytes[0], $bytes[1]],
            self::COLOR_RGB        => [$bytes[0], $bytes[1], $bytes[2], 255],
            self::COLOR_RGBA       => $bytes,
        };

        return $red << 24 | $green << 16 | $blue << 8 | ($transparent ? 0 : $alpha);
    }
}
