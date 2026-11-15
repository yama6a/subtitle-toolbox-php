<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Mp4\Mp4Reader;
use SubtitleToolbox\Container\MpegTs\MpegTsReader;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Exceptions\UnsupportedFormatException;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * Knows a video container by its first bytes and opens the reader for it. Holds the time check that the MP4 and MPEG-TS
 * readers share.
 *
 * @internal
 */
final class Containers
{
    /** The number of bytes that detect() needs. */
    public const HEAD_LENGTH = 2 * MpegTsReader::M2TS_PACKET_SIZE + 5;


    public static function detect(string $head): ?ContainerFormat
    {
        return match (true) {
            str_starts_with($head, MatroskaReader::EBML_MAGIC) => ContainerFormat::Matroska,
            substr($head, 4, 4) === Mp4Reader::FTYP           => ContainerFormat::Mp4,
            MpegTsReader::isTransportStream($head)             => ContainerFormat::MpegTs,
            default                                            => null,
        };
    }


    /**
     * Opens the reader of the container that the first bytes show.
     *
     * @param string|resource $file a file path or a seekable stream at its start
     * @throws UnsupportedFormatException when the first bytes show no container. A damaged container throws ParsingException.
     */
    public static function open($file): ContainerReader
    {
        if (is_resource($file)) {
            $head = (string) fread($file, self::HEAD_LENGTH);
            rewind($file);
        } else {
            $head = is_file($file) ? (string) @file_get_contents($file, false, null, 0, self::HEAD_LENGTH) : "";
        }

        return match (self::detect($head)) {
            ContainerFormat::Matroska => MatroskaReader::open($file),
            ContainerFormat::Mp4      => Mp4Reader::open($file),
            ContainerFormat::MpegTs   => MpegTsReader::open($file),
            null                      => throw new UnsupportedFormatException("The file is not an MKV, WebM, MP4 or MPEG-TS file."),
        };
    }


    /**
     * Returns the name of the container in messages, for example "MKV or WebM".
     */
    public static function label(ContainerFormat $container): string
    {
        return match ($container) {
            ContainerFormat::Matroska => "MKV or WebM",
            ContainerFormat::Mp4      => "MP4",
            ContainerFormat::MpegTs   => "MPEG-TS",
        };
    }


    /**
     * Throws when one of $cues starts or ends at MAX_HOURS or later, rounded to milliseconds as the cue holds it.
     * A packet or a sample that makes no cue does not count. So a track whose cues end below the limit reads, also when
     * its last packets pass the limit.
     *
     * @param list<SubtitleCue> $cues
     * @throws ParsingException
     */
    public static function checkCueTimes(int $trackNumber, array $cues): void
    {
        foreach ($cues as $cue) {
            $time = max($cue->getStart(), $cue->getEnd());
            if (!Timecode::isBelowLimit($time)) {
                $milliseconds = Timecode::totalMilliseconds($time);
                $seconds      = rtrim(rtrim(sprintf("%d.%03d", intdiv($milliseconds, 1000), $milliseconds % 1000), "0"), ".");
                throw new ParsingException("The cue times of track $trackNumber reach $seconds s. Times must be below " . Timecode::MAX_HOURS . " hours.");
            }
        }
    }
}
