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

    // Each sentence cue keeps a copy of its paragraph, so a damaged file with many sentences and large paragraphs
    // needs memory that grows with the square of its size. The copies may hold this many times the file size, plus the allowance.
    private const MAX_PARAGRAPH_COPY_FACTOR = 16;
    private const PARAGRAPH_COPY_ALLOWANCE  = 1 << 20;


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
            ? $this->readChannels($results["channels"], self::MAX_PARAGRAPH_COPY_FACTOR * strlen($content) + self::PARAGRAPH_COPY_ALLOWANCE)
            : $this->readUtterances($utterances, "results.utterances", "transcript", $this->readWords(...));

        $results["channels"] = array_map(self::channelData(...), $results["channels"]);

        return $this->transcript($cues, $results["channels"][0]["detected_language"] ?? null,
                                 self::fileDataWithResults($data, $results, ["utterances"]));
    }


    /**
     * Returns the fields of a channel and of its first alternative, without the transcript, the words and the paragraphs.
     */
    private static function channelData(mixed $channel): mixed
    {
        if (!is_array($channel) || !is_array($channel["alternatives"][0] ?? null)) {
            return $channel;
        }

        return ["alternatives" => [array_diff_key($channel["alternatives"][0], array_flip(["transcript", "words", "paragraphs"]))]] + $channel;
    }


    /**
     * @param int $paragraphBytes the bytes of paragraph data that the cues of all sentences may hold together
     */
    private function readChannels(array $channels, int $paragraphBytes): array
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

            array_push($cues, ...$this->paragraphCues($paragraphs, $words, $path, $channelIndex, $paragraphBytes));
        }

        return $cues;
    }


    /**
     * Returns a cue for each sentence of the paragraphs, with the words between its start and its end.
     * Each cue keeps its paragraph without the sentences. It moves $paragraphBytes down by the JSON size of these copies.
     *
     * @return list<SubtitleCue>
     * @throws ParsingException when the copies need more than $paragraphBytes.
     */
    private function paragraphCues(array $paragraphs, array $words, string $path, int $channelIndex, int &$paragraphBytes): array
    {
        $cues      = [];
        $wordIndex = 0;
        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            $sentences = self::listOrEmpty($paragraph["sentences"] ?? null);
            if ($sentences === []) {
                continue;
            }
            $speaker        = self::speaker($paragraph["speaker"] ?? null);
            $paragraphData  = array_diff_key($paragraph, ["sentences" => true]);
            $paragraphBytes -= count($sentences) * strlen(RawJson::encode($paragraphData));
            if ($paragraphBytes < 0) {
                throw new ParsingException("The sentences of $path.paragraphs repeat more paragraph data than " .
                                           self::MAX_PARAGRAPH_COPY_FACTOR . " times the size of the file.");
            }
            foreach ($sentences as $sentenceIndex => $sentence) {
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
                $formatData    = $sentence + ["channel" => $channelIndex] + ($speaker === null ? [] : ["speaker" => $paragraph["speaker"]])
                    + ["paragraph" => $paragraphData];
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
