<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * Writes the text runs of the cues in sentence case, in cue order. One instance holds the state of one pass over a subtitle.
 *
 * @internal
 */
final class SentenceCaser
{
    /** A cue that starts this many seconds after the end of the previous cue starts a sentence. */
    private const SENTENCE_GAP = 2.0;

    /** Abbreviations whose period ends no sentence when a comma or a lower case word follows. */
    private const ABBREVIATIONS = ["i.e", "e.g", "etc", "vs"];

    private const ASCII_SPACES = [" ", "\t", "\n", "\r", "\v", "\f"];

    private ?SubtitleCue $currentCue = null;
    private ?float $previousEnd      = null;
    private bool $endsSentence       = true;
    private bool $capitalizeNext     = true;
    private bool $afterPunctuation   = false;


    public function __construct(private readonly bool $turkic)
    {
    }


    /**
     * Returns the next text run of $cue in sentence case. $startsLine marks the first run of a line.
     */
    public function run(string $text, SubtitleCue $cue, bool $startsLine): string
    {
        if ($cue !== $this->currentCue) {
            $this->currentCue       = $cue;
            $visible                = Markup::visibleText(implode("\n", $cue->getLines()));
            $this->capitalizeNext   = $this->endsSentence || self::startsAnnotation($visible) || ($this->previousEnd !== null
                && $cue->getStart() - $this->previousEnd >= self::SENTENCE_GAP - Timecode::EPSILON);
            $this->afterPunctuation = false;
            $this->previousEnd      = $cue->getEnd();
            $this->endsSentence     = self::endsSentence($visible);
        }
        if ($startsLine && ($this->afterPunctuation || self::startsTurn($text))) {
            $this->capitalizeNext = true;
        }

        return $this->caseRun($text);
    }


    /**
     * Lowers a text run and capitalizes its first letter or digit after the start of a sentence.
     */
    private function caseRun(string $text): string
    {
        $chars        = Markup::characters(TextCase::lower($text, $this->turkic));
        $original     = Markup::characters($text);
        $word         = "";
        $abbreviation = false;
        $result       = "";
        foreach ($chars as $index => $char) {
            if (preg_match('/^[\p{L}\p{N}]$/u', $char) === 1) {
                if ($this->capitalizeNext) {
                    $char = TextCase::title($char, $this->turkic);
                }
                $this->capitalizeNext   = false;
                $this->afterPunctuation = false;
                $word                  .= $char;
            } elseif (in_array($char, [".", "!", "?", "\u{2026}"], true)) {
                $this->afterPunctuation = true;
                $abbreviation           = $char === "." && in_array(strtolower($word), self::ABBREVIATIONS, true);
                $word                   = $char === "." && $word !== "" ? "$word." : "";
            } else {
                $word = "";
                if ($this->afterPunctuation && $char === ",") {
                    $this->afterPunctuation = false;
                } elseif ($this->afterPunctuation && in_array($char, self::ASCII_SPACES, true)) {
                    // Lowering İ adds a combining dot, so the original case is known only when the counts match.
                    $lowerCaseFollows       = count($chars) === count($original) && self::lowerCaseFollows($original, $index);
                    $this->afterPunctuation = !($abbreviation && $lowerCaseFollows);
                    $this->capitalizeNext   = $this->capitalizeNext || $this->afterPunctuation;
                }
            }
            $result .= $char;
        }

        return $result;
    }


    /**
     * Tells if the first letter after $index in $chars is a lower case letter.
     *
     * @param list<string> $chars
     */
    private static function lowerCaseFollows(array $chars, int $index): bool
    {
        foreach (array_slice($chars, $index + 1) as $char) {
            if (preg_match('/^\p{L}$/u', $char) === 1) {
                return preg_match('/^\p{Ll}$/u', $char) === 1;
            }
            if (!in_array($char, self::ASCII_SPACES, true)) {
                return false;
            }
        }

        return false;
    }


    /**
     * Tells if $text starts with the CEA-608 speaker change ">>" or a dialogue dash.
     */
    private static function startsTurn(string $text): bool
    {
        $text = ltrim($text);

        return str_starts_with($text, ">>") || preg_match(DialogueDash::REGEX, $text) === 1;
    }


    /**
     * Tells if $text ends a sentence, or ends with a sound description, a music note or a speaker label.
     * Text that is not valid UTF-8 makes the Unicode regex fail, so an ASCII regex decides then.
     */
    private static function endsSentence(string $text): bool
    {
        $match = preg_match('/(?:[.!?\x{2026}][\p{Pe}\p{Pi}\p{Pf}"\']*|[\])\x{266A}:])$/u', $text);
        if ($match === false) {
            $match = preg_match('/[.!?][)\]}"\']*$|[\]):]$|\xE2\x99\xAA$/', $text);
        }

        return $match === 1;
    }


    /**
     * Tells if $text starts with a sound description or a music note.
     */
    private static function startsAnnotation(string $text): bool
    {
        $text = ltrim($text);

        return str_starts_with($text, "[") || str_starts_with($text, "(") || str_starts_with($text, "\u{266A}");
    }
}
