<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Parsers\YouTubeTimedTextParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Writes the start and the duration of each YouTube caption. An unedited time keeps its source value, and a duration
 * that the source lacked stays absent, when the parser reads the same cue end from it again.
 *
 * @internal
 */
final class YouTubeCaptionTimes
{
    /**
     * @param string  $startKey    the start field, such as "t" or "tStartMs"
     * @param string  $durationKey the duration field, such as "d" or "dDurationMs"
     * @param ?string $windowKey   the window field, such as "w", or null for a format without windows
     * @param int     $unit        the field units per second, 1 or 1000
     * @param bool    $asString    true for the XML formats, which write the times as attribute strings
     */
    public function __construct(
        private readonly string $startKey,
        private readonly string $durationKey,
        private readonly ?string $windowKey,
        private readonly int $unit,
        private readonly bool $asString,
    ) {
    }


    /**
     * Returns the fields of each cue with the start and the duration set, and the append events before it with
     * their starts moved as far as the cue moved.
     *
     * @param list<SubtitleCue> $cues
     * @param list<array>       $cueData the format data of each cue
     *
     * @return list<array{fields: array, appends: list<array>}>
     */
    public function captions(array $cues, array $cueData): array
    {
        $timed = [];
        foreach ($cues as $index => $cue) {
            $data     = $cueData[$index];
            $start    = max(0.0, $cue->getStart());
            $source   = $this->seconds($data[$this->startKey] ?? null);
            $duration = $source === null ? null : $this->seconds($data[$this->durationKey] ?? null);
            $window   = $this->windowKey === null ? null : $data[$this->windowKey] ?? null;
            $timed[]  = [$start, $source === null ? max($start, $cue->getEnd()) : ($duration === null ? null : $start + $duration), $window];
        }
        $ends = YouTubeTimedTextParser::captionEnds($timed, (new ReadOptions())->lastCueDuration);

        $captions = [];
        foreach ($cues as $index => $cue) {
            $data   = $cueData[$index];
            $start  = $timed[$index][0];
            $end    = max($start, $cue->getEnd());
            $source = $this->seconds($data[$this->startKey] ?? null);
            $fields = array_replace($data + [$this->startKey => null, $this->durationKey => null], [
                $this->startKey => TranscriptWords::sameTime($source, $start) ? $data[$this->startKey] : $this->value($start),
            ]);
            if ($source === null || !TranscriptWords::sameTime($ends[$index], $end)) {
                $fields[$this->durationKey] = $this->value($end - $start);
            } elseif (!isset($data[$this->durationKey])) {
                unset($fields[$this->durationKey]);
            }

            $shift      = $source === null ? 0.0 : $start - $source;
            $appends    = is_array($data["appends"] ?? null) ? array_values(array_filter($data["appends"], is_array(...))) : [];
            $captions[] = ["fields" => $fields, "appends" => array_map(fn (array $append): array => $this->shift($append, $shift), $appends)];
        }

        return $captions;
    }


    private function shift(array $append, float $shift): array
    {
        $start = $this->seconds($append[$this->startKey] ?? null);
        if ($start === null || Timecode::roundToMilliseconds($shift) === 0.0) {
            return $append;
        }

        return array_replace($append, [$this->startKey => $this->value(max(0.0, $start + $shift))]);
    }


    private function seconds(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value / $this->unit : null;
    }


    /**
     * Formats seconds in the unit of the format: "4.4" for srv1, "4400" for srv2 and srv3, 4400 for json3.
     */
    private function value(float $seconds): int|string
    {
        $milliseconds = (int) round($seconds * 1000);
        if (!$this->asString) {
            return $milliseconds;
        }

        return $this->unit === 1000 ? (string) $milliseconds : rtrim(rtrim(sprintf("%d.%03d", intdiv($milliseconds, 1000), $milliseconds % 1000), "0"), ".");
    }
}
