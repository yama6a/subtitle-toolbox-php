<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class PodcastTranscriptParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::PodcastTranscript->value;

    private const SEGMENT_FIELDS = ["speaker", "startTime", "endTime", "body"];

    private const SENTENCE_END = '/[.?!\x{2026}]["\'\x{201D}\x{2019})\]]*$/u';

    // The same gap as WordGrouping::MAX_WORD_GAP of the cloud speech-to-text parsers.
    private const MAX_WORD_GAP = 1.0;


    /**
     * Reads the Podcasting 2.0 JSON transcript, and joins single-word segments into cues by speaker, sentence end and pause.
     */
    protected function read(string $content): Subtitle
    {
        $data = $this->decodeJsonObject($content);
        if (!is_array($data["segments"] ?? null) || !array_is_list($data["segments"]) || $this->isJsonObject("segments")) {
            throw new ParsingException("The JSON has no \"segments\" list.");
        }

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($data, ["segments" => true]));

        foreach ($this->groups($this->readSegments($data["segments"])) as $group) {
            $speaker    = $group[0]["speaker"];
            $timestamps = count($group) > 1 && $this->formatOptions()->wordTimestamps;
            $words      = [];
            foreach ($group as $segment) {
                $words[] = ($timestamps ? "<" . Markup::coreTimestamp($segment["start"]) . ">" : "") . Markup::escapeText($segment["body"]);
            }
            $markup = implode(" ", $words);
            if ($speaker !== "") {
                $markup = Markup::voiceTag($speaker) . $markup;
            }

            $cue = new SubtitleCue($group[0]["start"], $group[count($group) - 1]["end"], $markup);
            if (count($group) === 1 && $group[0]["other"] !== []) {
                $cue->setFormatData(self::FORMAT_DATA_KEY, $group[0]["other"]);
            }
            if ($timestamps) {
                WordTimes::store($cue, array_map(fn (array $segment): array => [$segment["start"], $segment["end"]], $group));
            }
            $parsedCues[] = $cue;
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * @return list<array{start: float, end: float, speaker: string, body: string, other: array}>
     */
    private function readSegments(array $segments): array
    {
        $result = [];
        foreach ($segments as $index => $segment) {
            try {
                $result[] = $this->readSegment($segment, "segments[$index]", $index);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
            }
        }

        $starts = array_column($result, "start");
        foreach ($result as $index => $segment) {
            $result[$index]["end"] ??= $this->endAtNextStart($starts, $index);
        }

        return array_values(array_filter($result, fn (array $segment): bool => $segment["body"] !== ""));
    }


    /**
     * @return array{start: float, end: ?float, speaker: string, body: string, other: array}
     */
    private function readSegment(mixed $segment, string $path, int $index): array
    {
        if (!is_array($segment) || ($segment !== [] && array_is_list($segment))) {
            throw new ParsingException("The field $path must be an object.");
        }

        foreach (["startTime" => "a number", "endTime" => "a number", "speaker" => "a string", "body" => "a string"] as $key => $type) {
            $value = $segment[$key] ?? null;
            $valid = $type === "a string" ? is_string($value) : self::isTime($value);
            if (!$valid && ($key === "startTime" || $value !== null)) {
                throw new ParsingException("The field $path.$key must be $type.");
            }
        }

        $start = self::boundedField(Timecode::roundToMilliseconds($segment["startTime"]), "$path.startTime");
        $end   = $segment["endTime"] ?? null;
        $end   = $end === null ? null : self::boundedField(Timecode::roundToMilliseconds($end), "$path.endTime");
        if ($end !== null) {
            [$start, $end] = $this->orderedTimes($start, $end, null, $index, [RawJson::encode($segment)]);
        }

        return [
            "start"   => $start,
            "end"     => $end,
            "speaker" => trim($segment["speaker"] ?? ""),
            "body"    => trim(preg_replace('/[ \t\n\r]+/', " ", $segment["body"] ?? "") ?? ""),
            "other"   => array_diff_key($segment, array_flip(self::SEGMENT_FIELDS)),
        ];
    }


    /**
     * Joins runs of single-word segments of one speaker into a group that ends after a word with a sentence end,
     * or before a pause of MAX_WORD_GAP or more.
     *
     * @return list<list<array>>
     */
    private function groups(array $segments): array
    {
        $groups = [];
        $open   = false;
        foreach ($segments as $segment) {
            $isWord = !$this->formatOptions()->keepSegments && !str_contains($segment["body"], " ");
            $last   = $open ? $groups[count($groups) - 1] : null;
            if ($isWord && $last !== null && $last[0]["speaker"] === $segment["speaker"]
                && Timecode::roundToMilliseconds($segment["start"] - $last[count($last) - 1]["end"]) < self::MAX_WORD_GAP) {
                $groups[count($groups) - 1][] = $segment;
            } else {
                $groups[] = [$segment];
            }
            $open = $isWord && preg_match(self::SENTENCE_END, $segment["body"]) !== 1;
        }

        return $groups;
    }
}
