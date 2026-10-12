<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Closure;
use SubtitleToolbox\Markup;
use SubtitleToolbox\MarkupColors;

/**
 * Converts the Text field of an ASS or SSA event to core markup.
 *
 * @internal
 */
final class AssTextMarkup
{
    // A tag ends at the next backslash, except inside parentheses such as \t(\1c&HFF&).
    private const OVERRIDE_TAG_REGEX = '/\\\\[^\\\\(]*(?<args>\((?:[^()]++|(?&args))*\))?[^\\\\]*/';

    /** @var list<array{string, string}> tag name and opening tag, innermost last */
    private array $openTags = [];


    /**
     * @param list<array<string, string>>          $styles      the styles of the file, for \r with a style name
     * @param Closure(float, string, ?int): float  $boundedTime SubtitleParser::boundedTime(), which rejects a karaoke time past the limit
     */
    public function __construct(private readonly array $styles, private readonly Closure $boundedTime)
    {
    }


    /**
     * Returns the core markup lines and the alignment of the first \an or \a tag.
     * The bold, italic, underline and strikeout flags of the style open their tags at the start and after \r.
     *
     * @param ?array<string, string> $style
     * @return array{list<string>, ?int}
     */
    public function convert(string $text, float $start, bool $softBreakIsHard, int $lineNumber, ?array $style): array
    {
        $this->openTags = [];
        $alignment      = null;
        $drawing        = false;
        $karaokeStart   = $start;
        $markup         = $this->openStyleTags($style);
        foreach (preg_split('/(\{[^{}]*\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (!str_starts_with($part, "{") || !str_ends_with($part, "}")) {
                if (!$drawing) {
                    $markup .= str_replace(
                        ["\\N", "\\n", "\\h"],
                        ["\n", $softBreakIsHard ? "\n" : " ", "\u{00A0}"],
                        Markup::escapeText($part)
                    );
                }
                continue;
            }

            preg_match_all(self::OVERRIDE_TAG_REGEX, substr($part, 1, -1), $tags);
            foreach ($tags[0] as $tag) {
                $tag = trim($tag);
                $tagAlignment = SsaOverrideTags::alignment($tag);
                if ($tagAlignment !== null) {
                    $alignment ??= $tagAlignment;
                } elseif (preg_match('/^\\\\([bius])([01]?)$/', $tag, $matches)) {
                    $markup .= $this->setTag($matches[1], $matches[2] === "1" ? "<$matches[1]>" : null);
                } elseif (preg_match('/^\\\\1?c(?:&H([0-9A-Fa-f]{1,8})&?)?$/', $tag, $matches)) {
                    $markup .= $this->setTag("font", isset($matches[1]) ? $this->fontTag($matches[1]) : null);
                } elseif (preg_match('/^\\\\(?:k|K|kf|ko)(\d+(?:\.\d+)?)$/', $tag, $matches)) {
                    $markup       .= "<" . Markup::coreTimestamp($karaokeStart) . ">";
                    $karaokeStart  = ($this->boundedTime)($karaokeStart + $matches[1] / 100, $tag, $lineNumber);
                } elseif (preg_match('/^\\\\r(.*)$/', $tag, $matches)) {
                    $markup .= $this->closeAll();
                    $markup .= $this->openStyleTags(trim($matches[1]) === "" ? $style : AssStyles::find($this->styles, $matches[1]) ?? $style);
                } elseif (preg_match('/^\\\\p(\d+)$/', $tag, $matches)) {
                    $drawing = (int) $matches[1] > 0;
                }
            }
        }
        $markup .= $this->closeAll();

        return [explode("\n", Markup::removeEmptyTagPairs($markup)), $alignment];
    }


    /**
     * Opens, replaces or closes one tag. Tags opened after it close first and open again, so tags always nest.
     */
    private function setTag(string $tagName, ?string $openingTag): string
    {
        $markup = "";
        $index  = array_search($tagName, array_column($this->openTags, 0), true);
        if ($index !== false) {
            if ($this->openTags[$index][1] === $openingTag) {
                return "";
            }

            $inner = array_slice($this->openTags, $index + 1);
            foreach (array_reverse(array_slice($this->openTags, $index)) as [$openName]) {
                $markup .= "</$openName>";
            }
            $this->openTags = array_slice($this->openTags, 0, $index);
            foreach ($inner as $innerTag) {
                $markup           .= $innerTag[1];
                $this->openTags[] = $innerTag;
            }
        }

        if ($openingTag !== null) {
            $markup           .= $openingTag;
            $this->openTags[] = [$tagName, $openingTag];
        }

        return $markup;
    }


    private function openStyleTags(?array $style): string
    {
        $markup = "";
        foreach (AssStyles::tags($style) as $tagName) {
            $markup .= $this->setTag($tagName, "<$tagName>");
        }

        return $markup;
    }


    private function closeAll(): string
    {
        $markup = "";
        foreach (array_reverse($this->openTags) as [$tagName]) {
            $markup .= "</$tagName>";
        }
        $this->openTags = [];

        return $markup;
    }


    private function fontTag(string $hex): string
    {
        return "<font color=\"#" . strtolower(MarkupColors::bgrToRgb(substr(str_pad($hex, 6, "0", STR_PAD_LEFT), -6))) . "\">";
    }
}
