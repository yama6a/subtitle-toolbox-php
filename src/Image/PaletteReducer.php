<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
final class PaletteReducer
{
    public const MAX_COLORS = 256;

    // A color tuple holds the red, green, blue and alpha channels, then the pixel count and the color.
    private const CHANNELS = 4;
    private const COUNT    = 4;
    private const COLOR    = 5;


    /**
     * Maps 0xRRGGBBAA pixels to at most $maxColors palette entries, with median cut when the image has more colors.
     *
     * Entry 0 is a fully transparent color when the image has one. A reduced palette keeps entry 0 for all pixels
     * with alpha 0, and median cut shares the other entries.
     *
     * @param list<int> $pixels
     * @return array{palette: list<int>, indexes: string} the entry colors and one entry byte per pixel
     */
    public static function reduce(array $pixels, int $maxColors = self::MAX_COLORS): array
    {
        if ($maxColors < 2 || $maxColors > self::MAX_COLORS) {
            throw new InvalidArgumentException("The color count must be from 2 to " . self::MAX_COLORS . ", got $maxColors.");
        }

        $counts = array_count_values($pixels);
        if (count($counts) <= $maxColors) {
            $palette = self::transparentFirst(array_keys($counts));
            $entryOf = array_flip($palette);
        } else {
            [$palette, $entryOf] = self::medianCut($counts, $maxColors);
        }

        $characters = array_map("chr", range(0, self::MAX_COLORS - 1));
        $indexes    = "";
        foreach ($pixels as $pixel) {
            $indexes .= $characters[$entryOf[$pixel]];
        }

        return ["palette" => $palette, "indexes" => $indexes];
    }


    /**
     * Returns what reduce() returns for the pixels of the indexed images, one image after the other, without a list of
     * pixels. Returns null when the images have more than 256 colors together, so reduce() must cut the colors.
     *
     * @param list<array{palette: list<int>, indexes: string}> $images colors as 0xRRGGBBAA and one entry byte per pixel
     * @return array{palette: list<int>, indexes: string}|null
     *
     * @internal
     */
    public static function reduceIndexed(array $images): ?array
    {
        $firstPixel = [];
        $offset     = 0;
        foreach ($images as ["palette" => $palette, "indexes" => $indexes]) {
            foreach (str_split(count_chars($indexes, 3)) as $entry) {
                $color              = $palette[ord($entry)] ?? 0;
                $firstPixel[$color] = min($firstPixel[$color] ?? PHP_INT_MAX, $offset + strpos($indexes, $entry));
            }
            $offset += strlen($indexes);
        }
        if (count($firstPixel) > self::MAX_COLORS) {
            return null;
        }

        asort($firstPixel);
        $colors  = self::transparentFirst(array_keys($firstPixel));
        $entryOf = array_flip($colors);
        $joined  = "";
        foreach ($images as ["palette" => $palette, "indexes" => $indexes]) {
            $entries = count_chars($indexes, 3);
            $mapped  = "";
            foreach (str_split($entries) as $entry) {
                $mapped .= chr($entryOf[$palette[ord($entry)] ?? 0]);
            }
            $joined .= strtr($indexes, $entries, $mapped);
        }

        return ["palette" => $colors, "indexes" => $joined];
    }


    /**
     * Moves the fully transparent colors to the front and keeps the order otherwise.
     *
     * @param list<int> $colors
     * @return list<int>
     */
    private static function transparentFirst(array $colors): array
    {
        usort($colors, fn (int $a, int $b): int => ($a & 0xFF) === 0 ? (($b & 0xFF) === 0 ? 0 : -1) : (($b & 0xFF) === 0 ? 1 : 0));

        return $colors;
    }


    /**
     * @param array<int, int> $counts color => pixel count
     * @return array{list<int>, array<int, int>} the palette and the entry of each color
     */
    private static function medianCut(array $counts, int $maxColors): array
    {
        $entryOf = [];
        $opaque  = [];
        foreach ($counts as $color => $count) {
            if (($color & 0xFF) === 0) {
                $entryOf[$color] = 0;
            } else {
                $opaque[] = [$color >> 24 & 0xFF, $color >> 16 & 0xFF, $color >> 8 & 0xFF, $color & 0xFF, $count, $color];
            }
        }

        $boxes = $opaque === [] ? [] : [self::box($opaque)];
        while ($boxes !== [] && count($boxes) < $maxColors - 1) {
            $ranges   = array_column($boxes, 2);
            $boxIndex = array_search(max($ranges), $ranges, true);
            if ($ranges[$boxIndex] === 0) {
                break;
            }

            [$colors, $channel] = $boxes[$boxIndex];
            usort($colors, fn (array $a, array $b): int => $a[$channel] <=> $b[$channel] ?: $a[self::COLOR] <=> $b[self::COLOR]);
            $half  = array_sum(array_column($colors, self::COUNT)) / 2;
            $split = 1;
            for ($sum = $colors[0][self::COUNT]; $split < count($colors) - 1 && $sum + $colors[$split][self::COUNT] <= $half; $split++) {
                $sum += $colors[$split][self::COUNT];
            }
            array_splice($boxes, $boxIndex, 1, [self::box(array_slice($colors, 0, $split)), self::box(array_slice($colors, $split))]);
        }

        $palette = [0];
        foreach (array_column($boxes, 0) as $box) {
            $total = array_sum(array_column($box, self::COUNT));
            $mean  = 0;
            for ($channel = 0; $channel < self::CHANNELS; $channel++) {
                $sum  = array_sum(array_map(fn (array $color): int => $color[$channel] * $color[self::COUNT], $box));
                $mean = $mean << 8 | (int) round($sum / $total);
            }
            foreach ($box as $color) {
                $entryOf[$color[self::COLOR]] = count($palette);
            }
            $palette[] = $mean;
        }

        return [$palette, $entryOf];
    }


    /**
     * @param list<array{int, int, int, int, int, int}> $colors red, green, blue, alpha, pixel count and color
     * @return array{list<array{int, int, int, int, int, int}>, int, int} the colors, the channel with the widest range and that range
     */
    private static function box(array $colors): array
    {
        $box = [$colors, 0, 0];
        for ($channel = 0; $channel < self::CHANNELS; $channel++) {
            $values = array_column($colors, $channel);
            if (max($values) - min($values) > $box[2]) {
                $box = [$colors, $channel, max($values) - min($values)];
            }
        }

        return $box;
    }
}
