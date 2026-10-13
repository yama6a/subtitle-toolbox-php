<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Exceptions\UnsupportedFormatException;

class FormatDetectorTest extends TestCase
{
    private const DIR = __DIR__ . "/files/";

    private const FORMATS = [
        "ass"      => Format::Ass,
        "html"     => Format::HtmlTranscript,
        "json"     => Format::Json,
        "lrc"      => Format::Lyrics,
        "microdvd" => Format::MicroDvd,
        "mpl2"     => Format::Mpl2,
        "mpsub"    => Format::MpSub,
        "podcast"  => Format::PodcastTranscript,
        "qttext"   => Format::QuickTimeText,
        "sami"     => Format::Sami,
        "sbv"      => Format::Sbv,
        "spruce-stl" => Format::SpruceStl,
        "srt"      => Format::SubRip,
        "subviewer" => Format::SubViewer,
        "stl"      => Format::EbuStl,
        "tmplayer" => Format::TmPlayer,
        "ttml"     => Format::Ttml,
        "vtt"      => Format::WebVtt,
        "whisper"  => Format::Whisper,
        "youtube"  => Format::YouTubeTimedText,
    ];

    // Chapters and cloud speech-to-text JSON load only with an explicit format.
    private const NOT_DETECTED_DIRECTORIES = ["chapters", "aws-transcribe", "deepgram", "assemblyai", "google-speech"];

    // Headerless CSV starts with hh:mm:ss:ff frame timecodes, which are neither TMPlayer nor Spruce STL.
    private const FRAME_TIMECODE_FIXTURES = [
        "csv/real/headerless_frame_times.csv",
    ];

    // These fixtures break their own format on purpose, so their parser rejects them.
    private const BROKEN_FIXTURES = [
        "sbv/missing_milli_digits.sbv"  => null,
        "sbv/srt_timestamps.sbv"        => null,
        "vtt/missing_webvtt_header.vtt" => Format::SubRip,
    ];


    public static function fixtures(): array
    {
        $fixtures = [];
        foreach (self::FORMATS as $directory => $format) {
            foreach (array_merge(glob(self::DIR . "$directory/*.*"), glob(self::DIR . "$directory/real/*.*")) as $path) {
                $name = substr($path, strlen(self::DIR));
                if (!str_ends_with($path, ".md") && !str_ends_with($path, ".php") && !array_key_exists($name, self::BROKEN_FIXTURES)) {
                    $fixtures[$name] = [$name, $format];
                }
            }
        }

        return $fixtures;
    }


    public static function realFiles(): array
    {
        return array_filter(self::fixtures(), fn (array $fixture): bool => str_contains($fixture[0], "/real/"));
    }


    public static function brokenFixtures(): array
    {
        return array_map(null, array_keys(self::BROKEN_FIXTURES), array_values(self::BROKEN_FIXTURES));
    }


    #[DataProvider("fixtures")]
    public function testDetectsTheFormatOfEveryFixture(string $file, Format $format): void
    {
        $this->assertSame($format, FormatDetector::detect(file_get_contents(self::DIR . $file)));
    }


    #[DataProvider("brokenFixtures")]
    public function testDetectsBrokenFixturesByTheirShape(string $file, ?Format $format): void
    {
        $this->assertSame($format, FormatDetector::detect(file_get_contents(self::DIR . $file)));
    }


    #[DataProvider("realFiles")]
    public function testAutoDetectionGivesTheSameCuesAsTheDetectedFormat(string $file, Format $format): void
    {
        $content = file_get_contents(self::DIR . $file);
        if ($format === Format::MicroDvd && !str_starts_with(ltrim($content), "{1}{1}")) {
            $this->expectException(ParsingException::class);
            $this->expectExceptionMessage("The frame rate is unknown.");
        }

        // The parsers do not detect CP949, so this file needs the encoding.
        $options  = $file === "sami/real/own_korean_cp949.smi" ? new ReadOptions(TextEncoding::Cp949) : new ReadOptions();
        $detected = Subtitle::fromString($content, options: $options);
        $explicit = Subtitle::fromString($content, $format, $options);

        $this->assertEquals($explicit->getCues(), $detected->getCues());
    }


