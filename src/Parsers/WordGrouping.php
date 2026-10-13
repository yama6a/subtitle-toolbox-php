<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Builds cues from the JSON transcripts of speech-to-text services, and groups their words into cues.
 *
 * @phpstan-type Word array{text: string, start: float, end: float, speaker: ?string, data: list<mixed>}
 *
 * @internal
 */
trait WordGrouping
{
    private const MAX_WORD_GAP = 1.0;

    private const MAX_CUE_CHARACTERS = 84;

    // A full stop, question mark or exclamation mark, also the CJK forms, and closing quotes or brackets after it.
    private const SENTENCE_END = '/[.?!\x{3002}\x{FF0E}\x{FF1F}\x{FF01}]["\'\x{2019}\x{201D})\]\x{300D}\x{300F}\x{FF09}]*$/u';


    abstract protected static function formatDataKey(): string;


    abstract protected function formatOptions(): FormatReadOptions;


    /**
     * @param list<string> $block
     */
    abstract protected function fail(ParsingException $exception, ?int $lineNumber, ?int $blockIndex, array $block): void;


    /**
     * Returns the subtitle with the cues, the format data of the file and the language metadata.
     * The language metadata needs a $language that is a string and not empty.
     *
     * @param list<SubtitleCue> $cues
     */
    private function transcript(array $cues, mixed $language, array $fileData): Subtitle
    {
        $subtitle = new Subtitle();
        if (is_string($language) && $language !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
        }
        $subtitle->setFormatData(static::formatDataKey(), $fileData);

        return $subtitle->addCues($cues);
    }


    /**
     * Returns the fields of the file without "results", and the fields of "results" without $readKeys under "results".
     */
    private static function fileDataWithResults(array $data, array $results, array $readKeys): array
    {
        $fileData = array_diff_key($data, ["results" => true]);
        $other    = array_diff_key($results, array_flip($readKeys));
        if ($other !== []) {
            $fileData["results"] = $other;
        }

        return $fileData;
    }


    /**
     * Returns the words of the list. $readWord gets a word and its path, and returns its text, start, end and speaker.
     * In lenient mode, a word for which $readWord throws is skipped with a warning.
     *
     * @param callable(mixed, string): array{string, float, float, ?string} $readWord
     *
     * @return list<Word>
     */
    private function readWordList(array $words, string $path, callable $readWord): array
    {
        $result = [];
        foreach ($words as $index => $word) {
            try {
                [$text, $start, $end, $speaker] = $readWord($word, "{$path}[$index]");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($word)]);
                continue;
            }

