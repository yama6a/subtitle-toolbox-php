<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use Throwable;

class StreamReaderEdgeCasesTest extends TestCase
{
    public static function subRipInputs(): array
    {
        return [
            "BOM and CRLF"           => ["\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,000\r\nFirst\r\n\r\n2\r\n00:00:03,000 --> 00:00:04,000\r\nSecond\r\n"],
            "CR only"                => ["1\r00:00:01,000 --> 00:00:02,000\rFirst\r\r2\r00:00:03,000 --> 00:00:04,000\rSecond\r"],
            "CR CR LF"               => ["1\r\r\n00:00:01,000 --> 00:00:02,000\r\r\nFirst\r\r\n\r\r\n2\r\r\n00:00:03,000 --> 00:00:04,000\r\r\nSecond"],
            "blank lines and spaces" => ["\n\n  1\n00:00:01,000  -->  00:00:02,000\n\tA \t tab\n \n\t\n\n2\n00:00:03,000 --> 00:00:04,000\nB & <i>c</i> <3\n\n\n"],
            "BOM only"               => ["\xEF\xBB\xBF"],
            "empty"                  => [""],
            "whitespace only"        => [" \n\t\r\n\n  "],
            "no text"                => ["1\n00:00:01,000 --> 00:00:02,000\n\n2\n00:00:03,000 --> 00:00:04,000\nB\n"],
        ];
    }


    public static function webVttInputs(): array
    {
        return [
            "BOM and CRLF"             => ["\xEF\xBB\xBFWEBVTT\r\n\r\n00:01.000 --> 00:02.000\r\nFirst\r\n"],
            "CR only"                  => ["WEBVTT\r\r00:01.000 --> 00:02.000\rFirst\r\r00:03.000 --> 00:04.000\rSecond"],
            "CR CR LF"                 => ["WEBVTT\r\r\n\r\r\n00:01.000 --> 00:02.000\r\r\nFirst\r\r\n"],
            "whitespace around"        => ["\n \n  WEBVTT  title \nX-Meta: 1 \n\nSTYLE\n::cue { color: red; }  \n\n\n00:01.000 --> 00:02.000\nA\n \n\t\n"],
            "header only"              => ["WEBVTT\nKind: captions  \n  \n"],
            "style last"               => ["WEBVTT\n\nSTYLE\n::cue { color: red; }   \n\n"],
            "style after cue"          => ["WEBVTT\n\n00:01.000 --> 00:02.000\nA\n\nSTYLE\n::cue { color: red; }\n"],
            "cue without gap"          => ["WEBVTT\n\n00:01.000 --> 00:02.000\nA\n00:03.000 --> 00:04.000\nB\n"],
            "region and note"          => ["WEBVTT\n\nREGION\nid:a width:40%\n\nNOTE x\n\n00:01.000 --> 00:02.000 region:a\nA\n"],
            "missing gap after header" => ["WEBVTT\n00:01.000 --> 00:02.000\nA\n"],
            "empty"                    => [""],
            "unknown block"            => ["WEBVTT\n\nhello\n"],
        ];
    }


    #[DataProvider("subRipInputs")]
    public function testSubRipReaderMatchesSubRipParser(string $content): void
    {
        $this->assertSameOutcome(
            fn (): array => (new SubRipParser())->parse($content, new ReadOptions())->getCues(),
            fn (): array => iterator_to_array((new SubRipStreamReader())->read($this->stream($content)), false)
        );
    }


    #[DataProvider("webVttInputs")]
    public function testWebVttReaderMatchesWebVttParser(string $content): void
    {
        $reader = new WebVttStreamReader();
        $this->assertSameOutcome(
            function () use ($content): array {
                $subtitle = (new WebVttParser())->parse($content, new ReadOptions());

                return [$subtitle->getCues(), $subtitle->findFormatData(WebVttParser::FORMAT_DATA_KEY)];
            },
            fn (): array => [iterator_to_array($reader->read($this->stream($content)), false), $reader->getHeader()]
        );
    }


