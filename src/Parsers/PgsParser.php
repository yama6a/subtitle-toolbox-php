<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PgsColors;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads Blu-ray PGS (Presentation Graphic Stream, .sup) files. Each display set that shows objects becomes one image cue.
 *
 * Segment layout: http://blog.thescorpius.com/index.php/2017/07/15/presentation-graphic-stream-sup-files-bluray-subtitle-format/
 * Composition states, cropping and the forced flag: US patent application US 2009/0185789 A1,
 * https://patents.google.com/patent/US20090185789A1/en
 * Run-length decoding and the color matrix by video height: FFmpeg libavcodec/pgssubdec.c,
 * https://github.com/FFmpeg/FFmpeg/blob/5d4d3bdc61412641883a45e060e810f80ea7f4b5/libavcodec/pgssubdec.c
 * Position of a cropped object: libbluray graphics_controller.c,
 * https://code.videolan.org/videolan/libbluray/-/blob/0247557842050c8dfc0ae9293d76c3fb7386429a/src/libbluray/decoders/graphics_controller.c
 */
final class PgsParser extends SubtitleParser
{
    protected const BINARY = true;

    private const MAGIC          = "PG";
    private const HEADER_LENGTH  = 13;
    private const PTS_PER_SECOND = 90000;

    private const PRESENTATION_HEADER = 11;
    private const COMPOSITION_OBJECT  = 8;
    private const CROPPING            = 8;
    private const WINDOW_COUNT        = 1;
    private const WINDOW              = 9;
    private const PALETTE_HEADER      = 2;
    private const PALETTE_ENTRY       = 5;
    private const OBJECT_HEADER       = 4;
    private const OBJECT_DATA_LENGTH  = 3;
    private const FIRST_OBJECT_HEADER = self::OBJECT_HEADER + self::OBJECT_DATA_LENGTH;
    private const OBJECT_SIZE         = 4;

    private const SEGMENT_PALETTE      = 0x14;
    private const SEGMENT_OBJECT       = 0x15;
    private const SEGMENT_PRESENTATION = 0x16;
    private const SEGMENT_WINDOW       = 0x17;
    private const SEGMENT_END          = 0x80;

    private const STATE_EPOCH_START = 0x80;
    private const FLAG_CROPPED      = 0x80;
    private const FLAG_FORCED       = 0x40;
    private const SEQUENCE_FIRST    = 0x80;

    /** @var array<int, array<int, array{int, int, int, int}>> palette id => entry id => [Y, Cr, Cb, alpha] */
    private array $palettes = [];

    /** @var array<int, array{width: int, height: int, rle: string}> */
    private array $objects = [];

    /** @var array<int, array{x: int, y: int, width: int, height: int}> */
    private array $windows = [];

    private ?array $presentation = null;

    /** @var array{start: float, image: CueImage}|null */
    private ?array $shownImage = null;

    /** @var list<SubtitleCue> */
    private array $cues = [];


    protected function read(string $content): Subtitle
    {
        $this->cues         = [];
        $this->palettes     = [];
        $this->objects      = [];
        $this->windows      = [];
        $this->presentation = null;
        $this->shownImage   = null;

        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            if (substr($content, $offset, 2) !== self::MAGIC) {
                throw new ParsingException("The segment at byte $offset does not start with the PG magic bytes.");
            }
            if ($length - $offset < self::HEADER_LENGTH) {
                throw new ParsingException("The segment header at byte $offset is cut off.");
            }

            ["pts" => $pts, "type" => $type, "size" => $size] = unpack("Npts/Ndts/Ctype/nsize", $content, $offset + 2);
            if ($length - $offset - self::HEADER_LENGTH < $size) {
                throw new ParsingException("The segment at byte $offset has $size bytes of data, but the file ends before.");
            }

            $data    = substr($content, $offset + self::HEADER_LENGTH, $size);
            $offset += self::HEADER_LENGTH + $size;

            match ($type) {
                self::SEGMENT_PRESENTATION => $this->readPresentation($pts, $data),
                self::SEGMENT_WINDOW       => $this->readWindows($data),
                self::SEGMENT_PALETTE      => $this->readPalette($data),
                self::SEGMENT_OBJECT       => $this->readObject($data),
                self::SEGMENT_END          => $this->endDisplaySet(),
                default                    => null,
            };
        }

