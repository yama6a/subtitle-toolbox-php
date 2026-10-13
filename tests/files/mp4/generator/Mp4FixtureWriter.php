<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Mp4;

/**
 * Writes ISO base media file format boxes and 3GPP timed text samples for test fixtures.
 */
final class Mp4FixtureWriter
{
    public const ALL_SAMPLES_FORCED = 0x80000000;


    public static function box(string $type, string $data): string
    {
        return pack("N", 8 + strlen($data)) . $type . $data;
    }


    public static function fullBox(string $type, int $version, int $flags, string $data): string
    {
        return self::box($type, pack("N", $version << 24 | $flags) . $data);
    }


    public static function ftyp(): string
    {
        return self::box("ftyp", "isom" . pack("N", 512) . "isomiso2mp41");
    }


    /**
     * A tx3g sample: the 16-bit text length, the text and the modifier boxes.
     */
    public static function textSample(string $text, string $modifiers = ""): string
    {
        return pack("n", strlen($text)) . $text . $modifiers;
    }


    /**
     * A c608 sample: a cdat box with the CEA-608 words of field 1 and a cdt2 box with those of field 2.
     *
     * @param list<int> $field1
     * @param list<int> $field2
     */
    public static function captionSample(array $field1, array $field2 = []): string
    {
        return self::box("cdat", pack("n*", ...$field1)) . ($field2 === [] ? "" : self::box("cdt2", pack("n*", ...$field2)));
    }


    /**
     * A tx3g sample entry, or with $type "enct" and a sinf box the entry of an encrypted track.
     */
    public static function tx3gEntry(int $displayFlags = 0, string $type = "tx3g", string $extraBoxes = ""): string
    {
        $style = pack("nnnCC", 0, 0, 1, 0, 18) . "\xFF\xFF\xFF\xFF";
        $ftab  = self::box("ftab", pack("nn", 1, 1) . chr(10) . "Sans-Serif");

        return self::box($type, str_repeat("\0", 6) . pack("n", 1) . pack("N", $displayFlags) . "\x01\xFF" .
                                "\0\0\0\0" . str_repeat("\0", 8) . $style . $ftab . $extraBoxes);
    }


    /**
     * Lays out the samples of all tracks in one mdat box and writes the moov box before or after it.
     * Each track holds "samples", a list of [duration, data], and "perChunk", the samples per chunk.
     *
     * @param list<array<string, mixed>> $tracks see trak() for the other keys
     */
    public static function file(array $tracks, bool $moovFirst): string
    {
        $mdat   = "";
        $chunks = [];
        foreach ($tracks as $index => $track) {
            foreach (array_chunk($track["samples"], $track["perChunk"]) as $samples) {
                $chunks[$index][] = [strlen($mdat), array_map(fn (array $sample): array => [$sample[0], strlen($sample[1])], $samples)];
                $mdat            .= implode("", array_column($samples, 1));
            }
        }

        $moov = fn (int $base): string => self::moov(array_map(
            fn (array $track, int $index): array => ["chunks" => array_map(fn (array $chunk): array => [$base + $chunk[0], $chunk[1]],
                                                                            $chunks[$index])] + $track,
            $tracks,
            array_keys($tracks),
        ));
        $ftyp = self::ftyp();
        if ($moovFirst) {
            $size = strlen($moov(0));

            return $ftyp . $moov(strlen($ftyp) + $size + 8) . self::box("mdat", $mdat);
        }

        return $ftyp . self::box("mdat", $mdat) . $moov(strlen($ftyp) + 8);
    }


    /**
     * @param list<array<string, mixed>> $tracks
     */
    public static function moov(array $tracks): string
    {
        $mvhd = self::fullBox("mvhd", 0, 0, pack("NNNN", 0, 0, 1000, 0) . str_repeat("\0", 80));

        return self::box("moov", $mvhd . implode("", array_map(self::trak(...), $tracks)));
    }


