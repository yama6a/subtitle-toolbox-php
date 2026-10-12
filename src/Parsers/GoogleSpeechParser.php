<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;

final class GoogleSpeechParser extends SubtitleParser
{
    use WordGrouping;

    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::GoogleSpeech->value;


    protected static function formatDataKey(): string
    {
        return self::FORMAT_DATA_KEY;
    }


    /**
     * Reads the JSON response of Google Cloud Speech-to-Text V1 and V2, one cue per result.
     * With speaker diarization, it groups the words of the last result into cues.
     */
    protected function read(string $content): Subtitle
    {
        $data     = $this->decodeJsonObject($content);
        $fileData = [];
        if (is_array($data["response"] ?? null)) {
            $fileData = array_diff_key($data, ["response" => true]);
            $data     = $data["response"];
        }
        if (!self::isList($data["results"] ?? null)) {
            throw new ParsingException("The JSON has no \"results\" list.");
        }

        $results   = $data["results"];
        $lastIndex = count($results) - 1;
        $lastWords = self::listOrEmpty($results[$lastIndex]["alternatives"][0]["words"] ?? null);
        // With diarization, each result repeats the words of the results before it, so the last result holds all words.
        $diarized = array_filter($lastWords, fn (mixed $word): bool => isset($word["speakerLabel"]) || !empty($word["speakerTag"])) !== [];
        $cues     = $diarized
            ? $this->cuesFromWords($this->readWords($lastWords, "results[$lastIndex].alternatives[0].words"), "words")
            : $this->readResults($results);

        $language = null;
        foreach ($results as $result) {
            if (is_string($result["languageCode"] ?? null) && $result["languageCode"] !== "") {
                $language = $result["languageCode"];
                break;
            }
        }

        if ($diarized) {
            $fileData["results"] = [self::withoutWords($results[$lastIndex])];
        }

        return $this->transcript($cues, $language, $fileData + array_diff_key($data, ["results" => true]));
    }


    /**
     * Returns the fields of a result and of its first alternative, without the transcript and the words.
     */
    private static function withoutWords(mixed $result): mixed
    {
        if (!is_array($result) || !is_array($result["alternatives"][0] ?? null)) {
            return $result;
        }

        return ["alternatives" => [array_diff_key($result["alternatives"][0], ["transcript" => true, "words" => true])]] + $result;
    }


    private function readResults(array $results): array
    {
        $cues        = [];
        $previousEnd = 0.0;
        foreach ($results as $index => $result) {
            $path      = "results[$index]";
            $resultEnd = is_array($result) ? self::duration($result["resultEndTime"] ?? $result["resultEndOffset"] ?? null) : null;
            try {
                $alternative = $this->firstAlternative($result, $path);
                $words       = $this->readWords(self::listOrEmpty($alternative["words"] ?? null), "$path.alternatives[0].words");
                $end         = $words === [] ? $this->seconds($resultEnd, "$path.resultEndTime") : $words[count($words) - 1]["end"];
                $start       = $words === [] ? $previousEnd : $words[0]["start"];
                $text        = is_array($alternative) ? $this->text($alternative, "transcript", "$path.alternatives[0]") : "";
                $resultEnd   = self::isTime($resultEnd) ? $this->seconds($resultEnd, "$path.resultEndTime") : $end;
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($result)]);
                continue;
            }
            $previousEnd = $resultEnd;

            $formatData = array_diff_key($result, ["alternatives" => true]) + ($alternative ?? []);
            $cue        = $this->cue($start, $end, $text, $words, null, $formatData, $index, $result);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }


    private function readWords(array $words, string $path): array
    {
        return $this->readWordList($words, $path, fn (mixed $word, string $wordPath): array => [
            $this->text($word, "word", $wordPath),
            $this->seconds(self::duration($word["startTime"] ?? $word["startOffset"] ?? null), "$wordPath.startTime"),
            $this->seconds(self::duration($word["endTime"] ?? $word["endOffset"] ?? null), "$wordPath.endTime"),
            self::speaker($word["speakerLabel"] ?? (($word["speakerTag"] ?? 0) === 0 ? null : $word["speakerTag"])),
        ]);
    }


    /**
     * Returns the first alternative of a result, or null for a result without alternatives.
     */
    private function firstAlternative(mixed $result, string $path): ?array
    {
        $alternatives = is_array($result) ? $result["alternatives"] ?? null : null;
        if ($alternatives === null || $alternatives === []) {
            return null;
        }
        if (!is_array($alternatives[0] ?? null)) {
            throw new ParsingException("The field $path.alternatives must be a list of objects.");
        }

        return $alternatives[0];
    }


    /**
     * Returns the seconds of a protobuf Duration in JSON, such as "1.300s", or of an object with seconds and nanos.
     */
    private static function duration(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(-?\d+(?:\.\d+)?)s$/', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (is_array($value) && (isset($value["seconds"]) || isset($value["nanos"]))
            && is_numeric($value["seconds"] ?? 0) && is_numeric($value["nanos"] ?? 0)) {
            return (float) ($value["seconds"] ?? 0) + (float) ($value["nanos"] ?? 0) / 1e9;
        }

        return $value;
    }
}
