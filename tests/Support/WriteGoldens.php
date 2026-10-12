<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\SccWriteOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\WriteOptions;

/**
 * Lists the write goldens, and writes the output that a golden file holds.
 * A round trip golden "<file>.out.<extension>" holds a real file read and written in its own format. A round trip
 * whose output equals the input after BOM and line-end normalisation has no golden file, and its test compares with
 * the input.
 * A conversion golden "<file>.to.<format>" holds a real file of another format written in the format.
 */
final class WriteGoldens
{
    /**
     * The fixture folders under tests/files whose real files get a round trip golden, and their format.
     */
    private const ROUND_TRIPS = [
        "srt"            => Format::SubRip,
        "vtt"            => Format::WebVtt,
        "lrc"            => Format::Lyrics,
        "html"           => Format::HtmlTranscript,
        "podcast"        => Format::PodcastTranscript,
        "whisper"        => Format::Whisper,
        "aws-transcribe" => Format::AwsTranscribe,
        "deepgram"       => Format::Deepgram,
        "assemblyai"     => Format::AssemblyAi,
        "google-speech"  => Format::GoogleSpeech,
    ];

    /**
     * The real files under tests/files that get a round trip golden, in folders that ROUND_TRIPS does not cover.
     */
    private const ROUND_TRIP_FILES = [
        "ttml/real/bbc_ebu_tt_d.ttml"         => Format::Ttml,
        "ttml/real/mantas_netflix_ticks.dfxp" => Format::Ttml,
        "ttml/real/w3c_imsc11_regions.ttml"   => Format::Ttml,
        "itt/real/fcp_23976_styles.itt"       => Format::Itt,
        "scc/real/popon_broadcast_df.scc"     => Format::Scc,
        "sami/real/multi_language.smi"        => Format::Sami,
    ];

    /**
     * The EBU STL files under tests/files that get a conversion golden in SubRip and TTML, the targets of most
     * broadcast workflows.
     */
    private const STL_CONVERSIONS = [
        "stl/real/bakery_teletext_25fps.stl",
        "stl/real/irt_requirement-0056-001_modified.stl",
        "stl/real/irt_requirement-0061-001.stl",
        "stl/real/irt_requirement-0074-001.stl",
        "stl/real/irt_requirement-0076-001.stl",
        "stl/real/irt_requirement-0076-009.stl",
    ];

    /**
     * The transcript formats that get a conversion golden of each file in TRANSCRIPT_CONVERSIONS.
     */
    private const TRANSCRIPT_TARGETS = [Format::Whisper, Format::AwsTranscribe, Format::Deepgram, Format::AssemblyAi, Format::GoogleSpeech];

    /**
     * The real files under tests/files that get a conversion golden in each format of TRANSCRIPT_TARGETS.
     */
    private const TRANSCRIPT_CONVERSIONS = [
        "srt/real/own_styled.srt"     => Format::SubRip,
        "vtt/real/w3c_timestamps.vtt" => Format::WebVtt,
    ];

    /**
     * The real files under tests/files that get a conversion golden in SubRip, WebVTT and ASS, one or two per
     * subtitle format, with markup where the format has it.
     */
    private const SUBTITLE_CONVERSIONS = [
        "srt/real/own_styled.srt"                         => Format::SubRip,
        "vtt/real/w3c_voices.vtt"                         => Format::WebVtt,
        "vtt/real/own_ytdlp_rolling.vtt"                  => Format::WebVtt,
        "vtt/real/own_openai_whisper_highlight_words.vtt" => Format::WebVtt,
        "ass/real/own_aegisub.ass"                        => Format::Ass,
        "ass/real/own_signs_crlf.ass"                     => Format::Ass,
        "sbv/real/youtube_studio_lf.sbv"                  => Format::Sbv,
        "subviewer/real/subviewer2_crlf.sub"              => Format::SubViewer,
        "microdvd/real/sub_microdvd_with_styles.sub"      => Format::MicroDvd,
        "mpl2/real/subtitle_edit_shape.txt"               => Format::Mpl2,
        "mpsub/real/mplayer_doc_time.sub"                 => Format::MpSub,
        "tmplayer/real/tmplayer_multiline_bom.txt"        => Format::TmPlayer,
        "lrc/real/handwritten-enhanced.lrc"               => Format::Lyrics,
        "ttml/real/bbc_ebu_tt_d.ttml"                     => Format::Ttml,
        "itt/real/fcp_23976_styles.itt"                   => Format::Itt,
        "scc/real/popon_broadcast_df.scc"                 => Format::Scc,
        "scc/real/rollup_news_ndf.scc"                    => Format::Scc,
        "sami/real/multi_language.smi"                    => Format::Sami,
        "csv/real/mantas_done_shape.csv"                  => Format::Csv,
    ];

