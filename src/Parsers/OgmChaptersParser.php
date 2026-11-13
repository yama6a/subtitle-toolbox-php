<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * The line patterns are the ones of parse_simple() in mkvtoolnix src/common/chapters/chapters.cpp.
 *
 * @see https://mkvtoolnix.download/doc/mkvmerge.html#mkvmerge.chapters.simple
 */
final class OgmChaptersParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = ChapterReadOptions::class;
    private const TIMESTAMP_LINE = '/^CHAPTER\d+\s*=\s*(\d+)\s*:\s*(\d+)\s*:\s*(\d+)\s*[.,]\s*(\d{1,9})/';
    private const NAME_LINE      = '/^CHAPTER\d+NAME\s*=(.*)$/';


    protected function read(string $content): Subtitle
    {
        $lines = $this->lines($content);

        $chapters = [];
        $start    = null;
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if ($start === null) {
                $start = $this->readTimestampLine($line, $index + 1);
            } elseif (preg_match(self::NAME_LINE, $line, $matches) === 1) {
                $chapters[] = new SubtitleCue($start, $start, Markup::escapeText($matches[1]));
                $start      = null;
            } else {
                throw new ParsingException("The line \"$line\" is not a CHAPTERxxNAME= line.", $index + 1);
            }
        }

        return (new Subtitle())->addCues($this->endChapters($chapters));
    }


    private function readTimestampLine(string $line, int $lineNumber): float
    {
        if (preg_match(self::TIMESTAMP_LINE, $line, $matches) !== 1) {
            throw new ParsingException("The line \"$line\" is not a CHAPTERxx= line.", $lineNumber);
        }
        // A number beyond int64 stands for the largest integer, which is past every limit below.
        [$hours, $minutes, $seconds] = array_map(fn (string $digits): int => StringHelpers::parseInt64($digits) ?? PHP_INT_MAX, array_slice($matches, 1, 3));
        if ($minutes > 59 || $seconds > 59) {
            throw new ParsingException("The line \"$line\" has a minute or second above 59.", $lineNumber);
        }

        return self::boundedTime(Timecode::toSeconds($hours, $minutes, $seconds, $matches[4]), $line, $lineNumber);
    }
}
