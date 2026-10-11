<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;

/**
 * Encodes lines of core markup as CEA-608 cells and byte pairs.
 * The byte pairs are PACs (preamble address codes), tab offsets, mid-row codes and characters.
 *
 * @see https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml
 *
 * @internal
 */
final class Cea608Encoder
{
    private const NAMED_COLORS = [
        "white" => 0, "lime" => 1, "blue" => 2, "cyan" => 3, "aqua" => 3, "red" => 4, "yellow" => 5, "magenta" => 6, "fuchsia" => 6,
    ];

    private const DEFAULT_ATTRIBUTES = ["color" => Cea608::WHITE, "italic" => false, "underline" => false];


    /**
     * Splits a line of core markup into characters with their color, italics and underline.
     * The spaces at both ends of the line drop.
     *
     * @return list<array{char: string, color: int, italic: bool, underline: bool}>
     */
    public static function styledCharacters(string $line): array
    {
        $style      = ["italic" => 0, "underline" => 0, "colors" => []];
        $characters = [];
        foreach (Markup::splitTags(MarkupColors::webVttColorsToFont($line)) as $index => $part) {
            $isTag = $index % 2 === 1;
            if ($isTag && preg_match("/^<\s*(\/?)\s*([a-z]+)\b([^>]*)>$/i", $part, $tag)) {
                self::applyTag($style, strtolower($tag[2]), $tag[1] === "/", $tag[3]);
                continue;
            }
            if ($isTag || $part === "") {
                continue;
            }

            $text = str_replace(["\u{A0}", "\t"], " ", Markup::decodeEntities($part));
            foreach (Markup::characters($text) as $character) {
                $characters[] = [
                    "char"      => $character,
                    "color"     => end($style["colors"]) ?: Cea608::WHITE,
                    "italic"    => $style["italic"] > 0,
                    "underline" => $style["underline"] > 0,
                ];
            }
        }

        while ($characters !== [] && $characters[0]["char"] === " ") {
            array_shift($characters);
        }
        while ($characters !== [] && $characters[count($characters) - 1]["char"] === " ") {
            array_pop($characters);
        }

        return $characters;
    }


    /**
     * Counts the open i and u tags and keeps the stack of font colors.
     *
     * @param array{italic: int, underline: int, colors: list<int>} $style
     */
    private static function applyTag(array &$style, string $name, bool $closing, string $attributes): void
    {
        switch ($name) {
            case "i":
                $style["italic"] = max(0, $style["italic"] + ($closing ? -1 : 1));
                break;
            case "u":
                $style["underline"] = max(0, $style["underline"] + ($closing ? -1 : 1));
                break;
            case "font":
                if ($closing) {
                    array_pop($style["colors"]);
                } else {
                    $style["colors"][] = self::fontColor($attributes) ?? end($style["colors"]) ?: Cea608::WHITE;
                }
                break;
        }
    }


    private static function fontColor(string $attributes): ?int
    {
        $color = MarkupColors::fontColor($attributes);
        if ($color === null) {
            return null;
        }

        $color = strtolower(trim(Markup::decodeEntities($color)));
        $index = array_search(substr($color, 0, 7), Cea608::COLORS, true);

        return $index !== false ? $index : self::NAMED_COLORS[$color] ?? null;
    }


    /**
     * Turns the characters into cells. A style change takes a mid-row code, which shows as a space.
     * The code replaces the space before the change when there is one.
     * Styles that need more than 32 cells are dropped for the line.
     *
     * @return list<array{char?: string, midRow?: int}>
     */
    public static function cells(array $characters): array
    {
        $cells   = [];
        $current = self::DEFAULT_ATTRIBUTES;
        foreach ($characters as $character) {
            $wanted = ["color" => $character["color"], "italic" => $character["italic"], "underline" => $character["underline"]];
            if ($character["char"] !== " " && $wanted !== $current) {
                $codes = self::midRowCodes($current, $wanted);
                for ($index = 0; $index < count($codes) && $cells !== [] && ($cells[count($cells) - 1]["char"] ?? null) === " "; $index++) {
                    array_pop($cells);
                }
                foreach ($codes as $code) {
                    $cells[] = ["midRow" => $code];
                }
                $current = $wanted;
            }
            $cells[] = ["char" => $character["char"]];
        }

        if (count($cells) > Cea608::COLUMNS) {
            return array_map(fn (array $character): array => ["char" => $character["char"]], $characters);
        }

        return $cells;
    }


