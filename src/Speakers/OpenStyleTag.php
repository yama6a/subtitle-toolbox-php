<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

/**
 * A style tag such as <i> or <font color="red"> that is open at a point of a cue.
 *
 * @internal
 */
final class OpenStyleTag
{
    public function __construct(
        public readonly string $name,
        public readonly string $tag,
    ) {
    }
}
