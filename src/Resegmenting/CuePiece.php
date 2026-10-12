<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

/**
 * One word of a cue text, or one part of a word that CJK punctuation or a word timestamp splits.
 *
 * @internal
 */
final readonly class CuePiece
{
    /**
     * @param string                                 $text       the text with its tags
     * @param int                                    $length     the visible characters
     * @param string                                 $separator  " " or "\n" before the piece, or "" inside a word and before the first piece
     * @param ?float                                 $time       the word timestamp at the start of the piece, in seconds
     * @param list<array{name: string, tag: string}> $openBefore the core markup tags open before the piece
     * @param list<array{name: string, tag: string}> $openAfter  the core markup tags open after the piece
     */
    public function __construct(
        public string $text,
        public int $length,
        public string $separator,
        public ?float $time,
        public array $openBefore,
        public array $openAfter,
    ) {
    }
}
