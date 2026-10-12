<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\TextCase;

final class SpeakerLabels
{
    /** White, yellow, cyan and green, the speaker colors of the BBC Subtitle Guidelines, in their order of use. */
    public const BBC_COLORS = ["#ffffff", "#ffff00", "#00ffff", "#00ff00"];

    private const VOICE       = '/^(?:' . Markup::VOICE_TAG . '|' . Markup::VOICE_TAG_START . '>)$/';
    private const VOICE_END   = '/^<\/v\s*>$/';
    private const OPEN_STYLE  = '/^<([a-zA-Z][a-zA-Z0-9]*)(?:[\s.][^>]*)?>$/';
    private const CLOSE_STYLE = '/^<\/([a-zA-Z][a-zA-Z0-9]*)\s*>$/';


    /**
     * Returns the number of cues of each speaker, in the order of the first cue of each speaker.
     *
     * @return array<string, int>
     */
    public static function list(Subtitle $subtitle): array
    {
        $counts = [];
        foreach ($subtitle->getCues() as $cue) {
            $speakers = array_unique(array_filter(
                array_map(fn (SpeakerLine $line): ?string => $line->speaker, self::speakerLines($cue->getLines())),
                fn (?string $speaker): bool => $speaker !== null
            ));
            foreach ($speakers as $speaker) {
                $counts[$speaker] = ($counts[$speaker] ?? 0) + 1;
            }
        }

        return $counts;
    }


    /**
     * Turns speaker labels into <v> tags, renames the speakers and then writes them in the style of $options, in this
     * order. Each step runs only when $options asks for it.
     */
    public static function apply(Subtitle $subtitle, SpeakerLabelOptions $options): SpeakerLabelReport
    {
        $before = array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues());

        if ($options->speakerLabels) {
            self::fromPrefix($subtitle, $options->speakerLabelsUpperCaseOnly);
        }
        if ($options->rename !== []) {
            self::rename($subtitle, $options->rename);
        }
        match ($options->style) {
            SpeakerStyle::Prefix         => self::toPrefix($subtitle, $options->writeUpperCase, $options->separator),
            SpeakerStyle::DialogueDashes => self::toDialogueDashes($subtitle, $options->dialogueDashStyle->value),
            SpeakerStyle::Colors         => self::toColors($subtitle, $options->colors),
            null                         => null,
        };

        $changed = array_filter($subtitle->getCues(), fn (SubtitleCue $cue, int $index): bool =>
            $cue->getLines() !== ($before[$index] ?? null), ARRAY_FILTER_USE_BOTH);

