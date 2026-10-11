<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class CloudSpeechRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/";


    public static function realFiles(): array
    {
        return [
            "Amazon Transcribe with audio segments" => [
                AwsTranscribeParser::class,
                "aws-transcribe/real/library_speakers_language_id.json",
                "en-GB",
                3,
                [0.52, 2.3, "<v spk_0>The library opens at nine."],
                [5.78, 8.6, "<v spk_0>Yes, use the box by the door."],
            ],
            "Amazon Transcribe items only" => [
                AwsTranscribeParser::class,
                "aws-transcribe/real/weather_items_only.json",
                null,
                5,
                [0.31, 3.43, "Rain is expected in the north this afternoon."],
                [13.27, 14.35, "southern coast tonight."],
            ],
            "Deepgram utterances" => [
                DeepgramParser::class,
                "deepgram/real/pool_utterances_diarize.json",
                null,
                3,
                [0.24, 3.18, "<v 0>Good morning, is the pool open today?"],
                [6.5, 7.26, "<v 0>Thank you."],
            ],
            "Deepgram paragraphs" => [
                DeepgramParser::class,
                "deepgram/real/train_paragraphs_german.json",
                "de",
                3,
                [0.4, 4.06, "Der Zug nach Hamburg fährt um zehn Uhr ab."],
                [7.1, 8.96, "Der Speisewagen ist heute geschlossen."],
            ],
            "Deepgram words only" => [
                DeepgramParser::class,
                "deepgram/real/museum_words_punctuate.json",
                null,
                3,
                [0.16, 2.4, "The museum is closed on Monday."],
                [7.3, 8.06, "of age."],
            ],
            "AssemblyAI utterances" => [
                AssemblyAiParser::class,
                "assemblyai/real/hike_utterances_speakers.json",
                "en-US",
                3,
                [0.26, 3.5, "<v A>Where should we meet for the hike tomorrow?"],
                [9.96, 11.86, "<v A>Great, see you at eight."],
            ],
            "AssemblyAI words only" => [
                AssemblyAiParser::class,
                "assemblyai/real/market_words_french.json",
                "fr",
                4,
                [0.14, 2.34, "Le marché ouvre à sept heures."],
                [10.48, 12.0, "l'après-midi sur la place."],
            ],
            "Google V1 long running operation" => [
                GoogleSpeechParser::class,
                "google-speech/real/bus_v1_long_running_operation.json",
                "en-us",
                2,
                [0.2, 4.04, "okay the bus to the airport leaves every twenty minutes"],
                [4.94, 8.82, "the first one is at five thirty in the morning"],
            ],
            "Google V2 diarization" => [
                GoogleSpeechParser::class,
                "google-speech/real/restaurant_v2_diarization.json",
                "en-us",
                4,
                [0.3, 4.48, "<v 1>hi I would like to book a table for two tonight"],
                [9.82, 12.26, "<v 2>seven is fine see you then"],
            ],
        ];
    }


    private static function parse(string $parserClass, string $file, bool $wordTimestamps = false): Subtitle
    {
        return (new $parserClass())->parse(file_get_contents(self::DIR . $file), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: $wordTimestamps, speakerVoices: true)]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $parserClass, string $file, ?string $language, int $cueCount, array $firstCue, array $lastCue): void
    {
        $subtitle = self::parse($parserClass, $file);
        $cues     = $subtitle->getCues();

        $this->assertNull(Format::detect(file_get_contents(self::DIR . $file)));
        $this->assertSame($language, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileKeepsItsWordTimestampsAndSpeakersThroughWebVtt(string $parserClass, string $file): void
    {
        $subtitle = self::parse($parserClass, $file, true);
        $vtt      = (new WebVttParser())->parse($subtitle->toString(Format::WebVtt), new ReadOptions());
        $cueData  = fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()];

        $this->assertStringContainsString("<00:00:0", $subtitle->getCues()[0]->getText());
        $this->assertSame(array_map($cueData, $subtitle->getCues()), array_map($cueData, $vtt->getCues()));
    }


    public function testAmazonTranscribeKeepsTheItemsOfEachSegment(): void
    {
        $subtitle = self::parse(AwsTranscribeParser::class, "aws-transcribe/real/library_speakers_language_id.json");
        $data     = $subtitle->getCues()[0]->findFormatData("aws-transcribe");

        $this->assertSame(0, $data["id"]);
        $this->assertSame("spk_0", $data["speaker_label"]);
        $this->assertSame([0, 1, 2, 3, 4, 5], array_column($data["items"], "id"));
        $this->assertSame(["0.0", "punctuation"], [$data["items"][5]["alternatives"][0]["confidence"], $data["items"][5]["type"]]);
        $this->assertSame("library-hours", $subtitle->findFormatData("aws-transcribe")["jobName"]);
        $this->assertSame("en-GB", $subtitle->findFormatData("aws-transcribe")["results"]["language_identification"][0]["code"]);
    }


    public function testDeepgramKeepsTheWordsAndTheMetadata(): void
    {
        $subtitle = self::parse(DeepgramParser::class, "deepgram/real/train_paragraphs_german.json");
        $words    = $subtitle->getCues()[1]->findFormatData("deepgram")["words"];

        $this->assertSame(["Bitte", "steigen", "Sie", "vorne", "ein."], array_column($words, "punctuated_word"));
        $this->assertSame(0, $subtitle->getCues()[1]->findFormatData("deepgram")["channel"]);
        $this->assertSame("general-nova-3", array_values($subtitle->findFormatData("deepgram")["metadata"]["model_info"])[0]["name"]);
    }


    public function testAssemblyAiKeepsTheUtteranceConfidenceAndTheTranscriptFields(): void
    {
        $subtitle = self::parse(AssemblyAiParser::class, "assemblyai/real/hike_utterances_speakers.json");
        $data     = $subtitle->getCues()[1]->findFormatData("assemblyai");

        $this->assertSame("B", $data["speaker"]);
        $this->assertCount(13, $data["words"]);
        $this->assertIsFloat($data["confidence"]);
        $this->assertSame("https://example.com/audio/hike.mp3", $subtitle->findFormatData("assemblyai")["audio_url"]);
        $this->assertArrayNotHasKey("words", $subtitle->findFormatData("assemblyai"));
    }


    public function testGoogleKeepsTheOperationAndTheResultFields(): void
    {
        $subtitle = self::parse(GoogleSpeechParser::class, "google-speech/real/bus_v1_long_running_operation.json");
        $data     = $subtitle->getCues()[0]->findFormatData("google-speech");

        $this->assertSame("4.390s", $data["resultEndTime"]);
        $this->assertSame(0.9612345, $data["confidence"]);
        $this->assertSame(["startTime" => "0.200s", "endTime" => "0.660s", "word" => "okay"], $data["words"][0]);
        $this->assertSame("7612202767953098924", $subtitle->findFormatData("google-speech")["name"]);
        $this->assertSame("15s", $subtitle->findFormatData("google-speech")["totalBilledTime"]);
    }
}
