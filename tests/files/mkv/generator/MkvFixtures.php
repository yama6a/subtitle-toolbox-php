<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

require_once __DIR__ . "/MkvFixtureWriter.php";
require_once __DIR__ . "/../../pgs/generator/PgsFixtures.php";

use SubtitleToolbox\Parsers\PgsFixtures;

/**
 * Builds the MKV test fixtures. generate.php writes them to tests/files/mkv/. All text is written for this repository.
 */
final class MkvFixtures
{
    public const FILES = [
        "text_tracks.mkv"   => "textTracks",
        "compressed.mkv"    => "compressed",
        "unknown_sizes.mkv" => "unknownSizes",
        "seek_head.mkv"     => "seekHead",
        "pgs.mkv"           => "pgs",
        "vobsub.mkv"        => "vobSub",
    ];

    public const ASS_HEADER = "[Script Info]\n" .
                              "; Written for the subtitle-toolbox tests\n" .
                              "Title: Station\n" .
                              "ScriptType: v4.00+\n" .
                              "PlayResX: 1920\n" .
                              "PlayResY: 1080\n" .
                              "WrapStyle: 0\n" .
                              "\n" .
                              "[V4+ Styles]\n" .
                              "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, " .
                              "Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, " .
                              "Alignment, MarginL, MarginR, MarginV, Encoding\n" .
                              "Style: Default,Arial,48,&H00FFFFFF,&H000000FF,&H00000000,&H64000000,0,0,0,0,100,100,0,0,1,2,1,2,60,60,40,1\n" .
                              "Style: Sign,Arial,40,&H0000FFFF,&H000000FF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,2,0,8,60,60,40,1\n" .
                              "\n" .
                              "[Events]\n" .
                              "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

    public const SSA_HEADER = "[Script Info]\r\n" .
                              "ScriptType: v4.00\r\n" .
                              "Title: Estacion\r\n" .
                              "\r\n" .
                              "[V4 Styles]\r\n" .
                              "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, TertiaryColour, BackColour, Bold, " .
                              "Italic, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, AlphaLevel, Encoding\r\n" .
                              "Style: Default,Arial,20,16777215,65535,65535,0,0,0,1,2,0,2,30,30,30,0,0\r\n";

    public const VTT_HEADER = "WEBVTT - Gare\n\nSTYLE\n::cue {\n  color: yellow;\n}\n\nNOTE Fichier écrit pour les tests";


    public static function textTracks(): string
    {
        $tracks = [
            ["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"],
            ["number" => 2, "type" => MkvFixtureWriter::TRACK_AUDIO, "codecId" => "A_PCM/INT/LIT"],
            ["number" => 3, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "language" => "ger",
             "bcp47" => "de", "name" => "Deutsch (Forced)", "default" => false, "forced" => true],
            ["number" => 4, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/ASS", "language" => "eng",
             "name" => "English", "default" => true, "codecPrivate" => self::ASS_HEADER],
            ["number" => 5, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/WEBVTT", "language" => "fre",
             "name" => "Français", "default" => false, "codecPrivate" => self::VTT_HEADER],
            ["number" => 6, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/SSA", "language" => "spa",
             "default" => false, "codecPrivate" => self::SSA_HEADER],
            ["number" => 7, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_DVBSUB", "language" => "ita",
             "default" => false, "codecPrivate" => "\x00\x01\x00\x01"],
            ["number" => 8, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "default" => false],
        ];

        $blocks = self::mediaBlocks(20000);
        $text   = [
            [3, 1000, 2500, "Der Zug nach Hamburg fährt um acht Uhr ab."],
            [3, 4000, 2000, "<i>Gleis 4</i>\r\nBitte nicht einsteigen."],
            [3, 9800, 2200, "Die Bäckerei öffnet um sechs."],
            [3, 15000, 2250, "Morgen wird es sonnig\n\nund warm.\n"],
            [4, 1000, 3000, "1,0,Default,Guard,0,0,0,,The train to the coast leaves at eight."],
            [4, 1000, 5000, "0,1,Sign,,0,0,0,,{\\an8}PLATFORM 4"],
            [4, 7000, 2000, "2,0,Default,,0,0,0,,The bakery on the corner\\Nis open {\\i1}every{\\i0} day."],
            [4, 11000, 2500, "3,0,Default,Guard,0,0,0,,Bring an umbrella, it may rain later."],
            [5, 2000, 3000, "Le train pour Lyon part à huit heures.", "line:10% align:start\nannonce-1\n"],
            [5, 6000, 2500, "La boulangerie ouvre à six heures.\nLe pain est <00:00:01.500>encore chaud.", "\n\nNOTE Deuxième annonce"],
            [5, 12000, 2000, "Demain, il fera beau.", null],
            [6, 3000, 2000, "0,,Default,,0000,0000,0000,,El tren sale a las ocho."],
            [6, 13000, 2500, "1,,Default,,0000,0000,0000,,La panadería abre a las seis."],
            [7, 3000, 2000, "\x20\x00\x0F"],
        ];
        foreach ($text as $cue) {
            $blocks[] = [$cue[1], $cue[0], fn (int $time): string => MkvFixtureWriter::blockGroup($cue[0], $time, $cue[3], $cue[2], $cue[4] ?? null)];
        }
        foreach ([[2500, "Next stop: Central Station."], [8000, "Doors open on the left."], [16000, "End of the line."]] as [$start, $line]) {
            $blocks[] = [$start, 8, fn (int $time): string => MkvFixtureWriter::simpleBlock(8, $time, $line)];
        }

        $blocks[] = [0, 0, fn (): string => MkvFixtureWriter::element(MkvFixtureWriter::VOID, str_repeat("\0", 6))];
        $clusters = self::clusters($blocks, [0, 10000]);

        return self::file(MkvFixtureWriter::info() . self::tracks($tracks) . $clusters . self::cuesAndTags());
    }


