<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PaletteReducer;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

/**
 * Writes image cues as a Blu-ray PGS (.sup) file, the inverse of PgsParser. See PgsParser for the specs.
 */
final class PgsFormatter extends SubtitleFormatter implements ImageFormatter
{
    private const PTS_PER_SECOND = 90000;
    private const MAX_PTS        = 0xFFFFFFFF;
    private const MAX_FIELD      = 0xFFFF;

    private const SEGMENT_PALETTE      = 0x14;
    private const SEGMENT_OBJECT       = 0x15;
    private const SEGMENT_PRESENTATION = 0x16;
    private const SEGMENT_WINDOW       = 0x17;
    private const SEGMENT_END          = 0x80;

    private const STATE_NORMAL      = 0x00;
    private const STATE_EPOCH_START = 0x80;
    private const FLAG_FORCED       = 0x40;
    private const SEQUENCE_FIRST    = 0x80;
    private const SEQUENCE_LAST     = 0x40;

    // The frame rate code that Blu-ray discs and the PGS muxers write. Players ignore it.
    private const FRAME_RATE = 0x10;

    private const MAX_SEGMENT_DATA = 0xFFFF;
    private const MAX_RUN          = 0x3FFF;

    // An object segment starts with the object id, version and sequence flag. The first one adds the 3-byte data length.
    private const OBJECT_HEADER       = 4;
    private const OBJECT_DATA_LENGTH  = 3;
    private const FIRST_OBJECT_HEADER = self::OBJECT_HEADER + self::OBJECT_DATA_LENGTH;

    // PgsParser uses BT.601 up to this video height and BT.709 above it.
    private const SD_MAX_HEIGHT = 576;
    private const MATRIX_BT709  = [0.2126, 0.0722];
    private const MATRIX_BT601  = [0.299, 0.114];

    // The limited range of 8-bit video: luma from 16 to 235, chroma from 16 to 240 around 128.
    private const LUMA_MIN      = 16;
    private const LUMA_MAX      = 235;
    private const LUMA_RANGE    = self::LUMA_MAX - self::LUMA_MIN;
    private const CHROMA_MIN    = 16;
    private const CHROMA_MAX    = 240;
    private const CHROMA_CENTER = 128;
    private const CHROMA_RANGE  = self::CHROMA_MAX - self::CHROMA_MIN;

    // Clamped colors such as BT.601 yellow need a step of 2 to find the code that the parser decodes to the same RGB.
    private const SEARCH_STEPS = [0, -1, 1, -2, 2];

    // PGS shows at most 2 objects at one time. A third cue replaces the cue that started first.
    private const MAX_SHOWN = 2;

    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $cues = array_values($subtitle->getCues());
        usort($cues, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());

        $context = new PgsContext();
        $output  = "";
        $shown   = [];
        foreach ($cues as $index => $cue) {
            if (!CueImage::isImageCue($cue)) {
                throw new UnwritableContentException($this->cueError($cue, "the cue holds no image, and PGS output cannot hold text"));
            }

            $start   = $this->pts($cue, $cue->getStart());
            $output .= $this->endShownCues($context, $shown, $start);
            $shown[] = ["cue" => $cue, "image" => CueImage::fromCue($cue), "end" => $this->pts($cue, $cue->getEnd())];
            if (count($shown) > self::MAX_SHOWN) {
                array_shift($shown);
            }

            // A later cue with the same start joins this display set, so the screen does not change twice at one time.
            $next = isset($cues[$index + 1]) ? $this->pts($cues[$index + 1], $cues[$index + 1]->getStart()) : null;
            if ($next !== $start || end($shown)["end"] <= $start) {
                $output .= $this->showImages($context, $shown, $start);
            }
        }

