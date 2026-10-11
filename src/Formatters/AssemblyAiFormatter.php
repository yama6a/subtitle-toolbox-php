<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Parsers\AssemblyAiParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class AssemblyAiFormatter extends SubtitleFormatter
{
    private const KEY = AssemblyAiParser::FORMAT_DATA_KEY;


    /**
     * Writes the JSON of an AssemblyAI transcript: the text and the words of the cues, times in milliseconds.
     * It writes one utterance per cue when the source had utterances, when a cue has a speaker, or when a cue has no
     * words, as the words alone cannot hold such a cue. Else "utterances" is null, as AssemblyAI writes it.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $fileData = $subtitle->findFormatData(self::KEY);
        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        if ($language !== null && !isset($fileData["language_code"])) {
            $fileData["language_code"] = strtolower(str_replace("-", "_", $language));
        }

        $words         = [];
        $utterances    = [];
        $hasUtterances = false;
        foreach ($subtitle->getCues() as $cue) {
            $utterance     = self::utterance($cue, $cue->findFormatData(self::KEY));
            $hasUtterances = $hasUtterances || $utterance["words"] === [] || $utterance["speaker"] !== null
                || isset($cue->findFormatData(self::KEY)["text"]);
            $utterances[]  = $utterance;
            array_push($words, ...$utterance["words"]);
        }

        $document = ["text" => implode(" ", array_column($utterances, "text")), "words" => $words, "utterances" => $hasUtterances ? $utterances : null];

        return $this->applyOutputOptions(JsonOutput::document(array_replace($fileData, $document), false), $options ?? new WriteOptions());
    }


    private static function utterance(SubtitleCue $cue, array $cueData): array
    {
        $words     = is_array($cueData["words"] ?? null) ? array_values($cueData["words"]) : [];
        $grouped   = !isset($cueData["text"]);
        $sources   = [
            "start" => $grouped ? $words[0]["start"] ?? null : $cueData["start"] ?? null,
            "end"   => $grouped ? $words[count($words) - 1]["end"] ?? null : $cueData["end"] ?? null,
            "text"  => $grouped ? implode(" ", array_map(self::wordText(...), $words)) : $cueData["text"],
        ];
        $start     = max(0.0, $cue->getStart());
        $end       = max(0.0, $cue->getEnd());
        $sameStart = TranscriptWords::sameTime(self::seconds($sources["start"]), $start);
        $sameEnd   = TranscriptWords::sameTime(self::seconds($sources["end"]), $end);
        $sameText  = TranscriptWords::sameText($cue, $sources["text"]);
        $speaker   = TranscriptWords::speaker($cue);
        $words     = TranscriptWords::words(
            $cue,
            $words,
            $sameStart && $sameEnd,
            $sameText,
            fn (mixed $word): array => [self::wordText($word), self::seconds($word["start"] ?? null), self::seconds($word["end"] ?? null)],
            fn (string $text, float $wordStart, float $wordEnd): array => [
                "text"       => $text,
                "start"      => self::milliseconds($wordStart),
                "end"        => self::milliseconds($wordEnd),
                "confidence" => TranscriptWords::NEW_WORD_CONFIDENCE,
                "speaker"    => $speaker,
            ],
        ) ?? [];
        if ($speaker !== null && $speaker !== ($cueData["speaker"] ?? null)) {
            $words = array_map(fn (mixed $word): mixed => is_array($word) ? array_replace($word, ["speaker" => $speaker]) : $word, $words);
        }

        return array_replace(
            $grouped ? ["confidence" => TranscriptWords::NEW_WORD_CONFIDENCE] : $cueData,
            [
                "end"     => $sameEnd ? $sources["end"] : self::milliseconds($end),
                "speaker" => $speaker ?? ($grouped ? null : $cueData["speaker"] ?? null),
                "start"   => $sameStart ? $sources["start"] : self::milliseconds($start),
                "text"    => $sameText ? $sources["text"] : TranscriptWords::text($cue),
                "words"   => $words,
            ]
        );
    }


    private static function wordText(mixed $word): string
    {
        return is_array($word) && is_string($word["text"] ?? null) ? trim($word["text"]) : "";
    }


    private static function seconds(mixed $milliseconds): ?float
    {
        return is_int($milliseconds) || is_float($milliseconds) ? $milliseconds / 1000 : null;
    }


    private static function milliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
