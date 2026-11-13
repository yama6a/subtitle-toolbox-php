<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\StyleRuns;

/**
 * Decodes CEA-608 byte pairs of one data channel into the captions on screen.
 * It follows the screen model of 47 CFR 15.119 and records a state at each change of the displayed captions.
 *
 * @see https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml
 *
 * @internal
 */
final class Cea608Decoder
{
    // The values of the 3 caption modes appear as "mode" in the format data of a cue.
    private const MODE_POP_ON   = "pop-on";
    public const MODE_ROLL_UP   = "roll-up";
    private const MODE_PAINT_ON = "paint-on";
    private const MODE_TEXT     = "text";

    private const DEFAULT_ATTRIBUTES = ["color" => Cea608::WHITE, "italic" => false, "underline" => false];

    // The display change of paint-on or roll-up data, or of an EOC (End Of Caption) or EDM (Erase Displayed Memory) code.
    // Null means no change.
    private const DISPLAY_DIRECT  = "direct";
    private const DISPLAY_REPLACE = "replace";

    /** @var array<int, array<int, ?array{char: string, color: int, italic: bool, underline: bool}>> */
    private array $displayed = [];

    /** @var array<int, array<int, ?array{char: string, color: int, italic: bool, underline: bool}>> */
    private array $nonDisplayed = [];

    private string $mode = self::MODE_POP_ON;

    private int $row = Cea608::ROWS;

    // 32 means that the last character went to the last column.
    private int $column = 0;

    private array $attributes = self::DEFAULT_ATTRIBUTES;

    private int $rollUpRows = 0;

    private int $activeChannel = 1;

    private bool $inXds = false;

    private ?int $lastControl = null;

    private ?string $displayChange = null;

    private int $frame = 0;

    // The frame of the first character on the base row of roll-up captions since the last carriage return.
    private ?int $rollUpLineStart = null;

    private int $rollUpLineRow = Cea608::ROWS;

    /** @var list<array{start: int, end: ?int, row: int, column: int, text: string}> */
    private array $rollUpLines = [];


    public function __construct(private readonly int $channel)
    {
    }


    /**
     * Decodes the byte pairs of the code lines and returns the displayed captions at each change, in time order.
     *
     * @param list<array{int, list<int>}> $codeLines the start frame and the 16-bit words of each line, in time order
     * @return list<array{frame: int, line: int, lines: list<array{row: int, column: int, text: string}>, mode: string, direct: bool}>
     */
    public function decode(array $codeLines): array
    {
        $states            = [];
        $this->frame       = 0;
        $this->rollUpLines = [];
        foreach ($codeLines as $lineIndex => [$startFrame, $words]) {
            if ($startFrame > $this->frame) {
                $this->lastControl = null;
            }
            foreach ($words as $wordIndex => $word) {
                $this->frame = max($this->frame, $startFrame + $wordIndex);
                $this->decodeWord($word >> 8, $word & 0xFF);
                if ($this->displayChange !== null) {
                    $this->recordState($states, $this->frame, $lineIndex);
                    $this->displayChange = null;
                }
            }
            $this->frame = max($this->frame, $startFrame + count($words));
        }
        $this->endRollUpLine(null);

        return $states;
    }


    /**
     * Returns each row of roll-up captions that decode() saw, in time order.
     * A row starts at its first character and ends when it rolls up or leaves the screen.
     * The end is null for a row that is still on the screen at the end of the data.
     *
     * @return list<array{start: int, end: ?int, row: int, column: int, text: string}>
     */
    public function rollUpLines(): array
    {
        return $this->rollUpLines;
    }


