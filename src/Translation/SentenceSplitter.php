<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Markup;

/**
 * Cuts the translation of a sentence over several cues into one piece per cue.
 *
 * @internal
 */
final class SentenceSplitter
{
    private const NO_SPACES_SCRIPT_REGEX = '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]{2}$/u';


    /**
     * Cuts the tokens into one piece per weight, in proportion to the weights.
     * A cut falls at a space, or between two characters of a script without spaces such as Chinese.
     * Each piece closes the tags that are open at its end, and the next piece opens them again.
     *
     * @param list<PlaceholderToken> $tokens
     * @param list<int>              $weights
     * @return list<list<PlaceholderToken>>
     */
    public static function split(array $tokens, array $weights): array
    {
        $characters = [];
        foreach ($tokens as $token) {
            if ($token->type === PlaceholderTokenType::Text) {
                array_push($characters, ...Markup::characters((string) $token->value));
            }
        }

        $pieces = self::distribute($tokens, self::cutPositions($characters, $weights), count($weights));

        return self::balanceTags($pieces);
    }


    /**
     * Puts each token into the piece that holds its position. A text token splits at the cuts. A space at a cut drops.
     *
     * @param list<PlaceholderToken> $tokens
     * @param list<int>              $cuts
     * @return list<list<PlaceholderToken>>
     */
    private static function distribute(array $tokens, array $cuts, int $count): array
    {
        $pieces = array_fill(0, $count, []);
        $piece  = 0;
        $offset = 0;
        foreach ($tokens as $token) {
            if ($token->type === PlaceholderTokenType::Close) {
                $pieces[$piece][] = $token;
                continue;
            }

            $parts = $token->type === PlaceholderTokenType::Text ? Markup::characters((string) $token->value) : [null];
            foreach ($parts as $character) {
                while (isset($cuts[$piece]) && $offset >= $cuts[$piece]) {
                    $piece++;
                }
                if ($character === null) {
                    $pieces[$piece][] = $token;
                    continue;
                }
                if ($piece === 0 || $offset !== $cuts[$piece - 1] || trim($character) !== "") {
                    self::appendText($pieces[$piece], $character);
                }
                $offset++;
            }
        }

        return $pieces;
    }


    /**
     * @param list<PlaceholderToken> $piece
     */
    private static function appendText(array &$piece, string $character): void
    {
        $last = array_key_last($piece);
        if ($last !== null && $piece[$last]->type === PlaceholderTokenType::Text) {
            $piece[$last] = new PlaceholderToken(PlaceholderTokenType::Text, $piece[$last]->value . $character);
        } else {
            $piece[] = new PlaceholderToken(PlaceholderTokenType::Text, $character);
        }
    }


    /**
     * Closes in each piece the tags that are open at its end, and opens them again at the start of the next piece.
     *
     * @param list<list<PlaceholderToken>> $pieces
     * @return list<list<PlaceholderToken>>
     */
    private static function balanceTags(array $pieces): array
    {
        $open = [];
        foreach ($pieces as $pieceIndex => $pieceTokens) {
            $balanced = array_map(fn (int $number): PlaceholderToken => new PlaceholderToken(PlaceholderTokenType::Open, $number), $open);
            foreach ($pieceTokens as $token) {
                if ($token->type === PlaceholderTokenType::Open) {
                    $open[] = $token->value;
                } elseif ($token->type === PlaceholderTokenType::Close && in_array($token->value, $open, true)) {
                    unset($open[array_search($token->value, $open, true)]);
                    $open = array_values($open);
                }
                $balanced[] = $token;
            }
            foreach (array_reverse($open) as $number) {
                $balanced[] = new PlaceholderToken(PlaceholderTokenType::Close, $number);
            }
            $pieces[$pieceIndex] = $balanced;
        }

        return $pieces;
    }


    /**
     * Returns the character offsets where pieces 2 to n start, each as close as the cuts allow to its share of the weights.
     *
     * @param list<string> $characters
     * @param list<int>    $weights
     * @return list<int>
     */
    private static function cutPositions(array $characters, array $weights): array
    {
        $candidates = [];
        foreach ($characters as $offset => $character) {
            if ($offset === 0) {
                continue;
            }
            if (trim($character) === "" ? $offset < count($characters) - 1
                : preg_match(self::NO_SPACES_SCRIPT_REGEX, $characters[$offset - 1] . $character) === 1) {
                $candidates[] = $offset;
            }
        }

        $cutCount = min(count($weights) - 1, count($candidates));
        $total    = array_sum($weights);
        $cuts     = [];
        $share    = 0;
        $previous = -1;
        for ($cut = 0; $cut < $cutCount; $cut++) {
            $share += $weights[$cut];
            $target = count($characters) * $share / $total;
            $best   = $previous + 1;
            for ($candidate = $best + 1; $candidate < count($candidates) - ($cutCount - 1 - $cut); $candidate++) {
                if (abs($candidates[$candidate] - $target) < abs($candidates[$best] - $target)) {
                    $best = $candidate;
                }
            }
            $cuts[]   = $candidates[$best];
            $previous = $best;
        }

        return $cuts;
    }
}
