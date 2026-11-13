<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../files/mpegts/generator/TsFixtureWriter.php";

class TeletextDecoderTest extends TestCase
{
    public function testEscapesTheTextOfARow(): void
    {
        // 0x03 sets yellow from the next cell on.
        $decoder = new TeletextDecoder(0x088, 888, 0);
        $decoder->add(self::pesData([TsFixtureWriter::header(888, true), TsFixtureWriter::row(888, 21, "\x0B\x0B<laughs> Tom & Ann"),
                                     TsFixtureWriter::row(888, 22, "\x0B\x0B\x03<b>")]), 90000);

        $this->assertSame([[90000, ["&lt;laughs&gt; Tom &amp; Ann", "<font color=\"#ffff00\">&lt;b&gt;</font>"]]], $decoder->finish());
    }


    /**
     * Returns the data field of a teletext PES packet. EN 300 472 sends the bits of each byte in the reverse order of ETS 300 706.
     *
     * @param list<string> $packets teletext packets of 42 bytes in the bit order of ETS 300 706
     */
    private static function pesData(array $packets): string
    {
        $data = "\x10";
        foreach ($packets as $packet) {
            $data .= "\x03\x2C\xE7\xE4" . implode("", array_map(fn (string $byte): string => chr(bindec(strrev(sprintf("%08b", ord($byte))))),
                                                               str_split($packet)));
        }

        return $data;
    }
}