    /**
     * Applies the parity and redundancy rules of 47 CFR 15.119 (i) and (j) to a byte pair.
     */
    private function decodeWord(int $first, int $second): void
    {
        $code = $first & 0x7F;
        if ($code < 0x10 || $code > 0x1F) {
            $this->lastControl = null;
            $this->decodeCharacterPair($first, $second);

            return;
        }

        $word = ($first << 8) | $second;
        if (!Cea608::hasOddParity($first)) {
            $redundant         = $this->lastControl !== null && ($this->lastControl & 0xFF) === $second;
            $this->lastControl = null;
            if (!$redundant) {
                $this->decodeCharacterPair(0x80, $second);
            }

            return;
        }
        if (!Cea608::hasOddParity($second) || $this->lastControl === $word) {
            $this->lastControl = null;

            return;
        }

        $this->lastControl   = $word;
        $this->inXds         = false;
        $this->activeChannel = ($code & 0x08) === 0 ? 1 : 2;
        if ($this->activeChannel === $this->channel) {
            $this->decodeControl($code & 0x17, $second & 0x7F);
        }
    }


    private function decodeCharacterPair(int $first, int $second): void
    {
        $code = $first & 0x7F;
        if ($code > 0x00 && $code < 0x10 && Cea608::hasOddParity($first)) {
            // Extended data service packets in field 2 run from a start code 0x01 to 0x0E up to the end code 0x0F.
            $this->inXds = $code !== 0x0F;

            return;
        }
        if ($this->inXds || $this->activeChannel !== $this->channel || $this->mode === self::MODE_TEXT) {
            return;
        }

        foreach ([$first, $second] as $byte) {
            // A byte with a parity error shows as a solid block on a television. A text cue drops it, as it drops the block 0x7F.
            $character = Cea608::hasOddParity($byte) ? Cea608::standardCharacter($byte & 0x7F) : null;
            if ($character !== null) {
                $this->writeCharacter($character);
            }
        }
    }


    private function decodeControl(int $first, int $second): void
    {
        if ($first === Cea608::FIRST_BYTE_CONTROL || $first === 0x15) {
            if ($second <= 0x2F) {
                $this->decodeCommand($second);

                return;
            }
        }
        if ($this->mode === self::MODE_TEXT) {
            return;
        }

        $pac = Cea608::decodePac($first, $second);
        if ($pac !== null) {
            $this->applyPac($pac);
        } elseif ($first === Cea608::FIRST_BYTE_MID_ROW && $second >= 0x20 && $second <= 0x2F) {
            $midRow           = Cea608::decodeMidRow($second);
            $this->attributes = [
                "color"     => $midRow["color"] ?? $this->attributes["color"],
                "italic"    => $midRow["italic"],
                "underline" => $midRow["underline"],
            ];
            $this->writeCharacter(" ");
        } elseif ($first === Cea608::FIRST_BYTE_MID_ROW && $second >= 0x30 && $second <= 0x3F) {
            $this->writeCharacter($second === 0x39 ? null : Cea608::specialCharacter($second));
        } elseif (($first === 0x12 || $first === 0x13) && $second >= 0x20 && $second <= 0x3F) {
            // An extended character replaces the standard character that goes before it for older decoders.
            $this->column = max(0, $this->column - 1);
            $this->writeCharacter(Cea608::extendedCharacter($first, $second));
        } elseif ($first === Cea608::FIRST_BYTE_TAB_OFFSET && $second >= 0x21 && $second <= 0x23) {
            $this->column = min(Cea608::COLUMNS - 1, $this->column + $second - Cea608::TAB_OFFSET_BASE);
        }
    }


