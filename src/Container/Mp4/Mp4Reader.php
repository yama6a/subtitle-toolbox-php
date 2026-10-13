<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Mp4;

use Generator;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\ContainerReader;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Encoding\CaptionDataCues;
use SubtitleToolbox\Encoding\Cea608Cues;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FormatOptionsList;
use SubtitleToolbox\Parsers\Mcc;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\Streams;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads the subtitle tracks of MP4 and MOV files. It reads only the samples of the wanted track and skips media data with fseek().
 *
 * Boxes: ISO/IEC 14496-12. Timed text samples: 3GPP TS 26.245. Closed caption samples: Apple QuickTime File Format.
 *
 * @see https://developer.apple.com/documentation/quicktime-file-format/closed_captioning_sample_data
 */
final class Mp4Reader implements ContainerReader
{
    public const CODEC_TX3G = "tx3g";
    public const CODEC_C608 = "c608";
    public const CODEC_C708 = "c708";

    public const CODECS = [self::CODEC_TX3G, self::CODEC_C608, self::CODEC_C708];

    /** The type of the first box of an MP4 file, at byte 4. */
    public const FTYP = "ftyp";

    private const FORMATS = [self::CODEC_TX3G => Format::SubRip, self::CODEC_C608 => Format::Scc, self::CODEC_C708 => Format::Mcc];

    // A c608 sample holds a cdat box with the CEA-608 byte pairs of field 1, one pair per frame.
    // A c708 sample holds a ccdp box with a caption distribution packet of SMPTE 334-2.
    private const FIELD_1_DATA = "cdat";
    private const CAPTION_PACKET = "ccdp";

    // The handler types of timed text, subtitle and closed caption tracks.
    private const SUBTITLE_HANDLERS = ["text", "sbtl", "subt", "clcp"];

    private const ENCRYPTED_ENTRIES = ["encv", "enca", "enct", "encs"];

    // tkhd flag track_enabled, and the tx3g displayFlags bit for "all samples are forced".
    private const TRACK_ENABLED      = 0x000001;
    private const ALL_SAMPLES_FORCED = 0x80000000;

    // ISO 639-2/T "und" in the packed form of mdhd.
    private const UNDETERMINED = "und";

    // A tx3g sample starts with a 16-bit text length.
    private const TEXT_LENGTH_SIZE = 2;

    // A styl box holds a 16-bit record count and records of 12 bytes: start and end character, font ID, face style
    // flags, font size and color. The reader reads at most this many bytes of modifier boxes after the text.
    private const STYLE_COUNT_SIZE   = 2;
    private const STYLE_RECORD_SIZE  = 12;
    private const MAX_MODIFIERS_SIZE = self::BOX_HEADER_SIZE + self::STYLE_COUNT_SIZE + 0xFFFF * self::STYLE_RECORD_SIZE;

    // The face style flags of 3GPP TS 26.245 and the tags that hold them.
    private const FACE_STYLE_TAGS = ["b" => 0x01, "i" => 0x02, "u" => 0x04];

    // A box header holds a 32-bit size and the type. A size of 1 adds a 64-bit size field.
    private const BOX_HEADER_SIZE   = 8;
    private const LARGE_SIZE_LENGTH = 8;

    // Byte offsets in the box data, which starts with the version byte. The keys are the box versions of ISO/IEC 14496-12.
    private const HDLR_HANDLER_TYPE_OFFSET = 8;
    private const TKHD_TRACK_ID_OFFSET     = [0 => 12, 1 => 20];
    private const MDHD_TIMESCALE_OFFSET    = [0 => 12, 1 => 20];
    private const MDHD_LANGUAGE_OFFSET     = [0 => 20, 1 => 32];
    private const MVHD_TIMESCALE_OFFSET    = [0 => 12, 1 => 20];

    // An edit list entry with this media time is an empty edit, a span without media before the track starts.
    private const EMPTY_EDIT = -1;

    /** @var resource */
    private $stream;

    private bool $ownsStream;

    private int $fileSize;

    /** @var array<int, SubtitleTrack> */
    private array $tracks = [];

    /** @var array<int, array{timescale: int, encrypted: bool, tables: array<string, array{offset: int, end: int}>, delay: float}> */
    private array $trackData = [];

