<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WhisperJsonParserTest extends TestCase
{
    private const TWO_SEGMENTS_WITH_WORDS = <<<'JSON'
        {
                    "task": "transcribe",
                    "language": "english",
                    "duration": 8.47,
                    "text": "The beach was quiet. Nobody came.",
                    "segments": [
            {"id": 0, "start": 0.0, "end": 3.32, "text": " The beach was quiet."},
            {"id": 1, "start": 3.9, "end": 5.1, "text": " Nobody came."}
          ],
                    "words": [
            {"word": "The", "start": 0.0, "end": 0.24},
            {"word": "beach", "start": 0.24, "end": 0.71}
          ]
        }
        JSON;


    private static function withWords(string $json): Subtitle
    {
        return (new WhisperJsonParser())->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(wordTimestamps: true)]));
    }


    public function testReadsOneCuePerSegmentAndKeepsTheFieldsAsFormatData(): void
    {
        $subtitle = Subtitle::fromString(self::TWO_SEGMENTS_WITH_WORDS);

        $this->assertSame("en", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(["format" => "openai-api", "task" => "transcribe", "language" => "english", "duration" => 8.47],
                          $subtitle->findFormatData("whisper"));
        $this->assertSame([[0.0, 3.32, "The beach was quiet."], [3.9, 5.1, "Nobody came."]],
                          array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues()));
        $this->assertSame(["id" => 0, "start" => 0.0, "end" => 3.32, "text" => " The beach was quiet.", "words" => [["word" => "The", "start" => 0.0, "end" => 0.24], ["word" => "beach", "start" => 0.24, "end" => 0.71]]],
                          $subtitle->getCues()[0]->findFormatData("whisper"));
    }


    public function testWritesWordTimestampsWithTheOption(): void
    {
        $this->assertSame("<00:00:00.000>The <00:00:00.240>beach was quiet.", self::withWords(self::TWO_SEGMENTS_WITH_WORDS)->getCues()[0]->getText());
    }


    public function testSkipsWordsWithoutTimeAndWordsThatTheTextDoesNotHold(): void
    {
        $subtitle = self::withWords('{"segments": [{"start": 1, "end": 4, "text": " Tea, then toast <now> & later.", "words": [' .
                                    '{"word": " Tea,", "start": 1}, {"word": " coffee", "start": 1.5}, {"word": " then"}, ' .
                                    '{"word": " toast", "start": 2.25}, {"word": " <now>", "start": 3}, {"word": " &", "start": 3.5}]}]}');

        $this->assertSame("<00:00:01.000>Tea, then <00:00:02.250>toast <00:00:03.000>&lt;now&gt; <00:00:03.500>&amp; later.",
                          $subtitle->getCues()[0]->getText());
    }


    public function testEscapesTextWithoutTheOption(): void
    {
        $this->assertSame("Fish &amp; chips &lt;3", (new WhisperJsonParser())->parse(
            '{"segments": [{"start": 0, "end": 1, "text": " Fish & chips <3"}]}', new ReadOptions())->getCues()[0]->getText());
    }


    public function testTrimsTextAndSkipsSegmentsWithoutText(): void
    {
        $subtitle = (new WhisperJsonParser())->parse("\xEF\xBB\xBF" . '{"segments": [{"start": 0, "end": 1, "text": " One. "}, ' .
                                                     '{"start": 1, "end": 1, "text": ""}, {"start": 1, "end": 2, "text": "  "}, ' .
                                                     '{"start": 2, "end": 3, "text": "Two."}]}', new ReadOptions());

        $this->assertSame(["One.", "Two."], array_map(fn (SubtitleCue $cue) => $cue->getText(), $subtitle->getCues()));
    }


    public function testKeepsALongSegmentAsOneCue(): void
    {
        $text = str_repeat("The bus stops at every corner on the way to the station. ", 8);
        $cues = (new WhisperJsonParser())->parse(json_encode(["segments" => [["start" => 0, "end" => 30, "text" => $text]]]), new ReadOptions())->getCues();

        $this->assertCount(1, $cues);
        $this->assertSame([trim($text)], $cues[0]->getLines());
    }


    public static function languages(): array
    {
        return [
            "API name"         => ["english", "en"],
            "capitalised name" => ["German", "de"],
            "alias"            => ["castilian", "es"],
            "code"             => ["fr", "fr"],
            "three letters"    => ["yue", "yue"],
            "unknown"          => ["Klingon", "Klingon"],
        ];
    }


    #[DataProvider("languages")]
    public function testConvertsLanguageNamesToCodes(string $language, string $code): void
    {
        $subtitle = (new WhisperJsonParser())->parse(json_encode(["language" => $language, "segments" => []]), new ReadOptions());

        $this->assertSame($code, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testReadsWhisperCppOffsetsAndGroupsTokensIntoWords(): void
    {
        $subtitle = self::withWords('{"result": {"language": "nl"}, "transcription": [{"timestamps": {"from": "00:00:01,000", "to": "00:00:03,500"}, ' .
                                    '"offsets": {"from": 1000, "to": 3500}, "text": " Good morning.", "tokens": [' .
                                    '{"text": "[_BEG_]", "offsets": {"from": 1000, "to": 1000}}, {"text": " Good", "offsets": {"from": 1000, "to": 1400}}, ' .
                                    '{"text": " mor", "offsets": {"from": 1400, "to": 1700}}, {"text": "ning", "offsets": {"from": 1700, "to": 2000}}, ' .
                                    '{"text": ".", "offsets": {"from": 2000, "to": 2100}}, {"text": "[_TT_125]", "offsets": {"from": 3500, "to": 3500}}]}]}');
        $cue      = $subtitle->getCues()[0];

        $this->assertSame("nl", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame([1.0, 3.5, "<00:00:01.000>Good <00:00:01.400>morning."], [$cue->getStart(), $cue->getEnd(), $cue->getText()]);
        $this->assertCount(6, $cue->findFormatData("whisper")["tokens"]);
    }


    public static function invalidFiles(): array
    {
        return [
            "no JSON"             => ["{", "The content is not valid JSON: Syntax error."],
            "root list"           => ["[1]", "The JSON root must be an object."],
            "only text"           => ['{"text": "Hello"}', 'The JSON has no "segments" or "transcription" list.'],
            "only words"          => ['{"words": [{"word": "Hi", "start": 0, "end": 1}]}', 'The JSON has no "segments" or "transcription" list.'],
            "start no number"     => ['{"segments": [{"start": "0", "end": 1, "text": "Hi"}]}', "The field segments[0].start must be a number."],
            "segment no object"   => ['{"segments": [5]}', "The field segments[0].start must be a number."],
            "no text"             => ['{"segments": [{"start": 0, "end": 1}]}', "The field segments[0].text must be a string."],
            "whisper.cpp offsets" => ['{"transcription": [{"offsets": {"from": 0}, "text": "Hi"}]}', "The field transcription[0].offsets.to must be a number."],
            "negative start"      => ['{"segments": [{"id": 0, "start": -0.5, "end": 1.0, "text": "Hello there"}]}', "The field segments[0].start must be a time of 0 or more."],
            "negative offsets"    => ['{"transcription": [{"offsets": {"from": -500, "to": 1000}, "text": "Hi"}]}', "The field transcription[0].offsets.from must be a time of 0 or more."],
        ];
    }


    #[DataProvider("invalidFiles")]
    public function testThrowsParsingExceptionWithThePathOfTheBadField(string $json, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new WhisperJsonParser())->parse($json, new ReadOptions());
    }


    public function testASegmentWithANumberOutOfRangeFailsWithAParsingException(): void
    {
        $json = file_get_contents(__DIR__ . "/../files/whisper/own_out_of_range_number.json");

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("segments[1].start");
        (new WhisperJsonParser())->parse($json, new ReadOptions());
    }


    public function testLenientModeSkipsASegmentWithANumberOutOfRange(): void
    {
        $json     = file_get_contents(__DIR__ . "/../files/whisper/own_out_of_range_number.json");
        $subtitle = (new WhisperJsonParser())->parse($json, new ReadOptions(lenient: true));
        $warnings = $subtitle->getParseWarnings();

        $this->assertSame(["La boulangerie ouvre à sept heures."], $subtitle->getCues()[0]->getLines());
        $this->assertCount(1, $subtitle->getCues());
        $this->assertCount(1, $warnings);
        $this->assertSame(1, $warnings[0]->blockIndex);
        $this->assertStringContainsString('"avg_logprob":0,', $warnings[0]->block[0]);
    }
}
