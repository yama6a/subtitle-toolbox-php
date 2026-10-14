<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use Generator;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\ContainerReader;
use SubtitleToolbox\Container\DvbSubtitleDecoder;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\Streams;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads the teletext subtitle pages and the DVB subtitle streams of MPEG transport streams, such as DVB recordings, and the
 * ARIB captions of ISDB recordings. It reads the file once from the start and keeps only the packets of the subtitle stream.
 * It reads M2TS files with 192-byte packets too.
 *
 * Packets, PAT, PMT and PES: ISO/IEC 13818-1. Teletext and subtitling descriptors: ETSI EN 300 468. Caption streams:
 * ARIB STD-B10 and ARIB TR-B14.
 */
final class MpegTsReader implements ContainerReader
{
    public const PACKET_SIZE = 188;

    // An M2TS packet has a 4-byte arrival time stamp before the transport stream packet.
    public const M2TS_PACKET_SIZE = 192;

    public const SYNC_BYTE = "\x47";

    public const CODEC_TELETEXT = "dvb_teletext";

    public const CODEC_DVB_SUBTITLE = "dvb_subtitle";

    public const CODEC_ARIB_CAPTION = "arib_caption";

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

    private const SUBTITLING_DESCRIPTOR = 0x59;

    // The subtitling types of DVB subtitles for the hard of hearing, from "no monitor aspect ratio" to "plano-stereoscopic HD".
    private const HEARING_IMPAIRED_SUBTITLES = [0x20, 0x21, 0x22, 0x23, 0x24, 0x25];

    // The stream identifier descriptor, and the data component descriptor with the data component ID of captions. The component
    // tags 0x30 to 0x37 are the caption streams of ARIB TR-B14 profile A.
    private const STREAM_IDENTIFIER_DESCRIPTOR = 0x52;
    private const DATA_COMPONENT_DESCRIPTOR    = 0xFD;
    private const CAPTION_DATA_COMPONENT       = 0x0008;
    private const CAPTION_COMPONENT_TAGS       = [0x30, 0x37];

    private const CLOCK_RATE = 90000;
    private const PTS_RANGE  = 1 << 33;

    /** @var resource */
    private $stream;

    private bool $ownsStream;

    private int $packetSize = self::PACKET_SIZE;

    /** @var array<int, SubtitleTrack> */
    private array $tracks = [];

    /** @var array<int, array{pid: int, page: int, ancillaryPage: ?int, streams: list<int>}> */
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
     * Returns true for content with the sync byte at the start of its first 3 packets of 188 bytes, or after the time stamp
     * of its first 3 packets of 192 bytes.
     */
    public static function isTransportStream(string $head): bool
    {
        return self::packetSize($head) !== null;
    }


    private static function packetSize(string $head): ?int
    {
        foreach ([self::PACKET_SIZE, self::M2TS_PACKET_SIZE] as $size) {
            $sync = $size - self::PACKET_SIZE;
            if (strlen($head) > 2 * $size + $sync && $head[$sync] === self::SYNC_BYTE && $head[$size + $sync] === self::SYNC_BYTE
                && $head[2 * $size + $sync] === self::SYNC_BYTE) {
                return $size;
            }
        }

        return null;
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
     * Reads the teletext page, the DVB subtitle stream or the ARIB caption stream with the track number $trackNumber. Each state
     * of a teletext page or of the caption screen that shows text becomes a text cue. Each display set of DVB subtitles that
     * shows pixels becomes an image cue. Times count from the first PTS of the program.
     */
    public function extract(int $trackNumber, ?ReadOptions $options = null): Subtitle
    {
        $options ??= new ReadOptions();
        $track     = $this->subtitleTrack($trackNumber);
        $page      = $this->pages[$trackNumber];
        $warnings  = [];
        if ($track->codecId === self::CODEC_ARIB_CAPTION) {
            $decoder       = new AribCaptionDecoder($track->language);
            [$zero, $last] = $this->readPes($page, $decoder->add(...));
            $cues          = $this->textCues($decoder->finish(), $zero, $last, $options);
            foreach ($decoder->warnings() as [$time, $message]) {
                $seconds    = number_format(max(0, $time - $zero) / self::CLOCK_RATE, 3, ".", "");
                $warnings[] = new ParseWarning("At $seconds s: $message", null, null, [], ParseWarningAction::Repaired);
            }
        } elseif ($track->codecId === self::CODEC_DVB_SUBTITLE) {
            $decoder = new DvbSubtitleDecoder($page["page"], $page["ancillaryPage"]);
            [$zero]  = $this->readPes($page, fn (string $data, int $pts) => $decoder->add($data, $pts / self::CLOCK_RATE));
            $cues    = $decoder->finish($options->lastCueDuration, $zero / self::CLOCK_RATE);
        } else {
            $decoder       = new TeletextDecoder($page["page"], $trackNumber % 1000, TeletextCharacters::languageGroup($track->language));
            [$zero, $last] = $this->readPes($page, $decoder->add(...));
            $cues          = $this->textCues($decoder->finish(), $zero, $last, $options);
        }

        $subtitle = (new Subtitle())->addCues($cues)->setParseWarnings($warnings);
        $subtitle->setFormat($track->format);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $track->language);

        return $subtitle;
    }