    private int $movieTimescale = 0;


    /**
     * @param resource $stream
     */
    private function __construct($stream, bool $ownsStream)
    {
        $this->stream     = $stream;
        $this->ownsStream = $ownsStream;
        $this->fileSize   = (int) (fstat($stream)["size"] ?? 0);
        $this->readMovie();
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
     * @return list<SubtitleTrack>
     */
    public function getSubtitleTracks(): array
    {
        return array_values($this->tracks);
    }


    /**
     * Returns the format of the cues that extract() gives for the track, or null for a codec that it does not read.
     *
     * @throws InvalidArgumentException for a number that is not a subtitle track, as extract() does.
     */
    public function trackFormat(int $trackNumber): ?Format
    {
        return $this->subtitleTrack($trackNumber)->format;
    }


    /**
     * Reads all samples of the subtitle track with the track ID $trackNumber. An empty sample is a gap between cues.
     */
    public function extract(int $trackNumber, ?ReadOptions $options = null): Subtitle
    {
        $track = $this->subtitleTrack($trackNumber);
        $data  = $this->trackData[$trackNumber];
        if ($data["encrypted"]) {
            throw new ParsingException("Track $trackNumber has the codec $track->codecId and is encrypted.");
        }
        if ($track->format === null) {
            throw new ParsingException("Track $trackNumber has the codec $track->codecId. The reader extracts only " .
                                       implode(", ", self::CODECS) . ".");
        }

        $options ??= new ReadOptions();
        $cues      = match ($track->codecId) {
            self::CODEC_C608 => $this->captionCues($trackNumber, $options),
            self::CODEC_C708 => $this->packetCues($trackNumber, $options),
            default          => $this->textCues($trackNumber, $track->forced),
        };

        $subtitle = (new Subtitle())->addCues($cues);
        $subtitle->setFormat($track->format);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $track->language);

        return $subtitle;
    }


    /**
     * @return list<SubtitleCue>
     */
    private function textCues(int $trackNumber, bool $forced): array
    {
        $cues = [];
        foreach ($this->sampleTimes($trackNumber) as [$start, $end, $offset, $size]) {
            $text = $this->sampleText($trackNumber, $offset, $size);
            if ($text !== "") {
                $cues[] = (new SubtitleCue($start, $end, explode("\n", $text)))->setForced($forced);
            }
        }

        return $cues;
    }


    /**
     * Decodes the byte pairs of the cdat boxes as an SCC file with one line per sample.
     *
     * @return list<SubtitleCue>
     */
    private function captionCues(int $trackNumber, ReadOptions $options): array
    {
        $codeLines = [];
        foreach ($this->sampleTimes($trackNumber) as [$start, , $offset, $size]) {
            $words = [];
            foreach (self::sampleBoxes($this->sampleData($trackNumber, $offset, $size)) as [$type, $data]) {
                if ($type === self::FIELD_1_DATA) {
                    array_push($words, ...array_values(unpack("n*", substr($data, 0, strlen($data) & ~1))));
                }
            }
            if ($words !== []) {
                $codeLines[] = [Cea608Cues::secondsToFrame($start), $words];
            }
        }
        $sccOptions = FormatOptionsList::find($options->formats, SccReadOptions::class) ?? new SccReadOptions();

        return Cea608Cues::read($codeLines, $sccOptions, $options->lastCueDuration);
    }


    /**
     * Reads the caption distribution packets of the ccdp boxes as an MCC file with one line per sample.
     *
     * @return list<SubtitleCue>
     */
    private function packetCues(int $trackNumber, ReadOptions $options): array
    {
        $frames = [];
        foreach ($this->sampleTimes($trackNumber) as [$start, , $offset, $size]) {
            foreach (self::sampleBoxes($this->sampleData($trackNumber, $offset, $size)) as [$type, $data]) {
                if ($type === self::CAPTION_PACKET) {
                    $frames[] = [$start, Mcc::readCdp($data)[1]];
                }
            }
        }

        return CaptionDataCues::read($frames, $options);
    }


