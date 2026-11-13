<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

final class EbuStlWriteReport
{
    /**
     * @internal
     *
     * @param string                        $content           the EBU STL output, as EbuStlFormatter::format() returns it
     * @param list<EbuStlReplacedCharacter> $replacedCharacters each character that the output holds as "?", in the order of the cues and comments, then the GSI fields
     */
    public function __construct(
        public readonly string $content,
        public readonly array $replacedCharacters,
    ) {
    }
}
