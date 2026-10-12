<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

/**
 * What CommonErrorFixer knows about one cue beyond its lines.
 *
 * @internal
 */
final class CueFixContext
{
    /**
     * @param ?string $language       "en", "de", "fr", "es" or null, as CommonErrorFixer::language() returns it
     * @param bool    $turkic         the language needs the Turkic case of i and ı
     * @param bool    $continues      the next cue continues the sentence of this cue
     * @param bool    $startsSentence this cue starts a sentence
     */
    public function __construct(
        public readonly ?string $language,
        public readonly bool $turkic,
        public readonly bool $continues,
        public readonly bool $startsSentence,
    ) {
    }
}