        $this->endDisplaySet();
        if ($this->shownImage !== null) {
            $this->addCue($this->shownImage["start"] + $this->options->lastCueDuration);
        }

        return (new Subtitle())->addCues($this->cues);
    }


    private function readPresentation(int $pts, string $data): void
    {
        $this->endDisplaySet();

        if (strlen($data) < self::PRESENTATION_HEADER) {
            throw new ParsingException("The presentation composition segment at " . $this->seconds($pts) . " s is cut off.");
        }

        $header = unpack("nscreenWidth/nscreenHeight/CframeRate/ncompositionNumber/Cstate/CpaletteUpdate/CpaletteId/Ccount", $data);
        if ($header["state"] & self::STATE_EPOCH_START) {
            $this->palettes = [];
            $this->objects  = [];
            $this->windows  = [];
        }

        $references = [];
        $position   = self::PRESENTATION_HEADER;
        for ($index = 0; $index < $header["count"]; $index++) {
            if (strlen($data) < $position + self::COMPOSITION_OBJECT) {
                throw new ParsingException("The composition object $index at " . $this->seconds($pts) . " s is cut off.");
            }

            $reference = unpack("nid/CwindowId/Cflags/nx/ny", $data, $position);
            $position += self::COMPOSITION_OBJECT;
            if ($reference["flags"] & self::FLAG_CROPPED) {
                if (strlen($data) < $position + self::CROPPING) {
                    throw new ParsingException("The cropping of composition object $index at " . $this->seconds($pts) . " s is cut off.");
                }
                $reference["crop"] = array_values(unpack("n4", $data, $position));
                $position         += self::CROPPING;
            }
            $references[] = $reference;
        }

        $this->presentation = [
            "time"         => $this->seconds($pts),
            "screenWidth"  => $header["screenWidth"],
            "screenHeight" => $header["screenHeight"],
            "paletteId"    => $header["paletteId"],
            "references"   => $references,
        ];
    }


    private function readWindows(string $data): void
    {
        $count = ord($data[0] ?? "\0");
        for ($index = 0; $index < $count && strlen($data) >= self::WINDOW_COUNT + ($index + 1) * self::WINDOW; $index++) {
            $window = unpack("Cid/nx/ny/nwidth/nheight", $data, self::WINDOW_COUNT + $index * self::WINDOW);
            $this->windows[$window["id"]] = array_slice($window, 1);
        }
    }


    private function readPalette(string $data): void
    {
        $paletteId = ord($data[0] ?? "\0");
        foreach (str_split(substr($data, self::PALETTE_HEADER), self::PALETTE_ENTRY) as $entry) {
            if (strlen($entry) === self::PALETTE_ENTRY) {
                [, $entryId, $luma, $chromaRed, $chromaBlue, $alpha] = unpack("C5", $entry);
                $this->palettes[$paletteId][$entryId] = [$luma, $chromaRed, $chromaBlue, $alpha];
            }
        }
    }


    private function readObject(string $data): void
    {
        if (strlen($data) < self::OBJECT_HEADER) {
            return;
        }

        ["id" => $id, "sequence" => $sequence] = unpack("nid/Cversion/Csequence", $data);
        if ($sequence & self::SEQUENCE_FIRST) {
            if (strlen($data) < self::FIRST_OBJECT_HEADER + self::OBJECT_SIZE) {
                throw new ParsingException("The first definition segment of object $id is cut off.");
            }
            ["width" => $width, "height" => $height] = unpack("nwidth/nheight", $data, self::FIRST_OBJECT_HEADER);
            self::checkSize($width, $height, "read object $id");
            $this->objects[$id] = ["width" => $width, "height" => $height, "rle" => substr($data, self::FIRST_OBJECT_HEADER + self::OBJECT_SIZE)];
        } elseif (isset($this->objects[$id])) {
            $this->objects[$id]["rle"] .= substr($data, self::OBJECT_HEADER);
        }
    }


    private function endDisplaySet(): void
    {
        if ($this->presentation === null) {
            return;
        }

        $time               = $this->presentation["time"];
        $image              = $this->render($this->presentation);
        $this->presentation = null;

        if ($this->shownImage !== null) {
            if ($image == $this->shownImage["image"]) {
                return;
            }
            $this->addCue($time);
        }

        $this->shownImage = $image === null ? null : ["start" => $time, "image" => $image];
    }


    private function addCue(float $end): void
    {
        $image = $this->shownImage["image"];
        $cue   = $image->toCue(new SubtitleCue($this->shownImage["start"], $end));
        if ((2 * $image->y + $image->height) * 3 < 2 * $image->screenHeight) {
            $cue->setAlignment(Alignment::TopCenter);
        }

        $this->cues[] = $cue;
        $this->shownImage = null;
    }


    private function render(array $presentation): ?CueImage
    {
        $palette = $this->palettes[$presentation["paletteId"]] ?? null;
        if ($palette === null || $presentation["references"] === []) {
            return null;
        }

        $colors = $this->colorMap($palette, PgsColors::matrix($presentation["screenHeight"]));
        $parts  = [];
        $forced = false;
        foreach ($presentation["references"] as $reference) {
            if (!isset($this->objects[$reference["id"]])) {
                continue;
            }

            $object = $this->objects[$reference["id"]];
            $part   = $this->visibleArea($reference, $object);
            if ($part === null) {
                continue;
            }

            $pixels = $this->decodeRle($reference["id"], $object, $colors, $presentation["time"]);
            if ($part["width"] === $object["width"] && $part["height"] === $object["height"]) {
                $part["rgba"] = $pixels;
            } else {
                $part["rgba"] = "";
                for ($row = 0; $row < $part["height"]; $row++) {
                    $part["rgba"] .= substr($pixels, 4 * (($part["top"] + $row) * $object["width"] + $part["left"]), 4 * $part["width"]);
                }
            }
            $parts[] = $part;
            $forced  = $forced || ($reference["flags"] & self::FLAG_FORCED) !== 0;
        }

        if ($parts === []) {
            return null;
        }

        [$x, $y, $width, $height, $rgba] = $this->compose($parts);

        return new CueImage(PngEncoder::encode($width, $height, array_values(unpack("N*", $rgba))),
                            $x, $y, $width, $height, $presentation["screenWidth"], $presentation["screenHeight"], $forced);
    }


    /**
     * Returns the part of the object inside its cropping rectangle and its window.
     * The part is an area of the object and a screen position.
     */
    private function visibleArea(array $reference, array $object): ?array
    {
        [$left, $top, $width, $height] = $reference["crop"] ?? [0, 0, $object["width"], $object["height"]];
        $right  = min($left + $width, $object["width"]);
        $bottom = min($top + $height, $object["height"]);
        $x      = $reference["x"];
        $y      = $reference["y"];

        $window = $this->windows[$reference["windowId"]] ?? null;
        if ($window !== null) {
            $clipLeft = max(0, $window["x"] - $x);
            $clipTop  = max(0, $window["y"] - $y);
            $right    = min($right, $left + $window["x"] + $window["width"] - $x);
            $bottom   = min($bottom, $top + $window["y"] + $window["height"] - $y);
            $left    += $clipLeft;
            $top     += $clipTop;
            $x       += $clipLeft;
            $y       += $clipTop;
        }

        if ($right <= $left || $bottom <= $top) {
            return null;
        }

        return ["x" => $x, "y" => $y, "left" => $left, "top" => $top, "width" => $right - $left, "height" => $bottom - $top];
    }


    /**
     * Places the parts on one transparent canvas that covers all of them. A later part is drawn over the earlier parts.
     * Returns the position, size and RGBA bytes of the canvas.
     */
    private function compose(array $parts): array
    {
        $left   = min(array_column($parts, "x"));
        $top    = min(array_column($parts, "y"));
        $right  = max(array_map(fn (array $part): int => $part["x"] + $part["width"], $parts));
        $bottom = max(array_map(fn (array $part): int => $part["y"] + $part["height"], $parts));
        $width  = $right - $left;
        $height = $bottom - $top;
        self::checkSize($width, $height, "join the objects of one display set");

        if (count($parts) === 1) {
            return [$left, $top, $width, $height, $parts[0]["rgba"]];
        }

        $canvas = array_fill(0, $height, str_repeat("\0\0\0\0", $width));
        foreach ($parts as $index => $part) {
            $overlaps  = array_filter(array_slice($parts, 0, $index), fn (array $earlier): bool => self::overlap($earlier, $part));
            $rowLength = $part["width"] * 4;
            for ($row = 0; $row < $part["height"]; $row++) {
                $source = substr($part["rgba"], $row * $rowLength, $rowLength);
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


    /**
     * Decodes the run-length encoded bitmap to 4 RGBA bytes per pixel, row by row.
     *
     * @param array<string, string> $colors
     */
    private function decodeRle(int $id, array $object, array $colors, float $time): string
    {
        $rle    = $object["rle"];
        $length = strlen($rle);
        $size   = $object["width"] * $object["height"];
        $pixels = "";
        $offset = 0;
        // A run fills up to 16,383 pixels, so the runs after the last pixel of the object could take gigabytes.
        while ($offset < $length && strlen($pixels) < 4 * $size) {
            $byte = $rle[$offset++];
            if ($byte !== "\0") {
                $pixels .= $colors[$byte];
                continue;
            }

            $flags = ord($rle[$offset++] ?? "\0");
            $run   = $flags & 0x3F;
            if ($flags & 0x40) {
                $run = ($run << 8) | ord($rle[$offset++] ?? "\0");
            }
            $color   = $flags & 0x80 ? ($rle[$offset++] ?? "\0") : "\0";
            $pixels .= str_repeat($colors[$color], $run);
        }

        if (strlen($pixels) < 4 * $size) {
            throw new ParsingException("Object $id at $time s has " . strlen($pixels) / 4 . " pixels of run-length data, " .
                                       "but its size of {$object["width"]}x{$object["height"]} needs $size.");
        }

        return substr($pixels, 0, 4 * $size);
    }


    /**
     * Maps each palette entry byte to 4 RGBA bytes. Entries that the palette does not define are transparent.
     *
     * @param array{float, float} $matrix
     * @return array<string, string>
     */
    private function colorMap(array $palette, array $matrix): array
    {
        $map = array_fill(0, 256, "\0\0\0\0");
        foreach ($palette as $entryId => [$luma, $chromaRed, $chromaBlue, $alpha]) {
            $map[$entryId] = pack("C3", ...PgsColors::toRgb($luma, $chromaRed, $chromaBlue, $matrix)) . chr($alpha);
        }

        $colors = [];
        foreach ($map as $entryId => $rgba) {
            $colors[chr($entryId)] = $rgba;
        }

        return $colors;
    }


    private static function checkSize(int $width, int $height, string $action): void
    {
        try {
            CueImage::checkSize($width, $height, $action);
        } catch (InvalidArgumentException $exception) {
            throw new ParsingException($exception->getMessage());
        }
    }


    private function seconds(int $pts): float
    {
        return $pts / self::PTS_PER_SECOND;
    }
}
