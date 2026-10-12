<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\WriteOptions;

/**
 * Lists the write goldens, and writes the output that a golden file holds.
 * A round trip golden "<file>.out.<extension>" holds a real file read and written in its own format. A round trip
 * whose output equals the input after BOM and line-end normalisation has no golden file, and its test compares with
 * the input.
 * A conversion golden "<file>.to.<format>.<extension>" holds a real file of another format written in the format.
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
     * The transcript formats that get a conversion golden of each file in CONVERSIONS.
     */
    private const CONVERSION_FORMATS = [Format::Whisper, Format::AwsTranscribe, Format::Deepgram, Format::AssemblyAi, Format::GoogleSpeech];

    /**
     * The real files under tests/files that get a conversion golden in each format of CONVERSION_FORMATS.
     */
    private const CONVERSIONS = [
        "srt/real/own_styled.srt"     => Format::SubRip,
        "vtt/real/w3c_timestamps.vtt" => Format::WebVtt,
    ];


    /**
     * Returns one case per golden file: the input file, its format, the output format and the golden file.
     * The paths are relative to tests/files.
     *
     * @return array<string, array{string, Format, Format, string}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::ROUND_TRIPS as $folder => $format) {
            foreach (self::realFiles($folder) as $file) {
                $golden         = "$folder/real/expected/$file.out." . pathinfo($file, PATHINFO_EXTENSION);
                $cases[$golden] = ["$folder/real/$file", $format, $format, $golden];
            }
        }
        foreach (self::CONVERSION_FORMATS as $format) {
            foreach (self::CONVERSIONS as $path => $inputFormat) {
                $golden         = dirname($path) . "/expected/" . basename($path) . ".to.$format->value." . $format->extensions()[0];
                $cases[$golden] = [$path, $inputFormat, $format, $golden];
            }
        }

        return $cases;
    }


    /**
     * Returns the output of the input file. A transcript file is read with word timestamps and speaker voices.
     */
    public static function render(string $path, Format $inputFormat, Format $outputFormat, ?ReadOptions $options = null): string
    {
        $options ??= new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true, speakerVoices: true)]);

        return TestFiles::parse($path, $inputFormat, $options)->toString($outputFormat, new WriteOptions());
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