    /**
     * Returns the second bytes of the mid-row codes that change the style.
     * A color code turns italics off, and the italics code keeps the color, as 47 CFR 15.119 (h)(1)(ii) says.
     *
     * @return list<int>
     */
    private static function midRowCodes(array $from, array $to): array
    {
        if ($to["color"] !== $from["color"] || ($from["italic"] && !$to["italic"]) || (!$to["italic"] && $to["underline"] !== $from["underline"])) {
            $codes = [Cea608::encodeMidRow($to["color"], !$to["italic"] && $to["underline"])];
            if ($to["italic"]) {
                $codes[] = Cea608::encodeMidRow(null, $to["underline"]);
            }

            return $codes;
        }

        return [Cea608::encodeMidRow(null, $to["underline"])];
    }


    public static function leadingMidRowCount(array $cells): int
    {
        $count = 0;
        while (isset($cells[$count]["midRow"])) {
            $count++;
        }

        return $count;
    }


    /**
     * Returns the words of one row: the PAC, a tab offset, the mid-row codes and the characters.
     * Control codes and special characters go twice. A single standard character before a code gets the filler byte 0x80.
     *
     * @return list<int>
     */
    public static function rowWords(int $row, int $column, array $cells): array
    {
        [$pac, $start, $cells] = self::pac($row, $column, $cells);

        $words = [self::word(...$pac), self::word(...$pac)];
        if ($start % Cea608::PAC_INDENT_STEP > 0) {
            $tab   = self::word(Cea608::FIRST_BYTE_TAB_OFFSET, Cea608::TAB_OFFSET_BASE + $start % Cea608::PAC_INDENT_STEP);
            $words = [...$words, $tab, $tab];
        }

        return [...$words, ...self::cellWords($cells)];
    }


    /**
     * Returns the PAC bytes, the column that the PAC and the tab offset reach, and the cells after the PAC.
     * When the leading mid-row codes do not fit before $column, the PAC takes over their style at column 0.
     *
     * @return array{array{int, int}, int, array}
     */
    private static function pac(int $row, int $column, array $cells): array
    {
        $leading = self::leadingMidRowCount($cells);
        $start   = $column - $leading;
        if ($start >= 0) {
            return [Cea608::encodePac($row, intdiv($start, Cea608::PAC_INDENT_STEP) * Cea608::PAC_INDENT_STEP), $start, $cells];
        }

        $attributes = self::DEFAULT_ATTRIBUTES;
        for ($index = 0; $index < $leading; $index++) {
            $midRow     = Cea608::decodeMidRow($cells[$index]["midRow"]);
            $attributes = ["color" => $midRow["color"] ?? $attributes["color"], "italic" => $midRow["italic"], "underline" => $midRow["underline"]];
        }

        $cells = array_slice($cells, $leading);
        if ($attributes["italic"] && $attributes["color"] !== Cea608::WHITE) {
            array_unshift($cells, ["midRow" => Cea608::encodeMidRow(null, $attributes["underline"])]);

            return [Cea608::encodePac($row, 0, $attributes["color"]), 0, $cells];
        }

        return [Cea608::encodePac($row, 0, $attributes["color"], $attributes["italic"], $attributes["underline"]), 0, $cells];
    }


    /**
     * Returns the words of the mid-row codes and the characters. Two standard characters share a word.
     *
     * @return list<int>
     */
    private static function cellWords(array $cells): array
    {
        $words   = [];
        $pending = null;
        foreach ($cells as $cell) {
            $code = isset($cell["midRow"]) ? ["pair" => [Cea608::FIRST_BYTE_MID_ROW, $cell["midRow"]]] : Cea608::encodeCharacter($cell["char"]);
            if (isset($code["byte"])) {
                if ($pending === null) {
                    $pending = $code["byte"];
                } else {
                    $words[] = self::word($pending, $code["byte"]);
                    $pending = null;
                }
            }
            if (isset($code["pair"])) {
                if ($pending !== null) {
                    $words[] = self::word($pending, 0x00);
                    $pending = null;
                }
                $pair  = self::word(...$code["pair"]);
                $words = [...$words, $pair, $pair];
            }
        }
        if ($pending !== null) {
            $words[] = self::word($pending, 0x00);
        }

        return $words;
    }


    public static function word(int $first, int $second): int
    {
        return (Cea608::withParity($first) << 8) | Cea608::withParity($second);
    }
}
