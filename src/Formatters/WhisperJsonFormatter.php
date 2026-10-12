<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\WhisperJsonVariant as Variant;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class WhisperJsonFormatter extends SubtitleFormatter
{
    /**
     * Writes Whisper JSON in the shape of the tool that wrote the source, one segment per cue.
     * The "format" field of the subtitle format data names the tool. Without it, the shape is the one of openai-whisper.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $fileData = $subtitle->findFormatData(WhisperJsonParser::FORMAT_DATA_KEY);
        $variant  = Variant::tryFrom(is_string($fileData["format"] ?? null) ? $fileData["format"] : "") ?? Variant::OpenAiWhisper;
        unset($fileData["format"]);
        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        if ($language !== null && $variant !== Variant::WhisperCpp && !isset($fileData["language"])) {
            $fileData["language"] = $language;
        }

        $segments = [];
        $words    = [];
        foreach ($subtitle->getCues() as $cue) {
            if ($variant === Variant::WhisperCpp) {
                $segments[] = self::whisperCppSegment($cue);
                continue;
            }
            $segment = self::segment($cue, $variant, $segments === [] ? null : $segments[count($segments) - 1]["id"] ?? null);
            array_push($words, ...(is_array($segment["words"] ?? null) ? $segment["words"] : []));
            $segments[] = $variant === Variant::OpenAiApi ? array_diff_key($segment, ["words" => true]) : $segment;
        }
        $text = implode("", array_map(fn (array $segment): string => $segment["text"], $segments));

        $document = match ($variant) {
            Variant::OpenAiWhisper => ["text" => $text, "segments" => $segments] + $fileData,
            Variant::OpenAiApi     => $fileData + ["text" => trim($text)] + ($words === [] ? [] : ["words" => $words]) + ["segments" => $segments],
            Variant::WhisperX      => ["segments" => $segments, "word_segments" => $words] + $fileData,
            Variant::WhisperCpp    => $fileData + ["transcription" => $segments],
        };

        return $this->applyOutputOptions(JsonOutput::document($document, false), $options ?? new WriteOptions());
    }


    private static function segment(SubtitleCue $cue, Variant $variant, mixed $previousId): array
    {
        $cueData    = $cue->findFormatData(WhisperJsonParser::FORMAT_DATA_KEY);
        $start     = max(0.0, $cue->getStart());
        $end       = max(0.0, $cue->getEnd());
        $sameStart = TranscriptWords::sameTime(self::seconds($cueData["start"] ?? null), $start);
        $sameEnd   = TranscriptWords::sameTime(self::seconds($cueData["end"] ?? null), $end);
        $sameText  = TranscriptWords::sameText($cue, $cueData["text"] ?? null);
        $space     = !is_string($cueData["text"] ?? null) || str_starts_with($cueData["text"], " ") ? " " : "";
        $speaker   = TranscriptWords::speaker($cue);
        $words     = TranscriptWords::words(
            $cue,
            is_array($cueData["words"] ?? null) ? array_values($cueData["words"]) : [],
            $sameStart && $sameEnd,
            $sameText,
            fn (mixed $word): array => [trim(is_string($word["word"] ?? null) ? $word["word"] : ""), self::seconds($word["start"] ?? null), self::seconds($word["end"] ?? null)],
            fn (string $text, float $wordStart, float $wordEnd): array => self::newWord($variant, $text, $wordStart, $wordEnd, $speaker),
        );

        $segment = array_replace(
            $variant === Variant::WhisperX || isset($cueData["id"]) ? [] : ["id" => is_int($previousId) ? $previousId + 1 : 0],
            $cueData,
            [
                "start" => $sameStart ? $cueData["start"] : $start,
                "end"   => $sameEnd ? $cueData["end"] : $end,
                "text"  => $sameText ? $cueData["text"] : $space . TranscriptWords::text($cue),
            ]
        );
        if (!$sameText) {
            unset($segment["tokens"]);
        }
        if ($words !== null) {
            $segment["words"] = $words;
        } elseif (is_array($segment["words"] ?? null)) {
            unset($segment["words"]);
        }
        if ($speaker !== null && $speaker !== ($cueData["speaker"] ?? null)) {
            $segment["speaker"] = $speaker;
            if ($words !== null) {
                $segment["words"] = array_map(fn (mixed $word): mixed => is_array($word) && isset($word["speaker"]) ? array_replace($word, ["speaker" => $speaker]) : $word, $words);
            }
        }

        return $segment;
    }


    private static function newWord(Variant $variant, string $text, float $start, float $end, ?string $speaker): array
    {
        $times = ["start" => $start, "end" => $end];

        return match ($variant) {
            Variant::OpenAiApi => ["word" => $text] + $times,
            Variant::WhisperX  => ["word" => $text] + $times + ["score" => TranscriptWords::NEW_WORD_CONFIDENCE] + ($speaker === null ? [] : ["speaker" => $speaker]),
            default            => ["word" => " $text"] + $times + ["probability" => TranscriptWords::NEW_WORD_CONFIDENCE],
        };
    }


    /**
     * Returns the "transcription" entry of whisper.cpp. Its tokens stay when the cue has the text, the times and the
     * word timestamps of the source. Else the entry has no tokens, as whisper.cpp writes them only with -ojf.
     */
    private static function whisperCppSegment(SubtitleCue $cue): array
    {
        $cueData  = $cue->findFormatData(WhisperJsonParser::FORMAT_DATA_KEY);
        $offsets = [
            "from" => (int) round(max(0.0, $cue->getStart()) * 1000),
            "to"   => (int) round(max(0.0, $cue->getEnd()) * 1000),
        ];
        $sameTimes = ($cueData["offsets"] ?? null) === $offsets;
        $sameText  = TranscriptWords::sameText($cue, $cueData["text"] ?? null);
        $tokens    = is_array($cueData["tokens"] ?? null) ? $cueData["tokens"] : [];
        $sameWords = TranscriptWords::sameWords($cue, array_map(
            fn (array $word): array => [$word[0], is_int($word[1]) || is_float($word[1]) ? $word[1] / 1000 : null],
            WhisperJsonParser::tokenWords($tokens)
        ));

        $segment = array_replace($cueData, [
            "timestamps" => $sameTimes && isset($cueData["timestamps"])
                ? $cueData["timestamps"]
                : ["from" => self::clock($offsets["from"]), "to" => self::clock($offsets["to"])],
            "offsets"    => $offsets,
            "text"       => $sameText ? $cueData["text"] : " " . TranscriptWords::text($cue),
        ]);
        if (!$sameTimes || !$sameText || !$sameWords) {
            unset($segment["tokens"]);
        }

        return $segment;
    }


    private static function seconds(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }


    /**
     * Formats milliseconds as whisper.cpp does, for example "00:01:02,500".
     */
    private static function clock(int $milliseconds): string
    {
        return sprintf("%02d:%02d:%02d,%03d", ...Timecode::milliseconds($milliseconds / 1000));
    }
}
