<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\IndexedImages;
use SubtitleToolbox\Image\PgsColors;
use SubtitleToolbox\SubtitleCue;

/**
 * Decodes the segments of DVB bitmap subtitles. Each display set that shows pixels becomes an image cue.
 *
 * Segments, CLUTs and pixel code strings: ETSI EN 300 743,
 * https://www.etsi.org/deliver/etsi_en/300700_300799/300743/01.06.01_60/en_300743v010601p.pdf
 * Default CLUTs, reduced range CLUT entries and display sets without an end segment: FFmpeg libavcodec/dvbsubdec.c,
 * https://github.com/FFmpeg/FFmpeg/blob/a18b979d17fa169a6f93c5be8732533c8e06337d/libavcodec/dvbsubdec.c
 *
 * @internal
 */
final class DvbSubtitleDecoder
{
    // The data_identifier and the subtitle_stream_id at the start of the data of a PES packet.
    private const PES_DATA_START = "\x20\x00";

    private const SYNC_BYTE      = "\x0F";
    private const SEGMENT_HEADER = 6;

    private const SEGMENT_PAGE    = 0x10;
    private const SEGMENT_REGION  = 0x11;
    private const SEGMENT_CLUT    = 0x12;
    private const SEGMENT_OBJECT  = 0x13;
    private const SEGMENT_DISPLAY = 0x14;
    private const SEGMENT_END     = 0x80;

    // A display set without a display definition segment is for a 720x576 display.
    private const DEFAULT_DISPLAY = [720, 576, 0, 0];

    private const DEPTHS = [1 => 2, 2 => 4, 3 => 8];

    private const END_OF_LINE = 0xF0;

    // The pixel code string types, by bits per pixel code.
    private const PIXEL_STRINGS = [0x10 => 2, 0x11 => 4, 0x12 => 8];

    // The map table types, by the bits of the codes that they map from and to. The values are the default tables.
    private const MAP_TABLES = [
        0x20 => [2, 4, [0x0, 0x7, 0x8, 0xF]],
        0x21 => [2, 8, [0x00, 0x77, 0x88, 0xFF]],
        0x22 => [4, 8, [0x00, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, 0x77, 0x88, 0x99, 0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF]],
    ];

    /** @var list<int>|null */
    private ?array $pageIds;

    /** @var array{int, int, int, int} width, height, left and top of the display window */
    private array $display = self::DEFAULT_DISPLAY;

    private ?int $pageVersion = null;

    /** @var list<array{int, int, int}> region id, x and y */
    private array $pageRegions = [];

    /** @var array<int, array{width: int, height: int, depth: int, clut: int, rows: list<string>, objects: list<array{int, int, int}>}> */
    private array $regions = [];

    /** @var array<int, array<int, list<int>>> CLUT id => bits per entry => 0xRRGGBBAA by entry */
    private array $cluts = [];

    /** @var array{time: float, expires: float}|null */
    private ?array $pending = null;

    /** @var array{start: float, expires: float, image: CueImage}|null */
    private ?array $shown = null;

    /** @var list<array{float, float, CueImage}> */
    private array $cues = [];


    /**
     * @param int|null $compositionPage the page of the subtitles, or null to read the segments of every page
     * @param int|null $ancillaryPage   the page with the CLUTs and objects that several subtitle services share
     */
    public function __construct(?int $compositionPage = null, ?int $ancillaryPage = null)
    {
        $this->pageIds = $compositionPage === null ? null : [$compositionPage, $ancillaryPage ?? $compositionPage];
    }


    /**
     * Reads the segments of one PES packet or one MKV block, which start at $time seconds. A display set without an end segment
     * shows at the end of the packet. $end is the end of an MKV block with a duration.
     */
    public function add(string $data, float $time, ?float $end = null): void
    {
        $offset = str_starts_with($data, self::PES_DATA_START) ? 2 : 0;
        while ($offset + self::SEGMENT_HEADER <= strlen($data) && $data[$offset] === self::SYNC_BYTE) {
            ["type" => $type, "page" => $page, "length" => $length] = unpack("Ctype/npage/nlength", $data, $offset + 1);
            $segment = substr($data, $offset + self::SEGMENT_HEADER, $length);
            $offset += self::SEGMENT_HEADER + $length;
            if ($this->pageIds !== null && !in_array($page, $this->pageIds, true)) {
                continue;
            }

            match ($type) {
                self::SEGMENT_PAGE    => $this->readPage($segment, $time, $end),
                self::SEGMENT_REGION  => $this->readRegion($segment),
                self::SEGMENT_CLUT    => $this->readClut($segment),
                self::SEGMENT_OBJECT  => $this->readObject($segment),
                self::SEGMENT_DISPLAY => $this->readDisplay($segment),
                self::SEGMENT_END     => $this->show(),
                default               => null,
            };
        }
        $this->show();
    }


