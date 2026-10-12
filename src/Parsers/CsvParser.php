<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FormatDataSchema;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class CsvParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = CsvReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Csv->value;

    /** Header names, without case, spaces, underscores and hyphens, that stand for a role when no header has the role name. They apply only to a table without a "start" or a "text" header. */
    /** The smallest median cue duration, read as seconds, of a table with whole number times that the parser reads as milliseconds. */
    private const MILLISECONDS_MEDIAN_DURATION = 100;

    private const SYNONYMS = [
        "start"    => ["begin", "in", "starttime", "starttc", "timecode", "tcin"],
        "end"      => ["out", "stop", "endtime", "endtc", "tcout"],
        "duration" => ["length"],
        "speaker"  => ["name", "character"],
        "text"     => ["subtitle", "caption", "dialogue", "transcript"],
    ];

    private CsvColumns $columns;


    protected function read(string $content): Subtitle
    {
        $this->columns = $this->formatOptions()->columns ?? new CsvColumns();
        $delimiter     = $this->formatOptions()->delimiter ?? self::detectDelimiter($content);
        $records       = array_values(array_filter(
            $this->records($content, $delimiter),
            fn (array $record): bool => array_filter($record[1], fn (string $cell): bool => trim($cell) !== "") !== []
        ));
        self::checkWidth($records);
        if ($this->columns->header && $this->options->lenient) {
            $records = $this->skipRowsBeforeHeader($records, $delimiter);
        }

        [$headerLine, $header] = $this->columns->header && $records !== [] ? array_shift($records) : [null, null];
        $roles                 = $this->resolveRoles($header);
        $this->warnSynonymColumns($header, $roles, $headerLine, $delimiter);

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $rate       = $this->formatOptions()->frameRate;
        $frameRate  = $rate === null ? null : new FrameRate($rate);
        $timeFormat   = null;
        $openEnds     = [];
        $milliseconds = $this->hasMillisecondTimes($records, $roles, $delimiter);
        foreach (array_values($records) as $rowIndex => [$lineNumber, $cells]) {
            $cell = fn (string $role): string => isset($roles[$role]) ? trim($cells[$roles[$role]] ?? "") : "";
            try {
                [$start, $end] = self::readTimes($cell, $frameRate, $milliseconds, $lineNumber);
                // A cut-off row or a time with a comma that split the cells gives the wrong end, so lenient mode drops the row.
                $end = $end === null ? null : $this->orderedTimes($start, $end, $lineNumber, $rowIndex, [implode($delimiter, $cells)], false)[1];
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $rowIndex, [implode($delimiter, $cells)]);
                continue;
            }
            $timeFormat ??= $milliseconds ? CsvTimeFormat::Milliseconds->value : self::timeFormatOf($cell("start"));

            $cue = new SubtitleCue($start, $end ?? $start, $this->textLines($cells[$roles["text"]] ?? "", $cell("speaker")));
            self::addCellData($cue, $cell, $cells, $roles, $header);
            if ($end === null) {
                $openEnds[] = $cue;
            }
            $parsedCues[] = $cue;
        }
        $subtitle->addCues($parsedCues);
        $this->closeOpenEnds($subtitle, $openEnds);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "delimiter"  => $delimiter,
            "header"     => $header,
            "roles"      => $roles,
            "width"      => max([count($header ?? []), ...array_map("count", array_column($records, 1))]),
            "timeFormat" => $timeFormat ?? CsvTimeFormat::Dot->value,
            "frameRate"  => $rate,
        ]);

        return $subtitle;
    }


    /**
     * Returns the start and the end of a record. The end is null when the record has neither an end nor a duration.
     *
     * @param \Closure(string): string $cell the trimmed cell of a role
     * @return array{float, ?float}
     */
    private static function readTimes(\Closure $cell, ?FrameRate $frameRate, bool $milliseconds, int $lineNumber): array
    {
        $start = self::parseTime($cell("start"), $frameRate, $milliseconds, $lineNumber);
        $end   = match (true) {
            $cell("end") !== ""      => self::parseTime($cell("end"), $frameRate, $milliseconds, $lineNumber),
            $cell("duration") !== "" => $start + self::parseTime($cell("duration"), $frameRate, $milliseconds, $lineNumber),
            default                  => null,
        };

        return [$start, $end];
    }


    /**
     * Returns true when every time of the table is a whole number and the median cue duration is
     * MILLISECONDS_MEDIAN_DURATION or more, read as seconds. Then it warns once, also in strict mode.
     * openai-whisper and whisper.cpp write such tables with times in milliseconds.
     *
     * @param list<array{int, list<string>}> $records
     * @param array<string, int>             $roles
     */
    private function hasMillisecondTimes(array $records, array $roles, string $delimiter): bool
    {
        $durations = [];
        foreach ($records as [, $cells]) {
            $times = array_map(fn (string $role): string => isset($roles[$role]) ? trim($cells[$roles[$role]] ?? "") : "", ["start" => "start", "end" => "end", "duration" => "duration"]);
            foreach ($times as $time) {
                if ($time !== "" && !ctype_digit($time)) {
                    return false;
                }
            }
            if ($times["start"] !== "" && $times["end"] !== "") {
                $durations[] = (int) $times["end"] - (int) $times["start"];
            } elseif ($times["duration"] !== "") {
                $durations[] = (int) $times["duration"];
            }
        }
        if ($durations === []) {
            return false;
        }
        sort($durations);
        $middle = intdiv(count($durations), 2);
        $median = count($durations) % 2 === 1 ? $durations[$middle] : ($durations[$middle - 1] + $durations[$middle]) / 2;
        if ($median < self::MILLISECONDS_MEDIAN_DURATION) {
            return false;
        }

        $this->warn(
            "The times are whole numbers, and the median cue lasts $median seconds. The parser reads the times as milliseconds.",
            $records[0][0],
            null,
            [implode($delimiter, $records[0][1])],
            ParseWarningAction::Repaired
        );

        return true;
    }


    /**
     * Sets the identifier of $cue, and keeps the cells without a role as format data.
     * The format data keys them by their header name, else by their column index.
     *
     * @param \Closure(string): string $cell
     * @param list<string>             $cells
     * @param array<string, int>       $roles
     * @param list<string>|null        $header
     */
    private static function addCellData(SubtitleCue $cue, \Closure $cell, array $cells, array $roles, ?array $header): void
    {
        if ($cell("identifier") !== "") {
            $cue->setIdentifier($cell("identifier"));
        }
        $named = [];
        foreach (array_diff_key($cells + array_fill(0, count($header ?? $cells), ""), array_flip($roles)) as $index => $value) {
            $named[$header[$index] ?? $index] = $value;
        }
        if ($named !== []) {
            $cue->setFormatData(self::FORMAT_DATA_KEY, ["columns" => $named]);
        }
    }


    /**
     * @param array<int, array{int, list<string>}> $records
     */
    private static function checkWidth(array $records): void
    {
        foreach ($records as [$lineNumber, $cells]) {
            if (count($cells) > FormatDataSchema::CSV_MAX_COLUMNS) {
                throw new ParsingException("The table has " . count($cells) . " columns. The limit is " . FormatDataSchema::CSV_MAX_COLUMNS . ".", $lineNumber);
            }
        }
    }


    /**
     * Returns the delimiter that occurs most often in the first line that has one, outside quotes. A comma wins a tie.
     */
    private static function detectDelimiter(string $content): string
    {
        $counts = array_fill_keys(CsvReadOptions::DELIMITERS, 0);
        $quoted = false;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && ($char === "\n" || $char === "\r") && max($counts) > 0) {
                break;
            } elseif (!$quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }

        return array_search(max($counts), $counts, true);
    }


    /**
     * Splits RFC 4180 content into records of cells, keyed by order, each with the 1-based line number of its start.
     * Line breaks inside quotes become LF. A quote inside an unquoted cell stays text.
     * In lenient mode, a quote without a closing quote ends at the end of its line.
     *
     * @return list<array{int, list<string>}>
     */
    private function records(string $content, string $delimiter): array
    {
        $records = [];
        $cells   = [];
        $cell    = "";
        $line    = 1;
        $start   = 1;
        $quoted  = false;
        $quoteAt = 0;
        $length  = strlen($content);
        for ($i = 0; ; $i = $lineEnd) {
            for (; $i < $length; $i++) {
                $char = $content[$i];
                if ($quoted) {
                    if ($char === '"' && ($content[$i + 1] ?? "") === '"') {
                        $cell .= '"';
                        $i++;
                    } elseif ($char === '"') {
                        $quoted = false;
                    } elseif ($char === "\r" || $char === "\n") {
                        $i    += $char === "\r" && ($content[$i + 1] ?? "") === "\n" ? 1 : 0;
                        $cell .= "\n";
                        $line++;
                    } else {
                        $cell .= $char;
                    }
                } elseif ($char === '"' && $cell === "") {
                    [$quoted, $quoteAt, $quoteLine] = [true, $i, $line];
                } elseif ($char === $delimiter) {
                    $cells[] = $cell;
                    $cell    = "";
                } elseif ($char === "\r" || $char === "\n") {
                    $i        += $char === "\r" && ($content[$i + 1] ?? "") === "\n" ? 1 : 0;
                    $cells[]   = $cell;
                    $records[] = [$start, $cells];
                    $cells     = [];
                    $cell      = "";
                    $start     = ++$line;
                } else {
                    $cell .= $char;
                }
            }
            if (!$quoted) {
                break;
            }
            if (!$this->options->lenient) {
                throw new ParsingException("A quoted CSV cell has no closing quote.", $start);
            }
            $lineStart = $quoteAt - strcspn(strrev(substr($content, 0, $quoteAt)), "\r\n");
            $lineEnd   = $quoteAt + 1 + strcspn($content, "\r\n", $quoteAt + 1);
            $cell      = substr($content, $quoteAt + 1, $lineEnd - $quoteAt - 1);
            $quoted    = false;
            $line      = $quoteLine;
            $this->warn(
                "A quoted CSV cell has no closing quote. The cell ends at the end of the line.",
                $line,
                null,
                [substr($content, $lineStart, $lineEnd - $lineStart)],
                ParseWarningAction::Repaired
            );
        }
        if ($cell !== "" || $cells !== []) {
            $records[] = [$start, [...$cells, $cell]];
        }

        return $records;
    }


    /**
     * Drops the rows before the first row that has the columns of the header, and warns once for them.
     * Without such a row, it keeps all rows, so that resolveRoles() names the missing column.
     *
     * @param list<array{int, list<string>}> $records
     *
     * @return list<array{int, list<string>}>
     */
    private function skipRowsBeforeHeader(array $records, string $delimiter): array
    {
        foreach ($records as $index => [, $cells]) {
            try {
                $this->resolveRoles($cells);
            } catch (ParsingException) {
                continue;
            }
            if ($index > 0) {
                $this->warn(
                    "The table has " . ($index === 1 ? "1 row" : "$index rows") . " before the header row.",
                    $records[0][0],
                    null,
                    array_map(fn (array $record): string => implode($delimiter, $record[1]), array_slice($records, 0, $index)),
                    ParseWarningAction::Skipped
                );
            }

            return array_slice($records, $index);
        }

        return $records;
    }


    /**
     * Reads seconds, h:mm:ss.mmm, m:ss.mmm or hh:mm:ss:ff. The last seconds field can have 1 digit. Frames need a frame rate.
     * With $milliseconds, a number is milliseconds.
     */
    private static function parseTime(string $time, ?FrameRate $frameRate, bool $milliseconds, int $lineNumber): float
    {
        if (preg_match('/^\d+(?:\.\d+)?$/', $time)) {
            return self::boundedTime((float) $time / ($milliseconds ? 1000 : 1), $time, $lineNumber);
        }
        $problem = "is not seconds, h:mm:ss.mmm, m:ss.mmm, hh:mm:ss,mmm or hh:mm:ss:ff.";
        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d):(\d+)$/', $time, $matches)) {
            if ($frameRate !== null) {
                return self::boundedTime(Timecode::toSecondsFromFrames((int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[4], $frameRate), $time, $lineNumber);
            }
            $problem = "counts frames. Set CsvReadOptions::\$frameRate.";
        } else {
            try {
                return Timecode::parse((string) preg_replace('/:(\d)(?=$|[.,])/', ':0$1', $time));
            } catch (InvalidArgumentException $e) {
                $problem = str_contains($e->getMessage(), " is not below ") ? ltrim(substr($e->getMessage(), (int) strpos($e->getMessage(), " is not below "))) : $problem;
            }
        }

        throw new ParsingException("The time \"$time\" $problem", $lineNumber);
    }


    private static function timeFormatOf(string $time): string
    {
        return match (true) {
            str_contains($time, ",")       => CsvTimeFormat::Comma->value,
            substr_count($time, ":") === 3 => CsvTimeFormat::Frames->value,
            str_contains($time, ":")       => CsvTimeFormat::Dot->value,
            default                        => CsvTimeFormat::Seconds->value,
        };
    }


    /**
     * Returns the index of the first header name that is a synonym of $role, or false.
     * The match ignores case, spaces, underscores and hyphens. An earlier synonym in SYNONYMS wins.
     *
     * @param list<string> $header
     */
    private static function findSynonymColumn(string $role, array $header): int|false
    {
        $names = array_map(fn (string $name): string => (string) preg_replace('/[\s_-]+/', "", strtolower($name)), $header);
        foreach (self::SYNONYMS[$role] ?? [] as $synonym) {
            $index = array_search($synonym, $names, true);
            if ($index !== false) {
                return $index;
            }
        }

        return false;
    }


    /**
     * Records a ParseWarning that names the columns that resolveRoles() found by a synonym.
     *
     * @param list<string>|null  $header
     * @param array<string, int> $roles
     */
    private function warnSynonymColumns(?array $header, array $roles, ?int $headerLine, string $delimiter): void
    {
        if ($header === null || $this->formatOptions()->columns !== null) {
            return;
        }
        $found = [];
        foreach ($roles as $role => $index) {
            if (strtolower(trim($header[$index])) !== $role) {
                $found[] = "\"" . trim($header[$index]) . "\" as $role";
            }
        }
        if ($found !== []) {
            $last = array_pop($found);
            $this->warn(
                "The parser reads the " . ($found === [] ? "column $last" : "columns " . implode(", ", $found) . " and $last") . ".",
                $headerLine,
                null,
                [implode($delimiter, $header)],
                ParseWarningAction::Repaired
            );
        }
    }


    /**
     * Returns the 0-based column index of each role that the table has.
     *
     * @param list<string>|null $header
     *
     * @return array<string, int>
     */
    private function resolveRoles(?array $header): array
    {
        $names    = array_map(fn (string $name): string => strtolower(trim($name)), $header ?? []);
        $roles    = [];
        $synonyms = $this->formatOptions()->columns === null && (!in_array("start", $names, true) || !in_array("text", $names, true));
        foreach (CsvColumns::ROLES as $role) {
            $column = $this->columns->$role;
            $index  = match (true) {
                is_int($column)    => $column,
                is_string($column) => array_search(strtolower(trim($column)), $names, true),
                default            => array_search($role, $names, true),
            };
            if ($index === false && $synonyms) {
                $index = self::findSynonymColumn($role, $header ?? []);
            }
            if ($index !== false && ($header === null || $index < count($header))) {
                $roles[$role] = $index;
            } elseif ($column !== null || $role === "start" || $role === "text") {
                throw new ParsingException("The table has no column \"" . ($column ?? $role) . "\" for $role.", 1);
            }
        }

        return $roles;
    }


    /**
     * @return list<string>
     */
    private function textLines(string $text, string $speaker): array
    {
        return Markup::addSpeaker(explode("\n", Markup::escapeText($text)), $speaker);
    }


    /**
     * @param list<SubtitleCue> $openEnds
     */
    private function closeOpenEnds(Subtitle $subtitle, array $openEnds): void
    {
        $starts = array_values(array_unique(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $subtitle->getCues())));
        sort($starts);
        foreach ($openEnds as $cue) {
            $next = null;
            foreach ($starts as $start) {
                if ($start > $cue->getStart()) {
                    $next = $start;
                    break;
                }
            }
            $cue->setEnd($next ?? $cue->getStart() + $this->options->lastCueDuration);
        }
    }
}
