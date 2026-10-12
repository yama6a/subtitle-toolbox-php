<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

use Generator;
use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\ContainerReader;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Dependency;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\Streams;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;

/**
 * Reads the subtitle tracks of Matroska (.mkv, .mka, .mks) and WebM files. It skips video and audio data with fseek().
 *
 * Elements: https://www.matroska.org/technical/elements.html
 * Subtitle codecs: https://www.matroska.org/technical/subtitles.html
 * EBML: https://datatracker.ietf.org/doc/html/rfc8794
 */
final class MatroskaReader implements ContainerReader
{
    public const CODEC_SUBRIP = "S_TEXT/UTF8";
    public const CODEC_ASS    = "S_TEXT/ASS";
    public const CODEC_SSA    = "S_TEXT/SSA";
    public const CODEC_WEBVTT = "S_TEXT/WEBVTT";
    public const CODEC_PGS    = "S_HDMV/PGS";
    public const CODEC_VOBSUB = "S_VOBSUB";

    public const CODECS = [self::CODEC_SUBRIP, self::CODEC_ASS, self::CODEC_SSA, self::CODEC_WEBVTT, self::CODEC_PGS, self::CODEC_VOBSUB,
                           self::CODEC_WEBM_SUBTITLES, self::CODEC_WEBM_CAPTIONS, self::CODEC_WEBM_DESCRIPTIONS];

    /** The first 4 bytes of every Matroska and WebM file. */
    public const EBML_MAGIC = "\x1A\x45\xDF\xA3";

    private const DOC_TYPES = ["matroska", "webm"];

    // The default value of the Language element in the Matroska spec.
    private const DEFAULT_LANGUAGE = "eng";

    private const NANOSECONDS_PER_MILLISECOND = 1000000;

    private const MKVMERGE_WRITING_APP = "mkvmerge ";
    private const NUMBER_OF_FRAMES     = "NUMBER_OF_FRAMES";

    // The WebM codecs of WebVTT tracks. D_WEBVTT/METADATA holds no text to display.
    private const CODEC_WEBM_SUBTITLES    = "D_WEBVTT/SUBTITLES";
    private const CODEC_WEBM_CAPTIONS     = "D_WEBVTT/CAPTIONS";
    private const CODEC_WEBM_DESCRIPTIONS = "D_WEBVTT/DESCRIPTIONS";

    private const FORMATS = [
        self::CODEC_SUBRIP => Format::SubRip,
        self::CODEC_ASS    => Format::Ass,
        self::CODEC_SSA    => Format::Ass,
        self::CODEC_WEBVTT => Format::WebVtt,
        self::CODEC_WEBM_SUBTITLES    => Format::WebVtt,
        self::CODEC_WEBM_CAPTIONS     => Format::WebVtt,
        self::CODEC_WEBM_DESCRIPTIONS => Format::WebVtt,
        self::CODEC_PGS    => Format::Pgs,
        self::CODEC_VOBSUB => Format::VobSub,
    ];

