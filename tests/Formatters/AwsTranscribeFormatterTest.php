<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

class AwsTranscribeFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("aws-transcribe") as $file) {
            $cases["$file default"] = [$file, new ReadOptions()];
            $cases["$file words"]   = [$file, self::withWords()];
        }

        return $cases;
    }


    private static function withWords(): ReadOptions
    {
        return new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true, speakerVoices: true)]);
    }


    private static function read(string $file, ?ReadOptions $options = null): Subtitle
    {
        return TestFiles::parse("aws-transcribe/real/$file", Format::AwsTranscribe, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): array
    {
        return json_decode($subtitle->toString(Format::AwsTranscribe), true, 512, JSON_THROW_ON_ERROR)["results"];
    }


    #[DataProvider("realFiles")]
    public function testARoundTripKeepsTheDataOfTheSource(string $file, ReadOptions $options): void
    {
        $this->assertSame(
            WriteGoldens::canonicalJson(file_get_contents(TestFiles::DIR . "aws-transcribe/real/$file")),
            WriteGoldens::canonicalJson(self::read($file, $options)->toString(Format::AwsTranscribe))
        );
    }


    public function testAnEditedWordGetsTheConfidenceOfAPersonAndKeepsItsPunctuation(): void
    {
        $subtitle = self::read("library_speakers_language_id.json");
        $cue      = $subtitle->getCues()[0];
        $cue->setLines(str_replace("nine", "ten", $cue->getLines()));

        $results = self::write($subtitle);

        $this->assertSame("The library opens at ten.", $results["audio_segments"][0]["transcript"]);
        $this->assertStringStartsWith("The library opens at ten. Can I", $results["transcripts"][0]["transcript"]);
        $this->assertSame(
            ["id" => 4, "start_time" => "2.0", "speaker_label" => "spk_0", "end_time" => "2.3",
             "alternatives" => [["confidence" => "1.0", "content" => "ten"]], "type" => "pronunciation"],
            $results["items"][4]
        );
        $this->assertSame(["confidence" => "0.0", "content" => "."], $results["items"][5]["alternatives"][0]);
        $this->assertSame("0.95", $results["items"][2]["alternatives"][0]["confidence"]);
    }


    public function testANewWordGetsNewItemsAndTheFollowingIdsMove(): void
    {
        $subtitle = self::read("library_speakers_language_id.json");
        $cue      = $subtitle->getCues()[0];
        $cue->setLines(str_replace("nine.", "nine <00:00:02.200>sharp.", $cue->getLines()));

        $results = self::write($subtitle);

        $this->assertSame(["nine", "sharp", "."], array_map(fn (array $item): string => $item["alternatives"][0]["content"], array_slice($results["items"], 4, 3)));
        $this->assertSame(["2.2", "2.2", "2.3"], [$results["items"][4]["end_time"], $results["items"][5]["start_time"], $results["items"][5]["end_time"]]);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $results["audio_segments"][0]["items"]);
        $this->assertSame(7, $results["audio_segments"][1]["items"][0]);
        $this->assertSame(range(0, 22), array_column($results["items"], "id"));
    }


    public function testANewSpeakerChangesTheItemsAndTheSpeakerSegments(): void
    {
        $subtitle = self::read("library_speakers_language_id.json");
        $cue      = $subtitle->getCues()[1];
        $cue->setLines(str_replace("<v spk_1>", "<v spk_0>", $cue->getLines()));

        $results = self::write($subtitle);

        $this->assertSame("spk_0", $results["audio_segments"][1]["speaker_label"]);
        $this->assertSame(["spk_0"], array_values(array_unique(array_column($results["items"], "speaker_label"))));
        $this->assertCount(1, $results["speaker_labels"]["segments"]);
        $this->assertSame(["0.52", "8.6", 1], [
            $results["speaker_labels"]["segments"][0]["start_time"],
            $results["speaker_labels"]["segments"][0]["end_time"],
            $results["speaker_labels"]["speakers"],
        ]);
    }


    public function testAWordChangedWithoutWordTimestampsKeepsTheTimesOfItsSourceWord(): void
    {
        $subtitle = self::read("library_speakers_language_id.json", new ReadOptions());
        $subtitle->getCues()[2]->setLines("Yes, use the box by the gate.");

        $items = self::write($subtitle)["items"];

        $this->assertSame(["8.3", "8.6", "1.0", "gate"], [$items[20]["start_time"], $items[20]["end_time"],
                          $items[20]["alternatives"][0]["confidence"], $items[20]["alternatives"][0]["content"]]);
        $this->assertSame([".", "punctuation"], [$items[21]["alternatives"][0]["content"], $items[21]["type"]]);
        $this->assertSame("0.91", $items[19]["alternatives"][0]["confidence"]);
    }


    public function testATextWithOtherWordsWithoutWordTimestampsHasNoItemsAndKeepsItsAudioSegment(): void
    {
        $subtitle = self::read("library_speakers_language_id.json", new ReadOptions());
        $subtitle->getCues()[2]->setLines("Yes, use the box by the front door.");

        $results = self::write($subtitle);

        $this->assertSame([], $results["audio_segments"][2]["items"]);
        $this->assertSame("Yes, use the box by the front door.", $results["audio_segments"][2]["transcript"]);
        $this->assertCount(13, $results["items"]);
    }


    public function testACueWithoutWordsInAnItemsOnlyFileAddsAudioSegments(): void
    {
        $subtitle = self::read("weather_items_only.json", new ReadOptions());
        $subtitle->getCues()[0]->setLines("Rain is expected in the south.");

        $results = self::write($subtitle);

        $this->assertCount(5, $results["audio_segments"]);
        $this->assertSame([], $results["audio_segments"][0]["items"]);
        $this->assertSame(["0.31", "3.43"], [$results["audio_segments"][0]["start_time"], $results["audio_segments"][0]["end_time"]]);
        $this->assertCount(5, Subtitle::fromString($subtitle->toString(Format::AwsTranscribe), Format::AwsTranscribe)->getCues());
    }


    public function testCuesFromAnotherFormatGiveAudioSegmentsAndItemsForTheWordTimestamps(): void
    {
        $subtitle = new Subtitle();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "en-GB");
        $subtitle->addCue(new SubtitleCue(1.5, 3, ["<v Anna>Good", "morning."]));
        $subtitle->addCue(new SubtitleCue(4, 5, "<v Ben><00:00:04.000>See <00:00:04.500>you."));

        $this->assertSame(
            '{"results":{"transcripts":[{"transcript":"Good morning. See you."}],' .
            '"speaker_labels":{"speakers":1,"segments":[{"start_time":"4.0","speaker_label":"Ben","end_time":"5.0","items":[' .
            '{"start_time":"4.0","speaker_label":"Ben","end_time":"4.5"},{"start_time":"4.5","speaker_label":"Ben","end_time":"5.0"}]}]},' .
            '"items":[{"id":0,"start_time":"4.0","speaker_label":"Ben","end_time":"4.5","alternatives":[{"confidence":"1.0","content":"See"}],"type":"pronunciation"},' .
            '{"id":1,"start_time":"4.5","speaker_label":"Ben","end_time":"5.0","alternatives":[{"confidence":"1.0","content":"you"}],"type":"pronunciation"},' .
            '{"id":2,"speaker_label":"Ben","alternatives":[{"confidence":"0.0","content":"."}],"type":"punctuation"}],' .
            '"audio_segments":[{"id":0,"transcript":"Good morning.","start_time":"1.5","end_time":"3.0","speaker_label":"Anna","items":[]},' .
            '{"id":1,"transcript":"See you.","start_time":"4.0","end_time":"5.0","speaker_label":"Ben","items":[0,1,2]}],' .
            '"language_code":"en-GB"}}',
            $subtitle->toString(Format::AwsTranscribe)
        );
    }
}
