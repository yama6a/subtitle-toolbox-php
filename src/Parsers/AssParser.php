<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class AssParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Ass->value;

    // A tag ends at the next backslash, except inside parentheses such as \t(\1c&HFF&).
    private const OVERRIDE_TAG_REGEX = '/\\\\[^\\\\(]*(?<args>\((?:[^()]++|(?&args))*\))?[^\\\\]*/';

    private const EMPTY_FORMAT_DATA = [
        "sectionOrder"       => [],
        "scriptInfoComments" => [],
        "scriptInfo"         => [],
        "stylesSection"      => null,
        "styleFormat"        => null,
        "styles"             => [],
        "eventFormat"        => null,
        "commentEvents"      => [],
        "sections"           => [],
    ];

    /** @var list<SubtitleCue> */
    private array $cues = [];

    /** @var list<array{0: string, 1: int}> */
    private array $comments = [];

    /** @var list<array{format: list<string>, line: string, lineNumber: int, value: string, isComment: bool}> */
    private array $events = [];

    /** @var list<string> the repaired times of the event that the parser reads */
    private array $repairedTimes = [];

    // The first line in [Events] without a "Type:" descriptor, which the parser skips.
    private ?int $eventTextLine = null;


    protected function read(string $content): Subtitle
    {
        $this->cues          = [];
        $this->comments      = [];
        $this->events        = [];
        $this->eventTextLine = null;

        $subtitle = new Subtitle();
        $data     = self::EMPTY_FORMAT_DATA;

        $section = null;
        foreach ($this->lines($content) as $lineIndex => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if (preg_match('/^\[([A-Za-z][A-Za-z0-9+ ]*)\]$/', $line, $matches)) {
                $section = $matches[1];
                $this->startSection($data, $section);
                continue;
            }

            if ($section === null) {
                continue;
            }

            match (true) {
                strcasecmp($section, "Script Info") === 0 => $this->readScriptInfoLine($subtitle, $data, $line),
                $this->isStylesSection($section)          => $this->readStyleLine($data, $section, $line),
                strcasecmp($section, "Events") === 0      => $this->readEventLine($data, $line, $lineIndex + 1),
                default                                   => $data["sections"][$section][] = $line,
            };
        }

        // An editor can save an empty project without [Events], so [Script Info] alone gives an empty subtitle.
        $sections = array_map("strtolower", $data["sectionOrder"]);
        if (!in_array("events", $sections, true) && !in_array("script info", $sections, true)) {
            throw new ParsingException("The subtitle has no [Events] section.");
        }
        $data["eventFormat"] ??= $this->isSsa($data) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;

        // The events are read last because a [V4+ Styles] section after [Events] still sets their styles.
        foreach ($this->events as $eventIndex => $event) {
            $this->repairedTimes = [];
            try {
                $this->readEvent($data, $event["format"], $event["line"], $event["lineNumber"], $event["value"], $event["isComment"], $eventIndex);
                foreach ($this->repairedTimes as $message) {
                    $this->warn($message, $event["lineNumber"], $eventIndex, [$event["line"]], ParseWarningAction::Repaired);
                }
            } catch (ParsingException $exception) {
                $this->fail($exception, $event["lineNumber"], $eventIndex, [$event["line"]]);
            }
        }

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $data);

        return CommentAnchors::addParsed($subtitle, $this->cues, $this->comments);
    }


    private function startSection(array &$data, string $section): void
    {
        $data["sectionOrder"][] = $section;
        if ($this->isStylesSection($section)) {
            $data["stylesSection"] = $section;
        }
        if (strcasecmp($section, "Events") === 0) {
            $data["eventFormat"] ??= $this->isSsa($data) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;
        }
    }


    private function readScriptInfoLine(Subtitle $subtitle, array &$data, string $line): void
    {
        if (str_starts_with($line, ";")) {
            $data["scriptInfoComments"][] = $line;

            return;
        }

        if (!str_contains($line, ":")) {
            return;
        }

        [$key, $value] = array_map("trim", explode(":", $line, 2));
        if (strcasecmp($key, "Title") === 0) {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, $value);
        } else {
            $data["scriptInfo"][$key] = $value;
        }
    }


    private function readStyleLine(array &$data, string $section, string $line): void
    {
        [$type, $value] = $this->splitDescriptor($line);
        if (strcasecmp($type, "Format") === 0) {
            $data["styleFormat"] = array_map("trim", explode(",", $value));
        } elseif (strcasecmp($type, "Style") === 0) {
            $data["styleFormat"] ??= strcasecmp($section, "V4 Styles") === 0 ? AssFormatLines::SSA_STYLE_FORMAT : AssFormatLines::ASS_STYLE_FORMAT;
            $data["styles"][]      = $this->combine($data["styleFormat"], $value, true);
        }
    }


    private function readEventLine(array &$data, string $line, int $lineNumber): void
    {
        if (!str_contains($line, ":") && !str_starts_with($line, ";")) {
            $this->eventTextLine ??= $lineNumber;
        }
        [$type, $value] = $this->splitDescriptor($line);
        if (strcasecmp($type, "Format") === 0) {
            $data["eventFormat"] = array_map("trim", explode(",", $value));
            $this->checkFieldNames($data["eventFormat"], $line, $lineNumber);

            return;
        }

        $isComment = strcasecmp($type, "Comment") === 0;
        if (!$isComment && strcasecmp($type, "Dialogue") !== 0) {
            return;
        }

        $this->events[] = ["format" => $data["eventFormat"], "line" => $line, "lineNumber" => $lineNumber, "value" => $value, "isComment" => $isComment];
    }


    /**
     * PHP turns a field name such as "1" into an int array key, which the format data cannot hold. Lenient mode drops the values of such a field.
     */
    private function checkFieldNames(array $format, string $line, int $lineNumber): void
    {
        foreach ($format as $fieldName) {
            if (!is_int(array_key_first([$fieldName => true]))) {
                continue;
            }
            $message = "The field name \"$fieldName\" in the Format line of the [Events] section is a number.";
            if (!$this->options->lenient) {
                throw new ParsingException($message, $lineNumber);
            }
            $this->warn("$message The parser dropped the values of this field.", $lineNumber, null, [$line], ParseWarningAction::Repaired);
        }
    }


    protected function findTextLineWithoutCues(string $content): ?int
    {
        return $this->eventTextLine;
    }


    private function readEvent(array &$data, array $format, string $line, int $lineNumber, string $value, bool $isComment, int $eventIndex): void
    {
        $fields = $this->combine($format, $this->options->lenient ? $this->joinCommaFractions($format, $value) : $value, false);
        if ($fields === null) {
            throw new ParsingException("The line \"$line\" has fewer fields than the Format line of the [Events] section.", $lineNumber);
        }
        $fields = array_filter($fields, "is_string", ARRAY_FILTER_USE_KEY);

        $start = $this->findField($fields, "Start");
        $end   = $this->findField($fields, "End");
        $text  = $this->findField($fields, "Text");
        if ($start === null || $end === null || $text === null) {
            throw new ParsingException("The Format line of the [Events] section needs the fields Start, End and Text.", $lineNumber);
        }
        $fields[$start] = str_replace("\0", ",", $fields[$start]);
        $fields[$end]   = str_replace("\0", ",", $fields[$end]);

        if ($isComment) {
            $data["commentEvents"][] = $fields;
            $this->comments[] = [$fields[$text], count($this->cues)];

            return;
        }

        $startTime = $this->secondsFromString($fields[$start], $lineNumber);
        $wrapStyle = array_change_key_case($data["scriptInfo"])["wrapstyle"] ?? "";

        $styleField = $this->findField($fields, "Style");
        $style      = AssStyles::forEvent($data["styles"], $styleField === null ? "" : $fields[$styleField]);

        [$lines, $alignment] = $this->convertText($fields[$text], $startTime, $wrapStyle === "2", $lineNumber, $data["styles"], $style);
        $alignment         ??= AssStyles::alignment($style, $this->hasLegacyStyles($data));

        $name  = $this->findField($fields, "Name");
        $lines = Markup::addSpeaker($lines, $name === null ? "" : $fields[$name]);

        [$startTime, $endTime] = $this->orderedTimes($startTime, $this->secondsFromString($fields[$end], $lineNumber), $lineNumber, $eventIndex, [$line]);
        $cue = new SubtitleCue($startTime, $endTime, $lines);
        $cue->setAlignment(Alignment::from($alignment ?? Alignment::BottomCenter->value));
        $cue->setFormatData(self::FORMAT_DATA_KEY, [
            "fields"    => array_diff_key($fields, array_flip([$start, $end, $text])),
            "text"      => $fields[$text],
            "lines"     => $cue->getLines(),
            "alignment" => $alignment,
        ]);
        $this->cues[] = $cue;
    }


    /**
     * @return array{string, string} the descriptor before the first colon and the value after it
     */
    private function splitDescriptor(string $line): array
    {
        $parts = explode(":", $line, 2);

        return [trim($parts[0]), ltrim($parts[1] ?? "")];
    }


    /**
     * Masks the comma in a Start or End time such as "0:00:01,50" as NUL, so that combine() keeps the time in one field.
     */
    private function joinCommaFractions(array $format, string $value): string
    {
        $values = explode(",", $value);
        $index  = 0;
        foreach (array_slice($format, 0, -1) as $fieldName) {
            if (in_array(strtolower($fieldName), ["start", "end"], true)
                && preg_match('/^\s*\d+:\d{1,2}:\d{1,2}$/', $values[$index] ?? "")
                && preg_match('/^\d{1,4}\s*$/', $values[$index + 1] ?? "")
                && isset($values[$index + 2])) {
                array_splice($values, $index, 2, [$values[$index] . "\0" . $values[$index + 1]]);
            }
            $index++;
        }

        return implode(",", $values);
    }


    /**
     * Maps the comma-separated values to the field names. The last field keeps any further commas.
     */
    private function combine(array $format, string $value, bool $padMissing): ?array
    {
        $values = explode(",", $value, count($format));
        if (count($values) < count($format)) {
            if (!$padMissing) {
                return null;
            }
            $values = array_pad($values, count($format), "");
        }

        $fields = [];
        foreach ($format as $index => $fieldName) {
            $fields[$fieldName] = $index === count($format) - 1 ? $values[$index] : trim($values[$index]);
        }

        return $fields;
    }


    private function findField(array $fields, string $fieldName): ?string
    {
        foreach (array_keys($fields) as $key) {
            if (strcasecmp($key, $fieldName) === 0) {
                return $key;
            }
        }

        return null;
    }


    private function isStylesSection(string $section): bool
    {
        return strcasecmp($section, "V4+ Styles") === 0 || strcasecmp($section, "V4 Styles") === 0;
    }


    private function hasLegacyStyles(array $data): bool
    {
        return strcasecmp($data["stylesSection"] ?? "", "V4 Styles") === 0;
    }


    private function isSsa(array $data): bool
    {
        $scriptType = array_change_key_case($data["scriptInfo"])["scripttype"] ?? "";

        return strcasecmp($scriptType, "v4.00") === 0 || strcasecmp($data["stylesSection"] ?? "", "V4 Styles") === 0;
    }


    /**
     * In lenient mode, it also reads a time without a fraction, with 4 fraction digits or with "," or ":" before the fraction.
     */
    private function secondsFromString(string $time, int $lineNumber): float
    {
        if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d)\.(\d{1,3})$/', trim($time), $matches)) {
            return self::boundedTime(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]), $time, $lineNumber);
        }

        $seconds = $this->options->lenient ? LooseTime::toSeconds(trim($time), ".,:") : null;
        if ($seconds === null) {
            throw new ParsingException("The time \"$time\" is not valid.", $lineNumber);
        }
        $this->repairedTimes[] = "The time \"$time\" is not in the form h:mm:ss.cc. The parser read it as $seconds s.";

        return self::boundedTime($seconds, $time, $lineNumber);
    }


    /**
     * Converts the Text field to core markup lines and the alignment of the first \an or \a tag.
     * The bold, italic, underline and strikeout flags of the style open their tags at the start and after \r.
     *
     * @param list<array<string, string>> $styles
     * @param ?array<string, string>      $style
     * @return array{list<string>, ?int}
     */
    private function convertText(string $text, float $start, bool $softBreakIsHard, int $lineNumber, array $styles, ?array $style): array
    {
        $alignment    = null;
        $openTags     = [];
        $drawing      = false;
        $karaokeStart = $start;
        $markup       = $this->openStyleTags($style, $openTags);
        foreach (preg_split('/(\{[^{}]*\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (!str_starts_with($part, "{") || !str_ends_with($part, "}")) {
                if (!$drawing) {
                    $markup .= str_replace(
                        ["\\N", "\\n", "\\h"],
                        ["\n", $softBreakIsHard ? "\n" : " ", "\u{00A0}"],
                        Markup::escapeText($part)
                    );
                }
                continue;
            }

            preg_match_all(self::OVERRIDE_TAG_REGEX, substr($part, 1, -1), $tags);
            foreach ($tags[0] as $tag) {
                $tag = trim($tag);
                $tagAlignment = SsaOverrideTags::alignment($tag);
                if ($tagAlignment !== null) {
                    $alignment ??= $tagAlignment;
                } elseif (preg_match('/^\\\\([bius])([01]?)$/', $tag, $matches)) {
                    $markup .= $this->setTag($matches[1], $matches[2] === "1" ? "<$matches[1]>" : null, $openTags);
                } elseif (preg_match('/^\\\\1?c(?:&H([0-9A-Fa-f]{1,8})&?)?$/', $tag, $matches)) {
                    $markup .= $this->setTag("font", isset($matches[1]) ? $this->fontTag($matches[1]) : null, $openTags);
                } elseif (preg_match('/^\\\\(?:k|K|kf|ko)(\d+(?:\.\d+)?)$/', $tag, $matches)) {
                    $markup       .= "<" . Markup::coreTimestamp($karaokeStart) . ">";
                    $karaokeStart  = self::boundedTime($karaokeStart + $matches[1] / 100, $tag, $lineNumber);
                } elseif (preg_match('/^\\\\r(.*)$/', $tag, $matches)) {
                    $markup .= $this->closeAll($openTags);
                    $markup .= $this->openStyleTags(trim($matches[1]) === "" ? $style : AssStyles::find($styles, $matches[1]) ?? $style, $openTags);
                } elseif (preg_match('/^\\\\p(\d+)$/', $tag, $matches)) {
                    $drawing = (int) $matches[1] > 0;
                }
            }
        }
        $markup .= $this->closeAll($openTags);

        return [explode("\n", Markup::removeEmptyTagPairs($markup)), $alignment];
    }


    /**
     * Opens, replaces or closes one tag. Tags opened after it close first and open again, so tags always nest.
     *
     * @param list<array{string, string}> $openTags tag name and opening tag, innermost last
     */
    private function setTag(string $tagName, ?string $openingTag, array &$openTags): string
    {
        $markup = "";
        $index  = array_search($tagName, array_column($openTags, 0), true);
        if ($index !== false) {
            if ($openTags[$index][1] === $openingTag) {
                return "";
            }

            $inner = array_slice($openTags, $index + 1);
            foreach (array_reverse(array_slice($openTags, $index)) as [$openName]) {
                $markup .= "</$openName>";
            }
            $openTags = array_slice($openTags, 0, $index);
            foreach ($inner as $innerTag) {
                $markup     .= $innerTag[1];
                $openTags[] = $innerTag;
            }
        }

        if ($openingTag !== null) {
            $markup     .= $openingTag;
            $openTags[] = [$tagName, $openingTag];
        }

        return $markup;
    }


    private function openStyleTags(?array $style, array &$openTags): string
    {
        $markup = "";
        foreach (AssStyles::tags($style) as $tagName) {
            $markup .= $this->setTag($tagName, "<$tagName>", $openTags);
        }

        return $markup;
    }


    private function closeAll(array &$openTags): string
    {
        $markup = "";
        foreach (array_reverse($openTags) as [$tagName]) {
            $markup .= "</$tagName>";
        }
        $openTags = [];

        return $markup;
    }


    private function fontTag(string $hex): string
    {
        return "<font color=\"#" . strtolower(MarkupColors::bgrToRgb(substr(str_pad($hex, 6, "0", STR_PAD_LEFT), -6))) . "\">";
    }
}