    private const ID_EBML             = 0x1A45DFA3;
    private const ID_DOC_TYPE         = 0x4282;
    private const ID_SEGMENT          = 0x18538067;
    private const ID_SEEK_HEAD        = 0x114D9B74;
    private const ID_SEEK             = 0x4DBB;
    private const ID_SEEK_ID          = 0x53AB;
    private const ID_SEEK_POSITION    = 0x53AC;
    private const ID_INFO             = 0x1549A966;
    private const ID_TIMESTAMP_SCALE  = 0x2AD7B1;
    private const ID_WRITING_APP      = 0x5741;
    private const ID_TRACKS           = 0x1654AE6B;
    private const ID_TRACK_ENTRY      = 0xAE;
    private const ID_TRACK_NUMBER     = 0xD7;
    private const ID_TRACK_UID        = 0x73C5;
    private const ID_TRACK_TYPE       = 0x83;
    private const ID_CODEC_ID         = 0x86;
    private const ID_CODEC_PRIVATE    = 0x63A2;
    private const ID_LANGUAGE         = 0x22B59C;
    private const ID_LANGUAGE_BCP47   = 0x22B59D;
    private const ID_NAME             = 0x536E;
    private const ID_FLAG_DEFAULT     = 0x88;
    private const ID_FLAG_FORCED      = 0x55AA;
    private const ID_DEFAULT_DURATION = 0x23E383;
    private const ID_ENCODINGS        = 0x6D80;
    private const ID_ENCODING         = 0x6240;
    private const ID_ENCODING_ORDER   = 0x5031;
    private const ID_ENCODING_SCOPE   = 0x5032;
    private const ID_ENCODING_TYPE    = 0x5033;
    private const ID_COMPRESSION      = 0x5034;
    private const ID_COMP_ALGO        = 0x4254;
    private const ID_COMP_SETTINGS    = 0x4255;
    private const ID_CLUSTER          = 0x1F43B675;
    private const ID_CUES             = 0x1C53BB6B;
    private const ID_CUE_POINT        = 0xBB;
    private const ID_CUE_POSITIONS    = 0xB7;
    private const ID_CUE_TRACK        = 0xF7;
    private const ID_CUE_CLUSTER      = 0xF1;
    private const ID_CUE_RELATIVE     = 0xF0;
    private const ID_TAGS             = 0x1254C367;
    private const ID_TAG              = 0x7373;
    private const ID_TARGETS          = 0x63C0;
    private const ID_TAG_TRACK_UID    = 0x63C5;
    private const ID_SIMPLE_TAG       = 0x67C8;
    private const ID_TAG_NAME         = 0x45A3;
    private const ID_TAG_STRING       = 0x4487;
    private const ID_TIMESTAMP        = 0xE7;
    private const ID_SIMPLE_BLOCK     = 0xA3;
    private const ID_BLOCK_GROUP      = 0xA0;
    private const ID_BLOCK            = 0xA1;
    private const ID_BLOCK_DURATION   = 0x9B;
    private const ID_BLOCK_ADDITIONS  = 0x75A1;
    private const ID_BLOCK_MORE       = 0xA6;
    private const ID_BLOCK_ADD_ID     = 0xEE;
    private const ID_BLOCK_ADDITIONAL = 0xA5;

    private const TRACK_TYPE_SUBTITLE     = 0x11;
    private const DEFAULT_TIMESTAMP_SCALE = 1000000;
    private const SCOPE_FRAMES            = 1;
    private const SCOPE_CODEC_PRIVATE     = 2;
    private const ALGO_ZLIB               = 0;
    private const ALGO_HEADER_STRIPPING   = 3;

    // A block header holds the track number as a vint of up to 8 bytes.
    // A 16-bit relative timestamp and 1 flag byte follow it.
    private const MAX_BLOCK_HEADER  = 11;
    private const BLOCK_HEADER_TAIL = 3;
    private const LACING_MASK       = 0x06;

    // The relative timestamp is a signed 16-bit integer.
    private const INT16_SIGN  = 0x8000;
    private const INT16_RANGE = 0x10000;

    // A full-screen 4K PGS display set holds about 8 MB and a VobSub unit 64 KB. A block that inflates to more is a zip bomb.
    private const MAX_INFLATED_SIZE = 64 << 20;

    // The WebVTT BlockAdditional of a cue has the BlockAddID 1, the default value.
    private const WEBVTT_ADD_ID = 1;

    /** @var resource */
    private $stream;

    private bool $ownsStream;

    private EbmlReader $ebml;

    private int $segmentEnd;

    private int $firstCluster;

    private int $segmentOffset;

    private ?int $cuesOffset = null;

    private ?int $tagsOffset = null;

    private bool $writtenByMkvmerge = false;

    private int $timestampScale = self::DEFAULT_TIMESTAMP_SCALE;

    /** @var array<int, SubtitleTrack> */
    private array $tracks = [];

    /** @var array<int, array{uid: ?string, codecPrivate: string, defaultDuration: ?int, encodings: list<array{scope: int, type: int, algo: int, settings: string}>}> */
    private array $trackData = [];