    private function decodeCommand(int $command): void
    {
        switch ($command) {
            case Cea608::RESUME_CAPTION_LOADING:
                $this->mode = self::MODE_POP_ON;
                break;
            case Cea608::RESUME_DIRECT_CAPTIONING:
                $this->mode = self::MODE_PAINT_ON;
                break;
            case Cea608::TEXT_RESTART:
            case Cea608::RESUME_TEXT_DISPLAY:
                $this->mode = self::MODE_TEXT;
                break;
            case Cea608::ROLL_UP_2:
            case Cea608::ROLL_UP_3:
            case Cea608::ROLL_UP_4:
                $this->startRollUp($command - Cea608::ROLL_UP_2 + 2);
                break;
            case Cea608::BACKSPACE:
                $this->backspace();
                break;
            case Cea608::DELETE_TO_END_OF_ROW:
                $this->deleteToEndOfRow();
                break;
            case Cea608::CARRIAGE_RETURN:
                if ($this->mode === self::MODE_ROLL_UP) {
                    $this->endRollUpLine($this->frame);
                    $this->rollUp();
                }
                break;
            case Cea608::ERASE_DISPLAYED_MEMORY:
                $this->endRollUpLine($this->frame);
                $this->displayed     = [];
                $this->displayChange = self::DISPLAY_REPLACE;
                break;
            case Cea608::ERASE_NON_DISPLAYED:
                $this->nonDisplayed = [];
                break;
            case Cea608::END_OF_CAPTION:
                $this->endRollUpLine($this->frame);
                [$this->displayed, $this->nonDisplayed] = [$this->nonDisplayed, $this->displayed];
                $this->mode          = self::MODE_POP_ON;
                $this->displayChange = self::DISPLAY_REPLACE;
                break;
        }
    }


    private function backspace(): void
    {
        if ($this->mode !== self::MODE_TEXT && $this->column > 0) {
            $this->column--;
            $this->setCell(null);
        }
    }


    private function deleteToEndOfRow(): void
    {
        if ($this->mode === self::MODE_TEXT) {
            return;
        }

        $memory = &$this->targetMemory();
        foreach (array_keys($memory[$this->row] ?? []) as $column) {
            if ($column >= $this->column) {
                unset($memory[$this->row][$column]);
            }
        }
        $this->markDirectChange();
    }


    private function startRollUp(int $rows): void
    {
        if ($this->mode !== self::MODE_ROLL_UP) {
            $this->endRollUpLine($this->frame);
            $this->displayed     = [];
            $this->nonDisplayed  = [];
            $this->row           = Cea608::ROWS;
            $this->displayChange = self::DISPLAY_REPLACE;
        }

        $this->mode       = self::MODE_ROLL_UP;
        $this->rollUpRows = $rows;
        $this->column     = 0;
        $this->attributes = self::DEFAULT_ATTRIBUTES;
        foreach (array_keys($this->displayed) as $row) {
            if ($row > $this->row || $row <= $this->row - $rows) {
                unset($this->displayed[$row]);
                $this->markDirectChange();
            }
        }
    }


    private function rollUp(): void
    {
        $rolled = [];
        // The screen has no row 0, so a row that rolls above row 1 leaves it.
        for ($row = max(1, $this->row - $this->rollUpRows + 1); $row < $this->row; $row++) {
            if (isset($this->displayed[$row + 1])) {
                $rolled[$row] = $this->displayed[$row + 1];
            }
        }

        $this->displayed  = $rolled;
        $this->column     = 0;
        $this->attributes = self::DEFAULT_ATTRIBUTES;
        $this->markDirectChange();
    }


    private function applyPac(array $pac): void
    {
        if ($this->mode === self::MODE_ROLL_UP && $pac["row"] !== $this->row && $this->displayed !== []) {
            // The roll-up window moves to the new base row without erasing.
            $moved = [];
            foreach ($this->displayed as $row => $cells) {
                $newRow = $row + $pac["row"] - $this->row;
                if ($newRow >= 1 && $newRow <= Cea608::ROWS) {
                    $moved[$newRow] = $cells;
                }
            }
            $this->displayed     = $moved;
            $this->rollUpLineRow += $pac["row"] - $this->row;
            $this->markDirectChange();
        }

        $this->row        = $pac["row"];
        $this->column     = $pac["column"];
        $this->attributes = ["color" => $pac["color"], "italic" => $pac["italic"], "underline" => $pac["underline"]];
    }


