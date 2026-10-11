<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class WhisperJsonRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/whisper/real/";


    public static function realFiles(): array
    {
        return [
            "openai-whisper with word timestamps" => [
                "openai_whisper_word_timestamps.json",
                "en",
                3,
                [0.0, 2.2, "<00:00:00.000>The <00:00:00.240>bakery <00:00:00.700>opens <00:00:01.100>at <00:00:01.320>seven."],
                [4.62, 7.36, "<00:00:04.620>Fresh <00:00:05.100>bread <00:00:05.480>is <00:00:05.740>ready <00:00:06.200>by <00:00:06.500>eight."],
            ],
            "openai-whisper in German" => [
                "openai_whisper_german.json",
                "de",
                3,
                [0.0, 3.0, "Der Zug fährt um neun Uhr ab."],
                [8.16, 13.12, "Der nächste Halt ist der Hauptbahnhof."],
            ],
            "OpenAI API verbose_json with words" => [
                "openai_api_verbose_json_words.json",
                "en",
                3,
                [0.0, 3.32, "<00:00:00.000>The <00:00:00.240>beach <00:00:00.710>was <00:00:00.980>quiet <00:00:01.600>in " .
                            "<00:00:01.740>the <00:00:01.860>morning."],
                [5.62, 8.47, "<00:00:05.620>The <00:00:05.800>tide <00:00:06.120>was <00:00:06.360>low <00:00:06.900>at <00:00:07.040>noon."],
            ],
            "OpenAI API verbose_json in French" => [
                "openai_api_verbose_json_french.json",
                "fr",
                2,
                [0.0, 2.84, "La boulangerie ouvre à sept heures."],
                [2.84, 5.52, "Le pain est prêt à huit heures."],
            ],
            "whisper-ctranslate2 with word timestamps" => [
                "faster_whisper_ctranslate2_words.json",
                "en",
                3,
                [0.0, 2.48, "<00:00:00.000>It <00:00:00.200>will <00:00:00.400>rain <00:00:00.840>in <00:00:01.020>the <00:00:01.160>afternoon."],
                [5.6, 8.0, "<00:00:05.600>The <00:00:05.820>wind <00:00:06.160>is <00:00:06.400>calm <00:00:06.880>tonight."],
            ],
            "whisper-ctranslate2 without word timestamps" => [
                "faster_whisper_ctranslate2_segments.json",
                "en",
                2,
                [0.0, 3.2, "Snow is likely on the hills."],
                [3.2, 6.0, "Roads may be icy in the morning."],
            ],
            "WhisperX with speakers" => [
                "whisperx_diarize.json",
                "en",
                3,
                [0.031, 2.412, "<00:00:00.031>The <00:00:00.232>market <00:00:00.714>opens <00:00:01.136>on <00:00:01.317>Saturday."],
                [5.338, 6.12, "15."],
            ],
            "whisper.cpp -oj" => [
                "whisper_cpp_oj.json",
                "en",
                3,
                [0.0, 2.5, "The train to the coast leaves from platform four."],
                [5.2, 8.0, "The next stop is the harbour."],
            ],
            "whisper.cpp -ojf" => [
                "whisper_cpp_ojf.json",
                "en",
                2,
                [0.0, 2.12, "<00:00:00.000>The <00:00:00.320>museum <00:00:00.900>opens <00:00:01.320>at <00:00:01.500>ten."],
                [2.12, 4.6, "<00:00:02.120>Children <00:00:02.900>enter <00:00:03.440>for <00:00:03.700>free."],
            ],
            "whisper.cpp -ojf with split UTF-8 tokens" => [
                "whisper_cpp_ojf_split_utf8.json",
                "ja",
                1,
                [0.0, 1.8, "電車が来ます。"],
                [0.0, 1.8, "電車が来ます。"],
            ],
        ];
    }


    private static function parse(string $fileName): Subtitle
    {
        $parser = new WhisperJsonParser();

        return $parser->parse(file_get_contents(self::DIR . $fileName), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, string $language, int $cueCount, array $firstCue, array $lastCue): void
    {
        $subtitle = self::parse($fileName);
        $cues     = $subtitle->getCues();

        $this->assertSame($language, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileKeepsItsWordTimestampsThroughWebVtt(string $fileName): void
    {
        $subtitle = self::parse($fileName);
        $vtt      = (new WebVttParser())->parse($subtitle->toString(Format::WebVtt), new ReadOptions());

        $this->assertSame(
            array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues()),
            array_map(fn ($cue) => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $vtt->getCues())
        );
    }


    public function testOpenAiWhisperFileKeepsTheWordProbabilities(): void
    {
        $cue = self::parse("openai_whisper_word_timestamps.json")->getCues()[1];

        $this->assertSame(-0.21312345678901234, $cue->findFormatData("whisper")["avg_logprob"]);
        $this->assertSame(["word" => " caf\u{e9}", "start" => 2.42, "end" => 2.86, "probability" => 0.7412345409393311],
                          $cue->findFormatData("whisper")["words"][1]);
    }


    public function testApiFileKeepsTheDurationAndSplitsTheTopLevelWords(): void
    {
        $subtitle = self::parse("openai_api_verbose_json_words.json");

        $this->assertSame(8.470000267028809, $subtitle->findFormatData("whisper")["duration"]);
        $this->assertSame("english", $subtitle->findFormatData("whisper")["language"]);
        $this->assertSame([7, 2, 6], array_map(fn ($cue) => count($cue->findFormatData("whisper")["words"]), $subtitle->getCues()));
    }


    public function testWhisperCppFileKeepsTheModelAndTheTokens(): void
    {
        $subtitle = self::parse("whisper_cpp_ojf.json");
        $tokens   = $subtitle->getCues()[0]->findFormatData("whisper")["tokens"];

        $this->assertSame("base", $subtitle->findFormatData("whisper")["model"]["type"]);
        $this->assertSame("models/ggml-base.bin", $subtitle->findFormatData("whisper")["params"]["model"]);
        $this->assertSame(["[_BEG_]", " The"], [$tokens[0]["text"], $tokens[1]["text"]]);
        $this->assertSame(0.987654, $tokens[1]["p"]);
    }


    public function testWhisperXFileKeepsTheSpeakersAndTheWordWithoutTimes(): void
    {
        $subtitle = self::parse("whisperx_diarize.json");
        $last     = $subtitle->getCues()[2]->findFormatData("whisper");

        $this->assertSame(["SPEAKER_00", "SPEAKER_01", "SPEAKER_01"],
                          array_map(fn ($cue) => $cue->findFormatData("whisper")["speaker"], $subtitle->getCues()));
        $this->assertSame([["word" => "15.", "speaker" => "SPEAKER_01"]], $last["words"]);
        $this->assertSame(["language" => "en"], $subtitle->findFormatData("whisper"));
    }
}