    /**
     * @param resource $stream
     */
    private function __construct($stream, bool $ownsStream)
    {
        $this->stream     = $stream;
        $this->ownsStream = $ownsStream;
        $this->ebml       = new EbmlReader($stream);
        $this->readHeaders();
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
     * Returns the format of the parser that extract() uses for the track, or null for a codec that it does not read.
     *
     * @throws InvalidArgumentException for a number that is not a subtitle track, as extract() does.
     */
    public function trackFormat(int $trackNumber): ?Format
    {
        return $this->subtitleTrack($trackNumber)->format;
    }


    /**
     * Reads all blocks of the subtitle track with the TrackNumber $trackNumber.
     * ReadOptions::$lastCueDuration sets the end of a last text block without a duration.
     */
    public function extract(int $trackNumber, ?ReadOptions $options = null): Subtitle
    {
        $options ??= new ReadOptions();
        $track  = $this->subtitleTrack($trackNumber);
        $format = $track->format;
        if ($format === null) {
            throw new ParsingException("Track $trackNumber has the codec $track->codecId. The reader extracts only " .
                                       implode(", ", self::CODECS) . ".");
        }

        $trackData    = $this->trackData[$trackNumber];
        $codecPrivate = $this->decode($trackNumber, $trackData["codecPrivate"], self::SCOPE_CODEC_PRIVATE);
        $blocks       = $this->readBlocks($trackNumber);
        if ($format !== Format::Pgs) {
            $blocks = $this->timedBlocks($blocks, $trackData, $options->lastCueDuration);
        }

        $subtitle = match ($format) {
            Format::Pgs    => (new PgsParser())->parse(TrackFileBuilder::pgsStream($blocks, $this->timestampScale), $options),
            Format::WebVtt => (new WebVttParser())->parse(TrackFileBuilder::webVttFile($track, $codecPrivate, $blocks), $options),
            Format::SubRip => (new SubRipParser())->parse(TrackFileBuilder::subRipFile($blocks), $options),
            Format::Ass    => (new AssParser())->parse(TrackFileBuilder::assFile($track, $codecPrivate, $blocks), $options),
            Format::VobSub => (new VobSubParser())->parseBlocks($codecPrivate, array_map(
                fn (array $block): array => ["start" => $block["startMs"] / 1000, "end" => $block["endMs"] / 1000, "data" => $block["data"]],
                $blocks,
            ), $options),
        };

        $subtitle->setFormat($format);
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $track->language);
        if ($track->forced) {
            foreach ($subtitle->getCues() as $cue) {
                $cue->setForced(true);
            }
        }

        return $subtitle;
    }


    private function subtitleTrack(int $trackNumber): SubtitleTrack
    {
        return $this->tracks[$trackNumber]
            ?? throw new InvalidArgumentException("The file has no subtitle track with the number $trackNumber.");
    }


    private function readHeaders(): void
    {
        $header  = $this->ebml->readElementHeader();
        $docType = $header !== null && $header["id"] === self::ID_EBML ? $this->readDocType($header) : null;
        if (!in_array($docType, self::DOC_TYPES, true) || ($segment = $this->findSegment()) === null) {
            throw new ParsingException("The file is not a Matroska or WebM file.");
        }

        $this->segmentOffset = $segment["offset"];
        $this->segmentEnd    = $segment["size"] === EbmlReader::UNKNOWN_SIZE ? PHP_INT_MAX : $segment["offset"] + $segment["size"];
        $this->firstCluster  = $this->segmentEnd;

        $positions = [];
        $tracks    = null;
        $info      = null;
        $this->ebml->seek($segment["offset"]);
        while (($position = $this->ebml->tell()) < $this->segmentEnd && ($element = $this->ebml->readElementHeader()) !== null) {
            if ($element["id"] === self::ID_CLUSTER) {
                $this->firstCluster = $position;
                break;
            }

            match ($element["id"]) {
                self::ID_SEEK_HEAD => $positions += $this->readSeekHead($element),
                self::ID_INFO      => $info = $element,
                self::ID_TRACKS    => $tracks = $element,
                self::ID_CUES      => $this->cuesOffset = $position,
                self::ID_TAGS      => $this->tagsOffset = $position,
                default            => null,
            };
            $this->skip($element);
        }

        if (isset($positions[self::ID_CUES])) {
            $this->cuesOffset ??= $segment["offset"] + $positions[self::ID_CUES];
        }
        if (isset($positions[self::ID_TAGS])) {
            $this->tagsOffset ??= $segment["offset"] + $positions[self::ID_TAGS];
        }

        $info   ??= $this->elementAt($segment["offset"], $positions[self::ID_INFO] ?? null);
        $tracks ??= $this->elementAt($segment["offset"], $positions[self::ID_TRACKS] ?? null);
        if ($tracks === null) {
            throw new ParsingException("The file has no Tracks element.");
        }

        if ($info !== null) {
            $this->readInfo($info);
        }
        $this->readTracks($tracks);
    }


    private function readDocType(array $header): ?string
    {
        foreach ($this->children($header) as $child) {
            if ($child["id"] === self::ID_DOC_TYPE) {
                return $this->ebml->readString($child["size"]);
            }
        }

        return null;
    }


