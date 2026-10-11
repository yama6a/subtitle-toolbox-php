<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Parsers\AwsTranscribeParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class AwsTranscribeFormatter extends SubtitleFormatter
{
    private const KEY = AwsTranscribeParser::FORMAT_DATA_KEY;


    /**
     * Writes the JSON transcript of an Amazon Transcribe batch job: the transcript, one item per word and punctuation
     * mark, and the speaker segments when a cue has a speaker. It writes one audio segment per cue when the source had
     * them, or when a cue has no words, as the items alone cannot hold such a cue.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $fileData = $subtitle->findFormatData(self::KEY);
        $results  = is_array($fileData["results"] ?? null) ? $fileData["results"] : [];
        $language = $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE);
        if ($language !== null && !isset($results["language_code"])) {
            $results["language_code"] = $language;
        }

        $items       = [];
        $segments    = [];
        $hasSegments = false;
        foreach ($subtitle->getCues() as $cue) {
            [$segment, $cueItems] = self::segment($cue, count($items));
            array_push($items, ...$cueItems);
            $segments[]   = ["id" => count($segments)] + $segment;
            $hasSegments = $hasSegments || isset($cue->findFormatData(self::KEY)["transcript"]) || $cueItems === [];
        }

        $speakerSegments = self::speakerSegments($items);
        $results         = ["transcripts" => [["transcript" => implode(" ", array_column($segments, "transcript"))]]]
            + ($speakerSegments === [] ? [] : ["speaker_labels" => self::speakerLabels($results, $speakerSegments)])
            + ["items" => $items]
            + ($hasSegments ? ["audio_segments" => $segments] : [])
            + array_diff_key($results, ["speaker_labels" => true]);

        return $this->applyOutputOptions(JsonOutput::document(["results" => $results] + $fileData, false), $options ?? new WriteOptions());
    }


    /**
     * Returns the audio segment of the cue and its items. The items get the IDs from $firstId on.
     *
     * @return array{array, list<array>}
     */
    private static function segment(SubtitleCue $cue, int $firstId): array
    {
        $cueData  = $cue->findFormatData(self::KEY);
        $words   = self::words(is_array($cueData["items"] ?? null) ? array_values($cueData["items"]) : []);
        $start   = max(0.0, $cue->getStart());
        $end     = max(0.0, $cue->getEnd());
        $times   = array_merge([], ...array_map(fn (array $word): array => array_filter(array_column($word, "end_time")), $words));
        $grouped = !isset($cueData["transcript"]);
        $speaker = TranscriptWords::speaker($cue);
        $sources = [
            "start_time" => $grouped ? self::wordStart($words[0] ?? []) : $cueData["start_time"] ?? null,
            "end_time"   => $grouped ? $times[count($times) - 1] ?? null : $cueData["end_time"] ?? null,
            "transcript" => $grouped ? implode(" ", array_map(self::wordText(...), $words)) : $cueData["transcript"],
        ];

        $sameStart = TranscriptWords::sameTime(self::seconds($sources["start_time"]), $start);
        $sameEnd   = TranscriptWords::sameTime(self::seconds($sources["end_time"]), $end);
        $sameText  = TranscriptWords::sameText($cue, $sources["transcript"]);
        $words     = TranscriptWords::words(
            $cue,
            $words,
            $sameStart && $sameEnd,
            $sameText,
            fn (array $word): array => [self::wordText($word), self::seconds(self::wordStart($word)), self::seconds(self::wordEnd($word))],
            fn (string $text, float $wordStart, float $wordEnd): array => self::newItems($text, $wordStart, $wordEnd, $speaker),
        ) ?? [];

        $items = [];
        foreach (array_merge([], ...$words) as $item) {
            $item = is_array($item) ? $item : [];
            if ($speaker !== null && ($speaker !== ($cueData["speaker_label"] ?? null) || !isset($item["speaker_label"]))) {
                $item["speaker_label"] = $speaker;
            }
            $items[] = array_replace(["id" => $firstId + count($items)], $item, ["id" => $firstId + count($items)]);
        }

        $segment = array_replace(array_diff_key($cueData, ["id" => true]), [
            "transcript" => $sameText ? $sources["transcript"] : TranscriptWords::text($cue),
            "start_time" => $sameStart ? $sources["start_time"] : self::time($start),
            "end_time"   => $sameEnd ? $sources["end_time"] : self::time($end),
        ], $speaker === null ? [] : ["speaker_label" => $speaker], ["items" => array_column($items, "id")]);

        return [$segment, $items];
    }


    /**
     * Returns the words of the items, as the parser joins them: each pronunciation item with the punctuation items after it.
     * Punctuation before the first word goes to the first word.
     *
     * @return list<list<mixed>>
     */
    private static function words(array $items): array
    {
        $words   = [];
        $leading = [];
        foreach ($items as $item) {
            if (($item["type"] ?? null) !== "punctuation") {
                $words[] = [...$leading, $item];
                $leading = [];
            } elseif ($words === []) {
                $leading[] = $item;
            } else {
                $words[count($words) - 1][] = $item;
            }
        }

        return $leading === [] ? $words : [...$words, $leading];
    }


    private static function wordStart(array $word): mixed
    {
        return array_column($word, "start_time")[0] ?? null;
    }


    private static function wordEnd(array $word): mixed
    {
        $ends = array_column($word, "end_time");

        return $ends === [] ? null : $ends[count($ends) - 1];
    }


    private static function wordText(array $word): string
    {
        return implode("", array_map(fn (mixed $item): string => is_string($item["alternatives"][0]["content"] ?? null) ? $item["alternatives"][0]["content"] : "", $word));
    }


    /**
     * Returns a pronunciation item for a word, and a punctuation item for the punctuation at its end.
     */
    private static function newItems(string $text, float $start, float $end, ?string $speaker): array
    {
        preg_match('/\A(.*?)(\p{P}*)\z/su', $text, $parts);
        $speakerLabel = $speaker === null ? [] : ["speaker_label" => $speaker];
        $items        = [];
        if ($parts[1] !== "" || $parts[2] === "") {
            $items[] = ["start_time" => self::time($start)] + $speakerLabel + ["end_time" => self::time($end)]
                + ["alternatives" => [["confidence" => self::confidence(TranscriptWords::NEW_WORD_CONFIDENCE), "content" => $parts[1]]], "type" => "pronunciation"];
        }
        if ($parts[2] !== "") {
            $items[] = $speakerLabel + ["alternatives" => [["confidence" => "0.0", "content" => $parts[2]]], "type" => "punctuation"];
        }

        return $items;
    }


    /**
     * Returns the speaker turns of the items: the runs of pronunciation items with the same speaker.
     *
     * @return list<array>
     */
    private static function speakerSegments(array $items): array
    {
        $segments = [];
        foreach ($items as $item) {
            if (!isset($item["start_time"], $item["end_time"], $item["speaker_label"])) {
                continue;
            }

            $entry = ["start_time" => $item["start_time"], "speaker_label" => $item["speaker_label"], "end_time" => $item["end_time"]];
            $last  = count($segments) - 1;
            if ($last >= 0 && $segments[$last]["speaker_label"] === $item["speaker_label"]) {
                $segments[$last]["end_time"] = $item["end_time"];
                $segments[$last]["items"][]  = $entry;
                continue;
            }
            $segments[] = $entry + ["items" => [$entry]];
        }

        return $segments;
    }


    private static function speakerLabels(array $results, array $segments): array
    {
        $labels = is_array($results["speaker_labels"] ?? null) ? $results["speaker_labels"] : [];

        return array_replace($labels, ["speakers" => count(array_unique(array_column($segments, "speaker_label"))), "segments" => $segments]);
    }


    private static function seconds(mixed $value): ?float
    {
        return is_string($value) && is_numeric($value) ? (float) $value : (is_int($value) || is_float($value) ? (float) $value : null);
    }


    /**
     * Formats seconds as Amazon Transcribe does, for example "2.0" and "0.52".
     */
    private static function time(float $seconds): string
    {
        $text = rtrim(sprintf("%.3f", $seconds), "0");

        return str_ends_with($text, ".") ? $text . "0" : $text;
    }


    private static function confidence(float $value): string
    {
        return self::time($value);
    }
}
