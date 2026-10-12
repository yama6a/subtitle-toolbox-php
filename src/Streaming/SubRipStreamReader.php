<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\ReadOptions;

final class SubRipStreamReader implements CueStreamReader
{
    private readonly SubRipParser $parser;

    private readonly ReadOptions $options;


    /**
     * The reader uses ReadOptions::$lenient and ignores $encoding, $lastCueDuration and $formats.
     */
    public function __construct(?ReadOptions $options = null)
    {
        $options ??= new ReadOptions();
        $this->options = $options;
        $this->parser = (new SubRipParser())->useOptions($options);
    }


    public function getWarnings(): array
    {
        return $this->parser->getWarnings();
    }


    public function read($stream): Generator
    {
        $this->parser->useOptions($this->options);
        $index = 0;
        foreach ($this->parser->splitIntoBlocks($this->parser->withoutControlCharacters(Streams::lines($stream, $this->parser->warnInvalidUtf8(...)))) as $lineNumber => $rawLines) {
            foreach ($this->parser->parseBlock($rawLines, $index++, $lineNumber) as $cue) {
                yield $cue;
            }
        }
    }
}
