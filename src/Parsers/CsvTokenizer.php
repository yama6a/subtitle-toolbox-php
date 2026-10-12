<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;

/**
 * Splits RFC 4180 content into records of cells.
 *
 * @internal
 */
final class CsvTokenizer
{
    /** @var list<array{line: int, text: string}> */
    private array $unclosedQuotes = [];


    /**
     * Returns the delimiter that occurs most often in the first line that has one, outside quotes. A comma wins a tie.
     */
    public static function detectDelimiter(string $content): string
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
     * Returns the records of the content, each with the 1-based line number of its start and the indexes of its quoted cells.
     * Line breaks inside quotes become LF. A quote inside an unquoted cell stays text.
     * In lenient mode, a quote without a closing quote ends at the end of its line, and unclosedQuotes() lists it.
     *
     * @return list<array{line: int, cells: list<string>, quoted: list<int>}>
     */
    public function records(string $content, string $delimiter, bool $lenient): array
    {
        $this->unclosedQuotes = [];
        $records              = [];
        $cells                = [];
        $quotedCells          = [];
        $cell                 = "";
        $line                 = 1;
        $start                = 1;
        $length               = strlen($content);
        $i                    = 0;
        while ($i < $length) {
            $char = $content[$i];
            if ($char === '"' && $cell === "") {
                $quoted = self::readQuotedCell($content, $i + 1);
                if ($quoted !== null) {
                    [$cell, $i, $lineBreaks] = $quoted;
                    $line                   += $lineBreaks;
                    $quotedCells[]           = count($cells);
                    continue;
                }
                if (!$lenient) {
                    throw new ParsingException("A quoted CSV cell has no closing quote.", $start);
                }
                $lineStart              = $i - strcspn(strrev(substr($content, 0, $i)), "\r\n");
                $restartAt              = $i + 1 + strcspn($content, "\r\n", $i + 1);
                $cell                   = substr($content, $i + 1, $restartAt - $i - 1);
                $this->unclosedQuotes[] = ["line" => $line, "text" => substr($content, $lineStart, $restartAt - $lineStart)];
                $i                      = $restartAt;
                continue;
            }
            if ($char === $delimiter) {
                $cells[] = $cell;
                $cell    = "";
            } elseif ($char === "\r" || $char === "\n") {
                $i        += $char === "\r" && ($content[$i + 1] ?? "") === "\n" ? 1 : 0;
                $cells[]   = $cell;
                $records[]   = ["line" => $start, "cells" => $cells, "quoted" => $quotedCells];
                $cells       = [];
                $quotedCells = [];
                $cell        = "";
                $start       = ++$line;
            } else {
                $cell .= $char;
            }
            $i++;
        }
        if ($cell !== "" || $cells !== []) {
            $records[] = ["line" => $start, "cells" => [...$cells, $cell], "quoted" => $quotedCells];
        }

        return $records;
    }


    /**
     * Returns the quotes without a closing quote that the last records() call ended at the end of their line.
     * Each has its line number and the text of its line.
     *
     * @return list<array{line: int, text: string}>
     */
    public function unclosedQuotes(): array
    {
        return $this->unclosedQuotes;
    }


    /**
     * Reads a quoted cell from $from, the offset after the opening quote. A doubled quote is one quote.
     * Returns the cell, the offset after the closing quote and the count of line breaks, or null without a closing quote.
     *
     * @return array{string, int, int}|null
     */
    private static function readQuotedCell(string $content, int $from): ?array
    {
        $cell       = "";
        $lineBreaks = 0;
        $length     = strlen($content);
        for ($i = $from; $i < $length; $i++) {
            $char = $content[$i];
            if ($char === '"' && ($content[$i + 1] ?? "") === '"') {
                $cell .= '"';
                $i++;
            } elseif ($char === '"') {
                return [$cell, $i + 1, $lineBreaks];
            } elseif ($char === "\r" || $char === "\n") {
                $i    += $char === "\r" && ($content[$i + 1] ?? "") === "\n" ? 1 : 0;
                $cell .= "\n";
                $lineBreaks++;
            } else {
                $cell .= $char;
            }
        }

        return null;
    }
}
