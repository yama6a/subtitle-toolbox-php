<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;

final class AwsTranscribeParser extends SubtitleParser
{
    use WordGrouping;

    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::AwsTranscribe->value;


    protected static function formatDataKey(): string
    {
        return self::FORMAT_DATA_KEY;
    }


    /**
     * Reads the JSON transcript of an Amazon Transcribe batch job.
     * It makes one cue per audio segment, else cues grouped from the words.
     */
    protected function read(string $content): Subtitle
    {
        $data    = $this->decodeJsonObject($content);
        $results = $data["results"] ?? null;
        if (!is_array($results) || !self::isList($results["items"] ?? null)) {
            throw new ParsingException("The JSON has no \"results.items\" list.");
        }

        $words    = $this->readItems($results["items"]);
        $segments = self::listOrEmpty($results["audio_segments"] ?? null);
        $cues     = $segments === [] ? $this->cuesFromWords($words, "items") : $this->readSegments($segments, $words);

        return $this->transcript($cues, $results["language_code"] ?? null, self::fileDataWithResults(
            $data,
            $results,
            ["transcripts", "items", "audio_segments", "speaker_labels", "channel_labels"]
        ));
    }


    /**
     * Returns the words with the punctuation items joined to the word before them, keyed by the ids of their items.
     */
    private function readItems(array $items): array
    {
        $words       = [];
        $punctuation = [];
        foreach ($items as $index => $item) {
            $path = "results.items[$index]";
            try {
                $content = $this->text(is_array($item) ? $item["alternatives"][0] ?? null : null, "content", "$path.alternatives[0]");
                if (($item["type"] ?? null) === "punctuation") {
                    self::addPunctuation($words, $punctuation, $item, $content);
                    continue;
                }
                $start = $this->seconds($item["start_time"] ?? null, "$path.start_time");
                $end   = $this->seconds($item["end_time"] ?? null, "$path.end_time");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($item)]);
                continue;
            }

            $words[] = [
                "text"    => implode("", array_map(fn (array $item): string => $item["alternatives"][0]["content"], $punctuation)) . $content,
                "start"   => $start,
                "end"     => $end,
                "speaker" => self::speaker($item["speaker_label"] ?? null),
                "data"    => [...$punctuation, $item],
            ];
            $punctuation = [];
        }

        return $words;
    }


    /**
     * Joins a punctuation item to the last word, or keeps it for the first word when no word came before.
     */
    private static function addPunctuation(array &$words, array &$punctuation, array $item, string $content): void
    {
        if ($words === []) {
            $punctuation[] = $item;

            return;
        }
        $words[array_key_last($words)]["text"]  .= $content;
        $words[array_key_last($words)]["data"][] = $item;
    }


    private function readSegments(array $segments, array $words): array
    {
        $wordsById = [];
        foreach ($words as $word) {
            foreach ($word["data"] as $item) {
                if (is_int($item["id"] ?? null) || is_string($item["id"] ?? null)) {
                    $wordsById[$item["id"]] = $word;
                }
            }
        }

        $cues = [];
        foreach ($segments as $index => $segment) {
            $path = "results.audio_segments[$index]";
            try {
                $start = $this->seconds(is_array($segment) ? $segment["start_time"] ?? null : null, "$path.start_time");
                $end   = $this->seconds($segment["end_time"] ?? null, "$path.end_time");
                $text  = $this->text($segment, "transcript", $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }

            $segmentWords = [];
            foreach (self::listOrEmpty($segment["items"] ?? null) as $id) {
                $word = is_int($id) || is_string($id) ? $wordsById[$id] ?? null : null;
                if ($word !== null && end($segmentWords) !== $word) {
                    $segmentWords[] = $word;
                }
            }

            $formatData          = array_diff_key($segment, array_flip(["start_time", "end_time", "transcript"]));
            $formatData["items"] = $segmentWords === [] ? [] : array_merge(...array_column($segmentWords, "data"));
            $cue                 = $this->cue($start, $end, $text, $segmentWords,
                                              self::speaker($segment["speaker_label"] ?? null), $formatData, $index, $segment);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }
}
