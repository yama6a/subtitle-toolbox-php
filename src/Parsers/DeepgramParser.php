<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class DeepgramParser extends SubtitleParser
{
    use WordGrouping;

    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Deepgram->value;


    protected static function formatDataKey(): string
    {
        return self::FORMAT_DATA_KEY;
    }


    /**
     * Reads the JSON response of the Deepgram pre-recorded audio API.
     * It makes one cue per utterance, else one per paragraph sentence, else cues grouped from the words.
     */
    protected function read(string $content): Subtitle
    {
        $data    = $this->decodeJsonObject($content);
        $results = $data["results"] ?? null;
        if (!is_array($results) || !self::isList($results["channels"] ?? null)) {
            throw new ParsingException("The JSON has no \"results.channels\" list.");
        }

        $utterances = self::listOrEmpty($results["utterances"] ?? null);
        $cues       = $utterances === []
            ? $this->readChannels($results["channels"])
            : $this->readUtterances($utterances, "results.utterances", "transcript", $this->readWords(...));

        return $this->transcript($cues, $results["channels"][0]["detected_language"] ?? null,
                                 self::fileDataWithResults($data, $results, ["channels", "utterances"]));
    }


    private function readChannels(array $channels): array
    {
        $cues = [];
        foreach ($channels as $channelIndex => $channel) {
            $alternative = is_array($channel) ? $channel["alternatives"][0] ?? null : null;
            if (!is_array($alternative)) {
                continue;
            }

            $path       = "results.channels[$channelIndex].alternatives[0]";
            $words      = $this->readWords(self::listOrEmpty($alternative["words"] ?? null), "$path.words");
            $paragraphs = self::listOrEmpty($alternative["paragraphs"]["paragraphs"] ?? null);
            if ($paragraphs === []) {
                array_push($cues, ...$this->cuesFromWords($words, "words", ["channel" => $channelIndex]));
                continue;
            }

            array_push($cues, ...$this->paragraphCues($paragraphs, $words, $path, $channelIndex));
        }

        return $cues;
    }


    /**
     * Returns a cue for each sentence of the paragraphs, with the words between its start and its end.
     *
     * @return list<SubtitleCue>
     */
    private function paragraphCues(array $paragraphs, array $words, string $path, int $channelIndex): array
    {
        $cues      = [];
        $wordIndex = 0;
        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            $speaker = self::speaker(is_array($paragraph) ? $paragraph["speaker"] ?? null : null);
            foreach (self::listOrEmpty($paragraph["sentences"] ?? null) as $sentenceIndex => $sentence) {
                $sentencePath = "$path.paragraphs.paragraphs[$paragraphIndex].sentences[$sentenceIndex]";
                try {
                    $start = $this->seconds(is_array($sentence) ? $sentence["start"] ?? null : null, "$sentencePath.start");
                    $end   = $this->seconds($sentence["end"] ?? null, "$sentencePath.end");
                    $text  = $this->text($sentence, "text", $sentencePath);
                } catch (ParsingException $exception) {
                    $this->fail($exception, null, $sentenceIndex, [RawJson::encode($sentence)]);
                    continue;
                }

                $sentenceWords = self::wordsBetween($words, $wordIndex, $start, $end);
                $formatData    = ["channel" => $channelIndex] + ($speaker === null ? [] : ["speaker" => $paragraph["speaker"]]);
                $cue           = $this->cue($start, $end, $text, $sentenceWords, $speaker,
                                            $formatData + ["words" => array_merge([], ...array_column($sentenceWords, "data"))],
                                            $sentenceIndex, $sentence);
                if ($cue !== null) {
                    $cues[] = $cue;
                }
            }
        }

        return $cues;
    }


    private function readWords(array $words, string $path): array
    {
        return $this->readWordList($words, $path, fn (mixed $word, string $wordPath): array => [
            $this->text($word, isset($word["punctuated_word"]) ? "punctuated_word" : "word", $wordPath),
            $this->seconds($word["start"] ?? null, "$wordPath.start"),
            $this->seconds($word["end"] ?? null, "$wordPath.end"),
            self::speaker($word["speaker"] ?? null),
        ]);
    }
}