    /**
     * Passes the data and the PTS of each PES packet of the page stream to $add. Returns the first PTS of the program and the
     * last PTS of the stream. A PTS after the wrap of the 33-bit clock keeps counting.
     *
     * @param array{pid: int, streams: list<int>} $page
     * @param callable(string, int): void $add
     * @return array{int, int}
     */
    private function readPes(array $page, callable $add): array
    {
        $first   = [];
        $lastPts = null;
        foreach ($this->pesPackets($page["pid"], $page["streams"]) as [$pid, $pts, $data]) {
            $pts       = $pts === null ? $lastPts : $pts + (int) round((($lastPts ?? $pts) - $pts) / self::PTS_RANGE) * self::PTS_RANGE;
            $lastPts   = $pts ?? $lastPts;
            $first[$pid] ??= $pts;
            if ($pid === $page["pid"] && $pts !== null) {
                $add($data, $pts);
            }
        }

        $zero = $first === [] ? 0 : min(array_filter($first, fn (?int $pts): bool => $pts !== null) ?: [0]);

        return [$zero, $lastPts ?? $zero];
    }


    /**
     * @param list<array{0: int, 1: list<string>, 2?: Alignment}> $screenStates the PTS, the lines and the alignment of each state
     *                                                                          of the teletext page or the caption screen
     * @return list<SubtitleCue>
     */
    private function textCues(array $screenStates, int $zero, int $last, ReadOptions $options): array
    {
        $states = [];
        foreach ($screenStates as $state) {
            if ($states === [] || array_slice($states[array_key_last($states)], 1) !== array_slice($state, 1)) {
                $states[] = $state;
            }
        }

        $cues = [];
        foreach ($states as $index => $state) {
            [$start, $lines] = $state;
            if ($lines !== []) {
                $end    = $states[$index + 1][0] ?? ($last > $start ? $last : $start + (int) ($options->lastCueDuration * self::CLOCK_RATE));
                $cue    = new SubtitleCue(max(0, $start - $zero) / self::CLOCK_RATE, max(0, $end - $zero) / self::CLOCK_RATE, $lines);
                $cues[] = isset($state[2]) ? $cue->setAlignment($state[2]) : $cue;
            }
        }

        return $cues;
    }


    private function subtitleTrack(int $trackNumber): SubtitleTrack
    {
        return $this->tracks[$trackNumber]
            ?? throw new InvalidArgumentException("The file has no subtitle track with the number $trackNumber.");
    }


