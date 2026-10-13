<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class CloudSpeechParsersTest extends TestCase
{
    private const AWS_HELLO_WORLD = <<<'JSON'
        {"jobName": "lecture-12", "results": {
            "transcripts": [{"transcript": "Hello world."}],
            "items": [
            {"type": "pronunciation", "start_time": "0.04", "end_time": "0.51", "alternatives": [{"confidence": "0.99", "content": "Hello"}]},
            {"type": "pronunciation", "start_time": "0.51", "end_time": "0.98", "alternatives": [{"confidence": "0.98", "content": "world"}]},
            {"type": "punctuation", "alternatives": [{"confidence": "0.0", "content": "."}]}
          ]}}
        JSON;



    /**
     * @param list<array{string, int, int, 3?: string}> $words text, start and end in milliseconds, speaker
     */
    private static function assemblyAiWords(array $words): string
    {
        return json_encode(["words" => array_map(
            fn (array $word): array => ["text" => $word[0], "start" => $word[1], "end" => $word[2], "confidence" => 0.9, "speaker" => $word[3] ?? null],
            $words
        )]);
    }


    public function testReadsAnAwsTranscribeJobAsOneCueWithItsConfidences(): void
    {
        $subtitle = Subtitle::fromString(self::AWS_HELLO_WORLD, Format::AwsTranscribe);

        $this->assertSame([[0.04, 0.98, "Hello world."]], TestSubtitles::describe($subtitle));
        $this->assertSame(["jobName" => "lecture-12"], $subtitle->findFormatData("aws-transcribe"));
        $this->assertSame(["0.99", "0.98", "0.0"],
                          array_column(array_column(array_column($subtitle->getCues()[0]->findFormatData("aws-transcribe")["items"], "alternatives"), 0), "confidence"));
    }


    public function testWritesWordTimestampsWithTheOption(): void
    {
        $parser = new AwsTranscribeParser();

        $this->assertSame("<00:00:00.040>Hello <00:00:00.510>world.", $parser->parse(self::AWS_HELLO_WORLD, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]))->getCues()[0]->getText());
    }


    public static function groupings(): array
    {
        return [
            "sentence end"            => [[["One.", 0, 300], ["Two", 400, 700]], [[0.0, 0.3, "One."], [0.4, 0.7, "Two"]]],
            "question mark and quote" => [[["Why?\"", 0, 300], ["Two", 400, 700]], [[0.0, 0.3, "Why?\""], [0.4, 0.7, "Two"]]],
            "lower case after a dot"  => [[["e.g.", 0, 300], ["this", 400, 700]], [[0.0, 0.7, "e.g. this"]]],
            "CJK full stop"           => [[["\u{3067}\u{3059}\u{3002}", 0, 300], ["\u{306F}\u{3044}", 400, 700]],
                                          [[0.0, 0.3, "\u{3067}\u{3059}\u{3002}"], [0.4, 0.7, "\u{306F}\u{3044}"]]],
            "gap of 1 s"              => [[["wait", 0, 300], ["then", 1300, 1600]], [[0.0, 0.3, "wait"], [1.3, 1.6, "then"]]],
            "gap below 1 s"           => [[["wait", 0, 300], ["then", 1299, 1600]], [[0.0, 1.6, "wait then"]]],
            "speaker change"          => [[["Hi", 0, 300, "A"], ["there", 400, 700, "B"]], [[0.0, 0.3, "Hi"], [0.4, 0.7, "there"]]],
            "84 characters"           => [[[str_repeat("a", 41), 0, 300], [str_repeat("b", 42), 300, 600], ["c", 600, 900]],
                                          [[0.0, 0.6, str_repeat("a", 41) . " " . str_repeat("b", 42)], [0.6, 0.9, "c"]]],
            "84 characters in UTF-8"  => [[[str_repeat("\u{e9}", 41), 0, 300], [str_repeat("\u{e8}", 42), 300, 600]],
                                          [[0.0, 0.6, str_repeat("\u{e9}", 41) . " " . str_repeat("\u{e8}", 42)]]],
            "84 characters with marks" => [[[str_repeat("e\u{301}", 41), 0, 300], [str_repeat("a\u{308}", 42), 300, 600], ["c", 600, 900]],
                                          [[0.0, 0.6, str_repeat("e\u{301}", 41) . " " . str_repeat("a\u{308}", 42)], [0.6, 0.9, "c"]]],
        ];
    }


    #[DataProvider("groupings")]
    public function testGroupsWordsIntoCues(array $words, array $expected): void
    {
        $this->assertSame($expected, TestSubtitles::describe((new AssemblyAiParser())->parse(self::assemblyAiWords($words), new ReadOptions())));
    }


    public function testWritesTheSpeakerAsVoiceTagOnlyWithTheOption(): void
    {
        $json = self::assemblyAiWords([["Hi.", 0, 300, "O'Neil"], ["Bye.", 400, 700, "B"]]);

        $this->assertSame([[0.0, 0.3, "Hi."], [0.4, 0.7, "Bye."]], TestSubtitles::describe((new AssemblyAiParser())->parse($json, new ReadOptions())));
        $this->assertSame([[0.0, 0.3, "<v O'Neil>Hi."], [0.4, 0.7, "<v B>Bye."]],
                          TestSubtitles::describe((new AssemblyAiParser())->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]))));
    }


    public function testEscapesTextAndKeepsItsMarkup(): void
    {
        $json = self::assemblyAiWords([["<b>", 0, 300], ["&", 300, 600]]);

        $this->assertSame([[0.0, 0.6, "&lt;b&gt; &amp;"]], TestSubtitles::describe((new AssemblyAiParser())->parse($json, new ReadOptions())));
    }


    public function testAssemblyAiConvertsTheLanguageCodeToBcp47(): void
    {
        $parse = fn (string $code): ?string => (new AssemblyAiParser())->parse('{"language_code": "' . $code . '", "words": []}', new ReadOptions())
                                                                       ->findMetadata(Subtitle::METADATA_LANGUAGE);

        $this->assertSame(["en-US", "en-AU", "de"], [$parse("en_us"), $parse("en_au"), $parse("de")]);
    }


    public function testAwsJoinsALeadingPunctuationItemToTheNextWord(): void
    {
        $json = '{"results": {"transcripts": [], "items": [' .
                '{"type": "punctuation", "alternatives": [{"content": "¿"}]},' .
                '{"type": "pronunciation", "start_time": "1.0", "end_time": "1.5", "alternatives": [{"content": "Vienes"}]},' .
                '{"type": "punctuation", "alternatives": [{"content": "?"}]}]}}';

        $this->assertSame([[1.0, 1.5, "\u{bf}Vienes?"]], TestSubtitles::describe((new AwsTranscribeParser())->parse($json, new ReadOptions())));
    }


    public function testAwsWithoutSegmentsUsesTheSpeakerOfEachItem(): void
    {
        $json = '{"results": {"transcripts": [], "items": [' .
                '{"type": "pronunciation", "start_time": "0.1", "end_time": "0.4", "speaker_label": "spk_0", "alternatives": [{"content": "Yes"}]},' .
                '{"type": "pronunciation", "start_time": "0.5", "end_time": "0.9", "speaker_label": "spk_1", "alternatives": [{"content": "No"}]}]}}';
        $parser = new AwsTranscribeParser();

        $this->assertSame([[0.1, 0.4, "<v spk_0>Yes"], [0.5, 0.9, "<v spk_1>No"]], TestSubtitles::describe($parser->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]))));
    }


    public function testDeepgramReadsEveryChannelInTimeOrder(): void
    {
        $json = '{"results": {"channels": [' .
                '{"alternatives": [{"words": [{"word": "later", "start": 2.0, "end": 2.5}]}]},' .
                '{"alternatives": [{"words": [{"word": "first", "start": 0.5, "end": 1.0, "punctuated_word": "First."}]}]}]}}';
        $subtitle = (new DeepgramParser())->parse($json, new ReadOptions());

        $this->assertSame([[0.5, 1.0, "First."], [2.0, 2.5, "later"]], TestSubtitles::describe($subtitle));
        $this->assertSame([1, 0], array_map(fn (SubtitleCue $cue): int => $cue->findFormatData("deepgram")["channel"], $subtitle->getCues()));
    }


    public function testDeepgramParagraphsUseTheSpeakerOfTheParagraph(): void
    {
        $json = '{"results": {"channels": [{"alternatives": [{"words": [], "paragraphs": {"paragraphs": [' .
                '{"speaker": 1, "sentences": [{"text": "Hello there.", "start": 0.2, "end": 1.1}]}]}}]}]}}';
        $parser = new DeepgramParser();

        $this->assertSame([[0.2, 1.1, "<v 1>Hello there."]], TestSubtitles::describe($parser->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]))));
    }


    public function testGoogleV1DiarizationReadsTheWordsOfTheLastResult(): void
    {
        $json = '{"results": [' .
                '{"alternatives": [{"transcript": "hi there", "words": [{"startTime": "0s", "endTime": "0.300s", "word": "hi"}, ' .
                '{"startTime": "0.300s", "endTime": "0.600s", "word": "there"}]}]},' .
                '{"alternatives": [{"words": [{"startTime": "0s", "endTime": "0.300s", "word": "hi", "speakerTag": 1}, ' .
                '{"startTime": "0.300s", "endTime": "0.600s", "word": "there", "speakerTag": 2}]}]}]}';
        $parser = new GoogleSpeechParser();

        $this->assertSame([[0.0, 0.3, "<v 1>hi"], [0.3, 0.6, "<v 2>there"]], TestSubtitles::describe($parser->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]))));
    }


    public function testGoogleResultsWithoutWordsSpanFromTheEndOfTheResultBefore(): void
    {
        $json = '{"results": [{"alternatives": [{"transcript": "first part"}], "resultEndTime": "4.500s"},' .
                '{"resultEndTime": "5s"},' .
                '{"alternatives": [{"transcript": " second part"}], "resultEndTime": {"seconds": "9", "nanos": 250000000}}]}';

        $this->assertSame([[0.0, 4.5, "first part"], [5.0, 9.25, "second part"]], TestSubtitles::describe((new GoogleSpeechParser())->parse($json, new ReadOptions())));
    }


    public static function brokenWords(): array
    {
        return [
            "Amazon Transcribe" => [AwsTranscribeParser::class, '{"results": {"transcripts": [], "items": [' .
                '{"type": "pronunciation", "start_time": "soon", "end_time": "1", "alternatives": [{"content": "Hi"}]},' .
                '{"type": "pronunciation", "start_time": "1", "end_time": "2", "alternatives": [{"content": "there"}]}]}}',
                "The field results.items[0].start_time must be a time.", [[1.0, 2.0, "there"]]],
            "Deepgram"          => [DeepgramParser::class, '{"results": {"channels": [{"alternatives": [{"words": [' .
                '{"word": "hi", "start": 0}, {"word": "there", "start": 1, "end": 2}]}]}]}}',
                "The field results.channels[0].alternatives[0].words[0].end must be a time.", [[1.0, 2.0, "there"]]],
            "AssemblyAI"        => [AssemblyAiParser::class, '{"words": [{"text": null}, {"text": "there", "start": 1000, "end": 2000}]}',
                "The field words[0].text must be a string.", [[1.0, 2.0, "there"]]],
            "Google"            => [GoogleSpeechParser::class, '{"results": [{"alternatives": [{"transcript": "hi there", "words": [' .
                '{"word": "hi", "startTime": "soon", "endTime": "1s"}, {"word": "there", "startTime": "1s", "endTime": "2s"}]}]}]}',
                "The field results[0].alternatives[0].words[0].startTime must be a time.", [[1.0, 2.0, "hi there"]]],
        ];
    }


    #[DataProvider("brokenWords")]
    public function testThrowsForABrokenWordAndSkipsItInLenientMode(string $parserClass, string $json, string $message, array $cues): void
    {
        $parser   = new $parserClass();
        $subtitle = $parser->parse($json, new ReadOptions(lenient: true));

        $this->assertSame($cues, TestSubtitles::describe($subtitle));
        $this->assertCount(1, $subtitle->getParseWarnings());
        $this->assertSame([$message, 0, ParseWarningAction::Skipped],
                          [$subtitle->getParseWarnings()[0]->message, $subtitle->getParseWarnings()[0]->blockIndex, $subtitle->getParseWarnings()[0]->action]);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);
        (new $parserClass())->parse($json, new ReadOptions());
    }


    public function testSkipsABrokenUtteranceInLenientMode(): void
    {
        $subtitle = (new AssemblyAiParser())->parse('{"words": [], "utterances": [{"start": 0, "text": "Hi"}, {"start": 1000, "end": 2000, "text": "Bye"}]}', new ReadOptions(lenient: true));

        $this->assertSame([[1.0, 2.0, "Bye"]], TestSubtitles::describe($subtitle));
        $this->assertSame("The field utterances[0].end must be a time.", $subtitle->getParseWarnings()[0]->message);
    }


    public function testDeepgramCountsTheSentencesOfEachParagraphFromZero(): void
    {
        $json = '{"results": {"channels": [{"alternatives": [{"words": [], "paragraphs": {"paragraphs": [' .
                '{"sentences": [{"text": "Hi.", "start": 0}, {"text": "Bye.", "start": 1, "end": 2}]},' .
                '{"sentences": [{"text": "Hi again.", "start": 3}]}]}}]}]}}';
        $path = "The field results.channels[0].alternatives[0].paragraphs.paragraphs";

        $subtitle = (new DeepgramParser())->parse($json, new ReadOptions(lenient: true));

        $this->assertSame([[1.0, 2.0, "Bye."]], TestSubtitles::describe($subtitle));
        $this->assertSame([["{$path}[0].sentences[0].end must be a time.", 0], ["{$path}[1].sentences[0].end must be a time.", 0]],
                          array_map(fn ($warning): array => [$warning->message, $warning->blockIndex], $subtitle->getParseWarnings()));
    }


    public function testDetectionDoesNotTakeWhisperJson(): void
    {
        foreach (glob(__DIR__ . "/../files/whisper/real/*.json") as $path) {
            $this->assertSame(Format::Whisper, Format::detect(file_get_contents($path)), basename($path));
        }
    }
}