        return new SpeakerLabelReport(count($changed));
    }


    /**
     * @param array<string, string> $names
     */
    private static function rename(Subtitle $subtitle, array $names): void
    {
        foreach ($subtitle->getCues() as $cue) {
            $cue->setLines(array_map(fn (string $line): string => preg_replace_callback(
                '/' . Markup::VOICE_TAG . '/',
                function (array $match) use ($names): string {
                    $name = Markup::decodeEntities(trim($match[2]));

                    return isset($names[$name]) ? Markup::voiceTag((string)$names[$name], $match[1]) : $match[0];
                },
                $line
            ), $cue->getLines()));
        }
    }


    /**
     * Replaces each <v> tag with the speaker name and $separator before the first line of the speaker.
     */
    private static function toPrefix(Subtitle $subtitle, bool $upperCase, string $separator): void
    {
        self::convert($subtitle, function (array $lines) use ($upperCase, $separator): array {
            $result = [];
            foreach ($lines as $line) {
                $name     = $line->speaker === null ? "" : Markup::escapeText($upperCase ? TextCase::upper($line->speaker, false) : $line->speaker);
                $result[] = $line->startsSpeaker && $line->speaker !== null ? $name . Markup::escapeText($separator) . $line->text : $line->text;
            }

            return $result;
        });
    }


    /**
     * Replaces the <v> tags with $dash before the first line of each speaker, in cues with two or more speakers.
     */
    private static function toDialogueDashes(Subtitle $subtitle, string $dash): void
    {
        self::convert($subtitle, function (array $lines) use ($dash): array {
            $speakers = count(array_filter($lines, fn (SpeakerLine $line): bool => $line->startsSpeaker));
            $result   = [];
            foreach ($lines as $line) {
                $hasDash  = preg_match('/^\h*-/', Markup::visibleText($line->text)) === 1;
                $result[] = $speakers >= 2 && $line->startsSpeaker && !$hasDash ? Markup::escapeText($dash) . $line->text : $line->text;
            }

            return $result;
        });
    }


    /**
     * Replaces the <v> tags with a <font color> tag around each line of the speaker. Each speaker gets the next
     * color in the order of the first cue of each speaker. After the last color, the list starts again.
     *
     * @param list<string> $colors
     */
    private static function toColors(Subtitle $subtitle, array $colors): void
    {
        $assigned = [];
        foreach (array_keys(self::list($subtitle)) as $index => $speaker) {
            $assigned[$speaker] = strtolower($colors[$index % count($colors)]);
        }

        self::convert($subtitle, fn (array $lines): array => array_map(
            fn (SpeakerLine $line): string => $line->speaker === null ? $line->text : "<font color=\"{$assigned[$line->speaker]}\">$line->text</font>",
            $lines
        ));
    }


    /**
     * Replaces a speaker label such as "JOHN: " at the start of a line, or after its dialogue dash, with <v John>.
     * The label rule is the speakerLabels rule of HearingImpairedOptions.
     */
    private static function fromPrefix(Subtitle $subtitle, bool $upperCaseOnly): void
    {
        $options = new HearingImpairedOptions(
            squareBrackets: false,
            parentheses: false,
            speakerLabelsUpperCaseOnly: $upperCaseOnly,
            musicOnlyLines: false,
        );

        foreach ($subtitle->getCues() as $cue) {
            $lines   = array_values($cue->getLines());
            $result  = [];
            $pending = null;
            foreach ($lines as $index => $line) {
                [$name, $rest] = self::removeLabel($line, $options);
                if ($name === null) {
                    $result[] = ($pending ?? "") . $line;
                    $pending  = null;
                    continue;
                }

                $tag = Markup::voiceTag($name);
                if (Markup::visibleText($rest) === "") {
                    $isLast   = $index === count($lines) - 1;
                    $pending  = $isLast ? null : $tag . $rest;
                    $result[] = $isLast ? $line : "";
                    continue;
                }

                $result[] = $tag . $rest;
                $pending  = null;
            }

            if ($result !== $lines) {
                $cue->setLines($result);
            }
        }
    }


    /**
     * Applies $fn to the result of speakerLines() of each cue that has a <v> tag.
     *
     * @param callable(list<SpeakerLine>): list<string> $fn
     */
    private static function convert(Subtitle $subtitle, callable $fn): void
    {
        foreach ($subtitle->getCues() as $cue) {
            if (preg_grep('/<\/?v[\s.>]/', $cue->getLines()) !== []) {
                $cue->setLines($fn(self::speakerLines($cue->getLines())));
            }
        }
    }


    /**
     * Returns each line without <v> tags, with its speaker and whether a new speaker starts on it. A speaker that
     * changes in the middle of a line starts a new line. The style tags that are open there close and open again.
     *
     * @param array<string> $lines
     * @return list<SpeakerLine>
     */
    private static function speakerLines(array $lines): array
    {
        $state = new SpeakerSplitState();
        foreach ($lines as $line) {
            self::splitLine($line, $state);
        }

        $result   = [];
        $previous = null;
        $seen     = false;
        foreach ($state->parts as ["speaker" => $speaker, "text" => $text]) {
            $hasText  = Markup::visibleText($text) !== "";
            $result[] = new SpeakerLine($speaker, $text, $hasText && (!$seen || $speaker !== $previous));
            $previous = $hasText ? $speaker : $previous;
            $seen     = $seen || $hasText;
        }

        return $result;
    }


    /**
     * Adds the parts of one line to the parts of $state, a new part at each change of the speaker.
     */
    private static function splitLine(string $line, SpeakerSplitState $state): void
    {
        $current = "";
        $split   = false;
        foreach (Markup::splitTags($line) as $index => $token) {
            if ($index % 2 === 0) {
                $current .= $split && Markup::visibleText($current) === "" ? ltrim($token) : $token;
                continue;
            }

            $isVoice = preg_match(self::VOICE, $token, $voice) === 1;
            if (!$isVoice && preg_match(self::VOICE_END, $token) !== 1) {
                $state->open = self::trackStyle($state->open, $token);
                $current    .= $token;
                continue;
            }

            $name = $isVoice ? Markup::decodeEntities(trim($voice[2] ?? "")) : "";
            $name = $name === "" ? null : $name;
            if ($name === $state->speaker) {
                continue;
            }
            if (Markup::visibleText($current) !== "") {
                $closing        = implode("", array_map(fn (OpenStyleTag $tag): string => "</$tag->name>", array_reverse($state->open)));
                $state->parts[] = ["speaker" => $state->speaker, "text" => rtrim($current) . $closing];
                $current        = implode("", array_map(fn (OpenStyleTag $tag): string => $tag->tag, $state->open));
                $split          = true;
            }
            $state->speaker = $name;
        }
        $state->parts[] = ["speaker" => $state->speaker, "text" => $current];
    }


    /**
     * @param list<OpenStyleTag> $open
     * @return list<OpenStyleTag>
     */
    private static function trackStyle(array $open, string $token): array
    {
        if (preg_match(self::OPEN_STYLE, $token, $match) === 1) {
            $open[] = new OpenStyleTag($match[1], $token);
        } elseif (preg_match(self::CLOSE_STYLE, $token, $match) === 1) {
            for ($index = count($open) - 1; $index >= 0; $index--) {
                if ($open[$index]->name === $match[1]) {
                    array_splice($open, $index, 1);
                    break;
                }
            }
        }

        return $open;
    }


    /**
     * Returns the label name and the line without the dash and the label, or null and the line when it has no label.
     *
     * @return array{?string, string}
     */
    private static function removeLabel(string $line, HearingImpairedOptions $options): array
    {
        $rest = HearingImpairedRemover::removeFromText($line, $options);
        if ($rest === $line) {
            return [null, $line];
        }

        $visible = preg_replace('/^\h*(?:-\h*)?/u', "", Markup::visibleText($line)) ?? "";
        $name    = trim(explode(":", $visible, 2)[0]);
        if (preg_match('/^\h*-/', Markup::visibleText($line)) === 1) {
            $rest = preg_replace('/^((?:' . Markup::TAG . ')*)\h*-\h*/', '$1', $rest) ?? $rest;
        }

        return [preg_match('/\p{Ll}/u', $name) === 1 ? $name : self::titleCase($name), $rest];
    }


    /**
     * Turns an upper case label such as "DR. O'NEIL" into "Dr. O'Neil".
     */
    private static function titleCase(string $name): string
    {
        return preg_replace_callback(
            "/(?:^|(?<=[\\s.'-]))\\p{Ll}/u",
            fn (array $match): string => TextCase::upper($match[0], false),
            TextCase::lower($name, false)
        ) ?? $name;
    }
}