    private function findSegment(): ?array
    {
        while (($element = $this->ebml->readElementHeader()) !== null) {
            if ($element["id"] === self::ID_SEGMENT) {
                return $element;
            }
            $this->skip($element);
        }

        return null;
    }


    /**
     * @return array<int, int> element ID => position relative to the segment data
     */
    private function readSeekHead(array $seekHead): array
    {
        $positions = [];
        foreach ($this->children($seekHead) as $seek) {
            if ($seek["id"] !== self::ID_SEEK) {
                continue;
            }

            $id       = null;
            $position = null;
            foreach ($this->children($seek) as $child) {
                match ($child["id"]) {
                    self::ID_SEEK_ID       => $id = $this->ebml->readUnsigned($child),
                    self::ID_SEEK_POSITION => $position = $this->ebml->readUnsigned($child),
                    default                => null,
                };
            }
            if ($id !== null && $position !== null) {
                $positions[$id] ??= $position;
            }
        }

        return $positions;
    }


    private function elementAt(int $segmentOffset, ?int $position): ?array
    {
        if ($position === null) {
            return null;
        }

        $this->ebml->seek($segmentOffset + $position);

        return $this->ebml->readElementHeader();
    }


    private function readInfo(array $info): void
    {
        foreach ($this->children($info) as $child) {
            match ($child["id"]) {
                self::ID_TIMESTAMP_SCALE => $this->timestampScale = $this->ebml->readUnsigned($child),
                self::ID_WRITING_APP     => $this->writtenByMkvmerge = str_starts_with($this->ebml->readString($child["size"]), self::MKVMERGE_WRITING_APP),
                default                  => null,
            };
        }
    }


    private function readTracks(array $tracks): void
    {
        foreach ($this->children($tracks) as $entry) {
            if ($entry["id"] !== self::ID_TRACK_ENTRY) {
                continue;
            }

            $fields = ["default" => true, "forced" => false, "codecPrivate" => "", "defaultDuration" => null, "encodings" => []];
            foreach ($this->children($entry) as $child) {
                $size = $child["size"];
                match ($child["id"]) {
                    self::ID_TRACK_NUMBER     => $fields["number"] = $this->ebml->readUnsigned($child),
                    self::ID_TRACK_UID        => $fields["uid"] = $this->readUid($child),
                    self::ID_TRACK_TYPE       => $fields["type"] = $this->ebml->readUnsigned($child),
                    self::ID_CODEC_ID         => $fields["codecId"] = $this->ebml->readString($size),
                    self::ID_CODEC_PRIVATE    => $fields["codecPrivate"] = $this->ebml->readBytes($size),
                    self::ID_LANGUAGE         => $fields["language"] = $this->ebml->readString($size),
                    self::ID_LANGUAGE_BCP47   => $fields["bcp47"] = $this->ebml->readString($size),
                    self::ID_NAME             => $fields["name"] = $this->ebml->readString($size),
                    self::ID_FLAG_DEFAULT     => $fields["default"] = $this->ebml->readUnsigned($child) !== 0,
                    self::ID_FLAG_FORCED      => $fields["forced"] = $this->ebml->readUnsigned($child) !== 0,
                    self::ID_DEFAULT_DURATION => $fields["defaultDuration"] = $this->ebml->readUnsigned($child),
                    self::ID_ENCODINGS        => $fields["encodings"] = $this->readEncodings($child),
                    default                   => null,
                };
            }

            if (($fields["type"] ?? null) !== self::TRACK_TYPE_SUBTITLE || !isset($fields["number"])) {
                continue;
            }

            $number                   = $fields["number"];
            $codecId                  = $fields["codecId"] ?? "";
            $this->tracks[$number]    = new SubtitleTrack(
                ContainerFormat::Matroska,
                $number,
                $codecId,
                self::FORMATS[$codecId] ?? null,
                $fields["bcp47"] ?? $fields["language"] ?? self::DEFAULT_LANGUAGE,
                $fields["name"] ?? null,
                $fields["default"],
                $fields["forced"],
            );
            $this->trackData[$number] = [
                "uid"             => $fields["uid"] ?? null,
                "codecPrivate"    => $fields["codecPrivate"],
                "defaultDuration" => $fields["defaultDuration"],
                "encodings"       => $fields["encodings"],
            ];
        }
    }


