<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Http\FakeHttpClient;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

require_once __DIR__ . "/FakeTranslationEngine.php";
require_once __DIR__ . "/../Http/FakeHttpClient.php";

class TranslationRunnerTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";


    private static function readFile(string $path): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . $path), Format::SubRip);
    }


    /**
     * @param list<string> $translations
     */
    private static function fixedEngine(array $translations): TranslationEngine
    {
        return new class ($translations) implements TranslationEngine {
            public function __construct(private readonly array $translations)
            {
            }


            public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
            {
                return array_slice($this->translations, 0, count($texts));
            }
        };
    }


    /**
     * @return list<list<string>>
     */
    private static function lines(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues());
    }


    public function testTagsSurviveAndSentencesOverTwoCuesGoOutAsOneText(): void
    {
        $original = self::readFile("translation/own_station.srt");
        $engine   = new FakeTranslationEngine();
        $runner   = new TranslationRunner($engine);

        $report = $runner->translate($translated = $original, "en", "de");

        $this->assertSame([[
            "texts"  => [
                "The train to Basel leaves from platform 4 at <x1>10:15</x1>.",
                "<x1>Tickets &amp; seat\nreservations</x1> are sold here.",
                "- Is this seat free?\n- Yes, it is.",
                "<x1>The next stop is Zurich main station.</x1>",
            ],
            "source" => "en",
            "target" => "de",
        ]], $engine->calls);
        $this->assertSame([
            ["THE TRAIN TO BASEL LEAVES"],
            ["FROM PLATFORM 4 AT <b>10:15</b>."],
            ["\u{266A} \u{266A}"],
            ["<i>TICKETS &amp; SEAT", "RESERVATIONS</i> ARE SOLD HERE."],
            ["2024"],
            ["- IS THIS SEAT FREE?", "- YES, IT IS."],
            ["<i>THE NEXT STOP IS</i>"],
            ["<i>ZURICH MAIN STATION.</i>"],
        ], self::lines($translated));
        $this->assertSame([], $report->warnings);
        $this->assertSame("de", $translated->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(1.0, $translated->getCues()[0]->getStart());
        $this->assertSame(21.0, $translated->getCues()[7]->getEnd());
    }


    public function testTranslateChangesTheCuesInPlaceAndKeepsTheComments(): void
    {
        $subtitle = self::readFile("translation/own_station.srt")->addComment("Station announcements", 3);
        $cue      = $subtitle->getCues()[0];

        (new TranslationRunner(new FakeTranslationEngine()))->translate($subtitle, "en", "de");

        $this->assertSame($cue, $subtitle->getCues()[0]);
        $this->assertSame(["THE TRAIN TO BASEL LEAVES"], $cue->getLines());
        $this->assertSame("de", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame("Station announcements", $subtitle->getComments()[0]->text);
    }


    public function testAFailingEngineLeavesTheSubtitleAsItWas(): void
    {
        $subtitle = TestSubtitles::fromTexts(["one.", "two."], start: 1, step: 2);
        $before   = self::lines($subtitle);

        $engine = new class implements TranslationEngine {
            private int $calls = 0;


            public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
            {
                return ++$this->calls === 1 ? ["EINS."] : throw new \RuntimeException("The engine is down.");
            }
        };

        try {
            (new TranslationRunner($engine))->translate($subtitle, "en", "de", new TranslationOptions(maxCharactersPerRequest: 4));
            $this->fail("The second request did not throw.");
        } catch (\RuntimeException $exception) {
            $this->assertSame("The engine is down.", $exception->getMessage());
        }

        $this->assertSame($before, self::lines($subtitle));
        $this->assertNull($subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testADroppedPlaceholderStripsTheTagsOfTheCueAndGivesAWarning(): void
    {
        $runner = new TranslationRunner(new FakeTranslationEngine(true));

        $report = $runner->translate($translated = self::readFile("translation/own_station.srt"), "en", "de");

        $this->assertSame(["FROM PLATFORM 4 AT 10:15."], $translated->getCues()[1]->getLines());
        $this->assertSame(["TICKETS &amp; SEAT", "RESERVATIONS ARE SOLD HERE."], $translated->getCues()[3]->getLines());
        $this->assertSame(["- IS THIS SEAT FREE?", "- YES, IT IS."], $translated->getCues()[5]->getLines());
        $this->assertSame([0, 1, 3, 6, 7], array_map(fn (TranslationWarning $warning): int => $warning->cueIndex, $report->warnings));
        $this->assertSame("The engine dropped or changed a placeholder tag. The cue has no tags.", $report->warnings[0]->message);
    }


    public function testABrokenOrMovedPlaceholderGivesAWarning(): void
    {
        foreach (["<x1>Run! </ x1>", "Run!</x1><x1>", "<x1>Run!</x1><x2/>", "<x1>Run!</x1></x1>"] as $translation) {
            $runner = new TranslationRunner(self::fixedEngine([$translation]));

            $report = $runner->translate($translated = TestSubtitles::fromTexts(["<i>Lauf!</i>"], start: 1, step: 2), "de", "en");

            $this->assertSame([["Run!"]], self::lines($translated), $translation);
            $this->assertCount(1, $report->warnings, $translation);
        }
    }


    public function testTagsOfARealFileSurvive(): void
    {
        $original = self::readFile("srt/real/own_styled.srt");
        $engine   = new FakeTranslationEngine();
        $runner   = new TranslationRunner($engine);

        $report = $runner->translate($translated = $original, "en", "fr");

        $this->assertSame([
            "<x1><x2>[train horn]</x2></x1> <x3>[rain]</x3> <x4>[train horn]</x4>",
            "<x1><x2>[oven door opens]</x2></x1> [whistle] [station speaker] <x3>Thebakeryopensat7:35.</x3>",
            "<x1>Italics over two lines that close on the second line</x1> <x2/>Italics that never close The next cue is not italic",
            "<x1>x</x1>^3 * <x2>x</x2> = 100",
        ], $engine->calls[0]["texts"]);
        $this->assertSame([
            ['<font color="#00ff00"><b>[TRAIN HORN]</b></font>'],
            ['<font color="#ff00ff">[RAIN]</font>'],
            ['<font color="#00ff00">[TRAIN HORN]</font>'],
            ["<b><u>[OVEN DOOR OPENS]</u></b>"],
            ["[WHISTLE]"],
            ["[STATION SPEAKER] <i>THEBAKERYOPENSAT7:35.</i>"],
            ["<i>ITALICS OVER TWO LINES THAT CLOSE ON THE SECOND LINE</i>"],
            ["<i>ITALICS THAT NEVER CLOSE"],
            ["THE NEXT CUE IS NOT ITALIC"],
            ["<i>X</i>^3 * <i>X</i> = 100"],
        ], self::lines($translated));
        $this->assertSame([], $report->warnings);
    }


    public function testEntitiesOfARealFileSurvive(): void
    {
        $engine = new FakeTranslationEngine();

        (new TranslationRunner($engine))->translate($translated = self::readFile("srt/real/own_escaping.srt"), "en", "fr");

        $this->assertSame("I &lt;3 bread &amp; jam <x1>Salt &amp; pepper</x1> on the <x2>left</x2> " .
                          "Platform 2 &gt; platform 1 &lt; platform 3", $engine->calls[0]["texts"][0]);
        $this->assertSame([
            ["I &lt;3 BREAD &amp; JAM"],
            ["<i>SALT &amp; PEPPER</i> ON THE <b>LEFT</b>"],
            ["PLATFORM 2 &gt; PLATFORM 1 &lt; PLATFORM 3"],
            ["THE SIGN SAYS &amp;AMP; AND &amp;LT;B&amp;GT;"],
            ['<font color="#ffcc00">RAIN &amp; WIND &gt;&gt; 40 KM/H</font>'],
        ], self::lines($translated));
    }


    public function testDecodedAndOtherEntitiesFromTheEngineBecomeCoreText(): void
    {
        $engine = self::fixedEngine(["<x1>Tom & Jerry</x1> &#39;say&#39; a < b &amp; c&nbsp;d"]);

        (new TranslationRunner($engine))->translate($translated = TestSubtitles::fromTexts(["<b>Tom &amp; Jerry</b> sagen a &lt; b"], start: 1, step: 2), "de", "en");

        $this->assertSame(["<b>Tom &amp; Jerry</b> 'say' a &lt; b &amp; c\u{A0}d"], $translated->getCues()[0]->getLines());
    }


    public function testWordTimestampsAndSpeakerTagsBecomeSinglePlaceholders(): void
    {
        $engine = new FakeTranslationEngine();

        (new TranslationRunner($engine))->translate($translated = TestSubtitles::fromTexts(["<v Fred>Hi <00:00:01.500>there."], start: 1, step: 2), "en", "de");

        $this->assertSame("<x1/>Hi <x2/>there.", $engine->calls[0]["texts"][0]);
        $this->assertSame(["<v Fred>HI <00:00:01.500>THERE."], $translated->getCues()[0]->getLines());
    }


    public function testCuesWithOnlyNumbersSymbolsOrNoTextAreNotSent(): void
    {
        $subtitle = TestSubtitles::fromTexts(["\u{266A}\u{266B}", "1984", "...", "<i>\u{266A}</i>", "Hello."], start: 1, step: 2);
        $image    = new CueImage("png", 0, 0, 1, 1, 1, 1);
        $subtitle->addCue($image->toCue(new SubtitleCue(20, 21)));
        $engine   = new FakeTranslationEngine();

        (new TranslationRunner($engine))->translate($translated = $subtitle, "en", "de");

        $this->assertSame(["Hello."], $engine->calls[0]["texts"]);
        $this->assertSame([["\u{266A}\u{266B}"], ["1984"], ["..."], ["<i>\u{266A}</i>"], ["HELLO."], []], self::lines($translated));
        $this->assertTrue(CueImage::isImageCue($translated->getCues()[5]));
    }


    public function testAnEmptySubtitleCallsNoEngine(): void
    {
        $engine = new FakeTranslationEngine();

        (new TranslationRunner($engine))->translate($translated = new Subtitle(), "en", "de");

        $this->assertSame([], $engine->calls);
        $this->assertSame("de", $translated->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testSentenceJoiningStopsAtTheSentenceEndTheLimitAndAnUntranslatedCue(): void
    {
        $subtitle = TestSubtitles::fromTexts(["one", "two", "three", "four", "five.", "six", "\u{266A}", "seven", "\u{201C}Eight?\u{201D}", "nine",
                                    "\u{6B21}\u{3002}", "ten"], start: 1, step: 2);
        $engine   = new FakeTranslationEngine();

        (new TranslationRunner($engine))->translate($subtitle, "en", "de");

        $this->assertSame(["one two three", "four five.", "six", "seven \u{201C}Eight?\u{201D}", "nine \u{6B21}\u{3002}", "ten"],
                          $engine->calls[0]["texts"]);
    }


    public function testOptionsTurnOffJoiningAndSetTheCueLimit(): void
    {
        $engine = new FakeTranslationEngine();
        (new TranslationRunner($engine))->translate(TestSubtitles::fromTexts(["one", "two", "three."], start: 1, step: 2), "en", "de", new TranslationOptions(false));
        $this->assertSame(["one", "two", "three."], $engine->calls[0]["texts"]);

        $engine = new FakeTranslationEngine();
        (new TranslationRunner($engine))->translate(TestSubtitles::fromTexts(["one", "two", "three."], start: 1, step: 2), "en", "de", new TranslationOptions(maxCuesPerSentence: 2));
        $this->assertSame(["one two", "three."], $engine->calls[0]["texts"]);
    }


    public function testTheTranslationOfASentenceIsSplitInProportionToTheCharactersAtAWordBoundary(): void
    {
        $subtitle = TestSubtitles::fromTexts(["Regularly he takes part in events of", "the patient organization."], start: 1, step: 2);
        $engine   = self::fixedEngine(["Er nimmt regelmässig an Veranstaltungen der Patientenorganisation teil."]);

        (new TranslationRunner($engine))->translate($translated = $subtitle, "en", "de");

        $this->assertSame([["Er nimmt regelmässig an Veranstaltungen der"], ["Patientenorganisation teil."]], self::lines($translated));
    }


    public function testATranslationWithoutSpacesIsSplitBetweenCharacters(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>The train", "leaves now.</i>"], start: 1, step: 2);
        $engine   = self::fixedEngine(["<x1>\u{5217}\u{8F66}\u{73B0}\u{5728}\u{51FA}\u{53D1}\u{3002}</x1>"]);

        (new TranslationRunner($engine))->translate($translated = $subtitle, "en", "zh");

        $this->assertSame([["<i>\u{5217}\u{8F66}\u{73B0}</i>"], ["<i>\u{5728}\u{51FA}\u{53D1}\u{3002}</i>"]], self::lines($translated));
    }


    public function testATooShortTranslationLeavesACueEmptyAndGivesAWarning(): void
    {
        $runner = new TranslationRunner(self::fixedEngine(["Ja"]));

        $report = $runner->translate($translated = TestSubtitles::fromTexts(["Yes,", "of course."], start: 1, step: 2), "en", "de");

        $this->assertSame([["Ja"], []], self::lines($translated));
        $this->assertSame(1, $report->warnings[0]->cueIndex);
    }


    public function testBatchesRespectTheCharacterLimit(): void
    {
        $subtitle = TestSubtitles::fromTexts(["First sentence.", "Second sentence.", "Third one is", "split over cues.", str_repeat("Long. ", 10)], start: 1, step: 2);
        $engine   = new FakeTranslationEngine();

        (new TranslationRunner($engine))->translate($translated = $subtitle, "en", "de", new TranslationOptions(maxCharactersPerRequest: 32));

        $this->assertSame([
            ["First sentence.", "Second sentence."],
            ["Third one is split over cues."],
            [trim(str_repeat("Long. ", 10))],
        ], array_column($engine->calls, "texts"));
        $this->assertSame(["THIRD ONE IS"], $translated->getCues()[2]->getLines());
        $this->assertSame(["SPLIT OVER CUES."], $translated->getCues()[3]->getLines());
    }


    public function testAWrongNumberOfTranslationsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The translation engine must return one string per text, got 1 values for 2 texts.");

        (new TranslationRunner(self::fixedEngine(["One."])))->translate(TestSubtitles::fromTexts(["One.", "Two."], start: 1, step: 2), "en", "de");
    }


    public function testOptionsRejectLimitsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationOptions(maxCuesPerSentence: 0);
    }


    /**
     * @return array<string, array{string, Closure(FakeHttpClient): TranslationEngine, string, string, string, Format, string}>
     */
    public static function recordedTranslations(): array
    {
        return [
            "DeepL to WebVTT"      => ["own_station.srt", fn (FakeHttpClient $client) => new DeepLEngine(new DeepLOptions("key", httpClient: $client)),
                                       "deepl_de.json", "en", "de", Format::WebVtt, "own_station_de_deepl.vtt"],
            "Google"               => ["own_station.srt", fn (FakeHttpClient $client) => new GoogleTranslateEngine(new GoogleTranslateOptions("key", httpClient: $client)),
                                       "google_fr.json", "", "fr", Format::SubRip, "own_station_fr_google.srt"],
            "OpenAI-compatible"    => ["own_station.srt",
                                       fn (FakeHttpClient $client) => new OpenAiCompatibleEngine(new OpenAiCompatibleOptions("http://localhost/v1", "llama3", httpClient: $client)),
                                       "openai_de.json", "en", "de", Format::SubRip, "own_station_de_openai.srt"],
            "DeepL with entities"  => ["own_entities.srt", fn (FakeHttpClient $client) => new DeepLEngine(new DeepLOptions("key", httpClient: $client)),
                                       "deepl_entities_de.json", "en", "de", Format::SubRip, "own_entities_de_deepl.srt"],
        ];
    }


    /**
     * @param Closure(FakeHttpClient): TranslationEngine $engine
     */
    #[DataProvider("recordedTranslations")]
    public function testRecordedAnswersGiveTheGoldenFile(string $input, Closure $engine, string $answer, string $source, string $target,
                                                         Format $format, string $golden): void
    {
        $subtitle = Subtitle::load(self::FILES . "translation/$input", Format::SubRip);
        $client   = new FakeHttpClient([[200, (string)file_get_contents(self::FILES . "translation/$answer")]]);

        $report = (new TranslationRunner($engine($client)))->translate($subtitle, $source, $target);

        $this->assertStringEqualsFile(self::FILES . "translation/$golden", $subtitle->toString($format));
        $this->assertSame([], $report->warnings);
        $this->assertCount(1, $client->requests);
    }
}