    /**
     * Yields the start and the end in seconds, the file offset and the size of each sample that ends after 0 s.
     * The edit list moves the samples, see readEditList().
     *
     * @return Generator<int, array{float, float, int, int}>
     */
    private function sampleTimes(int $trackNumber): Generator
    {
        ["timescale" => $timescale, "delay" => $delay] = $this->trackData[$trackNumber];
        foreach ($this->samples($trackNumber) as [$start, $duration, $offset, $size]) {
            $end = ($start + $duration) / $timescale + $delay;
            if ($end > 0) {
                yield [max(0.0, $start / $timescale + $delay), $end, $offset, $size];
            }
        }
    }


    /**
     * Returns the type and the data of each box in a sample. A box that does not fit ends the list.
     *
     * @return list<array{string, string}>
     */
    private static function sampleBoxes(string $sample): array
    {
        $boxes  = [];
        $offset = 0;
        while ($offset + self::BOX_HEADER_SIZE <= strlen($sample)) {
            ["size" => $size, "type" => $type] = unpack("Nsize/a4type", $sample, $offset);
            if ($size < self::BOX_HEADER_SIZE || $offset + $size > strlen($sample)) {
                break;
            }
            $boxes[] = [$type, substr($sample, $offset + self::BOX_HEADER_SIZE, $size - self::BOX_HEADER_SIZE)];
            $offset += $size;
        }

        return $boxes;
    }


    private function sampleData(int $trackNumber, int $offset, int $size): string
    {
        $this->checkSample($trackNumber, $offset, $size);
        if ($size === 0) {
            return "";
        }
        fseek($this->stream, $offset);

        return (string) fread($this->stream, $size);
    }


    private function checkSample(int $trackNumber, int $offset, int $size): void
    {
        if ($offset + $size > $this->fileSize) {
            throw new ParsingException("A sample of track $trackNumber at byte $offset lies outside the file.");
        }
    }


    private function subtitleTrack(int $trackNumber): SubtitleTrack
    {
        return $this->tracks[$trackNumber]
            ?? throw new InvalidArgumentException("The file has no subtitle track with the number $trackNumber.");
    }


    private function readMovie(): void
    {
        fseek($this->stream, 0);
        if (substr((string) fread($this->stream, 8), 4) !== self::FTYP) {
            throw new ParsingException("The file is not an MP4 file.");
        }

        foreach ($this->children(0, $this->fileSize) as $box) {
            if ($box["type"] === "moov") {
                $mvhd = $this->childMap($box)["mvhd"] ?? null;
                if ($mvhd !== null) {
                    $this->movieTimescale = unpack("N", $this->read($mvhd, self::MVHD_TIMESCALE_OFFSET[$this->boxVersion($mvhd)], 4))[1];
                }
                foreach ($this->children($box["offset"], $box["end"]) as $child) {
                    if ($child["type"] === "trak") {
                        $this->readTrack($child);
                    }
                }

                return;
            }
        }

        throw new ParsingException("The MP4 file has no moov box.");
    }


    /**
     * @param array{type: string, offset: int, end: int} $trak
     */
    private function readTrack(array $trak): void
    {
        $boxes = $this->childMap($trak);
        $mdia  = isset($boxes["mdia"]) ? $this->childMap($boxes["mdia"]) : [];
        if (!isset($boxes["tkhd"], $mdia["hdlr"], $mdia["mdhd"])
            || !in_array($this->read($mdia["hdlr"], self::HDLR_HANDLER_TYPE_OFFSET, 4), self::SUBTITLE_HANDLERS, true)) {
            return;
        }

        $minf = isset($mdia["minf"]) ? $this->childMap($mdia["minf"]) : [];
        $stbl = isset($minf["stbl"]) ? $this->childMap($minf["stbl"]) : [];
        ["id" => $id, "enabled" => $enabled]                = $this->readTrackHeader($boxes["tkhd"]);
        ["timescale" => $timescale, "language" => $language] = $this->readMediaHeader($mdia["mdhd"]);
        if ($timescale === 0) {
            throw new ParsingException("Track $id has a timescale of 0.");
        }

        $entry = isset($stbl["stsd"]) ? $this->sampleEntry($stbl["stsd"]) : ["codec" => "", "encrypted" => false, "forced" => false];
        $udta  = isset($boxes["udta"]) ? $this->childMap($boxes["udta"]) : [];
        $name  = isset($udta["name"]) ? $this->readString($udta["name"], 0) : "";
        $elng  = isset($mdia["elng"]) ? $this->readString($mdia["elng"], 4) : "";

        $this->tracks[$id]    = new SubtitleTrack(
            ContainerFormat::Mp4,
            $id,
            self::codecName($entry["codec"]),
            $entry["encrypted"] ? null : self::FORMATS[$entry["codec"]] ?? null,
            $elng === "" ? self::language($language) : $elng,
            $name === "" ? null : $name,
            $enabled,
            $entry["forced"],
        );
        $edts                 = isset($boxes["edts"]) ? $this->childMap($boxes["edts"]) : [];
        $this->trackData[$id] = [
            "timescale" => $timescale,
            "encrypted" => $entry["encrypted"],
            "tables"    => $stbl,
            "delay"     => isset($edts["elst"]) ? $this->readEditList($edts["elst"], $timescale) : 0.0,
        ];
    }