    public static function signatures(): array
    {
        return [
            "WebVTT with title"          => ["WEBVTT - Weather report\n\n00:01.000 --> 00:02.000\nRain\n", Format::WebVtt],
            "WebVTT header only"         => ["WEBVTT", Format::WebVtt],
            "TTML with XML declaration"  => ["<?xml version=\"1.0\"?>\n<!-- made by hand -->\n<tt xmlns=\"http://www.w3.org/ns/ttml\"/>", Format::Ttml],
            "TTML with prefixed root"    => ["<tt:tt xmlns:tt=\"http://www.w3.org/ns/ttml\"></tt:tt>", Format::Ttml],
            "SAMI in lower case"         => ["<sami><body></body></sami>", Format::Sami],
            "SSA header"                 => ["[Script Info]\r\nScriptType: v4.00\r\n", Format::Ass],
            "MPSub with FORMAT first"    => ["FORMAT=25\n\n0 50\nHello\n", Format::MpSub],
            "MPSub with header comment"  => ["TITLE=Bakery\nFORMAT=TIME   # seconds\n\n1 2\nHello\n", Format::MpSub],
            "MicroDVD with fps line"     => ["{1}{1}25\n{24}{72}Hello\n", Format::MicroDvd],
            "MicroDVD without end frame" => ["{24}{}Hello\n", Format::MicroDvd],
            "SubRip with dot"            => ["1\n00:00:01.000 --> 00:00:04.000\nHello\n", Format::SubRip],
            "SubRip without cue number"  => ["00:00:01,000 --> 00:00:04,000\nHello\n", Format::SubRip],
            "SubRip after five lines"    => ["a\nb\nc\nd\n\n 1\n00:00:01,000 --> 00:00:04,000\nHello\n", Format::SubRip],
            "Spruce STL"                 => ["00:00:01:10 , 00:00:03:10 , The bakery opens at seven.\n", Format::SpruceStl],
            "Spruce STL without spaces"  => ["00:00:01:10,00:00:03:10,The bakery opens at seven.\n", Format::SpruceStl],
            "Spruce STL with tabs"       => ["\$FadeOut\t=\t0\n \n00:00:01:10\t,\t00:00:03:10\t,\tBakery\n", Format::SpruceStl],
            "Spruce STL quote after comment" => ["//Subtitles\n00:00:01:10 , 00:00:03:10 , \"Bakery\"\n", Format::SpruceStl],
            "SBV"                        => ["0:00:01.500,0:00:04.000\nHello\n", Format::Sbv],
            "SubViewer 2 information"    => ["[INFORMATION]\r\n[TITLE]Bakery\r\n[END INFORMATION]\r\n", Format::SubViewer],
            "SubViewer 2 timing line"    => ["00:00:01.50,00:00:04.00\nHello[br]world\n", Format::SubViewer],
            "SubViewer 2 after [SUBTITLE]" => ["[SUBTITLE]\n[COLF]&HFFFFFF,[SIZE]18\n00:00:01.50,00:00:04.00\nHello\n", Format::SubViewer],
            "SubViewer 1"                => ["[TITLE]\nBakery\n[DELAY]\n0\n******** START SCRIPT ********\n[00:00:01]\nHello\n", Format::SubViewer],
            "SBV with three digits"      => ["0:00:01.500,0:00:04.000\nHello[br]world\n", Format::Sbv],
            "SBV with two hour digits"   => ["00:00:01.500,00:00:04.000\nHello\n", Format::Sbv],
            "LRC with ID tag"            => ["[ti:Morning Train]\n[00:12.00]Hello\n", Format::Lyrics],
            "LRC without fraction"       => ["[00:12]Hello\n", Format::Lyrics],
            "JSON with cues first"       => ["{\"cues\": [], \"metadata\": {\"title\": \"version\"}, \"version\": 1}", Format::Json],
            "JSON with spaces"           => ["{\n  \"version\" : 1 ,\n  \"cues\" : [ ]\n}\n", Format::Json],
            "EBU STL at 30 fps"          => [str_pad("865STL30.011", 1024), Format::EbuStl],
            "JSON with a segments key"   => ["{\"version\": 1, \"formatData\": {\"x\": {\"segments\": [1]}}, \"cues\": []}", Format::Json],
            "Whisper JSON"               => ["{\"text\": \" Hello\", \"segments\": [{\"id\": 0, \"start\": 0.0, \"end\": 2.0, \"text\": \" Hello\"}], \"language\": \"en\"}",
                                             Format::Whisper],
            "whisper.cpp JSON"           => ["{\n\t\"systeminfo\": \"\",\n\t\"transcription\": [\n\t]\n}\n", Format::Whisper],
            "Whisper JSON with words"    => ["{\"segments\": [], \"words\": [{\"word\": \"Hi\", \"start\": 0, \"end\": 1}], " .
                                             "\"results\": [{\"x\": 1}]}", Format::Whisper],
            "YouTube json3"              => ["{\"wireMagic\": \"pb3\", \"events\": [ {\"tStartMs\": 0, \"id\": 1}, {\"tStartMs\": 0, \"segs\": []} ]}",
                                             Format::YouTubeTimedText],
            "Whisper JSON with a BOM"    => ["\xEF\xBB\xBF\r\n{\"text\": \"\", \"segments\": []}", Format::Whisper],
            "Whisper JSON with a cues key" => ["{\"version\": \"1\", \"cues\": [], \"segments\": []}", Format::Whisper],
            "YouTube srv3"               => ["<?xml version=\"1.0\" encoding=\"utf-8\" ?><timedtext format=\"3\">\n<body>\n</body>\n</timedtext>\n",
                                             Format::YouTubeTimedText],
            "YouTube srv1"               => ["<transcript><text start=\"1.2\" dur=\"2.3\">Hello</text></transcript>", Format::YouTubeTimedText],
            "JSON with an events list"   => ["{\"version\": 1, \"formatData\": {\"x\": {\"events\": [{\"tStartMs\": 0}]}}, \"cues\": []}", Format::Json],
            "MPL2"                       => ["[10][25]Hello|/world\n", Format::Mpl2],
            "MicroDVD next to MPL2"      => ["{10}{25}Hello|world\n", Format::MicroDvd],
            "LRC next to MPL2"           => ["[00:01.00]Hello\n[00:02.50]world\n", Format::Lyrics],
            "LRC with minutes only"      => ["[00:01]Hello\n", Format::Lyrics],
            "TMPlayer"                   => ["00:00:01:Hello|world\n", Format::TmPlayer],
            "TMPlayer+ with equals sign" => ["0:00:01=Hello\n", Format::TmPlayer],
            "TMPlayer+ with line numbers" => ["00:00:01,1=Hello\n00:00:01,2=world\n", Format::TmPlayer],
            "TMPlayer with a number"     => ["00:00:01:5 trains leave\n", Format::TmPlayer],
            "SBV next to TMPlayer"       => ["0:00:01.000,0:00:02.000\nHello\n", Format::Sbv],
            "JSON with a chapters list"  => ["{\"version\": 1, \"formatData\": {\"chapters\": {\"chapters\": []}}, \"cues\": []}", Format::Json],
            "Podcasting 2.0 JSON"        => ["{\"version\": \"1.0.0\", \"segments\": [{\"speaker\": \"Anna\", \"startTime\": 0.5, \"body\": \"I\"}]}",
                                             Format::PodcastTranscript],
            "Podcasting 2.0 JSON body first" => ["{\"segments\":[{\"body\":\"Hi\",\"endTime\":1,\"startTime\":0}]}", Format::PodcastTranscript],
            "JSON with podcast segments" => ["{\"version\": 1, \"formatData\": {\"x\": {\"segments\": [{\"startTime\": 0, \"body\": \"\"}]}}, \"cues\": []}",
                                             Format::Json],
            "Whisper JSON with a body"   => ["{\"segments\": [{\"start\": 0.0, \"end\": 2.0, \"text\": \" Hello\", \"body\": 1}]}", Format::Whisper],
            "Podcast transcript, not chapters" => ["{\"version\": \"1.0.0\", \"chapters\": [], \"segments\": [{\"startTime\": 0, \"body\": \"Hi\"}]}",
                                             Format::PodcastTranscript],
            "Podcasting 2.0 HTML"        => ["<cite>Anna:</cite>\n<time>0:00</time>\n<p>Hello</p>\n", Format::HtmlTranscript],
            "HTML document with time"    => ["<!DOCTYPE html>\n<html><body>\n<CITE>Anna:</CITE>\n<TIME>0:00</TIME><p>Hi</p></body></html>", Format::HtmlTranscript],
        ];
    }


