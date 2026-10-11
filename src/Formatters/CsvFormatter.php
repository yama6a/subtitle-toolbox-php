<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
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
        $second = $formatOptions->secondText;

        $cues               = array_values($subtitle->getCues());
        $rows               = array_map($this->splitSpeaker(...), $cues);
        [$columns, $header] = $this->columns($fileData, $cues, $rows);
        if ($second !== null) {
            $position = array_search(["text", null], $columns, true) + 1;
            array_splice($columns, $position, 0, [["second", null]]);
            array_splice($header, $position, 0, [$formatOptions->secondTextHeader]);
            $secondTexts = $this->secondTexts($cues, array_values($second->getCues()));
        }

        $records = $fileData === [] || $fileData["header"] !== null ? [$header] : [];
        foreach ($cues as $index => $cue) {
            $records[] = array_map(fn (array $column): string => match ($column[0]) {
                "identifier" => $cue->getIdentifier() ?? "",
                "start"      => $this->timeCell($cue->getStart(), $timeFormat, $frameRate),
                "end"        => $this->timeCell($cue->getEnd(), $timeFormat, $frameRate),
                "duration"   => $this->timeCell($cue->getEnd() - $cue->getStart(), $timeFormat, $frameRate),
                "speaker"    => $rows[$index][0],
                "text"       => $rows[$index][1],
                "second"     => $secondTexts[$index],
                "other"      => (string) ($cue->findFormatData(CsvParser::FORMAT_DATA_KEY)["columns"][$column[1]] ?? ""),
            }, $columns);
        }

        // In-cell line breaks stay LF, as in Excel, so the records get the line ending here.
        $lineEnding = $options->lineEnding->value;
        $lines      = array_map(fn (array $record): string => implode($delimiter, array_map(
            fn (string $cell): string => $this->quote($formatOptions->escapeFormulas ? $this->escapeFormula($cell) : $cell, $delimiter),
            $record
        )), $records);

        return $this->applyBom(implode($lineEnding, $lines) . $lineEnding, $options);
    }


    /**
     * @param list<SubtitleCue>           $cues
     * @param list<array{string, string}> $rows
     *
     * @return array{list<array{string, string|int|null}>, list<string>}
     */
    private function columns(array $fileData, array $cues, array $rows): array
    {
        $columns = [];
        $header  = [];
        if ($fileData !== []) {
            for ($index = 0; $index < $fileData["width"]; $index++) {
                $role      = array_search($index, $fileData["roles"], true);
                $columns[] = $role === false ? ["other", $fileData["header"][$index] ?? $index] : [$role, null];
                $header[]  = $fileData["header"][$index] ?? "";
            }
        } else {
            $hasIdentifier = array_filter($cues, fn (SubtitleCue $cue): bool => $cue->getIdentifier() !== null) !== [];
            foreach (["identifier", "start", "end", "text"] as $role) {
                if ($role !== "identifier" || $hasIdentifier) {
                    $columns[] = [$role, null];
                    $header[]  = $role;
                }
            }
            $others = [];
            foreach ($cues as $cue) {
                $others += $cue->findFormatData(CsvParser::FORMAT_DATA_KEY)["columns"] ?? [];
            }
            foreach (array_keys($others) as $name) {
                $columns[] = ["other", $name];
                $header[]  = (string) $name;
            }
        }

        $hasSpeaker = array_filter($rows, fn (array $row): bool => $row[0] !== "") !== [];
        if ($hasSpeaker && !in_array(["speaker", null], $columns, true)) {
            $position = array_search(["text", null], $columns, true);
            array_splice($columns, $position, 0, [["speaker", null]]);
            array_splice($header, $position, 0, ["speaker"]);
        }

        return [$columns, $header];
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
            CsvTimeFormat::Seconds => rtrim(rtrim(sprintf("%d.%03d", intdiv($milliseconds, 1000), $milliseconds % 1000), "0"), "."),
            CsvTimeFormat::Dot     => Markup::coreTimestamp($seconds),
            CsvTimeFormat::Comma   => sprintf("%02d:%02d:%02d,%03d", ...Timecode::milliseconds($seconds)),
            CsvTimeFormat::Frames  => sprintf("%02d:%02d:%02d:%02d", ...Timecode::clockSecondsAndFrames($seconds, $frameRate)),
        };
    }


    private function escapeFormula(string $cell): string
    {
        return $cell !== "" && str_contains("=+-@", $cell[0]) ? "'$cell" : $cell;
    }


    private function quote(string $cell, string $delimiter): string
    {
        return strpbrk($cell, "\"\r\n$delimiter") === false ? $cell : '"' . str_replace('"', '""', $cell) . '"';
    }
}
