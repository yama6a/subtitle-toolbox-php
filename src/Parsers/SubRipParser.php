<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SubRipParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::SubRip->value;

    // A SubRip file has no regions, so "region" is no setting here.
    private const CUE_SETTINGS = ["vertical", "line", "position", "size", "align"];

    // Chinese and Japanese tools write these full-width delimiters in timing lines.
    private const FULL_WIDTH_DELIMITERS = ["：" => ":", "，" => ",", "．" => ".", "。" => "."];

    private const ATTRIBUTE_TAG_REGEX =
        '#^</?[a-zA-Z][a-zA-Z0-9]*(?:\s+[a-zA-Z_:][-a-zA-Z0-9_:.]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>]+))*\s*/?>$#';

    protected function read(string $content): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $index      = 0;
        foreach ($this->splitIntoBlocks($this->lines($content)) as $lineNumber => $rawLines) {
            foreach ($this->parseBlock($rawLines, $index++, $lineNumber) as $cue) {
                $parsedCues[] = $cue;
            }
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * @see SubtitleParser::joinCueTextBlocks()
     *
     * @param iterable<int, string> $lines
     *
     * @return Generator<int, list<string>>
     *
     * @internal
     */
    public function splitIntoBlocks(iterable $lines): Generator
    {
        return $this->joinCueTextBlocks($this->splitAtEmptyLines($lines), $this->isTimingLine(...), true);
    }


    /**
     * Returns the cues of one block from splitIntoBlocks(). It splits the block before each timing line.
     * In lenient mode, it skips or repairs a broken block and warns.
     *
     * @param list<string> $rawLines
     *
     * @return list<SubtitleCue>
     *
     * @internal
     */
    public function parseBlock(array $rawLines, int $index, int $lineNumber): array
    {
        return $this->parseRepairedBlock(
            $rawLines,
            $lineNumber,
            $index,
            $this->isTimingLine(...),
            true,
            function (array $part, int $partLine) use ($index): SubtitleCue {
                $hasNumber = !$this->isTimingLine($part[0]);
                if ($hasNumber || !$this->options->lenient) {
                    return $this->parseCueBlock($part, $index, $partLine);
                }

                // The added cue number has no line of its own, so the timing line keeps its line number.
                $cue = $this->parseCueBlock(array_merge(["0"], $part), $index, $partLine - 1);
                $this->warn(
                    "Block #$index has no cue number on line $partLine. The parser read the cue without it.",
                    $partLine,
                    $index,
                    $part,
                    ParseWarningAction::Repaired
                );

                return $cue;
            }
        );
    }


    private function isTimingLine(string $line): bool
    {
        $start = $this->options->lenient ? "\d+:\d{1,2}" : "\d+:\d\d:\d\d";

        return preg_match("/^$start\S*?\s*" . $this->arrowRegex() . "/", $this->replaceFullWidthDelimiters($line)) === 1;
    }


    private function replaceFullWidthDelimiters(string $line): string
    {
        return $this->options->lenient ? strtr($line, self::FULL_WIDTH_DELIMITERS) : $line;
    }


    // Lenient mode also reads "->" and "--->", as srt (Ruby) and mkvmerge do.
    private function arrowRegex(): string
    {
        return $this->options->lenient ? "-+>" : "-->";
    }


    /**
     * Parses one cue block of trimmed lines without empty lines, as splitIntoBlocks() splits the file.
     *
     * @internal
     */
    public function parseCueBlock(array $rawLines, int $index, ?int $lineNumber = null): SubtitleCue
    {
        if (!is_numeric($rawLines[0])) {
            throw new ParsingException("Block #$index has no cue number on its first line. The line is " . self::quote($rawLines[0]) . ".", $lineNumber);
        }

        $secondLine = $rawLines[1] ?? null;
        $lineNumber = $lineNumber === null || $secondLine === null ? $lineNumber : $lineNumber + 1;
        $timingLine = $this->replaceFullWidthDelimiters($secondLine ?? "");
        if (!preg_match("/^(.*?)(?<!-)\s*(" . $this->arrowRegex() . ")\s*(.*)$/", $timingLine, $times)) {
            $quote = $secondLine === null ? "" : " The line is " . self::quote($secondLine) . ".";
            throw new ParsingException("Block #$index has no timing line on its second line.$quote", $lineNumber);
        }

        [, $startTime, $arrow, $endPart] = $times;
        [$endTime, $rest] = array_pad(preg_split('/\s+/', $endPart, 2), 2, "");
        $looseTimes = [];
        [$start, $end] = $this->orderedTimes(
            $this->secondsFromString($startTime, $lineNumber, $looseTimes),
            $this->secondsFromString($endTime, $lineNumber, $looseTimes),
            $lineNumber,
            $index,
            $rawLines
        );
        $cue = new SubtitleCue($start, $end, array_map($this->escapeText(...), array_slice($rawLines, 2)));
        $this->convertOverrideTags($cue);
        $coordinates = $this->coordinates($rest);
        if ($coordinates !== null) {
            $cue->setFormatData(self::FORMAT_DATA_KEY, ["coordinates" => $coordinates]);
        } else {
            [$settings, $unknown] = $this->cueSettings($rest);
            if ($unknown !== [] && !$this->options->lenient) {
                throw new ParsingException("The text \"" . implode(" ", $unknown) . "\" after the end time is not valid.", $lineNumber);
            }
            if ($settings !== []) {
                $alignment = $cue->getAlignment();
                $cue->setFormatData(WebVttParser::FORMAT_DATA_KEY, $alignment === null ? $settings : [...$settings, "alignment" => $alignment]);
            }
            if ($unknown !== []) {
                $this->warn(
                    "Block #$index has the unknown text \"" . implode(" ", $unknown) . "\" after the end time. The parser ignored it.",
                    $lineNumber,
                    $index,
                    $rawLines,
                    ParseWarningAction::Repaired
                );
            }
        }
        foreach ($looseTimes as $time => $seconds) {
            $this->warn(
                "Block #$index has the time \"$time\", which is not in the form hh:mm:ss,mmm. The parser read it as $seconds s.",
                $lineNumber,
                $index,
                $rawLines,
                ParseWarningAction::Repaired
            );
        }
        if ($timingLine !== $rawLines[1]) {
            $this->warn(
                "Block #$index has full-width delimiters in its timing line. The parser read them as ASCII.",
                $lineNumber,
                $index,
                $rawLines,
                ParseWarningAction::Repaired
            );
        }
        if ($arrow !== "-->") {
            $this->warn(
                "Block #$index has the arrow \"$arrow\" in its timing line. The parser read it as \"-->\".",
                $lineNumber,
                $index,
                $rawLines,
                ParseWarningAction::Repaired
            );
        }

        return $cue;
    }


    // Players show &amp; as typed, so every & is text.
    private function escapeText(string $line): string
    {
        $parts = preg_split('#(</?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?>)#', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0 || !$this->isTag($part)) {
                $parts[$index] = Markup::escapeText($part);
            }
        }

        return implode("", $parts);
    }


    // A tag is a core tag or a name with only name=value attributes, so "<a sentence in brackets>" is text.
    private function isTag(string $tag): bool
    {
        return in_array(Markup::tagName(trim($tag, "</>")), Markup::CORE_TAGS, true)
            || preg_match(self::ATTRIBUTE_TAG_REGEX, $tag) === 1;
    }


    /**
     * In lenient mode, it also reads the LooseTime variants and a time without hours, and adds them to $looseTimes.
     *
     * @param array<string, float> $looseTimes
     */
    private function secondsFromString(string $timeString, ?int $lineNumber, array &$looseTimes): float
    {
        $timeString = trim($timeString);
        if (preg_match("/^(\d{1,3}):([0-5]\d):([0-5]\d)(?:[,.](\d{1,3})|:(\d{3}))?$/", $timeString, $matches)) {
            return Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], ($matches[4] ?? "") . ($matches[5] ?? ""));
        }

        // A last field of 1 digit without a fraction is a time that the end of the file cut off.
        $seconds = $this->options->lenient && preg_match('/:\d$/', $timeString) !== 1 ? LooseTime::toSeconds($timeString, ",.:", true) : null;
        if ($seconds === null) {
            throw new ParsingException("The time \"$timeString\" is not valid.", $lineNumber);
        }
        $looseTimes[$timeString] = $seconds;

        return $seconds;
    }


    /**
     * @return array{x1: int, x2: int, y1: int, y2: int}|null
     */
    private function coordinates(string $rest): ?array
    {
        if (!preg_match("/^X1:(\d+)\s+X2:(\d+)\s+Y1:(\d+)\s+Y2:(\d+)$/", $rest, $matches)) {
            return null;
        }

        return ["x1" => (int) $matches[1], "x2" => (int) $matches[2], "y1" => (int) $matches[3], "y2" => (int) $matches[4]];
    }


    /**
     * Splits the text after the end time into the WebVTT cue settings and the unknown tokens.
     * Converters such as yt-dlp copy the settings of a WebVTT cue into its SubRip timing line.
     *
     * @return array{array<string, string>, list<string>}
     */
    private function cueSettings(string $rest): array
    {
        $settings = [];
        $unknown  = [];
        foreach (preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('/^(' . implode("|", self::CUE_SETTINGS) . '):(\S+)$/', $token, $matches)) {
                $settings[$matches[1]] = $matches[2];
            } else {
                $unknown[] = $token;
            }
        }

        return [$settings, $unknown];
    }


    private function convertOverrideTags(SubtitleCue $cue): void
    {
        $alignment = null;
        $openTags  = [];
        $text      = preg_replace_callback(
            '/\{(\\\\[^{}]*)\}/',
            function (array $block) use (&$alignment, &$openTags): string {
                preg_match_all('/\\\\[^\\\\]*/', $block[1], $tags);
                $markup  = "";
                $unknown = "";
                foreach ($tags[0] as $tag) {
                    $tagAlignment = SsaOverrideTags::alignment($tag);
                    if ($tagAlignment !== null) {
                        $alignment ??= $tagAlignment;
                    } elseif (preg_match('/^\\\\([bius])([01])$/', $tag, $matches)) {
                        $markup .= $this->toggleTag($matches[1], $matches[2] === "1", $openTags);
                    } else {
                        $unknown .= $tag;
                    }
                }

                return $markup . ($unknown === "" ? "" : "{" . $unknown . "}");
            },
            $cue->getText()
        );

        foreach (array_reverse(array_keys($openTags)) as $tagName) {
            $text .= "</$tagName>";
        }

        $cue->setLines($text);
        $cue->setAlignment($alignment);
    }


    private function toggleTag(string $tagName, bool $open, array &$openTags): string
    {
        if ($open === isset($openTags[$tagName])) {
            return "";
        }

        if ($open) {
            $openTags[$tagName] = true;

            return "<$tagName>";
        }

        unset($openTags[$tagName]);

        return "</$tagName>";
    }
}
