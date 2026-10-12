<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Parsers\GoogleSpeechParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class GoogleSpeechFormatter extends SubtitleFormatter
{
    private const KEY = GoogleSpeechParser::FORMAT_DATA_KEY;

    private const ALTERNATIVE_FIELDS = ["transcript" => true, "confidence" => true, "words" => true];

    private const OPERATION_FIELDS = ["name" => true, "metadata" => true, "done" => true, "error" => true];


    /**
     * Writes the JSON response of Google Cloud Speech-to-Text with the V1 or V2 field names of the source, V1 without a source.
     * It writes one result per cue. A source with speaker diarization gives one result with all words and their speakers.
     * A long-running operation from the source stays around the response.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $fileData = $subtitle->findFormatData(self::KEY);
        $cues     = $subtitle->getCues();
        $cueData  = array_map(fn (SubtitleCue $cue): array => $cue->findFormatData(self::KEY), $cues);
        $v2       = self::isV2($fileData, $cueData);
        $results  = is_array($fileData["results"] ?? null) ? $fileData["results"] : null;
        $response = array_diff_key($fileData, ["results" => true]);

        if ($results === null) {
            $results     = [];
            $previousEnd = 0.0;
            foreach ($cues as $index => $cue) {
                $results[] = self::result($cue, $cueData[$index], $v2, $subtitle, $previousEnd);
            }
        } else {
            $results = [self::diarizedResult($cues, $cueData, is_array($results[0] ?? null) ? $results[0] : [], $v2)];
        }

        $isOperation = isset($fileData["done"]) || isset($fileData["name"]);
        $response    = ["results" => $results] + array_diff_key($response, $isOperation ? self::OPERATION_FIELDS : []);
        $document    = $isOperation ? array_intersect_key($fileData, self::OPERATION_FIELDS) + ["response" => $response] : $response;

        return $this->applyOutputOptions(JsonOutput::document($document, false), $options ?? new WriteOptions());
    }


    private static function isV2(array $fileData, array $cueData): bool
    {
        $results = is_array($fileData["results"] ?? null) ? $fileData["results"] : [];
        foreach ([...$results, ...$cueData] as $data) {
            $word = is_array($data["words"][0] ?? null) ? $data["words"][0] : [];
            if (isset($data["resultEndOffset"]) || isset($word["startOffset"])) {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns the result of a cue: the fields of the source result, and its first alternative with the transcript and the words.
     */
    /**
     * The result end of an overlapping cue moves to $previousEnd, because results follow each other in the audio.
     */
    private static function result(SubtitleCue $cue, array $cueData, bool $v2, Subtitle $subtitle, float &$previousEnd): array
    {
        $entry       = self::cue($cue, $cueData, $v2, false);
        $endKey      = isset($cueData["resultEndTime"]) || (!$v2 && !isset($cueData["resultEndOffset"])) ? "resultEndTime" : "resultEndOffset";
        $alternative = array_replace(
            ["transcript" => null],
            array_intersect_key($cueData, self::ALTERNATIVE_FIELDS),
            ["transcript" => $entry["text"]],
            $entry["words"] === [] && !isset($cueData["words"]) ? [] : ["words" => $entry["words"]]
        );
        $language    = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        $fields      = array_diff_key($cueData, self::ALTERNATIVE_FIELDS)
            + [$endKey => null] + ($cueData === [] && $language !== null ? ["languageCode" => $language] : []);
        $end             = max($previousEnd, $cue->getEnd());
        $fields[$endKey] = $entry["sameTimes"] && isset($cueData[$endKey]) ? $cueData[$endKey] : self::duration($end);
        $previousEnd     = self::seconds($fields[$endKey]) ?? $end;

        return ["alternatives" => [$alternative]] + $fields;
    }


    /**
     * Returns the one result of a source with speaker diarization: all words with their speakers.
     *
     * @param list<SubtitleCue> $cues
     */
    private static function diarizedResult(array $cues, array $cueData, array $source, bool $v2): array
    {
        $texts = [];
        $words = [];
        foreach ($cues as $index => $cue) {
            $entry = self::cue($cue, $cueData[$index], $v2, true);
            if ($entry["words"] === []) {
                throw new UnwritableContentException("A Google Speech-to-Text result with speaker diarization holds cues only through their words. "
                    . "Cue #$index at {$cue->getStart()} s has no words. Read the file with word timestamps before you change the number of words.");
            }
            $texts[] = $entry["text"];
            array_push($words, ...$entry["words"]);
        }

        $alternative = is_array($source["alternatives"][0] ?? null) ? $source["alternatives"][0] : [];
        $alternative = ["transcript" => implode(" ", $texts)] + $alternative + ["words" => $words];

        return ["alternatives" => [$alternative]] + $source;
    }


    /**
     * Returns the text and the words of the cue, and whether the cue has the times of its source.
     *
     * @return array{text: string, words: list<mixed>, sameTimes: bool}
     */
    private static function cue(SubtitleCue $cue, array $cueData, bool $v2, bool $diarized): array
    {
        $words     = is_array($cueData["words"] ?? null) ? array_values($cueData["words"]) : [];
        $text      = $diarized || !isset($cueData["transcript"]) ? implode(" ", array_map(self::wordText(...), $words)) : $cueData["transcript"];
        $start     = max(0.0, $cue->getStart());
        $end       = max(0.0, $cue->getEnd());
        $sameStart = $words === [] || TranscriptWords::sameTime(self::wordTime($words[0], "start"), $start);
        $sameEnd   = TranscriptWords::sameTime($words === [] ? self::seconds($cueData["resultEndTime"] ?? $cueData["resultEndOffset"] ?? null)
            : self::wordTime($words[count($words) - 1], "end"), $end);
        $sameText  = TranscriptWords::sameText($cue, $text);
        $voice     = $diarized ? TranscriptWords::speaker($cue) : null;
        $speaker   = $voice === null ? null : ($v2 ? ["speakerLabel" => $voice] : ["speakerTag" => preg_match('/^[0-9]+$/D', $voice) === 1 ? (int) $voice : $voice]);
        $confident = array_filter($words, fn (mixed $word): bool => isset($word["confidence"])) !== [];
        $words     = TranscriptWords::words(
            $cue,
            $words,
            $sameStart && $sameEnd,
            $sameText,
            fn (mixed $word): array => [self::wordText($word), self::wordTime($word, "start"), self::wordTime($word, "end")],
            fn (string $word, float $wordStart, float $wordEnd): array => [
                ($v2 ? "startOffset" : "startTime") => self::duration($wordStart),
                ($v2 ? "endOffset" : "endTime")     => self::duration($wordEnd),
                "word"                              => $word,
            ] + ($confident ? ["confidence" => TranscriptWords::NEW_WORD_CONFIDENCE] : []) + ($speaker ?? []),
        ) ?? [];
        if ($speaker !== null) {
            $words = array_map(fn (mixed $word): mixed => is_array($word) ? array_replace($word, $speaker) : $word, $words);
        }

        $space = is_string($cueData["transcript"] ?? null) && str_starts_with($cueData["transcript"], " ") ? " " : "";

        return ["text" => $sameText ? $text : $space . TranscriptWords::text($cue), "words" => $words, "sameTimes" => $sameStart && $sameEnd];
    }


    private static function wordText(mixed $word): string
    {
        return is_array($word) && is_string($word["word"] ?? null) ? trim($word["word"]) : "";
    }


    private static function wordTime(mixed $word, string $edge): ?float
    {
        return is_array($word) ? self::seconds($word["{$edge}Time"] ?? $word["{$edge}Offset"] ?? null) : null;
    }


    /**
     * Returns the seconds of a protobuf Duration such as "1.300s", or of an object with seconds and nanos, as the parser reads them.
     */
    private static function seconds(mixed $value): ?float
    {
        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)s$/', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (is_array($value) && (isset($value["seconds"]) || isset($value["nanos"])) && is_numeric($value["seconds"] ?? 0) && is_numeric($value["nanos"] ?? 0)) {
            return (float) ($value["seconds"] ?? 0) + (float) ($value["nanos"] ?? 0) / 1e9;
        }

        return null;
    }


    /**
     * Formats seconds as the JSON of a protobuf Duration with 0 or 3 fraction digits, for example "6s" and "1.500s".
     */
    private static function duration(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return $milliseconds % 1000 === 0 ? intdiv($milliseconds, 1000) . "s" : sprintf("%d.%03ds", intdiv($milliseconds, 1000), $milliseconds % 1000);
    }
}