    /**
     * Returns the cues with times from $zero seconds. A last cue without a page time-out lasts $lastCueDuration.
     *
     * @return list<SubtitleCue>
     */
    public function finish(float $lastCueDuration, float $zero = 0.0): array
    {
        if ($this->shown !== null) {
            $this->endCue(is_finite($this->shown["expires"]) ? $this->shown["expires"] : $this->shown["start"] + $lastCueDuration);
        }

        return array_map(fn (array $cue): SubtitleCue => IndexedImages::cue(max(0, $cue[0] - $zero), max(0, $cue[1] - $zero), $cue[2]), $this->cues);
    }


    private function readPage(string $data, float $time, ?float $end): void
    {
        if (strlen($data) < 2) {
            return;
        }

        $timeOut       = ord($data[0]);
        $this->pending = ["time" => $time, "expires" => min($timeOut > 0 ? $time + $timeOut : INF, $end ?? INF)];
        $version       = ord($data[1]) >> 4;
        if ($version === $this->pageVersion) {
            return;
        }

        $this->pageVersion = $version;
        // An acquisition point or a mode change starts a new epoch, which sends every region and CLUT again.
        if ((ord($data[1]) >> 2 & 0x03) !== 0) {
            $this->regions = [];
            $this->cluts   = [];
        }
        $this->pageRegions = [];
        foreach (str_split(substr($data, 2), 6) as $entry) {
            if (strlen($entry) === 6) {
                $this->pageRegions[] = array_values(unpack("Cid/x/nx/ny", $entry));
            }
        }
    }


    private function readRegion(string $data): void
    {
        if (strlen($data) < 10) {
            return;
        }

        $region = unpack("Cid/Cflags/nwidth/nheight/Cdepth/Cclut/Ccode8/Ccode", $data);
        $depth  = self::DEPTHS[$region["depth"] >> 2 & 0x07] ?? null;
        if ($depth === null || $region["width"] === 0 || $region["height"] === 0) {
            return;
        }
        $tooLarge = CueImage::sizeLimitError($region["width"], $region["height"]);
        if ($tooLarge !== null) {
            throw new ParsingException("Cannot read region {$region["id"]}: $tooLarge");
        }

        $old        = $this->regions[$region["id"]] ?? null;
        $background = chr(match ($depth) { 8 => $region["code8"], 4 => $region["code"] >> 4, 2 => $region["code"] >> 2 & 0x03 });
        $rows       = $old !== null && $old["width"] === $region["width"] && $old["height"] === $region["height"] && ($region["flags"] & 0x08) === 0
            ? $old["rows"] : array_fill(0, $region["height"], str_repeat($background, $region["width"]));

        $objects = [];
        for ($offset = 10; $offset + 6 <= strlen($data); $offset += $type === 1 || $type === 2 ? 8 : 6) {
            ["id" => $id, "x" => $x, "y" => $y] = unpack("nid/nx/ny", $data, $offset);
            $type = $x >> 14;
            if ($type === 0) {
                $objects[] = [$id, $x & 0x0FFF, $y & 0x0FFF];
            }
        }

        $this->regions[$region["id"]] = ["width" => $region["width"], "height" => $region["height"], "depth" => $depth, "clut" => $region["clut"],
                                         "rows" => $rows, "objects" => $objects];
    }


    private function readClut(string $data): void
    {
        $id     = ord($data[0] ?? "\0");
        $clut   = $this->cluts[$id] ?? self::defaultClut();
        $length = strlen($data);
        for ($offset = 2; $offset + 4 <= $length; ) {
            $entry = ord($data[$offset]);
            $flags = ord($data[$offset + 1]);
            if (($flags & 0x01) !== 0) {
                if ($offset + 6 > $length) {
                    break;
                }
                [, $luma, $chromaRed, $chromaBlue, $transparency] = unpack("C4", $data, $offset + 2);
                $offset += 6;
            } else {
                $value        = unpack("n", $data, $offset + 2)[1];
                $luma         = $value >> 8 & 0xFC;
                $chromaRed    = ($value >> 6 & 0x0F) << 4;
                $chromaBlue   = ($value >> 2 & 0x0F) << 4;
                $transparency = ($value & 0x03) << 6;
                $offset      += 4;
            }

            // Y 0 is full transparency.
            [$red, $green, $blue] = PgsColors::toRgb($luma, $chromaRed, $chromaBlue, PgsColors::MATRIX_BT601);
            $color                = $luma === 0 ? 0 : $red << 24 | $green << 16 | $blue << 8 | 255 - $transparency;
            foreach ([0x80 => 2, 0x40 => 4, 0x20 => 8] as $flag => $bits) {
                if (($flags & $flag) !== 0 && $entry < 1 << $bits) {
                    $clut[$bits][$entry] = $color;
                }
            }
        }
        $this->cluts[$id] = $clut;
    }


