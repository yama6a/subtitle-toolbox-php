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
        $fit           = $formatOptions->fit;
        $this->changes = [];

        $cues = $subtitle->getCues();
        uasort($cues, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());

        $timeline     = [];
        $nextFree     = 0;
        $previousEnd  = null;
        foreach ($cues as $index => $cue) {
            $load = $this->loadWords($cue, $index, $fit);
            if ($load === []) {
                continue;
            }

            $start       = $this->secondsToFrame($cue->getStart());
            [$eoc, $edm] = $this->schedule(count($load), $start, $nextFree, $previousEnd);
            if ($fit && $eoc > $start) {
                $end = $this->secondsToFrame($cue->getEnd());
                if ($eoc >= $end) {
                    $this->change($index, SccFitAction::Dropped, "Cue #$index at {$cue->getStart()} s is left out. " .
                                  "Its caption data needs the frames up to " . $this->frameToSeconds($eoc) . " s, but the cue ends at {$cue->getEnd()} s.");
                    continue;
                }
                $this->change($index, SccFitAction::Delayed, "Cue #$index at {$cue->getStart()} s shows at " . $this->frameToSeconds($eoc) . " s. " .
                              "The frames before its start cannot hold its caption data.");
            }
            if ($edm !== null) {
                [$timeline[$edm], $timeline[$edm + 1]] = $this->commandTwice(Cea608::ERASE_DISPLAYED_MEMORY);
            }
            $frame = $eoc - 1;
            foreach (array_reverse($load) as $word) {
                while (isset($timeline[$frame])) {
                    $frame--;
                }
                $timeline[$frame--] = $word;
            }
            [$timeline[$eoc], $timeline[$eoc + 1]] = $this->commandTwice(Cea608::END_OF_CAPTION);

            $nextFree    = $eoc + 2;
            $previousEnd = $this->secondsToFrame($cue->getEnd());
        }
        if ($previousEnd !== null) {
            $edm                                   = max($previousEnd, $nextFree);
            [$timeline[$edm], $timeline[$edm + 1]] = $this->commandTwice(Cea608::ERASE_DISPLAYED_MEMORY);
        }

        return new SccWriteReport($this->applyOutputOptions($this->writeLines($timeline, $dropFrame), $options), $this->changes);
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
        foreach ($text as $line) {
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

        return count(LineWrapper::wrap($cue->getLines(), Cea608::COLUMNS, PHP_INT_MAX)) <= Cea608::MAX_LINES
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
        $cueData   = $cue->findFormatData(SccParser::FORMAT_DATA_KEY);
        $rows      = $cueData["rows"] ?? null;
        $columns   = $cueData["columns"] ?? null;
        $storedOk  = is_array($rows) && is_array($columns) && count($rows) === $count && count($columns) === $count
                     && array_is_list($rows) && array_is_list($columns)
                     && (($rows[0] ?? 0) <= Cea608::MAX_LINES) === in_array($alignment, [7, 8, 9], true);
        for ($index = 0; $storedOk && $index < $count; $index++) {
            $storedOk = is_int($rows[$index]) && is_int($columns[$index]) && $rows[$index] >= 1 && $rows[$index] <= Cea608::ROWS
                        && ($index === 0 || $rows[$index] > $rows[$index - 1]);
        }

        $firstRow = match (true) {
            in_array($alignment, [7, 8, 9], true) => 1,
            in_array($alignment, [4, 5, 6], true) => intdiv(Cea608::ROWS - $count, 2) + 1,
            default                               => Cea608::ROWS - $count + 1,
        };

        $positions = [];
        foreach ($cells as $index => $lineCells) {
            $width = count($lineCells) - Cea608Encoder::leadingMidRowCount($lineCells);
            if ($storedOk) {
                $positions[] = [$rows[$index], max(0, min($columns[$index], Cea608::COLUMNS - $width))];
                continue;
            }

            $column      = match (true) {
                in_array($alignment, [1, 4, 7], true) => 0,
                in_array($alignment, [3, 6, 9], true) => Cea608::COLUMNS - $width,
                default                               => intdiv(Cea608::COLUMNS - $width, 2),
            };
            $positions[] = [$firstRow + $index, $column];
        }

        return $positions;
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
        ksort($wordsByFrame);
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
