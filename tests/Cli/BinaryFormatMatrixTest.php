<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Tests\Support\CliTestCase;

/**
 * Converts one fixture of each readable format to each writable format, and reads each output back.
 */
class BinaryFormatMatrixTest extends CliTestCase
{
    private const INPUTS = [
        "ass"                => "ass/real/own_aegisub.ass",
        "csv"                => "csv/real/mantas_done_shape.csv",
        "ffmeta-chapters"    => "chapters/ffmetadata/real/ffmpeg_mp4_export.ffmeta",
        "html"               => "html/real/spec_example_shape.html",
        "itt"                => "itt/real/fcp_23976_styles.itt",
        "json"               => "json/real/own_aegisub.json",
        "assemblyai"         => "assemblyai/real/hike_utterances_speakers.json",
        "aws-transcribe"     => "aws-transcribe/real/library_speakers_language_id.json",
        "deepgram"           => "deepgram/real/pool_utterances_diarize.json",
        "google-speech"      => "google-speech/real/restaurant_v2_diarization.json",
        "lrc"                => "lrc/real/lrc-maker-nami.lrc",
        "mcc"                => "mcc/real/captionmaker_popon_df.mcc",
        "microdvd"           => "microdvd/real/sub_microdvd_with_styles.sub",
        "mpsub"              => "mpsub/real/mplayer_doc_time.sub",
        "pac"                => "pac/real/bakery_latin.pac",
        "pgs"                => "pgs/text_1080p.sup",
        "sami"               => "sami/real/pysubs2_source_id.smi",
        "sbv"                => "sbv/real/youtube_studio_lf.sbv",
        "scc"                => "scc/real/popon_broadcast_df.scc",
        "srt"                => "srt/real/own_styled.srt",
        "stl"                => "stl/real/bakery_teletext_25fps.stl",
        "spruce-stl"         => "spruce-stl/real/bakery_with_header.stl",
        "subviewer"          => "subviewer/real/subviewer2_crlf.sub",
        "ttml"               => "ttml/real/bbc_ebu_tt_d.ttml",
        "dcp"                => "dcp/real/dcpomatic_interop_shape.xml",
        "tsv"                => "csv/real/sheets_export.tsv",
        "mpl2"               => "mpl2/real/subtitle_edit_shape.txt",
        "tmplayer"           => "tmplayer/real/tmplayer_multiline_bom.txt",
        "qttext"             => "qttext/real/subtitle_edit_shape.txt",
        "vobsub"             => "vobsub/text-pal.idx",
        "vtt"                => "vtt/real/w3c_voices.vtt",
        "whisper"            => "whisper/real/openai_whisper_word_timestamps.json",
        "youtube"            => "youtube/real/manual.en.json3",
        "ogm-chapters"       => "chapters/ogm/real/mkvextract_simple.txt",
        "podcast-chapters"   => "chapters/podcast/real/spec_basic_example.json",
        "podcast-transcript" => "podcast/real/spec_word_segments.json",
        "youtube-chapters"   => "chapters/youtube/real/video_description.txt",
    ];

    /** Every conversion gets these options. They prevent the failures for a missing frame rate and for image cues. */
    private const CONVERT_OPTIONS = ["--input-fps", "25", "--output-fps", "25", "--skip-image-cues"];

    /** SCC and MCC output also get these options, because CEA-608 allows 32 characters per line and 4 lines per cue. */
    private const SCC_OPTIONS = ["--structure-split-long", "--structure-wrap", "--structure-max-cpl", "32", "--structure-max-lines", "4"];

    /** Conversions that fail on purpose, as "input>output" => outcome. Pairs that are not listed must succeed. */
    private const EXPECTED_FAILURES = [
        // CEA-608 cannot show the Chinese characters of the LRC fixture.
        "lrc>mcc" => "convert exit 3",
        "lrc>scc" => "convert exit 3",
        // CEA-608 cannot show the section sign of the PAC fixture.
        "pac>mcc" => "convert exit 3",
        "pac>scc" => "convert exit 3",
        // --skip-image-cues leaves no cue, so the plain text output is empty.
        "pgs>txt" => "empty",
        "vobsub>txt" => "empty",
    ];