    /**
     * The real files under tests/files that get a conversion golden in SubRip and WebVTT, one or more per transcript
     * format, with speakers where the format has them. They are read with the default read options.
     */
    private const TRANSCRIPT_INPUT_CONVERSIONS = [
        "whisper/real/openai_whisper_word_timestamps.json"      => Format::Whisper,
        "whisper/real/whisperx_diarize.json"                    => Format::Whisper,
        "aws-transcribe/real/library_speakers_language_id.json" => Format::AwsTranscribe,
        "deepgram/real/pool_utterances_diarize.json"            => Format::Deepgram,
        "assemblyai/real/hike_utterances_speakers.json"         => Format::AssemblyAi,
        "google-speech/real/restaurant_v2_diarization.json"     => Format::GoogleSpeech,
        "youtube/real/auto.en.json3"                            => Format::YouTubeTimedText,
        "youtube/real/styled.en.srv3"                           => Format::YouTubeTimedText,
        "youtube/real/transcript.en.srv1"                       => Format::YouTubeTimedText,
        "podcast/real/spec_word_segments.json"                  => Format::PodcastTranscript,
        "html/real/spec_example_shape.html"                     => Format::HtmlTranscript,
    ];


    /**
     * Returns one case per golden file: the input file, its format, the output format, the golden file, the read
     * options and the write options. The paths are relative to tests/files.
     *
     * @return array<string, array{string, Format, Format, string, ReadOptions, WriteOptions}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::ROUND_TRIPS as $folder => $format) {
            foreach (self::realFiles($folder) as $file) {
                $golden         = "$folder/real/expected/$file.out." . pathinfo($file, PATHINFO_EXTENSION);
                $cases[$golden] = ["$folder/real/$file", $format, $format, $golden, self::transcriptReadOptions(), new WriteOptions()];
            }
        }
        foreach (self::ROUND_TRIP_FILES as $path => $format) {
            $golden         = dirname($path) . "/expected/" . basename($path) . ".out." . pathinfo($path, PATHINFO_EXTENSION);
            $cases[$golden] = [$path, $format, $format, $golden, new ReadOptions(), new WriteOptions()];
        }
        foreach (self::styledConversions() as $suffix => [$target, $writeOptions]) {
            $cases += self::conversion("srt/real/own_styled.srt", Format::SubRip, $target, new ReadOptions(), $writeOptions, $suffix);
        }
        foreach (self::TRANSCRIPT_TARGETS as $target) {
            foreach (self::TRANSCRIPT_CONVERSIONS as $path => $format) {
                $cases += self::conversion($path, $format, $target, self::transcriptReadOptions());
            }
        }
        foreach (self::SUBTITLE_CONVERSIONS as $path => $format) {
            foreach ([Format::SubRip, Format::WebVtt, Format::Ass] as $target) {
                if ($target !== $format) {
                    $cases += self::conversion($path, $format, $target, self::readOptions($path));
                }
            }
        }
        foreach (self::STL_CONVERSIONS as $path) {
            foreach ([Format::SubRip, Format::Ttml] as $target) {
                $cases += self::conversion($path, Format::EbuStl, $target, new ReadOptions());
            }
        }
        foreach (self::TRANSCRIPT_INPUT_CONVERSIONS as $path => $format) {
            foreach ([Format::SubRip, Format::WebVtt] as $target) {
                $cases += self::conversion($path, $format, $target, new ReadOptions());
            }
        }

        return $cases;
    }


    public static function render(string $path, Format $inputFormat, Format $outputFormat, ReadOptions $options, WriteOptions $writeOptions = new WriteOptions()): string
    {
        $subtitle = TestFiles::parse($path, $inputFormat, $options);
        if ($outputFormat === Format::EbuStl && $subtitle->findFormatData("stl") === null) {
            // A new EBU STL file holds the date of today in the GSI fields CD and RD.
            $subtitle->setFormatData("stl", ["gsi" => ["CD" => "261012", "RD" => "261012"]]);
        }

        return $subtitle->toString($outputFormat, $writeOptions);
    }


    /**
     * Returns the text without a UTF-8 BOM and with LF line ends.
     */
    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text);
    }


    /**
     * Returns the decoded JSON with the keys of each object sorted, so two documents that differ only in key order
     * and white space compare as the same. Invalid UTF-8 becomes U+FFFD, as the parsers read it.
     */
    public static function canonicalJson(string $json): mixed
    {
        return self::sortKeys(json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }


    /**
     * Returns the case of a conversion golden "<file>.to.<suffix>". The suffix defaults to "<format>", or to
     * "<format>.<extension>" when the format name is not its extension.
     *
     * @return array<string, array{string, Format, Format, string, ReadOptions, WriteOptions}>
     */
    private static function conversion(string $path, Format $format, Format $target, ReadOptions $options,
                                       WriteOptions $writeOptions = new WriteOptions(), ?string $suffix = null): array
    {
        $extension = $target->extensions()[0];
        $suffix  ??= $target->value . ($extension === $target->value ? "" : ".$extension");
        $golden    = dirname($path) . "/expected/" . basename($path) . ".to.$suffix";

        return [$golden => [$path, $format, $target, $golden, $options, $writeOptions]];
    }


    /**
     * Returns the conversion goldens of srt/real/own_styled.srt, which has italic, bold, color, 2 lines and
     * alignment: the golden suffix, the format and the write options.
     *
     * @return array<string, array{Format, WriteOptions}>
     */
    private static function styledConversions(): array
    {
        return [
            "ttml"    => [Format::Ttml, new WriteOptions()],
            "itt"     => [Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 23.976)])],
            "scc"     => [Format::Scc, new WriteOptions()],
            "ndf.scc" => [Format::Scc, new WriteOptions(formats: [new SccWriteOptions(dropFrame: false)])],
            "smi"     => [Format::Sami, new WriteOptions()],
            "25.stl"  => [Format::EbuStl, new WriteOptions(formats: [new EbuStlWriteOptions(frameRate: 25)])],
            "30.stl"  => [Format::EbuStl, new WriteOptions(formats: [new EbuStlWriteOptions(frameRate: 30)])],
        ];
    }


    /**
     * A round trip and a conversion to a transcript format read a transcript with word timestamps and speaker voices.
     */
    private static function transcriptReadOptions(): ReadOptions
    {
        return new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true, speakerVoices: true)]);
    }


    /**
     * MicroDVD files need a frame rate. 23.976 fps is the most common rate of MicroDVD files. The SAMI file converts its
     * English class.
     */
    private static function readOptions(string $path): ReadOptions
    {
        return match (true) {
            str_starts_with($path, "microdvd/")       => new ReadOptions(formats: [new MicroDvdReadOptions(23.976)]),
            $path === "sami/real/multi_language.smi" => new ReadOptions(formats: [new SamiReadOptions("ENCC")]),
            default                                   => new ReadOptions(),
        };
    }


    /**
     * @return list<string>
     */
    public static function realFiles(string $folder): array
    {
        return array_values(array_filter(
            scandir(TestFiles::DIR . "$folder/real") ?: [],
            fn (string $file): bool => is_file(TestFiles::DIR . "$folder/real/$file") && $file !== "SOURCES.md"
        ));
    }


    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
