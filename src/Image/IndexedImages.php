<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\SubtitleCue;

/**
 * Builds image cues from palette-indexed bitmaps, such as PGS objects and DVB subtitle regions.
 *
 * @internal
 */
final class IndexedImages
{
    /**
     * Returns one image of all parts on a transparent background. One part gives an indexed PNG, more parts an RGBA PNG.
     * A later part is drawn over the earlier parts.
     *
     * @param non-empty-list<array{x: int, y: int, width: int, height: int, indexes: string, palette: list<int>}> $parts
     *        one palette entry byte per pixel, row by row, and the 0xRRGGBBAA color of each of the 256 entries
     * @throws ParsingException when the image of the parts is larger than the size limit of CueImage
     */
    public static function cueImage(array $parts, int $screenWidth, int $screenHeight, bool $forced): CueImage
    {
        $screen = [$screenWidth, $screenHeight, $forced];
        if (count($parts) === 1) {
            ["x" => $x, "y" => $y, "width" => $width, "height" => $height] = $parts[0];
            // The palette of the used colors in a fixed order makes the PNG depend on the pixels only, so a check for
            // a repeated image ignores palette entries that no pixel uses.
            $used = PaletteReducer::reduceIndexed([$parts[0]]);

            return new CueImage(PngEncoder::encodeIndexed($width, $height, $used["indexes"], $used["palette"]), $x, $y, $width, $height, ...$screen);
        }

        [$x, $y, $width, $height, $rgba] = self::compose($parts);

        return new CueImage(PngEncoder::encode($width, $height, array_values(unpack("N*", $rgba))), $x, $y, $width, $height, ...$screen);
    }


    /**
     * Returns a cue that shows the image. An image whose center is in the top third of the screen gets Alignment::TopCenter.
     */
    public static function cue(float $start, float $end, CueImage $image): SubtitleCue
    {
        $cue = new SubtitleCue($start, $end);
        $image->applyTo($cue);
        if ((2 * $image->y + $image->height) * 3 < 2 * $image->screenHeight) {
            $cue->setAlignment(Alignment::TopCenter);
        }

        return $cue;
    }


    /**
     * Places the parts on one transparent canvas that covers all of them. Returns the position, size and RGBA bytes of the canvas.
     */
    private static function compose(array $parts): array
    {
        $left   = min(array_column($parts, "x"));
        $top    = min(array_column($parts, "y"));
        $right  = max(array_map(fn (array $part): int => $part["x"] + $part["width"], $parts));
        $bottom = max(array_map(fn (array $part): int => $part["y"] + $part["height"], $parts));
        $width  = $right - $left;
        $height = $bottom - $top;
        $tooLarge = CueImage::sizeLimitError($width, $height);
        if ($tooLarge !== null) {
            throw new ParsingException("Cannot join the parts of one display set: $tooLarge");
        }

        $canvas = array_fill(0, $height, str_repeat("\0\0\0\0", $width));
        foreach ($parts as $index => $part) {
            $rgbaOf = [];
            foreach ($part["palette"] as $entry => $color) {
                $rgbaOf[chr($entry)] = pack("N", $color);
            }

            $overlaps  = array_filter(array_slice($parts, 0, $index), fn (array $earlier): bool => self::overlap($earlier, $part));
            $rgba      = strtr($part["indexes"], $rgbaOf);
            $rowLength = $part["width"] * 4;
            for ($row = 0; $row < $part["height"]; $row++) {
                $source = substr($rgba, $row * $rowLength, $rowLength);
                $line   = &$canvas[$part["y"] - $top + $row];
                if ($overlaps === []) {
                    $line = substr_replace($line, $source, ($part["x"] - $left) * 4, $rowLength);
                    continue;
                }
                for ($column = 0; $column < $part["width"]; $column++) {
                    $offset = ($part["x"] - $left + $column) * 4;
                    $line   = substr_replace($line, self::over(substr($source, $column * 4, 4), substr($line, $offset, 4)), $offset, 4);
                }
            }
            unset($line);
        }

        return [$left, $top, $width, $height, implode("", $canvas)];
    }


    private static function overlap(array $first, array $second): bool
    {
        return $first["x"] < $second["x"] + $second["width"] && $second["x"] < $first["x"] + $first["width"]
            && $first["y"] < $second["y"] + $second["height"] && $second["y"] < $first["y"] + $first["height"];
    }


    /**
     * Returns the RGBA pixel $source drawn over $below with the "source over" operator of straight alpha.
     */
    private static function over(string $source, string $below): string
    {
        $sourceAlpha = ord($source[3]);
        $belowAlpha  = ord($below[3]);
        if ($sourceAlpha === 255 || $belowAlpha === 0) {
            return $source;
        }
        if ($sourceAlpha === 0) {
            return $below;
        }

        $belowWeight = $belowAlpha * (255 - $sourceAlpha);
        $alpha       = $sourceAlpha * 255 + $belowWeight;
        $pixel       = "";
        for ($channel = 0; $channel < 3; $channel++) {
            $pixel .= chr(intdiv(2 * (ord($source[$channel]) * $sourceAlpha * 255 + ord($below[$channel]) * $belowWeight) + $alpha, 2 * $alpha));
        }

        return $pixel . chr(intdiv(2 * $alpha + 255, 2 * 255));
    }
}
