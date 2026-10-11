<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

/**
 * Checks that a time of 100,000 hours or more throws in strict mode and skips the cue in lenient mode.
 */
class TimesPastTheLimitTest extends TestCase
{
    private const SKIPPED = ParseWarningAction::Skipped;


    /**
     * Each case holds the parser, the content, the message, the cues in lenient mode and the line number and block of the warning.
     */
    public static function timesPastTheLimit(): array
    {
        return [
            "MPL2 with a deciseconds value of 14 digits" => [
                Mpl2Parser::class,
                "[10][30]The pool opens at seven.\n" .
                "[36000000000][36000000010]Towels are at the desk.\n" .
                "[70][90]No running, please.\n",
                "The time \"[36000000000]\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [2, 1],
            ],
            "MPSub with a wait of 100000 hours" => [
                MpSubParser::class,
                "FORMAT=TIME\n" .
                "\n" .
                "1 2\n" .
                "The pool opens at seven.\n" .
                "\n" .
                "360000000 2\n" .
                "Towels are at the desk.\n" .
                "\n" .
                "4 2\n" .
                "No running, please.\n",
                "The time \"360000000 2\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [6, 1],
            ],
            "SAMI with a Start of 15 digits" => [
                SamiParser::class,
                "<SAMI>\n" .
                "<BODY>\n" .
                "<SYNC Start=1000><P>The pool opens at seven.\n" .
                "<SYNC Start=3000><P>&nbsp;\n" .
                "<SYNC Start=900000000000000><P>Towels are at the desk.\n" .
                "<SYNC Start=7000><P>No running, please.\n" .
                "<SYNC Start=9000><P>&nbsp;\n" .
                "</BODY>\n" .
                "</SAMI>\n",
                "The time \"900000000000000\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [5, 2],
            ],
            "LRC with an offset that moves a line past the limit" => [
                LyricsParser::class,
                "[offset:-359999990000]\n" .
                "[00:01.00]The pool opens at seven.\n" .
                "[00:20.00]Towels are at the desk.\n",
                "The time \"[00:20.00]\" is not below 100000 hours.",
                [[359999991, 359999996, "The pool opens at seven."]],
                [NULL, 2],
            ],
            "ASS with a karaoke duration of 17 digits" => [
                AssParser::class,
                "[Script Info]\n" .
                "ScriptType: v4.00+\n" .
                "\n" .
                "[Events]\n" .
                "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" .
                "Dialogue: 0,0:00:01.00,0:00:03.00,Default,,0,0,0,,The pool opens at seven.\n" .
                "Dialogue: 0,0:00:04.00,0:00:06.00,Default,,0,0,0,,{\\k99999999999999999}Towels {\\k50}are at the desk.\n" .
                "Dialogue: 0,0:00:07.00,0:00:09.00,Default,,0,0,0,,No running, please.\n",
                "The time \"\\k99999999999999999\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [7, 1],
            ],
            "Whisper JSON with a word start of 1e20" => [
                WhisperJsonParser::class,
                '{"segments": [{"start": 1, "end": 3, "text": " The pool opens at seven."}, {"start": 4, "end": 6, "text": " Towels are at the desk.", "words": [{"word": " Towels", "start": 1e20, "end": 1e20}]}, {"start": 7, "end": 9, "text": " No running, please."}]}',
                "The field segments[1].words[0].start is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "whisper.cpp JSON with a token offset of 1e20" => [
                WhisperJsonParser::class,
                '{"transcription": [{"offsets": {"from": 1000, "to": 3000}, "text": " The pool opens at seven."}, {"offsets": {"from": 4000, "to": 6000}, "text": " Towels", "tokens": [{"text": " Towels", "offsets": {"from": 1e20, "to": 1e20}}]}, {"offsets": {"from": 7000, "to": 9000}, "text": " No running, please."}]}',
                "The field transcription[1].tokens[0].offsets.from is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "YouTube json3 with a segment offset of 1e20" => [
                YouTubeTimedTextParser::class,
                '{"events": [{"tStartMs": 1000, "dDurationMs": 2000, "segs": [{"utf8": "The pool opens at seven."}]}, {"tStartMs": 4000, "dDurationMs": 2000, "segs": [{"utf8": "Towels"}, {"utf8": " are at the desk.", "tOffsetMs": 1e20}]}, {"tStartMs": 7000, "dDurationMs": 2000, "segs": [{"utf8": "No running, please."}]}]}',
                "The field events[1].segs[1].tOffsetMs is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "YouTube srv3 with a duration of 18 digits" => [
                YouTubeTimedTextParser::class,
                "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n" .
                "<timedtext format=\"3\">\n" .
                "<body>\n" .
                "<p t=\"1000\" d=\"2000\">The pool opens at seven.</p>\n" .
                "<p t=\"4000\" d=\"900000000000000000\">Towels are at the desk.</p>\n" .
                "<p t=\"7000\" d=\"2000\">No running, please.</p>\n" .
                "</body>\n" .
                "</timedtext>\n",
                "The time \"900000000000000000\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [5, 1],
            ],
            "Podcast transcript with an end of 1e20" => [
                PodcastTranscriptParser::class,
                '{"version": "1.0.0", "segments": [{"startTime": 1, "endTime": 3, "body": "The pool opens at seven."}, {"startTime": 4, "endTime": 1e20, "body": "Towels are at the desk."}, {"startTime": 7, "endTime": 9, "body": "No running, please."}]}',
                "The field segments[1].endTime is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [NULL, 1],
            ],
            "AssemblyAI with a word end of 1e20 milliseconds" => [
                AssemblyAiParser::class,
                '{"words": [{"text": "Pool.", "start": 1000, "end": 3000}, {"text": "Towels.", "start": 4000, "end": 1e20}, {"text": "Running.", "start": 7000, "end": 9000}]}',
                "The field words[1].end is not below 100000 hours.",
                [[1, 3, "Pool."], [7, 9, "Running."]],
                [NULL, 1],
            ],
            "Google Speech with a result end of 1e20 seconds" => [
                GoogleSpeechParser::class,
                '{"results": [{"alternatives": [{"transcript": "Pool.", "words": [{"word": "Pool.", "startTime": "1s", "endTime": "3s"}]}]}, {"alternatives": [{"transcript": "Towels."}], "resultEndTime": "100000000000000000000s"}, {"alternatives": [{"transcript": "Running.", "words": [{"word": "Running.", "startTime": "7s", "endTime": "9s"}]}]}]}',
                "The field results[1].resultEndTime is not below 100000 hours.",
                [[1, 3, "Pool."], [7, 9, "Running."]],
                [NULL, 1],
            ],
            "TTML with a div and a p that begin at 99999 hours each" => [
                TtmlParser::class,
                "<tt xmlns=\"http://www.w3.org/ns/ttml\">\n" .
                "<body>\n" .
                "<div>\n" .
                "<p begin=\"1s\" end=\"3s\">The pool opens at seven.</p>\n" .
                "</div>\n" .
                "<div begin=\"99999h\">\n" .
                "<p begin=\"99999h\" end=\"99999h\">Towels are at the desk.</p>\n" .
                "</div>\n" .
                "<div>\n" .
                "<p begin=\"7s\" end=\"9s\">No running, please.</p>\n" .
                "</div>\n" .
                "</body>\n" .
                "</tt>\n",
                "The time \"99999h\" is not below 100000 hours.",
                [[1, 3, "The pool opens at seven."], [7, 9, "No running, please."]],
                [7, 1],
            ],
        ];
    }


    #[DataProvider("timesPastTheLimit")]
    public function testATimeOf100000HoursOrMoreThrowsOrSkipsTheCue(
        string $parserClass,
        string $content,
        string $message,
        array $expectedCues,
        array $warningPlace
    ): void {
        $subtitle = (new $parserClass())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals($expectedCues, TestSubtitles::describe($subtitle));
        $this->assertSame([[...$warningPlace, self::SKIPPED, $message]], TestSubtitles::warnings($subtitle));

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);
        (new $parserClass())->parse($content, new ReadOptions());
    }
}
