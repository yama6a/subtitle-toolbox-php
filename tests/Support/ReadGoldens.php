<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\TextEncoding;

/**
 * Lists the read goldens of the real files under tests/files and renders a parse result as a golden file.
 * A golden file is a JSON object with the parse warnings and the library JSON of the result.
 */
final class ReadGoldens
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * The fixture folders under tests/files whose real files are read with the default read options, and their format.
     */
    private const FOLDERS = [
        "srt"                 => Format::SubRip,
        "vtt"                 => Format::WebVtt,
        "ass"                 => Format::Ass,
        "sbv"                 => Format::Sbv,
        "subviewer"           => Format::SubViewer,
        "microdvd"            => Format::MicroDvd,
        "mpl2"                => Format::Mpl2,
        "mpsub"               => Format::MpSub,
        "tmplayer"            => Format::TmPlayer,
        "lrc"                 => Format::Lyrics,
        "csv"                 => Format::Csv,
        "json"                => Format::Json,
        "ttml"                => Format::Ttml,
        "itt"                 => Format::Itt,
        "scc"                 => Format::Scc,
        "sami"                => Format::Sami,
        "chapters/ffmetadata" => Format::FfMetadataChapters,
        "chapters/ogm"        => Format::OgmChapters,
        "chapters/podcast"    => Format::PodcastChapters,
        "chapters/youtube"    => Format::YouTubeChapters,
    ];

    /**
     * The transcript folders under tests/files, the format of their real files, and whether they get a golden with
     * the default read options next to the golden with word timestamps and speaker voices.
     */
    private const TRANSCRIPT_FOLDERS = [
        "whisper"        => [Format::Whisper, true],
        "aws-transcribe" => [Format::AwsTranscribe, true],
        "deepgram"       => [Format::Deepgram, true],
        "assemblyai"     => [Format::AssemblyAi, true],
        "google-speech"  => [Format::GoogleSpeech, true],
        "youtube"        => [Format::YouTubeTimedText, false],
        "podcast"        => [Format::PodcastTranscript, false],
        "html"           => [Format::HtmlTranscript, false],
    ];


    /**
     * Returns one case per golden file: the real file, its format, the read options and the golden file.
     * The paths are relative to tests/files.
     *
     * @return array<string, array{string, Format, ReadOptions, string}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::FOLDERS as $folder => $format) {
            foreach (self::realFiles($folder) as $file) {
                $golden         = "$folder/real/expected/$file";
                $cases[$golden] = ["$folder/real/$file", $format, self::readOptions("$folder/real/$file"), "$golden.json"];
            }
        }
        foreach (self::TRANSCRIPT_FOLDERS as $folder => [$format, $withDefault]) {
            foreach (self::realFiles($folder) as $file) {
                $golden         = "$folder/real/expected/$file";
                $cases[$golden] = [
                    "$folder/real/$file",
                    $format,
                    new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true, speakerVoices: true)]),
                    "$golden.json",
                ];
                if ($withDefault) {
                    $cases["$golden default"] = ["$folder/real/$file", $format, new ReadOptions(), "$golden.default.json"];
                }
            }
        }

        return $cases;
    }


    /**
     * Returns the read golden files on disk, relative to tests/files. The write goldens in the same folder have ".out." or ".to." in their names.
     *
     * @return list<string>
     */
    public static function goldenFiles(): array
    {
        $files = [];
        foreach ([...array_keys(self::FOLDERS), ...array_keys(self::TRANSCRIPT_FOLDERS)] as $folder) {
            foreach (glob(TestFiles::DIR . "$folder/real/expected/*.json") ?: [] as $path) {
                if (preg_match('/\.(?:out|to)\./', basename($path)) !== 1) {
                    $files[] = "$folder/real/expected/" . basename($path);
                }
            }
        }

        return $files;
    }


    public static function render(string $path, Format $format, ReadOptions $options): string
    {
        $subtitle = TestFiles::parse($path, $format, $options);
        $warnings = array_map(fn (ParseWarning $warning): array => [
            "message"    => $warning->message,
            "lineNumber" => $warning->lineNumber,
            "blockIndex" => $warning->blockIndex,
            "block"      => $warning->block,
            "action"     => $warning->action->value,
        ], $subtitle->getParseWarnings());
        $json     = json_decode($subtitle->toString(Format::Json), flags: JSON_THROW_ON_ERROR);

        return json_encode(["warnings" => $warnings, "subtitle" => $json], self::JSON_FLAGS) . "\n";
    }


    /**
     * MicroDVD files need a frame rate. 3 CSV files have no header row or name their columns in words the parser does not
     * guess. The parser does not detect CP949.
     */
    private static function readOptions(string $path): ReadOptions
    {
        return match (true) {
            str_starts_with($path, "microdvd/")             => new ReadOptions(formats: [new MicroDvdReadOptions(25)]),
            $path === "csv/real/dubbing_script.csv"         => new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: "Start TC", speaker: "Character"), frameRate: 25)]),
            $path === "csv/real/headerless_frame_times.csv" => new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(start: 0, end: 1, text: 2, header: false), frameRate: 25)]),
            $path === "csv/real/excel_de_semicolon.csv"     => new ReadOptions(formats: [new CsvReadOptions(new CsvColumns(end: "Ende", speaker: "Sprecher"))]),
            $path === "sami/real/own_korean_cp949.smi"      => new ReadOptions(TextEncoding::Cp949),
            default                                         => new ReadOptions(),
        };
    }


    /**
     * @return list<string>
     */
    private static function realFiles(string $folder): array
    {
        $files = [];
        foreach (scandir(TestFiles::DIR . "$folder/real") ?: [] as $file) {
            if (is_file(TestFiles::DIR . "$folder/real/$file") && $file !== "SOURCES.md") {
                $files[] = $file;
            }
        }

        return $files;
    }
}