    /**
     * @param array{id: int, handler: string, entry: string, chunks: list<array{int, list<array{int, int}>}>, timescale?: int,
     *              language?: string, elng?: string, name?: string, enabled?: bool, version1?: bool, co64?: bool, stz2?: bool,
     *              boxes?: array<string, string>, edits?: list<array{int, int}>} $track
     *        chunks holds the file offset of each chunk and the duration and size of its samples.
     *        edits holds the segment duration and the media time of each edit list entry. A media time of -1 is an empty edit.
     *        boxes replaces the stsd, stts, stsc, stsz or stco box, so a test can write a broken one.
     */
    public static function trak(array $track): string
    {
        $version1 = $track["version1"] ?? false;
        $flags    = ($track["enabled"] ?? true) ? 0x000003 : 0x000002;
        $tkhd     = $version1
            ? self::fullBox("tkhd", 1, $flags, pack("JJNNJ", 0, 0, $track["id"], 0, 0) . str_repeat("\0", 60))
            : self::fullBox("tkhd", 0, $flags, pack("NNNNN", 0, 0, $track["id"], 0, 0) . str_repeat("\0", 60));

        $timescale = $track["timescale"] ?? 1000;
        $language  = $track["language"] ?? "und";
        $packed    = (ord($language[0]) - 0x60) << 10 | (ord($language[1]) - 0x60) << 5 | (ord($language[2]) - 0x60);
        $mdhd      = $version1
            ? self::fullBox("mdhd", 1, 0, pack("JJNJnn", 0, 0, $timescale, 0, $packed, 0))
            : self::fullBox("mdhd", 0, 0, pack("NNNNnn", 0, 0, $timescale, 0, $packed, 0));
        $hdlr      = self::fullBox("hdlr", 0, 0, pack("N", 0) . $track["handler"] . str_repeat("\0", 12) . "Handler\0");
        $elng      = isset($track["elng"]) ? self::fullBox("elng", 0, 0, $track["elng"] . "\0") : "";

        $mdia = self::box("mdia", $mdhd . $hdlr . $elng . self::box("minf", self::box("stbl", self::sampleTables($track))));
        $udta = isset($track["name"]) ? self::box("udta", self::box("name", $track["name"])) : "";

        $edts = isset($track["edits"])
            ? self::box("edts", self::fullBox("elst", 0, 0, pack("N", count($track["edits"])) .
                                    implode("", array_map(fn (array $edit): string => pack("NNnn", $edit[0], $edit[1] & 0xFFFFFFFF, 1, 0), $track["edits"]))))
            : "";

        return self::box("trak", $tkhd . $edts . $mdia . $udta);
    }


    private static function sampleTables(array $track): string
    {
        $samples = array_merge(...array_column($track["chunks"], 1));
        $stsd    = self::fullBox("stsd", 0, 0, pack("N", 1) . $track["entry"]);

        $stts = [];
        foreach ($samples as [$duration]) {
            if ($stts !== [] && $stts[array_key_last($stts)][1] === $duration) {
                $stts[array_key_last($stts)][0]++;
            } else {
                $stts[] = [1, $duration];
            }
        }
        $stts = self::fullBox("stts", 0, 0, pack("N", count($stts)) . implode("", array_map(fn (array $e): string => pack("NN", ...$e), $stts)));

        $stsc = [];
        foreach ($track["chunks"] as $index => [, $chunkSamples]) {
            if ($stsc === [] || $stsc[array_key_last($stsc)][1] !== count($chunkSamples)) {
                $stsc[] = [$index + 1, count($chunkSamples)];
            }
        }
        $stsc = self::fullBox("stsc", 0, 0, pack("N", count($stsc)) .
                                            implode("", array_map(fn (array $e): string => pack("NNN", $e[0], $e[1], 1), $stsc)));

        $sizes = array_column($samples, 1);
        $stsz  = ($track["stz2"] ?? false)
            ? self::fullBox("stz2", 0, 0, "\0\0\0\x10" . pack("N", count($sizes)) . pack("n*", ...$sizes))
            : self::fullBox("stsz", 0, 0, pack("NN", 0, count($sizes)) . pack("N*", ...$sizes));

        $offsets = array_column($track["chunks"], 0);
        $stco    = ($track["co64"] ?? false)
            ? self::fullBox("co64", 0, 0, pack("N", count($offsets)) . pack("J*", ...$offsets))
            : self::fullBox("stco", 0, 0, pack("N", count($offsets)) . pack("N*", ...$offsets));

        $boxes = ["stsd" => $stsd, "stts" => $stts, "stsc" => $stsc, "stsz" => $stsz, "stco" => $stco];

        return implode("", array_replace($boxes, array_intersect_key($track["boxes"] ?? [], $boxes)));
    }
}
