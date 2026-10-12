<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\LineWrapper;
use SubtitleToolbox\Markup;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * The text of one cue, split into pieces at spaces and line breaks.
 * It is also split after CJK punctuation and before a word timestamp inside a word.
 *
 * @internal
 */
final readonly class CuePieces
{
    private const CLOSERS      = '["\'\)\]\x{2019}\x{201D}\x{3009}\x{300B}\x{300D}\x{300F}\x{3011}\x{FF09}\x{FF3D}\x{FF5D}]*';
    private const CJK_BREAKS   = '[\x{3001}\x{3002}\x{FF01}\x{FF0C}\x{FF1A}\x{FF1B}\x{FF1F}\x{FF61}\x{FF64}]';
    private const SENTENCE_END = '[.?!\x{2026}\x{3002}\x{FF01}\x{FF1F}\x{FF61}]+';
    private const CLAUSE_END   = '(?:[,;:\x{2013}\x{2014}\x{3001}\x{FF0C}\x{FF1A}\x{FF1B}\x{FF64}]|--|^-)';

    /** @var list<int> the visible characters before each piece, then the visible characters of the whole text */
    private array $positions;


    /**
     * @param list<CuePiece> $pieces
     */
    private function __construct(public array $pieces)
    {
        $positions = [0];
        foreach ($pieces as $index => $piece) {
            $separator   = isset($pieces[$index + 1]) && $pieces[$index + 1]->separator !== "" ? 1 : 0;
            $positions[] = end($positions) + $piece->length + $separator;
        }
        $this->positions = $positions;
    }


    public static function of(SubtitleCue $cue): self
    {
        $rawPieces = [];
        $prefix    = "";
        foreach ($cue->getLines() as $line) {
            foreach (LineWrapper::measuredWords($line) as $wordIndex => $word) {
                $separator = $wordIndex > 0 ? " " : ($rawPieces === [] ? "" : "\n");
                self::addWordPieces($rawPieces, $prefix, $word["text"], $separator);
            }
        }

        $pieces   = [];
        $openTags = [];
        foreach ($rawPieces as $rawPiece) {
            $openBefore = $openTags;
            $openTags   = Markup::openCoreTags($rawPiece["text"], $openTags);
            $time       = null;
            if (preg_match('/^(?:(?!<\d)' . Markup::TAG . ')*(<' . Markup::WORD_TIMESTAMP . '>)/', $rawPiece["text"], $matches) === 1) {
                $time = Timecode::roundToMilliseconds(Markup::wordTimestampSeconds($matches[1]));
            }
            $pieces[] = new CuePiece($rawPiece["text"], $rawPiece["length"], $rawPiece["separator"], $time, $openBefore, $openTags);
        }

        return new self($pieces);
    }


    public function count(): int
    {
        return count($this->pieces);
    }


    /**
     * Joins the pieces from $first to $after - 1.
     * It opens the core markup tags that are open before them, and closes the tags that are open after them.
     */
    public function text(int $first, int $after): string
    {
        $text = implode("", array_column($this->pieces[$first]->openBefore, "tag"));
        for ($index = $first; $index < $after; $index++) {
            $text .= ($index > $first ? $this->pieces[$index]->separator : "") . $this->pieces[$index]->text;
        }

        if ($after < count($this->pieces)) {
            $text .= Markup::closeCoreTags($this->pieces[$after - 1]->openAfter);
        }

        return $text;
    }


    /**
     * @return list<int> the visible characters before each piece, then the visible characters of the whole text
     */
    public function positions(): array
    {
        return $this->positions;
    }


    /**
     * Returns the start time of each piece, then $end. A word timestamp sets the time of its piece.
     * Other times split in proportion to the visible characters.
     *
     * @return list<float>
     */
    public function times(float $start, float $end): array
    {
        $anchors = [0 => $start];
        foreach ($this->pieces as $index => $piece) {
            if ($index > 0 && $piece->time !== null && $piece->time > end($anchors) && $piece->time < $end) {
                $anchors[$index] = $piece->time;
            }
        }
        $anchors[count($this->pieces)] = max($start, $end);

        $times   = [];
        $indexes = array_keys($anchors);
        foreach (array_slice($indexes, 1) as $number => $next) {
            $previous = $indexes[$number];
            $span     = $this->positions[$next] - $this->positions[$previous];
            for ($index = $previous; $index < $next; $index++) {
                $share         = $span > 0 ? ($this->positions[$index] - $this->positions[$previous]) / $span : 0;
                $times[$index] = Timecode::roundToMilliseconds($anchors[$previous] + ($anchors[$next] - $anchors[$previous]) * $share);
            }
        }
        $times[count($this->pieces)] = $anchors[count($this->pieces)];

        return $times;
    }


    /**
     * Returns 0 for a sentence end before piece $index, 1 for a clause end, 2 for another word boundary, or null.
     */
    public function breakRank(int $index): ?int
    {
        if ($this->endsSentence($index - 1)) {
            return 0;
        }

        $before = Markup::plainText($this->pieces[$index - 1]->text);
        if (preg_match('/' . self::CLAUSE_END . self::CLOSERS . '$/u', $before) === 1) {
            return 1;
        }

        return $this->pieces[$index]->separator !== "" || $this->pieces[$index]->time !== null ? 2 : null;
    }


    /**
     * A full stop before a word in lower case, as in "e.g. this", ends no sentence.
     */
    public function endsSentence(int $index): bool
    {
        $text = Markup::plainText($this->pieces[$index]->text);
        if (preg_match('/' . self::SENTENCE_END . self::CLOSERS . '$/u', $text) !== 1) {
            return false;
        }

        $next = isset($this->pieces[$index + 1]) ? Markup::plainText($this->pieces[$index + 1]->text) : "";

        return preg_match('/^\p{Ll}/u', $next) !== 1;
    }


    /**
     * Adds the pieces of one word. $prefix holds the tags of a word without text before the first piece.
     *
     * @param list<array{text: string, length: int, separator: string}> $pieces
     */
    private static function addWordPieces(array &$pieces, string &$prefix, string $word, string $separator): void
    {
        foreach (self::splitWord($word) as $partIndex => $text) {
            $piece = ["text"      => $text,
                      "length"    => LineWrapper::length(LineWrapper::measuredWords($text)),
                      "separator" => $partIndex === 0 ? $separator : ""];

            // A word of tags only, such as "</i>" after a space, joins its neighbour, so that no cue holds only tags.
            if ($piece["length"] === 0 && $pieces !== []) {
                $pieces[count($pieces) - 1]["text"] .= $piece["separator"] . $text;
            } elseif ($piece["length"] === 0) {
                $prefix .= "$text ";
            } else {
                $piece["text"] = $prefix . $piece["text"];
                $prefix        = "";
                $pieces[]      = $piece;
            }
        }
    }


    /**
     * @return list<string>
     */
    private static function splitWord(string $word): array
    {
        $parts        = [""];
        $hasText      = false;
        $isAfterBreak = false;
        foreach (preg_split('/(' . Markup::TAG . ')/', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('/^' . Markup::TAG . '$/', $token) === 1) {
                $isTimestamp = preg_match(Markup::WORD_TIMESTAMP_REGEX, $token) === 1;
                if ($hasText && (($isAfterBreak && !str_starts_with($token, "</")) || $isTimestamp)) {
                    $parts[]      = "";
                    $hasText      = false;
                    $isAfterBreak = false;
                }
                $parts[count($parts) - 1] .= $token;
                continue;
            }

            $chunks = preg_match_all('/.*?' . self::CJK_BREAKS . '+' . self::CLOSERS . '|.+/su', $token, $matches)
                ? $matches[0]
                : [$token];
            foreach ($chunks as $chunk) {
                if ($hasText && $isAfterBreak) {
                    $parts[] = "";
                }
                $parts[count($parts) - 1] .= $chunk;
                $hasText      = true;
                $isAfterBreak = preg_match('/' . self::CJK_BREAKS . self::CLOSERS . '$/u', $chunk) === 1;
            }
        }

        return $parts;
    }
}
