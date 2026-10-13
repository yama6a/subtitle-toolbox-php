<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use Generator;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\ContainerReader;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\Streams;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads the teletext subtitle pages of MPEG transport streams, such as DVB recordings. It reads the file once from the start
 * and keeps only the packets of the teletext stream.
 *
 * Packets, PAT, PMT and PES: ISO/IEC 13818-1. Teletext descriptor: ETSI EN 300 468.
 */
final class MpegTsReader implements ContainerReader
{
    public const PACKET_SIZE = 188;

    public const SYNC_BYTE = "\x47";

    public const CODEC_TELETEXT = "dvb_teletext";

    // A file with the PAT or a PMT more than 32 MB into it is not a recording that starts at a packet.
    private const TABLE_SCAN_BYTES = 32 << 20;

    private const CHUNK_SIZE = self::PACKET_SIZE << 11;

    private const PAT_PID   = 0x0000;
    private const TABLE_PAT = 0x00;
    private const TABLE_PMT = 0x02;

    private const STREAM_TYPE_PES_PRIVATE_DATA = 0x06;

    // The teletext descriptor and the VBI teletext descriptor, which has the same entries.
    private const TELETEXT_DESCRIPTORS = [0x56, 0x46];

    // The teletext types of a subtitle page and of a subtitle page for hearing impaired people.
    private const SUBTITLE_PAGE         = 0x02;
    private const HEARING_IMPAIRED_PAGE = 0x05;

    private const CLOCK_RATE = 90000;
    private const PTS_RANGE  = 1 << 33;

    /** @var resource */
    private $stream;

    private bool $ownsStream;

    /** @var array<int, SubtitleTrack> */
    private array $tracks = [];

    /** @var array<int, array{pid: int, page: int, streams: list<int>}> */
    private array $pages = [];


    /**
     * @param resource $stream
     */
    private function __construct($stream, bool $ownsStream)
    {
        $this->stream     = $stream;
        $this->ownsStream = $ownsStream;
        $this->readTables();
    }


