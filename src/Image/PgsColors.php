<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

/**
 * Converts the limited range Y, Cr, Cb colors of PGS palettes to RGB and back. PgsParser and PgsFormatter share it,
 * so the formatter writes the codes that the parser decodes to the same RGB colors.
 *
 * @internal
 */
final class PgsColors
{
    // libavcodec/pgssubdec.c uses BT.601 up to this video height and BT.709 above it.
    private const SD_MAX_HEIGHT = 576;

    // Kr and Kb of the YCbCr matrices in ITU-R BT.709 and BT.601.
    private const MATRIX_BT709 = [0.2126, 0.0722];
    public const MATRIX_BT601  = [0.299, 0.114];

    // The limited range of 8-bit video: luma from 16 to 235, chroma from 16 to 240 around 128.
    private const LUMA_MIN      = 16;
    private const LUMA_MAX      = 235;
    private const LUMA_RANGE    = self::LUMA_MAX - self::LUMA_MIN;
    private const CHROMA_MIN    = 16;
    private const CHROMA_MAX    = 240;
    private const CHROMA_CENTER = 128;
    private const CHROMA_RANGE  = self::CHROMA_MAX - self::CHROMA_MIN;

    // Some colors, such as 0xFFFF40 in BT.601, need a step of 2 to find the code that toRgb() decodes to the same RGB.
    private const SEARCH_STEPS = [0, -1, 1, -2, 2];


    /**
     * Returns Kr and Kb of the matrix that a video of $screenHeight pixels uses.
     *
     * @return array{float, float}
     */
    public static function matrix(int $screenHeight): array
    {
        return $screenHeight > self::SD_MAX_HEIGHT ? self::MATRIX_BT709 : self::MATRIX_BT601;
    }


    /**
     * @param array{float, float} $matrix
     * @return array{int, int, int} red, green and blue
     */
    public static function toRgb(int $luma, int $chromaRed, int $chromaBlue, array $matrix): array
    {
        [$kr, $kb] = $matrix;
        $kg        = 1 - $kr - $kb;
        $y         = ($luma - self::LUMA_MIN) * 255 / self::LUMA_RANGE;
        $cr        = ($chromaRed - self::CHROMA_CENTER) * 255 / self::CHROMA_RANGE;
        $cb        = ($chromaBlue - self::CHROMA_CENTER) * 255 / self::CHROMA_RANGE;

        return array_map(fn (float $value): int => max(0, min(255, (int) round($value))), [
            $y + 2 * (1 - $kr) * $cr,
            $y - 2 * $kb * (1 - $kb) / $kg * $cb - 2 * $kr * (1 - $kr) / $kg * $cr,
            $y + 2 * (1 - $kb) * $cb,
        ]);
    }


    /**
     * Returns the [Y, Cr, Cb] code that toRgb() turns back into the 0xRRGGBB color $rgb, or into the nearest color.
     *
     * @param array{float, float} $matrix
     * @return array{int, int, int}
     */
    public static function toYcrcb(int $rgb, array $matrix): array
    {
        [$kr, $kb] = $matrix;
        $target    = [$rgb >> 16, $rgb >> 8 & 0xFF, $rgb & 0xFF];
        $y         = $kr * $target[0] + (1 - $kr - $kb) * $target[1] + $kb * $target[2];
        $center    = [(int) round(self::LUMA_MIN + $y * self::LUMA_RANGE / 255),
                      (int) round(self::CHROMA_CENTER + ($target[0] - $y) / (2 * (1 - $kr)) * self::CHROMA_RANGE / 255),
                      (int) round(self::CHROMA_CENTER + ($target[2] - $y) / (2 * (1 - $kb)) * self::CHROMA_RANGE / 255)];

        return self::bestCandidate($center, $target, $matrix);
    }


    /**
     * Returns the code near $center whose RGB value has the smallest squared error to $target. The first one wins a tie.
     *
     * @param array{int, int, int} $center
     * @param array{int, int, int} $target
     * @param array{float, float} $matrix
     * @return array{int, int, int}
     */
    private static function bestCandidate(array $center, array $target, array $matrix): array
    {
        $best      = $center;
        $bestError = PHP_INT_MAX;
        foreach (self::searchSteps() as [$lumaStep, $redStep, $blueStep]) {
            $candidate = [max(self::LUMA_MIN, min(self::LUMA_MAX, $center[0] + $lumaStep)),
                          max(self::CHROMA_MIN, min(self::CHROMA_MAX, $center[1] + $redStep)),
                          max(self::CHROMA_MIN, min(self::CHROMA_MAX, $center[2] + $blueStep))];
            $error     = 0;
            foreach (self::toRgb(...$candidate, matrix: $matrix) as $channel => $value) {
                $error += ($value - $target[$channel]) ** 2;
            }
            if ($error < $bestError) {
                [$best, $bestError] = [$candidate, $error];
            }
        }

        return $best;
    }


    /**
     * Returns each combination of a luma, a red and a blue step, with the luma step outermost.
     *
     * @return list<array{int, int, int}>
     */
    private static function searchSteps(): array
    {
        $steps = [];
        foreach (self::SEARCH_STEPS as $lumaStep) {
            foreach (self::SEARCH_STEPS as $redStep) {
                foreach (self::SEARCH_STEPS as $blueStep) {
                    $steps[] = [$lumaStep, $redStep, $blueStep];
                }
            }
        }

        return $steps;
    }
}
