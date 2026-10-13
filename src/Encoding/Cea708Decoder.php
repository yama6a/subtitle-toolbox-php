<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Parsers\Mcc;
use SubtitleToolbox\StyleRuns;

/**
 * Decodes the DTVCC packets of one CEA-708 caption service into the text on screen.
 * It keeps the 8 windows of the service, their pen positions and pen styles, and records a state when the text of
 * the visible windows changes at the end of a service block.
 *
 * @see https://en.wikipedia.org/wiki/CEA-708
 *
 * @internal
 */
final class Cea708Decoder
{
    private const WINDOW_COUNT = 8;

    // The service number 7 in a service block header means that the next byte holds the number.
    private const EXTENDED_SERVICE = 7;

    // The anchor of an absolute position counts 75 rows and 210 columns, the size of a 16:9 screen.
    private const ABSOLUTE_ROWS    = 75;
    private const ABSOLUTE_COLUMNS = 210;

    private const DEFAULT_PEN = ["italic" => false, "underline" => false, "color" => Cea708::WHITE];

    /** @var array<int, array{visible: bool, rows: int, anchor: Alignment, priority: int, cells: array<int, array<int, array>>, row: int, column: int, pen: array}> */
    private array $windows = [];

    private ?int $current = null;

    private string $packet = "";

    private int $packetSize = 0;


    public function __construct(private readonly int $service)
    {
    }


    /**
     * Returns the displayed text at each change, in time order.
     *
     * @param list<array{float, string}> $frames the time in seconds and the valid cc_data triplets, as Mcc::readCdp() returns them
     * @return list<array{time: float, lines: list<string>, alignment: ?Alignment}>
     */
    public function decode(array $frames): array
    {
        $states = [];
        foreach ($frames as [$time, $triplets]) {
            for ($offset = 0; $offset < strlen($triplets); $offset += 3) {
                $type = ord($triplets[$offset]);
                if ($type === Mcc::DTVCC_START) {
                    $size             = ord($triplets[$offset + 1]) & 0x3F;
                    $this->packet     = "";
                    $this->packetSize = $size === 0 ? 128 : $size * 2;
                } elseif ($type !== Mcc::DTVCC_DATA || $this->packetSize === 0) {
                    continue;
                }
                $this->packet .= substr($triplets, $offset + 1, 2);
                if (strlen($this->packet) >= $this->packetSize) {
                    $this->readPacket(substr($this->packet, 1, $this->packetSize - 1), $time, $states);
                    $this->packetSize = 0;
                }
            }
        }

        return $states;
    }


