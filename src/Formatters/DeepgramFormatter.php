<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class DeepgramFormatter extends SubtitleFormatter
{
    private const KEY = DeepgramParser::FORMAT_DATA_KEY;


    /**
     * Writes the JSON response of the Deepgram pre-recorded audio API. Each channel gets the transcript and the words
     * of its cues, and the paragraphs when the source had them. It writes one utterance per cue when the source had
     * utterances, or when a cue outside paragraphs has no words, as the words alone cannot hold such a cue.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $fileData = $subtitle->findFormatData(self::KEY);
        $results  = is_array($fileData["results"] ?? null) ? $fileData["results"] : [];
        $channels = is_array($results["channels"] ?? null) ? array_values($results["channels"]) : [];
        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);

        $cues          = [];
        $utterances    = [];
        $hasUtterances = false;
        foreach ($subtitle->getCues() as $cue) {
            $cueData        = $cue->findFormatData(self::KEY);
            $channel       = is_int($cueData["channel"] ?? null) && $cueData["channel"] >= 0 ? $cueData["channel"] : 0;
            $entry         = self::cue($cue, $cueData);
            $hasUtterances = $hasUtterances || isset($cueData["transcript"]) || ($entry["words"] === [] && $entry["paragraph"] === null);
            $utterances[]  = array_replace(
                ["start" => null, "end" => null, "confidence" => TranscriptWords::NEW_WORD_CONFIDENCE],
                isset($cueData["transcript"]) ? $cueData : [],
                ["start" => $entry["start"], "end" => $entry["end"], "channel" => $channel, "transcript" => $entry["text"], "words" => $entry["words"]],
                $entry["speaker"] === null ? [] : ["speaker" => $entry["speaker"]]
            );
            $cues[$channel][] = $entry;
        }

        $channelCount = max(count($channels), $cues === [] ? 1 : max(array_keys($cues)) + 1);
        for ($index = 0; $index < $channelCount; $index++) {
            $channel            = is_array($channels[$index] ?? null) ? $channels[$index] : [];
            $channel           += $index === 0 && $language !== null ? ["detected_language" => $language] : [];
            $channels[$index]   = self::channel($channel, $cues[$index] ?? []);
        }

        $results = ["channels" => $channels] + ($hasUtterances ? ["utterances" => $utterances] : []) + $results;

        return $this->applyOutputOptions(JsonOutput::document(["results" => $results] + $fileData, false), $options ?? new WriteOptions());
    }


    /**
     * Returns the start, end, text, speaker, words and paragraph of the cue.
     * A field keeps its source form when the cue did not change it.
     *
     * @return array{start: mixed, end: mixed, text: string, speaker: int|string|null, words: list<mixed>, sentence: array, paragraph: ?array}
     */
    private static function cue(SubtitleCue $cue, array $cueData): array
    {
        $words     = is_array($cueData["words"] ?? null) ? array_values($cueData["words"]) : [];
        $textKey   = isset($cueData["transcript"]) ? "transcript" : "text";
        $grouped   = !isset($cueData[$textKey]);
        $sources   = [
            "start" => $grouped ? $words[0]["start"] ?? null : $cueData["start"] ?? null,
            "end"   => $grouped ? $words[count($words) - 1]["end"] ?? null : $cueData["end"] ?? null,
            "text"  => $grouped ? implode(" ", array_map(self::wordText(...), $words)) : $cueData[$textKey],
        ];
        $start     = max(0.0, $cue->getStart());
        $end       = max(0.0, $cue->getEnd());
        $sameStart = TranscriptWords::sameTime(self::seconds($sources["start"]), $start);
        $sameEnd   = TranscriptWords::sameTime(self::seconds($sources["end"]), $end);
        $sameText  = TranscriptWords::sameText($cue, $sources["text"]);
        $voice     = TranscriptWords::speaker($cue);
        $speaker   = $voice !== null && ctype_digit($voice) ? (int) $voice : $voice;
        $words     = TranscriptWords::words(
            $cue,
            $words,
            $sameStart && $sameEnd,
            $sameText,
            fn (mixed $word): array => [self::wordText($word), self::seconds($word["start"] ?? null), self::seconds($word["end"] ?? null)],
            fn (string $text, float $wordStart, float $wordEnd): array => self::newWord($text, $wordStart, $wordEnd, $speaker),
        ) ?? [];
        if ($speaker !== null && $speaker !== ($cueData["speaker"] ?? null)) {
            $words = array_map(fn (mixed $word): mixed => is_array($word) ? array_replace($word, ["speaker" => $speaker]) : $word, $words);
        }

        $text = $sameText ? $sources["text"] : TranscriptWords::text($cue);

        return [
            "start"     => $sameStart ? $sources["start"] : $start,
            "end"       => $sameEnd ? $sources["end"] : $end,
            "text"      => $text,
            "speaker"   => $speaker,
            "words"     => $words,
            "sentence"  => $textKey === "text" && !$grouped
                ? array_diff_key($cueData, array_flip(["channel", "speaker", "paragraph", "words"]))
                : [],
            "paragraph" => is_array($cueData["paragraph"] ?? null) ? $cueData["paragraph"] : null,
        ];
    }


    /**
     * @param list<array> $cues the entries of cue() for the cues of the channel
     */
    private static function channel(array $channel, array $cues): array
    {
        $alternative = is_array($channel["alternatives"][0] ?? null) ? $channel["alternatives"][0] : [];
        $alternative = ["transcript" => implode(" ", array_column($cues, "text"))] + $alternative
            + ["words" => array_merge([], ...array_column($cues, "words"))];
        if (array_filter(array_column($cues, "paragraph")) !== []) {
            $alternative["paragraphs"] = self::paragraphs($cues);
        }
        return ["alternatives" => [$alternative]] + $channel;
    }


    /**
     * Returns the paragraphs of the cues. A cue starts a new paragraph when its stored paragraph differs from the one of the
     * cue before it. A cue without a stored paragraph joins the paragraph before it when it has the same speaker.
     */
    private static function paragraphs(array $cues): array
    {
        $paragraphs = [];
        $previous   = null;
        foreach ($cues as $cue) {
            $last = count($paragraphs) - 1;
            $join = $last >= 0 && ($cue["paragraph"] === null ? $cue["speaker"] === $paragraphs[$last]["speaker"] : $cue["paragraph"] === $previous);
            if (!$join) {
                $paragraphs[] = ["source" => $cue["paragraph"] ?? [], "speaker" => $cue["speaker"], "cues" => []];
                $last++;
            }
            $paragraphs[$last]["cues"][] = $cue;
            $previous                    = $cue["paragraph"];
        }

        $transcript = "";
        $result     = [];
        foreach ($paragraphs as $paragraph) {
            $sentences = array_map(fn (array $cue): array => array_replace($cue["sentence"], ["text" => $cue["text"], "start" => $cue["start"], "end" => $cue["end"]]), $paragraph["cues"]);
            $first     = $paragraph["cues"][0];
            $last      = $paragraph["cues"][count($paragraph["cues"]) - 1];
            $result[]  = array_replace(
                ["sentences" => $sentences] + ($paragraph["speaker"] === null ? [] : ["speaker" => $paragraph["speaker"]]),
                $paragraph["source"],
                ["sentences" => $sentences, "num_words" => count(array_merge([], ...array_column($paragraph["cues"], "words"))),
                 "start" => $first["start"], "end" => $last["end"]],
                $paragraph["speaker"] === null ? [] : ["speaker" => $paragraph["speaker"]]
            );
            $speaker     = $paragraph["speaker"] === null ? "" : "Speaker {$paragraph["speaker"]}: ";
            $transcript .= "\n" . $speaker . implode(" ", array_column($sentences, "text")) . "\n";
        }

        return ["transcript" => substr($transcript, 0, -1), "paragraphs" => $result];
    }


    private static function newWord(string $text, float $start, float $end, int|string|null $speaker): array
    {
        $word = trim(mb_strtolower($text, "UTF-8"), " \t\n\r\0\x0B.,?!;:\"'()[]");

        return ["word" => $word === "" ? $text : $word, "start" => $start, "end" => $end, "confidence" => TranscriptWords::NEW_WORD_CONFIDENCE]
            + ($speaker === null ? [] : ["speaker" => $speaker])
            + ["punctuated_word" => $text];
    }


    private static function wordText(mixed $word): string
    {
        $text = is_array($word) ? $word["punctuated_word"] ?? $word["word"] ?? "" : "";

        return is_string($text) ? trim($text) : "";
    }


    private static function seconds(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
