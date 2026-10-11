<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

/**
 * Writes EBML elements and Matroska blocks for test fixtures.
 */
final class MkvFixtureWriter
{
    public const SEGMENT       = 0x18538067;
    public const SEEK_HEAD     = 0x114D9B74;
    public const INFO          = 0x1549A966;
    public const TRACKS        = 0x1654AE6B;
    public const CLUSTER       = 0x1F43B675;
    public const CUES          = 0x1C53BB6B;
    public const TAGS          = 0x1254C367;
    public const VOID          = 0xEC;
    public const TRACK_VIDEO   = 1;
    public const TRACK_AUDIO   = 2;
    public const TRACK_SUBTITLE = 0x11;

    public const LACING_XIPH = 0x02;


    public static function element(int $id, string $data): string
    {
        return self::id($id) . self::size(strlen($data)) . $data;
    }


    /**
     * Writes the size as all ones, the marker of an unknown size, in 8 bytes.
     */
    public static function unknownSizeElement(int $id, string $data): string
    {
        return self::id($id) . "\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF" . $data;
    }


    public static function uint(int $id, int $value): string
    {
        $bytes = ltrim(pack("J", $value), "\0");

        return self::element($id, $bytes === "" ? "\0" : $bytes);
    }


    public static function id(int $id): string
    {
        return ltrim(pack("N", $id), "\0");
    }


    public static function size(int $size): string
    {
        $length = 1;
        while ($size >= (1 << (7 * $length)) - 1) {
            $length++;
        }

        return substr(pack("J", $size | (1 << (7 * $length))), 8 - $length);
    }


    public static function ebmlHeader(string $docType = "matroska"): string
    {
        return self::element(0x1A45DFA3, self::uint(0x4286, 1) . self::uint(0x42F7, 1) . self::uint(0x42F2, 4) .
                                         self::uint(0x42F3, 8) . self::element(0x4282, $docType) .
                                         self::uint(0x4287, 4) . self::uint(0x4285, 2));
    }


    public static function info(int $timestampScale = 1000000, string $writingApp = "MkvFixtureWriter"): string
    {
        return self::element(self::INFO, self::uint(0x2AD7B1, $timestampScale) . self::element(0x4D80, "MkvFixtureWriter") .
                                         self::element(0x5741, $writingApp));
    }


    /**
     * @param array{number: int, type: int, codecId: string, codecPrivate?: string, language?: string, bcp47?: string,
     *              name?: string, default?: bool, forced?: bool, defaultDuration?: int, encodings?: string} $track
     */
    public static function trackEntry(array $track): string
    {
        $data = self::uint(0xD7, $track["number"]) . self::uint(0x73C5, $track["number"] * 1000) .
                self::uint(0x83, $track["type"]) . self::element(0x86, $track["codecId"]);
        if (isset($track["default"])) {
            $data .= self::uint(0x88, (int) $track["default"]);
        }
        if (isset($track["forced"])) {
            $data .= self::uint(0x55AA, (int) $track["forced"]);
        }
        if (isset($track["defaultDuration"])) {
            $data .= self::uint(0x23E383, $track["defaultDuration"]);
        }
        if (isset($track["name"])) {
            $data .= self::element(0x536E, $track["name"]);
        }
        if (isset($track["language"])) {
            $data .= self::element(0x22B59C, $track["language"]);
        }
        if (isset($track["bcp47"])) {
            $data .= self::element(0x22B59D, $track["bcp47"]);
        }
        if (isset($track["codecPrivate"])) {
            $data .= self::element(0x63A2, $track["codecPrivate"]);
        }
        if (isset($track["encodings"])) {
            $data .= self::element(0x6D80, $track["encodings"]);
        }

        return self::element(0xAE, $data);
    }


    /**
     * @param int $algo 0 zlib, 1 bzlib, 2 lzo1x, 3 header stripping
     * @param int $scope 1 frames, 2 CodecPrivate, 3 both
     */
    public static function compression(int $order, int $algo, string $settings = "", int $scope = 1): string
    {
        $compression = self::uint(0x4254, $algo) . ($settings === "" ? "" : self::element(0x4255, $settings));

        return self::element(0x6240, self::uint(0x5031, $order) . self::uint(0x5032, $scope) . self::uint(0x5033, 0) .
                                     self::element(0x5034, $compression));
    }


    public static function simpleBlock(int $track, int $time, string $data, int $lacing = 0): string
    {
        return self::element(0xA3, self::size($track) . pack("nC", $time & 0xFFFF, 0x80 | $lacing) . $data);
    }


    public static function blockGroup(int $track, int $time, string $data, ?int $duration, ?string $additional = null): string
    {
        $group = self::element(0xA1, self::size($track) . pack("nC", $time & 0xFFFF, 0) . $data);
        if ($additional !== null) {
            $group .= self::element(0x75A1, self::element(0xA6, self::uint(0xEE, 1) . self::element(0xA5, $additional)));
        }
        if ($duration !== null) {
            $group .= self::uint(0x9B, $duration);
        }

        return self::element(0xA0, $group);
    }


    /**
     * @param list<string> $blocks
     */
    public static function cluster(int $timestamp, array $blocks, bool $unknownSize = false): string
    {
        $data = self::uint(0xE7, $timestamp) . implode("", $blocks);

        return $unknownSize ? self::unknownSizeElement(self::CLUSTER, $data) : self::element(self::CLUSTER, $data);
    }


    /**
     * @param list<array{int, int, int, int}> $points the time, the track number, the cluster position relative to the segment data
     *                                               and the block position relative to the cluster data
     */
    public static function cues(array $points): string
    {
        return self::element(self::CUES, implode("", array_map(
            fn (array $point): string => self::element(0xBB, self::uint(0xB3, $point[0]) . self::element(0xB7,
                self::uint(0xF7, $point[1]) . self::uint(0xF1, $point[2]) . self::uint(0xF0, $point[3]))),
            $points,
        )));
    }


    /**
     * Writes the NUMBER_OF_FRAMES statistics tag of one track, as mkvmerge writes it.
     */
    public static function frameCountTags(int $trackUid, int $frames): string
    {
        return self::element(self::TAGS, self::element(0x7373, self::element(0x63C0, self::uint(0x63C5, $trackUid)) .
                                                               self::element(0x67C8, self::element(0x45A3, "NUMBER_OF_FRAMES") .
                                                                                     self::element(0x4487, (string) $frames))));
    }


    /**
     * Writes each position in 8 bytes, so the size of the SeekHead does not depend on the positions.
     *
     * @param array<int, int> $positions element ID => position relative to the segment data
     */
    public static function seekHead(array $positions): string
    {
        $data = "";
        foreach ($positions as $id => $position) {
            $data .= self::element(0x4DBB, self::element(0x53AB, self::id($id)) . self::element(0x53AC, pack("J", $position)));
        }

        return self::element(self::SEEK_HEAD, $data);
    }
}
