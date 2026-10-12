<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\FileFailure;
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Matroska\MkvFixtureWriter;
use SubtitleToolbox\Container\Mp4\Mp4FixtureWriter;
use SubtitleToolbox\Container\Mp4\Mp4Reader;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\Dependency;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Encoding\Cea608;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\XmlOutput;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Hls\TimestampMap;
use SubtitleToolbox\Http\CurlHttpClient;
use SubtitleToolbox\Http\FakeHttpClient;
use SubtitleToolbox\Http\WithoutCurl;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PaletteReducer;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\OcrEngineName;
use SubtitleToolbox\Ocr\RecognizedText;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Ocr\TesseractOcrOptions;
use SubtitleToolbox\Parsers\AssemblyAiParser;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\AwsTranscribeParser;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\GoogleSpeechParser;
use SubtitleToolbox\Parsers\HtmlTranscriptParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Mpl2Parser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\OgmChaptersParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\Parsers\TmPlayerParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\ReplaceTextOptions;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\SubRipStreamWriter;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;
use SubtitleToolbox\SyncPoint;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\GoogleTranslateOptions;
use SubtitleToolbox\Translation\OpenAiCompatibleEngine;
use SubtitleToolbox\Translation\OpenAiCompatibleOptions;
use SubtitleToolbox\Translation\TranslationEngine;
use SubtitleToolbox\Translation\TranslationOptions;
use SubtitleToolbox\Translation\TranslationRunner;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../files/mkv/generator/MkvFixtureWriter.php";
require_once __DIR__ . "/../files/mp4/generator/Mp4FixtureWriter.php";
require_once __DIR__ . "/../Http/FakeHttpClient.php";
require_once __DIR__ . "/../Http/WithoutCurl.php";

class ThrowSitesTest extends TestCase
{
    private const SRC = __DIR__ . "/../../src/";

    private const CODES = [
        ParsingException::class             => 100,
        UnsupportedFormatException::class   => 102,
        ImageCueWithoutTextException::class => 103,
        InvalidArgumentException::class     => 104,
        CueNotFoundException::class         => 105,
        UnknownFormatException::class       => 106,
        OcrException::class                 => 107,
        UnwritableContentException::class   => 108,
        TranslationException::class         => 109,
    ];

    // 101 belonged to InvalidFormatterException in 3.x. Do not give it to a new class.
    private const RETIRED_CODES = [101];

    // The CLI catches its own exceptions, so they need no error code.
    private const CLI_CODES = [FileFailure::class => 0];

    private const IDX = "# VobSub index file, v7 (do not modify this line!)\nsize: 720x576\n" .
                        "palette: 000000, f0f0f0, cccccc, 999999, 3333fa, 1111bb, fa3333, bb1111, " .
                        "33fa33, 11bb11, fafa33, bbbb11, fa33fa, bb11bb, 33fafa, 11bbbb\n";

    private const IDX_WITH_TRACK = self::IDX . "id: en, index: 0\ntimestamp: 00:00:01:000, filepos: 000000000\n";

    private const FILES = __DIR__ . "/../files/";

    private const FAKE_TESSERACT = __DIR__ . "/../files/ocr/fake-tesseract/tesseract";

    private const TTML = '<tt xmlns="http://www.w3.org/ns/ttml"><body><div>%s</div></body></tt>';


    private static function pgsSegment(int $type, string $data): string
    {
        return "PG" . pack("NNCn", 0, 0, $type, strlen($data)) . $data;
    }


    private static function png(): string
    {
        return PngEncoder::encode(1, 1, [0xFFFFFFFF]);
    }


    private static function pngChunk(string $type, string $data): string
    {
        return pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
    }


    private static function pngWithIhdr(int $interlace): string
    {
        return "\x89PNG\r\n\x1a\n" . self::pngChunk("IHDR", pack("NNCCCCC", 1, 1, 8, 6, 0, 0, $interlace));
    }


    private static function vobSubPacket(string $unit): string
    {
        $body = "\x81\x00\x00\x20" . $unit;

        return "\x00\x00\x01\xBA\x44\x00\x04\x00\x04\x01\x01\x89\xC3\xFA\xFF\xFF\x00\x00\x01\xBD" . pack("n", strlen($body)) . $body;
    }