    public function __destruct()
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }


    /**
     * Opens a file path or a seekable stream resource and reads the track list. A stream stays open after the reader ends.
     *
     * @param string|resource $file
     */
    public static function open($file): self
    {
        $stream = Streams::open($file, "rb");
        if (!stream_get_meta_data($stream)["seekable"]) {
            throw new InvalidArgumentException("The stream must be seekable.");
        }

        return new self($stream, !is_resource($file));
    }


    /**
     * Returns true for content with the sync byte at the start of its first 3 packets.
     */
    public static function isTransportStream(string $head): bool
    {
        return strlen($head) > 2 * self::PACKET_SIZE && $head[0] === self::SYNC_BYTE && $head[self::PACKET_SIZE] === self::SYNC_BYTE
               && $head[2 * self::PACKET_SIZE] === self::SYNC_BYTE;
    }


    /**
     * @return list<SubtitleTrack>
     */
    public function getSubtitleTracks(): array
    {
        return array_values($this->tracks);
    }


    /**
     * @throws InvalidArgumentException for a number that is not a subtitle page, as extract() does.
     */
    public function trackFormat(int $trackNumber): ?Format
    {
        return $this->subtitleTrack($trackNumber)->format;
    }


    /**
     * Reads the teletext page with the track number $trackNumber. Each state of the page that shows text becomes a cue.
     * Times count from the first PTS of the program.
     */
    public function extract(int $trackNumber, ?ReadOptions $options = null): Subtitle
    {
        $track   = $this->subtitleTrack($trackNumber);
        $page    = $this->pages[$trackNumber];
        $decoder = new TeletextDecoder($page["page"], $trackNumber % 1000, TeletextCharacters::languageGroup($track->language));

        $first   = [];
        $lastPts = null;
        foreach ($this->pesPackets($page["pid"], $page["streams"]) as [$pid, $pts, $data]) {
            $pts       = $pts === null ? $lastPts : $pts + (int) round((($lastPts ?? $pts) - $pts) / self::PTS_RANGE) * self::PTS_RANGE;
            $lastPts   = $pts ?? $lastPts;
            $first[$pid] ??= $pts;
            if ($pid === $page["pid"] && $pts !== null) {
                $decoder->add($data, $pts);
            }
        }

        $zero   = $first === [] ? 0 : min(array_filter($first, fn (?int $pts): bool => $pts !== null) ?: [0]);
        $last   = $lastPts ?? $zero;
        $states = [];
        foreach ($decoder->finish() as $state) {
            if ($states === [] || $states[array_key_last($states)][1] !== $state[1]) {
                $states[] = $state;
            }
        }

        $cues = [];
        foreach ($states as $index => [$start, $lines]) {
            if ($lines !== []) {
                $end    = $states[$index + 1][0] ?? ($last > $start ? $last : $start + (int) (($options ?? new ReadOptions())->lastCueDuration * self::CLOCK_RATE));
                $cues[] = new SubtitleCue(max(0, $start - $zero) / self::CLOCK_RATE, max(0, $end - $zero) / self::CLOCK_RATE, $lines);
            }
        }

        $subtitle = (new Subtitle())->addCues($cues);
        $subtitle->setFormat(Format::SubRip);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $track->language);

        return $subtitle;
    }


    private function subtitleTrack(int $trackNumber): SubtitleTrack
    {
        return $this->tracks[$trackNumber]
            ?? throw new InvalidArgumentException("The file has no subtitle track with the number $trackNumber.");
    }


    /**
     * Reads the PAT and the PMT of each program, and lists each teletext subtitle page as a track.
     */
    private function readTables(): void
    {
        fseek($this->stream, 0);
        if (!self::isTransportStream((string) fread($this->stream, 2 * self::PACKET_SIZE + 1))) {
            throw new ParsingException("The file is not an MPEG-TS file.");
        }

        $wanted   = [self::PAT_PID => true];
        $sections = [];
        $programs = null;
        $streams  = [];
        foreach ($this->payloads($wanted, self::TABLE_SCAN_BYTES) as [$pid, $start, $payload]) {
            foreach (self::sections($sections, $pid, $start, $payload) as $section) {
                $table = ord($section[0]);
                if ($pid === self::PAT_PID && $table === self::TABLE_PAT && $programs === null) {
                    $programs = self::programPids($section);
                    $wanted  += array_fill_keys($programs, true);
                } elseif ($programs !== null && $table === self::TABLE_PMT && in_array($pid, $programs, true) && !isset($streams[$pid])) {
                    $streams[$pid] = self::elementaryStreams($section);
                }
            }
            if ($programs !== null && count($streams) === count($programs)) {
                break;
            }
        }

        foreach ($streams as $program) {
            $this->addPages($program);
        }
    }


    /**
     * @param list<array{pid: int, type: int, descriptors: string}> $program
     */
    private function addPages(array $program): void
    {
        $pids = array_column($program, "pid");
        foreach ($program as ["pid" => $pid, "type" => $type, "descriptors" => $descriptors]) {
            if ($type !== self::STREAM_TYPE_PES_PRIVATE_DATA) {
                continue;
            }
            foreach (self::descriptors($descriptors, self::TELETEXT_DESCRIPTORS) as $entries) {
                foreach (str_split($entries, 5) as $entry) {
                    if (strlen($entry) < 5) {
                        continue;
                    }
                    $teletextType = ord($entry[3]) >> 3;
                    $magazine     = ord($entry[3]) & 0x07;
                    $pageByte     = ord($entry[4]);
                    if (!in_array($teletextType, [self::SUBTITLE_PAGE, self::HEARING_IMPAIRED_PAGE], true) || $pageByte > 0x99 || ($pageByte & 0x0F) > 9) {
                        continue;
                    }

                    $number = ($magazine === 0 ? 8 : $magazine) * 100 + ($pageByte >> 4) * 10 + ($pageByte & 0x0F);
                    $number = isset($this->tracks[$number]) ? $pid * 1000 + $number : $number;
                    if (isset($this->tracks[$number])) {
                        continue;
                    }

                    $language              = strtolower(substr($entry, 0, 3));
                    $this->tracks[$number] = new SubtitleTrack(
                        ContainerFormat::MpegTs,
                        $number,
                        self::CODEC_TELETEXT,
                        Format::SubRip,
                        preg_match('/^[a-z]{3}$/', $language) === 1 ? $language : "und",
                        $teletextType === self::HEARING_IMPAIRED_PAGE ? "hearing impaired" : null,
                        false,
                        false,
                    );
                    $this->pages[$number]  = ["pid" => $pid, "page" => $magazine << 8 | $pageByte, "streams" => $pids];
                }
            }
        }
    }


    /**
     * Yields the PID, the PTS or null, and the data of each PES packet of $pid. Of the other $streams, it yields only the first PES header.
     *
     * @param list<int> $streams
     * @return Generator<int, array{int, ?int, string}>
     */
    private function pesPackets(int $pid, array $streams): Generator
    {
        $wanted = array_fill_keys($streams, true);
        $data   = null;
        foreach ($this->payloads($wanted) as [$packetPid, $start, $payload]) {
            if ($packetPid !== $pid) {
                unset($wanted[$packetPid]);
                if ($start) {
                    yield [$packetPid, self::pesHeader($payload)[0], ""];
                }
            } elseif ($start || $data !== null) {
                if ($start && $data !== null) {
                    yield [$pid, ...self::pesHeader($data)];
                }
                $data = $start ? $payload : $data . $payload;
            }
        }
        if ($data !== null) {
            yield [$pid, ...self::pesHeader($data)];
        }
    }


    /**
     * Returns the PTS and the data of a PES packet. The PTS is null when the packet has none.
     *
     * @return array{?int, string}
     */
    private static function pesHeader(string $pes): array
    {
        if (strlen($pes) < 9 || !str_starts_with($pes, "\0\0\1")) {
            return [null, ""];
        }

        $length = unpack("n", $pes, 4)[1];
        $pes    = $length === 0 ? $pes : substr($pes, 0, 6 + $length);
        $pts    = null;
        if ((ord($pes[7]) & 0x80) !== 0 && strlen($pes) >= 14) {
            $bytes = array_map(ord(...), str_split(substr($pes, 9, 5)));
            $pts   = ($bytes[0] >> 1 & 0x07) << 30 | $bytes[1] << 22 | ($bytes[2] >> 1) << 15 | $bytes[3] << 7 | $bytes[4] >> 1;
        }

        return [$pts, (string) substr($pes, 9 + ord($pes[8]))];
    }


    /**
     * Yields the PID, the payload unit start flag and the payload of each packet of a PID in $wanted, from the start of the file.
     * The caller may remove PIDs from $wanted. A byte other than the sync byte at a packet start skips to the next sync byte.
     *
     * @param array<int, true> $wanted
     * @return Generator<int, array{int, bool, string}>
     */
    private function payloads(array &$wanted, int $maxBytes = PHP_INT_MAX): Generator
    {
        fseek($this->stream, 0);
        $chunk = "";
        $read  = 0;
        while ($read < $maxBytes && ($data = fread($this->stream, self::CHUNK_SIZE)) !== false && $data !== "") {
            $read  += strlen($data);
            $chunk .= $data;
            $length = strlen($chunk);
            $offset = 0;
            while ($offset + self::PACKET_SIZE <= $length) {
                if ($chunk[$offset] !== self::SYNC_BYTE) {
                    $next   = strpos($chunk, self::SYNC_BYTE, $offset + 1);
                    $offset = $next === false ? $length : $next;
                    continue;
                }

                $flags = ord($chunk[$offset + 1]);
                $pid   = ($flags & 0x1F) << 8 | ord($chunk[$offset + 2]);
                if (isset($wanted[$pid]) && ($flags & 0x80) === 0) {
                    $control = ord($chunk[$offset + 3]) >> 4 & 0x03;
                    $at      = 4 + (($control & 0x02) !== 0 ? 1 + ord($chunk[$offset + 4]) : 0);
                    if (($control & 0x01) !== 0 && $at < self::PACKET_SIZE) {
                        yield [$pid, ($flags & 0x40) !== 0, substr($chunk, $offset + $at, self::PACKET_SIZE - $at)];
                    }
                }
                $offset += self::PACKET_SIZE;
            }
            $chunk = substr($chunk, $offset);
        }
    }


    /**
     * Adds a packet payload to the section buffer of its PID and returns the sections that it completes.
     *
     * @param array<int, string> $buffers
     * @return list<string>
     */
    private static function sections(array &$buffers, int $pid, bool $start, string $payload): array
    {
        if ($start) {
            $pointer       = ord($payload[0]);
            $buffers[$pid] = isset($buffers[$pid]) ? $buffers[$pid] . substr($payload, 1, $pointer) : "";
            $complete      = self::completeSections($buffers[$pid]);
            $buffers[$pid] = substr($payload, 1 + $pointer);
        } elseif (isset($buffers[$pid])) {
            $buffers[$pid] .= $payload;
            $complete       = [];
        } else {
            return [];
        }

        return [...$complete, ...self::completeSections($buffers[$pid])];
    }


    /**
     * Removes the complete sections from the start of $buffer and returns them. A table ID of 0xFF starts the stuffing.
     *
     * @return list<string>
     */
    private static function completeSections(string &$buffer): array
    {
        $sections = [];
        while (strlen($buffer) >= 3 && $buffer[0] !== "\xFF") {
            $size = 3 + ((ord($buffer[1]) & 0x0F) << 8 | ord($buffer[2]));
            if (strlen($buffer) < $size) {
                break;
            }
            $sections[] = substr($buffer, 0, $size);
            $buffer     = substr($buffer, $size);
        }

        return $sections;
    }


    /**
     * @return list<int> the PMT PID of each program, without the network PID of program 0
     */
    private static function programPids(string $section): array
    {
        $pids = [];
        foreach (str_split(substr($section, 8, -4), 4) as $entry) {
            if (strlen($entry) === 4 && unpack("n", $entry)[1] !== 0) {
                $pids[] = unpack("n", $entry, 2)[1] & 0x1FFF;
            }
        }

        return array_values(array_unique($pids));
    }


    /**
     * @return list<array{pid: int, type: int, descriptors: string}>
     */
    private static function elementaryStreams(string $section): array
    {
        $end     = strlen($section) - 4;
        $offset  = 12 + (unpack("n", $section . "\0\0", 10)[1] & 0x0FFF);
        $streams = [];
        while ($offset + 5 <= $end) {
            ["type" => $type, "pid" => $pid, "length" => $length] = unpack("Ctype/npid/nlength", $section, $offset);
            $streams[] = ["pid" => $pid & 0x1FFF, "type" => $type, "descriptors" => substr($section, $offset + 5, min($length & 0x0FFF, $end - $offset - 5))];
            $offset   += 5 + ($length & 0x0FFF);
        }

        return $streams;
    }


    /**
     * Yields the data of each descriptor with a tag in $tags.
     *
     * @param list<int> $tags
     * @return Generator<int, string>
     */
    private static function descriptors(string $descriptors, array $tags): Generator
    {
        for ($offset = 0; $offset + 2 <= strlen($descriptors); $offset += 2 + ord($descriptors[$offset + 1])) {
            if (in_array(ord($descriptors[$offset]), $tags, true)) {
                yield substr($descriptors, $offset + 2, ord($descriptors[$offset + 1]));
            }
        }
    }
}