    public function testReaderKeepsTheFileOrder(): void
    {
        $content = "1\n00:00:05,000 --> 00:00:06,000\nLate\n\n2\n00:00:01,000 --> 00:00:02,000\nEarly\n";

        $cues = iterator_to_array((new SubRipStreamReader())->read($this->stream($content)), false);

        $this->assertSame(["Late", "Early"], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $cues));
    }


    public function testWebVttHeaderIsCompleteAtTheFirstCue(): void
    {
        $content = "WEBVTT Title\n\nREGION\nid:a\n\nSTYLE\n::cue { color: red; }\n\n00:01.000 --> 00:02.000\nA\n";
        $reader  = new WebVttStreamReader();

        foreach ($reader->read($this->stream($content)) as $cue) {
            $this->assertSame(
                ["header" => "Title", "regions" => [["id" => "a"]], "styles" => ["::cue { color: red; }"]],
                $reader->getHeader()
            );
        }
    }


    public function testReaderYieldsCuesBeforeABrokenBlock(): void
    {
        $content = "1\n00:00:01,000 --> 00:00:02,000\nGood\n\n2\nbroken\n";
        $texts   = [];

        try {
            foreach ((new SubRipStreamReader())->read($this->stream($content)) as $cue) {
                $texts[] = $cue->getText();
            }
            $this->fail("The reader does not throw.");
        } catch (ParsingException) {
            $this->assertSame(["Good"], $texts);
        }
    }


    public function testReaderReadsUtf16ThroughAnIconvFilter(): void
    {
        $path   = __DIR__ . "/../files/encoding/notepad-utf-16le.vtt";
        $stream = fopen($path, "rb");
        stream_filter_append($stream, "convert.iconv.UTF-16/UTF-8");

        $this->assertEquals(
            Subtitle::fromString(file_get_contents($path))->getCues(),
            iterator_to_array((new WebVttStreamReader())->read($stream), false)
        );
    }


    public static function filesWithInvalidUtf8(): array
    {
        return [
            "SubRip with one bad byte"      => [SubRipStreamReader::class, SubRipParser::class, "invalid-utf-8/one-bad-byte.srt"],
            "WebVTT with one bad byte"      => [WebVttStreamReader::class, WebVttParser::class, "invalid-utf-8/one-bad-byte.vtt"],
            "SubRip in Windows-1252"        => [SubRipStreamReader::class, SubRipParser::class, "french-windows-1252.srt"],
            "SubRip in Shift JIS with CRLF" => [SubRipStreamReader::class, SubRipParser::class, "japanese-shift_jis.srt"],
        ];
    }


    /**
     * @param class-string<CueStreamReader>           $readerClass
     * @param class-string<SubRipParser|WebVttParser> $parserClass
     */
    #[DataProvider("filesWithInvalidUtf8")]
    public function testLenientReaderWarnsAboutInvalidUtf8LikeTheParser(string $readerClass, string $parserClass, string $file): void
    {
        $path     = __DIR__ . "/../files/encoding/$file";
        $subtitle = (new $parserClass())->parse(file_get_contents($path), new ReadOptions(lenient: true));
        $reader   = new $readerClass(new ReadOptions(lenient: true));
        $cues     = iterator_to_array($reader->read($path), false);

        $this->assertStringStartsWith("The content is not valid UTF-8.", $subtitle->getParseWarnings()[0]->message);
        $this->assertEquals($subtitle->getCues(), $cues);
        $this->assertEquals($subtitle->getParseWarnings(), $reader->getWarnings());
    }


    public function testReaderLeavesTheCallersStreamToTheCallerAfterAnError(): void
    {
        $stream = fopen(__DIR__ . "/../files/srt/real/own_styled.srt", "rb");
        try {
            foreach ((new WebVttStreamReader(new ReadOptions(lenient: true)))->read($stream) as $cue) {
                $this->fail("The reader yields a cue.");
            }
        } catch (ParsingException $exception) {
            $this->assertSame("The file does not start with WEBVTT.", $exception->getRawMessage());
        }
        fclose($stream);

        // The exception keeps the stopped generators alive. Dropping it runs their finally blocks.
        unset($exception);

        $this->assertFalse(is_resource($stream));
    }


    public function testReaderRejectsAMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new SubRipStreamReader())->read(__DIR__ . "/missing.srt"));
    }


    public function testReaderRejectsAValueThatIsNoStream(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new WebVttStreamReader())->read(42));
    }


    private function assertSameOutcome(callable $batch, callable $stream): void
    {
        try {
            $expected = $batch();
        } catch (Throwable $exception) {
            $this->expectExceptionObject($exception);
            $stream();

            return;
        }

        $this->assertEquals($expected, $stream());
    }


    private function stream(string $content)
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
