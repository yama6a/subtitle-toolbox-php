<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\Alignment;
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
        return $this->joinCueTextBlocks($this->splitAtEmptyLines($lines), $this->blockRules());
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
            $this->blockRules(),
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


    private function blockRules(): CueBlockRules
    {
        return new CueBlockRules($this->isTimingLine(...), true);
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
        $timing     = $this->readTimingLine($secondLine, $index, $lineNumber);

        $looseTimes = [];
        [$start, $end] = $this->orderedTimes(
            $this->secondsFromString($timing["start"], $lineNumber, $looseTimes),
            $this->secondsFromString($timing["end"], $lineNumber, $looseTimes),
            $lineNumber,
            $index,
            $rawLines
        );
        $cue       = new SubtitleCue($start, $end, array_map($this->escapeText(...), array_slice($rawLines, 2)));
        $alignment = $this->convertOverrideTags($cue);
        $this->applyTextAfterEndTime($cue, $timing["textAfterEndTime"], $alignment, $index, $lineNumber, $rawLines);

        foreach ($looseTimes as $time => $seconds) {
            $message = "Block #$index has the time \"$time\", which is not in the form hh:mm:ss,mmm. The parser read it as $seconds s.";
            $this->warnRepairedTimingLine($message, $index, $lineNumber, $rawLines);
        }
        if ($timing["hasFullWidthDelimiters"]) {
            $message = "Block #$index has full-width delimiters in its timing line. The parser read them as ASCII.";
            $this->warnRepairedTimingLine($message, $index, $lineNumber, $rawLines);
        }
        if ($timing["arrow"] !== "-->") {
            $message = "Block #$index has the arrow \"{$timing["arrow"]}\" in its timing line. The parser read it as \"-->\".";
            $this->warnRepairedTimingLine($message, $index, $lineNumber, $rawLines);
        }

        return $cue;
    }


    /**
     * Throws when the second line of the block is no timing line.
     *
     * @return array{start: string, arrow: string, end: string, textAfterEndTime: string, hasFullWidthDelimiters: bool}
     */
    private function readTimingLine(?string $secondLine, int $index, ?int $lineNumber): array
    {
        $timingLine = $this->replaceFullWidthDelimiters($secondLine ?? "");
        if (!preg_match("/^(.*?)(?<!-)\s*(" . $this->arrowRegex() . ")\s*(.*)$/", $timingLine, $timingParts)) {
            $quote = $secondLine === null ? "" : " The line is " . self::quote($secondLine) . ".";
            throw new ParsingException("Block #$index has no timing line on its second line.$quote", $lineNumber);
        }

        [, $start, $arrow, $endAndSettings] = $timingParts;
        [$end, $textAfterEndTime] = array_pad(preg_split('/\s+/', $endAndSettings, 2), 2, "");

        return [
            "start"                  => $start,
            "arrow"                  => $arrow,
            "end"                    => $end,
            "textAfterEndTime"       => $textAfterEndTime,
            "hasFullWidthDelimiters" => $timingLine !== $secondLine,
        ];
    }


    /**
     * Stores the text after the end time as SubRip coordinates or as WebVTT cue settings. Lenient mode ignores unknown text.
     */
    private function applyTextAfterEndTime(SubtitleCue $cue, string $textAfterEndTime, ?int $alignment, int $index, ?int $lineNumber, array $rawLines): void
    {
        $coordinates = $this->coordinates($textAfterEndTime);
        if ($coordinates !== null) {
            $cue->setFormatData(self::FORMAT_DATA_KEY, ["coordinates" => $coordinates]);

            return;
        }

        [$settings, $unknown] = $this->cueSettings($textAfterEndTime);
        if ($unknown !== [] && !$this->options->lenient) {
            throw new ParsingException("The text \"" . implode(" ", $unknown) . "\" after the end time is not valid.", $lineNumber);
        }
        if ($settings !== []) {
            $cue->setFormatData(WebVttParser::FORMAT_DATA_KEY, $alignment === null ? $settings : [...$settings, "alignment" => $alignment]);
        }
        if ($unknown !== []) {
            $message = "Block #$index has the unknown text \"" . implode(" ", $unknown) . "\" after the end time. The parser ignored it.";
            $this->warnRepairedTimingLine($message, $index, $lineNumber, $rawLines);
        }
    }


    private function warnRepairedTimingLine(string $message, int $index, ?int $lineNumber, array $rawLines): void
    {
        $this->warn($message, $lineNumber, $index, $rawLines, ParseWarningAction::Repaired);
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
    private function coordinates(string $textAfterEndTime): ?array
    {
        if (!preg_match("/^X1:(\d+)\s+X2:(\d+)\s+Y1:(\d+)\s+Y2:(\d+)$/", $textAfterEndTime, $matches)) {
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
    private function cueSettings(string $textAfterEndTime): array
    {
        $settings = [];
        $unknown  = [];
        foreach (preg_split('/\s+/', $textAfterEndTime, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('/^(' . implode("|", self::CUE_SETTINGS) . '):(\S+)$/', $token, $matches)) {
                $settings[$matches[1]] = $matches[2];
            } else {
                $unknown[] = $token;
            }
        }

        return [$settings, $unknown];
    }


    /**
     * @return ?int the alignment of the first \an or \a tag
     */
    private function convertOverrideTags(SubtitleCue $cue): ?int
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
        $cue->setAlignment(Alignment::from($alignment ?? Alignment::BottomCenter->value));

        return $alignment;
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
