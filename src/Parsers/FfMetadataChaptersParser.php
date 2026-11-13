<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * The section reading follows libavformat/ffmetadec.c.
 *
 * @see https://ffmpeg.org/ffmpeg-formats.html#Metadata-1
 */
final class FfMetadataChaptersParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = ChapterReadOptions::class;
    public const FORMAT_DATA_KEY = Format::FfMetadataChapters->value;

    /** @internal */
    public const METADATA_KEYS = [
        Subtitle::METADATA_TITLE, Subtitle::METADATA_AUTHOR, Subtitle::METADATA_ARTIST,
        Subtitle::METADATA_ALBUM, Subtitle::METADATA_LANGUAGE,
    ];

    // ffmetadec.c reads START and END without a TIMEBASE line in nanoseconds.
    private const DEFAULT_TIME_BASE = "1/1000000000";


    protected function read(string $content): Subtitle
    {
        $content = StringHelpers::normalizeEOLs($content);
        if (!str_starts_with($content, ";FFMETADATA")) {
            throw new ParsingException("The content does not start with the ;FFMETADATA header.", 1);
        }

        $lines    = $this->logicalLines($content);
        $global   = [];
        $streams  = [];
        $chapters = [];
        $tags     = &$global;
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i][1];
            if ($line === "[STREAM]") {
                $streams[] = [];
                $tags      = &$streams[array_key_last($streams)];
            } elseif ($line === "[CHAPTER]") {
                $previousEnd = $chapters === [] ? 0.0 : $chapters[array_key_last($chapters)]["end"] ?? 0.0;
                $chapters[]  = $this->readChapterTimes($lines, $i, $previousEnd);
                $tags        = &$chapters[array_key_last($chapters)]["tags"];
            } else {
                $tags = $this->readTag($line, $tags);
            }
        }
        unset($tags);

        return $this->buildSubtitle($global, $streams, $chapters);
    }


    /**
     * Joins each line that ends in an escaped line break with the next line.
     *
     * @return list<array{int, string}> the 1-based number of the first physical line, and the joined line
     */
    private function logicalLines(string $content): array
    {
        $lines    = [];
        $pending  = null;
        $physical = $this->lines($content);
        foreach ($physical as $index => $line) {
            $pending = $pending === null ? [$index + 1, $line] : [$pending[0], $pending[1] . "\n" . $line];
            if ($index < count($physical) - 1 && (strlen($pending[1]) - strlen(rtrim($pending[1], "\\"))) % 2 === 1) {
                continue;
            }
            if ($pending[1] !== "" && $pending[1][0] !== ";" && $pending[1][0] !== "#") {
                $lines[] = $pending;
            }
            $pending = null;
        }

        return $lines;
    }


    // ffmetadec.c reads TIMEBASE, START and END only in this order, right after the [CHAPTER] line.
    private function readChapterTimes(array $lines, int &$i, float $previousEnd): array
    {
        $timeBase = self::DEFAULT_TIME_BASE;
        $next     = $lines[$i + 1] ?? [0, ""];
        if (preg_match('/^TIMEBASE=(\d+)\/(\d+)/', $next[1], $matches) === 1) {
            $numerator   = StringHelpers::parseInt64($matches[1]);
            $denominator = StringHelpers::parseInt64($matches[2]);
            if ($numerator === null || $denominator === null || $numerator === 0 || $denominator === 0) {
                throw new ParsingException("The chapter time base $matches[1]/$matches[2] is not valid.", $next[0]);
            }
            $timeBase = "$numerator/$denominator";
            $next     = $lines[++$i + 1] ?? [0, ""];
        }
        [$numerator, $denominator] = array_map("intval", explode("/", $timeBase));

        $start = $previousEnd;
        if (preg_match('/^START=(-?\d+)/', $next[1], $matches) === 1) {
            $start = self::boundedTime(self::ticks($matches[1], $matches[0], $next[0]) * $numerator / $denominator, $matches[0], $next[0]);
            $next  = $lines[++$i + 1] ?? [0, ""];
        }

        $end = null;
        if (preg_match('/^END=(-?\d+)/', $next[1], $matches) === 1) {
            $end = self::boundedTime(self::ticks($matches[1], $matches[0], $next[0]) * $numerator / $denominator, $matches[0], $next[0]);
            $i++;
        }

        return ["start" => $start, "end" => $end, "timeBase" => $timeBase, "tags" => []];
    }


    // FFmpeg reads START and END as int64 ticks.
    private static function ticks(string $digits, string $text, int $lineNumber): int
    {
        return StringHelpers::parseInt64($digits)
            ?? throw new ParsingException("The time \"$text\" is not below " . Timecode::MAX_HOURS . " hours.", $lineNumber);
    }


    // ffmetadec.c ignores a line without an unescaped "=". A scan finds it, because a regex fails for a key of about 50,000 bytes.
    private function readTag(string $line, array $tags): array
    {
        $length = strlen($line);
        for ($position = 0; $position < $length; $position++) {
            if ($line[$position] === "\\") {
                $position++;
            } elseif ($line[$position] === "=") {
                $tags[$this->unescape(substr($line, 0, $position))] = $this->unescape(substr($line, $position + 1));
                break;
            }
        }

        return $tags;
    }


    private function unescape(string $text): string
    {
        return preg_replace('/\\\\(.)/s', '$1', $text);
    }


    private function buildSubtitle(array $global, array $streams, array $chapters): Subtitle
    {
        $subtitle = new Subtitle();
        foreach (array_intersect_key($global, array_flip(self::METADATA_KEYS)) as $key => $value) {
            $subtitle->setMetadata($key, $value);
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["tags" => $global, "streams" => $streams]);

        $parsedCues = [];
        foreach ($chapters as $chapter) {
            $cue = new SubtitleCue($chapter["start"], $chapter["start"], Markup::escapeText($chapter["tags"]["title"] ?? ""));
            $cue->setFormatData(self::FORMAT_DATA_KEY, [
                "timeBase" => $chapter["timeBase"],
                "tags"     => array_diff_key($chapter["tags"], ["title" => true]),
            ]);
            $parsedCues[] = $cue;
        }

        return $subtitle->addCues($this->endChapters($parsedCues, array_map(fn (array $chapter): ?float => $chapter["end"], $chapters)));
    }
}
