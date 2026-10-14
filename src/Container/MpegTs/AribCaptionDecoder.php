<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Markup;

/**
 * Decodes the first language of an ARIB STD-B24 caption stream into the text that the screen shows over time.
 * Japanese ISDB streams use the 8-unit code with kanji, Brazilian ISDB-Tb streams the Latin sets of ABNT NBR 15606-1.
 *
 * Data groups and data units: ARIB STD-B24 volume 1 part 3 chapter 9. Codes and control functions: volume 1 part 2 chapter 7.
 * Adapted from xqq/libaribcaption, src/decoder/decoder_impl.cpp
 * (https://github.com/xqq/libaribcaption/blob/37dde0f32652c32aa98bf92278944d1cfb2e7354/src/decoder/decoder_impl.cpp), MIT.
 *
 * @internal
 */
final class AribCaptionDecoder
{
    private const JAPANESE = 0;
    private const LATIN    = 1;
    private const UTF8     = 2;

    // A wait of the TIME control counts in tenths of a second, in 90 kHz clock ticks.
    private const TENTH_SECOND = 9000;

    private const WHITE = "#ffffff";

    private const SPACES = [" ", "\u{3000}"];

    private int $encoding;

    private ?int $managementGroup = null;

    private int $time = 0;

    /** @var array<int, int> the graphic sets G0 to G3 */
    private array $sets = [];

    private int $left = 0;

    private int $right = 2;

    private int $format = 7;

    private int $planeWidth = 960;

    private int $planeHeight = 540;

    private int $areaX = 0;

    private int $areaY = 0;

    private int $areaWidth = 960;

    private int $areaHeight = 540;

    private int $charWidth = 36;

    private int $charHeight = 36;

    private int $spacingX = 4;

    private int $spacingY = 24;

    private float $scaleX = 1.0;

    private float $scaleY = 1.0;

    private bool $positioned = false;

    // The active position: the left edge of the next character and the bottom of its row.
    private int $x = 0;

    private int $y = 0;

    private int $palette = 0;

    private ?string $color = self::WHITE;

    private int $repeat = 0;

    /** @var array<int, array{hash: string, size: string, character: ?string}> */
    private array $glyphs = [];

    /** @var array<string, array{x: int, y: int, width: int, height: int, ruby: bool, color: ?string, text: string}> */
    private array $screen = [];

    /** @var list<array{int, list<string>, Alignment}> */
    private array $states = [];

    /** @var list<array{int, string}> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $warned = [];


    /**
     * @param string $language the ISO 639-2 language of the stream, which picks the coding until the caption management data does
     */
    public function __construct(string $language)
    {
        $this->encoding = self::encoding([[$language, 0]]);
        $this->reset();
    }


    /**
     * Reads the data of a PES packet with its time in 90 kHz clock ticks.
     */
    public function add(string $data, int $time): void
    {
        if (strlen($data) < 3 || $data[0] !== "\x80" || $data[1] !== "\xFF") {
            return;
        }

        $start = 3 + (ord($data[2]) & 0x0F);
        if ($start + 5 > strlen($data)) {
            return;
        }
        $id   = ord($data[$start]) >> 2;
        $size = unpack("n", $data, $start + 3)[1];
        $body = substr($data, $start + 5, $size);
        if ($body === "" || strlen($body) < $size) {
            return;
        }

        $this->time = max($time, $this->time);
        if (($id & 0x0F) === 0 && $id >> 5 !== $this->managementGroup) {
            $this->managementGroup = $id >> 5;
            $this->managementData($body);
        } elseif (($id & 0x0F) === 1) {
            $offset = 1 + (in_array(ord($body[0]) >> 6, [1, 2], true) ? 5 : 0);
            $this->dataUnits(substr($body, $offset + 3, self::uint24($body, $offset)));
        } else {
            return;
        }
        $this->snapshot();
    }


    /**
     * Returns the start time of each state of the screen, its lines and its alignment. A state without lines clears the screen.
     *
     * @return list<array{int, list<string>, Alignment}>
     */
    public function finish(): array
    {
        return $this->states;
    }