        return $output . $this->endShownCues($context, $shown, null);
    }


    /**
     * Writes a display set at each end of a shown cue before $before, or at each end when $before is null.
     * The display set shows the remaining cues or clears the screen. Cues that end at $before go without a display set,
     * because the display set of the next cue replaces them.
     *
     * @param list<array{cue: SubtitleCue, image: CueImage, end: int}> $shown
     */
    private function endShownCues(PgsContext $context, array &$shown, ?int $before): string
    {
        $output = "";
        while (($ends = array_filter(array_column($shown, "end"), fn (int $end): bool => $before === null || $end < $before)) !== []) {
            $time   = min($ends);
            $images = array_column($shown, "image");
            $shown  = array_values(array_filter($shown, fn (array $entry): bool => $entry["end"] > $time));
            $output .= $shown === [] ? $this->clearScreen($context, $images, $time) : $this->showImages($context, $shown, $time);
        }
        $shown = array_values(array_filter($shown, fn (array $entry): bool => $entry["end"] > $before));

        return $output;
    }


    /**
     * Writes an epoch start that shows the image of each cue as its own composition object.
     *
     * @param non-empty-list<array{cue: SubtitleCue, image: CueImage, end: int}> $shown
     */
    private function showImages(PgsContext $context, array $shown, int $pts): string
    {
        $pixels = [];
        foreach ($shown as $entry) {
            $pixels[] = $this->pixels($entry["cue"], $entry["image"]);
        }
        ["palette" => $palette, "indexes" => $indexes] = PaletteReducer::reduce(array_merge(...$pixels));

        $images     = array_column($shown, "image");
        $windows    = $this->windows($images);
        $objects    = "";
        $references = "";
        $offset     = 0;
        foreach ($shown as $id => ["cue" => $cue, "image" => $image]) {
            $windowId    = count($windows) === 1 ? 0 : $id;
            $references .= pack("nCCnn", $id, $windowId, $cue->isForced() ? self::FLAG_FORCED : 0, $image->x, $image->y);
            $objects    .= $this->objects($pts, $id, $image->width, $image->height,
                                          $this->encodeRle(substr($indexes, $offset, count($pixels[$id])), $image->width));
            $offset     += count($pixels[$id]);
        }

        return $this->segment($pts, self::SEGMENT_PRESENTATION,
                              $this->presentation($context, $images[0], self::STATE_EPOCH_START, count($shown)) . $references)
            . $this->segment($pts, self::SEGMENT_WINDOW, $this->window($windows))
            . $this->segment($pts, self::SEGMENT_PALETTE, $this->palette($context, $palette, $images[0]->screenHeight > self::SD_MAX_HEIGHT))
            . $objects
            . $this->segment($pts, self::SEGMENT_END, "");
    }


    /**
     * @return list<int> the 0xRRGGBBAA pixels of the image
     */
    private function pixels(SubtitleCue $cue, CueImage $image): array
    {
        foreach ([$image->x, $image->y, $image->width, $image->height, $image->screenWidth, $image->screenHeight] as $value) {
            if ($value < 0 || $value > self::MAX_FIELD) {
                throw new UnwritableContentException($this->cueError($cue, "the image position and size must be from 0 to " . self::MAX_FIELD));
            }
        }

        ["width" => $width, "height" => $height, "pixels" => $pixels] = PngDecoder::decode($image->png);
        if ($width !== $image->width || $height !== $image->height) {
            throw new UnwritableContentException($this->cueError($cue, "the PNG has {$width}x{$height} pixels, " .
                                                                       "but the image data says {$image->width}x{$image->height}"));
        }

        return $pixels;
    }


    /**
     * Returns one window per image. Images that overlap on the screen share one window around both, so no two windows
     * overlap. One PGS window holds up to 2 objects.
     *
     * @param non-empty-list<CueImage> $images
     * @return non-empty-list<array{int, int, int, int}> x, y, width and height of each window
     */
    private function windows(array $images): array
    {
        $areas = array_map(fn (CueImage $image): array => [$image->x, $image->y, $image->width, $image->height], $images);
        if (count($areas) === 1) {
            return $areas;
        }

        [[$x1, $y1, $w1, $h1], [$x2, $y2, $w2, $h2]] = $areas;
        if ($x1 >= $x2 + $w2 || $x2 >= $x1 + $w1 || $y1 >= $y2 + $h2 || $y2 >= $y1 + $h1) {
            return $areas;
        }

        $x = min($x1, $x2);
        $y = min($y1, $y2);

        return [[$x, $y, max($x1 + $w1, $x2 + $w2) - $x, max($y1 + $h1, $y2 + $h2) - $y]];
    }


    /**
     * @param non-empty-list<CueImage> $images the images that the screen shows before the clear
     */
    private function clearScreen(PgsContext $context, array $images, int $pts): string
    {
        return $this->segment($pts, self::SEGMENT_PRESENTATION, $this->presentation($context, $images[0], self::STATE_NORMAL, 0))
            . $this->segment($pts, self::SEGMENT_WINDOW, $this->window($this->windows($images)))
            . $this->segment($pts, self::SEGMENT_END, "");
    }


    private function presentation(PgsContext $context, CueImage $image, int $state, int $objectCount): string
    {
        $number                     = $context->compositionNumber;
        $context->compositionNumber = ($number + 1) & 0xFFFF;

        return pack("nnCnCCCC", $image->screenWidth, $image->screenHeight, self::FRAME_RATE, $number, $state, 0, 0, $objectCount);
    }


    /**
     * @param list<array{int, int, int, int}> $windows
     */
    private function window(array $windows): string
    {
        $data = chr(count($windows));
        foreach ($windows as $id => [$x, $y, $width, $height]) {
            $data .= pack("Cnnnn", $id, $x, $y, $width, $height);
        }

        return $data;
    }


    /**
     * @param list<int> $palette 0xRRGGBBAA colors
     */
    private function palette(PgsContext $context, array $palette, bool $highDefinition): string
    {
        $data = pack("CC", 0, 0);
        foreach ($palette as $entryId => $color) {
            [$luma, $chromaRed, $chromaBlue] = $this->toYcrcb($context, $color >> 8 & 0xFFFFFF, $highDefinition);
            $data .= pack("C5", $entryId, $luma, $chromaRed, $chromaBlue, $color & 0xFF);
        }

        return $data;
    }


    /**
     * Splits the object data into a first segment with the size and further segments of at most 65,535 bytes each.
     */
    private function objects(int $pts, int $id, int $width, int $height, string $rle): string
    {
        $data      = pack("nn", $width, $height) . $rle;
        $fragments = array_merge([substr($data, 0, self::MAX_SEGMENT_DATA - self::FIRST_OBJECT_HEADER)],
                                 str_split(substr($data, self::MAX_SEGMENT_DATA - self::FIRST_OBJECT_HEADER), self::MAX_SEGMENT_DATA - self::OBJECT_HEADER));
        $fragments = array_values(array_filter($fragments, fn (string $fragment): bool => $fragment !== ""));

        $output = "";
        foreach ($fragments as $index => $fragment) {
            $sequence = ($index === 0 ? self::SEQUENCE_FIRST : 0) | ($index === count($fragments) - 1 ? self::SEQUENCE_LAST : 0);
            $header   = pack("nCC", $id, 0, $sequence);
            if ($index === 0) {
                $header .= pack("Cn", strlen($data) >> 16, strlen($data) & 0xFFFF);
            }
            $output .= $this->segment($pts, self::SEGMENT_OBJECT, $header . $fragment);
        }

        return $output;
    }


    /**
     * Encodes rows of palette entry bytes with the run-length codes that PgsParser decodes.
     */
    private function encodeRle(string $indexes, int $width): string
    {
        $rle = "";
        foreach (str_split($indexes, $width) as $row) {
            $position = 0;
            while ($position < $width) {
                $entry = $row[$position];
                $run   = min(strspn($row, $entry, $position), self::MAX_RUN);
                $color = ord($entry);
                $rle  .= match (true) {
                    $color !== 0 && $run <= 2 => str_repeat($entry, $run),
                    $color === 0 && $run < 64 => "\0" . chr($run),
                    $color === 0              => "\0" . chr(0x40 | $run >> 8) . chr($run & 0xFF),
                    $run < 64                 => "\0" . chr(0x80 | $run) . $entry,
                    default                   => "\0" . chr(0xC0 | $run >> 8) . chr($run & 0xFF) . $entry,
                };
                $position += $run;
            }
            $rle .= "\0\0";
        }

        return $rle;
    }


    /**
     * Returns the limited range [Y, Cr, Cb] that PgsParser turns back into the same RGB color, or the nearest one.
     *
     * @return array{int, int, int}
     */
    private function toYcrcb(PgsContext $context, int $rgb, bool $highDefinition): array
    {
        $key = ($highDefinition ? "709:" : "601:") . $rgb;
        if (isset($context->ycrcb[$key])) {
            return $context->ycrcb[$key];
        }

        [$kr, $kb] = $highDefinition ? self::MATRIX_BT709 : self::MATRIX_BT601;
        $target    = [$rgb >> 16, $rgb >> 8 & 0xFF, $rgb & 0xFF];
        $y         = $kr * $target[0] + (1 - $kr - $kb) * $target[1] + $kb * $target[2];
        $center    = [(int) round(self::LUMA_MIN + $y * self::LUMA_RANGE / 255),
                      (int) round(self::CHROMA_CENTER + ($target[0] - $y) / (2 * (1 - $kr)) * self::CHROMA_RANGE / 255),
                      (int) round(self::CHROMA_CENTER + ($target[2] - $y) / (2 * (1 - $kb)) * self::CHROMA_RANGE / 255)];

        return $context->ycrcb[$key] = $this->bestCandidate($center, $target, $kr, $kb);
    }


    /**
     * Returns the color near $center whose RGB value has the smallest squared error to $target. The first one wins a tie.
     *
     * @param array{int, int, int} $center
     * @param array{int, int, int} $target
     * @return array{int, int, int}
     */
    private function bestCandidate(array $center, array $target, float $kr, float $kb): array
    {
        $best      = $center;
        $bestError = PHP_INT_MAX;
        foreach (self::searchSteps() as [$lumaStep, $redStep, $blueStep]) {
            $candidate = [max(self::LUMA_MIN, min(self::LUMA_MAX, $center[0] + $lumaStep)),
                          max(self::CHROMA_MIN, min(self::CHROMA_MAX, $center[1] + $redStep)),
                          max(self::CHROMA_MIN, min(self::CHROMA_MAX, $center[2] + $blueStep))];
            $error     = 0;
            foreach ($this->toRgb($candidate, $kr, $kb) as $channel => $value) {
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


    /**
     * The conversion of PgsParser::colorMap().
     *
     * @param array{int, int, int} $ycrcb
     * @return array{int, int, int}
     */
    private function toRgb(array $ycrcb, float $kr, float $kb): array
    {
        [$luma, $chromaRed, $chromaBlue] = $ycrcb;
        $kg = 1 - $kr - $kb;
        $y  = ($luma - self::LUMA_MIN) * 255 / self::LUMA_RANGE;
        $cr = ($chromaRed - self::CHROMA_CENTER) * 255 / self::CHROMA_RANGE;
        $cb = ($chromaBlue - self::CHROMA_CENTER) * 255 / self::CHROMA_RANGE;

        return array_map(fn (float $value): int => max(0, min(255, (int) round($value))), [
            $y + 2 * (1 - $kr) * $cr,
            $y - 2 * $kb * (1 - $kb) / $kg * $cb - 2 * $kr * (1 - $kr) / $kg * $cr,
            $y + 2 * (1 - $kb) * $cb,
        ]);
    }


    private function pts(SubtitleCue $cue, float $seconds): int
    {
        $pts = (int) round($seconds * self::PTS_PER_SECOND);
        if ($pts < 0 || $pts > self::MAX_PTS) {
            throw new UnwritableContentException($this->cueError($cue, "a time stamp must be from 0 to " . self::MAX_PTS . " ticks of 90 kHz"));
        }

        return $pts;
    }


    private function cueError(SubtitleCue $cue, string $reason): string
    {
        return "Cannot write cue {$cue->getStart()} to {$cue->getEnd()} as PGS: $reason.";
    }


    private function segment(int $pts, int $type, string $data): string
    {
        return "PG" . pack("NNCn", $pts, 0, $type, strlen($data)) . $data;
    }
}
