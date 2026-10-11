<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\PodcastTranscriptWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
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
}
