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

class DeepgramFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("deepgram") as $file) {
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
        return TestFiles::parse("deepgram/real/$file", Format::Deepgram, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): array
    {
        return json_decode($subtitle->toString(Format::Deepgram), true, 512, JSON_THROW_ON_ERROR)["results"];
    }


    #[DataProvider("realFiles")]
    public function testARoundTripKeepsTheDataOfTheSource(string $file, ReadOptions $options): void
    {
        $this->assertSame(
            WriteGoldens::canonicalJson(file_get_contents(TestFiles::DIR . "deepgram/real/$file")),
            WriteGoldens::canonicalJson(self::read($file, $options)->toString(Format::Deepgram))
        );
    }


    public function testAnEditedWordGetsTheConfidenceOfAPersonInTheUtteranceAndTheChannel(): void
    {
        $subtitle = self::read("pool_utterances_diarize.json");
        $cue      = $subtitle->getCues()[0];
        $cue->setLines(str_replace("pool", "sauna", $cue->getLines()));

        $results = self::write($subtitle);
        $word    = ["word" => "sauna", "start" => 1.92, "end" => 2.38, "confidence" => 1.0, "speaker" => 0, "punctuated_word" => "sauna"];

        $this->assertSame("Good morning, is the sauna open today?", $results["utterances"][0]["transcript"]);
        $this->assertSame($word, $results["utterances"][0]["words"][4]);
        $this->assertSame($word, $results["channels"][0]["alternatives"][0]["words"][4]);
        $this->assertStringStartsWith("Good morning, is the sauna open today? It opens", $results["channels"][0]["alternatives"][0]["transcript"]);
        $this->assertSame(0.9087655, $results["utterances"][0]["words"][3]["confidence"]);
    }


    public function testANewSpeakerChangesTheUtteranceAndItsWords(): void
    {
        $subtitle = self::read("pool_utterances_diarize.json");
        $cue      = $subtitle->getCues()[1];
        $cue->setLines(str_replace("<v 1>", "<v 2>", $cue->getLines()));

        $utterance = self::write($subtitle)["utterances"][1];

        $this->assertSame(2, $utterance["speaker"]);
        $this->assertSame([2], array_values(array_unique(array_column($utterance["words"], "speaker"))));
    }


    public function testAnEditedSentenceStaysInItsParagraph(): void
    {
        $subtitle = self::read("train_paragraphs_german.json", new ReadOptions());
        $subtitle->getCues()[1]->setLines("Bitte steigen Sie hinten ein.");

        $alternative = self::write($subtitle)["channels"][0]["alternatives"][0];
        $paragraphs  = $alternative["paragraphs"];

        $this->assertSame("\nDer Zug nach Hamburg fährt um zehn Uhr ab. Bitte steigen Sie hinten ein.\n\nDer Speisewagen ist heute geschlossen.", $paragraphs["transcript"]);
        $this->assertSame(["text" => "Bitte steigen Sie hinten ein.", "start" => 4.34, "end" => 6.2], $paragraphs["paragraphs"][0]["sentences"][1]);
        $this->assertSame(14, $paragraphs["paragraphs"][0]["num_words"]);
        $this->assertSame(["word" => "hinten", "start" => 5.44, "end" => 5.74, "confidence" => 1.0, "punctuated_word" => "hinten"], $alternative["words"][12]);
        $this->assertSame(0.9087655, $alternative["words"][11]["confidence"]);
    }


    public function testCuesFromAnotherFormatGiveUtterancesAndWordsForTheWordTimestamps(): void
    {
        $subtitle = new Subtitle();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "de");
        $subtitle->addCue(new SubtitleCue(1.5, 3, ["<v 1>Guten", "Morgen."]));
        $subtitle->addCue(new SubtitleCue(4, 5, "<00:00:04.000>Bis <00:00:04.500>bald."));

        $this->assertSame(
            '{"results":{"channels":[{"alternatives":[{"transcript":"Guten Morgen. Bis bald.","words":[' .
            '{"word":"bis","start":4.0,"end":4.5,"confidence":1.0,"punctuated_word":"Bis"},' .
            '{"word":"bald","start":4.5,"end":5.0,"confidence":1.0,"punctuated_word":"bald."}]}],"detected_language":"de"}],' .
            '"utterances":[{"start":1.5,"end":3.0,"confidence":1.0,"channel":0,"transcript":"Guten Morgen.","words":[],"speaker":1},' .
            '{"start":4.0,"end":5.0,"confidence":1.0,"channel":0,"transcript":"Bis bald.","words":[' .
            '{"word":"bis","start":4.0,"end":4.5,"confidence":1.0,"punctuated_word":"Bis"},' .
            '{"word":"bald","start":4.5,"end":5.0,"confidence":1.0,"punctuated_word":"bald."}]}]}}',
            $subtitle->toString(Format::Deepgram)
        );
    }
}
