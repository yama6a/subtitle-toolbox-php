<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Application;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\FileCommand;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Exceptions\ParsingException;

class JsonTypeErrorsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";

    private const INFINITY = "__INFINITY__";


    /**
     * Returns the JSON of the fixture with the value at $path replaced. INFINITY becomes the number 1e400.
     *
     * @param list<int|string> $path
     */
    private static function mutate(string $file, array $path, mixed $value): string
    {
        $data = json_decode(file_get_contents(self::FILES . $file), true, 512, JSON_THROW_ON_ERROR);
        $node = &$data;
        foreach ($path as $key) {
            $node = &$node[$key];
        }
        $node = $value;
        unset($node);

        return str_replace('"' . self::INFINITY . '"', "1e400", json_encode($data, JSON_THROW_ON_ERROR));
    }


    private static function assertThrowsParsing(string $expected, callable $call): void
    {
        try {
            $call();
        } catch (ParsingException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());

            return;
        }
        self::fail("No ParsingException with \"$expected\".");
    }


    public static function infiniteTimes(): array
    {
        return [
            "library JSON"         => [Format::Json, "json/real/own_image_cues.json", ["cues", 0, "start"], "cues[0].start must be a number"],
            "Whisper segment"      => [Format::Whisper, "whisper/real/openai_whisper_german.json", ["segments", 0, "start"], "segments[0].start must be a number"],
            "whisper.cpp offsets"  => [Format::Whisper, "whisper/real/whisper_cpp_oj.json", ["transcription", 0, "offsets", "from"], "transcription[0].offsets.from must be a number"],
            "Deepgram word"        => [Format::Deepgram, "deepgram/real/museum_words_punctuate.json", ["results", "channels", 0, "alternatives", 0, "words", 0, "start"], "words[0].start must be a time"],
            "AssemblyAI word"      => [Format::AssemblyAi, "assemblyai/real/market_words_french.json", ["words", 0, "start"], "words[0].start must be a time"],
            "AWS item"             => [Format::AwsTranscribe, "aws-transcribe/real/weather_items_only.json", ["results", "items", 0, "start_time"], "items[0].start_time must be a time"],
            "Google word"          => [Format::GoogleSpeech, "google-speech/real/restaurant_v2_diarization.json", ["results", 0, "alternatives", 0, "words", 0, "startOffset"], "words[0].startTime must be a time"],
            "Podcasting 2.0"       => [Format::PodcastTranscript, "podcast/real/podcast_transcript_convert_from_srt.json", ["segments", 0, "startTime"], "segments[0].startTime must be a number"],
            "YouTube json3"        => [Format::YouTubeTimedText, "youtube/real/manual.en.json3", ["events", 0, "tStartMs"], "events[0].tStartMs must be a number"],
        ];
    }


    /**
     * @param list<int|string> $path
     */
    #[DataProvider("infiniteTimes")]
    public function testInfiniteTimeThrowsInStrictModeAndSkipsInLenientMode(Format $format, string $file, array $path, string $message): void
    {
        $json = self::mutate($file, $path, self::INFINITY);

        self::assertThrowsParsing($message, fn () => Subtitle::fromString($json, $format));

        $subtitle = Subtitle::fromString($json, $format, new ReadOptions(lenient: true));
        $this->assertStringContainsString($message, $subtitle->getParseWarnings()[0]->message);
        foreach ($subtitle->getCues() as $cue) {
            $this->assertTrue(is_finite($cue->getStart()) && is_finite($cue->getEnd()));
        }
    }


    public function testInfiniteChapterTimesThrow(): void
    {
        foreach (["startTime", "endTime"] as $field) {
            $json = self::mutate("chapters/podcast/real/hosting_export.json", ["chapters", 0, $field], self::INFINITY);

            self::assertThrowsParsing("chapters[0].$field must be a number", fn () => Subtitle::fromString($json, Format::PodcastChapters));
        }
    }


    public function testWhisperSegmentsAsAnObjectThrowParsingException(): void
    {
        $segments      = '{"text": "x", "segments": {"a": {"start": "bad", "end": 1, "text": "Hi"}}}';
        $transcription = '{"transcription": {"a": {"offsets": {"from": "bad", "to": 1}, "text": "Hi"}}}';

        foreach ([$segments, $transcription] as $json) {
            foreach ([false, true] as $lenient) {
                self::assertThrowsParsing("The JSON has no \"segments\" or \"transcription\" list.",
                                          fn () => Subtitle::fromString($json, Format::Whisper, new ReadOptions(lenient: $lenient)));
            }
        }
    }


    public function testYouTubeEventsAsAnObjectThrowParsingException(): void
    {
        self::assertThrowsParsing("The JSON has no \"events\" list.",
                                  fn () => Subtitle::fromString('{"events": {"a": {"tStartMs": "x"}}}', Format::YouTubeTimedText, new ReadOptions(lenient: true)));
    }


    public function testGoogleAlternativesAsAStringThrowsInStrictModeAndSkipsInLenientMode(): void
    {
        $json = self::mutate("google-speech/real/bus_v1_long_running_operation.json", ["response", "results", 0, "alternatives"], "x");

        self::assertThrowsParsing("The field results[0].alternatives must be a list of objects.", fn () => Subtitle::fromString($json, Format::GoogleSpeech));

        $subtitle = Subtitle::fromString($json, Format::GoogleSpeech, new ReadOptions(lenient: true));
        $this->assertSame("The field results[0].alternatives must be a list of objects.", $subtitle->getParseWarnings()[0]->message);
        $this->assertNotSame([], $subtitle->getCues());
    }


    public static function badFileFormatData(): array
    {
        return [
            "ASS section order string" => [Format::Ass, ["ass" => ["sectionOrder" => "x"]], "The field formatData.ass.sectionOrder must be a list or an object."],
            "ASS script info string"   => [Format::Ass, ["ass" => ["scriptInfo" => "x"]], "The field formatData.ass.scriptInfo must be a list or an object."],
            "ASS script type number"   => [Format::Ass, ["ass" => ["scriptInfo" => ["ScriptType" => 4]]], "The field formatData.ass.scriptInfo.ScriptType must be a string."],
            "ASS section lines string" => [Format::Ass, ["ass" => ["sections" => ["Fonts" => "x"]]], "The field formatData.ass.sections.Fonts must be a list or an object."],
            "SCC drop frame string"    => [Format::Scc, ["scc" => ["dropFrame" => "x"]], "The field formatData.scc.dropFrame must be a boolean."],
            "SAMI style number"        => [Format::Sami, ["sami" => ["style" => 7]], "The field formatData.sami.style must be a string."],
            "MicroDVD frame rate"      => [Format::MicroDvd, ["microdvd" => ["frameRate" => "x"]], "The field formatData.microdvd.frameRate must be a finite number."],
            "TTML namespace number"    => [Format::Ttml, ["ttml" => ["namespace" => 7]], "The field formatData.ttml.namespace must be a string."],
            "TTML attributes list"     => [Format::Ttml, ["ttml" => ["attributes" => ["x"]]], "The field formatData.ttml.attributes must be an object whose keys are names, not numbers."],
            "SubViewer header string"  => [Format::SubViewer, ["subviewer" => ["header" => "x"]], "The field formatData.subviewer.header must be a list or an object."],
            "EBU STL GSI string"       => [Format::EbuStl, ["stl" => ["gsi" => "x"]], "The field formatData.stl.gsi must be a list or an object."],
            "EBU STL comment block"    => [Format::EbuStl, ["stl" => ["comments" => [["text" => "x", "blocks" => ["zz"]]]]],
                                           "The field formatData.stl.comments[0].blocks[0] must be a TTI block of 256 hexadecimal digits."],
            "CSV width too large"      => [Format::Csv, ["csv" => ["header" => null, "roles" => ["start" => 0], "width" => 1000000000]],
                                           "The field formatData.csv.width must be an integer from 0 to 1000."],
            "CSV unknown role"         => [Format::Csv, ["csv" => ["header" => null, "roles" => ["start" => 0, "x" => 1], "width" => 2]],
                                           "The field formatData.csv.roles must hold only the keys identifier, start, end, duration, speaker, text."],
            "CSV without roles"        => [Format::Csv, ["csv" => ["header" => null, "width" => 2]], "The field formatData.csv.roles is missing."],
            "CSV pipe delimiter"       => [Format::Csv, ["csv" => ["delimiter" => "|", "header" => null, "roles" => ["start" => 0], "width" => 1]],
                                           'The field formatData.csv.delimiter must be one of ",", ";", "\\t".'],
            "CSV unknown time format"  => [Format::Csv, ["csv" => ["header" => null, "roles" => ["start" => 0], "width" => 1, "timeFormat" => "hh:mm:ss;fff"]],
                                           'The field formatData.csv.timeFormat must be one of "seconds", "hh:mm:ss.mmm", "hh:mm:ss,mmm", "hh:mm:ss:ff", "milliseconds".'],
            "LRC tag number"           => [Format::Lyrics, ["lrc" => ["idTags" => ["ar" => 5]]], "The field formatData.lrc.idTags.ar must be a string."],
            "WebVTT header lines"      => [Format::WebVtt, ["vtt" => ["headerLines" => [5]]], "The field formatData.vtt.headerLines[0] must be a string."],
            "MPSub value list"         => [Format::MpSub, ["mpsub" => ["NOTE" => ["x"]]], "The field formatData.mpsub.NOTE must be a string."],
            "iTT frame rate number"    => [Format::Itt, ["itt" => ["frameRate" => 25]], "The field formatData.itt.frameRate must be a string."],
            "FFmetadata stream tags"   => [Format::FfMetadataChapters, ["ffmeta-chapters" => ["streams" => ["x"]]], "The field formatData.ffmeta-chapters.streams[0] must be a list or an object."],
        ];
    }


    #[DataProvider("badFileFormatData")]
    public function testBadFileFormatDataThrowsInStrictModeAndIsDroppedInLenientMode(Format $format, array $formatData, string $message): void
    {
        $key  = array_key_first($formatData);
        $json = json_encode(["version" => 1, "metadata" => [], "formatData" => $formatData,
                             "cues" => [["start" => 1, "end" => 2, "lines" => ["Hello"]]]], JSON_THROW_ON_ERROR);

        self::assertThrowsParsing($message, fn () => Subtitle::fromString($json, Format::Json));
        self::assertThrowsParsing($message, fn () => Subtitle::fromArray(json_decode($json, true)));

        $subtitle = Subtitle::fromString($json, Format::Json, new ReadOptions(lenient: true));
        $warning  = $subtitle->getParseWarnings()[0];
        $this->assertSame([$message, null, ParseWarningAction::Skipped], [$warning->message, $warning->blockIndex, $warning->action]);
        $this->assertSame([json_encode([$key => $formatData[$key]])], $warning->block);
        $this->assertSame([], $subtitle->findFormatData($key));
        $this->assertCount(1, $subtitle->getCues());
        $this->assertNotSame("", $subtitle->toString($format, $format === Format::MicroDvd || $format === Format::Itt
            ? new WriteOptions(formats: [$format === Format::Itt ? new Formatters\Options\IttWriteOptions(25) : new Formatters\Options\MicroDvdWriteOptions(25)])
            : new WriteOptions()));
    }


    public static function badCueFormatData(): array
    {
        return [
            "ASS fields string"         => [["ass" => ["fields" => "x"]], "The field cues[0].formatData.ass.fields must be an object."],
            "ASS field number"          => [["ass" => ["fields" => ["Name" => 7]]], "The field cues[0].formatData.ass.fields.Name must be a string."],
            "SAMI paragraph"            => [["sami" => ["paragraphs" => [["attributes" => [], "html" => 5]]]], "The field cues[0].formatData.sami.paragraphs[0].html must be a string."],
            "MicroDVD line without codes" => [["microdvd" => ["lines" => [["color" => null, "tags" => []]]]], "The field cues[0].formatData.microdvd.lines[0].codes is missing."],
            "TTML attribute number"     => [["ttml" => ["attributes" => ["region" => []]]], "The field cues[0].formatData.ttml.attributes.region must be a string."],
            "EBU STL group string"      => [["stl" => ["subtitleGroupNumber" => "1"]], "The field cues[0].formatData.stl.subtitleGroupNumber must be an integer."],
            "SubRip coordinates"        => [["srt" => ["coordinates" => ["x1" => 1]]], "The field cues[0].formatData.srt.coordinates.x2 is missing."],
            "FFmetadata time base"      => [["ffmeta-chapters" => ["timeBase" => "0/1000"]], "The field cues[0].formatData.ffmeta-chapters.timeBase must be a time base such as \"1/1000\"."],
            "image size string"         => [["image" => ["width" => "2"]], "The field cues[0].formatData.image.width must be an integer."],
            "WebVTT setting number"     => [["vtt" => ["line" => 0]], "The field cues[0].formatData.vtt.line must be a string."],
        ];
    }


    #[DataProvider("badCueFormatData")]
    public function testBadCueFormatDataThrowsInStrictModeAndSkipsTheCueInLenientMode(array $formatData, string $message): void
    {
        $data = json_decode(file_get_contents(self::FILES . "json/real/own_aegisub.json"), true);
        $data["cues"][0]["formatData"] = $formatData;
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertThrowsParsing($message, fn () => Subtitle::fromString($json, Format::Json));
        self::assertThrowsParsing($message, fn () => Subtitle::fromArray($data));

        $subtitle = Subtitle::fromString($json, Format::Json, new ReadOptions(lenient: true));
        $this->assertSame([$message, 0], [$subtitle->getParseWarnings()[0]->message, $subtitle->getParseWarnings()[0]->blockIndex]);
        $this->assertCount(count($data["cues"]) - 1, $subtitle->getCues());
    }


    public function testLenientJsonDropsABadMetadataFieldAndABadComment(): void
    {
        $json = json_encode(["version" => 1, "metadata" => ["title" => 5, "language" => "en"],
                             "comments" => [["text" => 5, "beforeCueIndex" => 0], new Comment("Kept", 1)],
                             "cues" => [["start" => 1, "end" => 2, "lines" => ["Hello"]], ["start" => 3, "end" => 4, "lines" => ["Bye"]]]]);

        $subtitle = Subtitle::fromString($json, Format::Json, new ReadOptions(lenient: true));

        $this->assertSame(["language" => "en"], $subtitle->getAllMetadata());
        $this->assertEquals([new Comment("Kept", 1)], $subtitle->getComments());
        $this->assertSame(["The field metadata.title must be a string.", "The field comments[0].text must be a string."],
                          array_map(fn (ParseWarning $warning): string => $warning->message, $subtitle->getParseWarnings()));
    }


    /**
     * @param list<Cli\Command> $commands
     *
     * @return array{int, string}
     */
    private static function runWith(array $commands, array $arguments): array
    {
        $streams     = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        $application = new Application(...$streams);
        (new \ReflectionProperty(Application::class, "commands"))->setValue($application, $commands);
        $code = $application->run(["subtitle-toolbox", ...$arguments]);
        rewind($streams[2]);

        return [$code, stream_get_contents($streams[2])];
    }


    public function testCliReportsAWrongFormatDataTypeAsAParsingError(): void
    {
        $path = self::FILES . "json/own_scc_drop_frame_string.json";

        [$code, $error] = self::runWith([new Cli\ConvertCommand()], ["convert", $path, "--to", "scc"]);

        $this->assertSame([3, "$path: ParsingException (Error #100): The field formatData.scc.dropFrame must be a boolean.\n" . Cli\FileCommand::LENIENT_HINT . "\n"], [$code, $error]);
    }


    public function testCliPrintsAnErrorOfAFileRunWithItsClassAndGoesOn(): void
    {
        $command = new class extends FileCommand {
            public function name(): string
            {
                return "info";
            }


            public function summary(): string
            {
                return "";
            }


            protected function usageLines(): array
            {
                return [];
            }


            protected function commandOptions(): array
            {
                return [];
            }


            protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
            {
                throw new \TypeError("Broken value");
            }
        };
        $first  = self::FILES . "profanity/radio.vtt";
        $second = self::FILES . "profanity/keys.srt";

        [$code, $error] = self::runWith([$command], ["info", $first, $second, "--keep-going"]);

        $this->assertSame(3, $code);
        $this->assertStringStartsWith("$first: TypeError: Broken value\n$second: TypeError: Broken value\n", $error);
    }


    public function testCliPrintsAnErrorOutsideAFileRunWithItsClass(): void
    {
        $command = new class extends Command {
            public function name(): string
            {
                return "formats";
            }


            public function summary(): string
            {
                return "";
            }


            protected function usageLines(): array
            {
                return [];
            }


            public function options(): array
            {
                return [];
            }


            public function execute(Arguments $arguments, Console $console): int
            {
                return 0;
            }


            public function run(array $arguments, Console $console): int
            {
                throw new \Error("Broken state");
            }
        };

        $this->assertSame([3, "Error: Error: Broken state\n"], self::runWith([$command], ["formats"]));
    }
}