    /** Bugs that the matrix found, as "input>output" => outcome. Remove a pair when its bug is fixed. */
    private const KNOWN_BUGS = [];

    /** The PGS writer renders no text, so each text input fails with exit 3. */
    private const PGS_OUTPUT_FAILURE = "convert exit 3";


    public function testInputsCoverEveryReadableFormat(): void
    {
        [$readable] = $this->formats();

        $this->assertSame($readable, array_keys(self::INPUTS));
    }


    public function testEveryReadableFormatConvertsToEveryWritableFormat(): void
    {
        [, $writable] = $this->formats();

        $converts = [];
        foreach (self::INPUTS as $input => $fixture) {
            foreach ($writable as $output) {
                $options = in_array($output, ["scc", "mcc"], true) ? [...self::CONVERT_OPTIONS, ...self::SCC_OPTIONS] : self::CONVERT_OPTIONS;
                $converts["$input>$output"] = ["convert", self::FILES . $fixture, "--from", $input, "--to", $output,
                                               "-o", "$this->dir/$input-to-$output.out", ...$options];
            }
        }

        $outcomes  = [];
        $readbacks = [];
        foreach (array_map($this->runCli(...), $converts) as $pair => [$code, $stdout, $stderr]) {
            [$input, $output] = explode(">", $pair);
            $file   = "$this->dir/$input-to-$output.out";
            $stderr = preg_replace('/^.*: dropped the color "[^"]*", because WebVTT has classes for 8 colors only\.\n/m', "", $stderr);
            if (self::hasPhpError($stderr)) {
                $outcomes[$pair] = "convert PHP error: $stderr";
            } elseif ($code !== 0) {
                $outcomes[$pair] = "convert exit $code";
            } elseif ($stdout !== self::FILES . self::INPUTS[$input] . " -> $file\n" || $stderr !== "") {
                $outcomes[$pair] = "convert output: " . $stdout . $stderr;
            } elseif ($output === "txt") {
                $outcomes[$pair] = filesize($file) > 0 ? "ok" : "empty";
            } else {
                $readbacks[$pair] = ["info", $file, "--from", $output, "--input-fps", "25"];
            }
        }
        foreach (array_map($this->runCli(...), $readbacks) as $pair => [$code, , $stderr]) {
            $outcomes[$pair] = match (true) {
                self::hasPhpError($stderr) => "readback PHP error: $stderr",
                $code !== 0                => "readback exit $code",
                $stderr !== ""             => "readback stderr: $stderr",
                default                    => "ok",
            };
        }

        $expected = [];
        foreach (array_keys($converts) as $pair) {
            $pgsOutput = str_ends_with($pair, ">pgs") && !in_array($pair, ["pgs>pgs", "vobsub>pgs"], true);
            $expected[$pair] = self::EXPECTED_FAILURES[$pair] ?? self::KNOWN_BUGS[$pair] ?? ($pgsOutput ? self::PGS_OUTPUT_FAILURE : "ok");
        }
        ksort($expected);
        ksort($outcomes);
        $this->assertSame($expected, $outcomes);
    }


    private static function hasPhpError(string $stderr): bool
    {
        return preg_match('/^(PHP )?(Fatal error|Warning|Notice|Deprecated):/m', $stderr) === 1;
    }


    /**
     * Reads the format table of the formats command.
     *
     * @return array{list<string>, list<string>} the readable and the writable format names, in table order
     */
    private function formats(): array
    {
        [$code, $stdout] = $this->runCli(["formats"]);
        $this->assertSame(0, $code);

        $readable = [];
        $writable = [];
        foreach (array_slice(explode("\n", trim($stdout)), 1) as $line) {
            $columns = preg_split('/\s+/', trim($line));
            [$name, $read, $write] = [$columns[0], $columns[count($columns) - 2], $columns[count($columns) - 1]];
            if ($read === "yes") {
                $readable[] = $name;
            }
            if ($write === "yes") {
                $writable[] = $name;
            }
        }

        return [$readable, $writable];
    }
}
