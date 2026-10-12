<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

/**
 * One piece of a translation: decoded text, or a placeholder such as <x1>, </x1> or <x2/> with its number.
 *
 * @internal
 */
final class PlaceholderToken
{
    public function __construct(
        public readonly PlaceholderTokenType $type,
        public readonly int|string $value,
    ) {
    }
}
