<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\PodcastTranscriptWriteOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\WordTimes;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class PodcastTranscriptFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = PodcastTranscriptWriteOptions::class;

    private const VERSION = "1.0.0";

    private const VOICE     = '/^(?:' . Markup::VOICE_TAG . '|' . Markup::VOICE_TAG_START . '>)$/';
    private const VOICE_END = '/^<\/v\s*>$/';


    /**
     * Writes the Podcasting 2.0 JSON transcript, one segment per cue and speaker.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $formatOptions = $this->formatOptions($options);
        $wordSegments  = $formatOptions->wordSegments;
        $fileData      = $subtitle->findFormatData(PodcastTranscriptParser::FORMAT_DATA_KEY);
        $cues          = $subtitle->getCues();
        $pieces        = $this->pieces($subtitle, $wordSegments);
        $piecesPerCue  = array_count_values(array_column($pieces, "cue"));

        $segments = [];
        foreach ($pieces as $piece) {
            $segment = $this->segment($piece);
            if (!$wordSegments && $piecesPerCue[$piece["cue"]] === 1) {
                $segment += $cues[$piece["cue"]]->findFormatData(PodcastTranscriptParser::FORMAT_DATA_KEY);
            }
            $segments[] = $segment;
        }

        $document = ["version" => $fileData["version"] ?? self::VERSION, "segments" => $segments] + $fileData;

        return $this->applyOutputOptions(JsonOutput::document($document, $formatOptions->prettyPrint), $options);
    }


    /**
     * Returns the segments that format() writes, each with "startTime", "endTime", "body" and, when known, "speaker".
     *
     * @internal
     *
     * @return list<array{speaker?: string, startTime: float, endTime: float, body: string}>
     */
    public function segments(Subtitle $subtitle): array
    {
        return array_map($this->segment(...), $this->pieces($subtitle, false));
    }


    private function segment(array $piece): array
    {
        return ($piece["speaker"] === null ? [] : ["speaker" => $piece["speaker"]]) +
               ["startTime" => max(0.0, $piece["start"]), "endTime" => max(0.0, $piece["start"], $piece["end"]), "body" => $piece["body"]];
    }


    /**
     * Splits each cue at a speaker change, and at each word timestamp when $wordSegments is true.
     * A word ends at the next word start, or at its end in WordTimes when that is earlier.
     *
     * @return list<array{cue: int, speaker: ?string, start: float, end: float, wordEnd: ?float, body: string}>
     */
    private function pieces(Subtitle $subtitle, bool $wordSegments): array
    {
        $pieces = [];
        foreach ($subtitle->getCues() as $index => $cue) {
            $cuePieces = [];
            $speaker   = null;
            $start     = $cue->getStart();
            $wordEnd   = null;
            $text      = "";
            $wordEnds  = $wordSegments ? WordTimes::ends($cue) : [];
            $wordIndex = 0;
            $tokens    = Markup::splitTags(Markup::rubyAsText(implode(" ", $cue->getLines())));
            foreach ($tokens as $position => $token) {
                if ($position % 2 === 0) {
                    $text .= $token;
                    continue;
                }

                $isVoice     = preg_match(self::VOICE, $token, $voice) === 1;
                $wordTime    = $wordSegments ? Markup::wordTimestampSeconds($token) : null;
                $isTimestamp = $wordTime !== null;
                if (!$isVoice && !$isTimestamp && preg_match(self::VOICE_END, $token) !== 1) {
                    continue;
                }

                $name = $isVoice ? trim(Markup::decodeEntities($voice[2] ?? "")) : null;
                $name = $isTimestamp ? $speaker : ($name === "" ? null : $name);
                if (!$isTimestamp && $name === $speaker) {
                    continue;
                }

                $cuePieces = $this->addPiece($cuePieces, $index, $speaker, $start, $wordEnd, $text);
                $speaker   = $name;
                $start     = $isTimestamp ? $this->wordStartInCue($wordTime, $cue) : $start;
                $wordEnd   = $isTimestamp ? $wordEnds[$wordIndex++] ?? null : null;
                $text      = "";
            }
            $cuePieces = $this->addPiece($cuePieces, $index, $speaker, $start, $wordEnd, $text);

            foreach ($cuePieces as $offset => $piece) {
                $end                       = $wordSegments ? $cuePieces[$offset + 1]["start"] ?? $cue->getEnd() : $cue->getEnd();
                $cuePieces[$offset]["end"] = $piece["wordEnd"] === null ? $end : min($end, $piece["wordEnd"]);
            }
            array_push($pieces, ...$cuePieces);
        }

        return $pieces;
    }


    private function addPiece(array $pieces, int $cue, ?string $speaker, float $start, ?float $wordEnd, string $text): array
    {
        $body = trim(preg_replace('/[ \t\n\r]+/', " ", Markup::decodeEntities($text)) ?? "");
        if ($body !== "") {
            $pieces[] = ["cue" => $cue, "speaker" => $speaker, "start" => $start, "end" => $start, "wordEnd" => $wordEnd, "body" => $body];
        }

        return $pieces;
    }


    private function wordStartInCue(float $time, SubtitleCue $cue): float
    {
        return Timecode::roundToMilliseconds(min(max($time, $cue->getStart()), $cue->getEnd()));
    }
}
