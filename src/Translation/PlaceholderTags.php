<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Markup;

/**
 * Replaces the tags of a text with numbered placeholders such as <x1>, </x1> and <x2/>, and puts the tags back into the
 * translation.
 *
 * @internal
 */
final class PlaceholderTags
{
    private const TAG_REGEX               = '/((?=<\/?[a-zA-Z])' . Markup::TAG . '|<' . Markup::WORD_TIMESTAMP . '>)/';
    private const PLACEHOLDER_REGEX       = '/(<\/?x\d+\/?>)/';
    private const LOOSE_PLACEHOLDER_REGEX = '/<\s*\/?\s*x\s*\d+\s*\/?\s*>/i';


    /**
     * Gives a tag with a matching closing tag a pair of placeholders, and any other tag a single one.
     */
    public static function encode(string $text): EncodedText
    {
        $parts      = preg_split(self::TAG_REGEX, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $closeIndex = [];
        $openByName = [];
        foreach ($parts as $partIndex => $part) {
            if ($partIndex % 2 === 0 || !preg_match('/^<(\/?)([a-zA-Z][^\s\/>]*)/', $part, $match) || str_ends_with($part, "/>")) {
                continue;
            }

            $name = strtolower($match[2]);
            if ($match[1] === "") {
                $openByName[$name][] = $partIndex;
            } elseif (!empty($openByName[$name])) {
                $closeIndex[array_pop($openByName[$name])] = $partIndex;
            }
        }

        $tags        = [];
        $placeholder = [];
        foreach ($parts as $partIndex => $part) {
            if ($partIndex % 2 === 0) {
                $placeholder[$partIndex] = Markup::escapeText(Markup::decodeEntities($part));
            } elseif (isset($closeIndex[$partIndex])) {
                $number                               = count($tags) + 1;
                $tags[$number]                        = new TagPair($part, $parts[$closeIndex[$partIndex]]);
                $placeholder[$partIndex]              = "<x$number>";
                $placeholder[$closeIndex[$partIndex]] = "</x$number>";
            } elseif (!isset($placeholder[$partIndex])) {
                $number                  = count($tags) + 1;
                $tags[$number]           = new TagPair($part, null);
                $placeholder[$partIndex] = "<x$number/>";
            }
        }
        ksort($placeholder);

        return new EncodedText(implode("", $placeholder), $tags);
    }


    /**
     * Returns true when $translation holds each placeholder of $tags once, with each opening placeholder before its closing one.
     *
     * @param array<int, TagPair> $tags
     */
    public static function keepsPlaceholders(string $translation, array $tags): bool
    {
        $expected = [];
        foreach ($tags as $number => $tag) {
            array_push($expected, ...($tag->close === null ? ["<x$number/>"] : ["<x$number>", "</x$number>"]));
        }

        preg_match_all(self::PLACEHOLDER_REGEX, $translation, $matches);
        $found = $matches[0];
        if (preg_match_all(self::LOOSE_PLACEHOLDER_REGEX, $translation) !== count($found)) {
            return false;
        }

        sort($expected);
        sort($found);
        if ($expected !== $found) {
            return false;
        }

        foreach ($tags as $number => $tag) {
            if ($tag->close !== null && strpos($translation, "<x$number>") > strpos($translation, "</x$number>")) {
                return false;
            }
        }

        return true;
    }


    /**
     * Removes each placeholder, also one that the engine changed, such as "< x1 >".
     */
    public static function remove(string $translation): string
    {
        return preg_replace(self::LOOSE_PLACEHOLDER_REGEX, "", $translation);
    }


    /**
     * Splits the translation into placeholders and decoded text.
     *
     * @return list<PlaceholderToken>
     */
    public static function tokenize(string $translation): array
    {
        $tokens = [];
        foreach (preg_split(self::PLACEHOLDER_REGEX, $translation, -1, PREG_SPLIT_DELIM_CAPTURE) as $partIndex => $part) {
            if ($partIndex % 2 === 0) {
                if ($part !== "") {
                    $tokens[] = new PlaceholderToken(PlaceholderTokenType::Text, Markup::decodeEntities($part));
                }
            } else {
                preg_match('/^<(\/?)x(\d+)(\/?)>$/', $part, $match);
                $type     = $match[1] === "/" ? PlaceholderTokenType::Close : ($match[3] === "/" ? PlaceholderTokenType::Single : PlaceholderTokenType::Open);
                $tokens[] = new PlaceholderToken($type, (int) $match[2]);
            }
        }

        return $tokens;
    }


    /**
     * Writes the tokens as text with the tags of $tags, and drops a pair of tags that holds no text.
     *
     * @param list<PlaceholderToken> $tokens
     * @param array<int, TagPair>    $tags
     */
    public static function restore(array $tokens, array $tags): string
    {
        $text = "";
        foreach ($tokens as $token) {
            $text .= match ($token->type) {
                PlaceholderTokenType::Text   => Markup::escapeText((string) $token->value),
                PlaceholderTokenType::Close  => "</x$token->value>",
                PlaceholderTokenType::Open   => "<x$token->value>",
                PlaceholderTokenType::Single => "<x$token->value/>",
            };
        }

        do {
            $text = preg_replace('/<x(\d+)>(\s*)<\/x\1>/', '$2', $text, -1, $count);
        } while ($count > 0);

        return preg_replace_callback(self::PLACEHOLDER_REGEX, function (array $match) use ($tags): string {
            preg_match('/^<(\/?)x(\d+)/', $match[1], $parts);
            $tag = $tags[(int) $parts[2]];

            return $parts[1] === "/" ? (string) $tag->close : $tag->open;
        }, $text);
    }
}