    private function writeCharacter(?string $character): void
    {
        if ($this->mode === self::MODE_TEXT) {
            return;
        }

        $this->column = min($this->column, Cea608::COLUMNS - 1);
        $this->setCell($character === null ? null : ["char" => $character] + $this->attributes);
        $this->column++;
    }


    private function setCell(?array $cell): void
    {
        if ($cell !== null && $this->mode === self::MODE_ROLL_UP) {
            $this->rollUpLineStart ??= $this->frame;
            $this->rollUpLineRow     = $this->row;
        }
        $memory = &$this->targetMemory();
        if ($cell === null) {
            unset($memory[$this->row][$this->column]);
        } else {
            $memory[$this->row][$this->column] = $cell;
        }
        $this->markDirectChange();
    }


    private function &targetMemory(): array
    {
        if ($this->mode === self::MODE_POP_ON) {
            return $this->nonDisplayed;
        }

        return $this->displayed;
    }


    private function markDirectChange(): void
    {
        if ($this->mode !== self::MODE_POP_ON) {
            $this->displayChange ??= self::DISPLAY_DIRECT;
        }
    }


    /**
     * Adds the displayed captions as a new state. Paint-on and roll-up data on one line of the file give one state.
     */
    private function recordState(array &$states, int $frame, int $lineIndex): void
    {
        $lines = $this->renderDisplayed();
        $last  = $states === [] ? null : $states[count($states) - 1];
        if ($last !== null && $last["lines"] === $lines) {
            return;
        }

        $mode = $this->mode === self::MODE_TEXT ? self::MODE_POP_ON : $this->mode;
        if ($last !== null && $this->displayChange === self::DISPLAY_DIRECT && $last["direct"] && $last["line"] === $lineIndex
            && $last["lines"] !== [] && $lines !== []) {
            $states[count($states) - 1]["lines"] = $lines;
            $states[count($states) - 1]["mode"]  = $mode;

            return;
        }

        $states[] = ["frame" => $frame, "line" => $lineIndex, "lines" => $lines, "mode" => $mode, "direct" => $this->displayChange === self::DISPLAY_DIRECT];
    }


    /**
     * @return list<array{row: int, column: int, text: string}>
     */
    private function renderDisplayed(): array
    {
        ksort($this->displayed);
        $lines = [];
        foreach ($this->displayed as $row => $cells) {
            $columns = array_keys(array_filter($cells, fn (?array $cell): bool => $cell !== null && trim($cell["char"]) !== ""));
            if ($columns === []) {
                continue;
            }

            $first = min($columns);
            $last  = max($columns);
            $runs  = [];
            for ($column = $first; $column <= $last; $column++) {
                $cell   = $cells[$column] ?? ["char" => " "] + self::DEFAULT_ATTRIBUTES;
                $runs[] = $cell;
            }
            $lines[] = ["row" => $row, "column" => $first, "text" => $this->toMarkup($runs)];
        }

        return $lines;
    }


    /**
     * Adds the base row of roll-up captions as a roll-up line that ends at $end, if a character went to it.
     */
    private function endRollUpLine(?int $end): void
    {
        if ($this->rollUpLineStart === null) {
            return;
        }

        $start                 = $this->rollUpLineStart;
        $this->rollUpLineStart = null;
        foreach ($this->renderDisplayed() as $line) {
            if ($line["row"] === $this->rollUpLineRow) {
                $this->rollUpLines[] = ["start" => $start, "end" => $end] + $line;
            }
        }
    }


    /**
     * Writes the cells as core markup. Spaces at the edge of a styled run go outside its tags.
     */
    private function toMarkup(array $cells): string
    {
        return StyleRuns::toMarkup(array_map(fn (array $cell): array => [$cell["char"], [
            "color" => $cell["color"] === Cea608::WHITE ? null : Cea608::COLORS[$cell["color"]],
            "i"     => $cell["italic"],
            "u"     => $cell["underline"],
        ]], $cells), true);
    }
}
