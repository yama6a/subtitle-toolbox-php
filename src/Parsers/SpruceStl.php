<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Alignment;

/**
 * The commands of Spruce STL that the parser and the formatter share.
 * A command such as "$Italic = True" applies to every later subtitle until the same command comes again.
 *
 * @see https://www.spectacle.co.uk/uploads/other%20media/Manual_DVD_Studio_Pro_4.pdf DVD Studio Pro 4 User Manual, pages 456 to 460
 *
 * @internal
 */
final class SpruceStl
{
    public const COMMAND_REGEX = '/^\$([A-Za-z0-9]+)[ \t]*=[ \t]*(.*?)[ \t]*$/';

    public const TOGGLES = ["b" => "^B", "i" => "^I", "u" => "^U"];

    public const DEFAULT_STATE = ["b" => false, "i" => false, "u" => false, "horizontal" => "center", "vertical" => "bottom"];

    private const FLAG_COMMANDS = ["bold" => "b", "italic" => "i", "underlined" => "u"];

    private const ALIGNMENTS = [
        "top"    => ["left" => Alignment::TopLeft, "center" => Alignment::TopCenter, "right" => Alignment::TopRight],
        "center" => ["left" => Alignment::MiddleLeft, "center" => Alignment::MiddleCenter, "right" => Alignment::MiddleRight],
        "bottom" => ["left" => Alignment::BottomLeft, "center" => Alignment::BottomCenter, "right" => Alignment::BottomRight],
    ];


    /**
     * Returns $state with the style or alignment command of $line applied. Other lines leave it unchanged.
     *
     * @param array{b: bool, i: bool, u: bool, horizontal: string, vertical: string} $state
     *
     * @return array{b: bool, i: bool, u: bool, horizontal: string, vertical: string}
     */
    public static function apply(array $state, string $line): array
    {
        if (!preg_match(self::COMMAND_REGEX, trim($line), $matches)) {
            return $state;
        }

        $name  = strtolower($matches[1]);
        $value = strtolower($matches[2]);
        if (isset(self::FLAG_COMMANDS[$name]) && in_array($value, ["true", "false"], true)) {
            $state[self::FLAG_COMMANDS[$name]] = $value === "true";
        } elseif ($name === "horzalign" && in_array($value, ["left", "center", "right"], true)) {
            $state["horizontal"] = $value;
        } elseif ($name === "vertalign" && in_array($value, ["top", "center", "bottom"], true)) {
            $state["vertical"] = $value;
        }

        return $state;
    }


    /**
     * @param array{horizontal: string, vertical: string} $state
     */
    public static function alignment(array $state): Alignment
    {
        return self::ALIGNMENTS[$state["vertical"]][$state["horizontal"]];
    }


    /**
     * Returns the values of $HorzAlign and $VertAlign for $alignment.
     *
     * @return array{horizontal: string, vertical: string}
     */
    public static function alignmentState(Alignment $alignment): array
    {
        foreach (self::ALIGNMENTS as $vertical => $row) {
            $horizontal = array_search($alignment, $row, true);
            if ($horizontal !== false) {
                return ["horizontal" => $horizontal, "vertical" => $vertical];
            }
        }

        return ["horizontal" => "center", "vertical" => "bottom"];
    }
}
