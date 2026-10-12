<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

final class ParseWarning
{
    /**
     * Holds one problem that a lenient parser found and what it did about it.
     *
     * @internal
     *
     * @param ?int         $lineNumber 1-based input line of the problem. Null for binary EBU STL, PGS, VobSub and the JSON formats, which have no lines.
     * @param ?int         $blockIndex 0-based number of the block, as in the "Block #n" messages. Null for a library JSON field outside the cues.
     * @param list<string> $block      the lines of the block, trimmed as the parser reads them
     */
    public function __construct(
        public readonly string $message,
        public readonly ?int $lineNumber,
        public readonly ?int $blockIndex,
        public readonly array $block,
        public readonly ParseWarningAction $action,
    ) {
    }


    /**
     * Returns the warning for a block that the parser skipped, with the message of $exception without its class prefix.
     * A warning with a line number also drops the " (line N)" end of the message, which would repeat it.
     *
     * @internal
     *
     * @param list<string> $block
     */
    public static function skipped(ParsingException $exception, ?int $lineNumber, ?int $blockIndex, array $block): self
    {
        $message = $exception->getRawMessage();
        if ($lineNumber === null && $exception->getLineNumber() !== null) {
            $message .= " (line {$exception->getLineNumber()})";
        }

        return new self($message, $lineNumber, $blockIndex, $block, ParseWarningAction::Skipped);
    }
}