    public static function compressed(): string
    {
        $headerStripped = fn (string $prefix, string $text): string => substr($text, strlen($prefix));
        $tracks         = [
            ["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"],
            ["number" => 3, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "language" => "eng",
             "encodings" => MkvFixtureWriter::compression(0, 0)],
            ["number" => 4, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/ASS", "language" => "eng",
             "codecPrivate" => gzcompress(self::ASS_HEADER, 9), "encodings" => MkvFixtureWriter::compression(0, 0, scope: 3)],
            ["number" => 5, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "language" => "eng",
             "encodings" => MkvFixtureWriter::compression(0, 3, "Weather: ")],
            ["number" => 6, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "language" => "eng",
             "encodings" => MkvFixtureWriter::compression(0, 3, "Bakery: ") . MkvFixtureWriter::compression(1, 0)],
            ["number" => 7, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "language" => "eng",
             "encodings" => MkvFixtureWriter::compression(0, 1)],
        ];

        $blocks = self::mediaBlocks(8000, false);
        $text   = [
            [3, 1000, 2000, gzcompress("The ferry leaves at noon.", 9)],
            [3, 4000, 2000, gzcompress("Tickets are sold\nat the harbour.", 9)],
            [4, 2000, 3000, gzcompress("0,0,Default,,0,0,0,,The museum is closed on Mondays.", 9)],
            [5, 1500, 2000, $headerStripped("Weather: ", "Weather: sunny.")],
            [5, 5000, 2500, $headerStripped("Weather: ", "Weather: cloudy, with light rain.")],
            [6, 3000, 1500, gzcompress($headerStripped("Bakery: ", "Bakery: fresh bread at seven."), 9)],
            [7, 3000, 1500, "BZh9"],
        ];
        foreach ($text as [$track, $start, $duration, $data]) {
            $blocks[] = [$start, $track, fn (int $time): string => MkvFixtureWriter::blockGroup($track, $time, $data, $duration)];
        }

        return self::file(MkvFixtureWriter::info() . self::tracks($tracks) . self::clusters($blocks, [0]));
    }


