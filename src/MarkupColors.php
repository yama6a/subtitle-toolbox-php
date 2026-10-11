<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * @internal
 */
final class MarkupColors
{
    /**
     * The 8 color classes of WebVTT and their colors, in the order of the default style sheet of the spec.
     *
     * @see https://www.w3.org/TR/webvtt1/#default-text-color
     */
    public const WEBVTT_COLORS = [
        "white"   => "#ffffff",
        "lime"    => "#00ff00",
        "cyan"    => "#00ffff",
        "red"     => "#ff0000",
        "yellow"  => "#ffff00",
        "magenta" => "#ff00ff",
        "blue"    => "#0000ff",
        "black"   => "#000000",
    ];


    /**
     * Returns the color attribute of a <font> tag as written, such as "#FF0000" for <FONT COLOR='#FF0000'>, or null.
     * $attributes holds the attributes or the whole tag.
     * The value can have double quotes, single quotes or no quotes, and the name can have any case.
     */
    public static function fontColor(string $attributes): ?string
    {
        if (!preg_match("/\bcolor\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s\"'>]+))/i", $attributes, $color)) {
            return null;
        }

        return ($color[1] ?? "") . ($color[2] ?? "") . ($color[3] ?? "");
    }


    /**
     * Returns the text color and the background color of WebVTT classes such as ".bg_black.yellow", or null for none.
     * When several color classes apply, the last one in the default style sheet wins, as in a browser.
     *
     * @return array{color: ?string, background: ?string} colors as "#rrggbb"
     */
    public static function webVttClassColors(string $classes): array
    {
        $names  = explode(".", $classes);
        $colors = ["color" => null, "background" => null];
        foreach (self::WEBVTT_COLORS as $name => $hex) {
            if (in_array($name, $names, true)) {
                $colors["color"] = $hex;
            }
            if (in_array("bg_$name", $names, true)) {
                $colors["background"] = $hex;
            }
        }

        return $colors;
    }


    /**
     * Turns WebVTT <c> tags with a color class into <font> tags, for formats that write <font color>.
     * For example "<c.yellow>Hi</c>" becomes "<font color=\"#ffff00\">Hi</font>". Other <c> tags stay as they are.
     */
    public static function webVttColorsToFont(string $text): string
    {
        if (!str_contains($text, "<c")) {
            return $text;
        }

        $tokens = Markup::splitTags($text);
        $open   = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                continue;
            }
            if (preg_match('/^<c((?:\.[^\s.<>]*)*)(?:\s[^<>]*)?>$/', $token, $matches) === 1) {
                $color          = self::webVttClassColors($matches[1])["color"];
                $open[]         = $color !== null;
                $tokens[$index] = $color === null ? $token : "<font color=\"$color\">";
            } elseif (preg_match('/^<\/c\s*>$/', $token) === 1 && array_pop($open) === true) {
                $tokens[$index] = "</font>";
            }
        }

        return implode("", $tokens);
    }


    /**
     * Turns <font> tags into WebVTT <c> tags, for example <font color="#ffff00"> becomes <c.yellow>.
     * It drops a <font> tag whose color is not one of WEBVTT_COLORS, and a <font> tag without a color.
     *
     * @return array{string, list<string>} the text and each dropped color as written
     */
    public static function fontToWebVttColors(string $text): array
    {
        if (stripos($text, "<font") === false) {
            return [$text, []];
        }

        $tokens  = Markup::splitTags($text);
        $open    = [];
        $dropped = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                continue;
            }
            if (preg_match('/^<font\b/i', $token) === 1) {
                $color = self::fontColor($token);
                $class = $color === null ? null : self::webVttColorClass($color);
                if ($class === null && $color !== null && trim($color) !== "") {
                    $dropped[] = $color;
                }
                $open[]         = $class !== null;
                $tokens[$index] = $class === null ? "" : "<c.$class>";
            } elseif (preg_match('/^<\/font\s*>$/i', $token) === 1) {
                $tokens[$index] = array_pop($open) === true ? "</c>" : "";
            }
        }

        return [implode("", $tokens), $dropped];
    }


    /**
     * Returns the WebVTT class of a color such as "#FFFF00", "#ff0" or "yellow", or null when no class has the color.
     */
    private static function webVttColorClass(string $color): ?string
    {
        $color = strtolower(trim(Markup::decodeEntities($color)));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $digits) === 1) {
            $color = "#$digits[1]$digits[1]$digits[2]$digits[2]$digits[3]$digits[3]";
        }
        if (isset(self::WEBVTT_COLORS[$color])) {
            return $color;
        }

        $class = array_search($color, self::WEBVTT_COLORS, true);

        return $class === false ? null : $class;
    }


    /**
     * Swaps the first and the last byte of a 6-digit hex color. ASS and MicroDVD write colors in BGR order.
     * For example, "0000FF" in BGR order becomes "FF0000" in RGB.
     */
    public static function bgrToRgb(string $bgr): string
    {
        return substr($bgr, 4, 2) . substr($bgr, 2, 2) . substr($bgr, 0, 2);
    }


    /**
     * Swaps the first and the last byte of a 6-digit hex color.
     * For example, "FF0000" in RGB order becomes "0000FF" in BGR.
     */
    public static function rgbToBgr(string $rgb): string
    {
        return self::bgrToRgb($rgb);
    }
}
