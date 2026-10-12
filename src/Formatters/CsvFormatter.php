<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\CsvColumnRole;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class CsvFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = CsvWriteOptions::class;

    protected const DEFAULT_BOM = true;

    private const SPEAKER_REGEX = '/^' . Markup::VOICE_TAG . '/';


    /**
     * Writes one row per cue. A subtitle from CsvParser keeps its columns, header names, delimiter and time format.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $formatOptions = $this->formatOptions($options);
        $fileData      = $subtitle->findFormatData(CsvParser::FORMAT_DATA_KEY);
        $delimiter     = $formatOptions->delimiter ?? $fileData["delimiter"] ?? ",";
        $timeFormat    = $formatOptions->timeFormat ?? CsvTimeFormat::from($fileData["timeFormat"] ?? CsvTimeFormat::Dot->value);
        $fps           = $formatOptions->frameRate ?? $fileData["frameRate"] ?? null;
        $frameRate     = $fps === null ? null : new FrameRate($fps);
        if ($timeFormat === CsvTimeFormat::Frames && $frameRate === null) {
            throw new InvalidArgumentException("The time format " . CsvTimeFormat::Frames->value . " needs a frame rate. Set CsvWriteOptions::\$frameRate.");
        }
        $secondSubtitle = $formatOptions->secondText;

        $cues               = array_values($subtitle->getCues());
        $speakerAndText     = array_map($this->splitSpeaker(...), $cues);
        [$columns, $header, $quotedColumns] = $this->columns($fileData, $cues, $speakerAndText);
        $secondTexts        = [];
        if ($secondSubtitle !== null) {
            $this->insertSecondTextColumn($columns, $header, $formatOptions->secondTextHeader);
            $secondTexts = $this->secondTexts($cues, array_values($secondSubtitle->getCues()));
        }

        $quotedPositions = array_keys(array_filter($columns, fn (array $column): bool => in_array($column, $quotedColumns, true)));
        $lines           = $fileData === [] || $fileData["header"] !== null ? [$this->line($header, $delimiter, $formatOptions->escapeFormulas, [])] : [];
        foreach ($cues as $index => $cue) {
            $record  = $this->record($cue, $columns, $speakerAndText[$index], $secondTexts[$index] ?? "", $timeFormat, $frameRate);
            $lines[] = $this->line($record, $delimiter, $formatOptions->escapeFormulas, $quotedPositions);
        }

        // In-cell line breaks stay LF, as in Excel, so the records get the line ending here.
        $lineEnding = $options->lineEnding->value;

        return $this->applyBom(implode($lineEnding, $lines) . $lineEnding, $options);
    }


    /**
     * @param list<array{CsvColumnRole, string|int|null}> $columns
     * @param list<string>                                $header
     */
    private function insertSecondTextColumn(array &$columns, array &$header, string $headerName): void
    {
        $position = array_search([CsvColumnRole::Text, null], $columns, true) + 1;
        array_splice($columns, $position, 0, [[CsvColumnRole::Second, null]]);
        array_splice($header, $position, 0, [$headerName]);
    }


    /**
     * @param list<array{CsvColumnRole, string|int|null}> $columns
     * @param array{string, string}                       $speakerAndText
     *
     * @return list<string>
     */
    private function record(SubtitleCue $cue, array $columns, array $speakerAndText, string $secondText, CsvTimeFormat $timeFormat, ?FrameRate $frameRate): array
    {
        return array_map(fn (array $column): string => match ($column[0]) {
            CsvColumnRole::Identifier => $cue->findIdentifier() ?? "",
            CsvColumnRole::Start      => $this->timeCell($cue->getStart(), $timeFormat, $frameRate),
            CsvColumnRole::End        => $this->timeCell($cue->getEnd(), $timeFormat, $frameRate),
            CsvColumnRole::Duration   => $this->timeCell($cue->getEnd() - $cue->getStart(), $timeFormat, $frameRate),
            CsvColumnRole::Speaker    => $speakerAndText[0],
            CsvColumnRole::Text       => $speakerAndText[1],
            CsvColumnRole::Second     => $secondText,
            CsvColumnRole::Other      => (string) ($cue->findFormatData(CsvParser::FORMAT_DATA_KEY)["columns"][$column[1]] ?? ""),
        }, $columns);
    }


    /**
     * @param list<string> $record
     * @param list<int>    $quotedPositions the positions of the cells that get quotes even when they need none
     */
    private function line(array $record, string $delimiter, bool $escapeFormulas, array $quotedPositions): string
    {
        $cells = [];
        foreach ($record as $position => $cell) {
            $cells[] = $this->quote($escapeFormulas ? $this->escapeFormula($cell) : $cell, $delimiter, in_array($position, $quotedPositions, true));
        }

        return implode($delimiter, $cells);
    }


    /**
     * Returns the columns, the header and the columns whose cells the parsed table quoted in every row.
     *
     * @param list<SubtitleCue>           $cues
     * @param list<array{string, string}> $speakerAndText
     *
     * @return array{list<array{CsvColumnRole, string|int|null}>, list<string>, list<array{CsvColumnRole, string|int|null}>}
     */
    private function columns(array $fileData, array $cues, array $speakerAndText): array
    {
        $columns       = [];
        $header        = [];
        $quotedColumns = [];
        if ($fileData !== []) {
            for ($index = 0; $index < $fileData["width"]; $index++) {
                $role      = array_search($index, $fileData["roles"], true);
                $columns[] = $role === false ? [CsvColumnRole::Other, $fileData["header"][$index] ?? $index] : [CsvColumnRole::from($role), null];
                $header[]  = $fileData["header"][$index] ?? "";
            }
            foreach ($fileData["quotedColumns"] ?? [] as $index) {
                if (isset($columns[$index])) {
                    $quotedColumns[] = $columns[$index];
                }
            }
        } else {
            $hasIdentifier = array_filter($cues, fn (SubtitleCue $cue): bool => $cue->findIdentifier() !== null) !== [];
            foreach ([CsvColumnRole::Identifier, CsvColumnRole::Start, CsvColumnRole::End, CsvColumnRole::Text] as $role) {
                if ($role !== CsvColumnRole::Identifier || $hasIdentifier) {
                    $columns[] = [$role, null];
                    $header[]  = $role->value;
                }
            }
            $others = [];
            foreach ($cues as $cue) {
                $others += $cue->findFormatData(CsvParser::FORMAT_DATA_KEY)["columns"] ?? [];
            }
            foreach (array_keys($others) as $name) {
                $columns[] = [CsvColumnRole::Other, $name];
                $header[]  = (string) $name;
            }
        }

        $hasSpeaker = array_filter($speakerAndText, fn (array $pair): bool => $pair[0] !== "") !== [];
        if ($hasSpeaker && !in_array([CsvColumnRole::Speaker, null], $columns, true)) {
            $position = array_search([CsvColumnRole::Text, null], $columns, true);
            array_splice($columns, $position, 0, [[CsvColumnRole::Speaker, null]]);
            array_splice($header, $position, 0, [CsvColumnRole::Speaker->value]);
        }

        return [$columns, $header, $quotedColumns];
    }


    /**
     * Returns the speaker of the leading <v> tag and the plain text of the cue.
     *
     * @return array{string, string}
     */
    private function splitSpeaker(SubtitleCue $cue): array
    {
        $lines   = array_map(Markup::rubyAsText(...), $cue->getLines());
        $speaker = "";
        if ($lines !== [] && preg_match(self::SPEAKER_REGEX, $lines[0], $matches)) {
            $speaker  = Markup::speaker($matches[0]);
            $lines[0] = substr($lines[0], strlen($matches[0]));
        }

        return [$speaker, implode(LineEnding::Lf->value, Markup::plainLines($lines))];
    }


    /**
     * Gives each row the text of the second cue that overlaps it most. A second cue fills only the first row that it wins.
     *
     * @param list<SubtitleCue> $cues
     * @param list<SubtitleCue> $secondCues
     *
     * @return list<string>
     */
    private function secondTexts(array $cues, array $secondCues): array
    {
        $texts = [];
        $used  = [];
        foreach ($cues as $cue) {
            $best        = null;
            $bestOverlap = 0.0;
            foreach ($secondCues as $index => $secondCue) {
                $overlap = min($cue->getEnd(), $secondCue->getEnd()) - max($cue->getStart(), $secondCue->getStart());
                if ($overlap > $bestOverlap) {
                    $best        = $index;
                    $bestOverlap = $overlap;
                }
            }
            if ($best === null || isset($used[$best])) {
                $texts[] = "";
                continue;
            }
            $used[$best] = true;
            $texts[]     = implode(LineEnding::Lf->value, $this->plainLines($secondCues[$best]));
        }

        return $texts;
    }


    private function timeCell(float $seconds, CsvTimeFormat $timeFormat, ?FrameRate $frameRate): string
    {
        $seconds      = max(0, $seconds);
        $milliseconds = Timecode::totalMilliseconds($seconds);

        return match ($timeFormat) {
            CsvTimeFormat::Seconds      => rtrim(rtrim(sprintf("%d.%03d", intdiv($milliseconds, 1000), $milliseconds % 1000), "0"), "."),
            CsvTimeFormat::Dot          => Markup::coreTimestamp($seconds),
            CsvTimeFormat::Comma        => sprintf("%02d:%02d:%02d,%03d", ...Timecode::milliseconds($seconds)),
            CsvTimeFormat::Frames       => sprintf("%02d:%02d:%02d:%02d", ...Timecode::clockSecondsAndFrames($seconds, $frameRate)),
            CsvTimeFormat::Milliseconds => (string) $milliseconds,
        };
    }


    private function escapeFormula(string $cell): string
    {
        return $cell !== "" && str_contains("=+-@", $cell[0]) ? "'$cell" : $cell;
    }


    private function quote(string $cell, string $delimiter, bool $always): string
    {
        return !$always && strpbrk($cell, "\"\r\n$delimiter") === false ? $cell : '"' . str_replace('"', '""', $cell) . '"';
    }
}
