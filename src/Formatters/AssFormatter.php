<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Alignment;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\AssFormatLines;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\AssStyles;
use SubtitleToolbox\Parsers\SsaOverrideTags;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class AssFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = AssWriteOptions::class;

    protected const DEFAULT_BOM = true;

    // The same header that FFmpeg writes when it converts a text subtitle to ASS.
    private const DEFAULT_SCRIPT_INFO = ["ScriptType" => "v4.00+", "PlayResX" => "384", "PlayResY" => "288", "ScaledBorderAndShadow" => "yes"];

    private const DEFAULT_STYLE = [
        "Default", "Arial", "16", "&H00FFFFFF", "&H00FFFFFF", "&H00000000", "&H00000000",
        "0", "0", "0", "0", "100", "100", "0", "0", "1", "1", "0", "2", "10", "10", "10", "1",
    ];

    private const TIME_PATTERN = "%d:%02d:%02d.%02d";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $data    = $subtitle->findFormatData(AssParser::FORMAT_DATA_KEY) + $this->defaultData();
        $context = new AssContext(
            $this->isSsa($data),
            $options->stripTags,
            $this->formatOptions($options)->karaokeTag,
            $data["styles"] ?? [],
            strcasecmp($data["stylesSection"] ?? "", "V4 Styles") === 0,
        );
        // The cue tags follow the styles before the style option, so that the option changes the look of every cue without its own tag.
        $style = $this->formatOptions($options)->style;
        if ($style !== null) {
            $data = $this->withDefaultStyle($data, $style);
        }

        $order = $data["sectionOrder"];
        if (!in_array("script info", array_map("strtolower", $order), true)) {
            array_unshift($order, "Script Info");
        }
        if (!in_array("events", array_map("strtolower", $order), true)) {
            $order[] = "Events";
        }

        $blocks = [];
        foreach (array_unique($order) as $section) {
            $lines = match (true) {
                strcasecmp($section, "Script Info") === 0                => $this->scriptInfoLines($subtitle, $data),
                strcasecmp($section, $data["stylesSection"] ?? "") === 0 => $this->styleLines($data),
                strcasecmp($section, "Events") === 0                     => $this->eventLines($subtitle, $data, $context),
                default                                                  => $data["sections"][$section] ?? [],
            };
            $blocks[] = implode(LineEnding::Lf->value, ["[$section]", ...$lines]);
        }

        return $this->applyOutputOptions(
            implode(LineEnding::Lf->value . LineEnding::Lf->value, $blocks) . LineEnding::Lf->value,
            $options
        );
    }


    private function defaultData(): array
    {
        return [
            "sectionOrder"       => ["Script Info", "V4+ Styles", "Events"],
            "scriptInfoComments" => [],
            "scriptInfo"         => self::DEFAULT_SCRIPT_INFO,
            "stylesSection"      => "V4+ Styles",
            "styleFormat"        => AssFormatLines::ASS_STYLE_FORMAT,
            "styles"             => [array_combine(AssFormatLines::ASS_STYLE_FORMAT, self::DEFAULT_STYLE)],
            "eventFormat"        => AssFormatLines::ASS_EVENT_FORMAT,
            "commentEvents"      => [],
            "sections"           => [],
        ];
    }


    private function withDefaultStyle(array $data, string $style): array
    {
        if ($data["stylesSection"] === null) {
            $isSsa                 = $this->isSsa($data);
            $data["stylesSection"] = $isSsa ? "V4 Styles" : "V4+ Styles";
            $data["styleFormat"]   = $isSsa ? AssFormatLines::SSA_STYLE_FORMAT : AssFormatLines::ASS_STYLE_FORMAT;
            $events                = array_search("events", array_map("strtolower", $data["sectionOrder"]), true);
            array_splice($data["sectionOrder"], $events === false ? count($data["sectionOrder"]) : $events, 0, [$data["stylesSection"]]);
        }
        $data["styleFormat"] ??= AssFormatLines::ASS_STYLE_FORMAT;
        $data["styles"]        = AssStyleOverride::apply(
            $style,
            $data["styleFormat"],
            $data["styles"],
            array_combine(AssFormatLines::ASS_STYLE_FORMAT, self::DEFAULT_STYLE)
        );

        return $data;
    }


    private function scriptInfoLines(Subtitle $subtitle, array $data): array
    {
        $lines = $data["scriptInfoComments"] ?? [];
        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE);
        if ($title !== null) {
            $lines[] = "Title: " . Markup::toSingleLine($title);
        }
        foreach ($data["scriptInfo"] ?? [] as $key => $value) {
            $lines[] = "$key: $value";
        }

        return $lines;
    }


    private function styleLines(array $data): array
    {
        $format = $data["styleFormat"] ?? AssFormatLines::ASS_STYLE_FORMAT;
        $lines  = ["Format: " . implode(", ", $format)];
        foreach ($data["styles"] ?? [] as $style) {
            $lines[] = "Style: " . implode(",", array_map(fn (string $field): string => $style[$field] ?? "", $format));
        }

        return $lines;
    }


    private function eventLines(Subtitle $subtitle, array $data, AssContext $context): array
    {
        $format        = $data["eventFormat"] ?? AssFormatLines::ASS_EVENT_FORMAT;
        $commentEvents = $data["commentEvents"] ?? [];
        $comments      = $subtitle->getComments();
        $cues          = array_values($subtitle->getCues());

        $lines = ["Format: " . implode(", ", $format)];
        foreach ([...$cues, null] as $cueIndex => $cue) {
            $commentTime = $cue?->getStart() ?? ($cues === [] ? 0 : end($cues)->getEnd());
            foreach ($comments as $comment) {
                $isAtCue = $cue === null ? $comment->beforeCueIndex >= $cueIndex : $comment->beforeCueIndex === $cueIndex;
                if ($isAtCue) {
                    $lines[] = $this->commentLine($comment->text, $format, $commentEvents, $commentTime);
                }
            }

            if ($cue !== null) {
                $lines[] = $this->dialogueLine($cue, $format, $context);
            }
        }

        return $lines;
    }


    /**
     * Writes the stored Comment event with the same text, or a new Comment event at the given time.
     */
    private function commentLine(string $text, array $format, array &$commentEvents, float $seconds): string
    {
        foreach ($commentEvents as $index => $event) {
            if ($this->fieldValue($event, "Text") === $text) {
                unset($commentEvents[$index]);

                return "Comment: " . implode(",", array_map(fn (string $field): string => $event[$field] ?? "", $format));
            }
        }

        $time   = sprintf(self::TIME_PATTERN, ...Timecode::centiseconds($seconds));
        $values = [];
        foreach ($format as $field) {
            $values[] = match (strtolower($field)) {
                "start", "end" => $time,
                "text"         => str_replace(LineEnding::Lf->value, "\\N", $text),
                default        => $this->defaultFieldValue($field),
            };
        }

        return "Comment: " . implode(",", $values);
    }


    private function dialogueLine(SubtitleCue $cue, array $format, AssContext $context): string
    {
        $stored = $cue->findFormatData(AssParser::FORMAT_DATA_KEY);
        $fields = $stored["fields"] ?? [];

        $unchanged = !$context->stripTags && isset($stored["text"]) &&
                     ($stored["lines"] ?? null) === $cue->getLines() &&
                     ($stored["alignment"] ?? Alignment::BottomCenter->value) === $cue->getAlignment()->value;
        [$text, $name] = $unchanged
            ? [$stored["text"], $this->fieldValue($fields, "Name") ?? ""]
            : $this->convertLines($cue, $context, AssStyles::forEvent($context->styles, $this->fieldValue($fields, "Style") ?? "Default"));

        $values = [];
        foreach ($format as $field) {
            $values[] = match (strtolower($field)) {
                "start" => sprintf(self::TIME_PATTERN, ...Timecode::centiseconds($cue->getStart())),
                "end"   => sprintf(self::TIME_PATTERN, ...Timecode::centiseconds($cue->getEnd())),
                "text"  => $text,
                "name"  => $name,
                default => $fields[$field] ?? $this->defaultFieldValue($field),
            };
        }

        return "Dialogue: " . implode(",", $values);
    }


    /**
     * Converts the core markup of the cue lines to the Text field and returns it together with the speaker name.
     * Tags that the style of the event already implies are not written.
     *
     * @param ?array<string, string> $style
     * @return array{string, string}
     */
    private function convertLines(SubtitleCue $cue, AssContext $context, ?array $style): array
    {
        $text = Markup::rubyAsText(implode(LineEnding::Lf->value, $cue->getLines()));
        $name = str_replace(",", "", Markup::speaker($text) ?? "");

        $parts     = [];
        $alignment = $cue->getAlignment()->value;
        if ($alignment !== (AssStyles::alignment($style, $context->legacyStyles) ?? Alignment::BottomCenter->value)) {
            $parts[] = ["tag", $context->isSsa ? "\\a" . array_flip(SsaOverrideTags::SSA_ALIGNMENTS)[$alignment] : "\\an" . $alignment];
        }

        $tokens = $context->stripTags ? [Markup::stripAllTags($text)] : Markup::splitTags(Markup::webVttColorsToFont($text));
        if (!$context->stripTags) {
            foreach ($this->removeStyleTags($tokens, AssStyles::tags($style)) as $tagName) {
                $parts[] = ["tag", "\\" . $tagName . "0"];
            }
        }
        $parts = [...$parts, ...$this->convertTokens($tokens, $cue, $context)];

        $output = "";
        $block  = [];
        foreach ([...$parts, ["text", ""]] as [$type, $value]) {
            if ($type === "tag") {
                // In one block the last \b, \i, \u, \s or \c wins, so an earlier tag of the same kind has no effect.
                $kind    = preg_match('/^\\\\([biusc])(?![a-z])/', $value, $matches) ? $matches[1] : null;
                $block   = array_filter($block, fn (array $tag): bool => $kind === null || $tag[0] !== $kind);
                $block[] = [$kind, $value];
                continue;
            }

            $output .= ($block === [] ? "" : "{" . implode("", array_column($block, 1)) . "}") . $value;
            $block   = [];
        }

        return [$output, $name];
    }


    /**
     * Removes the outer tag pairs that the style implies, such as <i> around all text of an italic style.
     * Returns the style tag names that do not wrap all text. Their tags are turned off at the start.
     *
     * @param list<string> $tokens text runs at the even indexes and core markup tags at the odd indexes
     * @param list<string> $styleTags
     * @return list<string>
     */
    private function removeStyleTags(array &$tokens, array $styleTags): array
    {
        $remaining = $styleTags;
        while (($index = $this->outerStyleTag($tokens, $remaining)) !== null) {
            $remaining = array_values(array_diff($remaining, [$tokens[$index][1]]));
            $tokens[$this->closingIndex($tokens, $index)] = "";
            $tokens[$index] = "";
        }

        return $remaining;
    }


    /**
     * Returns the index of a first b, i, u or s tag of $tagNames whose pair wraps all text, or null.
     *
     * @param list<string> $tokens
     * @param list<string> $tagNames
     */
    private function outerStyleTag(array $tokens, array $tagNames): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                if ($token !== "") {
                    return null;
                }
                continue;
            }
            if (!preg_match('/^<([bius])>$/', $token, $matches)) {
                continue;
            }
            if (!in_array($matches[1], $tagNames, true)) {
                return null;
            }
            $closing = $this->closingIndex($tokens, $index);
            if ($closing === null) {
                return null;
            }
            foreach (array_slice($tokens, $closing + 1, null, true) as $after => $rest) {
                if ($after % 2 === 0 && $rest !== "") {
                    return null;
                }
            }

            return $index;
        }

        return null;
    }


    /**
     * @param list<string> $tokens
     */
    private function closingIndex(array $tokens, int $openingIndex): ?int
    {
        $tagName = substr($tokens[$openingIndex], 1, -1);
        $depth   = 0;
        for ($index = $openingIndex; $index < count($tokens); $index += 2) {
            $depth += match ($tokens[$index]) {
                "<$tagName>"  => 1,
                "</$tagName>" => -1,
                default       => 0,
            };
            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }


    /**
     * @param list<string> $tokens text runs at the even indexes and core markup tags at the odd indexes
     *
     * @return list<array{string, string}> override tags and escaped text
     */
    private function convertTokens(array $tokens, SubtitleCue $cue, AssContext $context): array
    {
        $karaoke = $this->karaokeDurations($tokens, $cue);
        $parts   = [];
        if ($karaoke["leading"] !== null) {
            $parts[] = ["tag", "\\" . $context->karaokeTag->value . $karaoke["leading"]];
        }

        $colors         = [];
        $timestampIndex = 0;
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                if ($token !== "") {
                    $parts[] = ["text", $this->escapeText(Markup::decodeEntities($token))];
                }
            } elseif (preg_match('/^<(\/?)([bius])>$/', $token, $matches)) {
                $parts[] = ["tag", "\\" . $matches[2] . ($matches[1] === "" ? "1" : "0")];
            } elseif (preg_match('/^<font\b[^>]*>$/i', $token)) {
                $color    = preg_match('/^#([0-9a-fA-F]{6})/', trim(Markup::fontColor($token) ?? ""), $matches) ? strtoupper($matches[1]) : null;
                $colors[] = $color;
                if ($color !== null) {
                    $parts[] = ["tag", $this->colorTag($color)];
                }
            } elseif (strcasecmp($token, "</font>") === 0) {
                if (array_pop($colors) !== null) {
                    $outer   = array_values(array_filter($colors));
                    $parts[] = ["tag", $outer === [] ? "\\c" : $this->colorTag(end($outer))];
                }
            } elseif (Markup::wordTimestampSeconds($token) !== null) {
                $parts[] = ["tag", "\\" . $context->karaokeTag->value . $karaoke["durations"][$timestampIndex++]];
            }
        }

        return $parts;
    }


    /**
     * Turns the word timestamps into \k durations in centiseconds. Each syllable lasts until the next one or the cue end.
     *
     * @return array{leading: ?int, durations: list<int>}
     */
    private function karaokeDurations(array $tokens, SubtitleCue $cue): array
    {
        $startCs    = (int) round($cue->getStart() * 100);
        $times      = [];
        $textBefore = false;
        foreach ($tokens as $index => $token) {
            $seconds = $index % 2 === 1 ? Markup::wordTimestampSeconds($token) : null;
            if ($seconds !== null) {
                $times[] = max($startCs, (int) round($seconds * 100));
            } elseif ($times === [] && $index % 2 === 0 && trim($token) !== "") {
                $textBefore = true;
            }
        }

        if ($times === []) {
            return ["leading" => null, "durations" => []];
        }

        $durations = [];
        $ends      = [...array_slice($times, 1), max($startCs, (int) round($cue->getEnd() * 100))];
        foreach ($times as $index => $time) {
            $durations[] = max(0, $ends[$index] - $time);
        }

        return ["leading" => $textBefore ? $times[0] - $startCs : null, "durations" => $durations];
    }


    private function colorTag(string $rgb): string
    {
        return "\\c&H" . Markup::rgbToBgr($rgb) . "&";
    }


    private function escapeText(string $text): string
    {
        return str_replace([LineEnding::Lf->value, "\u{00A0}"], ["\\N", "\\h"], $text);
    }


    private function fieldValue(array $fields, string $fieldName): ?string
    {
        foreach ($fields as $key => $value) {
            if (strcasecmp($key, $fieldName) === 0) {
                return $value;
            }
        }

        return null;
    }


    private function defaultFieldValue(string $field): string
    {
        return match (strtolower($field)) {
            "layer", "marginl", "marginr", "marginv" => "0",
            "marked"                                 => "Marked=0",
            "style"                                  => "Default",
            default                                  => "",
        };
    }


    private function isSsa(array $data): bool
    {
        $scriptType = array_change_key_case($data["scriptInfo"] ?? [])["scripttype"] ?? "";

        return strcasecmp($scriptType, "v4.00") === 0 || strcasecmp($data["stylesSection"] ?? "", "V4 Styles") === 0;
    }
}