    public static function subFiles(): array
    {
        $files = [];
        foreach (["microdvd" => Format::MicroDvd, "mpsub" => Format::MpSub, "subviewer" => Format::SubViewer] as $directory => $format) {
            foreach (glob(self::DIR . "$directory/real/*.sub") as $path) {
                $files[basename($path)] = [$path, $format];
            }
        }

        return $files;
    }


    #[DataProvider("subFiles")]
    public function testSubFilesDetectByTheirContent(string $path, Format $format): void
    {
        $this->assertSame($format, FormatDetector::detect(file_get_contents($path)));
    }


    #[DataProvider("signatures")]
    public function testDetectsSignatures(string $content, Format $format): void
    {
        $this->assertSame($format, FormatDetector::detect($content));
    }


    public function testIgnoresUtf8BomAndLeadingBlankLines(): void
    {
        $content = "\xEF\xBB\xBF\r\n\r\n  \n1\r\n00:00:01,000 --> 00:00:04,000\r\nHello\r\n";

        $this->assertSame(Format::SubRip, FormatDetector::detect($content));
        $this->assertCount(1, Subtitle::fromString($content)->getCues());
    }


    public static function subRipWithoutLeadingCueNumber(): array
    {
        return [
            "no cue numbers"            => ["no_cue_numbers.srt", 3],
            "text before the first cue" => ["text_before_first_cue.srt", 2],
        ];
    }