    /**
     * Interprets the service blocks of the wanted service in one packet.
     */
    private function readPacket(string $data, float $time, array &$states): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $header  = ord($data[$offset++]);
            $service = $header >> 5;
            $size    = $header & 0x1F;
            if ($service === 0 || $size === 0) {
                return;
            }
            if ($service === self::EXTENDED_SERVICE) {
                $service = ord($data[$offset++] ?? "\0") & 0x3F;
            }
            if ($service === $this->service) {
                $this->interpret(substr($data, $offset, $size));
                $this->record($time, $states);
            }
            $offset += $size;
        }
    }


    private function interpret(string $block): void
    {
        $offset = 0;
        $length = strlen($block);
        while ($offset < $length) {
            $code = ord($block[$offset++]);
            if ($code === Cea708::EXT1) {
                $offset = $this->extended($block, $offset);
            } elseif ($code < 0x20) {
                $this->c0($code);
                $offset += $code >= Cea708::P16 ? 2 : ($code >= 0x11 ? 1 : 0);
            } elseif ($code < 0x80) {
                $this->write($code === Cea708::MUSIC_NOTE ? "\u{266A}" : chr($code));
            } elseif ($code < 0xA0) {
                $count  = Cea708::C1_PARAMETERS[$code - 0x80];
                $this->c1($code, array_values(unpack("C*", substr($block, $offset, $count) . str_repeat("\0", $count)) ?: []));
                $offset += $count;
            } else {
                $this->write(mb_chr($code, "UTF-8"));
            }
        }
    }


    /**
     * Reads the code after EXT1: a C2 or C3 code with its parameter bytes, a G2 character, or a G3 icon.
     */
    private function extended(string $block, int $offset): int
    {
        $code = ord($block[$offset] ?? "\0");
        $offset++;
        if ($code < 0x20) {
            return $offset + intdiv($code, 8);
        }
        if ($code < 0x80) {
            $character = Cea708::G2[$code] ?? null;
            if ($character !== null) {
                $this->write($character);
            }

            return $offset;
        }
        if ($code < 0x90) {
            return $offset + ($code < 0x88 ? 4 : 5);
        }
        if ($code < 0xA0) {
            // A variable length code: the next byte holds the length in its 6 low bits.
            return $offset + 1 + (ord($block[$offset] ?? "\0") & 0x3F);
        }

        return $offset;
    }


    private function c0(int $code): void
    {
        $window = &$this->window();
        if ($window === null) {
            return;
        }
        switch ($code) {
            case Cea708::BS:
                if ($window["column"] > 0) {
                    $window["column"]--;
                    unset($window["cells"][$window["row"]][$window["column"]]);
                }
                break;
            case Cea708::FF:
                $window["cells"] = [];
                $window["row"]   = 0;
                $window["column"] = 0;
                break;
            case Cea708::CR:
                $window["column"] = 0;
                if ($window["row"] + 1 < $window["rows"]) {
                    $window["row"]++;
                } else {
                    $cells = [];
                    foreach ($window["cells"] as $row => $rowCells) {
                        if ($row > 0) {
                            $cells[$row - 1] = $rowCells;
                        }
                    }
                    $window["cells"] = $cells;
                }
                break;
            case Cea708::HCR:
                unset($window["cells"][$window["row"]]);
                $window["column"] = 0;
                break;
        }
    }


    /**
     * @param list<int> $parameters
     */
    private function c1(int $code, array $parameters): void
    {
        if ($code < Cea708::CLW) {
            $this->current = isset($this->windows[$code - Cea708::CW0]) ? $code - Cea708::CW0 : $this->current;

            return;
        }
        if ($code >= Cea708::DF0) {
            $this->defineWindow($code - Cea708::DF0, $parameters);

            return;
        }

        $window = &$this->window();
        switch ($code) {
            case Cea708::CLW:
            case Cea708::DSW:
            case Cea708::HDW:
            case Cea708::TGW:
            case Cea708::DLW:
                $this->windowCommand($code, $parameters[0]);
                break;
            case Cea708::RST:
                $this->windows = [];
                $this->current = null;
                break;
            case Cea708::SPA:
                if ($window !== null) {
                    $window["pen"]["italic"]    = ($parameters[1] & 0x80) !== 0;
                    $window["pen"]["underline"] = ($parameters[1] & 0x40) !== 0;
                }
                break;
            case Cea708::SPC:
                if ($window !== null) {
                    $window["pen"]["color"] = $parameters[0] & 0x3F;
                }
                break;
            case Cea708::SPL:
                if ($window !== null) {
                    $window["row"]    = $parameters[0] & 0x0F;
                    $window["column"] = $parameters[1] & 0x3F;
                }
                break;
        }
    }


    private function windowCommand(int $code, int $bitmap): void
    {
        for ($id = 0; $id < self::WINDOW_COUNT; $id++) {
            if (($bitmap >> $id & 1) === 0 || !isset($this->windows[$id])) {
                continue;
            }
            match ($code) {
                Cea708::CLW => $this->windows[$id]["cells"] = [],
                Cea708::DSW => $this->windows[$id]["visible"] = true,
                Cea708::HDW => $this->windows[$id]["visible"] = false,
                Cea708::TGW => $this->windows[$id]["visible"] = !$this->windows[$id]["visible"],
                default     => $this->deleteWindow($id),
            };
        }
    }


    private function deleteWindow(int $id): void
    {
        unset($this->windows[$id]);
        if ($this->current === $id) {
            $this->current = null;
        }
    }


    /**
     * Defines a window or changes its attributes. A new window starts empty with the pen at row 0, column 0.
     *
     * @param list<int> $parameters
     */
    private function defineWindow(int $id, array $parameters): void
    {
        $relative = ($parameters[1] & 0x80) !== 0;
        $vertical = ($parameters[1] & 0x7F) / ($relative ? 100 : self::ABSOLUTE_ROWS);
        $point    = $parameters[3] >> 4;
        $row      = $vertical < 1 / 3 ? 0 : ($vertical > 2 / 3 ? 2 : 1);
        $column   = $point % 3;

        $this->windows[$id] = [
            "visible"  => ($parameters[0] & 0x20) !== 0,
            "rows"     => ($parameters[3] & 0x0F) + 1,
            "anchor"   => Alignment::from(array_search(3 * $row + $column, Cea708::ANCHOR_POINTS, true)),
            "priority" => $parameters[0] & 0x07,
        ] + ($this->windows[$id] ?? ["cells" => [], "row" => 0, "column" => 0, "pen" => self::DEFAULT_PEN]);
        $this->current = $id;
    }


    private function write(string $character): void
    {
        $window = &$this->window();
        if ($window !== null) {
            $window["cells"][$window["row"]][$window["column"]++] = ["char" => $character] + $window["pen"];
        }
    }


    private function &window(): ?array
    {
        $none = null;
        if ($this->current === null) {
            return $none;
        }

        return $this->windows[$this->current];
    }


    /**
     * Adds the text of the visible windows as a new state when it differs from the last state.
     * Windows with a higher priority, which is a lower number, come first.
     */
    private function record(float $time, array &$states): void
    {
        $windows = array_filter($this->windows, fn (array $window): bool => $window["visible"]);
        uasort($windows, fn (array $a, array $b): int => $a["priority"] <=> $b["priority"]);

        $lines     = [];
        $alignment = null;
        foreach ($windows as $window) {
            ksort($window["cells"]);
            foreach ($window["cells"] as $cells) {
                $line = $this->line($cells);
                if ($line !== "") {
                    $lines[]     = $line;
                    $alignment ??= $window["anchor"];
                }
            }
        }

        $last = $states === [] ? null : $states[count($states) - 1];
        if ($last === null ? $lines !== [] : $last["lines"] !== $lines) {
            $states[] = ["time" => $time, "lines" => $lines, "alignment" => $alignment];
        }
    }


    /**
     * Writes a row of cells as core markup without the spaces at both ends.
     */
    private function line(array $cells): string
    {
        ksort($cells);
        $runs = [];
        for ($column = 0, $last = $cells === [] ? -1 : max(array_keys($cells)); $column <= $last; $column++) {
            $cell   = $cells[$column] ?? ["char" => " "] + self::DEFAULT_PEN;
            $color  = $cell["color"];
            $runs[] = [$cell["char"], [
                "color" => $color === Cea708::WHITE ? null : "#" . Cea708::CHANNEL_HEX[$color >> 4] . Cea708::CHANNEL_HEX[$color >> 2 & 3] . Cea708::CHANNEL_HEX[$color & 3],
                "i"     => $cell["italic"],
                "u"     => $cell["underline"],
            ]];
        }

        return trim(StyleRuns::toMarkup($runs, true));
    }
}
