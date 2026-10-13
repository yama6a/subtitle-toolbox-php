<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\LineWrapper;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;

/**
 * Encodes cues as CEA-708 service 1 pop-on captions: each cue loads into a hidden window, which shows at the cue start.
 * Two windows take turns, so the next cue loads while the current one shows.
 *
 * @see https://en.wikipedia.org/wiki/CEA-708
 *
 * @internal
 */
final class Cea708Encoder
{
    private const SERVICE = 1;

    // A service block holds at most 31 bytes.
    private const MAX_BLOCK = 31;

    // A 16:9 window holds 42 columns. The window definition holds at most 16 rows and 64 columns.
    private const COLUMNS     = 42;
    private const MAX_ROWS    = 16;
    private const MAX_COLUMNS = 64;

    // Window style 1 and pen style 1 are the predefined styles of a pop-on caption.
    private const WINDOW_AND_PEN_STYLE = 0x09;

    // Relative anchor positions in percent of the screen for the top, middle and bottom row, and the left, center and right column.
    private const ANCHOR_ROWS    = [10, 50, 90];
    private const ANCHOR_COLUMNS = [10, 50, 90];

    // The justify bits of the window attributes for the left, center and right column, with a transparent fill and
    // a scroll direction from bottom to top.
    private const JUSTIFY = [0x0C, 0x0E, 0x0D];
    private const TRANSPARENT_FILL = 0xC0;

    // A solid black pen background.
    private const BACKGROUND = 0x00;

    /** @var array<int, list<string>> the commands of each frame */
    private array $frames = [];

    private int $capacity;


    /**
     * Returns the service blocks of service 1 by frame. $frameOf turns seconds into a frame number.
     * A block holds whole commands and at most $capacity bytes.
     *
     * @param callable(float): int $frameOf
     * @return array<int, string>
     */
    public function blocks(Subtitle $subtitle, callable $frameOf, int $capacity): array
    {
        $this->frames   = [];
        $this->capacity = min($capacity, self::MAX_BLOCK);
        $previous       = null;
        $lastShow       = PHP_INT_MIN;
        $index          = 0;
        foreach ($subtitle->getCues() as $cue) {
            $lines = self::lines($cue->getLines());
            if ($lines === []) {
                continue;
            }

            $window = $index++ % 2;
            $start  = $frameOf($cue->getStart());
            if ($previous !== null && $previous["end"] < $start) {
                $this->place([self::command(Cea708::DLW, 1 << $previous["window"])], $previous["end"]);
                $previous = null;
            }

            $start = $this->placeBefore($this->load($window, $lines, $cue->getAlignment()), $start, $lastShow);
            $show  = ($previous === null ? "" : self::command(Cea708::DLW, 1 << $previous["window"])) . self::command(Cea708::DSW, 1 << $window);
            $start = $this->place([$show], $start);

            $previous = ["window" => $window, "end" => max($start + 1, $frameOf($cue->getEnd()))];
            $lastShow = $start;
        }
        if ($previous !== null) {
            $this->place([self::command(Cea708::DLW, 1 << $previous["window"])], $previous["end"]);
        }

        ksort($this->frames);

        return array_map(fn (array $commands): string => implode("", $commands), $this->frames);
    }


    /**
     * Returns the commands that define the window hidden and write the lines into it.
     *
     * @param list<list<array{char: string, color: int, italic: bool, underline: bool}>> $lines
     * @return list<string>
     */
    private function load(int $window, array $lines, Alignment $alignment): array
    {
        $point   = Cea708::ANCHOR_POINTS[$alignment->value];
        $columns = max(array_map(count(...), $lines));
        $commands = [
            self::command(Cea708::DLW, 1 << $window),
            self::command(Cea708::DF0 + $window, 0x18, 0x80 | self::ANCHOR_ROWS[intdiv($point, 3)], self::ANCHOR_COLUMNS[$point % 3],
                          $point << 4 | (count($lines) - 1), $columns - 1, self::WINDOW_AND_PEN_STYLE),
            self::command(Cea708::SWA, self::TRANSPARENT_FILL, 0x00, self::JUSTIFY[$point % 3], 0x00),
        ];
        $pen = null;
        foreach ($lines as $row => $characters) {
            $commands[] = self::command(Cea708::SPL, $row, 0);
            foreach ($characters as $character) {
                $style = [$character["italic"], $character["underline"], $character["color"]];
                if ($style !== $pen && $character["char"] !== " ") {
                    $commands[] = self::command(Cea708::SPA, 0x01, ($character["italic"] ? 0x80 : 0) | ($character["underline"] ? 0x40 : 0));
                    $commands[] = self::command(Cea708::SPC, self::color($character["color"]), self::BACKGROUND, 0x00);
                    $pen        = $style;
                }
                $commands[] = self::character($character["char"]);
            }
        }

        return $commands;
    }


