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
    private const VERSION_2_TIME_REGEX = '/^(0*\d{1,5}):(\d{2}):(\d{2})\.(\d{1,3}),(0*\d{1,5}):(\d{2}):(\d{2})\.(\d{1,3})$/';
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
        /** @var list<SubtitleCue> $cues */
        $cues       = [];
        $hasEndLine = [];
        $timeLines  = [];
        $afterTime  = null;
        $timeLine   = null;
        foreach ($scriptLines as $lineIndex => $line) {
            if ($afterTime !== null) {
                $time      = $afterTime;
                $afterTime = null;
                if ($line !== "" && !preg_match(self::VERSION_1_TIME_REGEX, $line)) {
                    $cues[]       = new SubtitleCue($time, $time, array_map(Markup::escapeText(...), explode("|", $line)));
                    $hasEndLine[] = false;
                    $timeLines[]  = $timeLine;
                    continue;
                }

                self::endLastCue($cues, $hasEndLine, $time);
            }

            if (preg_match(self::VERSION_1_TIME_REGEX, $line, $matches)) {
                try {
                    $timeLine  = [$lineIndex + 1, $line];
                    $afterTime = self::boundedTime(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3]) + $delay, $delay === 0 ? $line : "$line with DELAY $delay", $lineIndex + 1);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $lineIndex + 1, count($cues), [$line]);
                }
            }
        }

        if ($afterTime !== null) {
            self::endLastCue($cues, $hasEndLine, $afterTime);
        }

        $checked = [];
        foreach ($cues as $index => $cue) {
            if (!$hasEndLine[$index]) {
                $cue->setEnd(isset($cues[$index + 1]) ? $cues[$index + 1]->getStart() : $cue->getStart() + $this->options->lastCueDuration);
                $checked[] = $cue;
                continue;
            }
            try {
                [$start, $end] = $this->orderedTimes($cue->getStart(), $cue->getEnd(), $timeLines[$index][0], $index, [$timeLines[$index][1]]);
                $checked[]     = $cue->setStart($start)->setEnd($end);
            } catch (ParsingException $exception) {
                $this->fail($exception, $timeLines[$index][0], $index, [$timeLines[$index][1]]);
            }
        }

        return $checked;
    }


    /**
     * Ends the last cue at $time, unless an end line already ended it.
     *
     * @param list<SubtitleCue> $cues
     * @param list<bool>        $hasEndLine
     */
    private static function endLastCue(array $cues, array &$hasEndLine, float $time): void
    {
        $last = count($cues) - 1;
        if ($last >= 0 && !$hasEndLine[$last]) {
            $cues[$last]->setEnd($time);
            $hasEndLine[$last] = true;
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
        $skipped    = null;
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if ($line === "") {
                continue;
            }

            $isTime   = preg_match(self::VERSION_2_TIME_REGEX, $line, $matches) === 1;
            $looseCue = !$isTime && $this->options->lenient ? $this->looseVersion2Cue($line, $lineNumber, $cueIndex) : null;
            $badTime  = !$isTime && $looseCue === null && $this->options->lenient && $this->hasOneBadTime($line);
            if ($isTime || $looseCue !== null || $badTime) {
                $this->addCueWithText($parsedCues, $cue);
                $this->warnSkipped($skipped);
                $cue     = $badTime ? null : $looseCue ?? self::version2Cue($matches);
                $skipped = $badTime ? [$lineNumber, $cueIndex, [$line], "The timing line \"$line\" has a time that is not valid."] : null;
                if ($cue !== null) {
                    try {
                        [$start, $end] = $this->orderedTimes($cue->getStart(), $cue->getEnd(), $lineNumber, $cueIndex, [$line]);
                        $cue->setStart($start)->setEnd($end);
                    } catch (ParsingException $exception) {
                        if (!$this->options->lenient) {
                            throw $exception;
                        }
                        [$cue, $skipped] = [null, [$lineNumber, $cueIndex, [$line], $exception->getRawMessage()]];
                    }
                }
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
            if ($skipped !== null) {
                $skipped[2][] = $line;
            } elseif ($cue !== null) {
                self::addTextLines($cue, $line);
            } else {
                $this->readVersion2Header($line, $lineNumber, $subtitle, $header);
            }
        }
        $this->addCueWithText($parsedCues, $cue);
        $this->warnSkipped($skipped);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_filter(
            ["version" => 2, "header" => $header, "style" => $style],
            fn ($value): bool => $value !== null
        ));

        return $subtitle->addCues($parsedCues);
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
     * @param ?array{int, int, list<string>} $skipped the line number, the cue index and the lines of a cue with a bad time line
     */
    private function warnSkipped(?array $skipped): void
    {
        if ($skipped !== null) {
            [$lineNumber, $cueIndex, $block, $message] = $skipped;
            $this->warn($message, $lineNumber, $cueIndex, $block, ParseWarningAction::Skipped);
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