    /**
     * @return list<array{scope: int, type: int, algo: int, settings: string}> in decoding order, the highest ContentEncodingOrder first
     */
    private function readEncodings(array $encodings): array
    {
        $list = [];
        foreach ($this->children($encodings) as $encoding) {
            if ($encoding["id"] !== self::ID_ENCODING) {
                continue;
            }

            $fields = ["order" => 0, "scope" => self::SCOPE_FRAMES, "type" => 0, "algo" => self::ALGO_ZLIB, "settings" => ""];
            foreach ($this->children($encoding) as $child) {
                match ($child["id"]) {
                    self::ID_ENCODING_ORDER => $fields["order"] = $this->ebml->readUnsigned($child),
                    self::ID_ENCODING_SCOPE => $fields["scope"] = $this->ebml->readUnsigned($child),
                    self::ID_ENCODING_TYPE  => $fields["type"] = $this->ebml->readUnsigned($child),
                    self::ID_COMPRESSION    => $fields = $this->readCompression($child) + $fields,
                    default                 => null,
                };
            }
            $list[] = $fields;
        }
        usort($list, fn (array $a, array $b): int => $b["order"] <=> $a["order"]);

        return array_map(fn (array $fields): array => array_diff_key($fields, ["order" => true]), $list);
    }


    /**
     * @return array{algo?: int, settings?: string}
     */
    private function readCompression(array $compression): array
    {
        $fields = [];
        foreach ($this->children($compression) as $child) {
            match ($child["id"]) {
                self::ID_COMP_ALGO     => $fields["algo"] = $this->ebml->readUnsigned($child),
                self::ID_COMP_SETTINGS => $fields["settings"] = $this->ebml->readBytes($child["size"]),
                default                => null,
            };
        }

        return $fields;
    }


    /**
     * @return list<array{start: int, duration: ?int, data: string, additional: ?string}> times in TimestampScale ticks
     */
    private function readBlocks(int $trackNumber): array
    {
        return $this->readListedBlocks($trackNumber) ?? $this->walkBlocks($trackNumber);
    }


    /**
     * Reads only the blocks that the Cues element lists for the track, in a file from mkvmerge.
     * mkvmerge lists every subtitle block by default, and its NUMBER_OF_FRAMES tag counts the blocks of the track.
     * Returns null, so that the caller walks all clusters, when the counts differ, the tag is missing or a listed block cannot be read.
     *
     * @return list<array{start: int, duration: ?int, data: string, additional: ?string}>|null
     */
    private function readListedBlocks(int $trackNumber): ?array
    {
        if (!$this->writtenByMkvmerge || $this->cuesOffset === null || $this->tagsOffset === null) {
            return null;
        }

        try {
            $positions = $this->cuePositions($trackNumber);
            if ($positions === [] || count($positions) !== $this->frameCount($this->trackData[$trackNumber]["uid"])) {
                return null;
            }

            $blocks   = [];
            $clusters = [];
            foreach ($positions as [$cluster, $relative]) {
                $clusters[$cluster] ??= $this->clusterStart($cluster);
                if ($clusters[$cluster] === null) {
                    return null;
                }
                $this->ebml->seek($clusters[$cluster]["dataOffset"] + $relative);
                $element = $this->ebml->readElementHeader();
                $block   = match ($element["id"] ?? null) {
                    self::ID_SIMPLE_BLOCK => $this->readBlock($element, $trackNumber),
                    self::ID_BLOCK_GROUP  => $this->readBlockGroup($element, $trackNumber),
                    default               => null,
                };
                if ($block === null) {
                    return null;
                }
                $blocks[] = $this->finishBlock($trackNumber, $element["offset"], $block, $clusters[$cluster]["time"]);
            }
        } catch (ParsingException) {
            return null;
        }

        return $blocks;
    }