    /**
     * Draws the object into each region that shows it. Only objects coded as pixels are read, not as character strings.
     */
    private function readObject(string $data): void
    {
        if (strlen($data) < 7 || (ord($data[2]) >> 2 & 0x03) !== 0) {
            return;
        }

        ["id" => $id, "flags" => $flags, "top" => $topLength, "bottom" => $bottomLength] = unpack("nid/Cflags/ntop/nbottom", $data);
        $top    = substr($data, 7, $topLength);
        $bottom = $bottomLength === 0 ? $top : substr($data, 7 + $topLength, $bottomLength);
        foreach ($this->regions as &$region) {
            foreach ($region["objects"] as [$objectId, $x, $y]) {
                if ($objectId === $id) {
                    self::drawField($region, $top, $x, $y, ($flags & 0x02) !== 0);
                    self::drawField($region, $bottom, $x, $y + 1, ($flags & 0x02) !== 0);
                }
            }
        }
        unset($region);
    }


    private function readDisplay(string $data): void
    {
        if (strlen($data) < 5) {
            return;
        }

        ["flags" => $flags, "width" => $width, "height" => $height] = unpack("Cflags/nwidth/nheight", $data);
        [$left, $top] = ($flags & 0x08) !== 0 && strlen($data) >= 13 ? array_values(unpack("nleft/x2/ntop", $data, 5)) : [0, 0];
        $this->display = [$width + 1, $height + 1, $left, $top];
    }


    /**
     * Shows the page of the display set that the last page composition segment started.
     * The image of the shown cue continues it when it is the same.
     */
    private function show(): void
    {
        if ($this->pending === null) {
            return;
        }

        ["time" => $time, "expires" => $expires] = $this->pending;
        $this->pending = null;
        $image         = $this->render();
        if ($this->shown !== null) {
            if ($image == $this->shown["image"] && $this->shown["expires"] >= $time) {
                $this->shown["expires"] = $expires;
                return;
            }
            $this->endCue(min($this->shown["expires"], $time));
        }

        $this->shown = $image === null ? null : ["start" => $time, "expires" => $expires, "image" => $image];
    }


    private function endCue(float $end): void
    {
        $this->cues[] = [$this->shown["start"], $end, $this->shown["image"]];
        $this->shown  = null;
    }


    private function render(): ?CueImage
    {
        [$width, $height, $left, $top] = $this->display;
        $parts = [];
        foreach ($this->pageRegions as [$id, $x, $y]) {
            $region = $this->regions[$id] ?? null;
            if ($region === null) {
                continue;
            }

            $colors  = array_replace(array_fill(0, 256, 0), ($this->cluts[$region["clut"]] ?? self::defaultClut())[$region["depth"]]);
            $indexes = implode("", $region["rows"]);
            foreach (count_chars($indexes, 1) as $code => $count) {
                if (($colors[$code] & 0xFF) !== 0) {
                    $parts[] = ["x" => $left + $x, "y" => $top + $y, "width" => $region["width"], "height" => $region["height"],
                                "indexes" => $indexes, "palette" => $colors];
                    break;
                }
            }
        }

        return $parts === [] ? null : IndexedImages::cueImage($parts, $width, $height, false);
    }


