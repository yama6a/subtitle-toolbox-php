<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

require_once __DIR__ . "/../../files/mkv/generator/MkvFixtureWriter.php";

class EbmlReaderTest extends TestCase
{
    public static function vints(): array
    {
        return [
            "1-byte ID with marker"    => ["\xA3", true, [0xA3, 1]],
            "4-byte ID with marker"    => ["\x1A\x45\xDF\xA3", true, [0x1A45DFA3, 4]],
            "1-byte size"              => ["\x85", false, [5, 1]],
            "2-byte size"              => ["\x40\x7F", false, [127, 2]],
            "8-byte size"              => ["\x01\x00\x00\x00\x00\x01\x00\x00", false, [65536, 8]],
            "first byte 0"             => ["\x00\x81", false, null],
            "too few bytes"            => ["\x40", false, null],
        ];
    }


    #[DataProvider("vints")]
    public function testReadsVariableLengthIntegers(string $bytes, bool $keepMarker, ?array $expected): void
    {
        $this->assertSame($expected, EbmlReader::readVint($bytes, 0, $keepMarker));
    }


    public function testReadsElementHeadersAndValues(): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::uint(0x2AD7B1, 100000) . MkvFixtureWriter::element(0x4282, "webm\0\0") .
                        MkvFixtureWriter::element(0x4489, pack("E", 7200000.5)) . MkvFixtureWriter::unknownSizeElement(0x1F43B675, ""));
        rewind($stream);
        $reader = new EbmlReader($stream);

        $this->assertSame(["id" => 0x2AD7B1, "size" => 3, "offset" => 4], $reader->readElementHeader());
        $this->assertSame(100000, $reader->readUnsigned(["id" => 0x2AD7B1, "size" => 3, "offset" => 4]));
        $this->assertSame(0x4282, $reader->readElementHeader()["id"]);
        $this->assertSame("webm", $reader->readString(6));
        $this->assertSame(8, $reader->readElementHeader()["size"]);
        $this->assertSame(pack("E", 7200000.5), $reader->readBytes(8));
        $this->assertSame(EbmlReader::UNKNOWN_SIZE, $reader->readElementHeader()["size"]);
        $this->assertNull($reader->readElementHeader());
    }


    public static function brokenElements(): array
    {
        return [
            "ID with first byte 0"     => ["\x00\xA3", fn (EbmlReader $reader) => $reader->readElementHeader(), "The EBML element header at byte 0 is not valid."],
            "size with first byte 0"   => ["\xA3\x00\x81", fn (EbmlReader $reader) => $reader->readElementHeader(), "The EBML element header at byte 0 is not valid."],
            "size cut by the file end" => ["\xA3\x40", fn (EbmlReader $reader) => $reader->readElementHeader(), "The EBML element header at byte 0 is not valid."],
            "data cut by the file end" => [MkvFixtureWriter::element(0x86, "S_TEXT/UTF8") . "\x86\x8BS_TEXT", fn (EbmlReader $reader) => [
                $reader->readString($reader->readElementHeader()["size"]),
                $reader->readString($reader->readElementHeader()["size"]),
            ], "The element data at byte 15 needs 11 bytes, but the file ends before."],
        ];
    }


    #[DataProvider("brokenElements")]
    public function testThrowsForABrokenElement(string $bytes, \Closure $read, string $message): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $bytes);
        rewind($stream);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        $read(new EbmlReader($stream));
    }


    public static function unsignedOutOfRange(): array
    {
        return [
            "2^63"    => ["\x80" . str_repeat("\0", 7)],
            "9 bytes" => [str_repeat("\0", 8) . "\x01"],
        ];
    }


    #[DataProvider("unsignedOutOfRange")]
    public function testThrowsForAnUnsignedIntegerThatPhpCannotHold(string $data): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::element(0xE7, $data));
        rewind($stream);
        $reader = new EbmlReader($stream);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The unsigned integer of the element 0xE7 at byte 2 is longer than 8 bytes or not below 2^63.");

        $reader->readUnsigned($reader->readElementHeader());
    }


    public function testReadsTheLargestUnsignedIntegerBelow2To63(): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::element(0xE7, "\x7F" . str_repeat("\xFF", 7)));
        rewind($stream);
        $reader = new EbmlReader($stream);

        $this->assertSame(PHP_INT_MAX, $reader->readUnsigned($reader->readElementHeader()));
    }
}