    /**
     * Returns the seconds by which the edit list moves the samples: the empty edits at the start, minus the media time
     * of the first edit after them. ffmpeg writes an empty edit for a c608 track that starts after 0 s.
     * Later edits, which cut or repeat parts of the media, do not change the times.
     *
     * @param array{type: string, offset: int, end: int} $elst
     */
    private function readEditList(array $elst, int $mediaTimescale): float
    {
        $version   = $this->boxVersion($elst);
        $entrySize = $version === 1 ? 20 : 12;
        $count     = unpack("N", $this->read($elst, 4, 4))[1];
        $count     = min($count, intdiv($elst["end"] - $elst["offset"] - 8, $entrySize));
        $empty     = 0;
        for ($index = 0; $index < $count; $index++) {
            $entry = $this->read($elst, 8 + $index * $entrySize, $entrySize);
            [$duration, $mediaTime] = $version === 1
                ? array_values(unpack("Jduration/Jtime", $entry))
                : [unpack("N", $entry)[1], unpack("N", $entry, 4)[1] << 32 >> 32];
            if ($mediaTime !== self::EMPTY_EDIT) {
                return ($this->movieTimescale === 0 ? 0.0 : $empty / $this->movieTimescale) - $mediaTime / $mediaTimescale;
            }
            $empty += $duration;
        }

        return 0.0;
    }


    /**
     * @param array{type: string, offset: int, end: int} $tkhd
     * @return array{id: int, enabled: bool}
     */
    private function readTrackHeader(array $tkhd): array
    {
        $version = $this->boxVersion($tkhd);
        $flags   = unpack("N", "\0" . $this->read($tkhd, 1, 3))[1];

        return [
            "id"      => unpack("N", $this->read($tkhd, self::TKHD_TRACK_ID_OFFSET[$version], 4))[1],
            "enabled" => ($flags & self::TRACK_ENABLED) !== 0,
        ];
    }


    /**
     * @param array{type: string, offset: int, end: int} $mdhd
     * @return array{timescale: int, language: int} language in the packed ISO 639-2/T form
     */
    private function readMediaHeader(array $mdhd): array
    {
        $version = $this->boxVersion($mdhd);

        return [
            "timescale" => unpack("N", $this->read($mdhd, self::MDHD_TIMESCALE_OFFSET[$version], 4))[1],
            "language"  => unpack("n", $this->read($mdhd, self::MDHD_LANGUAGE_OFFSET[$version], 2))[1],
        ];
    }


    /**
     * @param array{type: string, offset: int, end: int} $box
     * @return 0|1 version 1 for a box with 64-bit times, otherwise 0
     */
    private function boxVersion(array $box): int
    {
        return $this->read($box, 0, 1) === "\x01" ? 1 : 0;
    }


