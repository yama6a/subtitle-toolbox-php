<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestFiles;
use SubtitleToolbox\Tests\Support\WriteGoldens;

class GoogleSpeechFormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, ReadOptions}>
     */
    public static function realFiles(): array
    {
        $cases = [];
        foreach (WriteGoldens::realFiles("google-speech") as $file) {
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
        return TestFiles::parse("google-speech/real/$file", Format::GoogleSpeech, $options ?? self::withWords());
    }


    private static function write(Subtitle $subtitle): array
    {
        return json_decode($subtitle->toString(Format::GoogleSpeech), true, 512, JSON_THROW_ON_ERROR);
    }


    #[DataProvider("realFiles")]
    public function testARoundTripKeepsTheDataOfTheSource(string $file, ReadOptions $options): void
    {
        $this->assertSame(
            WriteGoldens::canonicalJson(file_get_contents(TestFiles::DIR . "google-speech/real/$file")),
            WriteGoldens::canonicalJson(self::read($file, $options)->toString(Format::GoogleSpeech))
        );
    }


    public function testAnEditedWordKeepsTheV1FieldsAndTheOperation(): void
    {
        $subtitle = self::read("bus_v1_long_running_operation.json");
        $cue      = $subtitle->getCues()[1];
        $cue->setLines(str_replace("five", "six", $cue->getLines()));

        $data   = self::write($subtitle);
        $result = $data["response"]["results"][1];

        $this->assertSame(" the first one is at six thirty in the morning", $result["alternatives"][0]["transcript"]);
        $this->assertSame(["startTime" => "6.840s", "endTime" => "7.300s", "word" => "six"], $result["alternatives"][0]["words"][5]);
        $this->assertSame(["9.170s", "en-us"], [$result["resultEndTime"], $result["languageCode"]]);
        $this->assertSame([true, "7612202767953098924"], [$data["done"], $data["name"]]);
    }


    public function testAMovedCueGetsANewResultEnd(): void
    {
        $subtitle = self::read("bus_v1_long_running_operation.json", new ReadOptions());
        $subtitle->getCues()[0]->setEnd(4.5);

        $result = self::write($subtitle)["response"]["results"][0];

        $this->assertSame("4.500s", $result["resultEndTime"]);
        $this->assertSame([], $result["alternatives"][0]["words"]);
    }


    public function testANewSpeakerChangesTheWordsOfADiarizedResult(): void
    {
        $subtitle = self::read("restaurant_v2_diarization.json");
        $cue      = $subtitle->getCues()[1];
        $cue->setLines(str_replace("<v 2>", "<v 3>", $cue->getLines()));

        $words = self::write($subtitle)["results"][0]["alternatives"][0]["words"];

        $runs  = array_values(array_filter(array_column($words, "speakerLabel"), fn (string $speaker, int $index): bool
            => $index === 0 || $words[$index - 1]["speakerLabel"] !== $speaker, ARRAY_FILTER_USE_BOTH));

        $this->assertSame(["1", "3", "1", "2"], $runs);
        $this->assertCount(1, self::write($subtitle)["results"]);
    }


    public function testADiarizedCueWithoutWordsThrows(): void
    {
        $subtitle = self::read("restaurant_v2_diarization.json", new ReadOptions());
        $subtitle->getCues()[2]->setLines("Around seven o'clock please");

        $this->expectException(UnwritableContentException::class);
        $this->expectExceptionMessage("Cue #2 at 8.24 s has no words.");

        $subtitle->toString(Format::GoogleSpeech);
    }


    public function testAnOverlappingCueEndsItsResultWithTheResultBeforeIt(): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(1, 6, "Long cue"), new SubtitleCue(2, 4, "Short cue")]);

        $results = self::write($subtitle)["results"];

        $this->assertSame(["6s", "6s"], array_column($results, "resultEndTime"));
        $this->assertCount(2, Subtitle::fromString($subtitle->toString(Format::GoogleSpeech), Format::GoogleSpeech)->getCues());
    }


    public function testCuesFromAnotherFormatGiveV1Results(): void
    {
        $subtitle = new Subtitle();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "en-US");
        $subtitle->addCue(new SubtitleCue(1.5, 3, ["<v Anna>Good", "morning."]));
        $subtitle->addCue(new SubtitleCue(4, 5, "<00:00:04.000>See <00:00:04.500>you."));

        $this->assertSame(
            '{"results":[{"alternatives":[{"transcript":"Good morning."}],"resultEndTime":"3s","languageCode":"en-US"},' .
            '{"alternatives":[{"transcript":"See you.","words":[{"startTime":"4s","endTime":"4.500s","word":"See"},' .
            '{"startTime":"4.500s","endTime":"5s","word":"you."}]}],"resultEndTime":"5s","languageCode":"en-US"}]}',
            $subtitle->toString(Format::GoogleSpeech)
        );
    }
}