    /**
     * Returns the position of each block that the Cues element lists for the track, in file order: the offset of the cluster
     * and the offset of the block in the cluster data.
     *
     * @return list<array{int, int}>
     */
    private function cuePositions(int $trackNumber): array
    {
        $this->ebml->seek($this->cuesOffset);
        $cues = $this->ebml->readElementHeader();
        if ($cues === null || $cues["id"] !== self::ID_CUES) {
            return [];
        }

        $positions = [];
        foreach ($this->children($cues) as $point) {
            foreach ($point["id"] === self::ID_CUE_POINT ? $this->children($point) : [] as $trackPositions) {
                $fields = [];
                foreach ($trackPositions["id"] === self::ID_CUE_POSITIONS ? $this->children($trackPositions) : [] as $child) {
                    if (in_array($child["id"], [self::ID_CUE_TRACK, self::ID_CUE_CLUSTER, self::ID_CUE_RELATIVE], true)) {
                        $fields[$child["id"]] = $this->ebml->readUnsigned($child);
                    }
                }
                if (($fields[self::ID_CUE_TRACK] ?? null) !== $trackNumber) {
                    continue;
                }
                if (!isset($fields[self::ID_CUE_CLUSTER], $fields[self::ID_CUE_RELATIVE])) {
                    return [];
                }
                $positions[$fields[self::ID_CUE_CLUSTER] . ":" . $fields[self::ID_CUE_RELATIVE]] = [
                    $this->segmentOffset + $fields[self::ID_CUE_CLUSTER],
                    $fields[self::ID_CUE_RELATIVE],
                ];
            }
        }
        usort($positions, fn (array $a, array $b): int => $a <=> $b);

        return $positions;
    }


    /**
     * Returns the NUMBER_OF_FRAMES statistics tag of the track with the TrackUID $uid, or null without one.
     */
    private function frameCount(?string $uid): ?int
    {
        $this->ebml->seek($this->tagsOffset);
        $tags = $this->ebml->readElementHeader();
        if ($uid === null || $tags === null || $tags["id"] !== self::ID_TAGS) {
            return null;
        }

        foreach ($this->children($tags) as $tag) {
            $uids  = [];
            $count = null;
            foreach ($tag["id"] === self::ID_TAG ? $this->children($tag) : [] as $child) {
                if ($child["id"] === self::ID_TARGETS) {
                    foreach ($this->children($child) as $target) {
                        if ($target["id"] === self::ID_TAG_TRACK_UID) {
                            $uids[] = $this->readUid($target);
                        }
                    }
                } elseif ($child["id"] === self::ID_SIMPLE_TAG) {
                    $count ??= $this->numberOfFrames($child);
                }
            }
            if ($count !== null && in_array($uid, $uids, true)) {
                return $count;
            }
        }

        return null;
    }


    /**
     * Reads a UID as bytes without leading zeros, because a UID may be 2^63 or more.
     */
    private function readUid(array $element): string
    {
        return ltrim($this->ebml->readBytes($element["size"]), "\0");
    }


    private function numberOfFrames(array $simpleTag): ?int
    {
        $name  = null;
        $value = null;
        foreach ($this->children($simpleTag) as $child) {
            match ($child["id"]) {
                self::ID_TAG_NAME   => $name = $this->ebml->readString($child["size"]),
                self::ID_TAG_STRING => $value = $this->ebml->readString($child["size"]),
                default             => null,
            };
        }

        return $name === self::NUMBER_OF_FRAMES && $value !== null && preg_match('/^\d+\z/', $value) === 1 ? (int) $value : null;
    }


    /**
     * Reads the start of the cluster data and the cluster Timestamp, which mkvmerge writes as the first child.
     *
     * @return array{dataOffset: int, time: int}|null null when no cluster with a Timestamp first starts at $offset
     */
    private function clusterStart(int $offset): ?array
    {
        $this->ebml->seek($offset);
        $cluster   = $this->ebml->readElementHeader();
        $timestamp = $cluster !== null && $cluster["id"] === self::ID_CLUSTER ? $this->ebml->readElementHeader() : null;
        if ($timestamp === null || $timestamp["id"] !== self::ID_TIMESTAMP) {
            return null;
        }

        return ["dataOffset" => $cluster["offset"], "time" => $this->ebml->readUnsigned($timestamp)];
    }


    /**
     * Walks all clusters. Clusters of unknown size, as live recordings write them, end at the next cluster.
     *
     * @return list<array{start: int, duration: ?int, data: string, additional: ?string}> times in TimestampScale ticks
     */
    private function walkBlocks(int $trackNumber): array
    {
        $blocks      = [];
        $clusterTime = 0;
        $this->ebml->seek($this->firstCluster);
        while ($this->ebml->tell() < $this->segmentEnd && ($element = $this->ebml->readElementHeader()) !== null) {
            $block = null;
            match ($element["id"]) {
                self::ID_CLUSTER      => $clusterTime = 0,
                self::ID_TIMESTAMP    => $clusterTime = $this->ebml->readUnsigned($element),
                self::ID_SIMPLE_BLOCK => $block = $this->readBlock($element, $trackNumber),
                self::ID_BLOCK_GROUP  => $block = $this->readBlockGroup($element, $trackNumber),
                default               => null,
            };
            if ($element["id"] !== self::ID_CLUSTER) {
                $this->skip($element);
            }

            if ($block !== null) {
                $blocks[] = $this->finishBlock($trackNumber, $element["offset"], $block, $clusterTime);
            }
        }

        return $blocks;
    }


