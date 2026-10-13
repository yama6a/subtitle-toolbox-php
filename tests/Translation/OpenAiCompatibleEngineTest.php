<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Http\FakeHttpClient;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require_once __DIR__ . "/../Http/FakeHttpClient.php";

class OpenAiCompatibleEngineTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/translation/";

    private const KEY = "sk-proj-4f1c9e2a7b3d";

    private const TEXTS = [
        "The train to Basel leaves from platform 4 at <x1>10:15</x1>.",
        "<x1>Tickets &amp; seat\nreservations</x1> are sold here.",
        "- Is this seat free?\n- Yes, it is.",
        "<x1>The next stop is Zurich main station.</x1>",
    ];

    private const GERMAN = [
        "Der Zug nach Basel fährt um <x1>10:15</x1> von Gleis 4 ab.",
        "<x1>Fahrkarten &amp; Platz-\nreservierungen</x1> gibt es hier.",
        "- Ist dieser Platz frei?\n- Ja, ist er.",
        "<x1>Der nächste Halt ist Zürich Hauptbahnhof.</x1>",
    ];

    /** @var list<int> */
    private array $waits = [];


    protected function setUp(): void
    {
        HttpRetry::$sleep = function (int $seconds): void {
            $this->waits[] = $seconds;
        };
    }


    protected function tearDown(): void
    {
        HttpRetry::$sleep = null;
    }


    /**
     * @return array{0: int, 1: string}
     */
    private static function answer(string $content): array
    {
        return [200, json_encode(["choices" => [["index" => 0, "message" => ["role" => "assistant", "content" => $content]]]])];
    }


    private static function engine(FakeHttpClient $client, ?string $apiKey = null, ?string $prompt = null): OpenAiCompatibleEngine
    {
        return new OpenAiCompatibleEngine(new OpenAiCompatibleOptions("http://localhost:11434/v1/", "llama3", $apiKey, $prompt, $client));
    }


    public function testSendsTheModelThePromptAndTheTextsAsOneJsonArrayWithoutAKey(): void
    {
        $client = new FakeHttpClient([[200, file_get_contents(self::FILES . "openai_de.json")]]);

        $translations = self::engine($client)->translate(self::TEXTS, "en", "de");

        $this->assertSame(self::GERMAN, $translations);
        $this->assertSame([[
            "url"     => "http://localhost:11434/v1/chat/completions",
            "headers" => ["Content-Type: application/json"],
            "body"    => ["model" => "llama3", "messages" => [
                ["role" => "system", "content" => "You translate subtitle text from en to de. The user sends a JSON array of strings. " .
                                                  "Translate each string on its own. Keep the tags such as <x1>, </x1> and <x2/> around the words " .
                                                  "that they mark, and keep the entities &lt;, &gt; and &amp;. Keep the line breaks. Answer only " .
                                                  "with a JSON array of strings that has as many strings as the input, in the same order, and no other text."],
                ["role" => "user", "content" => json_encode(self::TEXTS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ]],
        ]], $client->requests);
    }


    public function testSendsTheKeyAsABearerToken(): void
    {
        $client = new FakeHttpClient([self::answer('["Hallo"]')]);

        self::engine($client, self::KEY)->translate(["Hello"], "en", "de");

        $this->assertSame(["Content-Type: application/json", "Authorization: Bearer " . self::KEY], $client->requests[0]["headers"]);
    }


    public function testAPromptOfItsOwnGetsTheLanguageCodesAndAnEmptySourceAsText(): void
    {
        $client = new FakeHttpClient([self::answer('["Hallo"]')]);

        self::engine($client, prompt: "Translate {source} into {target} for children.")->translate(["Hello"], "", "de-CH");

        $this->assertSame("Translate the language of the text into de-CH for children.", $client->requests[0]["body"]["messages"][0]["content"]);
    }


    /**
     * @return array<string, array{string}>
     */
    public static function unreadableBatches(): array
    {
        return [
            "too few strings"  => ['["Der Zug", "Fahrkarten"]'],
            "too many strings" => ['["a", "b", "c", "d", "e"]'],
            "no JSON"          => ["Sure! Here is the translation."],
            "broken JSON"      => ['["Der Zug", "Fahrkarten", "Ist dieser", "Der nächste'],
            "not strings"      => ['[1, 2, 3, 4]'],
            "an object"        => ['{"text": "Der Zug"}'],
        ];
    }


    #[DataProvider("unreadableBatches")]
    public function testAnUnreadableBatchFallsBackToOneRequestPerText(string $content): void
    {
        $client = new FakeHttpClient(respond: fn (array $body): array => count(json_decode($body["messages"][1]["content"])) === 1
            ? self::answer(json_encode([self::GERMAN[array_search(json_decode($body["messages"][1]["content"])[0], self::TEXTS, true)]]))
            : self::answer($content));

        $translations = self::engine($client)->translate(self::TEXTS, "en", "de");

        $this->assertSame(self::GERMAN, $translations);
        $this->assertSame([json_encode(self::TEXTS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                           ...array_map(fn (string $text): string => json_encode([$text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::TEXTS)],
                          array_map(fn (array $request): string => $request["body"]["messages"][1]["content"], $client->requests));
    }


    public function testReadsTheArrayInACodeBlockAfterAThinkBlock(): void
    {
        $client = new FakeHttpClient([self::answer("<think>The user wants [German].</think>\n```json\n[\"Hallo\", \"Tschüss\"]\n```")]);

        $this->assertSame(["Hallo", "Tschüss"], self::engine($client)->translate(["Hello", "Bye"], "en", "de"));
        $this->assertCount(1, $client->requests);
    }


    #[DataProvider("reasoningFields")]
    public function testReadsTheArrayFromTheReasoningWhenTheContentIsEmpty(string $field): void
    {
        $answer = str_replace('"reasoning"', '"' . $field . '"', file_get_contents(self::FILES . "openai_reasoning_de.json"));
        $client = new FakeHttpClient([[200, $answer]]);

        $this->assertSame(self::GERMAN, self::engine($client)->translate(self::TEXTS, "en", "de"));
        $this->assertCount(1, $client->requests);
    }


    public static function reasoningFields(): array
    {
        return ["reasoning" => ["reasoning"], "reasoning_content" => ["reasoning_content"]];
    }


    public function testIgnoresTheReasoningWhenTheContentHasAnAnswer(): void
    {
        $answer = json_encode(["choices" => [["message" => ["content" => "[\"Hallo\", \"Tschüss\"]", "reasoning" => "[\"Hi\", \"Ciao\"]"]]]]);

        $this->assertSame(["Hallo", "Tschüss"], self::engine(new FakeHttpClient([[200, $answer]]))->translate(["Hello", "Bye"], "en", "de"));
    }


    public function testEmptyContentAndEmptyReasoningFailWithTheArrayMessage(): void
    {
        $answer = json_encode(["choices" => [["message" => ["content" => "", "reasoning" => ""]]]]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage("did not answer with a JSON array that holds 1 string for 1 text.");
        self::engine(new FakeHttpClient(respond: fn (): array => [200, $answer]))->translate(["Hello", "Bye"], "en", "de");
    }


    public function testAFallbackAnswerWithoutAJsonArrayFails(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage("TranslationException (Error #109): The model \"llama3\" did not answer with a JSON array that holds 1 string for 1 text.");

        self::engine(new FakeHttpClient([self::answer("Hallo"), self::answer("Hallo")]))->translate(["Hello", "Bye"], "en", "de");
    }


    public function testAnAnswerThatIsNotAChatCompletionFallsBackAndFails(): void
    {
        $client = new FakeHttpClient(respond: fn (): array => [200, "<html>Welcome</html>"]);

        try {
            self::engine($client)->translate(["Hello"], "en", "de");
            $this->fail("No exception");
        } catch (TranslationException $exception) {
            $this->assertStringContainsString("did not answer with a JSON array", $exception->getMessage());
        }
        $this->assertCount(2, $client->requests);
    }


    /**
     * @return array<string, array{int, string, string}>
     */
    public static function errors(): array
    {
        return [
            "401 key in message" => [401, '{"error": {"message": "Incorrect API key provided: ' . self::KEY . '."}}',
                                     "The OpenAI-compatible service rejected the API key (HTTP 401). Incorrect API key provided: ***."],
            "404 recorded"       => [404, file_get_contents(self::FILES . "openai_error.json"),
                                     "The OpenAI-compatible service answered with HTTP 404. The model `gpt-9` does not exist or you do not have access to it."],
            "404 Ollama"         => [404, '{"error": "model \"llama3\" not found, try pulling it first"}',
                                     "The OpenAI-compatible service answered with HTTP 404. model \"llama3\" not found, try pulling it first"],
            "429 busy"           => [429, '{"error": {"message": "Rate limit reached"}}',
                                     "The OpenAI-compatible service got too many requests (HTTP 429). Wait and try again."],
            "500 no JSON"        => [500, "Internal Server Error", "The OpenAI-compatible service answered with HTTP 500."],
        ];
    }


    #[DataProvider("errors")]
    public function testAnErrorStatusNamesTheCauseWithoutTheKey(int $status, string $body, string $message): void
    {
        try {
            self::engine(new FakeHttpClient(respond: fn (): array => [$status, $body]), self::KEY)->translate(["Hello"], "en", "de");
            $this->fail("No exception");
        } catch (TranslationException $exception) {
            $this->assertSame("TranslationException (Error #109): $message", $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }


    public function testRetriesHttp429(): void
    {
        $client = new FakeHttpClient([[429, "{}"], self::answer('["Hallo"]')]);

        $this->assertSame(["Hallo"], self::engine($client)->translate(["Hello"], "en", "de"));
        $this->assertSame([1], $this->waits);
    }


    public function testNoTextsSendNoRequest(): void
    {
        $client = new FakeHttpClient();

        $this->assertSame([], self::engine($client)->translate([], "en", "de"));
        $this->assertSame([], $client->requests);
    }


    public function testTheRunnerKeepsTheTagsOfARealFile(): void
    {
        $subtitle = Subtitle::load(self::FILES . "own_station.srt", Format::SubRip);

        $report = (new TranslationRunner(self::engine(new FakeHttpClient([[200, file_get_contents(self::FILES . "openai_de.json")]]))))
            ->translate($subtitle, "en", "de");

        $this->assertSame([], $report->warnings);
        $this->assertSame([
            ["Der Zug nach Basel fährt"],
            ["um <b>10:15</b> von Gleis 4 ab."],
            ["\u{266A} \u{266A}"],
            ["<i>Fahrkarten &amp; Platz-", "reservierungen</i> gibt es hier."],
            ["2024"],
            ["- Ist dieser Platz frei?", "- Ja, ist er."],
            ["<i>Der nächste Halt ist</i>"],
            ["<i>Zürich Hauptbahnhof.</i>"],
        ], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
    }


    public function testADroppedPlaceholderGivesAWarning(): void
    {
        $subtitle = Subtitle::load(self::FILES . "own_station.srt", Format::SubRip);
        $german   = self::GERMAN;
        $german[3] = "Der nächste Halt ist Zürich Hauptbahnhof.";

        $report = (new TranslationRunner(self::engine(new FakeHttpClient([self::answer(json_encode($german))]))))->translate($subtitle, "en", "de");

        $this->assertSame([6, 7], array_map(fn (TranslationWarning $warning): int => $warning->cueIndex, $report->warnings));
        $this->assertSame(["Der nächste Halt ist"], $subtitle->getCues()[6]->getLines());
    }
}
