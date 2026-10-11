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

class WhisperJsonFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("whisper") as $file) {
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
        return TestFiles::parse("whisper/real/$file", Format::Whisper, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): array
    {
        return json_decode($subtitle->toString(Format::Whisper), true, 512, JSON_THROW_ON_ERROR);
    }


    /**
     * The parser makes no cue of a segment without text, so the writer drops such a segment.
     */
    #[DataProvider("realFiles")]
    public function testARoundTripKeepsTheDataOfTheSource(string $file, ReadOptions $options): void
    {
        $source = WriteGoldens::canonicalJson(file_get_contents(TestFiles::DIR . "whisper/real/$file"));
        foreach (["segments", "transcription"] as $list) {
            if (isset($source[$list])) {
                $source[$list] = array_values(array_filter($source[$list], fn (array $segment): bool => trim($segment["text"]) !== ""));
            }
        }

        $this->assertSame($source, WriteGoldens::canonicalJson(self::read($file, $options)->toString(Format::Whisper)));
    }


    public function testAnEditedWordGetsTheConfidenceOfAPersonAndTheOtherWordsKeepTheirs(): void
    {
        $subtitle = self::read("openai_whisper_word_timestamps.json");
        $cue      = $subtitle->getCues()[0];
        $cue->setLines(str_replace("bakery", "baker", $cue->getLines()));

        $data  = self::write($subtitle);
        $words = $data["segments"][0]["words"];

        $this->assertSame(" The baker opens at seven.", $data["segments"][0]["text"]);
        $this->assertStringStartsWith(" The baker opens at seven. The café", $data["text"]);
        $this->assertSame(["word" => " baker", "start" => 0.24, "end" => 0.7, "probability" => 1.0], $words[1]);
        $this->assertSame(["word" => " The", "start" => 0.0, "end" => 0.24, "probability" => 0.991234540939331], $words[0]);
        $this->assertSame(0.9876543283462524, $words[2]["probability"]);
    }


    public function testAWordChangedWithoutWordTimestampsKeepsTheTimesOfItsSourceWord(): void
    {
        $subtitle = self::read("openai_whisper_word_timestamps.json", new ReadOptions());
        $subtitle->getCues()[0]->setLines("The bakery opens at six.");

        $data  = self::write($subtitle);
        $words = $data["segments"][0]["words"];

        $this->assertSame(" The bakery opens at six.", $data["segments"][0]["text"]);
        $this->assertSame(["word" => " six.", "start" => 1.32, "end" => 2.2, "probability" => 1.0], $words[4]);
        $this->assertSame(0.9534912109375, $words[1]["probability"]);
        $this->assertArrayNotHasKey("tokens", $data["segments"][0]);
        $this->assertArrayHasKey("tokens", $data["segments"][1]);
    }


    public function testATextWithOtherWordsWithoutWordTimestampsHasNoWords(): void
    {
        $subtitle = self::read("openai_whisper_word_timestamps.json", new ReadOptions());
        $subtitle->getCues()[0]->setLines("The bakery opens at six today.");

        $data = self::write($subtitle);

        $this->assertArrayNotHasKey("words", $data["segments"][0]);
        $this->assertCount(6, $data["segments"][1]["words"]);
        $this->assertSame(0.0, $data["segments"][0]["start"]);
    }


    public function testAMovedCueWritesItsNewTimes(): void
    {
        $subtitle = self::read("openai_api_verbose_json_french.json", new ReadOptions());
        $subtitle->getCues()[1]->setStart(3.0)->setEnd(5.5);

        $data = self::write($subtitle);

        $this->assertSame([3.0, 5.5], [$data["segments"][1]["start"], $data["segments"][1]["end"]]);
        $this->assertSame([0.0, 2.8399999141693115], [$data["segments"][0]["start"], $data["segments"][0]["end"]]);
    }


    public function testTheOpenAiApiShapeListsTheWordsAtTheTopLevel(): void
    {
        $subtitle = self::read("openai_api_verbose_json_words.json");
        $subtitle->getCues()[1]->setLines("<00:00:04.320>Somebody <00:00:04.900>came.");

        $data = self::write($subtitle);

        $this->assertSame("The beach was quiet in the morning. Somebody came. The tide was low at noon.", $data["text"]);
        $this->assertSame(["word" => "Somebody", "start" => 4.32, "end" => 4.9], $data["words"][7]);
        $this->assertSame(["word" => "came.", "start" => 4.9, "end" => 5.1], $data["words"][8]);
        $this->assertArrayNotHasKey("words", $data["segments"][1]);
    }


    public function testANewSpeakerChangesTheSpeakerOfTheSegmentAndOfItsWords(): void
    {
        $subtitle = self::read("whisperx_diarize.json");
        $cue      = $subtitle->getCues()[0];
        $cue->setLines(str_replace("<v SPEAKER_00>", "<v SPEAKER_02>", $cue->getLines()));

        $data = self::write($subtitle);

        $this->assertSame("SPEAKER_02", $data["segments"][0]["speaker"]);
        $this->assertSame(["SPEAKER_02"], array_unique(array_column($data["segments"][0]["words"], "speaker")));
        $this->assertSame("SPEAKER_02", $data["word_segments"][0]["speaker"]);
        $this->assertSame("SPEAKER_01", $data["segments"][1]["speaker"]);
    }


    public function testAnEditedWhisperCppSegmentHasNoTokens(): void
    {
        $subtitle = self::read("whisper_cpp_ojf.json");
        $subtitle->getCues()[0]->setLines("The museum opens at nine.");

        $data = self::write($subtitle);

        $this->assertSame(" The museum opens at nine.", $data["transcription"][0]["text"]);
        $this->assertArrayNotHasKey("tokens", $data["transcription"][0]);
        $this->assertArrayHasKey("tokens", $data["transcription"][1]);
    }


    public function testAMovedWhisperCppSegmentGetsNewOffsetsAndTimestamps(): void
    {
        $subtitle = self::read("whisper_cpp_oj.json");
        $subtitle->getCues()[0]->setEnd(62.5);

        $segment = self::write($subtitle)["transcription"][0];

        $this->assertSame(["from" => 0, "to" => 62500], $segment["offsets"]);
        $this->assertSame(["from" => "00:00:00,000", "to" => "00:01:02,500"], $segment["timestamps"]);
    }


    public function testCuesFromAnotherFormatGiveTheShapeOfOpenAiWhisper(): void
    {
        $subtitle = new Subtitle();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "de");
        $subtitle->addCue(new SubtitleCue(1.5, 3, ["<v Anna>Guten", "Morgen."]));
        $subtitle->addCue(new SubtitleCue(4, 5, "<00:00:04.000>Bis <00:00:04.500>bald."));

        $this->assertSame(
            '{"text":" Guten Morgen. Bis bald.","segments":[{"id":0,"start":1.5,"end":3.0,"text":" Guten Morgen.","speaker":"Anna"},' .
            '{"id":1,"start":4.0,"end":5.0,"text":" Bis bald.","words":[{"word":" Bis","start":4.0,"end":4.5,"probability":1.0},' .
            '{"word":" bald.","start":4.5,"end":5.0,"probability":1.0}]}],"language":"de"}',
            $subtitle->toString(Format::Whisper)
        );
    }
}