    /**
     * Adds the cluster time to the block time, checks the time limit and decodes the block data.
     */
    private function finishBlock(int $trackNumber, int $offset, array $block, int $clusterTime): array
    {
        $block["start"] += $clusterTime;
        $this->checkTime($trackNumber, $offset, $block);
        $block["data"] = $this->decode($trackNumber, $block["data"], self::SCOPE_FRAMES);

        return $block;
    }


    /**
     * @return array{start: int, duration: ?int, data: string, additional: ?string}|null null for a block of another track
     */
    private function readBlockGroup(array $group, int $trackNumber): ?array
    {
        $block      = null;
        $duration   = null;
        $additional = null;
        foreach ($this->children($group) as $child) {
            if ($child["id"] === self::ID_BLOCK) {
                $block = $this->readBlock($child, $trackNumber);
                if ($block === null) {
                    return null;
                }
            } elseif ($child["id"] === self::ID_BLOCK_DURATION) {
                $duration = $this->ebml->readUnsigned($child);
            } elseif ($child["id"] === self::ID_BLOCK_ADDITIONS) {
                $additional = $this->readBlockAdditions($child);
            }
        }

        return $block === null ? null : ["duration" => $duration, "additional" => $additional] + $block;
    }


    private function readBlockAdditions(array $additions): ?string
    {
        foreach ($this->children($additions) as $more) {
            if ($more["id"] !== self::ID_BLOCK_MORE) {
                continue;
            }

            $id   = self::WEBVTT_ADD_ID;
            $data = null;
            foreach ($this->children($more) as $child) {
                match ($child["id"]) {
                    self::ID_BLOCK_ADD_ID     => $id = $this->ebml->readUnsigned($child),
                    self::ID_BLOCK_ADDITIONAL => $data = $this->ebml->readBytes($child["size"]),
                    default                   => null,
                };
            }
            if ($id === self::WEBVTT_ADD_ID && $data !== null) {
                return $data;
            }
        }

        return null;
    }


    /**
     * Reads the track number, the relative timestamp and the flags of a SimpleBlock or Block, and the data of the wanted track.
     *
     * @return array{start: int, duration: null, data: string, additional: null}|null
     */
    private function readBlock(array $block, int $trackNumber): ?array
    {
        $header = $this->ebml->readBytes(min($block["size"], self::MAX_BLOCK_HEADER));
        [$blockTrack, $trackVintLength] = EbmlReader::readVint($header, 0, false) ?? [null, 0];
        if ($blockTrack === null || strlen($header) < $trackVintLength + self::BLOCK_HEADER_TAIL) {
            $offset = $block["offset"];
            throw new ParsingException("The block header at byte $offset is not valid.");
        }
        if ($blockTrack !== $trackNumber) {
            return null;
        }

        ["time" => $time, "flags" => $flags] = unpack("ntime/Cflags", $header, $trackVintLength);
        if (($flags & self::LACING_MASK) !== 0) {
            throw new ParsingException("A block of track $trackNumber uses lacing, which subtitle tracks do not use.");
        }

        $this->ebml->seek($block["offset"] + $trackVintLength + self::BLOCK_HEADER_TAIL);

        return [
            "start"      => $time >= self::INT16_SIGN ? $time - self::INT16_RANGE : $time,
            "duration"   => null,
            "data"       => $this->ebml->readBytes($block["size"] - $trackVintLength - self::BLOCK_HEADER_TAIL),
            "additional" => null,
        ];
    }


