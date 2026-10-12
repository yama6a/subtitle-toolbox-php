<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Reads DVD VobSub subtitles: the .idx index from VobSubReadOptions and the .sub program stream from parse().
 *
 * Program stream layout: http://sam.zoy.org/writings/dvd/subtitles/
 * DvdSubpicture decodes the subpicture units.
 * Index lines: https://github.com/FFmpeg/FFmpeg/blob/master/libavformat/mpeg.c and VSFilter's VobSubFile.cpp
 */
final class VobSubParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = VobSubReadOptions::class;
    protected const BINARY = true;

    private const SUBSTREAM_FIRST     = 0x20;
    private const MAX_SUBSTREAM_INDEX = 31;

    private const INDEX_FIRST_LINE = "# VobSub index file, v7 (do not modify this line!)";

    private const MPEG_START_CODE   = "\x00\x00\x01";
    private const PACK_START        = 0xBA;
    private const PRIVATE_STREAM_1  = 0xBD;
    private const FIRST_STREAM_CODE = 0xBB;

    // An MPEG-2 pack header has 14 bytes plus up to 7 stuffing bytes. Its byte after the start code begins with the bits 01.
    private const PACK_HEADER_SIZE     = 14;
    private const PACK_STUFFING_OFFSET = 13;
    private const PACK_STUFFING_MASK   = 0x07;
    private const MPEG2_MARKER_MASK    = 0xC0;
    private const MPEG2_MARKER         = 0x40;

    // A PES packet starts with the start code, the stream code and the 2-byte packet length.
    private const PES_HEADER_SIZE               = 6;
    private const PES_HEADER_DATA_LENGTH_OFFSET = 8;
    private const PES_PAYLOAD_OFFSET            = 9;

    private int $screenWidth;
    private int $screenHeight;

    /** @var list<int> 0xRRGGBB */
    private array $palette = [];

    /** @var list<int>|null 0xRRGGBBAA by pixel value, from an enabled "custom colors" line */
    private ?array $customColors = null;

    private int $trackIndex;
    private ?string $language = null;

    /** @var array<int, array{time: float, filepos: int}> in time order, by the index of the timestamp line in the track */
    private array $entries = [];


    /**
     * Reads the image cues of one track from the .sub content. VobSubReadOptions holds the .idx content.
     * Its $track and $language select the track. Without them, the parser reads the first track.
     */
    protected function read(string $content): Subtitle
    {
        $formatOptions = $this->formatOptions();
        if ($formatOptions->idx === null) {
            throw new InvalidArgumentException("VobSub needs the .idx content. Set VobSubReadOptions::\$idx.");
        }
        $this->palette      = [];
        $this->customColors = null;
        $this->selectTrack($this->readIndex($formatOptions->idx), $formatOptions->track, $formatOptions->language);

        $units = [];
        foreach ($this->entries as $index => $entry) {
            try {
                if ($entry["filepos"] >= strlen($content)) {
                    throw new ParsingException(sprintf("The filepos %09x of timestamp %.3f is outside the .sub content of %d bytes.",
                                                       $entry["filepos"], $entry["time"], strlen($content)));
                }
                $unit = $this->decodeUnit($this->readUnit($content, $entry["filepos"]));
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, []);
                continue;
            }

            $unit["start"] = $entry["time"] + $unit["startDelay"];
            $unit["stop"]  = $unit["stopDelay"] === null ? null : $entry["time"] + $unit["stopDelay"];
            $units[]       = $unit;
        }

        return $this->toSubtitle($units);
    }


    /**
     * Reads the subpicture units of a Matroska S_VOBSUB track. $codecPrivate holds the .idx header lines.
     * A unit without a stop command ends at the end of its block.
     *
     * @param list<array{start: float, end: float, data: string}> $blocks times in seconds
     *
     * @internal
     */
    public function parseBlocks(string $codecPrivate, array $blocks, ReadOptions $options): Subtitle
    {
        $this->useOptions($options);
        $this->palette      = [];
        $this->customColors = null;
        $this->language     = null;
        $header             = StringHelpers::removeUtf8Bom($codecPrivate);
        $this->readIndex(str_contains(explode("\n", $header, 2)[0], "VobSub index file") ? $header : self::INDEX_FIRST_LINE . "\n" . $header);

        $units = [];
        foreach ($blocks as $block) {
            $unit          = $this->decodeUnit($block["data"]);
            $unit["start"] = $block["start"] + $unit["startDelay"];
            $unit["stop"]  = $unit["stopDelay"] === null ? max($block["end"], $unit["start"]) : $block["start"] + $unit["stopDelay"];
            $units[]       = $unit;
        }

        return $this->toSubtitle($units)->setParseWarnings($this->warnings);
    }


    /**
     * @param list<array{start: float, stop: ?float, image: ?CueImage}> $units
     */
    private function toSubtitle(array $units): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        if ($this->language !== null) {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $this->language);
        }
        foreach ($units as $index => $unit) {
            if ($unit["image"] === null) {
                continue;
            }

            $end = $unit["stop"] ?? min($unit["start"] + $this->options->lastCueDuration,
                                        $units[$index + 1]["start"] ?? INF);
            $parsedCues[] = $unit["image"]->toCue(new SubtitleCue($unit["start"], $end));
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * @param list<array{id: string, index: int, entries: list<array{time: float, filepos: int}>}> $tracks
     */
    private function selectTrack(array $tracks, ?int $track, ?string $language): void
    {
        $selected = null;
        foreach ($tracks as $candidate) {
            if (($track === null || $candidate["index"] === $track)
                && ($language === null || strcasecmp($candidate["id"], $language) === 0)) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            $wanted = implode(" and ", array_filter([
                $track === null ? null : "index $track",
                $language === null ? null : "language \"$language\"",
            ]));
            throw new ParsingException($wanted === ""
                ? "The .idx content has no \"id:\" line."
                : "The .idx content has no track with $wanted.");
        }

        $this->trackIndex = $selected["index"];
        $this->language   = $selected["id"];
        $this->entries    = $selected["entries"];
        uasort($this->entries, fn (array $entry1, array $entry2): int => $entry1["time"] <=> $entry2["time"]);
    }


    /**
     * @return list<array{id: string, index: int, entries: list<array{time: float, filepos: int}>}>
     */
    private function readIndex(string $idx): array
    {
        $lines = $this->lines(StringHelpers::removeUtf8Bom($idx));
        if (!str_contains($lines[0], "VobSub index file")) {
            throw new ParsingException("The .idx content does not start with the \"VobSub index file\" line.");
        }

        $tracks = [];
        $delay  = 0.0;
        $size   = null;
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === "#" || !str_contains($line, ":")) {
                continue;
            }

            [$key, $value] = array_map("trim", explode(":", $line, 2));
            switch (strtolower($key)) {
                case "size":
                    $size = self::readSize($value, $line);
                    break;
                case "palette":
                    $this->palette = $this->readColors($value, 16, $line);
                    break;
                case "custom colors":
                    $this->customColors = $this->readCustomColors($value, $line);
                    break;
                case "id":
                    $tracks[] = self::readTrackId($value, $line);
                    $delay    = 0.0;
                    break;
                case "delay":
                    // VSFilter adds up the delay lines of a track and resets the sum at each id line.
                    $delay += $this->readTime($value, $line);
                    break;
                case "timestamp":
                    if ($tracks === []) {
                        throw new ParsingException("The .idx timestamp line \"$line\" comes before any id line.");
                    }
                    $tracks[array_key_last($tracks)]["entries"][] = $this->readEntry($value, $line, $delay);
                    break;
            }
        }

        $this->setScreen($size);

        return $tracks;
    }


    /**
     * @param array{int, int}|null $size
     */
    private function setScreen(?array $size): void
    {
        if ($size === null) {
            throw new ParsingException("The .idx content has no size line.");
        }
        if ($this->palette === [] && $this->customColors === null) {
            throw new ParsingException("The .idx content has no palette line.");
        }
        [$this->screenWidth, $this->screenHeight] = $size;
    }


    /**
     * @return array{int, int}
     */
    private static function readSize(string $value, string $line): array
    {
        if (!preg_match('/^(\d+)\s*x\s*(\d+)$/i', $value, $matches) || $matches[1] < 1 || $matches[2] < 1) {
            throw new ParsingException("The .idx size line \"$line\" is not valid.");
        }

        return [(int) $matches[1], (int) $matches[2]];
    }


    /**
     * Returns the colors of an enabled "custom colors" line, or null for a disabled one.
     *
     * @return list<int>|null
     */
    private function readCustomColors(string $value, string $line): ?array
    {
        if (!preg_match('/^(on|off|1|0)\s*,\s*tridx\s*:\s*([01]{4})\s*,\s*colors\s*:\s*(.*)$/i', $value, $matches)) {
            throw new ParsingException("The .idx custom colors line \"$line\" is not valid.");
        }
        if (!in_array(strtolower($matches[1]), ["on", "1"], true)) {
            return null;
        }

        return array_map(
            fn (int $rgb, int $pixelValue): int => $rgb << 8 | ($matches[2][$pixelValue] === "1" ? 0x00 : 0xFF),
            $this->readColors($matches[3], 4, $line),
            [0, 1, 2, 3]
        );
    }


    /**
     * @return array{id: string, index: int, entries: list<array{time: float, filepos: int}>}
     */
    private static function readTrackId(string $value, string $line): array
    {
        if (!preg_match('/^([^,]*),\s*index:\s*(\d+)$/i', $value, $matches) || $matches[2] > self::MAX_SUBSTREAM_INDEX) {
            throw new ParsingException("The .idx id line \"$line\" is not valid.");
        }

        return ["id" => trim($matches[1]), "index" => (int) $matches[2], "entries" => []];
    }


    /**
     * @return array{time: float, filepos: int}
     */
    private function readEntry(string $value, string $line, float $delay): array
    {
        if (!preg_match('/^(.+?),\s*filepos:\s*([0-9a-f]+)$/i', $value, $matches)) {
            throw new ParsingException("The .idx timestamp line \"$line\" is not valid.");
        }

        return [
            "time"    => self::boundedTime($this->readTime($matches[1], $line) + $delay, $delay === 0.0 ? $line : "$line with delay {$delay}s", null),
            "filepos" => (int) hexdec($matches[2]),
        ];
    }


    /**
     * @return list<int>
     */
    private function readColors(string $value, int $count, string $line): array
    {
        $colors = array_map("trim", explode(",", $value));
        if (count($colors) !== $count || preg_grep('/^[0-9a-f]{1,6}$/i', $colors, PREG_GREP_INVERT) !== []) {
            throw new ParsingException("The .idx line \"$line\" needs $count colors as hex RGB.");
        }

        return array_map("hexdec", $colors);
    }


    private function readTime(string $value, string $line): float
    {
        if (!preg_match('/^([+-]?)(\d+):(\d{1,2}):(\d{1,2})[:.,](\d{1,3})$/', trim($value), $matches)) {
            throw new ParsingException("The .idx time \"$line\" is not valid.");
        }
        $seconds = self::boundedTime(Timecode::toSeconds((int) $matches[2], (int) $matches[3], (int) $matches[4], str_pad($matches[5], 3, "0", STR_PAD_LEFT)), $line, null);

        return $matches[1] === "-" ? -$seconds : $seconds;
    }


    /**
     * Joins the private stream 1 payloads of the selected track from $filepos on, until they hold one whole SPU.
     */
    private function readUnit(string $sub, int $filepos): string
    {
        $unit     = "";
        $unitSize = null;
        $position = $filepos;
        $length   = strlen($sub);

        while ($unitSize === null || strlen($unit) < $unitSize) {
            if ($position + self::PES_HEADER_SIZE > $length || substr($sub, $position, 3) !== self::MPEG_START_CODE) {
                throw new ParsingException(sprintf("The .sub content has no complete subtitle packet at filepos %09x. " .
                                                   "It ends or has no MPEG start code at byte %d.", $filepos, $position));
            }

            $code = ord($sub[$position + 3]);
            if ($code === self::PACK_START) {
                if ($position + self::PACK_HEADER_SIZE > $length || (ord($sub[$position + 4]) & self::MPEG2_MARKER_MASK) !== self::MPEG2_MARKER) {
                    throw new ParsingException("The .sub content has no MPEG-2 pack header at byte $position.");
                }
                $position += self::PACK_HEADER_SIZE + (ord($sub[$position + self::PACK_STUFFING_OFFSET]) & self::PACK_STUFFING_MASK);
                continue;
            }
            if ($code < self::FIRST_STREAM_CODE) {
                throw new ParsingException(sprintf("The .sub content ends at byte %d before the subtitle packet at filepos %09x is complete.",
                                                   $position, $filepos));
            }

            $packetEnd = $position + self::PES_HEADER_SIZE + unpack("n", $sub, $position + 4)[1];
            if ($packetEnd > $length) {
                throw new ParsingException("The .sub packet at byte $position is longer than the content.");
            }
            if ($code === self::PRIVATE_STREAM_1) {
                $payloadStart = $position + self::PES_PAYLOAD_OFFSET + ord($sub[$position + self::PES_HEADER_DATA_LENGTH_OFFSET]);
                $substream    = $payloadStart < $packetEnd ? ord($sub[$payloadStart]) : -1;
                if ($substream === self::SUBSTREAM_FIRST + $this->trackIndex) {
                    $unit .= substr($sub, $payloadStart + 1, $packetEnd - $payloadStart - 1);
                }
            }
            $position = $packetEnd;

            if ($unitSize === null && strlen($unit) >= 2) {
                $unitSize = unpack("n", $unit)[1];
            }
        }

        return substr($unit, 0, $unitSize);
    }


    /**
     * @return array{startDelay: float, stopDelay: ?float, image: ?CueImage}
     */
    private function decodeUnit(string $unit): array
    {
        return DvdSubpicture::decode($unit, $this->palette, $this->customColors, $this->screenWidth, $this->screenHeight);
    }
}
