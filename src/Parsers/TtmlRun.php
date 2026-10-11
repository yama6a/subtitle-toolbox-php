<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * One piece of the content of a TTML paragraph: a line break, the start of a timed span, or text with its style and speaker.
 *
 * @internal
 */
final class TtmlRun
{
    /**
     * @param array{color?: ?string, b?: bool, i?: bool, u?: bool, s?: bool} $style
     */
    private function __construct(
        public ?string $text,
        public readonly ?float $begin = null,
        public readonly ?float $end = null,
        public readonly array $style = [],
        public readonly ?string $agent = null,
    ) {
    }


    public static function lineBreak(): self
    {
        return new self(null);
    }


    public static function timedSpan(float $begin, ?float $end): self
    {
        return new self("", $begin, $end);
    }


    /**
     * @param array{color?: ?string, b?: bool, i?: bool, u?: bool, s?: bool} $style
     */
    public static function text(string $text, array $style, ?string $agent): self
    {
        return new self($text, style: $style, agent: $agent);
    }


    public function isLineBreak(): bool
    {
        return $this->text === null;
    }


    public function isTimedSpan(): bool
    {
        return $this->begin !== null;
    }
}
