<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class AssParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Ass->value;

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

    // The first line in [Events] without a "Type:" descriptor, which the parser skips.
    private ?int $eventTextLine = null;


    protected function read(string $content): Subtitle
    {
        $this->cues          = [];
        $this->comments      = [];
        $this->events        = [];
        $this->eventTextLine = null;

        $subtitle   = new Subtitle();
        $formatData = self::EMPTY_FORMAT_DATA;

        $section = null;
        foreach ($this->lines($content) as $lineIndex => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if (preg_match('/^\[([A-Za-z][A-Za-z0-9+ ]*)\]$/', $line, $matches)) {
                $section = $matches[1];
                $this->startSection($formatData, $section);
                continue;
            }

            if ($section === null) {
                continue;
            }

            match (true) {
                strcasecmp($section, "Script Info") === 0 => $this->readScriptInfoLine($subtitle, $formatData, $line),
                $this->isStylesSection($section)          => $this->readStyleLine($formatData, $section, $line),
                strcasecmp($section, "Events") === 0      => $this->readEventLine($formatData, $line, $lineIndex + 1),
                default                                   => $formatData["sections"][$section][] = $line,
            };
        }

        // An editor can save an empty project without [Events], so [Script Info] alone gives an empty subtitle.
        $sections = array_map("strtolower", $formatData["sectionOrder"]);
        if (!in_array("events", $sections, true) && !in_array("script info", $sections, true)) {
            throw new ParsingException("The subtitle has no [Events] section.");
        }
        $formatData["eventFormat"] ??= $this->isSsa($formatData) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;

        // The events are read last because a [V4+ Styles] section after [Events] still sets their styles.
        foreach ($this->events as $eventIndex => $event) {
            try {
                foreach ($this->readEvent($formatData, $event, $eventIndex) as [$time, $seconds]) {
                    $message = "The time \"$time\" is not in the form h:mm:ss.cc. The parser read it as $seconds s.";
                    $this->warn($message, $event["lineNumber"], $eventIndex, [$event["line"]], ParseWarningAction::Repaired);
                }
            } catch (ParsingException $exception) {
                $this->fail($exception, $event["lineNumber"], $eventIndex, [$event["line"]]);
            }
        }

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $formatData);

        return CommentAnchors::addParsed($subtitle, $this->cues, $this->comments);
    }


    private function startSection(array &$formatData, string $section): void
    {
        $formatData["sectionOrder"][] = $section;
        if ($this->isStylesSection($section)) {
            $formatData["stylesSection"] = $section;
        }
        if (strcasecmp($section, "Events") === 0) {
            $formatData["eventFormat"] ??= $this->isSsa($formatData) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;
        }
    }


    private function readScriptInfoLine(Subtitle $subtitle, array &$formatData, string $line): void
    {
        if (str_starts_with($line, ";")) {
            $formatData["scriptInfoComments"][] = $line;

            return;
        }

        if (!str_contains($line, ":")) {
            return;
        }

        [$key, $value] = array_map("trim", explode(":", $line, 2));
        if (strcasecmp($key, "Title") === 0) {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, $value);
        } else {
            $formatData["scriptInfo"][$key] = $value;
        }
    }


    private function readStyleLine(array &$formatData, string $section, string $line): void
    {
        [$type, $value] = $this->splitDescriptor($line);
        if (strcasecmp($type, "Format") === 0) {
            $formatData["styleFormat"] = array_map("trim", explode(",", $value));
        } elseif (strcasecmp($type, "Style") === 0) {
            $formatData["styleFormat"] ??= strcasecmp($section, "V4 Styles") === 0 ? AssFormatLines::SSA_STYLE_FORMAT : AssFormatLines::ASS_STYLE_FORMAT;
            $formatData["styles"][]      = $this->combine($formatData["styleFormat"], $value, true);
        }
    }


    private function readEventLine(array &$formatData, string $line, int $lineNumber): void
    {
        if (!str_contains($line, ":") && !str_starts_with($line, ";")) {
            $this->eventTextLine ??= $lineNumber;
        }
        [$type, $value] = $this->splitDescriptor($line);
        if (strcasecmp($type, "Format") === 0) {
            $formatData["eventFormat"] = array_map("trim", explode(",", $value));
            $this->checkFieldNames($formatData["eventFormat"], $line, $lineNumber);

            return;
        }

        $isComment = strcasecmp($type, "Comment") === 0;
        if (!$isComment && strcasecmp($type, "Dialogue") !== 0) {
            return;
        }

        $this->events[] = ["format" => $formatData["eventFormat"], "line" => $line, "lineNumber" => $lineNumber, "value" => $value, "isComment" => $isComment];
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


    /**
     * @param array{format: list<string>, line: string, lineNumber: int, value: string, isComment: bool} $event
     * @return list<array{string, float}> the loose times of the event that lenient mode read, with their seconds
     */
    private function readEvent(array &$formatData, array $event, int $eventIndex): array
    {
        ["format" => $format, "line" => $line, "lineNumber" => $lineNumber, "value" => $value] = $event;
        $fields = $this->combine($format, $this->options->lenient ? $this->joinCommaFractions($format, $value) : $value, false);
        if ($fields === null) {
            throw new ParsingException("The line \"$line\" has fewer fields than the Format line of the [Events] section.", $lineNumber);
        }
        $fields = array_filter($fields, "is_string", ARRAY_FILTER_USE_KEY);

        $startKey = $this->fieldKey($fields, "Start");
        $endKey   = $this->fieldKey($fields, "End");
        $textKey  = $this->fieldKey($fields, "Text");
        if ($startKey === null || $endKey === null || $textKey === null) {
            throw new ParsingException("The Format line of the [Events] section needs the fields Start, End and Text.", $lineNumber);
        }
        $fields[$startKey] = str_replace("\0", ",", $fields[$startKey]);
        $fields[$endKey]   = str_replace("\0", ",", $fields[$endKey]);

        if ($event["isComment"]) {
            $formatData["commentEvents"][] = $fields;
            $this->comments[] = [$fields[$textKey], count($this->cues)];

            return [];
        }

        $looseTimes = [];
        $startTime  = $this->secondsFromString($fields[$startKey], $lineNumber, $looseTimes);
        $wrapStyle  = array_change_key_case($formatData["scriptInfo"])["wrapstyle"] ?? "";

        $styleKey = $this->fieldKey($fields, "Style");
        $style    = AssStyles::forEvent($formatData["styles"], $styleKey === null ? "" : $fields[$styleKey]);

        $textMarkup          = new AssTextMarkup($formatData["styles"], self::boundedTime(...));
        [$lines, $alignment] = $textMarkup->convert($fields[$textKey], $startTime, $wrapStyle === "2", $lineNumber, $style);
        $alignment         ??= AssStyles::alignment($style, $this->hasLegacyStyles($formatData));

        $nameKey = $this->fieldKey($fields, "Name");
        $lines   = Markup::addSpeaker($lines, $nameKey === null ? "" : $fields[$nameKey]);

        $endTime               = $this->secondsFromString($fields[$endKey], $lineNumber, $looseTimes);
        [$startTime, $endTime] = $this->orderedTimes($startTime, $endTime, $lineNumber, $eventIndex, [$line]);
        $cue = new SubtitleCue($startTime, $endTime, $lines);
        $cue->setAlignment(Alignment::from($alignment ?? Alignment::BottomCenter->value));
        $cue->setFormatData(self::FORMAT_DATA_KEY, [
            "fields"    => array_diff_key($fields, array_flip([$startKey, $endKey, $textKey])),
            "text"      => $fields[$textKey],
            "lines"     => $cue->getLines(),
            "alignment" => $alignment,
        ]);
        $this->cues[] = $cue;

        return $looseTimes;
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


    private function fieldKey(array $fields, string $fieldName): ?string
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


    private function hasLegacyStyles(array $formatData): bool
    {
        return strcasecmp($formatData["stylesSection"] ?? "", "V4 Styles") === 0;
    }


    private function isSsa(array $formatData): bool
    {
        $scriptType = array_change_key_case($formatData["scriptInfo"])["scripttype"] ?? "";

        return strcasecmp($scriptType, "v4.00") === 0 || strcasecmp($formatData["stylesSection"] ?? "", "V4 Styles") === 0;
    }


    /**
     * In lenient mode, it also reads a time without a fraction, with 4 fraction digits or with "," or ":" before the fraction,
     * and adds it to $looseTimes.
     *
     * @param list<array{string, float}> $looseTimes
     */
    private function secondsFromString(string $time, int $lineNumber, array &$looseTimes): float
    {
        if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d)\.(\d{1,3})$/', trim($time), $matches)) {
            return self::boundedTime(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]), $time, $lineNumber);
        }

        $seconds = $this->options->lenient ? LooseTime::toSeconds(trim($time), ".,:") : null;
        if ($seconds === null) {
            throw new ParsingException("The time \"$time\" is not valid.", $lineNumber);
        }
        $looseTimes[] = [$time, $seconds];

        return self::boundedTime($seconds, $time, $lineNumber);
    }
}
