<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class LyricsParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Lyrics->value;

    /** @internal */
    public const METADATA_TAGS = [
        "ti" => Subtitle::METADATA_TITLE,
        "ar" => Subtitle::METADATA_ARTIST,
        "al" => Subtitle::METADATA_ALBUM,
        "au" => Subtitle::METADATA_AUTHOR,
    ];

    private const TIMESTAMP_PATTERN     = "(?:(\d{1,2}):(?=[0-5]?\d:))?(\d{1,3}):([0-5]\d)(?:\.(\d{1,3}))?";
    private const TIME_TAG_PATTERN      = "\[\s*" . self::TIMESTAMP_PATTERN . "\s*\]";
    private const TIMESTAMP_LINE_REGEX  = "/^((?:" . self::TIME_TAG_PATTERN . ")+)(.*)$/";
    private const BROKEN_TIME_TAG_REGEX = "/^\[\s*\d/";
    private const ID_TAG_REGEX          = "/^\[([A-Za-z][A-Za-z0-9_]*|#):(.*)\]$/";
    private const OFFSET_REGEX          = "/^[+-]?\d+$/";


    protected function read(string $content): Subtitle
    {
        $content = StringHelpers::normalizeEOLs($content);
        if ($this->options->lenient) {
            $this->warnBrokenTimeTags($this->lines($content));
        }
        $content = StringHelpers::normalizeSpaces($content);
        $content = StringHelpers::removeEmptyLines($content);
        $content = StringHelpers::trimEachLine($content);

        $lines      = $this->lines($content);
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $offset     = $this->findOffset($lines);
        $idTags     = [];
        $comments   = [];
        $timeline   = [];

        for ($index = 0; $index < count($lines); $index++) {
            $currentLine = $lines[$index];
            if (preg_match(self::TIMESTAMP_LINE_REGEX, $currentLine, $matches)) {
                $text = substr($currentLine, strlen($matches[1]));
                if (trim($text) === "" && $this->isPlainTextLine($lines[$index + 1] ?? null)) {
                    $text = $lines[++$index];
                }
                try {
                    $this->readTimedLine($matches[1], $text, $offset, $parsedCues, $timeline);
                } catch (ParsingException $exception) {
                    $this->fail($exception, null, $index, [$currentLine]);
                }
                continue;
            }

            $this->addIdTag($subtitle, $idTags, $comments, $currentLine, count($parsedCues));
        }

        if ($idTags !== []) {
            $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["idTags" => $idTags]);
        }

        $this->assignEndTimes($timeline);

        return CommentAnchors::addParsed($subtitle, $parsedCues, $comments);
    }


    /**
     * Adds a cue for each time tag of a line with text, and an entry for each time tag to $timeline.
     *
     * @param list<SubtitleCue>                           $parsedCues
     * @param list<array{time: float, cue: ?SubtitleCue}> $timeline
     */
    private function readTimedLine(string $timeTags, string $text, float $offset, array &$parsedCues, array &$timeline): void
    {
        $text = StringHelpers::cleanString($text);
        $text = $this->convertWordTimestamps($text, $offset);

        preg_match_all("/" . self::TIME_TAG_PATTERN . "/", $timeTags, $timestamps, PREG_SET_ORDER);
        $starts = array_map(fn (array $timestamp): float => $this->toSeconds($timestamp, $offset), $timestamps);
        foreach ($starts as $start) {
            $cue = $text === "" ? null : new SubtitleCue($start, $start, $text);
            if ($cue !== null) {
                $parsedCues[] = $cue;
            }
            $timeline[] = ["time" => $start, "cue" => $cue];
        }
    }


    protected function findTextLineWithoutCues(string $content): ?int
    {
        foreach ($this->lines($content) as $index => $line) {
            $line = trim(StringHelpers::normalizeSpaces($line));
            if ($line !== "" && $this->isPlainTextLine($line)) {
                return $index + 1;
            }
        }

        return null;
    }


    private function isPlainTextLine(?string $line): bool
    {
        return $line !== null
            && !preg_match(self::TIMESTAMP_LINE_REGEX, $line)
            && !preg_match(self::ID_TAG_REGEX, $line)
            && !preg_match(self::BROKEN_TIME_TAG_REGEX, $line);
    }


    /**
     * @param list<string> $lines
     */
    private function warnBrokenTimeTags(array $lines): void
    {
        $blockIndex = 0;
        foreach ($lines as $lineIndex => $line) {
            $line = trim(StringHelpers::normalizeSpaces($line));
            if ($line === "") {
                continue;
            }

            if (preg_match(self::BROKEN_TIME_TAG_REGEX, $line) && !preg_match(self::TIMESTAMP_LINE_REGEX, $line)) {
                $lineNumber = $lineIndex + 1;
                $this->warn("The line \"$line\" has a time tag that is not valid.", $lineNumber, $blockIndex, [$line], ParseWarningAction::Skipped);
            }
            $blockIndex++;
        }
    }


    /**
     * @param list<string> $lines
     */
    private function findOffset(array $lines): float
    {
        foreach ($lines as $line) {
            if (preg_match(self::ID_TAG_REGEX, $line, $matches)
                && strtolower($matches[1]) === "offset"
                && preg_match(self::OFFSET_REGEX, trim($matches[2]))
            ) {
                return (int) trim($matches[2]) / 1000;
            }
        }

        return 0;
    }


    /**
     * @param array<string, string>          $idTags
     * @param list<array{0: string, 1: int}> $comments
     */
    private function addIdTag(Subtitle $subtitle, array &$idTags, array &$comments, string $line, int $cueCount): void
    {
        if (!preg_match(self::ID_TAG_REGEX, $line, $matches)) {
            return;
        }

        $tag   = strtolower($matches[1]);
        $value = trim($matches[2]);

        if ($tag === "#") {
            $comments[] = [$value, $cueCount];
        } elseif (array_key_exists($tag, self::METADATA_TAGS)) {
            $subtitle->setMetadata(self::METADATA_TAGS[$tag], $value);
        } elseif ($tag !== "offset" || !preg_match(self::OFFSET_REGEX, $value)) {
            $idTags[$tag] = $value;
        }
    }


    /**
     * @param list<array{time: float, cue: ?SubtitleCue}> $timeline
     */
    private function assignEndTimes(array $timeline): void
    {
        usort($timeline, fn (array $entry1, array $entry2): int => $entry1["time"] <=> $entry2["time"]);

        foreach ($timeline as $index => $entry) {
            if ($entry["cue"] === null) {
                continue;
            }

            $next = $timeline[$index + 1] ?? null;
            if ($next === null) {
                $entry["cue"]->setEnd($entry["time"] + $this->options->lastCueDuration);
                continue;
            }

            $entry["cue"]->setEnd($next["time"]);
            if ($next["cue"] === null) {
                $entry["cue"]->setFormatData(self::FORMAT_DATA_KEY, ["endLine" => true]);
            }
        }
    }


    private function convertWordTimestamps(string $text, float $offset): string
    {
        return preg_replace_callback(
            "/<" . self::TIMESTAMP_PATTERN . ">|[^<]+|</",
            fn (array $matches): string => isset($matches[2])
                ? "<" . Markup::coreTimestamp($this->toSeconds($matches, $offset)) . ">"
                : Markup::escapeText($matches[0]),
            $text
        );
    }


    /**
     * @param array<int, string> $matches the time tag, then optional hours, minutes, seconds and an optional fraction in groups 1 to 4
     */
    private function toSeconds(array $matches, float $offset): float
    {
        $fraction = $matches[4] ?? "";
        $whole    = ((int) $matches[1]) * 3600 + $matches[2] * 60 + $matches[3];
        $seconds  = match (strlen($fraction)) {
            0       => $whole,
            1       => Timecode::roundToMilliseconds($whole + $fraction / 10),
            2       => $whole + round($fraction / 100, 2),
            default => $whole + Timecode::roundToMilliseconds($fraction / 1000),
        };

        return self::boundedTime($offset === 0.0 ? (float) $seconds : max(0.0, Timecode::roundToMilliseconds($seconds - $offset)), $matches[0], null);
    }
}
