<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

final class EbuStlReplacedCharacter
{
    /**
     * Records a character that the table of the output lacks, so the output holds "?".
     * $location names the place, for example "Cue 1", "Comment 2" or "GSI field OPT". Cue and comment numbers start at 1.
     *
     * @internal
     */
    public function __construct(
        public readonly string $character,
        public readonly string $location,
        public readonly string $message,
    ) {
    }
}
