<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Encoding\Cea608;
use SubtitleToolbox\Encoding\Cea608Encoder;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\LineWrapper;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Writes pop-on captions for CEA-608 data channel 1, one byte pair per frame at 29.97 fps.
 * The EOC (End Of Caption) code shows the loaded caption. The EDM (Erase Displayed Memory) code erases it.
 *
 * @see http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/SCC_FORMAT.HTML
 */
final class SccFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = SccWriteOptions::class;

    private const FRAMES_PER_SECOND = 30000 / 1001;


    /** @var list<SccFitChange> */
    private array $changes = [];


    /**
     * @throws UnwritableContentException for a cue with more than 4 lines, a line longer than 32 characters or a character that CEA-608 lacks.
     *         With SccWriteOptions::$fit, only for text that does not wrap into 4 lines of 32 characters or a character without a replacement.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        return $this->formatWithReport($subtitle, $options)->content;
    }


    /**
     * Writes the subtitle as format() does, and returns the output with each change that SccWriteOptions::$fit made.
     *
     * @throws UnwritableContentException as format() does.
     */
    public function formatWithReport(Subtitle $subtitle, ?WriteOptions $options = null): SccWriteReport
    {
        $options     ??= new WriteOptions();
        $formatOptions = $this->formatOptions($options);
        $dropFrame     = $formatOptions->dropFrame ?? $subtitle->findFormatData(SccParser::FORMAT_DATA_KEY)["dropFrame"] ?? true;
        $timeline      = $this->captionWords($subtitle, $formatOptions->fit);

        return new SccWriteReport($this->applyOutputOptions($this->writeLines($timeline, $dropFrame), $options), $this->changes);
    }


    /**
     * Returns the CEA-608 words of data channel 1 by frame at 29.97 fps. MccFormatter writes them too.
     * With $fit, it records each change for formatWithReport().
     *
     * @return array<int, int>
     * @throws UnwritableContentException as format() does.
     * @internal
     */
    public function captionWords(Subtitle $subtitle, bool $fit): array
    {
        $this->changes = [];
        $timeline      = [];
        $nextFree    = 0;
        $previousEnd = null;
        foreach ($subtitle->getCues() as $index => $cue) {
            $loadWords = $this->loadWords($cue, $index, $fit);
            if ($loadWords === []) {
                continue;
            }

            $start       = $this->secondsToFrame($cue->getStart());
            [$eoc, $edm] = $this->schedule(count($loadWords), $start, $nextFree, $previousEnd);
            if ($fit && $eoc > $start && !$this->fitReport($index, $cue, $eoc)) {
                continue;
            }
            $this->placeCaption($timeline, $loadWords, $eoc, $edm);

            $nextFree    = $eoc + 2;
            $previousEnd = $this->secondsToFrame($cue->getEnd());
        }
        if ($previousEnd !== null) {
            $edm                                   = max($previousEnd, $nextFree);
            [$timeline[$edm], $timeline[$edm + 1]] = $this->commandTwice(Cea608::ERASE_DISPLAYED_MEMORY);
        }
        ksort($timeline);

        return $timeline;
    }


    /**
     * Records that the caption of a cue shows after the cue start at the EOC frame $eoc.
     * Returns false and records a dropped cue when the EOC is not before the cue end.
     */
    private function fitReport(int $index, SubtitleCue $cue, int $eoc): bool
    {
        if ($eoc >= $this->secondsToFrame($cue->getEnd())) {
            $this->change($index, SccFitAction::Dropped, "Cue #$index at {$cue->getStart()} s is left out. " .
                          "Its caption data needs the frames up to " . $this->frameToSeconds($eoc) . " s, but the cue ends at {$cue->getEnd()} s.");

            return false;
        }
        $this->change($index, SccFitAction::Delayed, "Cue #$index at {$cue->getStart()} s shows at " . $this->frameToSeconds($eoc) . " s. " .
                      "The frames before its start cannot hold its caption data.");

        return true;
    }


    /**
     * Puts the EDM of the caption before, the load words in the free frames before the EOC, and the EOC on the timeline.
     *
     * @param array<int, int> $timeline the words by frame
     * @param list<int>       $loadWords
     */
    private function placeCaption(array &$timeline, array $loadWords, int $eoc, ?int $edm): void
    {
        if ($edm !== null) {
            [$timeline[$edm], $timeline[$edm + 1]] = $this->commandTwice(Cea608::ERASE_DISPLAYED_MEMORY);
        }
        $frame = $eoc - 1;
        foreach (array_reverse($loadWords) as $word) {
            while (isset($timeline[$frame])) {
                $frame--;
            }
            $timeline[$frame--] = $word;
        }
        [$timeline[$eoc], $timeline[$eoc + 1]] = $this->commandTwice(Cea608::END_OF_CAPTION);
    }


    private function change(int $cueIndex, SccFitAction $action, string $message): void
    {
        $this->changes[] = new SccFitChange($cueIndex, $action, $message);
    }


    /**
     * Finds the frame of the EOC for the caption, and the frame of the EDM for the caption before it, if any.
     * The load goes into the free frames before the EOC. When they are too few, the EOC comes after the cue start.
     *
     * @return array{int, ?int}
     */
    private function schedule(int $loadLength, int $start, int $nextFree, ?int $previousEnd): array
    {
        $eoc = max($start, $nextFree + $loadLength);
        while (true) {
            $edm = null;
            if ($previousEnd !== null && $previousEnd < $eoc && max($previousEnd, $nextFree) + 1 < $eoc) {
                $edm = max($previousEnd, $nextFree);
            }

            $free = $eoc - $nextFree - ($edm === null ? 0 : 2);
            if ($free >= $loadLength) {
                return [$eoc, $edm];
            }
            $eoc += $loadLength - $free;
        }
    }


    /**
     * Returns the words that load the caption into non-displayed memory, without the EOC, or [] for a cue without text.
     *
     * @return list<int>
     */
    private function loadWords(SubtitleCue $cue, int $index, bool $fit): array
    {
        $text = array_map(Markup::rubyAsText(...), $cue->getLines());
        if ($fit) {
            $text = $this->fitText($text, $cue, $index);
        }
        $lines = [];
        foreach (Markup::carryOpenTags($text) as $line) {
            $characters = Cea608Encoder::styledCharacters($line);
            if ($characters !== []) {
                $lines[] = $characters;
            }
        }
        if ($lines === []) {
            return [];
        }

        if (count($lines) > Cea608::MAX_LINES) {
            throw new UnwritableContentException("Cue #$index at {$cue->getStart()} s has " . count($lines) . " lines, " .
                                                 "but SCC allows " . Cea608::MAX_LINES . ". " . $this->fitHint($cue));
        }
        foreach ($lines as $characters) {
            if (count($characters) > Cea608::COLUMNS) {
                throw new UnwritableContentException("Cue #$index at {$cue->getStart()} s has a line with " . count($characters) .
                                                     " characters, but SCC allows " . Cea608::COLUMNS . ". " . $this->fitHint($cue));
            }
            foreach ($characters as $character) {
                if (Cea608::encodeCharacter($character["char"]) === null) {
                    throw new UnwritableContentException("Cue #$index at {$cue->getStart()} s has the character \"{$character["char"]}\", " .
                                                         "which CEA-608 cannot show.");
                }
            }
        }

        $cells     = array_map(Cea608Encoder::cells(...), $lines);
        $positions = $this->positions($cue, $cells);
        $words     = [...$this->commandTwice(Cea608::ERASE_NON_DISPLAYED), ...$this->commandTwice(Cea608::RESUME_CAPTION_LOADING)];
        foreach ($cells as $lineIndex => $lineCells) {
            array_push($words, ...Cea608Encoder::rowWords($positions[$lineIndex][0], $positions[$lineIndex][1], $lineCells));
        }

        return $words;
    }


    /**
     * Replaces the characters that CEA-608 lacks, then wraps the lines again when they break the limits of SCC.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function fitText(array $lines, SubtitleCue $cue, int $index): array
    {
        $replaced = [];
        foreach ($lines as $lineIndex => $line) {
            $parts = Markup::splitTags($line);
            foreach ($parts as $partIndex => $part) {
                if ($partIndex % 2 === 1) {
                    continue;
                }
                $characters = Markup::characters($part);
                foreach ($characters as $characterIndex => $character) {
                    $replacement = Cea608::encodeCharacter($character) === null ? Cea608::transliterate($character) : null;
                    if ($replacement !== null) {
                        $characters[$characterIndex] = $replacement;
                        $replaced[$character]        = "\"$character\" with \"$replacement\"";
                    }
                }
                $parts[$partIndex] = implode("", $characters);
            }
            $lines[$lineIndex] = implode("", $parts);
        }
        if ($replaced !== []) {
            $this->change($index, SccFitAction::Transliterated, "Cue #$index at {$cue->getStart()} s: replaced " . implode(", ", $replaced) . ".");
        }

        $lengths = array_map(fn (string $line): int => count(Cea608Encoder::styledCharacters($line)), $lines);
        $lengths = array_values(array_filter($lengths));
        if (count($lengths) <= Cea608::MAX_LINES && max([0, ...$lengths]) <= Cea608::COLUMNS) {
            return $lines;
        }

        // Wrapping keeps every visible character but spaces, so more of them than the rows hold never fit.
        // The check skips a break search that takes seconds for a long CJK cue with a break point after each character.
        if (array_sum(array_column(LineWrapper::measuredWords(implode(" ", $lines)), "length")) > Cea608::COLUMNS * Cea608::MAX_LINES) {
            return $lines;
        }

        $wrapped = LineWrapper::wrapToFit($lines, Cea608::COLUMNS, Cea608::MAX_LINES);
        if ($wrapped === null) {
            $wrapped = LineWrapper::wrap($lines, Cea608::COLUMNS, Cea608::MAX_LINES);
            if (!LineWrapper::fits($wrapped, Cea608::COLUMNS, Cea608::MAX_LINES)) {
                return $lines;
            }
        }
        $this->change($index, SccFitAction::Wrapped, "Cue #$index at {$cue->getStart()} s: wrapped the text into " . count($wrapped) .
                      " lines of at most " . Cea608::COLUMNS . " characters.");

        return $wrapped;
    }


    /**
     * Names wrapLines() when the cue text wraps into 4 lines or fewer at 32 characters.
     * Otherwise it also names the split step.
     */
    private function fitHint(SubtitleCue $cue): string
    {
        $columns = Cea608::COLUMNS;
        $lines   = Cea608::MAX_LINES;

        return LineWrapper::fewestLines($cue->getLines(), Cea608::COLUMNS) <= Cea608::MAX_LINES
            ? "Call wrapLines($columns, $lines) first."
            : "Call Resegmenter::apply() with ResegmentMode::SplitLong and new CueLimits($columns, $lines), then wrapLines($columns, $lines).";
    }


    /**
     * Returns the row and the text column of each line.
     * Rows and columns from the scc format data win when they still fit the lines and the alignment.
     * Otherwise the alignment sets them. The default is centred lines at the bottom.
     *
     * @param list<list<array>> $cells
     * @return list<array{int, int}>
     */
    private function positions(SubtitleCue $cue, array $cells): array
    {
        $count     = count($cells);
        $alignment = $cue->getAlignment()->value;
        $stored    = $this->storedPositions($cue, $count, $alignment);

        $positions = [];
        foreach ($cells as $index => $lineCells) {
            $width       = count($lineCells) - Cea608Encoder::leadingMidRowCount($lineCells);
            $positions[] = $stored === null
                ? $this->alignedPosition($alignment, $index, $count, $width)
                : [$stored[0][$index], max(0, min($stored[1][$index], Cea608::COLUMNS - $width))];
        }

        return $positions;
    }


    /**
     * Returns the rows and the columns of the scc format data, or null when they do not fit the lines and the alignment.
     *
     * @return array{list<int>, list<int>}|null
     */
    private function storedPositions(SubtitleCue $cue, int $lineCount, int $alignment): ?array
    {
        $cueData            = $cue->findFormatData(SccParser::FORMAT_DATA_KEY);
        $rows               = $cueData["rows"] ?? null;
        $columns            = $cueData["columns"] ?? null;
        $storedPositionsFit = is_array($rows) && is_array($columns) && count($rows) === $lineCount && count($columns) === $lineCount
                              && array_is_list($rows) && array_is_list($columns)
                              && (($rows[0] ?? 0) <= Cea608::MAX_LINES) === in_array($alignment, [7, 8, 9], true);
        for ($index = 0; $storedPositionsFit && $index < $lineCount; $index++) {
            $storedPositionsFit = is_int($rows[$index]) && is_int($columns[$index]) && $rows[$index] >= 1 && $rows[$index] <= Cea608::ROWS
                                  && ($index === 0 || $rows[$index] > $rows[$index - 1]);
        }

        return $storedPositionsFit ? [$rows, $columns] : null;
    }


    /**
     * Returns the row and the text column of a line from the alignment.
     *
     * @return array{int, int}
     */
    private function alignedPosition(int $alignment, int $lineIndex, int $lineCount, int $width): array
    {
        $firstRow = match (true) {
            in_array($alignment, [7, 8, 9], true) => 1,
            in_array($alignment, [4, 5, 6], true) => intdiv(Cea608::ROWS - $lineCount, 2) + 1,
            default                               => Cea608::ROWS - $lineCount + 1,
        };
        $column   = match (true) {
            in_array($alignment, [1, 4, 7], true) => 0,
            in_array($alignment, [3, 6, 9], true) => Cea608::COLUMNS - $width,
            default                               => intdiv(Cea608::COLUMNS - $width, 2),
        };

        return [$firstRow + $lineIndex, $column];
    }


    /**
     * @return array{int, int} the command word twice, as CEA-608 sends control codes
     */
    private function commandTwice(int $command): array
    {
        $word = Cea608Encoder::word(Cea608::FIRST_BYTE_CONTROL, $command);

        return [$word, $word];
    }


    private function frameToSeconds(int $frame): float
    {
        return round((new FrameRate(self::FRAMES_PER_SECOND))->framesToSeconds($frame), 3);
    }


    private function secondsToFrame(float $seconds): int
    {
        return (new FrameRate(self::FRAMES_PER_SECOND))->secondsToFrames(max(0.0, $seconds));
    }


    /**
     * Writes one line per run of consecutive frames, with an empty line between lines.
     *
     * @param array<int, int> $wordsByFrame
     */
    private function writeLines(array $wordsByFrame, bool $dropFrame): string
    {
        $frameRate = new FrameRate(self::FRAMES_PER_SECOND);
        $lines     = [];
        $previous  = null;
        foreach ($wordsByFrame as $frame => $word) {
            if ($previous === null || $frame !== $previous + 1) {
                [$hours, $minutes, $seconds, $frames] = Timecode::frameNumber($frame, $frameRate, $dropFrame);
                $lines[] = sprintf("%02d:%02d:%02d%s%02d\t%04x", $hours, $minutes, $seconds, $dropFrame ? ";" : ":", $frames, $word);
            } else {
                $lines[count($lines) - 1] .= sprintf(" %04x", $word);
            }
            $previous = $frame;
        }

        $output = SccParser::HEADER . LineEnding::Lf->value . LineEnding::Lf->value;
        foreach ($lines as $line) {
            $output .= $line . LineEnding::Lf->value . LineEnding::Lf->value;
        }

        return rtrim($output, LineEnding::Lf->value) . LineEnding::Lf->value;
    }
}
