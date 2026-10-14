<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

/**
 * Writes MPEG transport stream packets with teletext PES packets for test fixtures.
 * Packets and tables follow ISO/IEC 13818-1, teletext PES packets ETSI EN 300 472, teletext packets ETS 300 706.
 */
final class TsFixtureWriter
{
    public const START_BOX = "\x0B\x0B";
    public const END_BOX   = "\x0A\x0A";

    public const DOUBLE_HEIGHT = "\x0D";

    public const SUBTITLE_PAGE         = 0x02;
    public const HEARING_IMPAIRED_PAGE = 0x05;
    public const INITIAL_PAGE          = 0x01;

    /** @var array<int, int> */
    private array $counters = [];

    private string $file = "";


    public function bytes(): string
    {
        return $this->file;
    }


    /**
     * Adds the PAT of one program and its PMT. Each stream is [pid, stream type, descriptors].
     *
     * @param list<array{int, int, string}> $streams
     */
    public function tables(int $programNumber, int $pmtPid, int $pcrPid, array $streams): void
    {
        $this->section(0x0000, 0x00, 1, pack("nn", $programNumber, 0xE000 | $pmtPid));
        $loop = "";
        foreach ($streams as [$pid, $type, $descriptors]) {
            $loop .= pack("Cnn", $type, 0xE000 | $pid, 0xF000 | strlen($descriptors)) . $descriptors;
        }
        $this->section($pmtPid, 0x02, $programNumber, pack("nn", 0xE000 | $pcrPid, 0xF000) . $loop);
    }


    /**
     * A teletext descriptor. Each entry is [language, teletext type, page number such as 888].
     *
     * @param list<array{string, int, int}> $pages
     */
    public static function teletextDescriptor(array $pages): string
    {
        $entries = "";
        foreach ($pages as [$language, $type, $page]) {
            $entries .= $language . chr($type << 3 | intdiv($page, 100) % 8) . chr(intdiv($page % 100, 10) << 4 | $page % 10);
        }

        return chr(0x56) . chr(strlen($entries)) . $entries;
    }


    /**
     * A subtitling descriptor of ETSI EN 300 468. Each entry is [language, subtitling type, composition page, ancillary page].
     *
     * @param list<array{string, int, int, int}> $services
     */
    public static function subtitlingDescriptor(array $services): string
    {
        $entries = "";
        foreach ($services as [$language, $type, $page, $ancillaryPage]) {
            $entries .= $language . pack("Cnn", $type, $page, $ancillaryPage);
        }

        return chr(0x59) . chr(strlen($entries)) . $entries;
    }


    /**
     * Adds a DVB subtitle PES packet with the segments of ETSI EN 300 743.
     */
    public function subtitlePes(int $pid, int $pts, string $segments): void
    {
        $this->packets($pid, self::pes(0xBD, $pts, "\x20\x00" . $segments . "\xFF", 5), null);
    }


    /**
     * Adds a PES packet with a PTS and a PCR in its first TS packet.
     */
    public function videoPes(int $pid, int $pts): void
    {
        $this->packets($pid, self::pes(0xE0, $pts, str_repeat("\0", 16), 5), $pts);
    }


    /**
     * Adds a teletext PES packet with the teletext packets and stuffing data units, so that it fills whole TS packets.
     *
     * @param list<string> $packets teletext packets of 42 bytes in the bit order of ETS 300 706
     */
    public function teletextPes(int $pid, int $pts, array $packets): void
    {
        $data = "\x10";
        foreach ($packets as $index => $packet) {
            $data .= "\x03\x2C" . chr(0xE0 | (7 + $index)) . "\xE4" . strtr($packet, self::bytes256(), self::reversed256());
        }
        while ((45 + strlen($data)) % 184 !== 0) {
            $data .= "\xFF\x2C" . str_repeat("\xFF", 44);
        }

        $this->packets($pid, self::pes(0xBD, $pts, $data, 36), null);
    }


    /**
     * Adds a PES packet of private stream 1 with a PTS, such as an ARIB caption PES packet.
     */
    public function privatePes(int $pid, int $pts, string $data): void
    {
        $this->packets($pid, self::pes(0xBD, $pts, $data, 5), null);
    }


    /**
     * Returns the bytes as an M2TS file of Blu-ray and Japanese recorders: each packet gets a 4-byte arrival time stamp
     * of a 27 MHz clock in front of it.
     */
    public static function m2ts(string $transportStream): string
    {
        $file = "";
        foreach (str_split($transportStream, 188) as $index => $packet) {
            $file .= pack("N", $index * 2700 & 0x3FFFFFFF) . $packet;
        }

        return $file;
    }


    /**
     * A page header packet X/0. $page is the page number such as 888. $national holds C12 to C14 with C12 as bit 0.
     */
    public static function header(int $page, bool $erase, int $national = 0, bool $subtitle = true, bool $serial = false): string
    {
        $magazine = intdiv($page, 100) % 8;
        $bytes    = [$page % 10, intdiv($page % 100, 10), 0, $erase ? 0x08 : 0, 0, $subtitle ? 0x08 : 0, $subtitle ? 0x01 : 0, ($serial ? 1 : 0) | $national << 1];

        return self::address($magazine, 0) . implode("", array_map(self::hamming84(...), $bytes)) . self::text(str_pad("$page TEXT", 32));
    }


    /**
     * A time filling header with the page number FF of a magazine such as 8. It ends the page transmission of the magazine.
     */
    public static function fillingHeader(int $magazine): string
    {
        return self::address($magazine % 8, 0) . implode("", array_map(self::hamming84(...), [0xF, 0xF, 0, 0, 0, 0, 0, 0])) . self::text(str_repeat(" ", 32));
    }


