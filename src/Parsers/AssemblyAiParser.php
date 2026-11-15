<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;

final class AssemblyAiParser extends SubtitleParser
{
    use WordGrouping;

    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::AssemblyAi->value;

    private const MILLISECONDS = 0.001;


    protected static function formatDataKey(): string
    {
        return self::FORMAT_DATA_KEY;
    }


    /**
     * Reads the JSON of an AssemblyAI transcript, one cue per utterance, else cues grouped from the words.
     */
    protected function read(string $content): Subtitle
    {
        $data       = $this->decodeJsonObject($content);
        $words      = $data["words"] ?? null;
        $utterances = $this->listOrEmpty($data["utterances"] ?? null, "utterances");
        if ($utterances === [] && !$this->isList($words, "words")) {
            throw new ParsingException("The JSON has no \"words\" or \"utterances\" list.");
        }

        $cues = $utterances === []
            ? $this->cuesFromWords($this->readWords($words, "words"), "words")
            : $this->readUtterances($utterances, ["utterances"], "text", $this->readWords(...), self::MILLISECONDS);

        $language = $data["language_code"] ?? null;
        if (is_string($language) && $language !== "") {
            // AssemblyAI writes a region as "en_us". The language metadata holds BCP 47 tags such as "en-US".
            $parts    = explode("_", $language, 2);
            $language = $parts[0] . (isset($parts[1]) ? "-" . strtoupper($parts[1]) : "");
        }

        return $this->transcript($cues, $language, array_diff_key($data, array_flip(["text", "words", "utterances"])));
    }


    private function readWords(array $words, string $path): array
    {
        return $this->readWordList($words, $path, fn (mixed $word, string $wordPath): array => [
            $this->text($word, "text", $wordPath),
            $this->seconds($word["start"] ?? null, "$wordPath.start", self::MILLISECONDS),
            $this->seconds($word["end"] ?? null, "$wordPath.end", self::MILLISECONDS),
            self::speaker($word["speaker"] ?? null),
        ]);
    }
}
