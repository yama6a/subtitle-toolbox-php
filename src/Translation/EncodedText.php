<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

/**
 * @internal
 */
final class EncodedText
{
    /**
     * @param string              $text the text for the engine, with placeholders in place of the tags
     * @param array<int, TagPair>  $tags by placeholder number
     */
    public function __construct(
        public readonly string $text,
        public readonly array $tags,
    ) {
    }
}
