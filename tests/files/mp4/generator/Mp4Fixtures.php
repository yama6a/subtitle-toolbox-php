<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Mp4;

require_once __DIR__ . "/Mp4FixtureWriter.php";

/**
 * Builds the MP4 test fixtures. generate.php writes them to tests/files/mp4/. All text is written for this repository.
 */
final class Mp4Fixtures
{
    public const FILES = [
        "text_tracks.mp4" => "textTracks",
        "one_track.mp4"   => "oneTrack",
        "c708_track.mov"  => "c708Track",
    ];


    /**
     * The moov box follows the mdat box, as ffmpeg writes it without -movflags faststart.
     */
    public static function textTracks(): string
    {
        $styl    = Mp4FixtureWriter::box("styl", pack("n", 1) . pack("nnnCC", 0, 4, 1, 1, 18) . "\xFF\xFF\xFF\xFF");
        $english = [
            [1000, Mp4FixtureWriter::textSample("")],
            [2500, Mp4FixtureWriter::textSample("The tram to the old town leaves from stop 3.")],
            [500, Mp4FixtureWriter::textSample("")],
            [3000, Mp4FixtureWriter::textSample("Tickets are sold\nat the machine.")],
            [2000, Mp4FixtureWriter::textSample("Bold words here.", $styl)],
            [1000, Mp4FixtureWriter::textSample("")],
            [2250, Mp4FixtureWriter::textSample("\xFE\xFF" . mb_convert_encoding("Café at the corner, 2 € a cup.", "UTF-16BE", "UTF-8"))],
            [1750, Mp4FixtureWriter::textSample("Line one\r\nLine two")],
        ];
        $french  = [
            [90000, Mp4FixtureWriter::textSample("")],
            [180000, Mp4FixtureWriter::textSample("Le tram part à huit heures.")],
            [225000, Mp4FixtureWriter::textSample("La boulangerie ouvre à six heures.")],
        ];

        $tracks = [
            ["id" => 1, "handler" => "vide", "entry" => Mp4FixtureWriter::box("mp4v", str_repeat("\0", 78)), "perChunk" => 4,
             "samples" => array_fill(0, 32, [500, str_repeat("\x80", 64)])],
            ["id" => 2, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "language" => "eng", "name" => "English",
             "perChunk" => 2, "samples" => $english],
            ["id" => 3, "handler" => "text", "entry" => Mp4FixtureWriter::tx3gEntry(Mp4FixtureWriter::ALL_SAMPLES_FORCED),
             "language" => "fra", "elng" => "fr-CA", "enabled" => false, "timescale" => 90000, "version1" => true, "co64" => true,
             "stz2" => true, "perChunk" => 1, "samples" => $french],
            ["id" => 4, "handler" => "clcp", "entry" => Mp4FixtureWriter::box("c608", str_repeat("\0", 6) . pack("n", 1)),
             "language" => "eng", "enabled" => false, "perChunk" => 1, "edits" => [[1000, -1], [2000, 500]], "samples" => [
                 [1500, Mp4FixtureWriter::captionSample([0x9420, 0x9420, 0x94E0, 0x94E0, 0xC2D5, 0xD320, 0x3780, 0x942F, 0x942F],
                                                        [0x9420, 0x9420, 0x94E0, 0x94E0, 0xCE4F, 0x942F, 0x942F])],
                 [500, Mp4FixtureWriter::captionSample([0x942C, 0x942C])],
             ]],
            ["id" => 5, "handler" => "sbtl", "language" => "deu", "enabled" => false, "perChunk" => 1,
             "entry" => Mp4FixtureWriter::tx3gEntry(0, "enct", Mp4FixtureWriter::box("sinf", Mp4FixtureWriter::box("frma", "tx3g"))),
             "samples" => [[2000, str_repeat("\x5A", 24)]]],
        ];

        return Mp4FixtureWriter::file($tracks, false);
    }


    /**
     * One tx3g track and a video track. The moov box comes first, as ffmpeg writes it with -movflags faststart.
     */
    public static function oneTrack(): string
    {
        $tracks = [
            ["id" => 1, "handler" => "vide", "entry" => Mp4FixtureWriter::box("mp4v", str_repeat("\0", 78)), "perChunk" => 2,
             "samples" => array_fill(0, 12, [1000, str_repeat("\x80", 32)])],
            ["id" => 2, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "language" => "eng", "perChunk" => 1,
             "samples" => [
                 [2000, Mp4FixtureWriter::textSample("")],
                 [3000, Mp4FixtureWriter::textSample("The museum opens at ten.")],
                 [4000, Mp4FixtureWriter::textSample("Entry is free on Sundays.")],
             ]],
        ];

        return Mp4FixtureWriter::file($tracks, true);
    }


    /**
     * A c708 track at 29.97 fps with one ccdp box per sample, from the packets of ../mcc/real/captionmaker_608_708.mcc.
     * Its first line has the time code 00:00:01;00, so an empty edit of 1001 ms comes before the first sample.
     */
    public static function c708Track(): string
    {
        $aliases = ["G" => "FA0000", "H" => str_repeat("FA0000", 2), "I" => str_repeat("FA0000", 3), "J" => str_repeat("FA0000", 4),
                    "K" => str_repeat("FA0000", 5), "L" => str_repeat("FA0000", 6), "M" => str_repeat("FA0000", 7), "N" => str_repeat("FA0000", 8),
                    "O" => str_repeat("FA0000", 9), "P" => "FB8080", "Q" => "FC8080", "R" => "FD8080", "S" => "9669", "T" => "6101", "Z" => "00"];
        $samples = [];
        foreach (file(__DIR__ . "/../../mcc/real/captionmaker_608_708.mcc", FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\d\d:\d\d:\d\d:\d\d\t(\S+)$/', $line, $matches) === 1) {
                $anc       = hex2bin(strtr($matches[1], $aliases));
                $samples[] = [1001, Mp4FixtureWriter::box("ccdp", substr($anc, 3, ord($anc[2])))];
            }
        }
        $tracks = [
            ["id" => 1, "handler" => "clcp", "entry" => Mp4FixtureWriter::box("c708", str_repeat("\0", 6) . pack("n", 1)), "language" => "fra",
             "timescale" => 30000, "perChunk" => 30, "edits" => [[1001, -1], [intdiv(count($samples) * 1001, 30), 0]], "samples" => $samples],
        ];

        return Mp4FixtureWriter::file($tracks, true);
    }
}
