<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\YouTubeRollingCues;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\SubtitleCue;

final class WebVttStreamReader implements CueStreamReader
{
    private readonly WebVttParser $parser;

    private readonly ReadOptions $options;

    private array $header = [];


    /**
     * The reader uses ReadOptions::$lenient and ignores $encoding, $lastCueDuration and $formats.
     */
    public function __construct(?ReadOptions $options = null)
    {
        $options ??= new ReadOptions();
        $this->options = $options;
        $this->parser = (new WebVttParser())->useOptions($options);
    }


    public function getWarnings(): array
    {
        $warnings = $this->parser->getWarnings();
        usort($warnings, fn (ParseWarning $a, ParseWarning $b): int => $a->lineNumber <=> $b->lineNumber);

        return $warnings;
    }


    public function read($stream): Generator
    {
        foreach (YouTubeRollingCues::collapse($this->readCues($stream)) as $cue) {
            yield $cue;
        }
    }


    private function readCues($stream): Generator
    {
        $this->parser->useOptions($this->options);
        $this->header = [];
        $seenCue      = false;
        $lines        = $this->trimmedLines($stream);
        if (!$lines->valid()) {
            return;
        }
        if (!str_starts_with($lines->current() ?? "", "WEBVTT")) {
            if (!$this->options->lenient) {
                throw new ParsingException("The file does not start with WEBVTT.", ($lines->key() ?? 0) + 1);
            }
            $lines = $this->parser->repairSignature($lines);
        }

        $count = 0;
        foreach ($this->parser->numberedBlocks($lines) as $lineNumber => $rawLines) {
            $index = $count++;
            if ($index === 0) {
                $this->header = $this->parser->parseHeader($rawLines, $lineNumber);
                continue;
            }

            // The stream skips NOTE blocks, which WebVttParser keeps as comments.
            $block = $this->parser->parseBlock($rawLines, $index, $lineNumber, $seenCue, $this->header);
            if ($block instanceof SubtitleCue) {
                $seenCue = true;
                yield $block;
            }
        }
    }


    /**
     * Returns the header, STYLE and REGION blocks as WebVttParser stores them, complete at the first cue.
     */
    public function getHeader(): array
    {
        return $this->header;
    }


    /**
     * Drops the whitespace around the file like WebVttParser does, and joins runs of empty lines into one.
     * A line of white space inside the file stays, because WebVttParser decides whether it ends a block.
     */
    private function trimmedLines($stream): Generator
    {
        $held    = null;
        $heldKey = 0;
        $pending = [];
        foreach ($this->parser->withoutControlCharacters(Streams::lines($stream, $this->parser->warnInvalidUtf8(...))) as $key => $line) {
            if (trim($line) === "") {
                if ($held !== null && ($line !== "" || end($pending) !== "")) {
                    $pending[$key] = $line;
                }
                continue;
            }
            if ($held === null) {
                $line = ltrim($line);
            } else {
                yield $heldKey => $held;
                yield from $pending;
            }
            $held     = $line;
            $heldKey  = $key;
            $pending  = [];
        }

        if ($held !== null) {
            yield $heldKey => rtrim($held);
        }
    }
}