    /**
     * A live recording: the Segment and the Clusters have an unknown size. Ticks of 0.1 ms.
     */
    public static function unknownSizes(): string
    {
        $tracks = [
            ["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"],
            ["number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8", "language" => "swe"],
        ];

        $blocks = [];
        for ($time = 0; $time < 90000; $time += 5000) {
            $blocks[] = [$time, 1, fn (int $relative): string => MkvFixtureWriter::simpleBlock(1, $relative, str_repeat("\x10", 32))];
        }
        foreach ([[12345, 17655, "Tåget till Malmö är försenat."], [40000, 15000, "Bageriet stänger klockan fem."],
                  [70002, 19998, "I morgon blir det soligt."]] as [$start, $duration, $line]) {
            $blocks[] = [$start, 2, fn (int $relative): string => MkvFixtureWriter::blockGroup(2, $relative, $line, $duration)];
        }

        $segment = MkvFixtureWriter::info(100000) . self::tracks($tracks) . self::clusters($blocks, [0, 30000, 60000], true);

        return MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::SEGMENT, $segment);
    }


    /**
     * Info and Tracks follow the clusters. The SeekHead at the start of the segment points to them. WebM doc type.
     */
    public static function seekHead(): string
    {
        $tracks = [
            ["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_VP9"],
            ["number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/WEBVTT", "language" => "eng"],
        ];

        $blocks = [
            [0, 1, fn (int $time): string => MkvFixtureWriter::simpleBlock(1, $time, str_repeat("\x20", 32))],
            [1000, 2, fn (int $time): string => MkvFixtureWriter::blockGroup(2, $time, "The library opens at nine.", 2000)],
            [2000, 1, fn (int $time): string => MkvFixtureWriter::simpleBlock(1, $time, str_repeat("\x20", 32))],
        ];

        $void     = MkvFixtureWriter::element(MkvFixtureWriter::VOID, str_repeat("\0", 20));
        $clusters = self::clusters($blocks, [0]);
        $info     = MkvFixtureWriter::info();
        $before   = strlen(MkvFixtureWriter::seekHead([MkvFixtureWriter::INFO => 0, MkvFixtureWriter::TRACKS => 0])) +
                    strlen($void) + strlen($clusters);
        $seekHead = MkvFixtureWriter::seekHead([MkvFixtureWriter::INFO => $before, MkvFixtureWriter::TRACKS => $before + strlen($info)]);

        return MkvFixtureWriter::ebmlHeader("webm") .
               MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, $seekHead . $void . $clusters . $info . self::tracks($tracks));
    }


    /**
     * Track 3 has one PGS segment per block, as the Matroska spec says. Track 4 has one display set per block,
     * in BlockGroups without BlockDuration, and the forced flag. Both hold shapes_1080p.sup.
     */
    public static function pgs(): string
    {
        $tracks = [
            ["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"],
            ["number" => 3, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_HDMV/PGS", "language" => "ger"],
            ["number" => 4, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_HDMV/PGS", "language" => "eng",
             "forced" => true],
        ];

        $blocks     = self::mediaBlocks(16000, false);
        $sup        = PgsFixtures::shapes1080p();
        $displaySet = "";
        for ($offset = 0; $offset < strlen($sup); $offset += 13 + $size) {
            ["pts" => $pts, "type" => $type, "size" => $size] = unpack("Npts/Ndts/Ctype/nsize", $sup, $offset + 2);
            $segment     = substr($sup, $offset + 10, 3 + $size);
            $milliseconds = intdiv($pts, 90);
            $blocks[]    = [$milliseconds, 3, fn (int $time): string => MkvFixtureWriter::simpleBlock(3, $time, $segment)];

            $displaySet .= $segment;
            if ($type === 0x80) {
                $data        = $displaySet;
                $blocks[]    = [$milliseconds, 4, fn (int $time): string => MkvFixtureWriter::blockGroup(4, $time, $data, null)];
                $displaySet  = "";
            }
        }

        return self::file(MkvFixtureWriter::info() . self::tracks($tracks) . self::clusters($blocks, [0, 10000]));
    }


    /**
     * Both tracks hold the "en" units of ../vobsub/two-tracks-pal. Track 3 has the whole .idx header in CodecPrivate and
     * SimpleBlocks. Track 4 has only the size and palette lines, zlib compression and BlockGroups of 1500 ms.
     */
    public static function vobSub(): string
    {
        $idx    = file_get_contents(__DIR__ . "/../../vobsub/two-tracks-pal.idx");
        $header = substr($idx, 0, strpos($idx, "# English"));
        preg_match_all('/^(?:size|palette):.*$/m', $header, $lines);
        $tracks = [
            ["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"],
            ["number" => 3, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_VOBSUB", "language" => "eng",
             "codecPrivate" => $header],
            ["number" => 4, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_VOBSUB", "language" => "eng",
             "codecPrivate" => implode("\n", array_map("trim", $lines[0])) . "\n",
             "encodings" => MkvFixtureWriter::compression(0, 0)],
        ];

        $blocks = self::mediaBlocks(22000, false);
        foreach (self::vobSubUnits($idx, file_get_contents(__DIR__ . "/../../vobsub/two-tracks-pal.sub"), "en") as [$start, $unit]) {
            $compressed = gzcompress($unit, 9);
            $blocks[]   = [$start, 3, fn (int $time): string => MkvFixtureWriter::simpleBlock(3, $time, $unit)];
            $blocks[]   = [$start, 4, fn (int $time): string => MkvFixtureWriter::blockGroup(4, $time, $compressed, 1500)];
        }

        return self::file(MkvFixtureWriter::info() . self::tracks($tracks) . self::clusters($blocks, [0, 10000]));
    }


    /**
     * Joins the private stream 1 payloads of the track from each timestamp line until they hold one whole unit.
     *
     * @return list<array{int, string}> start in milliseconds and the subpicture unit
     */
    public static function vobSubUnits(string $idx, string $sub, string $language): array
    {
        preg_match('/^id: ' . $language . ', index: (\d+)\r?\n(.*?)(?:^id:|\z)/ms', $idx, $track);
        preg_match_all('/^timestamp: (\d+):(\d+):(\d+):(\d+), filepos: ([0-9a-f]+)/m', $track[2], $entries, PREG_SET_ORDER);

        $units = [];
        foreach ($entries as [, $hours, $minutes, $seconds, $milliseconds, $filepos]) {
            $unit     = "";
            $position = (int) hexdec($filepos);
            while (strlen($unit) < 2 || strlen($unit) < unpack("n", $unit)[1]) {
                $code = ord($sub[$position + 3]);
                if ($code === 0xBA) {
                    $position += 14;
                    continue;
                }
                $end   = $position + 6 + unpack("n", $sub, $position + 4)[1];
                $start = $position + 9 + ord($sub[$position + 8]);
                if ($code === 0xBD && ord($sub[$start]) === 0x20 + (int) $track[1]) {
                    $unit .= substr($sub, $start + 1, $end - $start - 1);
                }
                $position = $end;
            }
            $units[] = [(($hours * 60 + $minutes) * 60 + $seconds) * 1000 + $milliseconds, substr($unit, 0, unpack("n", $unit)[1])];
        }

        return $units;
    }


    private static function file(string $segment): string
    {
        return MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, $segment);
    }


    private static function tracks(array $tracks): string
    {
        return MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, implode("", array_map(MkvFixtureWriter::trackEntry(...), $tracks)));
    }


    /**
     * Video blocks every 500 ms and Xiph-laced audio blocks every 1000 ms.
     *
     * @return list<array{int, int, \Closure}>
     */
    private static function mediaBlocks(int $until, bool $withAudio = true): array
    {
        $blocks = [];
        for ($time = 0; $time < $until; $time += 500) {
            $blocks[] = [$time, 1, fn (int $relative): string => MkvFixtureWriter::simpleBlock(1, $relative, str_repeat("\x80", 64))];
            if ($withAudio && $time % 1000 === 0) {
                $laced    = "\x02\x0A\x0A" . str_repeat("\x01", 30);
                $blocks[] = [$time, 2, fn (int $relative): string => MkvFixtureWriter::simpleBlock(2, $relative, $laced, MkvFixtureWriter::LACING_XIPH)];
            }
        }

        return $blocks;
    }


    /**
     * Puts each block into the last cluster that starts at most 200 ticks after it, so a block can have a negative relative time.
     *
     * @param list<array{int, int, \Closure}> $blocks [time, track number, writer of the relative time]
     * @param list<int> $starts cluster timestamps
     */
    private static function clusters(array $blocks, array $starts, bool $unknownSize = false): string
    {
        usort($blocks, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $grouped = array_fill_keys($starts, []);
        foreach ($blocks as [$time, , $write]) {
            $cluster = $starts[0];
            foreach ($starts as $start) {
                if ($start <= $time + 200) {
                    $cluster = $start;
                }
            }
            $grouped[$cluster][] = $write($time - $cluster);
        }

        return implode("", array_map(fn (int $start): string => MkvFixtureWriter::cluster($start, $grouped[$start], $unknownSize), $starts));
    }


    private static function cuesAndTags(): string
    {
        $cuePoint = MkvFixtureWriter::element(0xBB, MkvFixtureWriter::uint(0xB3, 0) .
                                                    MkvFixtureWriter::element(0xB7, MkvFixtureWriter::uint(0xF7, 1) . MkvFixtureWriter::uint(0xF1, 0)));
        $tag      = MkvFixtureWriter::element(0x7373, MkvFixtureWriter::element(0x63C0, "") .
                                                      MkvFixtureWriter::element(0x67C8, MkvFixtureWriter::element(0x45A3, "TITLE") .
                                                                                        MkvFixtureWriter::element(0x4487, "Station")));

        return MkvFixtureWriter::element(MkvFixtureWriter::CUES, $cuePoint) . MkvFixtureWriter::element(MkvFixtureWriter::TAGS, $tag);
    }
}
