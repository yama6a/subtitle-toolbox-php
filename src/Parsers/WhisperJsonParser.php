<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;

final class WhisperJsonParser extends SubtitleParser
{
    use WordGrouping;

    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Whisper->value;


    protected static function formatDataKey(): string
    {
        return self::FORMAT_DATA_KEY;
    }


    /**
     * Reads the JSON of the OpenAI transcription API, openai-whisper, faster-whisper, WhisperX and whisper.cpp.
     * It makes one cue per segment.
     */
    protected function read(string $content): Subtitle
    {
        $data = $this->decodeJsonObject($content);

        $segments = match (true) {
            self::isList($data["segments"] ?? null)      => $this->readSegments($data["segments"], $data["words"] ?? null),
            self::isList($data["transcription"] ?? null) => $this->readTranscription($data["transcription"]),
            default                                  => throw new ParsingException("The JSON has no \"segments\" or \"transcription\" list."),
        };

        $cues = [];
        foreach ($segments as [$start, $end, $text, $words, $formatData, $index, $segment]) {
            $speaker = is_string($formatData["speaker"] ?? null) ? $formatData["speaker"] : null;
            $cue     = $this->cue($start, $end, $text, $words, $speaker, $formatData, $index, $segment);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        $language = $data["language"] ?? $data["result"]["language"] ?? null;
        $language = is_string($language) ? WhisperLanguages::CODES[strtolower($language)] ?? $language : $language;
        $fileData = array_diff_key($data, array_flip(["segments", "transcription", "words", "word_segments", "text"]));

        return $this->transcript($cues, $language, $fileData);
    }


    private function readSegments(array $segments, mixed $topLevelWords): array
    {
        $topLevelWords = is_array($topLevelWords) ? array_values($topLevelWords) : [];
        $wordIndex     = 0;
        $lastIndex     = array_key_last($segments);
        $result        = [];

        foreach ($segments as $index => $segment) {
            $path = "segments[$index]";
            try {
                $start = self::boundedField($this->number($segment, "start", $path), "$path.start");
                $end   = self::boundedField($this->number($segment, "end", $path), "$path.end");
                $text  = $this->text($segment, "text", $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }
            $words     = is_array($segment["words"] ?? null) ? $segment["words"] : [];
            $wordPaths = array_map(fn (int|string $key): string => "$path.words[$key]", array_keys($words));

            if ($topLevelWords !== []) {
                // The API lists the words of all segments at the top level. A word belongs to the segment that holds its middle.
                while ($wordIndex < count($topLevelWords)
                       && ($index === $lastIndex || $this->middle($topLevelWords[$wordIndex]) <= $end)) {
                    $wordPaths[] = "words[$wordIndex]";
                    $words[]     = $topLevelWords[$wordIndex++];
                }
                $segment["words"] = $words;
            }

            $timedWords = [];
            try {
                foreach (array_values($words) as $position => $word) {
                    $timedWords[] = [
                        "text"  => is_string($word["word"] ?? null) ? trim($word["word"]) : "",
                        "start" => self::isTime($word["start"] ?? null)
                            ? self::boundedField(Timecode::roundToMilliseconds($word["start"]), "$wordPaths[$position].start")
                            : null,
                    ];
                }
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }

            $result[] = [$start, $end, $text, $timedWords, array_diff_key($segment, array_flip(["start", "end", "text"])), $index, $segment];
        }

        return $result;
    }


    private function readTranscription(array $transcription): array
    {
        $result = [];
        foreach ($transcription as $index => $segment) {
            $path    = "transcription[$index]";
            $offsets = is_array($segment) ? $segment["offsets"] ?? null : null;
            try {
                $start = self::boundedField(Timecode::roundToMilliseconds($this->number($offsets, "from", "$path.offsets") / 1000), "$path.offsets.from");
                $end   = self::boundedField(Timecode::roundToMilliseconds($this->number($offsets, "to", "$path.offsets") / 1000), "$path.offsets.to");
                $text  = $this->text($segment, "text", $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }

            // A token with a leading space starts a new word, as in should_split_on_word() of whisper.cpp.
            $words = [];
            try {
                foreach (is_array($segment["tokens"] ?? null) ? $segment["tokens"] : [] as $tokenIndex => $token) {
                    $tokenText = $token["text"] ?? null;
                    if (!is_string($tokenText) || str_starts_with($tokenText, "[_")) {
                        continue;
                    }

                    if ($words === [] || str_starts_with($tokenText, " ")) {
                        $from    = $token["offsets"]["from"] ?? null;
                        $words[] = ["text" => "", "start" => self::isTime($from)
                            ? self::boundedField(Timecode::roundToMilliseconds($from / 1000), "$path.tokens[$tokenIndex].offsets.from")
                            : null];
                    }
                    $words[count($words) - 1]["text"] .= $tokenText;
                }
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }

            $result[] = [
                $start,
                $end,
                $text,
                array_map(fn (array $word): array => ["text" => trim($word["text"]), "start" => $word["start"]], $words),
                array_diff_key($segment, array_flip(["timestamps", "offsets", "text"])),
                $index,
                $segment,
            ];
        }

        return $result;
    }


    private function number(mixed $object, string $key, string $path): float
    {
        $value = is_array($object) ? $object[$key] ?? null : null;
        if ((!is_int($value) && !is_float($value)) || !is_finite($value)) {
            throw new ParsingException("The field $path.$key must be a number.");
        }

        if (!self::isTime($value)) {
            throw new ParsingException("The field $path.$key must be a time of 0 or more.");
        }

        return Timecode::roundToMilliseconds($value);
    }


    private function middle(mixed $word): float
    {
        $start = $word["start"] ?? null;
        $end   = $word["end"] ?? $start;

        return is_numeric($start) && is_numeric($end) ? ($start + $end) / 2 : -INF;
    }
}
