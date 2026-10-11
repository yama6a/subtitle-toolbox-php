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
     * The color names of TTML without "transparent". They are the 16 basic colors of CSS plus "cyan" and "magenta".
     *
     * @see https://www.w3.org/TR/ttml1/#style-value-namedColor
     */
    public const NAMED_COLORS = [
        "black"   => "#000000",
        "silver"  => "#c0c0c0",
        "gray"    => "#808080",
        "white"   => "#ffffff",
        "maroon"  => "#800000",
        "red"     => "#ff0000",
        "purple"  => "#800080",
        "fuchsia" => "#ff00ff",
        "magenta" => "#ff00ff",
        "green"   => "#008000",
        "lime"    => "#00ff00",
        "olive"   => "#808000",
        "yellow"  => "#ffff00",
        "navy"    => "#000080",
        "blue"    => "#0000ff",
        "teal"    => "#008080",
        "aqua"    => "#00ffff",
        "cyan"    => "#00ffff",
    ];

    // A TTML color in the hex, rgb() or rgba() form, or "transparent".
    private const TTML_COLOR = '/^(?:#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?|rgb\(\s*\d{1,3}(?:\s*,\s*\d{1,3}){2}\s*\)|rgba\(\s*\d{1,3}(?:\s*,\s*\d{1,3}){3}\s*\)|transparent)$/';


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
     * Returns the color of a <font> tag as "#rrggbb", or null. See colorHex() for the colors it reads.
     */
    public static function fontColorHex(string $attributes): ?string
    {
        $color = self::fontColor($attributes);

        return $color === null ? null : self::colorHex($color);
    }


    /**
     * Returns a color as "#rrggbb" in lower case, or null when it is not a color. This is the one color rule of all writers.
     * It reads the values of colorHexWithAlpha() and drops the alpha channel.
     */
    public static function colorHex(string $color): ?string
    {
        $hex = self::colorHexWithAlpha($color);

        return $hex === null ? null : substr($hex, 0, 7);
    }


    /**
     * Returns a color as "#rrggbb", or as "#rrggbbaa" when it has an alpha channel, in lower case. Returns null when it is not a color.
     * It reads "#rgb", a value that starts with "#rrggbb" or "#rrggbbaa", "rgb(r, g, b)", "rgba(r, g, b, a)"
     * and NAMED_COLORS, in any case and with entities decoded.
     * The alpha of rgba() is a fraction such as 0.5, a percentage, or an integer from 0 to 255 as in TTML.
     */
    public static function colorHexWithAlpha(string $color): ?string
    {
        $color = strtolower(trim(Markup::decodeEntities($color)));
        if (preg_match('/^#[0-9a-f]{6}(?:[0-9a-f]{2}(?![0-9a-f]))?/', $color, $hex) === 1) {
            return $hex[0];
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $digits) === 1) {
            return "#$digits[1]$digits[1]$digits[2]$digits[2]$digits[3]$digits[3]";
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(\d+(?:\.\d*)?|\.\d+)(%?)\s*)?\)$/', $color, $rgb) === 1) {
            $alpha = match (true) {
                !isset($rgb[4])            => null,
                $rgb[5] === "%"            => (float) $rgb[4] / 100 * 255,
                str_contains($rgb[4], ".") => (float) $rgb[4] * 255,
                default                    => (float) $rgb[4],
            };

            return max((int) $rgb[1], (int) $rgb[2], (int) $rgb[3], (int) round($alpha ?? 0)) > 255 ? null
                : sprintf("#%02x%02x%02x", $rgb[1], $rgb[2], $rgb[3]) . ($alpha === null ? "" : sprintf("%02x", round($alpha)));
        }

        return self::NAMED_COLORS[$color] ?? null;
    }


    /**
     * Returns a color for TTML and iTT output, or null when it is not a color.
     * A valid TTML color stays as it is, a named color in lower case. colorHexWithAlpha() converts other colors.
     */
    public static function ttmlColor(string $color): ?string
    {
        if (preg_match(self::TTML_COLOR, $color) === 1) {
            return $color;
        }
        $name = strtolower($color);
        if (isset(self::NAMED_COLORS[$name]) || $name === "transparent") {
            return $name;
        }

        return self::colorHexWithAlpha($color);
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
        $class = array_search(self::colorHex($color), self::WEBVTT_COLORS, true);

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