    /**
     * A display row packet X/1 to X/23. $codes holds the attribute bytes and the G0 codes of the row.
     */
    public static function row(int $page, int $row, string $codes): string
    {
        return self::address(intdiv($page, 100) % 8, $row) . self::text(str_pad($codes, 40));
    }


    /**
     * Packet X/28/0 format 1 or M/29/0 with the G0 character set designation of $group and $national.
     * Triplet bits 10, 9 and 8 hold C12, C13 and C14, the reverse order of $national.
     */
    public static function designation(int $page, int $row, int $group, int $national): string
    {
        $options  = ($national & 1) << 2 | ($national & 2) | ($national >> 2 & 1);
        $triplets = self::hamming2418(($group << 3 | $options) << 7) . str_repeat(self::hamming2418(0), 12);

        return self::address(intdiv($page, 100) % 8, $row) . self::hamming84(0) . $triplets;
    }


    /**
     * Returns $text with the odd parity bit of each byte.
     */
    public static function text(string $text): string
    {
        return implode("", array_map(fn (string $character): string => chr(ord($character) | (substr_count(decbin(ord($character)), "1") % 2 === 0 ? 0x80 : 0)),
                                     str_split($text)));
    }


    public static function hamming84(int $value): string
    {
        [$d1, $d2, $d3, $d4] = [$value & 1, $value >> 1 & 1, $value >> 2 & 1, $value >> 3 & 1];
        [$p1, $p2, $p3]      = [1 ^ $d1 ^ $d3 ^ $d4, 1 ^ $d1 ^ $d2 ^ $d4, 1 ^ $d1 ^ $d2 ^ $d3];
        $p4                  = 1 ^ $p1 ^ $d1 ^ $p2 ^ $d2 ^ $p3 ^ $d3 ^ $d4;

        return chr($p1 | $d1 << 1 | $p2 << 2 | $d2 << 3 | $p3 << 4 | $d3 << 5 | $p4 << 6 | $d4 << 7);
    }


    public static function hamming2418(int $value): string
    {
        $bits = ($value & 1) << 2 | ($value >> 1 & 0x07) << 4 | ($value >> 4 & 0x7F) << 8 | ($value >> 11 & 0x7F) << 16;
        foreach ([1, 2, 4, 8, 16] as $check) {
            $ones = 0;
            for ($position = 1; $position <= 23; $position++) {
                $ones += ($position & $check) !== 0 ? $bits >> ($position - 1) & 1 : 0;
            }
            $bits |= $ones % 2 === 0 ? 1 << ($check - 1) : 0;
        }
        $bits |= substr_count(decbin($bits), "1") % 2 === 0 ? 1 << 23 : 0;

        return chr($bits & 0xFF) . chr($bits >> 8 & 0xFF) . chr($bits >> 16);
    }


    private static function address(int $magazine, int $row): string
    {
        return self::hamming84($magazine | ($row & 1) << 3) . self::hamming84($row >> 1);
    }


    private function section(int $pid, int $tableId, int $tableIdExtension, string $body): void
    {
        $section = chr($tableId) . pack("n", 0xB000 | (strlen($body) + 9)) . pack("n", $tableIdExtension) . "\xC1\x00\x00" . $body;
        $this->packets($pid, "\x00" . $section . pack("N", self::crc32Mpeg2($section)), null);
    }


    private static function pes(int $streamId, int $pts, string $data, int $headerLength): string
    {
        $ptsBytes = chr(0x21 | ($pts >> 29 & 0x0E)) . pack("n", ($pts >> 14 & 0xFFFE) | 1) . pack("n", ($pts << 1 & 0xFFFE) | 1);
        $header   = "\x84\x80" . chr($headerLength) . $ptsBytes . str_repeat("\xFF", $headerLength - 5);

        return "\x00\x00\x01" . chr($streamId) . pack("n", strlen($header) + strlen($data)) . $header . $data;
    }


    /**
     * Splits $payload into TS packets. The adaptation field of the first packet holds the PCR when $pcr is set.
     * The adaptation field of the last packet stuffs it to 188 bytes.
     */
    private function packets(int $pid, string $payload, ?int $pcr): void
    {
        $first = true;
        while ($payload !== "" || $first) {
            $adaptation = $first && $pcr !== null ? "\x10" . pack("N", $pcr >> 1) . chr(($pcr & 1) << 7 | 0x7E) . "\x00" : null;
            $room       = 184 - ($adaptation === null ? 0 : 1 + strlen($adaptation));
            if (strlen($payload) < $room) {
                $missing    = $room - strlen($payload);
                $adaptation = $adaptation === null
                    ? ($missing === 1 ? "" : "\x00" . str_repeat("\xFF", $missing - 2))
                    : $adaptation . str_repeat("\xFF", $missing);
                $room       = strlen($payload);
            }
            $counter              = $this->counters[$pid] ?? 0;
            $this->counters[$pid] = ($counter + 1) % 16;
            $this->file          .= "\x47" . pack("n", ($first ? 0x4000 : 0) | $pid) . chr(($adaptation === null ? 0x10 : 0x30) | $counter) .
                                    ($adaptation === null ? "" : chr(strlen($adaptation)) . $adaptation) . substr($payload, 0, $room);
            $payload              = (string) substr($payload, $room);
            $first                = false;
        }
    }


    private static function crc32Mpeg2(string $data): int
    {
        $crc = 0xFFFFFFFF;
        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte) << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x80000000) !== 0 ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
            }
        }

        return $crc;
    }


    private static function bytes256(): string
    {
        return implode("", array_map(chr(...), range(0, 255)));
    }


    private static function reversed256(): string
    {
        return implode("", array_map(fn (int $byte): string => chr(bindec(strrev(str_pad(decbin($byte), 8, "0", STR_PAD_LEFT)))), range(0, 255)));
    }
}
