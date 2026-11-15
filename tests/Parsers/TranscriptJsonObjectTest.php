<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

/**
 * A JSON object with the keys 0 to n-1 reads as a PHP list. A field that must be a list rejects it.
 */
class TranscriptJsonObjectTest extends TestCase
{
    public static function listFieldsThatFail(): iterable
    {
        yield "whisper segments" => [Format::Whisper, '{"text":"hi","segments":{"0":{"id":0,"start":0,"end":1,"text":"hi"}}}', 'The JSON has no "segments" or "transcription" list.'];
        yield "whisper transcription" => [Format::Whisper, '{"transcription":{"0":{"offsets":{"from":0,"to":1000},"text":"hi"}}}', 'The JSON has no "segments" or "transcription" list.'];
        yield "aws items" => [Format::AwsTranscribe, '{"results":{"transcripts":[{"transcript":"hi"}],"items":{"0":{"start_time":"0.0","end_time":"1.0","alternatives":[{"confidence":"0.9","content":"hi"}],"type":"pronunciation"}}}}', 'The JSON has no "results.items" list.'];
        yield "deepgram channels" => [Format::Deepgram, '{"results":{"channels":{"0":{"alternatives":[{"transcript":"hi","words":[{"word":"hi","start":0,"end":1}]}]}}}}', 'The JSON has no "results.channels" list.'];
        yield "google results" => [Format::GoogleSpeech, '{"results":{"0":{"alternatives":[{"transcript":"hi","words":[{"word":"hi","startTime":"0s","endTime":"1s"}]}]}}}', 'The JSON has no "results" list.'];
        yield "google wrapped results" => [Format::GoogleSpeech, '{"response":{"results":{"0":{"alternatives":[{"transcript":"hi"}]}}}}', 'The JSON has no "results" list.'];
        yield "assemblyai words" => [Format::AssemblyAi, '{"text":"hi","words":{"0":{"text":"hi","start":0,"end":1000}}}', 'The JSON has no "words" or "utterances" list.'];
        yield "assemblyai utterances" => [Format::AssemblyAi, '{"utterances":{"0":{"text":"hi","start":0,"end":1000}}}', 'The JSON has no "words" or "utterances" list.'];
    }


    #[DataProvider("listFieldsThatFail")]
    public function testAJsonObjectWhereAListBelongsFails(Format $format, string $json, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        Subtitle::fromString($json, $format);
    }


    public static function listFieldsThatGiveNoItems(): iterable
    {
        yield "whisper segment words" => [Format::Whisper, '{"segments":[{"start":0,"end":1,"text":"hi","words":{"0":{"word":"hi","start":0,"end":1}}}]}', "hi"];
        yield "whisper top-level words" => [Format::Whisper, '{"task":"transcribe","segments":[{"start":0,"end":1,"text":"hi"}],"words":{"0":{"word":"hi","start":0,"end":1}}}', "hi"];
        yield "json3 segs" => [Format::YouTubeTimedText, '{"events":[{"tStartMs":0,"dDurationMs":1000,"segs":{"0":{"utf8":"hi"}}}]}', null];
        yield "json3 pens object" => [Format::YouTubeTimedText, '{"pens":{"0":{"bAttr":1}},"events":[{"tStartMs":0,"dDurationMs":1000,"pPenId":0,"segs":[{"utf8":"hi"}]}]}', "hi"];
        yield "json3 pens with a gap" => [Format::YouTubeTimedText, '{"pens":{"1":{"bAttr":1}},"events":[{"tStartMs":0,"dDurationMs":1000,"pPenId":1,"segs":[{"utf8":"hi"}]}]}', "hi"];
        yield "json3 pens list" => [Format::YouTubeTimedText, '{"pens":[{"bAttr":1}],"events":[{"tStartMs":0,"dDurationMs":1000,"pPenId":0,"segs":[{"utf8":"hi"}]}]}', "<b>hi</b>"];
        yield "deepgram words" => [Format::Deepgram, '{"results":{"channels":[{"alternatives":[{"transcript":"hi","words":{"0":{"word":"hi","start":0,"end":1}}}]}]}}', null];
        yield "assemblyai utterance words" => [Format::AssemblyAi, '{"utterances":[{"text":"hi","start":0,"end":1000,"words":{"0":{"text":"hi","start":0,"end":1000}}}]}', "hi"];
    }


    #[DataProvider("listFieldsThatGiveNoItems")]
    public function testAJsonObjectWhereAnOptionalListBelongsIsIgnored(Format $format, string $json, ?string $text): void
    {
        $subtitle = Subtitle::fromString($json, $format);

        $this->assertSame($text === null ? [] : [$text], array_map(fn ($cue): string => $cue->getText(), $subtitle->getCues()));
    }
}