    /**
     * Reads the PAT and the PMT of each program. It lists each teletext subtitle page as a track, then each DVB subtitle service,
     * then each ARIB caption stream.
     */
    private function readTables(): void
    {
        fseek($this->stream, 0);
        $this->packetSize = self::packetSize((string) fread($this->stream, 2 * self::M2TS_PACKET_SIZE + 5))
                            ?? throw new ParsingException("The file is not an MPEG-TS file.");

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
        foreach ($streams as $program) {
            $this->addDvbSubtitles($program);
        }
        $this->addAribCaptions($streams);
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
                    $this->pages[$number]  = ["pid" => $pid, "page" => $magazine << 8 | $pageByte, "ancillaryPage" => null, "streams" => $pids];
                }
            }
        }
    }


    /**
     * Lists each entry of the subtitling descriptors as a track. An entry is a subtitle service with its language and its pages.
     * Decision: the first entry of a stream has the PID as its number, for example 2050. A PID from 100 to 899 can be the
     * number of a teletext page, which keeps its number. A clash, and each later entry of the same stream, gives the PID times
     * 1,000 plus the index of the entry, as a clash of teletext pages does.
     *
     * @param list<array{pid: int, type: int, descriptors: string}> $program
     */
    private function addDvbSubtitles(array $program): void
    {
        $pids = array_column($program, "pid");
        foreach ($program as ["pid" => $pid, "type" => $type, "descriptors" => $descriptors]) {
            if ($type !== self::STREAM_TYPE_PES_PRIVATE_DATA) {
                continue;
            }
            $entries = str_split(implode("", iterator_to_array(self::descriptors($descriptors, [self::SUBTITLING_DESCRIPTOR]), false)), 8);
            foreach ($entries as $index => $entry) {
                $number = $index === 0 && !isset($this->tracks[$pid]) ? $pid : $pid * 1000 + $index;
                if (strlen($entry) < 8 || isset($this->tracks[$number])) {
                    continue;
                }

                ["type" => $subtitlingType, "page" => $page, "ancillary" => $ancillary] = unpack("Ctype/npage/nancillary", $entry, 3);
                $language              = strtolower(substr($entry, 0, 3));
                $this->tracks[$number] = new SubtitleTrack(
                    ContainerFormat::MpegTs,
                    $number,
                    self::CODEC_DVB_SUBTITLE,
                    Format::Pgs,
                    preg_match('/^[a-z]{3}$/', $language) === 1 ? $language : "und",
                    in_array($subtitlingType, self::HEARING_IMPAIRED_SUBTITLES, true) ? "hearing impaired" : null,
                    false,
                    false,
                );
                $this->pages[$number]  = ["pid" => $pid, "page" => $page, "ancillaryPage" => $ancillary, "streams" => $pids];
            }
        }
    }


    /**
     * Lists each ARIB caption stream as a track. Its number is its PID, as for DVB subtitles, or the PID times 1,000 when a
     * teletext page or a DVB subtitle stream has the number. The language comes from the first caption management data
     * in the first 32 MB of the file.
     *
     * @param array<int, list<array{pid: int, type: int, descriptors: string}>> $programs
     */
    private function addAribCaptions(array $programs): void
    {
        $captions = [];
        foreach ($programs as $program) {
            foreach ($program as ["pid" => $pid, "type" => $type, "descriptors" => $descriptors]) {
                $tag       = ord((implode("", iterator_to_array(self::descriptors($descriptors, [self::STREAM_IDENTIFIER_DESCRIPTOR]), false)) . "\0")[0]);
                $component = implode("", iterator_to_array(self::descriptors($descriptors, [self::DATA_COMPONENT_DESCRIPTOR]), false));
                if ($type === self::STREAM_TYPE_PES_PRIVATE_DATA && $tag >= self::CAPTION_COMPONENT_TAGS[0] && $tag <= self::CAPTION_COMPONENT_TAGS[1]
                    && strlen($component) >= 2 && unpack("n", $component)[1] === self::CAPTION_DATA_COMPONENT) {
                    $captions[$pid] = array_column($program, "pid");
                }
            }
        }

        $languages = $captions === [] ? [] : $this->captionLanguages(array_keys($captions));
        foreach ($captions as $pid => $pids) {
            $number                = isset($this->tracks[$pid]) ? $pid * 1000 : $pid;
            $this->tracks[$number] = new SubtitleTrack(
                ContainerFormat::MpegTs,
                $number,
                self::CODEC_ARIB_CAPTION,
                Format::SubRip,
                $languages[$pid] ?? "und",
                null,
                false,
                false,
            );
            $this->pages[$number]  = ["pid" => $pid, "page" => 0, "ancillaryPage" => null, "streams" => $pids];
        }
    }


    /**
     * Returns the language of the first caption management data of each PID in the first 32 MB of the file.
     *
     * @param list<int> $pids
     * @return array<int, string>
     */
    private function captionLanguages(array $pids): array
    {
        $wanted    = array_fill_keys($pids, true);
        $pes       = [];
        $languages = [];
        foreach ($this->payloads($wanted, self::TABLE_SCAN_BYTES) as [$pid, $start, $payload]) {
            if ($start && isset($pes[$pid])) {
                $language = AribCaptionDecoder::language(self::pesHeader($pes[$pid])[1]);
                if ($language !== null && preg_match('/^[a-z]{3}$/', $language) === 1) {
                    $languages[$pid] = $language;
                    unset($wanted[$pid], $pes[$pid]);
                    if ($wanted === []) {
                        break;
                    }
                    continue;
                }
            }
            if ($start || isset($pes[$pid])) {
                $pes[$pid] = $start ? $payload : $pes[$pid] . $payload;
            }
        }

        return $languages;
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
     * An M2TS packet holds the transport stream packet after its 4-byte time stamp.
     *
     * @param array<int, true> $wanted
     * @return Generator<int, array{int, bool, string}>
     */
    private function payloads(array &$wanted, int $maxBytes = PHP_INT_MAX): Generator
    {
        fseek($this->stream, 0);
        $chunk = "";
        $read  = 0;
        $sync  = $this->packetSize - self::PACKET_SIZE;
        while ($read < $maxBytes && ($data = fread($this->stream, self::CHUNK_SIZE)) !== false && $data !== "") {
            $read  += strlen($data);
            $chunk .= $data;
            $length = strlen($chunk);
            $offset = 0;
            while ($offset + $this->packetSize <= $length) {
                if ($chunk[$offset + $sync] !== self::SYNC_BYTE) {
                    $next   = strpos($chunk, self::SYNC_BYTE, $offset + $sync + 1);
                    $offset = $next === false ? $length : $next - $sync;
                    continue;
                }

                $packet = $offset + $sync;
                $flags  = ord($chunk[$packet + 1]);
                $pid    = ($flags & 0x1F) << 8 | ord($chunk[$packet + 2]);
                if (isset($wanted[$pid]) && ($flags & 0x80) === 0) {
                    $control = ord($chunk[$packet + 3]) >> 4 & 0x03;
                    $at      = 4 + (($control & 0x02) !== 0 ? 1 + ord($chunk[$packet + 4]) : 0);
                    if (($control & 0x01) !== 0 && $at < self::PACKET_SIZE) {
                        yield [$pid, ($flags & 0x40) !== 0, substr($chunk, $packet + $at, self::PACKET_SIZE - $at)];
                    }
                }
                $offset += $this->packetSize;
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
