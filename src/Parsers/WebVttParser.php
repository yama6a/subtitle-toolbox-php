<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class WebVttParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::WebVtt->value;

    /** @internal */
    public const CUE_SETTINGS    = ["vertical", "line", "position", "size", "align", "region"];
    /** @internal */
    public const REGION_SETTINGS = ["id", "width", "lines", "regionanchor", "viewportanchor", "scroll"];

    private const SIGNATURE = "WEBVTT";
    private const NOTE      = "NOTE";

    private const TIMESTAMP_PATTERN = "((\d+):)?([0-5]\d):([0-5]\d)\.(\d{3})";

    private const ENTITIES = ["&nbsp;" => "\u{00A0}", "&lrm;" => "\u{200E}", "&rlm;" => "\u{200F}"];


    protected function replaceNul(string $content): string
    {
        return str_replace("\0", "\u{FFFD}", $content);
    }


    protected function read(string $content): Subtitle
    {
        $content      = StringHelpers::normalizeEOLs($content);
        $leadingLines = substr_count(substr($content, 0, strlen($content) - strlen(ltrim($content))), "\n");
        $content      = trim($content);

        // Joined files keep the BOM of each part at the start of a line.
        $lines = array_merge(array_fill(0, $leadingLines, ""), array_map(StringHelpers::removeUtf8Bom(...), $this->lines($content)));
        if (!str_starts_with($content, self::SIGNATURE)) {
            if (!$this->options->lenient) {
                throw new ParsingException("The file does not start with WEBVTT.", $leadingLines + 1);
            }
            $lines = $this->repairSignature($lines);
        }

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $comments   = [];
        $fileData   = [];
        $seenCue    = false;
        $count      = 0;
        foreach ($this->numberedBlocks($lines) as $lineNumber => $rawLines) {
            $index = $count++;
            if ($index === 0) {
                $fileData = $this->parseHeader($rawLines, $lineNumber);
                continue;
            }

            $block = $this->parseBlock($rawLines, $index, $lineNumber, $seenCue, $fileData);
            if ($block instanceof SubtitleCue) {
                $parsedCues[] = $block;
                $seenCue      = true;
            } elseif ($block !== null) {
                $comments[] = [$block, count($parsedCues)];
            }
        }

        // The collapse keeps the keys, so each comment count still points at the cue that followed the comment.
        $parsedCues = iterator_to_array(YouTubeRollingCues::collapse($parsedCues));

        return CommentAnchors::addParsed($subtitle, $parsedCues, $comments)->setFormatData(self::FORMAT_DATA_KEY, $fileData);
    }


    /**
     * Yields the lines from the first line that starts with WEBVTT, if it comes before the first timing line.
     * Otherwise yields a WEBVTT line and an empty line, then the lines from the first cue.
     * Warns once. Throws when the first timing line is not a WebVTT timing line.
     *
     * @param iterable<int, string> $lines keyed by the 0-based line number
     *
     * @return Generator<int, string>
     *
     * @internal
     */
    public function repairSignature(iterable $lines): Generator
    {
        $lines     = (fn (): Generator => yield from $lines)();
        $skipped   = [];
        $firstLine = null;
        $repaired  = false;
        foreach ($lines as $key => $line) {
            $firstLine ??= trim($line) !== "" ? $key + 1 : null;
            if (str_starts_with($line, self::SIGNATURE)) {
                $this->warnMissingSignature($skipped, (int) $firstLine, $key + 1);
                $repaired = true;
                break;
            }
            if (str_contains($line, "-->")) {
                if (!preg_match("/^" . self::TIMESTAMP_PATTERN . "[ \t]*-->[ \t]*" . self::TIMESTAMP_PATTERN . "(?!\d)/", trim($line))) {
                    break;
                }
                $identifier = trim((string) end($skipped)) !== "" ? [array_key_last($skipped) => array_pop($skipped)] : [];
                $cueStart   = array_key_first($identifier) ?? $key;
                $this->warnMissingSignature($skipped, (int) $firstLine, $cueStart + 1);
                yield $cueStart - 2 => self::SIGNATURE;
                yield $cueStart - 1 => "";
                yield from $identifier;
                $repaired = true;
                break;
            }
            $skipped[$key] = $line;
        }
        if (!$repaired) {
            throw new ParsingException("The file does not start with WEBVTT.", $firstLine ?? 1);
        }

        while ($lines->valid()) {
            yield $lines->key() => $lines->current();
            $lines->next();
        }
    }


    private function warnMissingSignature(array $skipped, int $firstLine, int $readFrom): void
    {
        $skipped = array_values(array_filter(array_map("trim", $skipped), fn (string $line): bool => $line !== ""));
        $this->warn(
            "The file does not start with WEBVTT. " .
            ($skipped === [] ? "The parser read the cues without it." : "The parser skipped the lines before line $readFrom."),
            $firstLine,
            0,
            $skipped,
            ParseWarningAction::Repaired
        );
    }


    /**
     * Parses one block after the header.
     * A STYLE or REGION block before the first cue goes into $fileData and gives null.
     * Other blocks that start with NOTE, STYLE or REGION give null too.
     * In lenient mode, it skips a broken block and warns.
     *
     * @param list<string>         $rawLines
     * @param array<string, mixed> $fileData
     *
     * @return SubtitleCue|string|null the cue, the text of a NOTE block, or null
     *
     * @internal
     */
    public function parseBlock(array $rawLines, int $index, int $lineNumber, bool $seenCue, array &$fileData): SubtitleCue|string|null
    {
        $firstLine = trim($rawLines[0]);
        try {
            switch (true) {
                case str_contains($rawLines[0], "-->") || str_contains($rawLines[1] ?? "", "-->"):
                    return $this->parseCueBlock($rawLines, $index, $lineNumber);
                case $this->startsWithKeyword($firstLine, self::NOTE):
                    return $this->parseComment($rawLines);
                case !$seenCue && $firstLine === "STYLE":
                    $fileData["styles"][] = implode(LineEnding::Lf->value, array_slice($rawLines, 1));
                    break;
                case !$seenCue && $firstLine === "REGION":
                    $fileData["regions"][] = $this->parseSettings(implode(" ", array_slice($rawLines, 1)), self::REGION_SETTINGS);
                    break;
                case preg_match("/^(NOTE|STYLE|REGION)/i", $firstLine) === 1:
                    // The spec parser ignores every block that is not a cue, so these blocks do not throw.
                    break;
                default:
                    throw new ParsingException("Block #$index is not a WebVTT cue, comment, style or region. The line is " . self::quote($firstLine) . ".", $lineNumber);
            }
        } catch (ParsingException $exception) {
            $this->fail($exception, $exception->getLineNumber() ?? $lineNumber, $index, $rawLines);
        }

        return null;
    }


    /**
     * Splits at empty lines, and before a timing line that cannot belong to the current cue.
     *
     * @see https://www.w3.org/TR/webvtt1/#collect-a-webvtt-block
     *
     * @internal
     */
    public function splitIntoBlocks(iterable $lines): Generator
    {
        foreach ($this->collectBlocks($lines, false) as [, $block]) {
            yield $block;
        }
    }


    /**
     * Yields the blocks of splitIntoBlocks(), keyed by the 1-based number of their first line.
     * In lenient mode, it splits the cues off a header block that has no empty line after it,
     * joins cue text after an empty line to its cue as joinCueTextBlocks() does, and warns.
     *
     * @param iterable<int, string> $lines keyed by the 0-based line number
     *
     * @return Generator<int, list<string>>
     *
     * @internal
     */
    public function numberedBlocks(iterable $lines): Generator
    {
        $blocks = $this->splitHeader($lines);

        // The spec ends a cue at an empty line, so strict mode keeps the text block apart.
        return $this->options->lenient
            ? $this->joinCueTextBlocks($blocks, fn (string $line): bool => str_contains($line, "-->"), false, $this->isOtherBlock(...))
            : $blocks;
    }


    /**
     * @return Generator<int, list<string>>
     */
    private function splitHeader(iterable $lines): Generator
    {
        $isHeader = true;
        foreach ($this->collectBlocks($lines, false) as [$lineNumber, $block]) {
            $timingOffset = $isHeader && $this->options->lenient ? $this->firstTimingLineOffset($block) : null;
            $isHeader     = false;
            if ($timingOffset === null) {
                yield $lineNumber => $block;
                continue;
            }

            $this->warn(
                "The WEBVTT header has no empty line before the first cue. The parser split the header block at line " .
                ($lineNumber + $timingOffset) . ".",
                $lineNumber + $timingOffset,
                0,
                $block,
                ParseWarningAction::Repaired
            );
            yield $lineNumber => array_slice($block, 0, $timingOffset);

            $rest = array_combine(
                range($lineNumber - 1 + $timingOffset, $lineNumber - 2 + count($block)),
                array_slice($block, $timingOffset)
            );
            foreach ($this->collectBlocks($rest, true) as [$restLineNumber, $restBlock]) {
                yield $restLineNumber => $restBlock;
            }
        }
    }


    /**
     * @param list<string> $block
     */
    private function isOtherBlock(array $block): bool
    {
        return preg_match("/^(NOTE|STYLE|REGION)/i", trim($block[0])) === 1;
    }


    private function firstTimingLineOffset(array $block): ?int
    {
        foreach (array_slice($block, 1, null, true) as $offset => $line) {
            if (str_contains($line, "-->")) {
                return $offset;
            }
        }

        return null;
    }


    /**
     * @return Generator<int, array{int, list<string>}>
     */
    private function collectBlocks(iterable $lines, bool $hasBlocks): Generator
    {
        $current   = [];
        $startLine = 1;
        foreach ($lines as $index => $line) {
            // The spec ends a cue only at an empty line. YouTube auto captions put a line with one space into each cue.
            if ($line === "" || trim($line) === "" && !$this->hasTimingLine($current)) {
                if ($current !== []) {
                    yield [$startLine, $current];
                    $hasBlocks = true;
                }
                $current = [];
                continue;
            }

            $startsCue = count($current) === 0
                         || (count($current) === 1 && !str_contains($current[0], "-->"));
            // The header block is exempt, so that a missing empty line after WEBVTT still throws.
            if (str_contains($line, "-->") && !$startsCue && $hasBlocks) {
                yield [$startLine, $current];
                $current = [];
            }
            if ($current === []) {
                $startLine = $index + 1;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            yield [$startLine, $current];
        }
    }


    private function hasTimingLine(array $block): bool
    {
        foreach ($block as $line) {
            if (str_contains($line, "-->")) {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns the file format data of the header block that starts with WEBVTT on line $lineNumber.
     *
     * @internal
     */
    public function parseHeader(array $rawLines, int $lineNumber = 1): array
    {
        $fileData   = [];
        $headerText = trim(substr($rawLines[0], strlen(self::SIGNATURE)));
        if ($headerText !== "") {
            $fileData["header"] = $headerText;
        }

        $headerLines = array_slice($rawLines, 1);
        foreach ($headerLines as $offset => $line) {
            if (str_contains($line, "-->")) {
                throw new ParsingException("The WEBVTT header has no empty line before the first cue.", $lineNumber + 1 + $offset);
            }
        }
        if ($headerLines !== []) {
            $fileData["headerLines"] = $headerLines;
        }

        return $fileData;
    }


    /**
     * Parses one cue block as splitIntoBlocks() returns it.
     *
     * @internal
     */
    public function parseCueBlock(array $rawLines, int $index, ?int $lineNumber = null): SubtitleCue
    {
        return $this->parseCue($this->cleanLines($rawLines), $index, $lineNumber);
    }


    private function cleanLines(array $rawLines): array
    {
        return array_map(fn (string $line): string => trim(StringHelpers::normalizeSpaces($line)), $rawLines);
    }


    private function parseCue(array $rawLines, int $index, ?int $lineNumber): SubtitleCue
    {
        if (str_contains($rawLines[1] ?? "", "-->")) {
            $identifier = $rawLines[0];
            $rawLines   = array_slice($rawLines, 1);
            $lineNumber = $lineNumber === null ? null : $lineNumber + 1;
        }
        $times = explode("-->", $rawLines[0], 2);
        $end   = trim($times[1]);
        // Settings may follow the end time without white space, as in "00:01.000line:40%". A fourth fraction digit is no setting.
        if (preg_match("/^(" . self::TIMESTAMP_PATTERN . ")((?!\d)[ \t]*(.*))?$/", $end, $matches)) {
            [$endTime, $settingsText] = [$matches[1], $matches[7] ?? ""];
        } elseif ($this->options->lenient) {
            [$endTime, $settingsText] = array_pad(preg_split("/[ \t]+/", $end, 2), 2, "");
        } else {
            throw new ParsingException("The time \"$end\" is not valid.", $lineNumber);
        }

        $lines      = str_replace(array_keys(self::ENTITIES), array_values(self::ENTITIES), array_slice($rawLines, 1));
        $looseTimes = [];
        foreach ($lines as $offset => $line) {
            self::checkWordTimestamps([$line], $lineNumber === null ? null : $lineNumber + 1 + $offset);
        }
        [$start, $end] = $this->orderedTimes(
            $this->secondsFromString($times[0], $lineNumber, $looseTimes),
            $this->secondsFromString($endTime, $lineNumber, $looseTimes),
            $lineNumber,
            $index,
            $rawLines
        );
        $cue = new SubtitleCue($start, $end, $lines);
        $cue->setIdentifier($identifier ?? null);
        foreach ($looseTimes as $time => $seconds) {
            $this->warn(
                "Block #$index has the time \"$time\", which is not in the form hh:mm:ss.mmm. The parser read it as $seconds s.",
                $lineNumber,
                $index,
                $rawLines,
                ParseWarningAction::Repaired
            );
        }

        $settings  = $this->parseSettings($settingsText, self::CUE_SETTINGS);
        $alignment = $this->settingsToAlignment($settings);
        $cue->setFormatData(self::FORMAT_DATA_KEY, $alignment === null ? $settings : [...$settings, "alignment" => $alignment]);
        $cue->setAlignment($alignment);

        return $cue;
    }


    /**
     * In lenient mode, it also reads the LooseTime variants with "." or "," before the fraction, and adds them to $looseTimes.
     *
     * @param array<string, float> $looseTimes
     */
    private function secondsFromString(string $timeString, ?int $lineNumber, array &$looseTimes): float
    {
        $timeString = trim($timeString);
        if (preg_match("/^" . self::TIMESTAMP_PATTERN . "$/", $timeString, $matches)) {
            return self::boundedTime(Timecode::toSeconds((int) $matches[2], (int) $matches[3], (int) $matches[4], $matches[5]), $timeString, $lineNumber);
        }

        // A last field of 1 digit without a fraction is a time that the end of the file cut off.
        $seconds = $this->options->lenient && preg_match('/:\d$/', $timeString) !== 1 ? LooseTime::toSeconds($timeString, ".,", true) : null;
        if ($seconds === null) {
            throw new ParsingException("The time \"$timeString\" is not valid.", $lineNumber);
        }
        $looseTimes[$timeString] = $seconds;

        return $seconds;
    }


    /**
     * Keeps the exact value of each known "name:value" token. The last token with the same name wins.
     *
     * @see https://www.w3.org/TR/webvtt1/#parse-the-webvtt-cue-settings
     *
     * @internal
     */
    public function parseSettings(string $input, array $knownNames): array
    {
        $settings = [];
        foreach (preg_split("/[ \t]+/", trim($input), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $colon = strpos($token, ":");
            if ($colon === false || $colon === 0 || $colon === strlen($token) - 1) {
                continue;
            }

            $name = substr($token, 0, $colon);
            if (in_array($name, $knownNames, true)) {
                $settings[$name] = substr($token, $colon + 1);
            }
        }

        return $settings;
    }


    /**
     * Maps only the values with an unambiguous screen position. Start and end depend on the text direction.
     */
    private function settingsToAlignment(array $settings): ?int
    {
        if ($settings === [] || isset($settings["vertical"])) {
            return null;
        }

        $row = match ($settings["line"] ?? null) {
            null, "-1", "100%,end"  => 0,
            "50%,center"            => 3,
            "0", "0%", "0,start"    => 6,
            default                 => null,
        };
        $column = match ($settings["align"] ?? null) {
            "left"           => 1,
            null, "center"   => 2,
            "right"          => 3,
            default          => null,
        };
        if ($row === null || $column === null || !isset($settings["line"]) && !isset($settings["align"])) {
            return null;
        }

        return $row + $column;
    }


    private function parseComment(array $rawLines): string
    {
        $rawLines[0] = substr(trim($rawLines[0]), strlen(self::NOTE));
        $lines       = array_filter(array_map("trim", $rawLines), fn (string $line): bool => $line !== "");

        return implode(LineEnding::Lf->value, $lines);
    }


    /**
     * @see https://www.w3.org/TR/webvtt1/#webvtt-comment-block
     */
    private function startsWithKeyword(string $line, string $keyword): bool
    {
        return $line === $keyword || preg_match("/^{$keyword}[ \t]/", $line) === 1;
    }
}