            $result[] = ["text" => trim($text), "start" => $start, "end" => $end, "speaker" => $speaker, "data" => [$word]];
        }

        return $result;
    }


    /**
     * Returns one cue per utterance with the fields start, end, $textKey, speaker and words.
     *
     * @param callable(array, string): array $readWords reads the words of an utterance
     *
     * @return list<SubtitleCue>
     */
    private function readUtterances(array $utterances, string $path, string $textKey, callable $readWords, float $unit = 1.0): array
    {
        $cues = [];
        foreach ($utterances as $index => $utterance) {
            $itemPath = "{$path}[$index]";
            try {
                $start = $this->seconds(is_array($utterance) ? $utterance["start"] ?? null : null, "$itemPath.start", $unit);
                $end   = $this->seconds($utterance["end"] ?? null, "$itemPath.end", $unit);
                $text  = $this->text($utterance, $textKey, $itemPath);
                $words = $readWords(self::listOrEmpty($utterance["words"] ?? null), "$itemPath.words");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($utterance)]);
                continue;
            }

            $formatData = $utterance;
            $cue        = $this->cue($start, $end, $text, $words, self::speaker($utterance["speaker"] ?? null), $formatData, $index, $utterance);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }


    private function seconds(mixed $value, string $path, float $unit = 1.0): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        $seconds = is_int($value) || is_float($value) ? $value * $unit : null;
        if (!self::isTime($seconds)) {
            throw new ParsingException("The field $path must be a time.");
        }

        return self::boundedField(Timecode::roundToMilliseconds($seconds), $path);
    }


    private function text(mixed $object, string $key, string $path): string
    {
        $value = is_array($object) ? $object[$key] ?? null : null;
        if (!is_string($value)) {
            throw new ParsingException("The field $path.$key must be a string.");
        }

        return $value;
    }


    private static function speaker(mixed $speaker): ?string
    {
        return is_string($speaker) || is_int($speaker) ? (string) $speaker : null;
    }


    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }


    private static function listOrEmpty(mixed $value): array
    {
        return self::isList($value) ? $value : [];
    }


    /**
     * @param list<Word> $words
     *
     * @return list<list<Word>>
     */
    private function groupWords(array $words): array
    {
        $groups = [];
        $group  = [];
        $length = 0;
        foreach ($words as $word) {
            $wordLength = Markup::countCharacters($word["text"]);
            if ($group !== []) {
                $previous = $group[count($group) - 1];
                if ($previous["speaker"] !== $word["speaker"]
                    || Timecode::roundToMilliseconds($word["start"] - $previous["end"]) >= self::MAX_WORD_GAP
                    || $length + 1 + $wordLength > self::MAX_CUE_CHARACTERS
                    // A full stop before a word in lower case, as in "e.g. this", ends no sentence.
                    || (preg_match(self::SENTENCE_END, $previous["text"]) === 1 && preg_match('/^\p{Ll}/u', $word["text"]) !== 1)) {
                    $groups[] = $group;
                    $group    = [];
                }
            }

            $length  = $group === [] ? $wordLength : $length + 1 + $wordLength;
            $group[] = $word;
        }

        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }


    /**
     * @param list<Word> $words
     *
     * @return list<SubtitleCue>
     */
    private function cuesFromWords(array $words, string $wordsKey, array $formatData = []): array
    {
        $cues = [];
        foreach ($this->groupWords($words) as $index => $group) {
            $source = array_merge(...array_column($group, "data"));
            $cue    = $this->cue(
                $group[0]["start"],
                $group[count($group) - 1]["end"],
                implode(" ", array_column($group, "text")),
                $group,
                $group[0]["speaker"],
                $formatData + [$wordsKey => $source],
                $index,
                $source
            );
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }


    /**
     * Returns the cue, or null for an empty text. $index and $source name the segment, utterance or word group in a warning.
     * A cue that ends before it starts throws in strict mode. In lenient mode, it is swapped or skipped with a warning.
     */
    private function cue(float $start, float $end, string $text, array $words, ?string $speaker, array $formatData, int $index, mixed $source): ?SubtitleCue
    {
        $text = trim($text);
        if ($text === "") {
            return null;
        }

        try {
            [$start, $end] = $this->orderedTimes($start, $end, null, $index, [RawJson::encode($source)]);
        } catch (ParsingException $exception) {
            $this->fail($exception, null, $index, [RawJson::encode($source)]);

            return null;
        }

        $markup  = $this->formatOptions()->wordTimestamps
            ? Markup::insertWordTimestamps($text, array_map(fn (array $word): array => [$word["text"], $word["start"]], $words))
            : Markup::escapeText($text);
        $speaker = trim($speaker ?? "");
        if ($this->formatOptions()->speakerVoices && $speaker !== "") {
            $markup = Markup::voiceTag($speaker) . $markup;
        }

        return (new SubtitleCue($start, $end, $markup))->setFormatData(static::formatDataKey(), $formatData);
    }


    /**
     * Returns the words whose middle lies between $start and $end, from the word at $index on.
     * It moves $index past them.
     *
     * @param list<Word> $words
     *
     * @return list<Word>
     */
    private static function wordsBetween(array $words, int &$index, float $start, float $end): array
    {
        $result = [];
        while ($index < count($words) && ($words[$index]["start"] + $words[$index]["end"]) / 2 < $start) {
            $index++;
        }
        while ($index < count($words) && ($words[$index]["start"] + $words[$index]["end"]) / 2 <= $end) {
            $result[] = $words[$index++];
        }

        return $result;
    }
}