    private static function unreadableFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), "unreadable");
        chmod($path, 0);
        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }


    private static function closedWriter(): SubRipStreamWriter
    {
        $writer = new SubRipStreamWriter(fopen("php://memory", "wb"));
        $writer->close();

        return $writer;
    }


    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }


    /**
     * Opens an MP4 file with one tx3g track 1 that holds $sample, with the given track fields.
     */
    private static function mp4(array $track = [], ?string $sample = null): Mp4Reader
    {
        return Mp4Reader::open(self::stream(Mp4FixtureWriter::file([$track + [
            "id" => 1, "handler" => "sbtl", "entry" => Mp4FixtureWriter::tx3gEntry(), "perChunk" => 1,
            "samples" => [[1000, $sample ?? Mp4FixtureWriter::textSample("The shop is closed.")]],
        ]], true)));
    }


    /**
     * Opens an MKV file with one S_TEXT/UTF8 track 2, the given track fields and the given segment data after the Tracks element.
     */
    private static function mkv(string $clusters, array $track = [], string $cut = ""): MatroskaReader
    {
        $tracks = MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, MkvFixtureWriter::trackEntry($track + [
            "number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8",
        ]));
        $file   = MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, $tracks . $clusters);

        return MatroskaReader::open(self::stream($cut === "" ? $file : substr($file, 0, -strlen($cut))));
    }


    private static function closedPortUrl(): string
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return "http://$name/";
    }


    private static function fromArray(array $data): Subtitle
    {
        return Subtitle::fromArray($data + ["version" => Subtitle::ARRAY_VERSION, "cues" => []]);
    }


    private static function subtitle(): Subtitle
    {
        return TestSubtitles::fromCues([[1, 2, ["first line", "second line"]]]);
    }


    /**
     * Keys start with the file under src/ that holds the throw site, so that the coverage test can count them.
     *
     * @return array<string, array{Closure, class-string, class-string}>
     */
    public static function throwSites(): array
    {
        $invalid     = [\InvalidArgumentException::class, InvalidArgumentException::class];
        $parsing     = [ParsingException::class, ParsingException::class];
        $ocr         = [\RuntimeException::class, OcrException::class];
        $translation = [\RuntimeException::class, TranslationException::class];
        $unwritable  = [\RuntimeException::class, UnwritableContentException::class];
        $unsupported = [\RuntimeException::class, UnsupportedFormatException::class];
        $unknown     = [UnsupportedFormatException::class, UnknownFormatException::class];
        $imageCue   = (new CueImage("png", 0, 0, 1, 1, 1, 1))->toCue(new SubtitleCue(1, 2));

        $cue = ["start" => 1, "end" => 2, "lines" => ["text"]];

        return [
            "ArrayConversion.php: no version"               => [fn () => Subtitle::fromArray([]), ...$parsing],
            "ArrayConversion.php: unknown version"          => [fn () => Subtitle::fromArray(["version" => 9]), ...$parsing],
            "ArrayConversion.php: metadata no string"       => [fn () => self::fromArray(["metadata" => ["title" => 5]]), ...$parsing],
            "ArrayConversion.php: cues no list"             => [fn () => Subtitle::fromArray(["version" => Subtitle::ARRAY_VERSION]), ...$parsing],
            "ArrayConversion.php: comment no object"        => [fn () => self::fromArray(["comments" => ["note"]]), ...$parsing],
            "ArrayConversion.php: comment text"             => [fn () => self::fromArray(["comments" => [["beforeCueIndex" => 0]]]), ...$parsing],
            "ArrayConversion.php: comment index"            => [fn () => self::fromArray(["comments" => [["text" => "note"]]]), ...$parsing],
            "ArrayConversion.php: cue no object"            => [fn () => self::fromArray(["cues" => [5]]), ...$parsing],
            "ArrayConversion.php: cue start"                => [fn () => self::fromArray(["cues" => [["start" => "1"] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue lines"                => [fn () => self::fromArray(["cues" => [["lines" => "text"] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue line"                 => [fn () => self::fromArray(["cues" => [["lines" => [5]] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue identifier"           => [fn () => self::fromArray(["cues" => [["identifier" => 5] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue alignment"            => [fn () => self::fromArray(["cues" => [["alignment" => 10] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue forced"               => [fn () => self::fromArray(["cues" => [["forced" => 1] + $cue]]), ...$parsing],
            "ArrayConversion.php: format data no object"    => [fn () => self::fromArray(["formatData" => ["srt" => 5]]), ...$parsing],
            "ArrayConversion.php: format data field type"   => [fn () => self::fromArray(["formatData" => ["scc" => ["dropFrame" => "x"]]]), ...$parsing],
            "ArrayConversion.php: map no object"            => [fn () => self::fromArray(["metadata" => 5]), ...$parsing],
            "ArrayConversion.php: comments no list"         => [fn () => self::fromArray(["comments" => 5]), ...$parsing],
            "Cli/Command.php: unknown option"               => [fn () => Arguments::parse(["--nope"], []), ...$invalid],
            "Cli/Command.php: file failure"                 => [fn () => Command::failFile("Cannot read x."), \RuntimeException::class, FileFailure::class],
            "Container/Matroska/EbmlReader.php: invalid element header" => [fn () => MatroskaReader::open(self::stream("\0\0\0\0")), ...$parsing],
            "Container/Matroska/EbmlReader.php: cut off element data" => [fn () => self::mkv("", ["codecPrivate" => "abc"], "c"), ...$parsing],
            "Container/Matroska/EbmlReader.php: unsigned integer of 2^63" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::blockGroup(2, 0, "x", PHP_INT_MIN)]))->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: stream not seekable" => [fn () => MatroskaReader::open(fopen("php://output", "wb")), ...$invalid],
            "Container/Matroska/MatroskaReader.php: unknown track" => [fn () => self::mkv("")->extract(9), ...$invalid],
            "Container/Matroska/MatroskaReader.php: unsupported codec" => [fn () => self::mkv("", ["codecId" => "S_DVBSUB"])->extract(2), ...$parsing],
            "Container/Mp4/Mp4Reader.php: stream not seekable" => [fn () => Mp4Reader::open(fopen("php://output", "wb")), ...$invalid],
            "Container/Mp4/Mp4Reader.php: encrypted track" => [fn () => Mp4Reader::open(self::FILES . "mp4/text_tracks.mp4")->extract(5), ...$parsing],
            "Container/Mp4/Mp4Reader.php: unsupported codec" => [fn () => Mp4Reader::open(self::FILES . "mp4/text_tracks.mp4")->extract(4), ...$parsing],
            "Container/Mp4/Mp4Reader.php: unknown track" => [fn () => self::mp4()->extract(9), ...$invalid],
            "Container/Mp4/Mp4Reader.php: not MP4" => [fn () => Mp4Reader::open(self::stream(str_repeat("\0", 16))), ...$parsing],
            "Container/Mp4/Mp4Reader.php: no moov" => [fn () => Mp4Reader::open(self::stream(Mp4FixtureWriter::ftyp())), ...$parsing],
            "Container/Mp4/Mp4Reader.php: timescale 0" => [fn () => self::mp4(["timescale" => 0]), ...$parsing],
            "Container/Mp4/Mp4Reader.php: stts too short" => [fn () => self::mp4(["boxes" => [
                                                                "stts" => Mp4FixtureWriter::fullBox("stts", 0, 0, pack("N", 0))]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: no sample sizes" => [fn () => self::mp4(["boxes" => ["stsz" => ""]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: invalid stz2" => [fn () => self::mp4(["boxes" => [
                                                                "stsz" => Mp4FixtureWriter::fullBox("stz2", 0, 0, "\0\0\0\x03" . pack("N", 1) . "\0")]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: sample count too large" => [fn () => self::mp4(["boxes" => [
                                                                "stsz" => Mp4FixtureWriter::fullBox("stsz", 0, 0, pack("NN", 1, 1 << 20))]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: missing table" => [fn () => self::mp4(["boxes" => ["stco" => ""]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: table count" => [fn () => self::mp4(["boxes" => [
                                                                "stco" => Mp4FixtureWriter::fullBox("stco", 0, 0, pack("N", 5))]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: sample outside the file" => [fn () => self::mp4(["boxes" => [
                                                                "stco" => Mp4FixtureWriter::fullBox("stco", 0, 0, pack("NN", 1, 1 << 30))]])->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: text longer than sample" => [fn () => self::mp4([], pack("n", 50) . "abc")->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: invalid UTF-8" => [fn () => self::mp4([], Mp4FixtureWriter::textSample("\xC3\x28"))->extract(1), ...$parsing],
            "Container/Mp4/Mp4Reader.php: box too large" => [fn () => Mp4Reader::open(self::stream(Mp4FixtureWriter::ftyp() . pack("N", 4096) . "moov")),
                                                                ...$parsing],
            "Container/Mp4/Mp4Reader.php: box too short" => [fn () => Mp4Reader::open(self::stream(Mp4FixtureWriter::ftyp() . Mp4FixtureWriter::box("moov",
                                                                Mp4FixtureWriter::box("trak", Mp4FixtureWriter::fullBox("tkhd", 0, 0, "") . Mp4FixtureWriter::box("mdia",
                                                                Mp4FixtureWriter::box("mdhd", "") . Mp4FixtureWriter::box("hdlr", "ab")))))), ...$parsing],
            "Container/Matroska/MatroskaReader.php: not Matroska" => [fn () => MatroskaReader::open(self::stream(MkvFixtureWriter::ebmlHeader("avi"))),
                                                                ...$parsing],
            "Container/Matroska/MatroskaReader.php: no Tracks" => [fn () => MatroskaReader::open(self::stream(MkvFixtureWriter::ebmlHeader() .
                                                                MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::info()))), ...$parsing],
            "Container/Matroska/MatroskaReader.php: invalid block header" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::element(0xA3, "\0\0\0\0")]))->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: time of 100000 hours" => [fn () => self::mkv(MkvFixtureWriter::cluster(1 << 40, [
                                                                MkvFixtureWriter::simpleBlock(2, 0, "x")]))->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: laced subtitle block" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::simpleBlock(2, 0, "\0x", MkvFixtureWriter::LACING_XIPH)]))->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: bzlib compression" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::blockGroup(2, 0, "BZh9", 1000)]),
                                                                ["encodings" => MkvFixtureWriter::compression(0, 1)])->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: invalid zlib data" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::blockGroup(2, 0, "not zlib", 1000)]),
                                                                ["encodings" => MkvFixtureWriter::compression(0, 0)])->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: child larger than its parent" => [fn () => MatroskaReader::open(self::stream(
                                                                "\x1A\x45\xDF\xA3\x84\x42\x82\x88matroska")), ...$parsing],
            "Container/Matroska/MatroskaReader.php: unknown size of Tracks" => [fn () => MatroskaReader::open(self::stream(
                                                                MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT,
                                                                MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::TRACKS, "")))), ...$parsing],
            "CueLimits.php: maximum lines 0"                => [fn () => new CueLimits(maxLinesPerCue: 0), ...$invalid],
            "CueLimits.php: maximum duration 0"             => [fn () => new CueLimits(maxDuration: 0), ...$invalid],
            "CueLimits.php: minimum above maximum duration" => [fn () => new CueLimits(minDuration: 8, maxDuration: 5), ...$invalid],
            "CueEditing.php: slice start after end"         => [fn () => self::subtitle()->withSlice(5, 1), ...$invalid],
            "CueEditing.php: split time outside the cue"    => [fn () => self::subtitle()->splitCue(0, 9, 1), ...$invalid],
            "CueEditing.php: split line out of range"       => [fn () => self::subtitle()->splitCue(0, 1.5, 5), ...$invalid],
            "CueEditing.php: join in the wrong order"       => [fn () => self::subtitle()->joinCues(0, 0), ...$invalid],
            "CueEditing.php: split a missing cue"           => [fn () => self::subtitle()->splitCue(9, 1.5, 1),
                                                                \RuntimeException::class, CueNotFoundException::class],
            "CueLookup.php: range start after end"          => [fn () => self::subtitle()->findCuesBetween(10, 5), ...$invalid],
            "Dependency.php: missing"                       => [fn () => Dependency::check("gzuncompress_missing", "Cannot decode a PNG - PHP has no ext-zlib."),
                                                                ...$invalid],
            "Dual/DualSubtitleOptions.php: unknown style"        => [fn () => new DualSubtitleOptions(secondaryStyle: "blink"), ...$invalid],
            "Encoding/Cea608.php: row 16"                   => [fn () => Cea608::encodePac(16, 0), ...$invalid],
            "Fixes.php: maximum characters 0"               => [fn () => self::subtitle()->wrapLines(0), ...$invalid],
            "Fixing/OcrReplaceList.php: invalid regex"      => [fn () => new OcrReplaceList(regularExpressions: ["/(/" => ""]), ...$invalid],
            "Fixing/OcrReplaceList.php: invalid XML"        => [fn () => OcrReplaceList::fromSubtitleEditXml("<ReplaceList>"), ...$parsing],
            "FormatDataSchema.php: field type"              => [fn () => (new Subtitle())->setFormatData("scc", ["dropFrame" => "x"]), ...$invalid],
            "FormatOptionsList.php: not a format class"     => [fn () => new ReadOptions(formats: [new CsvWriteOptions()]), ...$invalid],
            "FormatOptionsList.php: 2 objects of one class" => [fn () => new WriteOptions(formats: [new CsvWriteOptions(), new CsvWriteOptions()]), ...$invalid],
            "Formatters/JsonOutput.php: invalid UTF-8" => [fn () => Subtitle::load(self::FILES . "cli/latin1.srt", Format::SubRip, new ReadOptions(encoding: "UTF-8"))->toString(Format::Json),
                ...$unwritable],
            "Formatters/AssStyleOverride.php: no equals sign" => [fn () => new AssWriteOptions(style: "Fontsize"), ...$invalid],
            "Formatters/AssStyleOverride.php: unknown field" => [fn () => new AssWriteOptions(style: "Bogus=1"), ...$invalid],
            "Formatters/AssStyleOverride.php: field not in the SSA styles" => [fn () => Subtitle::fromString(
                "[V4 Styles]\nFormat: Name, Fontname\n\n[Events]\nDialogue: Marked=0,0:00:01.00,0:00:02.00,Default,,0,0,0,,Hi\n",
                Format::Ass
            )->toString(Format::Ass, new WriteOptions(formats: [new AssWriteOptions(style: "Underline=1")])), ...$unwritable],
            "Formatters/CsvFormatter.php: frames without rate" => [fn () => self::subtitle()->toString(Format::Csv,
                new WriteOptions(formats: [new CsvWriteOptions(timeFormat: CsvTimeFormat::Frames)])), ...$invalid],
            "Formatters/EbuStlFormatter.php: code table 09" => [fn () => self::subtitle()->setFormatData(EbuStlParser::FORMAT_DATA_KEY,
                ["gsi" => ["CCT" => "09"]])->toString(Format::EbuStl), ...$invalid],
            "Formatters/EbuStlFormatter.php: subtitle number 65536" => [fn () => self::subtitle()->setFormatData(EbuStlParser::FORMAT_DATA_KEY,
                ["firstSubtitleNumber" => 65536])->toString(Format::EbuStl), ...$unwritable],
            "Formatters/EbuStlFormatter.php: text too long" => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, str_repeat("a", 30000)))
                ->toString(Format::EbuStl), ...$unwritable],
            "Formatters/IttFormatter.php: no frame rate"    => [fn () => (new IttFormatter())->format(self::subtitle(), new WriteOptions()), ...$invalid],
            "Formatters/MicroDvdFormatter.php: no frame rate" => [fn () => (new MicroDvdFormatter())->format(self::subtitle(), new WriteOptions()),
                                                                ...$invalid],
            "Formatters/Options/EbuStlWriteOptions.php: frame rate 24" => [fn () => new EbuStlWriteOptions(frameRate: 24), ...$invalid],
            "Formatters/Options/IttWriteOptions.php: frame rate 50" => [fn () => new IttWriteOptions(frameRate: 50), ...$invalid],
            "Formatters/Options/MpSubWriteOptions.php: frame rate 23.976" => [fn () => new MpSubWriteOptions(frameRate: 23.976), ...$invalid],
            "Formatters/GoogleSpeechFormatter.php: diarized cue without words" => [function (): string {
                $subtitle = Subtitle::load(self::FILES . "google-speech/real/restaurant_v2_diarization.json", Format::GoogleSpeech);
                $subtitle->getCues()[0]->setLines("Hello");

                return $subtitle->toString(Format::GoogleSpeech);
            }, ...$unwritable],
            "Formatters/PgsFormatter.php: text cue"         => [fn () => self::subtitle()->toString(Format::Pgs), ...$unwritable],
            "Formatters/PgsFormatter.php: negative x"       => [fn () => (new Subtitle())->addCue((new CueImage(self::png(), -1, 0, 1, 1, 9, 9))
                ->toCue(new SubtitleCue(1, 2)))->toString(Format::Pgs), ...$unwritable],
            "Formatters/PgsFormatter.php: PNG size"         => [fn () => (new Subtitle())->addCue((new CueImage(self::png(), 0, 0, 2, 1, 9, 9))
                ->toCue(new SubtitleCue(1, 2)))->toString(Format::Pgs), ...$unwritable],
            "Formatters/PgsFormatter.php: negative time"    => [fn () => (new Subtitle())->addCue((new CueImage(self::png(), 0, 0, 1, 1, 9, 9))
                ->toCue(new SubtitleCue(-1, 2)))->toString(Format::Pgs), ...$unwritable],
            "Formatters/SccFormatter.php: 5 lines"          => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, ["1", "2", "3", "4", "5"]))
                ->toString(Format::Scc), ...$unwritable],
            "Formatters/SccFormatter.php: 33 characters"    => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, str_repeat("a", 33)))
                ->toString(Format::Scc), ...$unwritable],
            "Formatters/SccFormatter.php: no CEA-608 character" => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, "\u{20AC}"))
                ->toString(Format::Scc), ...$unwritable],
            "Formatters/XmlOutput.php: invalid UTF-8"       => [fn () => Subtitle::load(self::FILES . "cli/latin1.srt", Format::SubRip, new ReadOptions(encoding: "UTF-8"))->toString(Format::Ttml),
                                                                ...$unwritable],
            "Formatters/XmlOutput.php: not well-formed"     => [fn () => XmlOutput::load("<p>"), ...$unwritable],
            "Formatters/TtmlHead.php: stored head"          => [fn () => self::subtitle()->setFormatData(TtmlParser::FORMAT_DATA_KEY, ["head" => "<p/>"])
                ->toString(Format::Ttml), ...$unwritable],
            "HearingImpaired/HearingImpairedOptions.php: empty bracket"     => [fn () => new HearingImpairedOptions(customBrackets: [["{", ""]]), ...$invalid],
            "Hls/HlsSegmentOptions.php: segment duration 0" => [fn () => new HlsSegmentOptions(segmentDuration: 0), ...$invalid],
            "Hls/HlsSegmentOptions.php: no %d in pattern"   => [fn () => new HlsSegmentOptions(fileNamePattern: "sub.vtt"), ...$invalid],
            "Hls/HlsSegmentOptions.php: media duration 0"   => [fn () => new HlsSegmentOptions(mediaDuration: 0), ...$invalid],
            "Hls/HlsWebVttSegmenter.php: no duration"       => [fn () => HlsWebVttSegmenter::segment(new Subtitle()), ...$invalid],
            "Hls/TimestampMap.php: MPEGTS above 33 bits"    => [fn () => new TimestampMap(TimestampMap::MPEGTS_WRAP), ...$invalid],
            "Hls/TimestampMap.php: negative LOCAL"          => [fn () => new TimestampMap(0, -1), ...$invalid],
            "Hls/TimestampMap.php: other header"            => [fn () => TimestampMap::fromHeader("WEBVTT"), ...$parsing],
            "Hls/TimestampMap.php: no MPEGTS"               => [fn () => TimestampMap::fromHeader("X-TIMESTAMP-MAP=LOCAL:00:00.000"), ...$parsing],
            "Image/CueImage.php: width 0"                   => [fn () => new CueImage("png", 0, 0, 0, 1, 1, 1), ...$invalid],
            "Image/CueImage.php: too large"                 => [fn () => new CueImage("png", 0, 0, 8000, 1, 1, 1), ...$invalid],
            "Image/CueImage.php: no image"                  => [fn () => CueImage::fromCue(new SubtitleCue(1, 2, "text")), ...$invalid],
            "Image/CueImage.php: no integer x"              => [fn () => CueImage::fromCue((new SubtitleCue(1, 2))
                ->setFormatData(CueImage::FORMAT_DATA_KEY, ["png" => "png"])), ...$invalid],
            "Image/CueImage.php: no png"                    => [fn () => CueImage::fromCue((new SubtitleCue(1, 2))
                ->setFormatData(CueImage::FORMAT_DATA_KEY, ["x" => 0, "y" => 0, "width" => 1, "height" => 1,
                                                            "screenWidth" => 1, "screenHeight" => 1])), ...$invalid],
            "Image/PngEncoder.php: width 0"                 => [fn () => PngEncoder::encode(0, 1, []), ...$invalid],
            "Image/PngEncoder.php: pixel count"             => [fn () => PngEncoder::encode(1, 1, []), ...$invalid],
            "Image/PaletteReducer.php: 257 colors"          => [fn () => PaletteReducer::reduce([0], 257), ...$invalid],
            "Image/PngDecoder.php: no signature"            => [fn () => PngDecoder::decode("GIF89a"), ...$invalid],
            "Image/PngDecoder.php: cut off chunk"           => [fn () => PngDecoder::decode(substr(self::png(), 0, 20)), ...$invalid],
            "Image/PngDecoder.php: no IHDR"                 => [fn () => PngDecoder::decode("\x89PNG\r\n\x1a\n"), ...$invalid],
            "Image/PngDecoder.php: interlaced"              => [fn () => PngDecoder::decode(self::pngWithIhdr(1) . self::pngChunk("IDAT", "")), ...$invalid],
            "Image/PngDecoder.php: invalid zlib data"       => [fn () => PngDecoder::decode(self::pngWithIhdr(0) . self::pngChunk("IDAT", "nope")), ...$invalid],
            "Image/PngDecoder.php: too few rows"            => [fn () => PngDecoder::decode(self::pngWithIhdr(0) . self::pngChunk("IDAT", gzcompress(""))),
                                                                ...$invalid],
            "Image/PngDecoder.php: filter type 5"           => [fn () => PngDecoder::decode(self::pngWithIhdr(0) . self::pngChunk("IDAT", gzcompress("\5\0\0\0\0"))),
                                                                ...$invalid],
            "Karaoke/WordHighlightOptions.php: speaker style" => [fn () => new WordHighlightOptions(style: "v Ann"), ...$invalid],
            "Karaoke/WordHighlightOptions.php: 0 words"       => [fn () => new WordHighlightOptions(maxWordsPerCue: 0), ...$invalid],
            "MergeShortCuesOptions.php: minimum characters 0" => [fn () => new MergeShortCuesOptions(minCharacters: 0), ...$invalid],
            "Ocr/GlyphOcrOptions.php: ink threshold 0"      => [fn () => new GlyphOcrOptions(inkThreshold: 0), ...$invalid],
            "Ocr/GlyphOcrOptions.php: space width 0"        => [fn () => new GlyphOcrOptions(spaceWidth: 0), ...$invalid],
            "Ocr/GlyphOcrOptions.php: wrong pixels -1"      => [fn () => new GlyphOcrOptions(maxWrongPixels: -1), ...$invalid],
            "Ocr/GlyphOcrOptions.php: line height 0"        => [fn () => new GlyphOcrOptions(minLineHeight: 0), ...$invalid],
            "Ocr/GlyphOcrEngine.php: no PNG"                => [fn () => (new GlyphOcrEngine())
                ->recognize(new CueImage("png", 0, 0, 1, 1, 1, 1), null), ...$ocr],
            "Ocr/OcrEngineChooser.php: engine missing"      => [fn () => OcrEngineChooser::choose(OcrEngineName::Tesseract, __DIR__ . "/none"), ...$invalid],
            "Ocr/RecognizedText.php: line is no string"          => [fn () => new RecognizedText([5]), ...$invalid],
            "Ocr/RecognizedText.php: confidence above 1"         => [fn () => new RecognizedText(["text"], 2), ...$invalid],
            "Ocr/TesseractOcrOptions.php: mode 14"          => [fn () => new TesseractOcrOptions(pageSegmentationMode: 14), ...$invalid],
            "Ocr/TesseractOcrOptions.php: threshold 0"      => [fn () => new TesseractOcrOptions(threshold: 0), ...$invalid],
            "Ocr/TesseractOcrEngine.php: program missing"   => [fn () => (new TesseractOcrEngine(new TesseractOcrOptions(program: __DIR__ . "/none")))
                ->recognize(new CueImage(self::png(), 0, 0, 1, 1, 1, 1), null), ...$invalid],
            "Ocr/TesseractOcrEngine.php: language missing"  => [fn () => (new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE_TESSERACT)))
                ->recognize(new CueImage(self::png(), 0, 0, 1, 1, 1, 1), "xyz"), ...$invalid],
            "Ocr/TesseractOcrEngine.php: program fails"     => [function (): void {
                putenv("FAKE_TESSERACT_FAIL=1");
                try {
                    (new TesseractOcrEngine(new TesseractOcrOptions(program: self::FAKE_TESSERACT)))->recognize(new CueImage(self::png(), 0, 0, 1, 1, 1, 1), null);
                } finally {
                    putenv("FAKE_TESSERACT_FAIL");
                }
            }, ...$ocr],
            "OptionChecks.php: negative number"             => [fn () => new SubtitleDiffOptions(-1), ...$invalid],
            "OptionChecks.php: blank text"                  => [fn () => new VobSubReadOptions(language: " "), ...$invalid],
            "Parsers/AssParser.php: no events section"      => [fn () => (new AssParser())->parse("[V4+ Styles]\nFormat: Name\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: too few fields"         => [fn () => (new AssParser())->parse("[Events]\nFormat: Layer, Start, End, Text\n" .
                                                                                                  "Dialogue: 0,0:00:01.00\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: no Start field"         => [fn () => (new AssParser())->parse("[Events]\nFormat: Layer, Text\n" .
                                                                                                  "Dialogue: 0,text\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: invalid time"           => [fn () => (new AssParser())->parse("[Events]\nFormat: Start, End, Text\n" .
                                                                                                  "Dialogue: soon,0:00:02.00,text\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: number as field name"   => [fn () => (new AssParser())->parse("[Events]\nFormat: 1, Start, End, Text\n", new ReadOptions()), ...$parsing],
            "Parsers/AssemblyAiParser.php: no words"        => [fn () => (new AssemblyAiParser())->parse('{"text": "Hi"}', new ReadOptions()), ...$parsing],
            "Parsers/AwsTranscribeParser.php: no items"     => [fn () => (new AwsTranscribeParser())->parse('{"results": {}}', new ReadOptions()), ...$parsing],
            "Parsers/Options/CsvColumns.php: negative index"        => [fn () => new CsvColumns(start: -1), ...$invalid],
            "Parsers/Options/CsvColumns.php: name without header"   => [fn () => new CsvColumns(start: 0, text: "Text", header: false), ...$invalid],
            "Parsers/Options/CsvReadOptions.php: delimiter" => [fn () => new CsvReadOptions(delimiter: "|"), ...$invalid],
            "Parsers/CsvTokenizer.php: open quote"          => [fn () => (new CsvParser())->parse("start,text\n1,\"a", new ReadOptions()), ...$parsing],
            "Parsers/CsvParser.php: bad time"               => [fn () => (new CsvParser())->parse("start,text\nsoon,a", new ReadOptions()), ...$parsing],
            "Parsers/CsvParser.php: missing column"         => [fn () => (new CsvParser())->parse("start,end\n1,2", new ReadOptions()), ...$parsing],
            "Parsers/CsvParser.php: too many columns"       => [fn () => (new CsvParser())->parse(str_repeat("a,", 1000) . "a", new ReadOptions()), ...$parsing],
            "Parsers/DeepgramParser.php: no channels"       => [fn () => (new DeepgramParser())->parse('{"metadata": {}}', new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: no GSI block"        => [fn () => (new EbuStlParser())->parse("STL", new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: partial TTI block"   => [fn () => (new EbuStlParser())->parse(str_repeat(" ", 1025), new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: disk format code"    => [fn () => (new EbuStlParser())->parse(str_repeat(" ", 1024), new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: code table 09"       => [fn () => (new EbuStlParser())->parse(
                str_pad("850STL25.01109", 1024, " "), new ReadOptions()), ...$parsing],
            "Parsers/GoogleSpeechParser.php: no results"    => [fn () => (new GoogleSpeechParser())->parse('{"done": true}', new ReadOptions()), ...$parsing],
            "Parsers/GoogleSpeechParser.php: alternatives"  => [fn () => (new GoogleSpeechParser())->parse('{"results": [{"alternatives": "x"}]}', new ReadOptions()), ...$parsing],
            "Parsers/FfMetadataChaptersParser.php: no header" => [fn () => (new FfMetadataChaptersParser())->parse("title=x", new ReadOptions()), ...$parsing],
            "Parsers/FfMetadataChaptersParser.php: time base 0" => [fn () => (new FfMetadataChaptersParser())->parse(
                ";FFMETADATA1\n[CHAPTER]\nTIMEBASE=0/1\n", new ReadOptions()), ...$parsing],
            "Parsers/HtmlTranscriptParser.php: no time"     => [fn () => (new HtmlTranscriptParser())->parse("<p>Hi</p>", new ReadOptions()), ...$parsing],
            "Parsers/HtmlTranscriptParser.php: bad time"    => [fn () => (new HtmlTranscriptParser())->parse("<time>x</time><p>Hi</p>", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/JsonParser.php: invalid base64"        => [fn () => (new JsonParser())->parse(
                '{"version": 1, "cues": [], "formatData": {"stl": {"base64": "!"}}}', new ReadOptions()), ...$parsing],
            "Parsers/MicroDvdParser.php: no frame rate"     => [fn () => (new MicroDvdParser())->parse("{0}{25}text", new ReadOptions()), ...$parsing],
            "Parsers/MicroDvdParser.php: frame rate 0"      => [fn () => (new MicroDvdParser())->parse("{1}{1}0\n{0}{25}text", new ReadOptions()), ...$parsing],
            "Parsers/MicroDvdParser.php: no MicroDVD cue"   => [fn () => (new MicroDvdParser())->parse("{1}{1}25\ntext", new ReadOptions()), ...$parsing],
            "Parsers/Mpl2Parser.php: no MPL2 cue"           => [fn () => (new Mpl2Parser())->parse("text\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: no timing line"       => [fn () => (new MpSubParser())->parse("FORMAT=TIME\ntext\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: negative duration"    => [fn () => (new MpSubParser())->parse("FORMAT=TIME\n0 -1\ntext\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: unknown FORMAT"       => [fn () => (new MpSubParser())->parse("FORMAT=FAST\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: frame rate 0"         => [fn () => (new MpSubParser())->parse("FORMAT=0\n", new ReadOptions()), ...$parsing],
            "Parsers/OgmChaptersParser.php: no name line"   => [fn () => (new OgmChaptersParser())->parse(
                "CHAPTER01=00:00:00.000\nCHAPTER02=00:00:01.000\n", new ReadOptions()), ...$parsing],
            "Parsers/OgmChaptersParser.php: no time line"   => [fn () => (new OgmChaptersParser())->parse("CHAPTER01NAME=x\n", new ReadOptions()), ...$parsing],
            "Parsers/OgmChaptersParser.php: second 60"      => [fn () => (new OgmChaptersParser())->parse("CHAPTER01=00:00:60.000\n", new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: no magic bytes"         => [fn () => (new PgsParser())->parse("XG", new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off header"         => [fn () => (new PgsParser())->parse("PG\0\0", new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off data"           => [fn () => (new PgsParser())->parse(substr(self::pgsSegment(0x14, "\0\0"), 0, -1), new ReadOptions()),
                                                                ...$parsing],
            "Parsers/PgsParser.php: cut off presentation"   => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16, "\0\0"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off object"         => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16,
                "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off cropping"       => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16,
                "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\0\0\x80\0\0\0\0"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off definition"     => [fn () => (new PgsParser())->parse(self::pgsSegment(0x15, "\0\1\0\x80"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: short bitmap"           => [fn () => (new PgsParser())->parse(
                self::pgsSegment(0x16, "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\7\0\0\0\0\0\0") .
                self::pgsSegment(0x14, "\0\0\1\x10\x80\x80\xFF") .
                self::pgsSegment(0x15, "\0\7\0\xC0\0\0\7\0\4\0\2\1\1\0\0") .
                self::pgsSegment(0x80, ""), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: object too large"       => [fn () => (new PgsParser())->parse(
                self::pgsSegment(0x15, "\0\7\0\xC0\0\0\4" . pack("nn", 8000, 1)), new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: no chapters" => [fn () => (new PodcastChaptersParser())->parse('{"version": "1.2.0"}', new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: start no number" => [fn () => (new PodcastChaptersParser())->parse(
                '{"chapters": [{"title": "x"}]}', new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: end no number" => [fn () => (new PodcastChaptersParser())->parse(
                '{"chapters": [{"startTime": 1, "endTime": "2"}]}', new ReadOptions()), ...$parsing],
            "Parsers/PodcastTranscriptParser.php: no segments" => [fn () => (new PodcastTranscriptParser())->parse('{"version": "1.0.0"}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/PodcastTranscriptParser.php: segment no object" => [fn () => (new PodcastTranscriptParser())->parse('{"segments": [1]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/PodcastTranscriptParser.php: time no number" => [fn () => (new PodcastTranscriptParser())->parse(
                '{"segments": [{"startTime": "0"}]}', new ReadOptions()), ...$parsing],
            "Parsers/SamiParser.php: no Start attribute"    => [fn () => (new SamiParser())->parse("<SAMI><BODY><SYNC>text</BODY></SAMI>", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SamiParser.php: unknown class"         => [fn () => (new SamiParser())->parse(
                "<SAMI><BODY><SYNC Start=0><P Class=ENCC>text</BODY></SAMI>", new ReadOptions(formats: [new SamiReadOptions("FRCC")])), ...$parsing],
            "Parsers/SbvParser.php: no timestamps"          => [fn () => (new SbvParser())->parse("text\nmore", new ReadOptions()), ...$parsing],
            "Parsers/SbvParser.php: invalid time"           => [fn () => (new SbvParser())->parse("soon,0:00:02.000\ntext", new ReadOptions()), ...$parsing],
            "Parsers/SccParser.php: other header"           => [fn () => (new SccParser())->parse("Scenarist_SCC V2.0\n", new ReadOptions()), ...$parsing],
            "Parsers/SccParser.php: no time code"           => [fn () => (new SccParser())->parse(SccParser::HEADER . "\n\n942c\n", new ReadOptions()), ...$parsing],
            "Parsers/SccParser.php: invalid byte pair"      => [fn () => (new SccParser())->parse(SccParser::HEADER . "\n\n00:00:01:00\t94zz\n", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: no cue number"       => [fn () => (new SubRipParser())->parse("x\n00:00:01,000 --> 00:00:02,000\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: no timestamps"       => [fn () => (new SubRipParser())->parse("1\ntext\nmore", new ReadOptions()), ...$parsing],
            "Parsers/Options/SccReadOptions.php: channel 3"         => [fn () => new SccReadOptions(3), ...$invalid],
            "Parsers/SubRipParser.php: invalid time"        => [fn () => (new SubRipParser())->parse("1\nsoon --> 00:00:02,000\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: text after end time" => [fn () => (new SubRipParser())->parse("1\n00:00:01,000 --> 00:00:02,000 x\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubViewerParser.php: version 1 header" => [fn () => (new SubViewerParser())->parse("text\n" . SubViewerParser::START_SCRIPT . "\n", new ReadOptions()), ...$parsing],
            "Parsers/SubViewerParser.php: version 1 time"   => [fn () => (new SubViewerParser())->parse(SubViewerParser::START_SCRIPT . "\n[00:75:00]\n", new ReadOptions()), ...$parsing],
            "Parsers/SubViewerParser.php: version 2 time"   => [fn () => (new SubViewerParser())->parse("00:00:01.00,00:00:02.00\na\n00:00:03.00,00:75:04.00\nb\n", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: invalid UTF-8"    => [fn () => (new SamiParser())->parse("<SAMI>\xFF</SAMI>", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: no JSON"           => [fn () => (new JsonParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: JSON root no object" => [fn () => (new PodcastTranscriptParser())->parse("[1]", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: JSON field of 100000 hours" => [fn () => (new JsonParser())->parse('{"version": 1, "cues": [{"start": 360000000, "end": 360000001, "lines": []}]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubtitleParser.php: NUL in a text file" => [fn () => (new SubRipParser())->parse("1\n00:00:01,000 --> 00:00:02,000\nte\0xt\n", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: end before start" => [fn () => (new SubRipParser())->parse("1\n00:00:05,000 --> 00:00:02,000\ntext\n", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: text before XML"  => [fn () => (new TtmlParser())->parse("x\n" . sprintf(self::TTML, ""), new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: 100000 hours"      => [fn () => (new WebVttParser())->parse("WEBVTT\n\n100000:00:00.000 --> 100000:00:01.000\ntext\n", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/TmPlayerParser.php: no TMPlayer line"  => [fn () => (new TmPlayerParser())->parse("text\n", new ReadOptions()), ...$parsing],
            "Parsers/TtmlClock.php: invalid time"           => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="soon" end="2s">text</p>'), new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: no tt root"            => [fn () => (new TtmlParser())->parse("<html/>", new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: not well-formed"       => [fn () => (new TtmlParser())->parse("<tt", new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: not repairable"        => [fn () => (new TtmlParser())->parse("<!-- no root -->", new ReadOptions(lenient: true)), ...$parsing],
            "Parsers/TtmlParser.php: after the div ends"    => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<div begin="2s" end="3s"><p begin="1s">text</p></div>'), new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: frame label"           => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="00:00:01:45" end="00:00:02:10">text</p>'), new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: no end time"           => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="1s">text</p>'), new ReadOptions()), ...$parsing],
            "Parsers/VobSubParser.php: no VobSubReadOptions" => [fn () => (new VobSubParser())->parse("", new ReadOptions()), ...$invalid],
            "Parsers/VobSubParser.php: no track"            => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX)])), ...$parsing],
            "Parsers/VobSubParser.php: filepos outside"     => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/VobSubParser.php: no header line"      => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions("size: 720x576\n")])), ...$parsing],
            "Parsers/VobSubParser.php: invalid size"        => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "size: 0x0\n")])), ...$parsing],
            "Parsers/VobSubParser.php: invalid custom colors" => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "custom colors: yes\n")])), ...$parsing],
            "Parsers/VobSubParser.php: invalid id"          => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "id: en, index: 99\n")])), ...$parsing],
            "Parsers/VobSubParser.php: timestamp before id" => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "timestamp: 00:00:01:000, filepos: 0\n")])),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: invalid timestamp"   => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "id: en, index: 0\ntimestamp: soon\n")])),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: no size"             => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions("# VobSub index file\nid: en, index: 0\n")])),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: no palette"          => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions("# VobSub index file\nsize: 720x576\n")])),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: short palette"       => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "palette: 000000\n")])), ...$parsing],
            "Parsers/VobSubParser.php: invalid time"        => [fn () => (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions(self::IDX . "delay: soon\n")])), ...$parsing],
            "Parsers/VobSubParser.php: no start code"       => [fn () => (new VobSubParser())->parse("text", new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/VobSubParser.php: cut off pack header" => [fn () => (new VobSubParser())->parse("\0\0\1\xBA\x44\0", new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: program end code"    => [fn () => (new VobSubParser())->parse("\0\0\1\xB9\0\0", new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: packet too long"     => [fn () => (new VobSubParser())->parse(
                substr(self::vobSubPacket("\0\4\0\4"), 0, -1), new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/DvdSubpicture.php: no control sequence" => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\2"), new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/DvdSubpicture.php: cut off command"    => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\x09\0\4\0\0\0\4\x05"), new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/DvdSubpicture.php: image too large"    => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\x15\0\4\0\0\0\4\x05\x00\x0F\xFF\x00\x0F\xFF\x06\0\0\0\0\xFF"),
                new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/DvdSubpicture.php: cut off bitmap"     => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\x15\0\4\0\0\0\4\x05\0\0\1\0\0\1\x06\0\x15\0\x15\xFF"), new ReadOptions(formats: [new VobSubReadOptions(self::IDX_WITH_TRACK)])), ...$parsing],
            "Parsers/Options/VobSubReadOptions.php: negative track" => [fn () => new VobSubReadOptions(track: -1), ...$invalid],
            "Parsers/WebVttParser.php: no WEBVTT"           => [fn () => (new WebVttParser())->parse("text", new ReadOptions()), ...$parsing],
            "Parsers/WebVttParser.php: no WEBVTT, lenient"  => [fn () => (new WebVttParser())->parse("text", new ReadOptions(lenient: true)), ...$parsing],
            "Parsers/WebVttParser.php: unknown block"       => [fn () => (new WebVttParser())->parse("WEBVTT\n\ntext\nmore", new ReadOptions()), ...$parsing],
            "Parsers/WebVttParser.php: no empty header line" => [fn () => (new WebVttParser())->parse(
                "WEBVTT\n00:00:01.000 --> 00:00:02.000\ntext", new ReadOptions()), ...$parsing],
            "Parsers/WebVttParser.php: invalid end time"    => [fn () => (new WebVttParser())->parse("WEBVTT\n\n00:00:01.000 --> soon\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WebVttParser.php: invalid start time"  => [fn () => (new WebVttParser())->parse("WEBVTT\n\nsoon --> 00:00:02.000\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WhisperJsonParser.php: no segments"    => [fn () => (new WhisperJsonParser())->parse('{"text": "Hi"}', new ReadOptions()), ...$parsing],
            "Parsers/WhisperJsonParser.php: time no number" => [fn () => (new WhisperJsonParser())->parse('{"segments": [{"start": "0"}]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WhisperJsonParser.php: time negative"  => [fn () => (new WhisperJsonParser())->parse('{"segments": [{"start": -1}]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WordGrouping.php: bad time"            => [fn () => (new AssemblyAiParser())->parse('{"words": [{"text": "Hi", "start": "soon"}]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WordGrouping.php: text no string"      => [fn () => (new AwsTranscribeParser())->parse(
                '{"results": {"items": [{"alternatives": []}]}}', new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: no events" => [fn () => (new YouTubeTimedTextParser())->parse('{"segs": []}', new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: time no number" => [fn () => (new YouTubeTimedTextParser())->parse(
                '{"events": [{"tStartMs": "0"}]}', new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: no XML"    => [fn () => (new YouTubeTimedTextParser())->parse("<transcript>", new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: other root" => [fn () => (new YouTubeTimedTextParser())->parse("<tt/>", new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: bad time"  => [fn () => (new YouTubeTimedTextParser())->parse(
                '<transcript><text dur="1">Hi</text></transcript>', new ReadOptions()), ...$parsing],
            "Profanity/ProfanityOptions.php: star in a word" => [fn () => new ProfanityOptions(["f*ck"]), ...$invalid],
            "Profanity/ProfanityOptions.php: no words"      => [fn () => new ProfanityOptions([]), ...$invalid],
            "ReadOptions.php: unknown encoding"             => [fn () => new ReadOptions(encoding: "NO-SUCH-ENCODING"), ...$invalid],
            "Retiming.php: shift range in reverse"          => [fn () => self::subtitle()->shift(1, 2, 1), ...$invalid],
            "Retiming.php: no sync points"                  => [fn () => self::subtitle()->syncByPoints([]), ...$invalid],
            "Retiming.php: sync point of another type"      => [fn () => self::subtitle()->syncByPoints([[1, 2]]), ...$invalid],
            "Retiming.php: sync points out of order"        => [fn () => self::subtitle()->syncByPoints([new SyncPoint(2, 2), new SyncPoint(1, 3)]), ...$invalid],
            "Speakers/SpeakerLabelOptions.php: invalid color" => [fn () => new SpeakerLabelOptions(colors: ["yellow"]), ...$invalid],
            "Streaming/Streams.php: no stream"              => [fn () => iterator_to_array((new SubRipStreamReader())->read(5)), ...$invalid],
            "Streaming/Streams.php: missing file"           => [fn () => iterator_to_array((new SubRipStreamReader())->read(__DIR__ . "/missing.srt")),
                                                                ...$invalid],
            "Streaming/Streams.php: closed writer"          => [fn () => self::closedWriter()->write(new SubtitleCue(1, 2, "text")), ...$invalid],
            "Streaming/Streams.php: read-only stream"       => [fn () => new SubRipStreamWriter(fopen("php://memory", "rb")), ...$invalid],
            "Streaming/WebVttStreamReader.php: no WEBVTT"   => [fn () => iterator_to_array((new WebVttStreamReader())->read(self::stream("text"))),
                                                                ...$parsing],
            "StringHelpers.php: unknown encoding"           => [fn () => StringHelpers::convertToUtf8("text", "NO-SUCH-ENCODING"),
                                                                ...$parsing],
            "Subtitle.php: addCues no cue"                  => [fn () => (new Subtitle())->addCues([5]), ...$invalid],
            "Subtitle.php: save() to an unknown extension"  => [fn () => self::subtitle()->save(self::FILES . "out.unknown"), ...$unsupported],
            "Subtitle.php: save() into a missing directory" => [fn () => self::subtitle()->save(self::FILES . "missing/out.srt"), ...$invalid],
            "Subtitle.php: format without a formatter"      => [fn () => self::subtitle()->toString(Format::VobSub), ...$unsupported],
            "Subtitle.php: image cue without text"          => [fn () => (new Subtitle())->addCue($imageCue)->toString(Format::SubRip),
                                                                ImageCueWithoutTextException::class, ImageCueWithoutTextException::class],
            "Subtitle.php: remove a missing cue"            => [fn () => self::subtitle()->removeCue(9),
                                                                \RuntimeException::class, CueNotFoundException::class],
            "Subtitle.php: negative comment index"          => [fn () => self::subtitle()->addComment("note", -1), ...$invalid],
            "SubtitleReader.php: unknown format"            => [fn () => Subtitle::fromStringAutoDetectFormat("text"), ...$unknown],
            "SubtitleReader.php: unknown format of a file"  => [fn () => Subtitle::loadAutoDetectFormat(self::FILES . "chapters/ffmetadata/real/m4b_audiobook.ffmeta"),
                                                                ...$unknown],
            "SubtitleReader.php: load() of an MKV file"     => [fn () => Subtitle::load(self::FILES . "mkv/pgs.mkv", Format::Pgs), ...$unsupported],
            "SubtitleReader.php: fromString() of MKV content" => [fn () => Subtitle::fromString(MatroskaReader::EBML_MAGIC, Format::SubRip), ...$unsupported],
            "SubtitleReader.php: MKV with 2 subtitle tracks" => [fn () => Subtitle::loadAutoDetectFormat(self::FILES . "mkv/pgs.mkv"), ...$unsupported],
            "SubtitleReader.php: VobSub without its .sub file" => [fn () => Subtitle::load(self::FILES . "vobsub/SOURCES.md", Format::VobSub), ...$invalid],
            "SubtitleReader.php: missing file"              => [fn () => Subtitle::load(self::FILES . "missing.srt", Format::SubRip), ...$invalid],
            "SubtitleReader.php: unreadable file"           => [fn () => Subtitle::load(self::unreadableFile(), Format::SubRip), ...$invalid],
            "SubtitleReader.php: format without a parser"   => [fn () => Subtitle::fromString("text", Format::PlainText), ...$unsupported],
            "Sync/ReferenceSyncOptions.php: offset range too wide" => [fn () => new ReferenceSyncOptions(new Subtitle(), -5000, 5000), ...$invalid],
            "Sync/ReferenceSyncOptions.php: offsets in reverse" => [fn () => new ReferenceSyncOptions(new Subtitle(), 5, 1), ...$invalid],
            "Sync/ReferenceSyncOptions.php: negative split count" => [fn () => new ReferenceSyncOptions(new Subtitle(), maxSplits: -1), ...$invalid],
            "Sync/SpeechReference.php: mono log"            => [fn () => SpeechReference::fromFfmpegSilencedetect("channel: 0 | silence_start: 1", 9),
                                                                ...$parsing],
            "Sync/SpeechReference.php: silence end first"   => [fn () => SpeechReference::fromFfmpegSilencedetect("silence_end: 1", 9),
                                                                ...$parsing],
            "Sync/SpeechReference.php: invalid interval"    => [fn () => SpeechReference::fromIntervals([[2, 1]]), ...$invalid],
            "TextTransforms.php: empty search"              => [fn () => self::subtitle()->replaceText("", "x"), ...$invalid],
            "TextTransforms.php: invalid regex"             => [fn () => self::subtitle()->replaceText("/[/", "x", new ReplaceTextOptions(regex: true)), ...$invalid],
            "Timecode.php: drop frame at 25 fps"            => [fn () => Timecode::frameNumber(0, new FrameRate(25), true), ...$invalid],
            "Timecode.php: invalid timecode"                => [fn () => Timecode::parse("1:2:3:4:5"), ...$invalid],
            "Timing/ShotChangeOptions.php: negative window" => [fn () => new ShotChangeOptions(24, snapWindowFrames: -1), ...$invalid],
            "Timing/ShotChangeOptions.php: negative gap"    => [fn () => new ShotChangeOptions(24, minGapFrames: -1), ...$invalid],
            "Timing/ShotChangeOptions.php: negative minimum duration" => [fn () => new ShotChangeOptions(24, minDurationFrames: -1),
                                                                ...$invalid],
            "Timing/ShotChanges.php: line without a time"   => [fn () => ShotChanges::fromText("abc"), ...$parsing],
            "Http/CurlHttpClient.php: no curl extension"    => [fn () => throw WithoutCurl::exception("new " . CurlHttpClient::class . "();"),
                                                                ...$invalid],
            "Http/CurlHttpClient.php: no connection"        => [fn () => (new CurlHttpClient())->post(self::closedPortUrl(), [], ""), ...$translation],
            "Translation/DeepLEngine.php: HTTP 403"         => [fn () => (new DeepLEngine(new DeepLOptions("key", httpClient: new FakeHttpClient([[403, ""]]))))
                                                                ->translate(["a"], "en", "de"), ...$translation],
            "Translation/DeepLEngine.php: no translations"  => [fn () => (new DeepLEngine(new DeepLOptions("key", httpClient: new FakeHttpClient([[200, "{}"]]))))
                                                                ->translate(["a"], "en", "de"), ...$translation],
            "Translation/DeepLOptions.php: empty key"       => [fn () => new DeepLOptions(" "), ...$invalid],
            "Translation/DeepLOptions.php: key with a line break" => [fn () => new DeepLOptions("abc:fx\r\n"), ...$invalid],
            "Translation/DeepLOptions.php: base URL without scheme" => [fn () => new DeepLOptions("key", "api.deepl.com"), ...$invalid],
            "Translation/GoogleTranslateEngine.php: HTTP 403" => [fn () => (new GoogleTranslateEngine(new GoogleTranslateOptions("key",
                                                                httpClient: new FakeHttpClient([[403, ""]]))))->translate(["a"], "en", "fr"),
                                                                ...$translation],
            "Translation/GoogleTranslateEngine.php: no translations" => [fn () => (new GoogleTranslateEngine(new GoogleTranslateOptions("key",
                                                                httpClient: new FakeHttpClient([[200, "{}"]]))))->translate(["a"], "en", "fr"),
                                                                ...$translation],
            "Translation/GoogleTranslateOptions.php: empty key" => [fn () => new GoogleTranslateOptions(""), ...$invalid],
            "Translation/GoogleTranslateOptions.php: key with a space" => [fn () => new GoogleTranslateOptions(" key"), ...$invalid],
            "Translation/GoogleTranslateOptions.php: base URL without scheme" => [fn () => new GoogleTranslateOptions("key", "ftp://example.com"),
                                                                ...$invalid],
            "Translation/OpenAiCompatibleEngine.php: HTTP 401" => [fn () => (new OpenAiCompatibleEngine(new OpenAiCompatibleOptions("http://localhost/v1",
                                                                "llama3", httpClient: new FakeHttpClient([[401, ""]]))))->translate(["a"], "en", "de"),
                                                                ...$translation],
            "Translation/OpenAiCompatibleEngine.php: no JSON array" => [fn () => (new OpenAiCompatibleEngine(new OpenAiCompatibleOptions(
                                                                "http://localhost/v1", "llama3", httpClient: new FakeHttpClient([[200, "{}"], [200, "{}"]]))))
                                                                ->translate(["a"], "en", "de"), ...$translation],
            "Translation/OpenAiCompatibleOptions.php: base URL without scheme" => [fn () => new OpenAiCompatibleOptions("localhost:11434/v1", "llama3"),
                                                                ...$invalid],
            "Translation/OpenAiCompatibleOptions.php: empty model" => [fn () => new OpenAiCompatibleOptions("http://localhost/v1", " "), ...$invalid],
            "Translation/OpenAiCompatibleOptions.php: key with a line break" => [fn () => new OpenAiCompatibleOptions("http://localhost/v1", "llama3", "sk\n"),
                                                                ...$invalid],
            "Translation/OpenAiCompatibleOptions.php: empty prompt" => [fn () => new OpenAiCompatibleOptions("http://localhost/v1", "llama3", prompt: ""),
                                                                ...$invalid],
            "Translation/TranslationOptions.php: cue limit 0" => [fn () => new TranslationOptions(maxCuesPerSentence: 0), ...$invalid],
            "Translation/TranslationOptions.php: character limit 0" => [fn () => new TranslationOptions(maxCharactersPerRequest: 0), ...$invalid],
            "Translation/TranslationRunner.php: no translations" => [fn () => (new TranslationRunner(new class implements TranslationEngine {
                public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
                {
                    return [];
                }
            }))->translate(self::subtitle(), "en", "de"), ...$invalid],
            "Validation/ValidationRules.php: invalid character class" => [fn () => new ValidationRules(allowedCharacters: "[z-a]"),
                ...$invalid],
            "WriteDefaults.php: MicroDVD without a frame rate" => [fn () => self::subtitle()->toString(Format::MicroDvd), ...$invalid],
            "WriteDefaults.php: iTT without a frame rate"   => [fn () => self::subtitle()->toString(Format::Itt), ...$invalid],
        ];
    }


    #[DataProvider("throwSites")]
    public function testOldCatchBlocksAndTheInterfaceCatchTheThrowSite(Closure $trigger, string $oldType, string $class): void
    {
        $caughtByOldType = null;
        try {
            $trigger();
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $caughtByOldType = $exception;
        }

        $caughtByInterface = null;
        try {
            $trigger();
        } catch (SubtitleToolboxException $exception) {
            $caughtByInterface = $exception;
        }

        $this->assertInstanceOf($oldType, $caughtByOldType);
        $this->assertInstanceOf(SubtitleToolboxException::class, $caughtByInterface);
        $this->assertSame($class, $caughtByInterface::class);
        $this->assertSame((self::CODES + self::CLI_CODES)[$class], $caughtByInterface->getCode());
    }


    public function testEveryThrowSiteInSrcHasExactlyOneCase(): void
    {
        $cases      = [];
        $throwLines = [];
        foreach (self::throwSites() as $key => [$trigger]) {
            $file         = explode(":", $key)[0];
            $cases[$file] = ($cases[$file] ?? 0) + 1;
            try {
                $trigger();
            } catch (SubtitleToolboxException $exception) {
                $this->assertSame(realpath(self::SRC . $file), $exception->getFile(), $key);
                $throwLines[$exception->getFile() . ":" . $exception->getLine()] = $key;
            }
        }
        $this->assertCount(array_sum($cases), $throwLines);

        $sites = [];
        foreach ($this->sourceFiles() as $file => $code) {
            $count = preg_match_all('/\bthrow\s+new\b/', $code);
            if ($count > 0) {
                $sites[$file] = $count;
            }
        }
        ksort($cases);
        ksort($sites);

        $this->assertSame($sites, $cases);
    }


    public function testNoThrowSiteInSrcThrowsAGlobalClass(): void
    {
        $globalThrows = [];
        foreach ($this->sourceFiles() as $file => $code) {
            preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $code, $imports, PREG_SET_ORDER);
            $aliases = [];
            foreach ($imports as $import) {
                $aliases[$import[2] ?? substr(strrchr("\\" . $import[1], "\\"), 1)] = $import[1];
            }
            preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $namespace);

            preg_match_all('/\bthrow\s+new\s+(\\\\?[\w\\\\]+)/', $code, $throws);
            foreach ($throws[1] as $name) {
                $class = match (true) {
                    str_starts_with($name, "\\") => substr($name, 1),
                    isset($aliases[$name])       => $aliases[$name],
                    default                      => $namespace[1] . "\\" . $name,
                };
                if (!is_subclass_of($class, SubtitleToolboxException::class)) {
                    $globalThrows[] = "$file: $name";
                }
            }
        }

        $this->assertSame([], $globalThrows);
    }


    public function testErrorCodesStartAt100WithoutGapsOrClashesExceptTheRetiredCode(): void
    {
        $codes = [];
        foreach (glob(self::SRC . "Exceptions/*.php") as $path) {
            $class = __NAMESPACE__ . "\\" . basename($path, ".php");
            if (!(new \ReflectionClass($class))->isInstantiable()) {
                continue;
            }
            $codes[$class] = (new $class("message"))->getCode();
        }
        asort($codes);

        $this->assertSame(self::CODES, $codes);
        $this->assertSame(array_values(array_diff(range(100, 100 + count($codes)), self::RETIRED_CODES)), array_values($codes));
    }


    /**
     * @return array<string, string> file path relative to src/ => file content
     */
    private function sourceFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path => $info) {
            if ($info->getExtension() === "php") {
                $files[substr($path, strlen(self::SRC))] = file_get_contents($path);
            }
        }

        return $files;
    }
}
