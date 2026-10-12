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

class AssemblyAiFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("assemblyai") as $file) {
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
        return TestFiles::parse("assemblyai/real/$file", Format::AssemblyAi, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): array
    {
        return json_decode($subtitle->toString(Format::AssemblyAi), true, 512, JSON_THROW_ON_ERROR);
    }


    #[DataProvider("realFiles")]
    public function testARoundTripKeepsTheDataOfTheSource(string $file, ReadOptions $options): void
    {
        $this->assertSame(
            WriteGoldens::canonicalJson(file_get_contents(TestFiles::DIR . "assemblyai/real/$file")),
            WriteGoldens::canonicalJson(self::read($file, $options)->toString(Format::AssemblyAi))
        );
    }


    public function testAnEditedWordGetsTheConfidenceOfAPersonInTheUtteranceAndTheWords(): void
    {
        $subtitle = self::read("hike_utterances_speakers.json");
        $cue      = $subtitle->getCues()[0];
        $cue->setLines(str_replace("hike", "walk", $cue->getLines()));

        $data = self::write($subtitle);
        $word = ["text" => "walk", "start" => 2580, "end" => 3040, "confidence" => 1.0, "speaker" => "A"];

        $this->assertSame("Where should we meet for the walk tomorrow?", $data["utterances"][0]["text"]);
        $this->assertSame($word, $data["utterances"][0]["words"][6]);
        $this->assertSame($word, $data["words"][6]);
        $this->assertStringStartsWith("Where should we meet for the walk tomorrow? At the", $data["text"]);
        $this->assertSame(0.90655, $data["words"][5]["confidence"]);
    }


    public function testANewSpeakerChangesTheUtteranceAndItsWords(): void
    {
        $subtitle = self::read("hike_utterances_speakers.json");
        $cue      = $subtitle->getCues()[1];
        $cue->setLines(str_replace("<v B>", "<v C>", $cue->getLines()));

        $utterance = self::write($subtitle)["utterances"][1];

        $this->assertSame("C", $utterance["speaker"]);
        $this->assertSame(["C"], array_values(array_unique(array_column($utterance["words"], "speaker"))));
    }


    public function testAMovedCueWithoutWordTimestampsLosesItsWordsInAWordsOnlyFile(): void
    {
        $subtitle = self::read("market_words_french.json", new ReadOptions());
        $subtitle->getCues()[1]->setStart(3.0);

        $data = self::write($subtitle);

        $this->assertCount(4, $data["utterances"]);
        $this->assertSame([3000, []], [$data["utterances"][1]["start"], $data["utterances"][1]["words"]]);
        $this->assertSame([140, 2340], [$data["utterances"][0]["start"], $data["utterances"][0]["end"]]);
        $this->assertCount(4, Subtitle::fromString($subtitle->toString(Format::AssemblyAi), Format::AssemblyAi)->getCues());
    }


    public function testCuesFromAnotherFormatGiveWordsAndUtterancesNullWhenEveryCueHasWords(): void
    {
        $subtitle = new Subtitle();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "en-US");
        $subtitle->addCue(new SubtitleCue(4, 5, "<00:00:04.000>See <00:00:04.500>you."));

        $this->assertSame(
            '{"language_code":"en_us","text":"See you.","words":[{"text":"See","start":4000,"end":4500,"confidence":1.0,"speaker":null},' .
            '{"text":"you.","start":4500,"end":5000,"confidence":1.0,"speaker":null}],"utterances":null}',
            $subtitle->toString(Format::AssemblyAi)
        );
    }


    public function testCuesFromAnotherFormatWithSpeakersGiveUtterances(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1.5, 3, ["<v Anna>Good", "morning."]));

        $this->assertSame(
            '{"text":"Good morning.","words":[],"utterances":[{"confidence":1.0,"end":3000,"speaker":"Anna","start":1500,"text":"Good morning.","words":[]}]}',
            $subtitle->toString(Format::AssemblyAi)
        );
    }
}
