<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

use SubtitleToolbox\Exceptions\ParsingException;

/**
 * Reads EBML elements (RFC 8794) from a seekable stream without loading more than one element header at a time.
 *
 * @internal
 */
final class EbmlReader
{
    public const UNKNOWN_SIZE = -1;

    // An element ID has at most 4 bytes and a data size at most 8 bytes.
    private const MAX_HEADER_LENGTH = 12;

    /** @var resource */
    private $stream;

    private int $streamSize;


    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        $this->stream     = $stream;
        $this->streamSize = fstat($stream)["size"] ?? PHP_INT_MAX;
    }


    public function tell(): int
    {
        return (int) ftell($this->stream);
    }


    public function seek(int $offset): void
    {
        fseek($this->stream, $offset);
    }


    /**
     * Reads the element header at the current position and stops at the start of the element data.
     *
     * @return array{id: int, size: int, offset: int}|null size is UNKNOWN_SIZE for an element of unknown size, null at the end of the stream
     */
    public function readElementHeader(): ?array
    {
        $start = $this->tell();
        $bytes = fread($this->stream, self::MAX_HEADER_LENGTH);
        if ($bytes === false || $bytes === "") {
            return null;
        }

        $id   = self::readVint($bytes, 0, true);
        $size = $id === null ? null : self::readVint($bytes, $id[1], false);
        if ($id === null || $size === null) {
            throw new ParsingException("The EBML element header at byte $start is not valid.");
        }

        $length = $id[1] + $size[1];
        $this->seek($start + $length);

        return [
            "id"     => $id[0],
            "size"   => $size[0] === (1 << (7 * $size[1])) - 1 ? self::UNKNOWN_SIZE : $size[0],
            "offset" => $start + $length,
        ];
    }


    public function readBytes(int $length): string
    {
        if ($length === 0) {
            return "";
        }

        // fread() allocates $length bytes before it reads, so a damaged size must not reach it.
        $start = $this->tell();
        $bytes = $length > $this->streamSize - $start ? false : fread($this->stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new ParsingException("The element data at byte $start needs $length bytes, but the file ends before.");
        }

        return $bytes;
    }


    /**
     * Reads the data of $element as an unsigned integer. RFC 8794 allows 8 bytes, and PHP holds values below 2^63.
     *
     * @param array{id: int, size: int, offset: int} $element
     */
    public function readUnsigned(array $element): int
    {
        $bytes = $this->readBytes($element["size"]);
        if (strlen($bytes) > 8 || (strlen($bytes) === 8 && ord($bytes[0]) >= 0x80)) {
            throw new ParsingException(sprintf("The unsigned integer of the element 0x%X at byte %d is longer than 8 bytes or not below 2^63.",
                                               $element["id"], $element["offset"]));
        }

        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }


    /**
     * Reads a string element and removes the NUL bytes that may pad it.
     */
    public function readString(int $length): string
    {
        return rtrim($this->readBytes($length), "\0");
    }


    /**
     * Reads a variable-length integer. Element IDs keep the length marker bit, data sizes and track numbers do not.
     *
     * @return array{int, int}|null [value, length in bytes], null for an invalid first byte or too few bytes
     */
    public static function readVint(string $bytes, int $offset, bool $keepMarker): ?array
    {
        if (!isset($bytes[$offset])) {
            return null;
        }

        $first  = ord($bytes[$offset]);
        $length = 1;
        while ($length <= 8 && ($first & (0x100 >> $length)) === 0) {
            $length++;
        }
        if ($length > 8 || strlen($bytes) < $offset + $length) {
            return null;
        }

        $value = $keepMarker ? $first : $first & ((0x100 >> $length) - 1);
        for ($i = 1; $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$offset + $i]);
        }

        return [$value, $length];
    }
}