    /**
     * Reads the first sample entry of an stsd box: its type, and for tx3g the forced flag of its displayFlags.
     *
     * @param array{type: string, offset: int, end: int} $stsd
     * @return array{codec: string, encrypted: bool, forced: bool}
     */
    private function sampleEntry(array $stsd): array
    {
        $entry = $this->readBoxHeader($stsd["offset"] + 8, $stsd["end"]);
        if ($entry === null) {
            return ["codec" => "", "encrypted" => false, "forced" => false];
        }

        $codec     = $entry["type"];
        $encrypted = in_array($codec, self::ENCRYPTED_ENTRIES, true);
        $forced    = false;
        if ($codec === self::CODEC_TX3G) {
            $displayFlags = unpack("N", $this->read($entry, 8, 4))[1];
            $forced       = ($displayFlags & self::ALL_SAMPLES_FORCED) !== 0;
            // The sample entry of 3GPP timed text holds 38 bytes of fields before its boxes.
            foreach ($this->children($entry["offset"] + 38, $entry["end"]) as $child) {
                $encrypted = $encrypted || $child["type"] === "sinf";
            }
        }

        return ["codec" => $codec, "encrypted" => $encrypted, "forced" => $forced];
    }


    /**
     * Reads a string from $at to the end of the box or the first zero byte. Invalid UTF-8 gives "".
     *
     * @param array{type: string, offset: int, end: int} $box
     */
    private function readString(array $box, int $at): string
    {
        $string = explode("\0", $this->read($box, $at, max(0, $box["end"] - $box["offset"] - $at)))[0];

        return StringHelpers::isValidUtf8($string) ? $string : "";
    }


    private static function language(int $packed): string
    {
        if ($packed === 0 || $packed === 0x7FFF) {
            return self::UNDETERMINED;
        }

        return chr(($packed >> 10 & 0x1F) + 0x60) . chr(($packed >> 5 & 0x1F) + 0x60) . chr(($packed & 0x1F) + 0x60);
    }


    /**
     * Yields the start, the duration, the file offset and the size of each sample, in timescale units and bytes.
     *
     * @return Generator<int, array{int, int, int, int}>
     */
    private function samples(int $trackNumber): Generator
    {
        $tables = $this->trackData[$trackNumber]["tables"];
        [$count, $sizeAt] = $this->sampleSizes($trackNumber, $tables);
        $deltas  = $this->table($trackNumber, $tables, "stts", 8, "Ncount/Ndelta");
        $chunks  = $this->table($trackNumber, $tables, "stsc", 12, "Nfirst/Ncount/Nentry");
        $offsets = isset($tables["co64"])
            ? array_column($this->table($trackNumber, $tables, "co64", 8, "Joffset"), "offset")
            : array_column($this->table($trackNumber, $tables, "stco", 4, "Noffset"), "offset");

        $sample     = 0;
        $time       = 0;
        $delta      = 0;
        $left       = 0;
        $deltaIndex = 0;
        $chunkEntry = -1;
        foreach ($offsets as $chunkIndex => $offset) {
            while (isset($chunks[$chunkEntry + 1]) && $chunks[$chunkEntry + 1]["first"] <= $chunkIndex + 1) {
                $chunkEntry++;
            }
            $perChunk = $chunks[$chunkEntry]["count"] ?? 0;

            for ($inChunk = 0; $inChunk < $perChunk && $sample < $count; $inChunk++, $sample++) {
                while ($left === 0) {
                    $entry = $deltas[$deltaIndex++]
                        ?? throw new ParsingException("The stts box of track $trackNumber has fewer samples than the sample size box.");
                    [$left, $delta] = [$entry["count"], $entry["delta"]];
                }
                $size = $sizeAt($sample);
                yield [$time, $delta, $offset, $size];
                $offset += $size;
                $time   += $delta;
                $left--;
            }
        }
    }


    /**
     * Returns the sample count and a function that returns the size of a sample.
     *
     * @param array<string, array{type: string, offset: int, end: int}> $tables
     * @return array{int, callable(int): int}
     */
    private function sampleSizes(int $trackNumber, array $tables): array
    {
        if (isset($tables["stsz"])) {
            ["size" => $size, "count" => $count] = unpack("Nsize/Ncount", $this->read($tables["stsz"], 4, 8));
            if ($size !== 0) {
                if ($count * $size > $this->fileSize) {
                    throw new ParsingException("The stsz box of track $trackNumber holds $count samples of $size bytes, which do not fit into the file.");
                }

                return [$count, fn (): int => $size];
            }
            $sizes = array_column($this->table($trackNumber, $tables, "stsz", 4, "Nsize", 8), "size");

            return [count($sizes), fn (int $index): int => $sizes[$index]];
        }
        if (!isset($tables["stz2"])) {
            throw new ParsingException("Track $trackNumber has no stsz or stz2 box.");
        }

        ["bits" => $bits, "count" => $count] = unpack("x3/Cbits/Ncount", $this->read($tables["stz2"], 4, 8));
        $data = $this->read($tables["stz2"], 12, $tables["stz2"]["end"] - $tables["stz2"]["offset"] - 12);
        if (!in_array($bits, [4, 8, 16], true) || strlen($data) * 8 < $count * $bits) {
            throw new ParsingException("The stz2 box of track $trackNumber is not valid.");
        }

        return [$count, fn (int $index): int => match ($bits) {
            4       => ord($data[$index >> 1]) >> ($index & 1 ? 0 : 4) & 0x0F,
            8       => ord($data[$index]),
            default => unpack("n", $data, $index * 2)[1],
        }];
    }


