<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Timecode;

final class HlsSegmentOptions
{
    public const DEFAULT_MPEGTS = 900000;

    public readonly TimestampMap $timestampMap;


    /**
     * @param float  $segmentDuration seconds per segment, Apple recommends 6
     * @param int    $mpegts          90 kHz MPEG-2 timestamp at which cue time $local plays
     * @param float  $local           WebVTT cue time in seconds that maps to $mpegts
     * @param string $fileNamePattern sprintf() pattern with one %d for the 0-based segment number
     * @param ?float $mediaDuration   seconds the playlist covers, null for the end of the last cue
     */
    public function __construct(
        public readonly float $segmentDuration = 6,
        public readonly int $mpegts = self::DEFAULT_MPEGTS,
        public readonly float $local = 0,
        public readonly string $fileNamePattern = "sub%d.vtt",
        public readonly ?float $mediaDuration = null,
    ) {
        if (!is_finite($segmentDuration) || Timecode::roundToMilliseconds($segmentDuration) <= 0) {
            throw new InvalidArgumentException("The segment duration must be a finite number of at least 0.001 s, got " . OptionChecks::text($segmentDuration) . ".");
        }

        $placeholders = str_replace("%%", "", $fileNamePattern);
        if (substr_count($placeholders, "%") !== 1 || preg_match("/%\d*d/", $placeholders) !== 1) {
            throw new InvalidArgumentException("The file name pattern must hold exactly one %d and no other " .
                                               "placeholder, got \"$fileNamePattern\".");
        }

        if ($mediaDuration !== null && (!is_finite($mediaDuration) || Timecode::roundToMilliseconds($mediaDuration) <= 0)) {
            throw new InvalidArgumentException("The media duration must be a finite number of at least 0.001 s, got " . OptionChecks::text($mediaDuration) . ".");
        }

        $this->timestampMap = new TimestampMap($mpegts, $local);
    }


    /**
     * @internal
     */
    public function segmentMilliseconds(): int
    {
        return Timecode::totalMilliseconds($this->segmentDuration);
    }


    /**
     * Yields the start and the end in milliseconds of each segment, keyed by its file name, in playlist order.
     *
     * @internal
     *
     * @return Generator<string, array{int, int}>
     */
    public function segmentBounds(int $totalMilliseconds): Generator
    {
        $segmentMilliseconds = $this->segmentMilliseconds();
        for ($start = 0, $index = 0; $start < $totalMilliseconds; $start += $segmentMilliseconds, $index++) {
            yield $this->fileName($index) => [$start, min($start + $segmentMilliseconds, $totalMilliseconds)];
        }
    }


    /**
     * Returns the file name of the segment with the 0-based number $index.
     *
     * @internal
     */
    public function fileName(int $index): string
    {
        return sprintf($this->fileNamePattern, $index);
    }
}
