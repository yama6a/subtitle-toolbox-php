<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Closure;
use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\StringHelpers;

/**
 * @internal
 */
final class Streams
{
    private const CHUNK_BYTES = 8192;


    /**
     * Yields the lines of a stream resource or file path without UTF-8 BOMs at their start and without line endings.
     * It calls $onInvalidUtf8 with the byte offset of the first byte that is not valid UTF-8.
     *
     * @param resource|string     $stream
     * @param ?Closure(int): void $onInvalidUtf8
     *
     * @return Generator<int, string>
     */
    public static function lines($stream, ?Closure $onInvalidUtf8 = null): Generator
    {
        $ownsHandle = !is_resource($stream);
        $handle     = self::open($stream, "rb");
        $offset     = 0;
        $buffer     = "";
        try {
            do {
                $chunk = fread($handle, self::CHUNK_BYTES);
                $atEnd = $chunk === false || $chunk === "";
                $buffer .= $chunk === false ? "" : $chunk;
                foreach (self::split($buffer, $atEnd) as [$line, $endLength]) {
                    $badByte = $onInvalidUtf8 === null ? null : StringHelpers::findInvalidUtf8Offset($line);
                    if ($badByte !== null) {
                        $onInvalidUtf8($offset + $badByte);
                        $onInvalidUtf8 = null;
                    }
                    $offset += strlen($line) + $endLength;

                    // Joined files keep the BOM of each part at the start of a line.
                    yield StringHelpers::removeUtf8Bom($line);
                }
            } while (!$atEnd);
        } finally {
            // The caller may close its stream before a stopped generator runs this block.
            if ($ownsHandle) {
                fclose($handle);
            }
        }
    }


    /**
     * Cuts the complete lines off the front of $buffer. A line ends at LF, CR LF or CR.
     * A CR at the end of $buffer waits for the next chunk, which may start with the LF. At the end of the stream the rest is a line.
     *
     * @return Generator<int, array{string, int}> the line and the length of its line ending
     */
    private static function split(string &$buffer, bool $atEnd): Generator
    {
        $length = strlen($buffer);
        $start  = 0;
        while ($start < $length) {
            $end = $start + strcspn($buffer, "\r\n", $start);
            if ($end === $length) {
                break;
            }
            $endLength = 1;
            if ($buffer[$end] === "\r") {
                // CR CR LF is one line ending, as StringHelpers::normalizeEOLs() reads it.
                $crCount = strspn($buffer, "\r", $end);
                $after   = $end + $crCount;
                if ($after === $length && !$atEnd) {
                    break;
                }
                $endLength = ($buffer[$after] ?? "") === "\n" ? $crCount + 1 : 1;
            }
            yield [substr($buffer, $start, $end - $start), $endLength];
            $start = $end + $endLength;
        }
        $buffer = (string) substr($buffer, $start);

        if ($atEnd && $buffer !== "") {
            $line   = $buffer;
            $buffer = "";
            yield [$line, 0];
        }
    }


    /**
     * @param resource|string $stream
     *
     * @return resource
     */
    public static function open($stream, string $mode)
    {
        if (is_resource($stream) && get_resource_type($stream) === "stream") {
            return $stream;
        }
        if (!is_string($stream)) {
            throw new InvalidArgumentException("The stream must be a stream resource or a file path.");
        }

        $handle = @fopen($stream, $mode);
        if ($handle === false) {
            throw new InvalidArgumentException("Cannot open the file $stream.");
        }

        return $handle;
    }


    /**
     * @param resource|null $handle null after the writer closed the stream
     */
    public static function write($handle, string $text): void
    {
        if ($handle === null) {
            throw new InvalidArgumentException("The writer is closed.");
        }
        // fwrite() raises a notice before it returns false, and the exception reports the failure.
        if ($text !== "" && @fwrite($handle, $text) === false) {
            throw new InvalidArgumentException("Cannot write to the stream.");
        }
    }
}
