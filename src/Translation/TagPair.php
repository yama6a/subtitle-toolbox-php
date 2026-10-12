<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

/**
 * The tags that one placeholder number stands for. $close is null for a tag without a closing tag, such as a word timestamp.
 *
 * @internal
 */
final class TagPair
{
    public function __construct(
        public readonly string $open,
        public readonly ?string $close,
    ) {
    }
}
