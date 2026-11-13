<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

use Closure;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;

final class ProfanityOptions
{
    /** @var list<string> */
    public readonly array $words;


    /**
     * docs/text.md#profanity-filter shows an example of each mask.
     *
     * @param list<string>                          $words   one word or phrase each, with an optional * at the end for any word ending
     * @param ProfanityMask|Closure(string): string $mask    how the filter writes a match, or a function that returns the new text of a match
     * @param float                                 $padding the seconds added on both sides of a mute range
     */
    public function __construct(
        array $words,
        public readonly ProfanityMask|Closure $mask = ProfanityMask::Stars,
        public readonly float $padding = 0.0,
    ) {
        foreach ($words as $word) {
            if (!is_string($word) || preg_match('/^[^*\s](?:[^*]*[^*\s])?\*?\z/u', $word) !== 1) {
                throw new InvalidArgumentException("A profanity word must be a non-empty UTF-8 string with a * only at the end, got " .
                                                   var_export($word, true) . ".");
            }
        }
        if ($words === []) {
            throw new InvalidArgumentException("The profanity word list is empty.");
        }
        OptionChecks::nonNegativeFinite($padding, "The padding must not be negative, got %s.");

        $this->words = array_values(array_unique($words));
    }
}