    /**
     * Returns the time and the message of each DRCS glyph that has no known character.
     *
     * @return list<array{int, string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }


    /**
     * Returns the first language of caption management data, or null. The reader names the track with it.
     */
    public static function language(string $data): ?string
    {
        if (strlen($data) < 3 || $data[0] !== "\x80") {
            return null;
        }
        $start = 3 + (ord($data[2]) & 0x0F);
        if ($start + 5 > strlen($data) || (ord($data[$start]) >> 2 & 0x0F) !== 0) {
            return null;
        }

        return self::languages(substr($data, $start + 5))[0][0] ?? null;
    }


    private function managementData(string $body): void
    {
        $offset    = 1 + (ord($body[0]) >> 6 === 2 ? 5 : 0);
        $languages = self::languages($body, $offset);
        if ($languages !== []) {
            $this->format = $languages[0][2] - 1;
            $encoding     = self::encoding($languages);
            if ($encoding !== $this->encoding) {
                $this->encoding = $encoding;
                $this->reset();
            } else {
                $this->resetSets();
                $this->resetFormat();
            }
        }
        $this->dataUnits(substr($body, $offset + 3, self::uint24($body, $offset)));
    }


    /**
     * Reads the language loop of caption management data. Moves $offset past it.
     *
     * @return list<array{string, int, int}> the language, the TCS and the format of each language
     */
    private static function languages(string $body, int &$offset = -1): array
    {
        if ($offset < 0) {
            $offset = $body === "" ? 0 : 1 + (ord($body[0]) >> 6 === 2 ? 5 : 0);
        }
        $count     = ord($body[$offset] ?? "\0");
        $offset   += 1;
        $languages = [];
        for ($index = 0; $index < $count && $offset + 5 <= strlen($body); $index++) {
            $display      = ord($body[$offset]) & 0x0F;
            $offset      += $display >= 0x0C && $display <= 0x0E ? 2 : 1;
            $flags        = ord($body[$offset + 3] ?? "\0");
            $languages[]  = [strtolower(substr($body, $offset, 3)), $flags >> 2 & 0x03, $flags >> 4];
            $offset      += 4;
        }

        return $languages;
    }


    /**
     * @param list<array{0: string, 1: int}> $languages
     */
    private static function encoding(array $languages): int
    {
        $codes = array_column($languages, 0);

        return match (true) {
            in_array(1, array_column($languages, 1), true)                          => self::UTF8,
            in_array("jpn", $codes, true)                                           => self::JAPANESE,
            array_intersect(["por", "spa"], $codes) !== []                           => self::LATIN,
            array_intersect(["eng", "tgl"], $codes) !== []                           => self::UTF8,
            default                                                                 => self::JAPANESE,
        };
    }


    private function dataUnits(string $units): void
    {
        for ($offset = 0; $offset + 5 <= strlen($units) && $units[$offset] === "\x1F"; $offset += 5 + $size) {
            $parameter = ord($units[$offset + 1]);
            $size      = self::uint24($units, $offset + 2);
            $unit      = substr($units, $offset + 5, $size);
            if (strlen($unit) < $size) {
                return;
            }
            if ($parameter === 0x20) {
                $this->text($unit);
            } elseif ($parameter === 0x30 || $parameter === 0x31) {
                $this->glyphs = AribDrcs::glyphs($unit, $parameter === 0x31) + $this->glyphs;
            }
        }
    }


    private function text(string $bytes): void
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $byte = ord($bytes[$offset]);
            if ($this->encoding === self::UTF8) {
                $offset += match (true) {
                    $byte <= 0x1F                                                       => $this->c0($bytes, $offset),
                    $byte === 0x7F                                                      => 1,
                    $byte === 0xC2 && ord($bytes[$offset + 1] ?? "\0") >= 0x80 && ord($bytes[$offset + 1]) <= 0x9F => 1 + $this->c1($bytes, $offset + 1),
                    default                                                             => $this->utf8($bytes, $offset),
                };
                continue;
            }

