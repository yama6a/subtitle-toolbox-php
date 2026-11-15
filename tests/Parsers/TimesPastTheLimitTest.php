<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
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
            "SCC with a time code past 100000 hours, issue #1315" => [
                SccParser::class,
                SccParser::HEADER . "\n" .
                "\n" .
                "00:00:00;20\t94ae 94ae 9420 9420 9476 9476 97a2 97a2 d04f 4f4c 942f 942f\n" .
                "00:00:03;00\t942c 942c\n" .
                "100001:00:03;19\t94ae 94ae 9420 9420 9476 9476 97a1 97a1 544f 5745 4cd3 942f 942f\n" .
                "00:00:06;20\t94ae 94ae 9420 9420 9476 9476 52d5 cece 49ce c780 942f 942f\n" .
                "00:00:09;00\t942c 942c\n",
                "The time \"100001:00:03;19\" is not below 100000 hours.",
                [[1.001, 3.003, "POOL"], [7.007, 9.009, "RUNNING"]],
                [5, 3],
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


    /**
     * Each case holds the parser, the content and the message. The time is below the limit until it rounds to milliseconds.
     */
    public static function timesThatRoundToTheLimit(): array
    {
        return [
            "TTML begin 99999:59:59.9999" => [
                TtmlParser::class,
                '<?xml version="1.0" encoding="UTF-8"?><tt xmlns="http://www.w3.org/ns/ttml"><body><div>' .
                '<p begin="99999:59:59.9999" end="99999:59:59.9999">x</p></div></body></tt>',
                "The time \"99999:59:59.9999\" is not below 100000 hours.",
            ],
            "CSV clock time 99999:59:59.9999, issue #1303" => [
                CsvParser::class,
                "start,text\n99999:59:59.9999,x\n",
                "The time \"99999:59:59.9999\" is not below 100000 hours.",
            ],
            "OGM chapter on the last half millisecond, issue #1318" => [
                OgmChaptersParser::class,
                "CHAPTER01=99999:59:59.9995\nCHAPTER01NAME=x\n",
                "The time \"CHAPTER01=99999:59:59.9995\" is not below 100000 hours.",
            ],
            "Podcast chapter on the last half millisecond, issue #1318" => [
                PodcastChaptersParser::class,
                '{"version":"1.2.0","chapters":[{"startTime":359999999.9995,"title":"x"}]}',
                "is not below 100000 hours.",
            ],
            "YouTube srv3 t of 359999999999.9 ms" => [
                YouTubeTimedTextParser::class,
                '<?xml version="1.0" encoding="utf-8"?><timedtext format="3"><body><p t="359999999999.9" d="0">x</p></body></timedtext>',
                "The time \"359999999999.9\" is not below 100000 hours.",
            ],
            "YouTube srv1 start of 359999999.9999 s" => [
                YouTubeTimedTextParser::class,
                '<?xml version="1.0" encoding="utf-8"?><transcript><text start="359999999.9999" dur="0">x</text></transcript>',
                "The time \"359999999.9999\" is not below 100000 hours.",
            ],
        ];
    }


    #[DataProvider("timesThatRoundToTheLimit")]
    public function testATimeThatRoundsTo100000HoursThrows(string $parserClass, string $content, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new $parserClass())->parse($content, new ReadOptions());
    }


    /**
     * 719999999999 / 2000 s is 359999999.9995 s in decimal, which rounds up to 100000 hours, though its float is just below it.
     * 7199999999989 / 20000 s is 359999999.99945 s, which rounds down to the last millisecond below the limit. Issue #1318.
     */
    public function testATimeIsCheckedOnItsDecimalValueRoundedToMilliseconds(): void
    {
        $chapters = (new FfMetadataChaptersParser())->parse(";FFMETADATA1\n[CHAPTER]\nTIMEBASE=1/20000\nSTART=7199999999989\nEND=7199999999989\n", new ReadOptions());
        $microDvd = (new MicroDvdParser())->parse("{1}{1}20000\n{7199999999989}{7199999999989}x\n", new ReadOptions());

        $this->assertSame(359999999.999, $chapters->getCues()[0]->getStart());
        $this->assertSame(359999999.999, $microDvd->getCues()[0]->getStart());

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("is not below 100000 hours.");
        (new MicroDvdParser())->parse("{1}{1}2000\n{719999999999}{719999999999}x\n", new ReadOptions());
    }


    /**
     * Drop-frame time code runs ahead of the clock. So the writer gives a time just below the limit a label such as 100000:05:49;21.
     * Issue #1315.
     */
    public function testSccAndMccReadATimeCodePast100000HoursThatIsBelowTheLimitInSeconds(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(359999990, 359999995, "HI"));

        foreach ([Format::Scc, Format::Mcc] as $format) {
            $content = $subtitle->toString($format);
            $this->assertStringContainsString("100000:05:49", $content);
            $this->assertSame([[359999990.016, 359999994.988, "HI"]], TestSubtitles::describe(Subtitle::fromString($content, $format)), $format->value);
        }
    }


    /**
     * @return array<string, array{Format, string, string}>
     */
    public static function timeCodesPastTheLimit(): array
    {
        return [
            "SCC 100001 hours"                  => [Format::Scc, "100001:00:0", "The time \"100001:00:00;21\" is not below 100000 hours."],
            "MCC 100100 hours"                  => [Format::Mcc, "100100:00:0", "The time \"100100:00:00:21\" is not below 100000 hours."],
            "MCC 999998 hours"                  => [Format::Mcc, "999998:00:0", "The time \"999998:00:00:21\" is not below 100000 hours."],
        ];
    }


    #[DataProvider("timeCodesPastTheLimit")]
    public function testSccAndMccRejectATimeCodePast100000Hours(Format $format, string $hours, string $message): void
    {
        $written = (new Subtitle())->addCue(new SubtitleCue(1, 2, "HI"))->toString($format);
        $content = preg_replace('/^00:00:0(?=\d[:;]\d\d)/m', $hours, $written);

        $lenient = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));
        $this->assertSame([], $lenient->getCues());
        $this->assertSame([self::SKIPPED, $message], [$lenient->getParseWarnings()[0]->action, $lenient->getParseWarnings()[0]->message]);

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);
        Subtitle::fromString($content, $format);
    }


    /**
     * @return array<string, array{Format, string, string}>
     */
    public static function captionsPastTheLimit(): array
    {
        // The MCC file holds every packet of a written cue at one time code, so its byte pairs take the frames after that time code.
        $mcc = (new Subtitle())->addCue(new SubtitleCue(1, 2, "HI"))->toString(Format::Mcc);

        return [
            "SCC" => [Format::Scc, SccParser::HEADER . "\n\n00:00:01;00\t9420 9420 d04f 4f4c 942f 942f\n00:00:03;00\t942c 942c\n100000:05:59;29\t9420 9420 c849 942f 942f\n",
                      "The times of a cue reach 360000000.093 s. Times must be below 100000 hours."],
            "MCC" => [Format::Mcc, preg_replace('/^\d\d:\d\d:\d\d[:;]\d\d(?=\t)/m', "100000:05:59;29", $mcc),
                      "The times of a cue reach 360000000.36 s. Times must be below 100000 hours."],
        ];
    }


    /**
     * The time code of each line is below the limit, but each byte pair takes one frame. Issue #1349.
     */
    #[DataProvider("captionsPastTheLimit")]
    public function testSccAndMccCheckTheTimesOfTheCuesTheyReturn(Format $format, string $content, string $message): void
    {
        $lenient = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));
        $this->assertSame($format === Format::Scc ? [[1.134, 3.003, "POOL"]] : [], TestSubtitles::describe($lenient));
        $this->assertSame([[self::SKIPPED, $message]], array_map(fn ($warning): array => [$warning->action, $warning->message], $lenient->getParseWarnings()));

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);
        Subtitle::fromString($content, $format);
    }
}
