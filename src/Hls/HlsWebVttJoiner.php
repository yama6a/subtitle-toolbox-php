<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;

final class HlsWebVttJoiner
{
    /**
     * Joins WebVTT segments in playlist order into one subtitle, with cue times relative to $streamStartPts.
     * A null $streamStartPts uses the MPEGTS value of the first segment.
     *
     * @param iterable<string> $segments
     *
     * @see https://datatracker.ietf.org/doc/html/rfc8216#section-3.5
     */
    public static function join(iterable $segments, ?int $streamStartPts = null): Subtitle
    {
        if ($streamStartPts !== null && ($streamStartPts < 0 || $streamStartPts >= TimestampMap::MPEGTS_WRAP)) {
            throw new InvalidArgumentException("The stream start must be a 33-bit timestamp from 0 to " .
                                                (TimestampMap::MPEGTS_WRAP - 1) . ", got $streamStartPts.");
        }

        $joined   = new Subtitle();
        $fileData = null;
        $seen     = [];
        $cues     = [];
        foreach ($segments as $content) {
            $segment = Subtitle::fromString($content, Format::WebVtt);
            // RFC 8216 section 3.5: without the header, cue time 0 maps to MPEG-2 timestamp 0.
            $map              = TimestampMap::fromSubtitle($segment) ?? new TimestampMap(0);
            $streamStartPts ??= $map->mpegts;
            $fileData       ??= self::withoutTimestampMap($segment);

            foreach ($segment->shift($map->offset($streamStartPts))->getCues() as $cue) {
                $key = $cue->getStart() . "|" . $cue->getEnd() . "|" . $cue->getText();
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $cues[] = $cue;
                }
            }
        }

        return $joined->setFormatData(WebVttParser::FORMAT_DATA_KEY, $fileData ?? [])->addCues($cues)->removeDuplicateCues();
    }


    private static function withoutTimestampMap(Subtitle $segment): array
    {
        $fileData                = $segment->findFormatData(WebVttParser::FORMAT_DATA_KEY);
        $fileData["headerLines"] = TimestampMap::withoutHeader($fileData["headerLines"] ?? []);
        if ($fileData["headerLines"] === []) {
            unset($fileData["headerLines"]);
        }

        return $fileData;
    }
}