            $offset += match (true) {
                $byte <= 0x20 => $this->c0($bytes, $offset),
                $byte < 0x7F  => $this->graphic($bytes, $offset, $this->sets[$this->left]),
                $byte <= 0xA0 => $this->c1($bytes, $offset),
                $byte < 0xFF  => $this->graphic($bytes, $offset, $this->sets[$this->right]),
                default       => 1,
            };
        }
    }


    /**
     * Each control function returns the number of bytes it takes, at least 1.
     */
    private function c0(string $bytes, int $at): int
    {
        switch (ord($bytes[$at])) {
            case 0x08:
                $this->move(-1, 0);
                return 1;
            case 0x09:
                $this->move(1, 0);
                return 1;
            case 0x0A:
                $this->move(0, 1);
                return 1;
            case 0x0B:
                $this->move(0, -1);
                return 1;
            case 0x0C:
                $this->screen = [];
                $this->reset();
                return 1;
            case 0x0D:
                $this->newline();
                return 1;
            case 0x0E:
                $this->left = 1;
                return 1;
            case 0x0F:
                $this->left = 0;
                return 1;
            case 0x16:
                $this->move(self::byte($bytes, $at + 1) & 0x3F, 0);
                return 2;
            case 0x19:
                return 1 + $this->graphic($bytes, $at + 1, $this->sets[2]);
            case 0x1B:
                return 1 + $this->escape($bytes, $at + 1);
            case 0x1C:
                $this->setPosition(self::byte($bytes, $at + 2) & 0x3F, self::byte($bytes, $at + 1) & 0x3F);
                return 3;
            case 0x1D:
                return 1 + $this->graphic($bytes, $at + 1, $this->sets[3]);
            case 0x20:
                $this->put($this->encoding === self::JAPANESE && !$this->halfWidth() ? "\u{3000}" : " ");
                return 1;
            default:
                return 1;
        }
    }


    private function escape(string $bytes, int $at): int
    {
        $first = self::byte($bytes, $at);
        $index = $first - 0x28;
        switch (true) {
            case in_array($first, [0x6E, 0x6F], true):
                $this->left = $first - 0x6C;
                return 1;
            case in_array($first, [0x7C, 0x7D, 0x7E], true):
                $this->right = 0x7F - $first;
                return 1;
            case $first === 0x24 && self::byte($bytes, $at + 1) >= 0x28 && self::byte($bytes, $at + 1) <= 0x2B:
                $index = self::byte($bytes, $at + 1) - 0x28;
                if (self::byte($bytes, $at + 2) === 0x20) {
                    $this->sets[$index] = AribCharacters::DRCS | self::byte($bytes, $at + 3);
                    return 4;
                }
                $this->sets[$index] = self::byte($bytes, $at + 2);
                return 3;
            case $first === 0x24:
                $this->sets[0] = self::byte($bytes, $at + 1);
                return 2;
            case $index >= 0 && $index <= 3 && self::byte($bytes, $at + 1) === 0x20:
                $this->sets[$index] = AribCharacters::DRCS | self::byte($bytes, $at + 2);
                return 3;
            case $index >= 0 && $index <= 3:
                $this->sets[$index] = self::byte($bytes, $at + 1);
                return 2;
            default:
                return 1;
        }
    }


    private function c1(string $bytes, int $at): int
    {
        $code      = ord($bytes[$at]);
        $parameter = self::byte($bytes, $at + 1);
        switch (true) {
            case $code >= 0x80 && $code <= 0x87:
                $this->color = AribCharacters::color($this->palette, $code - 0x80);
                return 1;
            case $code === 0x88:
                [$this->scaleX, $this->scaleY] = [0.5, 0.5];
                return 1;
            case $code === 0x89:
                [$this->scaleX, $this->scaleY] = [0.5, 1.0];
                return 1;
            case $code === 0x8A:
                [$this->scaleX, $this->scaleY] = [1.0, 1.0];
                return 1;
            case $code === 0x8B:
                $this->scaleY = in_array($parameter, [0x41, 0x45], true) ? 2.0 : $this->scaleY;
                $this->scaleX = in_array($parameter, [0x44, 0x45], true) ? 2.0 : $this->scaleX;
                return 2;
            case $code === 0x90 && $parameter === 0x20:
                $this->palette = self::byte($bytes, $at + 2) & 0x07;
                return 3;
            case $code === 0x90:
                $this->color = ($parameter & 0xF0) === 0x40 ? AribCharacters::color($this->palette, $parameter & 0x0F) : $this->color;
                return 2;
            case $code === 0x92:
                return $parameter === 0x20 ? 3 : 2;
            case $code === 0x95:
                // A macro definition. TR-B14 does not use them, so the decoder skips it to its end code.
                $end = strpos($bytes, "\x95\x4F", $at + 1);
                return $end === false ? strlen($bytes) - $at : $end + 2 - $at;
            case $code === 0x98:
                $this->repeat = ($parameter & 0x3F) === 0 ? -1 : $parameter & 0x3F;
                return 2;
            case in_array($code, [0x91, 0x93, 0x94, 0x97], true):
                return 2;
            case $code === 0x9B:
                return 1 + $this->csi($bytes, $at + 1);
            case $code === 0x9D && $parameter === 0x20:
                $this->snapshot();
                $this->time += (self::byte($bytes, $at + 2) & 0x3F) * self::TENTH_SECOND;
                return 3;
            case $code === 0x9D && $parameter === 0x28:
                return 3;
            case $code === 0x9D:
                for ($end = $at + 2; $end < strlen($bytes) && (ord($bytes[$end]) < 0x40 || ord($bytes[$end]) > 0x7E); $end++);
                return min($end + 1, strlen($bytes)) - $at;
            default:
                return 1;
        }
    }


    /**
     * Reads a control sequence: decimal parameters split by 0x3B, then 0x20 and the final byte.
     */
    private function csi(string $bytes, int $at): int
    {
        $parameters = [];
        $value      = 0;
        for ($end = $at; $end < strlen($bytes) && $bytes[$end] !== " "; $end++) {
            $byte = ord($bytes[$end]);
            if ($byte === 0x3B) {
                $parameters[] = $value;
                $value        = 0;
            } elseif ($byte >= 0x30 && $byte <= 0x39) {
                $value = $value * 10 + $byte - 0x30;
            }
        }
        if ($end + 1 >= strlen($bytes)) {
            return strlen($bytes) - $at;
        }

        $parameters[] = $value;
        [$first, $second] = [$parameters[0], $parameters[1] ?? 0];
        switch (ord($bytes[$end + 1])) {
            case 0x53:
                $this->format = count($parameters) === 1 ? $first : $this->format;
                $this->resetFormat();
                break;
            case 0x56:
                [$this->areaWidth, $this->areaHeight] = [$first, $second];
                break;
            case 0x57:
                [$this->charWidth, $this->charHeight] = [$first, $second];
                break;
            case 0x58:
                $this->spacingX = $first;
                break;
            case 0x59:
                $this->spacingY = $first;
                break;
            case 0x5F:
                $this->areaX = $first;
                $this->areaY = count($parameters) > 1 ? $second : $this->areaY;
                if (!$this->positioned) {
                    $this->setPosition(0, 0);
                }
                break;
            case 0x61:
                [$this->x, $this->y, $this->positioned] = [$first, $second, true];
                break;
        }

        return $end + 2 - $at;
    }


    private function graphic(string $bytes, int $at, int $set): int
    {
        $count = AribCharacters::bytes($set);
        if ($at + $count > strlen($bytes)) {
            return strlen($bytes) - $at;
        }

        $first  = ord($bytes[$at]) & 0x7F;
        $second = $count === 2 ? ord($bytes[$at + 1]) & 0x7F : 0;
        if ($set === AribCharacters::MACRO) {
            if (isset(AribCharacters::MACROS[$first])) {
                $this->text(AribCharacters::MACROS[$first]);
            }
        } elseif ($set >= AribCharacters::DRCS) {
            $this->put($this->drcs($set << 16 | ($count === 2 ? $first << 8 | $second : $first)));
        } elseif ($first >= 0x21 && $first <= 0x7E && ($count === 1 || ($second >= 0x21 && $second <= 0x7E))) {
            $this->put(AribCharacters::character($set, $first, $second, $this->halfWidth(), $this->encoding === self::LATIN));
        }

        return $count;
    }


    private function utf8(string $bytes, int $at): int
    {
        $byte      = ord($bytes[$at]);
        $length    = $byte < 0x80 ? 1 : ($byte < 0xE0 ? 2 : ($byte < 0xF0 ? 3 : 4));
        $character = substr($bytes, $at, $length);
        if (!mb_check_encoding($character, "UTF-8") || strlen($character) < $length) {
            $this->put("\u{FFFD}");

            return 1;
        }

        $code = mb_ord($character);
        $this->put($code >= 0xEC00 && $code <= 0xF8FF ? $this->drcs(AribCharacters::DRCS_0 << 16 | $code) : $character);

        return $length;
    }


    private function drcs(int $key): string
    {
        $glyph = $this->glyphs[$key] ?? null;
        if ($glyph !== null && $glyph["character"] !== null) {
            return $glyph["character"];
        }

        $set  = ($key >> 16) - AribCharacters::DRCS_0;
        $code = sprintf($set === 0 ? "0x%04X" : "0x%02X", $key & 0xFFFF);
        $id   = $glyph["hash"] ?? "DRCS-$set $code";
        if (!isset($this->warned[$id])) {
            $this->warned[$id] = true;
            $this->warnings[]  = [$this->time, $glyph === null
                ? "The character $code of the set DRCS-$set has no glyph in the stream. The reader wrote U+3013 for it."
                : "The glyph of the character $code of the set DRCS-$set ({$glyph['size']} dots, MD5 {$glyph['hash']}) has no known character. The reader wrote U+3013 for it."];
        }

        return "\u{3013}";
    }


    private function put(string $text): void
    {
        if ($text === "") {
            return;
        }
        if (!$this->positioned) {
            $this->setPosition(0, 0);
        }

        $times        = $this->repeat > 0 ? $this->repeat : 1;
        $times        = $this->repeat < 0 ? max(1, intdiv($this->areaX + $this->areaWidth - $this->x, max(1, $this->sectionWidth()))) : $times;
        $this->repeat = 0;
        $ruby         = $this->encoding === self::JAPANESE
                        && (($this->scaleX === 0.5 && $this->scaleY === 0.5) || ($this->charWidth === 18 && $this->charHeight === 18));
        for ($index = 0; $index < $times; $index++) {
            $top                             = $this->y - $this->sectionHeight();
            $this->screen["$this->x,$top"] = [
                "x"      => $this->x,
                "y"      => $top,
                "width"  => $this->sectionWidth(),
                "height" => $this->sectionHeight(),
                "ruby"   => $ruby,
                "color"  => $this->color,
                "text"   => $text,
            ];
            $this->move(1, 0);
        }
    }


    private function halfWidth(): bool
    {
        return $this->scaleX * 2 === $this->scaleY;
    }


    private function sectionWidth(): int
    {
        return (int) floor(($this->charWidth + $this->spacingX) * $this->scaleX);
    }


    private function sectionHeight(): int
    {
        return (int) floor(($this->charHeight + $this->spacingY) * $this->scaleY);
    }


    private function setPosition(int $column, int $row): void
    {
        $this->x          = $this->areaX + $column * $this->sectionWidth();
        $this->y          = $this->areaY + ($row + 1) * $this->sectionHeight();
        $this->positioned = true;
    }


    private function newline(): void
    {
        if (!$this->positioned) {
            $this->setPosition(0, 0);
        }
        $this->x  = $this->areaX;
        $this->y += $this->sectionHeight();
    }


    /**
     * Moves the active position by whole cells. It wraps at the edges of the display area, as STD-B24 section 7.2.5 says.
     */
    private function move(int $columns, int $rows): void
    {
        if (!$this->positioned) {
            $this->setPosition(0, 0);
        }
        $width  = max(1, $this->sectionWidth());
        $height = max(1, $this->sectionHeight());
        for (; $columns < 0; $columns++) {
            $this->x -= $width;
            if ($this->x < $this->areaX) {
                $this->x = $this->areaX + $this->areaWidth - $width;
                $rows--;
            }
        }
        for (; $columns > 0; $columns--) {
            $this->x += $width;
            if ($this->x >= $this->areaX + $this->areaWidth) {
                $this->x = $this->areaX;
                $rows++;
            }
        }
        for (; $rows < 0; $rows++) {
            $this->y -= $height;
            $this->y  = $this->y < $this->areaY ? $this->areaY + $this->areaHeight : $this->y;
        }
        for (; $rows > 0; $rows--) {
            $this->y += $height;
            $this->y  = $this->y > $this->areaY + $this->areaHeight ? $this->areaY + $height : $this->y;
        }
    }


    /**
     * The state after a clear screen: the default sets, writing format, size and color.
     */
    private function reset(): void
    {
        $this->resetSets();
        $this->resetFormat();
        [$this->areaX, $this->areaY, $this->x, $this->y, $this->positioned] = [0, 0, 0, 0, false];
        // Latin captions are middle size by default, Japanese captions normal size.
        [$this->scaleX, $this->scaleY] = $this->encoding === self::LATIN ? [0.5, 1.0] : [1.0, 1.0];
        [$this->palette, $this->color, $this->repeat] = [0, self::WHITE, 0];
    }


    private function resetSets(): void
    {
        $this->sets = $this->encoding === self::LATIN
            ? [AribCharacters::ALPHANUMERIC, AribCharacters::ALPHANUMERIC, AribCharacters::LATIN_EXTENSION, AribCharacters::LATIN_SPECIAL]
            : [AribCharacters::KANJI, AribCharacters::ALPHANUMERIC, AribCharacters::HIRAGANA, AribCharacters::MACRO];
        [$this->left, $this->right] = [0, 2];
    }


    /**
     * Sets the plane, the display area and the character size of the writing format of SWF, as ARIB TR-B14 profile A gives them.
     */
    private function resetFormat(): void
    {
        [$width, $height, $spacingX, $spacingY] = match ($this->format) {
            5       => [1920, 1080, 4, 24],
            8       => [960, 540, 12, 24],
            9       => [720, 480, 4, 16],
            10      => [720, 480, 8, 24],
            default => [960, 540, 4, 24],
        };
        [$this->planeWidth, $this->planeHeight, $this->areaWidth, $this->areaHeight] = [$width, $height, $width, $height];
        [$this->charWidth, $this->charHeight] = [36, 36];
        [$this->spacingX, $this->spacingY]    = $this->encoding === self::LATIN ? [2, 16] : [$spacingX, $spacingY];
    }


    private function snapshot(): void
    {
        $state = [$this->time, ...$this->render()];
        $last  = array_key_last($this->states);
        if ($last !== null && $this->states[$last][0] === $this->time) {
            $this->states[$last] = $state;
            $previous            = $this->states[$last - 1] ?? null;
            if ($previous !== null && [$previous[1], $previous[2]] === [$state[1], $state[2]]) {
                array_pop($this->states);
            }
        } elseif ($last === null || [$this->states[$last][1], $this->states[$last][2]] !== [$state[1], $state[2]]) {
            $this->states[] = $state;
        }
    }


    /**
     * Returns the lines of the screen from top to bottom and its alignment. Small characters above a row are furigana:
     * they become <ruby> markup on the characters of the row below them that they cover.
     *
     * @return array{list<string>, Alignment}
     */
    private function render(): array
    {
        $rows  = [];
        $rubys = [];
        foreach ($this->screen as $character) {
            if ($character["ruby"]) {
                $rubys[$character["y"]][] = $character;
            } else {
                $rows[$character["y"]][] = $character;
            }
        }
        if ($rows === [] && $rubys === []) {
            return [[], Alignment::BottomCenter];
        }
        ksort($rows);
        ksort($rubys);

        $runs = [];
        foreach ($rubys as $y => $characters) {
            usort($characters, fn (array $a, array $b): int => $a["x"] <=> $b["x"]);
            foreach ($characters as $character) {
                $last = array_key_last($runs);
                if ($last !== null && $runs[$last]["y"] === $y && $runs[$last]["end"] === $character["x"]) {
                    $runs[$last]["end"]  += $character["width"];
                    $runs[$last]["text"] .= $character["text"];
                } else {
                    $runs[] = ["y" => $y, "start" => $character["x"], "end" => $character["x"] + $character["width"], "text" => $character["text"]];
                }
            }
        }

        $readings = [];
        $lines    = [];
        foreach ($rows as $y => $characters) {
            usort($characters, fn (array $a, array $b): int => $a["x"] <=> $b["x"]);
            $rows[$y] = $characters;
        }
        foreach ($runs as $index => $run) {
            $base = array_values(array_filter(array_keys($rows), fn (int $y): bool => $y > $run["y"]))[0] ?? null;
            if ($base === null || !$this->attach($rows[$base], $readings[$base], $run, $index)) {
                $lines[$run["y"]][] = Markup::escapeText(trim($run["text"]));
            }
        }
        foreach ($rows as $y => $characters) {
            $line = $this->line($characters, $readings[$y] ?? [], $runs);
            if ($line !== "") {
                $lines[$y][] = $line;
            }
        }
        ksort($lines);

        return [array_merge(...array_values($lines)), $this->alignment()];
    }


    /**
     * Gives the characters of $row that $run covers the reading $index. The middle of a character must lie under the run,
     * or else the character must overlap it.
     *
     * @param list<array{x: int, width: int}> $row
     * @param ?array<int, int>                $readings
     * @param array{start: int, end: int}     $run
     */
    private function attach(array $row, ?array &$readings, array $run, int $index): bool
    {
        $readings ??= [];
        foreach ([true, false] as $byMiddle) {
            $found = false;
            foreach ($row as $position => $character) {
                $middle = $character["x"] + $character["width"] / 2;
                $covers = $byMiddle ? $middle >= $run["start"] && $middle < $run["end"]
                    : $character["x"] < $run["end"] && $character["x"] + $character["width"] > $run["start"];
                if ($covers && !isset($readings[$position])) {
                    $readings[$position] = $index;
                    $found               = true;
                }
            }
            if ($found) {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns the text of a row with <font> tags for colors other than white and <ruby> tags for furigana. A gap of at least
     * half a cell between characters becomes a space. Spaces at the ends of the row drop.
     *
     * @param list<array{x: int, width: int, color: ?string, text: string}> $row
     * @param array<int, int>                                                $readings
     * @param list<array{text: string}>                                      $runs
     */
    private function line(array $row, array $readings, array $runs): string
    {
        $first = 0;
        $last  = count($row) - 1;
        for (; $first <= $last && !isset($readings[$first]) && in_array($row[$first]["text"], self::SPACES, true); $first++);
        for (; $last >= $first && !isset($readings[$last]) && in_array($row[$last]["text"], self::SPACES, true); $last--);

        $text  = "";
        $open  = self::WHITE;
        $end   = null;
        for ($position = $first; $position <= $last;) {
            $character = $row[$position];
            $run       = $readings[$position] ?? null;
            $base      = "";
            do {
                $base .= $row[$position]["text"];
                $right = $row[$position]["x"] + $row[$position]["width"];
                $position++;
            } while ($run !== null && $position <= $last && ($readings[$position] ?? null) === $run);

            $gap   = $end !== null && $character["x"] - $end >= $character["width"] / 2 ? " " : "";
            $color = $character["color"] ?? self::WHITE;
            if ($color !== $open) {
                $text .= ($open === self::WHITE ? "" : "</font>") . $gap . ($color === self::WHITE ? "" : "<font color=\"$color\">");
                $gap   = "";
                $open  = $color;
            }
            $text .= $gap . ($run === null ? Markup::escapeText($base)
                : "<ruby>" . Markup::escapeText($base) . "<rt>" . Markup::escapeText($runs[$run]["text"]) . "</rt></ruby>");
            $end   = $right;
        }

        return $text === "" || $open === self::WHITE ? $text : "$text</font>";
    }


    /**
     * The middle of the text on the caption plane gives the alignment: the left, middle or right third and the top,
     * middle or bottom third.
     */
    private function alignment(): Alignment
    {
        $left   = min(array_column($this->screen, "x"));
        $right  = max(array_map(fn (array $character): int => $character["x"] + $character["width"], $this->screen));
        $top    = min(array_column($this->screen, "y"));
        $bottom = max(array_map(fn (array $character): int => $character["y"] + $character["height"], $this->screen));
        $middle = ($left + $right) * 3 / 2;
        $height = ($top + $bottom) * 3 / 2;
        $column = $middle < $this->planeWidth ? 1 : ($middle >= 2 * $this->planeWidth ? 3 : 2);
        $row    = $height < $this->planeHeight ? 6 : ($height >= 2 * $this->planeHeight ? 0 : 3);

        return Alignment::from($row + $column);
    }


    private static function byte(string $bytes, int $at): int
    {
        return ord($bytes[$at] ?? "\0");
    }


    private static function uint24(string $bytes, int $at): int
    {
        return self::byte($bytes, $at) << 16 | self::byte($bytes, $at + 1) << 8 | self::byte($bytes, $at + 2);
    }
}