    /**
     * Draws the pixel code strings of one field of an object into every second row of the region, from row $y.
     * A non-modifying color flag keeps the region pixels below the pixel code 1.
     */
    private static function drawField(array &$region, string $data, int $x, int $y, bool $nonModifying): void
    {
        $maps   = array_map(fn (array $table): array => $table[2], self::MAP_TABLES);
        $column = $x;
        $offset = 0;
        while ($offset < strlen($data)) {
            $type = ord($data[$offset++]);
            if ($type === self::END_OF_LINE) {
                [$column, $y] = [$x, $y + 2];
            } elseif (isset(self::MAP_TABLES[$type])) {
                [$from, $to] = self::MAP_TABLES[$type];
                $bytes       = substr($data, $offset, (1 << $from) * $to >> 3);
                $offset     += strlen($bytes);
                $maps[$type] = $to === 4 ? array_merge(...array_map(fn (int $byte): array => [$byte >> 4, $byte & 0x0F], array_values(unpack("C*", $bytes) ?: [])))
                                         : array_values(unpack("C*", $bytes) ?: []);
            } elseif (isset(self::PIXEL_STRINGS[$type]) && self::PIXEL_STRINGS[$type] <= $region["depth"]) {
                $bits            = self::PIXEL_STRINGS[$type];
                $map             = $bits === $region["depth"] ? null : $maps[$bits === 4 ? 0x22 : ($region["depth"] === 4 ? 0x20 : 0x21)];
                [$runs, $offset] = self::pixelCodes($data, $offset, $bits, $region["width"] - $column);
                foreach ($runs as [$code, $count]) {
                    if (($code !== 1 || !$nonModifying) && $y < $region["height"] && $column < $region["width"]) {
                        $length             = min($count, $region["width"] - $column);
                        $region["rows"][$y] = substr_replace($region["rows"][$y], str_repeat(chr($map[$code] ?? $code), $length), $column, $length);
                    }
                    $column += $count;
                }
            } elseif (isset(self::PIXEL_STRINGS[$type])) {
                return;
            }
        }
    }


    /**
     * Reads one 2-, 4- or 8-bit pixel code string from byte $offset. Returns the runs of pixel codes and the offset after the string.
     * The string also ends after $pixels pixels, at the end of the region row. ffmpeg ends its 8-bit strings with one zero byte
     * instead of two, so only the row end ends them. drawField() skips the zero bytes of an end code after the row end.
     *
     * @return array{list<array{int, int}>, int} pixel code and count of each run
     */
    private static function pixelCodes(string $data, int $offset, int $bits, int $pixels): array
    {
        $position = $offset * 8;
        $end      = strlen($data) * 8;
        $read     = function (int $count) use ($data, &$position): int {
            $value = 0;
            for ($index = 0; $index < $count; $index++, $position++) {
                $value = $value << 1 | (ord($data[$position >> 3] ?? "\0") >> (7 - ($position & 7)) & 1);
            }

            return $value;
        };

        $runs = [];
        while ($position < $end && $pixels > 0) {
            $code = $read($bits);
            if ($code !== 0) {
                $runs[] = [$code, 1];
                $pixels--;
                continue;
            }

            $run = match ($bits) {
                2 => match (true) {
                    $read(1) === 1 => [3 + $read(3), 2],
                    $read(1) === 1 => [1, 0],
                    default        => match ($read(2)) { 0 => null, 1 => [2, 0], 2 => [12 + $read(4), 2], 3 => [29 + $read(8), 2] },
                },
                4 => match (true) {
                    $read(1) === 0 => (fn (int $count): ?array => $count === 0 ? null : [$count + 2, 0])($read(3)),
                    $read(1) === 0 => [4 + $read(2), 4],
                    default        => match ($read(2)) { 0 => [1, 0], 1 => [2, 0], 2 => [9 + $read(4), 4], 3 => [25 + $read(8), 4] },
                },
                8 => $read(1) === 0 ? (fn (int $count): ?array => $count === 0 ? null : [$count, 0])($read(7)) : [$read(7), 8],
            };
            if ($run === null) {
                break;
            }
            // The code of a run follows its length. A run without code bits has the pixel code 0.
            $runs[]  = [$run[1] === 0 ? 0 : $read($run[1]), $run[0]];
            $pixels -= $run[0];
        }

        return [$runs, ($position + 7) >> 3];
    }


    /**
     * Returns the CLUT that applies before a CLUT definition segment sets entries.
     *
     * @return array<int, list<int>> bits per entry => 0xRRGGBBAA by entry
     */
    private static function defaultClut(): array
    {
        $clut = [2 => [0, 0xFFFFFFFF, 0x000000FF, 0x7F7F7FFF], 4 => [0], 8 => [0]];
        for ($entry = 1; $entry < 256; $entry++) {
            [$base, $low, $high, $alpha] = $entry < 8 ? [0, 255, 0, 63] : match ($entry & 0x88) {
                0x00 => [0, 85, 170, 255],
                0x08 => [0, 85, 170, 127],
                0x80 => [127, 43, 85, 255],
                0x88 => [0, 43, 85, 255],
            };
            $color = $alpha;
            $full  = 0xFF;
            for ($channel = 0; $channel < 3; $channel++) {
                $color += ($base + ($entry >> $channel & 1) * $low + ($entry >> $channel + 4 & 1) * $high) << 24 - 8 * $channel;
                $full  += ($entry >> $channel & 1) * ($entry < 8 ? 255 : 127) << 24 - 8 * $channel;
            }
            $clut[8][] = $color;
            if ($entry < 16) {
                $clut[4][] = $full;
            }
        }

        return $clut;
    }
}
