<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Mp4\Mp4Reader;
use SubtitleToolbox\Container\MpegTs\MpegTsReader;

/**
 * Knows a video container by its first bytes and opens the reader for it.
 *
 * @internal
 */
final class Containers
{
    /** The number of bytes that detect() needs. */
    public const HEAD_LENGTH = 2 * MpegTsReader::PACKET_SIZE + 1;


    public static function detect(string $head): ?ContainerFormat
    {
        return match (true) {
            str_starts_with($head, MatroskaReader::EBML_MAGIC) => ContainerFormat::Matroska,
            substr($head, 4, 4) === Mp4Reader::FTYP           => ContainerFormat::Mp4,
            MpegTsReader::isTransportStream($head)             => ContainerFormat::MpegTs,
            default                                            => null,
        };
    }


    public static function detectFile(string $path): ?ContainerFormat
    {
        $file = is_file($path) ? @fopen($path, "rb") : false;
        if ($file === false) {
            return null;
        }
        $head = (string) fread($file, self::HEAD_LENGTH);
        fclose($file);

        return self::detect($head);
    }


    /**
     * Opens the reader of the container that the first bytes show. Other content goes to MatroskaReader, which names the problem.
     *
     * @param string|resource $file a file path or a seekable stream at its start
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
            ContainerFormat::Mp4    => Mp4Reader::open($file),
            ContainerFormat::MpegTs => MpegTsReader::open($file),
            default                 => MatroskaReader::open($file),
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
}
