<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Subtitle;

final class JsonParser extends SubtitleParser
{
    /**
     * Reads the JSON that JsonFormatter writes.
     * A bad field throws ParsingException with the path of the field, for example cues[3].start.
     */
    protected function read(string $content): Subtitle
    {
        $data = $this->decodeJsonObject($content);

        $this->decodeFileFormatData($data);
        $skipped = $this->checkCueTimes($data, $this->decodeCueFormatData($data));

        $reject   = function (ParsingException $exception, string $field, int|string $key) use ($data): void {
            $entry = $data[$field][$key];
            $block = $field === "cues" || $field === "comments" ? $entry : [$key => $entry];
            $this->fail($exception, null, $field === "cues" ? $key : null, [RawJson::encode($block)]);
        };
        $subtitle = Subtitle::fromArrayLeavingOut($data, $skipped, $reject);
        usort($this->warnings, fn (ParseWarning $warning1, ParseWarning $warning2): int => $warning1->blockIndex <=> $warning2->blockIndex);

        return $subtitle;
    }


    /**
     * Decodes the binary values of the file format data. An entry that fails drops out.
     */
    private function decodeFileFormatData(array &$data): void
    {
        foreach (is_array($data["formatData"] ?? null) ? $data["formatData"] : [] as $format => $formatData) {
            if (!is_array($formatData)) {
                continue;
            }
            try {
                $data["formatData"][$format] = $this->decodeBinary($formatData, "formatData.$format");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, null, [RawJson::encode([$format => $formatData])]);
                unset($data["formatData"][$format]);
            }
        }
    }


    /**
     * Decodes the binary values of the cue format data. Returns the indexes of the cues where that fails.
     *
     * @return array<int, true>
     */
    private function decodeCueFormatData(array &$data): array
    {
        $skipped = [];
        foreach (is_array($data["cues"] ?? null) ? $data["cues"] : [] as $index => $cue) {
            if (!is_array($cue["formatData"] ?? null)) {
                continue;
            }
            try {
                $data["cues"][$index]["formatData"] = $this->decodeBinary($cue["formatData"], "cues[$index].formatData");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($cue)]);
                $skipped[$index] = true;
            }
        }

        return $skipped;
    }


    /**
     * Returns $skipped with the indexes of the cues that have a time of MAX_HOURS or more, or that end before they start.
     * Lenient mode swaps the times of a short cue that ends before it starts.
     *
     * @param array<int, true> $skipped
     *
     * @return array<int, true>
     */
    private function checkCueTimes(array &$data, array $skipped): array
    {
        foreach (is_array($data["cues"] ?? null) ? $data["cues"] : [] as $index => $cue) {
            if (isset($skipped[$index]) || !is_array($cue)) {
                continue;
            }
            try {
                foreach (["start", "end"] as $key) {
                    $time = $cue[$key] ?? null;
                    if (is_int($time) || (is_float($time) && is_finite($time))) {
                        self::boundedField($time, "cues[$index].$key");
                    }
                }
                self::checkWordTimestamps(is_array($cue["lines"] ?? null) ? array_values(array_filter($cue["lines"], "is_string")) : [], null);
                if (self::isTime($cue["start"] ?? null) && self::isTime($cue["end"] ?? null) && $cue["end"] < $cue["start"]) {
                    [$data["cues"][$index]["start"], $data["cues"][$index]["end"]] = $this->orderedTimes($cue["start"], $cue["end"], null, $index, [RawJson::encode($cue)]);
                }
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($cue)]);
                $skipped[$index] = true;
            }
        }

        return $skipped;
    }


    private function decodeBinary(array $value, string $path): array|string
    {
        if (array_keys($value) === ["base64"] && is_string($value["base64"])) {
            $bytes = base64_decode($value["base64"], true);
            if ($bytes === false) {
                throw new ParsingException("The field $path.base64 must be valid base64.");
            }

            return $bytes;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->decodeBinary($item, is_int($key) ? "{$path}[$key]" : "$path.$key");
            }
        }

        return $value;
    }
}
