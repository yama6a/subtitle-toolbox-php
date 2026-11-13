<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\PodcastTranscriptWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class PodcastTranscriptFormatterTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/";


    private function dialogue(): Subtitle
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 3, ["<v Anna><00:00:01.000><i>Where</i> <00:00:01.500>are", "<00:00:02.000>you?"]));
        $subtitle->addCue(new SubtitleCue(3, 5, ["<v Ben>Home &amp; <00:00:04.000>bed.", "<v Anna>Why?"]));
        $subtitle->addCue(new SubtitleCue(6, 7, "No &lt;speaker&gt; <00:00:09.000>here"));

        return $subtitle;
    }


    public function testWritesOneSegmentPerCueAndSpeaker(): void
    {
        $this->assertSame(
            '{"version":"1.0.0","segments":[{"speaker":"Anna","startTime":1.0,"endTime":3.0,"body":"Where are you?"},' .
            '{"speaker":"Ben","startTime":3.0,"endTime":5.0,"body":"Home & bed."},{"speaker":"Anna","startTime":3.0,"endTime":5.0,"body":"Why?"},' .
            '{"startTime":6.0,"endTime":7.0,"body":"No <speaker> here"}]}',
            $this->dialogue()->toString(Format::PodcastTranscript)
        );
    }


    public function testWritesOneSegmentPerWordTimestamp(): void
    {
        $json     = $this->dialogue()->toString(Format::PodcastTranscript, new WriteOptions(formats: [new PodcastTranscriptWriteOptions(wordSegments: true)]));
        $segments = json_decode($json, true, 512, JSON_THROW_ON_ERROR)["segments"];

        $this->assertSame([
            ["speaker" => "Anna", "startTime" => 1.0, "endTime" => 1.5, "body" => "Where"],
            ["speaker" => "Anna", "startTime" => 1.5, "endTime" => 2.0, "body" => "are"],
            ["speaker" => "Anna", "startTime" => 2.0, "endTime" => 3.0, "body" => "you?"],
            ["speaker" => "Ben", "startTime" => 3.0, "endTime" => 4.0, "body" => "Home &"],
            ["speaker" => "Ben", "startTime" => 4.0, "endTime" => 4.0, "body" => "bed."],
            ["speaker" => "Anna", "startTime" => 4.0, "endTime" => 5.0, "body" => "Why?"],
            ["startTime" => 6.0, "endTime" => 7.0, "body" => "No <speaker>"],
            ["startTime" => 7.0, "endTime" => 7.0, "body" => "here"],
        ], $segments);
    }


    public function testWritesTheFormatDataBackAndPrettyPrints(): void
    {
        $subtitle = new Subtitle();
        $subtitle->setFormatData("podcast-transcript", ["version" => "1.0.1", "language" => "en"]);
        $subtitle->addCue((new SubtitleCue(0, 1.25, "Hi"))->setFormatData("podcast-transcript", ["confidence" => 0.9]));

        $this->assertSame(
            "{\r\n    \"version\": \"1.0.1\",\r\n    \"segments\": [\r\n        {\r\n            \"startTime\": 0.0,\r\n" .
            "            \"endTime\": 1.25,\r\n            \"body\": \"Hi\",\r\n            \"confidence\": 0.9\r\n        }\r\n" .
            "    ],\r\n    \"language\": \"en\"\r\n}\r\n",
            $subtitle->toString(Format::PodcastTranscript, new WriteOptions(lineEnding: LineEnding::Crlf, formats: [new PodcastTranscriptWriteOptions(prettyPrint: true)]))
        );
    }


    public function testWritesWordSegmentsFromWhisperJson(): void
    {
        $subtitle = (new WhisperJsonParser())->parse(file_get_contents(self::DIR . "whisper/real/openai_whisper_word_timestamps.json"), new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
        $json     = $subtitle->toString(Format::PodcastTranscript, new WriteOptions(formats: [new PodcastTranscriptWriteOptions(wordSegments: true)]));

        $this->assertSame(
            ["startTime" => 0.0, "endTime" => 0.24, "body" => "The"],
            json_decode($json, true)["segments"][0]
        );
    }


    public function testWritesNoEndTimeBeforeTheStartTimeOfAJoinedCue(): void
    {
        $json     = '{"segments":[{"startTime":5,"endTime":6,"body":"a"},{"startTime":1,"endTime":2,"body":"b"}]}';
        $subtitle = (new PodcastTranscriptParser())->parse($json, new ReadOptions());
        $out      = $subtitle->toString(Format::PodcastTranscript);

        $this->assertSame(['startTime' => 5.0, 'endTime' => 5.0, 'body' => 'a b'], json_decode($out, true)["segments"][0]);
        $this->assertCount(1, (new PodcastTranscriptParser())->parse($out, new ReadOptions())->getCues());
    }


    private static function wordSegments(Subtitle $subtitle): array
    {
        $json = $subtitle->toString(Format::PodcastTranscript, new WriteOptions(formats: [new PodcastTranscriptWriteOptions(wordSegments: true)]));

        return array_map(fn (array $segment): array => [$segment["startTime"], $segment["endTime"], $segment["body"]],
                         json_decode($json, true, 512, JSON_THROW_ON_ERROR)["segments"]);
    }


    public function testAWordSegmentEndsAtTheEndOfItsWordBeforeTheNextStart(): void
    {
        $json     = '{"segments": [{"start": 0, "end": 3, "text": "a b", "words": [{"word": "a", "start": 0, "end": 1}, {"word": "b", "start": 2, "end": 2.5}]}]}';
        $subtitle = Subtitle::fromString($json, Format::Whisper, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));

        $this->assertSame("<00:00:00.000>a <00:00:02.000>b", $subtitle->getCues()[0]->getText());
        $this->assertSame([[0.0, 1.0, "a"], [2.0, 2.5, "b"]], self::wordSegments($subtitle));
    }


    public function testAWordSegmentRoundTripKeepsTheEndOfEachWord(): void
    {
        $json     = '{"version": "1.0.0", "segments": [{"startTime": 0, "endTime": 1, "body": "a"}, {"startTime": 2, "endTime": 2.5, "body": "b"}]}';
        $subtitle = Subtitle::fromString($json, Format::PodcastTranscript, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));

        $this->assertSame([[0.0, 1.0, "a"], [2.0, 2.5, "b"]], self::wordSegments($subtitle));
    }


    public function testStoredWordEndsMoveWithRetimingAndStopCountingAfterAnEdit(): void
    {
        $cue = new SubtitleCue(0, 4, "<00:00:00.000>a <00:00:02.000>b <00:00:03.000>c");
        $cue->setFormatData("words", ["times" => [["start" => 0, "end" => 1.5], ["start" => 2, "end" => 2.5], ["start" => 3, "end" => 3.5]]]);
        $subtitle = (new Subtitle())->addCue($cue);

        $this->assertSame([[0.0, 1.5, "a"], [2.0, 2.5, "b"], [3.0, 3.5, "c"]], self::wordSegments($subtitle));

        $subtitle->shift(1);
        $this->assertSame([[1.0, 2.5, "a"], [3.0, 3.5, "b"], [4.0, 4.5, "c"]], self::wordSegments($subtitle));

        $cue->setLines("<00:00:00.000>a <00:00:02.000>b <00:00:03.000>c <00:00:03.500>d")->setStart(0)->setEnd(4);
        $this->assertSame([[0.0, 2.0, "a"], [2.0, 3.0, "b"], [3.0, 3.5, "c"], [3.5, 4.0, "d"]], self::wordSegments($subtitle));
    }
}
