<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class HlsWebVttSegmenter
{
    /**
     * Cuts the subtitle into WebVTT segments with an X-TIMESTAMP-MAP header, and lists them in a VOD playlist.
     * The result writes each segment when the caller reads it, so the memory does not grow with the media duration.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc8216#section-3.5
     */
    public static function segment(Subtitle $subtitle, ?HlsSegmentOptions $options = null): HlsWebVttRendition
    {
        $options ??= new HlsSegmentOptions();
        $cues              = array_values($subtitle->getCues());
        $totalMilliseconds = $options->mediaDuration === null
            ? max([0, ...array_map(fn (SubtitleCue $cue): int => Timecode::totalMilliseconds($cue->getEnd()), $cues)])
            : Timecode::totalMilliseconds($options->mediaDuration);
        if ($totalMilliseconds === 0) {
            throw new InvalidArgumentException("The subtitle has no cue that ends after 0 s. " .
                                               "Set the mediaDuration option to segment it.");
        }

        $fileData                = $subtitle->findFormatData(WebVttParser::FORMAT_DATA_KEY);
        $fileData["headerLines"] = [$options->timestampMap->toHeader(), ...TimestampMap::withoutHeader($fileData["headerLines"] ?? [])];

        $copy = (new Subtitle())->addCues(array_map(
            fn (SubtitleCue $cue, int $cueIndex): SubtitleCue => (clone $cue)->setIdentifier($cue->getIdentifier() ?? (string) ($cueIndex + 1)),
            $cues,
            array_keys($cues),
        ));
        $sorted  = array_values($copy->getCues());
        $starts  = array_map(fn (SubtitleCue $cue): int => Timecode::totalMilliseconds($cue->getStart()), $sorted);
        $ends    = array_map(fn (SubtitleCue $cue): int => Timecode::totalMilliseconds($cue->getEnd()), $sorted);
        $shifted = array_values($copy->shift($options->local)->getCues());

        $segments = function () use ($fileData, $starts, $ends, $shifted, $options, $totalMilliseconds): Generator {
            $empty  = null;
            $next   = 0;
            $active = [];
            foreach ($options->segmentBounds($totalMilliseconds) as $fileName => [$startMilliseconds, $endMilliseconds]) {
                self::moveWindow($active, $next, $starts, $ends, $startMilliseconds, $endMilliseconds);

                yield $fileName => $active === []
                    ? $empty ??= self::write($fileData, [])
                    : self::write($fileData, array_map(fn (int $cueIndex): SubtitleCue => $shifted[$cueIndex], array_keys($active)));
            }
        };

        return new HlsWebVttRendition($segments, $options, $totalMilliseconds);
    }


    /**
     * Updates the active cues to those that overlap the segment. $next is the first cue that has not started yet.
     *
     * @param array<int, true> $active
     * @param list<int>        $starts
     * @param list<int>        $ends
     */
    private static function moveWindow(array &$active, int &$next, array $starts, array $ends, int $startMilliseconds, int $endMilliseconds): void
    {
        for (; $next < count($starts) && $starts[$next] < $endMilliseconds; $next++) {
            $active[$next] = true;
        }
        foreach (array_keys($active) as $cueIndex) {
            $isEmpty = $ends[$cueIndex] === $starts[$cueIndex];
            // RFC 8216 section 3.5: a cue keeps its full time range in every segment it overlaps.
            if ($ends[$cueIndex] <= $startMilliseconds && !($isEmpty && $starts[$cueIndex] >= $startMilliseconds)) {
                unset($active[$cueIndex]);
            }
        }
        ksort($active);
    }


    /**
     * @param array<int, SubtitleCue> $cues
     */
    private static function write(array $fileData, array $cues): string
    {
        $segment = (new Subtitle())->setFormatData(WebVttParser::FORMAT_DATA_KEY, $fileData)->addCues($cues);

        return $segment->toString(Format::WebVtt, new WriteOptions(bom: false));
    }
}