    /**
     * Splits the cue text into styled characters. A line longer than 42 characters wraps once.
     * A window holds at most 16 rows, so the lines after the 16th drop.
     *
     * @param list<string> $text
     * @return list<list<array{char: string, color: int, italic: bool, underline: bool}>>
     */
    private static function lines(array $text, bool $wrapped = false): array
    {
        $text  = array_map(Markup::rubyAsText(...), $text);
        $lines = [];
        foreach (Markup::carryOpenTags($text) as $line) {
            $characters = Cea608Encoder::styledCharacters($line);
            if (count($characters) > self::COLUMNS && !$wrapped) {
                return self::lines(LineWrapper::wrap($text, self::COLUMNS, self::MAX_ROWS), true);
            }
            if ($characters !== []) {
                $lines[] = array_slice($characters, 0, self::MAX_COLUMNS);
            }
        }

        return array_slice($lines, 0, self::MAX_ROWS);
    }


    /**
     * Returns the bytes of a character: G0, the music note, G1, G2 after EXT1, or the CEA-608 replacement of a
     * character that neither set holds.
     */
    private static function character(string $character): string
    {
        $code = mb_ord($character, "UTF-8");
        if ($code >= 0x20 && $code < 0x7F || $code >= 0xA0 && $code <= 0xFF) {
            return chr($code);
        }
        if ($character === "\u{266A}") {
            return chr(Cea708::MUSIC_NOTE);
        }
        $g2 = array_search($character, Cea708::G2, true);
        if ($g2 !== false && $g2 > 0x21) {
            return chr(Cea708::EXT1) . chr($g2);
        }

        return implode("", array_map(self::character(...), mb_str_split(Cea608::transliterate($character) ?? "?", 1, "UTF-8")));
    }


    /**
     * Returns the 6-bit color of a CEA-608 color: 2 bits for each of red, green and blue.
     */
    private static function color(int $color608): int
    {
        $hex = Cea608::COLORS[$color608];

        return intdiv(hexdec(substr($hex, 1, 2)), 85) << 4 | intdiv(hexdec(substr($hex, 3, 2)), 85) << 2 | intdiv(hexdec(substr($hex, 5, 2)), 85);
    }


    private static function command(int $code, int ...$parameters): string
    {
        return pack("C*", $code, ...$parameters);
    }


    /**
     * Puts the commands into the free bytes of the frames after $after and before $before, the last command as late as
     * possible. Returns $before, or a later frame when the frames between are too few. Then the commands follow $after.
     *
     * @param list<string> $commands
     */
    private function placeBefore(array $commands, int $before, int $after): int
    {
        $assigned = [];
        $used     = [];
        $frame    = $before - 1;
        for ($index = count($commands) - 1; $index >= 0; $index--) {
            while ($frame > $after && $frame >= 0 && $this->free($frame) - ($used[$frame] ?? 0) < strlen($commands[$index])) {
                $frame--;
            }
            if ($frame <= $after || $frame < 0) {
                return $this->place($commands, max($after + 1, 0)) + 1;
            }
            $assigned[$index] = $frame;
            $used[$frame]     = ($used[$frame] ?? 0) + strlen($commands[$index]);
        }
        ksort($assigned);
        foreach ($assigned as $index => $frame) {
            $this->frames[$frame][] = $commands[$index];
        }

        return $before;
    }


    /**
     * Puts the commands into the free bytes of the frames from $from on, in order. Returns the frame of the last command.
     *
     * @param list<string> $commands
     */
    private function place(array $commands, int $from): int
    {
        $frame = $from;
        foreach ($commands as $command) {
            while ($this->free($frame) < strlen($command)) {
                $frame++;
            }
            $this->frames[$frame][] = $command;
        }

        return $frame;
    }


    private function free(int $frame): int
    {
        return $this->capacity - array_sum(array_map(strlen(...), $this->frames[$frame] ?? []));
    }
}
