<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

/**
 * One text for the engine and the cues that its translation fills.
 *
 * @internal
 */
final class TranslationRequest
{
    /**
     * @param list<int> $cueIndexes
     */
    public function __construct(
        public readonly array $cueIndexes,
        public readonly EncodedText $encoded,
    ) {
    }
}