    #[DataProvider("subRipWithoutLeadingCueNumber")]
    public function testDetectsSubRipWithoutALeadingCueNumber(string $file, int $cueCount): void
    {
        $content = file_get_contents(self::DIR . "lenient/$file");

        $this->assertSame(Format::SubRip, FormatDetector::detect($content));
        $this->assertCount($cueCount, Subtitle::fromString($content, options: new ReadOptions(lenient: true))->getCues());
    }


    public static function unknownContent(): array
    {
        return [
            "empty string"             => [""],
            "blank lines"              => ["\n\r\n  \n"],
            "BOM only"                 => ["\xEF\xBB\xBF"],
            "plain text"               => ["The train to the coast leaves at 7:15.\nBring a coat.\n"],
            "JSON with version string" => ["{\"version\": \"1\", \"cues\": []}"],
            "JSON with cues as text"   => ["{\"version\": 1, \"text\": \"\\\"cues\\\": [\"}"],
            "JSON"                     => ["{\"cues\": [{\"start\": 1, \"end\": 2, \"text\": \"Hello\"}]}"],
            "HTML"                     => ["<!DOCTYPE html>\n<html><head><title>Bakery</title></head><body><p>Hello</p></body></html>"],
            "XHTML"                    => ["<?xml version=\"1.0\"?>\n<html xmlns=\"http://www.w3.org/1999/xhtml\"></html>"],
            "INI section with colon"   => ["[server:main]\nport=80\n"],
            "key value lines"          => ["TITLE=Bakery\nAUTHOR=Jane Doe\n"],
            "number without timing"    => ["1\nHello\n"],
            "SubRip after six lines"   => ["a\nb\nc\nd\ne\nf\n1\n00:00:01,000 --> 00:00:04,000\nHello\n"],
            "WebVTT timing without header or number" => ["00:00:01.000 --> 00:00:04.000\nHello\n"],
            "WebVTT after text without header" => ["Converted online\n\n1\n00:00:01.000 --> 00:00:04.000\nHello\n"],
            "SubRip timing after text without number" => ["Converted online\n\n00:00:01,000 --> 00:00:04,000\nHello\n"],
            "WEBVTT inside a word"     => ["WEBVTTX\n"],
            "EBU STL at 24 fps"        => [str_pad("850STL24.011", 1024)],
            "events without tStartMs"  => ["{\"events\": [{\"start\": 1}]}"],
            "events as text"           => ["{\"text\": \"\\\"events\\\": [{\\\"tStartMs\\\": 1}]\"}"],
            "timedtext inside a word"  => ["<timedtextx/>"],
            "MPL2 with a letter"       => ["[1a][25]Hello\n"],
            "clock time in text"       => ["10:30 is the time.\n"],
            "bracketed time in text"   => ["Meet at [10:30] by the pier.\n[ferry] leaves at noon\n"],
            "time tag with 4 minute digits" => ["[1234:45.67] Hello\n"],
            "time tag with 61 seconds" => ["[00:61.00] Hello\n"],
            "chapters without version" => ["{\"chapters\": [{\"startTime\": 0}]}"],
            "chapters as text"         => ["{\"version\": \"1\", \"text\": \"\\\"chapters\\\": [\"}"],
            "OGM without name line"    => ["CHAPTER01=00:00:00.000\nCHAPTER02=00:01:00.000\n"],
            "YouTube chapters"         => ["0:00 Intro\n2:48 Hearing aids\n4:20 Progress report\n"],
            "HTML without time"        => ["<cite>Anna:</cite>\n<p>Hello</p>\n"],
            "cite and time as text"    => ["Hello <cite> and <time>\n"],
            "invalid JSON"             => ["{\"segments\": [{\"start\": 0,"],
            "JSON with a bad escape"   => ["{\"text\": \"\\x\", \"segments\": []}"],
            "JSON list"                => ["[{\"tStartMs\": 0}]"],
            "segments as an object"    => ["{\"segments\": {\"0\": {\"start\": 0}}}"],
            "first event without tStartMs" => ["{\"wireMagic\": \"pb3\", \"events\": [{\"id\": 1}, {\"tStartMs\": 0, \"segs\": []}]}"],
            "nested segments"          => ["{\"results\": {\"segments\": [{\"startTime\": 0, \"body\": \"Hi\"}]}}"],
            "Amazon Transcribe"          => ["{\"jobName\": \"a\", \"results\": {\"transcripts\": [{\"transcript\": \"\"}], \"items\": []}}"],
            "Amazon Transcribe speakers" => ["{\"results\": {\"speaker_labels\": {\"segments\": []}, \"transcripts\": []}}"],
            "Deepgram"                   => ["{\"metadata\": {\"channels\": 1}, \"results\": {\"channels\": [{\"alternatives\": []}]}}"],
            "Deepgram with topics"       => ["{\"results\": {\"topics\": {\"segments\": []}, \"channels\": [{\"detected_language\": \"en\", " .
                                             "\"alternatives\": []}]}}"],
            "AssemblyAI"                 => ["{\"id\": \"x\", \"audio_url\": \"https://example.com/a.mp3\", \"words\": null}"],
            "AssemblyAI words only"      => ["{\"words\": [{\"text\": \"Hi\", \"start\": 250, \"end\": 650}]}"],
            "Google V1"                  => ["{\"results\": [{\"alternatives\": [{\"transcript\": \"hi\"}], \"resultEndTime\": \"1s\"}]}"],
            "Google V2 end first"        => ["{\"results\": [{\"resultEndOffset\": \"1s\", \"alternatives\": []}], \"metadata\": {}}"],
            "Podcast chapters first"     => ["{\"chapters\": [], \"version\": \"1.2.0\"}"],
            "FFmpeg metadata"            => [";FFMETADATA1\ntitle=Meetup\n"],
            "OGM with blank line"        => ["CHAPTER00 = 00:00:00.000\r\n\r\nCHAPTER00NAME=Intro\r\n"],
            "Headerless CSV with quotes" => ["00:00:01:10,00:00:03:10,\"The bakery opens at seven.\"\n"],
            "two frame timecodes"        => ["00:00:01:10:00:00:03:10 Hello\n"],
            "Podcast chapters, not a transcript" => ["{\"version\": \"1.2.0\", \"chapters\": [{\"startTime\": 0, \"title\": \"Intro\"}]}"],
        ];
    }


