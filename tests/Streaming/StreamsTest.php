<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class StreamsTest extends TestCase
{
    public static function lineEnds(): array
    {
        return [
            "LF"       => ["\n"],
            "CR LF"    => ["\r\n"],
            "CR"       => ["\r"],
        ];
    }


    #[DataProvider("lineEnds")]
    public function testSplitsLinesAtEveryChunkBoundary(string $eol): void
    {
        for ($length = 8185; $length <= 8195; $length++) {
            $first   = str_repeat("a", $length);
            $content = $first . $eol . "b" . $eol . $eol . "c";

            $this->assertSame([$first, "b", "", "c"], iterator_to_array(Streams::lines($this->stream($content)), false), "length $length");
        }
    }


    public function testAFinalCrEndsTheLastLine(): void
    {
        $this->assertSame(["a", "b"], iterator_to_array(Streams::lines($this->stream("a\rb\r")), false));
    }


    public function testReadsAFileWithCrLineEndsInConstantMemory(): void
    {
        $stream = tmpfile();
        for ($index = 0; $index < 100000; $index++) {
            fwrite($stream, "Line number $index of the file, with some padding to fill it\r");
        }
        rewind($stream);

        $before = memory_get_usage();
        memory_reset_peak_usage();
        $count = 0;
        foreach (Streams::lines($stream) as $line) {
            $count++;
        }

        $this->assertSame(100000, $count);
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
    }


    public function testReportsTheByteOffsetOfInvalidUtf8(): void
    {
        $offsets = [];
        iterator_to_array(Streams::lines($this->stream("ab\rc\xFFd\n\xFE"), function (int $offset) use (&$offsets): void {
            $offsets[] = $offset;
        }));

        $this->assertSame([4], $offsets);
    }


    public function testTheWebVttStreamReaderRejectsAFirstLineThatOnlyStartsWithWebvtt(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");

        iterator_to_array((new WebVttStreamReader())->read($this->stream("WEBVTTX\n\n00:01.000 --> 00:02.000\nA\n")));
    }


    private function stream(string $content)
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