    private function decode(int $trackNumber, string $data, int $scope): string
    {
        foreach ($this->trackData[$trackNumber]["encodings"] as $encoding) {
            if (($encoding["scope"] & $scope) === 0) {
                continue;
            }
            if ($encoding["type"] !== 0 || !in_array($encoding["algo"], [self::ALGO_ZLIB, self::ALGO_HEADER_STRIPPING], true)) {
                throw new ParsingException("Track $trackNumber uses encryption or a compression other than zlib and header stripping.");
            }

            if ($encoding["algo"] === self::ALGO_HEADER_STRIPPING) {
                $data = $encoding["settings"] . $data;
                continue;
            }

            // gzuncompress() warns before it returns false. The exception reports the failure.
            $inflated = Dependency::isAvailable("gzuncompress") ? @gzuncompress($data, self::MAX_INFLATED_SIZE) : false;
            if ($inflated === false || strlen($inflated) > self::MAX_INFLATED_SIZE) {
                throw new ParsingException("The zlib data of track $trackNumber is not valid or inflates to more than " .
                                           (self::MAX_INFLATED_SIZE >> 20) . " MB.");
            }
            $data = $inflated;
        }

        return $data;
    }


    /**
     * Converts the block times from ticks to milliseconds and sets the end of each block.
     * A block without BlockDuration and DefaultDuration ends at the start of the next block.
     * The last such block ends after $lastDuration seconds.
     *
     * @param list<array{start: int, duration: ?int, data: string, additional: ?string}> $blocks times in TimestampScale ticks
     * @return list<array{startMs: int, endMs: int, data: string, additional: ?string}>
     */
    private function timedBlocks(array $blocks, array $trackData, float $lastDuration): array
    {
        $cues = [];
        foreach ($blocks as $index => $block) {
            $startTime = $block["start"] * $this->timestampScale;
            $start     = $this->milliseconds($startTime);
            $end       = match (true) {
                $block["duration"] !== null            => $this->milliseconds($startTime + $block["duration"] * $this->timestampScale),
                $trackData["defaultDuration"] !== null => $this->milliseconds($startTime + $trackData["defaultDuration"]),
                isset($blocks[$index + 1])             => $this->milliseconds($blocks[$index + 1]["start"] * $this->timestampScale),
                default                                => $start + Timecode::totalMilliseconds($lastDuration),
            };

            $cues[] = ["startMs" => $start, "endMs" => $end, "data" => $block["data"], "additional" => $block["additional"]];
        }

        return $cues;
    }


    /**
     * Throws when the start or the end of $block reaches MAX_HOURS, so that later time arithmetic stays within int.
     *
     * @param array{start: int|float, duration: ?int} $block
     */
    private function checkTime(int $trackNumber, int $offset, array $block): void
    {
        $start = $block["start"] * $this->timestampScale;
        $end   = $start + ($block["duration"] !== null
            ? $block["duration"] * $this->timestampScale
            : $this->trackData[$trackNumber]["defaultDuration"] ?? 0);
        if (max(abs($start), abs($end)) >= Timecode::MAX_HOURS * 3600 * 1000000000) {
            throw new ParsingException("The block of track $trackNumber at byte $offset has the time {$block['start']} at a TimestampScale of " .
                                       "$this->timestampScale ns. Times must be below " . Timecode::MAX_HOURS . " hours.");
        }
    }


    private function milliseconds(int $nanoseconds): int
    {
        return intdiv($nanoseconds + intdiv(self::NANOSECONDS_PER_MILLISECOND, 2), self::NANOSECONDS_PER_MILLISECOND);
    }


    /**
     * Yields the child elements of a master element. Each iteration starts at the next child, whether or not the caller read the data.
     *
     * @return Generator<int, array{id: int, size: int, offset: int}>
     */
    private function children(array $parent): Generator
    {
        $this->requireSize($parent);
        $end      = $parent["offset"] + $parent["size"];
        $position = $parent["offset"];
        while ($position < $end) {
            $this->ebml->seek($position);
            $child = $this->ebml->readElementHeader();
            if ($child === null || $child["size"] === EbmlReader::UNKNOWN_SIZE || $child["offset"] + $child["size"] > $end) {
                throw new ParsingException("The element at byte $position does not fit into its parent element.");
            }

            yield $child;
            $position = $child["offset"] + $child["size"];
        }
    }


    private function skip(array $element): void
    {
        $this->requireSize($element);
        $this->ebml->seek($element["offset"] + $element["size"]);
    }


    private function requireSize(array $element): void
    {
        if ($element["size"] === EbmlReader::UNKNOWN_SIZE) {
            $id = sprintf("0x%X", $element["id"]);
            throw new ParsingException("The element $id at byte {$element['offset']} has an unknown size, which only Segment and Cluster may have.");
        }
    }
}