    /**
     * Reads the entries of a sample table box. The entry count follows the version and flags, or $skip more bytes.
     *
     * @param array<string, array{type: string, offset: int, end: int}> $tables
     * @return list<array<string, int>>
     */
    private function table(int $trackNumber, array $tables, string $type, int $entrySize, string $format, int $skip = 4): array
    {
        $box = $tables[$type] ?? throw new ParsingException("Track $trackNumber has no $type box.");
        $count = unpack("N", $this->read($box, $skip, 4))[1];
        if ($skip + 4 + $count * $entrySize > $box["end"] - $box["offset"]) {
            throw new ParsingException("The $type box of track $trackNumber holds fewer entries than its count of $count.");
        }

        $data    = $this->read($box, $skip + 4, $count * $entrySize);
        $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $entries[] = unpack($format, $data, $index * $entrySize);
        }

        return $entries;
    }


    /**
     * Reads the text of a tx3g sample with the bold, italic and underline runs of its styl box as tags.
     * A UTF-16 BOM sets UTF-16.
     */
    private function sampleText(int $trackNumber, int $offset, int $size): string
    {
        $this->checkSample($trackNumber, $offset, $size);
        if ($size < self::TEXT_LENGTH_SIZE) {
            return "";
        }

        fseek($this->stream, $offset);
        $length = unpack("n", (string) fread($this->stream, self::TEXT_LENGTH_SIZE))[1];
        if ($length > $size - self::TEXT_LENGTH_SIZE) {
            throw new ParsingException("The text of the sample of track $trackNumber at byte $offset is longer than the sample.");
        }

        // 3GPP TS 26.245 allows only UTF-8 and UTF-16 with a BOM, so the text needs no code page detection.
        $text = $length === 0 ? "" : StringHelpers::removeUtf8Bom(StringHelpers::decode((string) fread($this->stream, $length), guess: false)->content);
        if (!StringHelpers::isValidUtf8($text)) {
            throw new ParsingException("The text of the sample of track $trackNumber at byte $offset is not valid UTF-8.");
        }

        $rest      = $size - self::TEXT_LENGTH_SIZE - $length;
        $modifiers = $rest < self::BOX_HEADER_SIZE ? "" : (string) fread($this->stream, min($rest, self::MAX_MODIFIERS_SIZE));

        // The character offsets of the styl box count the line endings as they are in the sample.
        return StringHelpers::normalizeEOLs(self::addStyleTags($text, self::readStyles($modifiers)));
    }


    /**
     * Returns the start character, the end character and the face style flags of each record of the styl box.
     * A damaged modifier box gives no styles, as the text alone is still correct.
     *
     * @return list<array{int, int, int}>
     */
    private static function readStyles(string $modifiers): array
    {
        $offset = 0;
        while ($offset + self::BOX_HEADER_SIZE <= strlen($modifiers)) {
            ["size" => $size, "type" => $type] = unpack("Nsize/a4type", $modifiers, $offset);
            if ($size < self::BOX_HEADER_SIZE) {
                return [];
            }
            if ($type !== "styl") {
                $offset += $size;
                continue;
            }

            $data    = substr($modifiers, $offset + self::BOX_HEADER_SIZE, $size - self::BOX_HEADER_SIZE);
            $count   = strlen($data) >= self::STYLE_COUNT_SIZE ? unpack("n", $data)[1] : 0;
            $count   = min($count, intdiv(strlen($data) - self::STYLE_COUNT_SIZE, self::STYLE_RECORD_SIZE));
            $records = [];
            for ($index = 0; $index < $count; $index++) {
                $records[] = array_values(unpack("nstart/nend/x2/Cflags", $data, self::STYLE_COUNT_SIZE + $index * self::STYLE_RECORD_SIZE));
            }

            return $records;
        }

        return [];
    }


    /**
     * Wraps each style run of $text in <b>, <i> and <u> tags. The start and end count characters, and the end is exclusive.
     *
     * @param list<array{int, int, int}> $styles
     */
    private static function addStyleTags(string $text, array $styles): string
    {
        $characters = preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY);
        $covered    = 0;
        usort($styles, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($styles as [$start, $end, $flags]) {
            $start = max($start, $covered);
            $end   = min($end, count($characters));
            $tags  = array_keys(array_filter(self::FACE_STYLE_TAGS, fn (int $flag): bool => ($flags & $flag) !== 0));
            if ($start >= $end || $tags === []) {
                continue;
            }

            $characters[$start]   = "<" . implode("><", $tags) . ">" . $characters[$start];
            $characters[$end - 1] .= "</" . implode("></", array_reverse($tags)) . ">";
            $covered               = $end;
        }

        return implode("", $characters);
    }


    /**
     * @param array{type: string, offset: int, end: int} $parent
     * @return array<string, array{type: string, offset: int, end: int}> the first child box of each type
     */
    private function childMap(array $parent): array
    {
        $map = [];
        foreach ($this->children($parent["offset"], $parent["end"]) as $child) {
            $map[$child["type"]] ??= $child;
        }

        return $map;
    }


    /**
     * @return Generator<int, array{type: string, offset: int, end: int}>
     */
    private function children(int $start, int $end): Generator
    {
        $position = $start;
        while ($position < $end && ($box = $this->readBoxHeader($position, $end)) !== null) {
            yield $box;
            $position = $box["end"];
        }
    }


    /**
     * Reads the box header at $position. Returns null when fewer than 8 bytes remain before $end.
     *
     * @return array{type: string, offset: int, end: int}|null offset is the start of the box data
     */
    private function readBoxHeader(int $position, int $end): ?array
    {
        if ($position + self::BOX_HEADER_SIZE > $end) {
            return null;
        }

        fseek($this->stream, $position);
        $header = (string) fread($this->stream, self::BOX_HEADER_SIZE);
        if (strlen($header) < self::BOX_HEADER_SIZE) {
            return null;
        }

        ["size" => $size, "type" => $type] = unpack("Nsize/a4type", $header);
        $offset = $position + self::BOX_HEADER_SIZE;
        if ($size === 1) {
            $largeSize = (string) fread($this->stream, self::LARGE_SIZE_LENGTH);
            $size      = strlen($largeSize) === self::LARGE_SIZE_LENGTH ? unpack("J", $largeSize)[1] : -1;
            $offset   += self::LARGE_SIZE_LENGTH;
        } elseif ($size === 0) {
            $size = $end - $position;
        }
        if ($size < $offset - $position || $size > $end - $position) {
            throw new ParsingException("The box " . self::typeName($type) . " at byte $position does not fit into its parent box.");
        }

        return ["type" => $type, "offset" => $offset, "end" => $position + $size];
    }


    /**
     * Returns a printable type as it is, and other bytes as hex.
     */
    private static function codecName(string $type): string
    {
        return preg_match('/^[\x20-\x7E]{4}$/', $type) === 1 ? $type : "0x" . bin2hex($type);
    }


    private static function typeName(string $type): string
    {
        $name = self::codecName($type);

        return $name === $type ? "\"$type\"" : $name;
    }


    /**
     * Reads $length bytes of the box data from $at on.
     *
     * @param array{type: string, offset: int, end: int} $box
     */
    private function read(array $box, int $at, int $length): string
    {
        if ($at + $length > $box["end"] - $box["offset"] || $length < 0) {
            throw new ParsingException("The box " . self::typeName($box["type"]) . " at byte {$box['offset']} is too short.");
        }
        if ($length === 0) {
            return "";
        }

        fseek($this->stream, $box["offset"] + $at);

        return (string) fread($this->stream, $length);
    }
}
