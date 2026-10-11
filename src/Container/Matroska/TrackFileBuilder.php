<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Parsers\AssFormatLines;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Timecode;

/**
 * Rebuilds the file of a subtitle track from its blocks, in the format that the parser of its codec reads.
 *
 * @internal
 */
final class TrackFileBuilder
{
    // PGS time stamps count ticks of a 90 kHz clock: 9 ticks in 100,000 ns.
    private const PTS_TICKS       = 9;
    private const PTS_NANOSECONDS = 100000;

    // The codec IDs of WebVTT tracks in WebM files start with this prefix.
    private const WEBM_WEBVTT_PREFIX = "D_WEBVTT/";

    // A PGS segment starts with a type byte and a 16-bit size.
    private const PGS_SEGMENT_HEADER = 3;

    private const ASS_FIELDS = [
        "layer" => 1, "marked" => 1, "style" => 2, "name" => 3, "actor" => 3,
        "marginl" => 4, "marginr" => 5, "marginv" => 6, "effect" => 7, "text" => 8,
    ];

    // An ASS block holds ReadOrder and the 8 fields of ASS_FIELDS.
    private const ASS_BLOCK_FIELDS = 9;


    /**
     * @param list<array{startMs: int, endMs: int, data: string}> $cues
     */
    public static function subRipFile(array $cues): string
    {
        $file = "";
        foreach ($cues as $index => $cue) {
            $file .= ($index + 1) . "\n" . self::time($cue["startMs"], ",", true) . " --> " . self::time($cue["endMs"], ",", true) .
                     "\n" . self::cueText($cue["data"]) . "\n\n";
        }

        return $file;
    }


    /**
     * Rebuilds the Dialogue lines from the fields of each block, as mkvextract does.
     * The fields are ReadOrder, Layer, Style, Name, MarginL, MarginR, MarginV, Effect and Text.
     * Each Dialogue line takes its fields in the order of the Format line of the header.
     *
     * @param list<array{startMs: int, endMs: int, data: string}> $cues
     */
    public static function assFile(SubtitleTrack $track, string $codecPrivate, array $cues): string
    {
        $header = rtrim(StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($codecPrivate))) . "\n";
        $format = $track->codecId === MatroskaReader::CODEC_SSA ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;
        if (!preg_match('/^\[Events\][ \t]*$/mi', $header)) {
            $header .= "\n[Events]\nFormat: " . implode(", ", $format) . "\n";
        } elseif (preg_match('/^\[Events\][ \t]*\n(?:(?!\[).*\n)*?Format:(.*)$/mi', $header, $matches)) {
            $format = array_map("trim", explode(",", $matches[1]));
        }

        $events = [];
        foreach ($cues as $cue) {
            $fields = array_pad(explode(",", $cue["data"], self::ASS_BLOCK_FIELDS), self::ASS_BLOCK_FIELDS, "");
            $values = [];
            foreach ($format as $name) {
                $key      = strtolower($name);
                $values[] = match ($key) {
                    "start"  => self::time($cue["startMs"], ".", false),
                    "end"    => self::time($cue["endMs"], ".", false),
                    "marked" => str_starts_with($fields[1], "Marked=") ? $fields[1] : "Marked=" . ($fields[1] === "" ? "0" : $fields[1]),
                    "text"   => str_replace("\n", "\\N", StringHelpers::normalizeEOLs($fields[self::ASS_FIELDS["text"]])),
                    default  => $fields[self::ASS_FIELDS[$key] ?? -1] ?? "",
                };
            }
            $events[] = ["order" => (int) $fields[0], "line" => "Dialogue: " . implode(",", $values)];
        }
        usort($events, fn (array $a, array $b): int => $a["order"] <=> $b["order"]);

        return $header . implode("", array_map(fn (array $event): string => $event["line"] . "\n", $events));
    }


    /**
     * Rebuilds the cue blocks from the block text and the BlockAdditional.
     * The BlockAdditional holds the cue settings, the cue identifier and the comments before the cue.
     * In a WebM track, the block holds the cue identifier, the cue settings and the cue text, each after a line break.
     *
     * @param list<array{startMs: int, endMs: int, data: string, additional: ?string}> $cues
     */
    public static function webVttFile(SubtitleTrack $track, string $codecPrivate, array $cues): string
    {
        $file = $codecPrivate === "" ? "WEBVTT" : rtrim(StringHelpers::normalizeEOLs($codecPrivate));
        $webm = str_starts_with($track->codecId, self::WEBM_WEBVTT_PREFIX);
        foreach ($cues as $cue) {
            [$settings, $identifier, $comments] = array_pad(explode("\n", StringHelpers::normalizeEOLs($cue["additional"] ?? ""), 3), 3, "");
            $data = $cue["data"];
            if ($webm) {
                [$identifier, $settings, $data] = array_pad(explode("\n", StringHelpers::normalizeEOLs($data), 3), 3, "");
            }

            $file .= "\n\n";
            if (trim($comments) !== "") {
                $file .= rtrim($comments) . "\n\n";
            }
            if ($identifier !== "") {
                $file .= "$identifier\n";
            }

            $text  = preg_replace_callback(
                '/<(?:(\d+):)?(\d{2}):(\d{2})\.(\d{3})>/',
                fn (array $m): string => "<" . self::time($cue["startMs"] + ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]) * 1000 + (int) $m[4], ".", true) . ">",
                self::cueText($data),
            );
            $file .= self::time($cue["startMs"], ".", true) . " --> " . self::time($cue["endMs"], ".", true) .
                     ($settings === "" ? "" : " $settings") . "\n$text";
        }

        return $file . "\n";
    }


    /**
     * Puts the PG magic bytes, the PTS from the block timestamp and a DTS of 0 before each segment of each block.
     *
     * @param list<array{start: int, data: string}> $blocks start in TimestampScale ticks
     */
    public static function pgsStream(array $blocks, int $timestampScale): string
    {
        $stream = "";
        foreach ($blocks as $block) {
            $pts    = intdiv($block["start"] * $timestampScale * self::PTS_TICKS, self::PTS_NANOSECONDS) & 0xFFFFFFFF;
            $data   = $block["data"];
            $offset = 0;
            while ($offset < strlen($data)) {
                $size    = strlen($data) - $offset >= self::PGS_SEGMENT_HEADER ? unpack("n", $data, $offset + 1)[1] : 0;
                $stream .= "PG" . pack("NN", $pts, 0) . substr($data, $offset, self::PGS_SEGMENT_HEADER + $size);
                $offset += self::PGS_SEGMENT_HEADER + $size;
            }
        }

        return $stream;
    }


    /**
     * Removes the empty lines that would end the cue in a text file.
     */
    private static function cueText(string $data): string
    {
        return preg_replace('/\n(?:[ \t]*\n)+/', "\n", trim(StringHelpers::normalizeEOLs($data), "\n"));
    }


    private static function time(int $milliseconds, string $separator, bool $twoDigitHours): string
    {
        [$hours, $minutes, $seconds, $fraction] = Timecode::milliseconds($milliseconds / 1000);

        return sprintf($twoDigitHours ? "%02d:%02d:%02d%s%03d" : "%d:%02d:%02d%s%03d", $hours, $minutes, $seconds, $separator, $fraction);
    }
}
