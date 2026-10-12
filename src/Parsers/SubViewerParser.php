<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SubViewerParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::SubViewer->value;

    /** @internal */
    public const START_SCRIPT = "******** START SCRIPT ********";

    /** @internal */
    public const METADATA_TAGS = [
        "TITLE"  => Subtitle::METADATA_TITLE,
        "AUTHOR" => Subtitle::METADATA_AUTHOR,
    ];

    // Five hour digits keep a time below Timecode::MAX_HOURS.
    private const VERSION_1_TIME_REGEX = '/^\[(0*\d{1,5}):(\d{2}):(\d{2})\]$/';
    private const VERSION_2_TIME_REGEX = '/^(0*\d{1,5}):([0-5]\d):([0-5]\d)\.(\d{1,3}),(0*\d{1,5}):([0-5]\d):([0-5]\d)\.(\d{1,3})$/';
    private const TAG_REGEX            = '/^\[([^\]]+)\](.*)$/';
    private const STYLE_TAGS           = ["[COLF]", "[SIZE]", "[FONT]", "[STYLE]"];
    private const VERSION_2_BLOCK_TAGS = ["INFORMATION", "END INFORMATION", "SUBTITLE"];


    protected function read(string $content): Subtitle
    {
        $lines = array_map("trim", $this->lines($content));

        $startScript = array_search(self::START_SCRIPT, $lines, true);

        return $startScript === false
            ? $this->parseVersion2($lines)
            : $this->parseVersion1(array_slice($lines, 0, $startScript), array_slice($lines, $startScript + 1, null, true));
    }


    /**
     * @param list<string> $headerLines
     * @param array<int, string> $scriptLines
     */
    private function parseVersion1(array $headerLines, array $scriptLines): Subtitle
    {
        $subtitle = new Subtitle();
        $header   = [];
        $delay    = $this->readVersion1Header($headerLines, $subtitle, $header);
        $cues     = $this->readVersion1Script($scriptLines, $delay);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["version" => 1, "header" => $header]);

        return $subtitle->addCues($cues);
    }


    /**
     * Reads the header tags into $subtitle and $header, and returns the delay in seconds.
     *
     * @param list<string>          $headerLines
     * @param array<string, string> $header
     */
    private function readVersion1Header(array $headerLines, Subtitle $subtitle, array &$header): int
    {
        $delay = 0;
        for ($index = 0; $index < count($headerLines); $index++) {
            $line = $headerLines[$index];
            if ($line === "") {
                continue;
            }

            try {
                $matches = $this->headerTag($line, $index + 1, "is not a SubViewer 1 header tag");
            } catch (ParsingException $exception) {
                $this->fail($exception, $index + 1, 0, [$line]);
                continue;
            }

            $tag   = strtoupper(trim($matches[1]));
            $value = trim($matches[2]);
            $next  = $headerLines[$index + 1] ?? "";
            if ($value === "" && $next !== "" && !str_starts_with($next, "[")) {
                $value = $next;
                $index++;
            }

            if ($tag === "DELAY") {
                // FFmpeg reads the delay as whole seconds and adds it to every time.
                $delay = (int) $value;
                $value = "0";
            }

            $this->addHeaderTag($subtitle, $header, $tag, $value);
        }

        return $delay;
    }


    /**
     * @param array<int, string> $scriptLines
     * @return list<SubtitleCue>
     */
    private function readVersion1Script(array $scriptLines, int $delay): array
    {
        /** @var list<array{cue: SubtitleCue, endedByTimeLine: bool, timeLineNumber: int, timeLine: string}> $entries */
        $entries        = [];
        $pendingTime    = null;
        $timeLineNumber = 0;
        $timeLine       = "";
        foreach ($scriptLines as $lineIndex => $line) {
            if ($pendingTime !== null) {
                $time        = $pendingTime;
                $pendingTime = null;
                if ($line !== "" && !preg_match(self::VERSION_1_TIME_REGEX, $line)) {
                    $entries[] = [
                        "cue"             => new SubtitleCue($time, $time, array_map(Markup::escapeText(...), explode("|", $line))),
                        "endedByTimeLine" => false,
                        "timeLineNumber"  => $timeLineNumber,
                        "timeLine"        => $timeLine,
                    ];
                    continue;
                }

                self::endLastCue($entries, $time);
            }

            if (preg_match(self::VERSION_1_TIME_REGEX, $line, $matches)) {
                $timeLineNumber = $lineIndex + 1;
                $timeLine       = $line;
                try {
                    if ((int) $matches[2] > 59 || (int) $matches[3] > 59) {
                        throw new ParsingException("The time \"$line\" is not valid.", $timeLineNumber);
                    }
                    $pendingTime = self::boundedTime(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3]) + $delay, $delay === 0 ? $line : "$line with DELAY $delay", $timeLineNumber);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $timeLineNumber, count($entries), [$line]);
                }
            }
        }

        if ($pendingTime !== null) {
            self::endLastCue($entries, $pendingTime);
        }

        $checked = [];
        foreach ($entries as $index => $entry) {
            $cue = $entry["cue"];
            if (!$entry["endedByTimeLine"]) {
                $cue->setEnd(isset($entries[$index + 1]) ? $entries[$index + 1]["cue"]->getStart() : $cue->getStart() + $this->options->lastCueDuration);
                $checked[] = $cue;
                continue;
            }
            try {
                [$start, $end] = $this->orderedTimes($cue->getStart(), $cue->getEnd(), $entry["timeLineNumber"], $index, [$entry["timeLine"]]);
                $checked[]     = $cue->setStart($start)->setEnd($end);
            } catch (ParsingException $exception) {
                $this->fail($exception, $entry["timeLineNumber"], $index, [$entry["timeLine"]]);
            }
        }

        return $checked;
    }


    /**
     * Ends the cue of the last entry at $time, unless a time line already ended it.
     *
     * @param list<array{cue: SubtitleCue, endedByTimeLine: bool, timeLineNumber: int, timeLine: string}> $entries
     */
    private static function endLastCue(array &$entries, float $time): void
    {
        $last = array_key_last($entries);
        if ($last !== null && !$entries[$last]["endedByTimeLine"]) {
            $entries[$last]["cue"]->setEnd($time);
            $entries[$last]["endedByTimeLine"] = true;
        }
    }


    /**
     * @param list<string> $lines
     */
    private function parseVersion2(array $lines): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $header     = [];
        $style      = null;
        $cue        = null;
        $cueIndex   = 0;
        $skippedCue = null;
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if ($line === "") {
                continue;
            }

            $timing = $this->readVersion2TimingLine($line, $lineNumber, $cueIndex);
            if ($timing !== null) {
                $this->addCueWithText($parsedCues, $cue);
                $this->warnSkipped($skippedCue);
                ["cue" => $cue, "skipped" => $skippedCue] = $timing;
                $cueIndex++;
                continue;
            }

            // Like FFmpeg, the parser reads a style line anywhere, but keeps only the style line before the first cue.
            if ($this->isStyleLine($line)) {
                if ($cue === null) {
                    $style ??= $line;
                }
                continue;
            }
            if ($skippedCue !== null) {
                $skippedCue["lines"][] = $line;
            } elseif ($cue !== null) {
                self::addTextLines($cue, $line);
            } else {
                $this->readVersion2Header($line, $lineNumber, $subtitle, $header);
            }
        }
        $this->addCueWithText($parsedCues, $cue);
        $this->warnSkipped($skippedCue);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_filter(
            ["version" => 2, "header" => $header, "style" => $style],
            fn ($value): bool => $value !== null
        ));

        return $subtitle->addCues($parsedCues);
    }


    /**
     * Returns null for a line that is no timing line. In lenient mode, a timing line with a bad time gives a skipped cue instead of a cue.
     *
     * @return ?array{cue: ?SubtitleCue, skipped: ?array{lineNumber: int, cueIndex: int, lines: list<string>, message: string}}
     */
    private function readVersion2TimingLine(string $line, int $lineNumber, int $cueIndex): ?array
    {
        if (preg_match(self::VERSION_2_TIME_REGEX, $line, $matches)) {
            $cue = self::version2Cue($matches);
        } elseif (!$this->options->lenient) {
            return null;
        } else {
            $cue = $this->looseVersion2Cue($line, $lineNumber, $cueIndex);
            if ($cue === null) {
                return $this->hasOneBadTime($line) ? self::skippedCue($line, $lineNumber, $cueIndex, "The timing line \"$line\" has a time that is not valid.") : null;
            }
        }

        try {
            [$start, $end] = $this->orderedTimes($cue->getStart(), $cue->getEnd(), $lineNumber, $cueIndex, [$line]);
        } catch (ParsingException $exception) {
            if (!$this->options->lenient) {
                throw $exception;
            }

            return self::skippedCue($line, $lineNumber, $cueIndex, $exception->getRawMessage());
        }

        return ["cue" => $cue->setStart($start)->setEnd($end), "skipped" => null];
    }


    /**
     * @return array{cue: null, skipped: array{lineNumber: int, cueIndex: int, lines: list<string>, message: string}}
     */
    private static function skippedCue(string $line, int $lineNumber, int $cueIndex, string $message): array
    {
        return ["cue" => null, "skipped" => ["lineNumber" => $lineNumber, "cueIndex" => $cueIndex, "lines" => [$line], "message" => $message]];
    }


    /**
     * @param array<int, string> $matches the matches of VERSION_2_TIME_REGEX
     */
    private static function version2Cue(array $matches): SubtitleCue
    {
        return new SubtitleCue(
            Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]),
            Timecode::toSeconds((int) $matches[5], (int) $matches[6], (int) $matches[7], $matches[8]),
            []
        );
    }


    /**
     * Reads a timing line without a fraction, with 4 fraction digits or with 1-digit fields, and warns. Returns null for another line.
     */
    private function looseVersion2Cue(string $line, int $lineNumber, int $cueIndex): ?SubtitleCue
    {
        $times = explode(",", $line);
        if (count($times) !== 2) {
            return null;
        }

        $start = LooseTime::toSeconds($times[0], ".");
        $end   = LooseTime::toSeconds($times[1], ".");
        if ($start === null || $end === null) {
            return null;
        }

        $this->warn(
            "The timing line \"$line\" is not in the form hh:mm:ss.ff,hh:mm:ss.ff. The parser read it as $start s to $end s.",
            $lineNumber,
            $cueIndex,
            [$line],
            ParseWarningAction::Repaired
        );

        return new SubtitleCue($start, $end, []);
    }


    private static function addTextLines(SubtitleCue $cue, string $line): void
    {
        foreach (explode("[br]", $line) as $textLine) {
            if (trim($textLine) !== "") {
                $cue->addLine(Markup::escapeText(trim($textLine)));
            }
        }
    }


    /**
     * @param array<string, string> $header
     */
    private function readVersion2Header(string $line, int $lineNumber, Subtitle $subtitle, array &$header): void
    {
        try {
            $matches = $this->headerTag($line, $lineNumber, "is neither a header tag nor a timing line");
        } catch (ParsingException $exception) {
            $this->fail($exception, $lineNumber, 0, [$line]);

            return;
        }

        $tag = strtoupper(trim($matches[1]));
        if (!in_array($tag, self::VERSION_2_BLOCK_TAGS, true)) {
            $this->addHeaderTag($subtitle, $header, $tag, trim($matches[2]));
        }
    }


    /**
     * @param array<string, string> $header
     */
    private function addHeaderTag(Subtitle $subtitle, array &$header, string $tag, string $value): void
    {
        if (array_key_exists($tag, self::METADATA_TAGS)) {
            $subtitle->setMetadata(self::METADATA_TAGS[$tag], $value === "" ? null : $value);
        } else {
            $header[$tag] = $value;
        }
    }


    private function isStyleLine(string $line): bool
    {
        if (!str_starts_with($line, "[")) {
            return false;
        }

        foreach (self::STYLE_TAGS as $tag) {
            if (str_contains($line, $tag)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns the matches of TAG_REGEX, and throws with $problem, for example "is not a header tag", in the message.
     */
    private function headerTag(string $line, int $lineNumber, string $problem): array
    {
        if (!preg_match(self::TAG_REGEX, $line, $matches)) {
            throw new ParsingException("The line \"$line\" $problem.", $lineNumber);
        }

        return $matches;
    }


    private function hasOneBadTime(string $line): bool
    {
        $time  = '\d+:\d{1,2}:\d{1,2}(?:\.\d{1,4})?';
        $parts = explode(",", $line);

        return count($parts) === 2
            && !preg_match(self::VERSION_2_TIME_REGEX, $line)
            && (preg_match("/^$time$/", $parts[0]) || preg_match("/^$time$/", $parts[1]));
    }


    /**
     * @param ?array{lineNumber: int, cueIndex: int, lines: list<string>, message: string} $skippedCue a cue with a bad timing line
     */
    private function warnSkipped(?array $skippedCue): void
    {
        if ($skippedCue !== null) {
            $this->warn($skippedCue["message"], $skippedCue["lineNumber"], $skippedCue["cueIndex"], $skippedCue["lines"], ParseWarningAction::Skipped);
        }
    }


    /**
     * @param list<SubtitleCue> $cues
     */
    private function addCueWithText(array &$cues, ?SubtitleCue $cue): void
    {
        // FFmpeg makes no event for a timing line without text.
        if ($cue !== null && $cue->getLines() !== []) {
            $cues[] = $cue;
        }
    }
}