    #[DataProvider("unknownContent")]
    public function testReturnsNullForUnknownContent(string $content): void
    {
        $this->assertNull(FormatDetector::detect($content));
    }


    public static function frameTimecodeFixtures(): array
    {
        return array_combine(self::FRAME_TIMECODE_FIXTURES, array_map(fn (string $name): array => [$name], self::FRAME_TIMECODE_FIXTURES));
    }


    #[DataProvider("frameTimecodeFixtures")]
    public function testReturnsNullForFrameTimecodeFixtures(string $file): void
    {
        $this->assertNull(FormatDetector::detect(file_get_contents(self::DIR . $file)));
    }


    public static function notDetectedFixtures(): array
    {
        $fixtures = [];
        foreach (self::NOT_DETECTED_DIRECTORIES as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                // A conversion golden "<file>.to.<format>" holds another format.
                if ($file->getExtension() !== "md" && !str_contains($file->getFilename(), ".to.")) {
                    $name            = substr($file->getPathname(), strlen(self::DIR));
                    $fixtures[$name] = [$name];
                }
            }
        }
        ksort($fixtures);

        return $fixtures;
    }


    #[DataProvider("notDetectedFixtures")]
    public function testReturnsNullForChaptersAndCloudSpeechJson(string $file): void
    {
        $this->assertNull(Format::detect(file_get_contents(self::DIR . $file)));
    }


    public function testNeverReturnsAFormatThatIsNotAutoDetected(): void
    {
        $contents = array_merge(array_column(self::signatures(), 0), array_column(self::unknownContent(), 0));
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $contents[] = file_get_contents($file->getPathname());
        }

        foreach ($contents as $content) {
            $format = Format::detect($content);
            $this->assertTrue($format === null || $format->isAutoDetected(), $format?->value ?? "");
        }
    }


    public function testDetectsWhisperCppJsonWithASplitUtf8Character(): void
    {
        $this->assertSame(Format::Whisper, Format::detect(file_get_contents(self::DIR . "whisper/real/whisper_cpp_ojf_split_utf8.json")));
    }


    public function testSignaturesHoldNoJsonPattern(): void
    {
        $signatures = (new \ReflectionClassConstant(FormatDetector::class, "SIGNATURES"))->getValue();

        foreach ($signatures as $format => $pattern) {
            $this->assertStringNotContainsString('"', $pattern, $format);
        }
    }


    public function testAutoDetectionThrowsForUnknownContent(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage("Format detection found no subtitle format.");

        Subtitle::fromString("Just some text.");
    }


    public function testDetectFormatAppliesTheIttAndExtensionRulesOfLoadWithoutFormat(): void
    {
        $ttml = "<?xml version=\"1.0\"?>\n<tt xmlns=\"http://www.w3.org/ns/ttml\"><body><div>" .
                "<p begin=\"00:00:01.000\" end=\"00:00:02.000\">Hi</p></div></body></tt>";

        $this->assertSame(Format::Ttml, SubtitleReader::detectFormat($ttml));
        $this->assertSame(Format::Itt, SubtitleReader::detectFormat($ttml, "film.itt"));
        $this->assertSame(Format::SubRip, SubtitleReader::detectFormat("plain words", "film.srt"));
        $this->assertNull(SubtitleReader::detectFormat("plain words", "film.json"));
        $this->assertNull(SubtitleReader::detectFormat("plain words"));
    }
}
