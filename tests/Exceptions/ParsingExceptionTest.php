<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\SubtitleParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\ReadOptions;

class ParsingExceptionTest extends TestCase
{
    public function testMessageCarriesClassNameAndErrorCodeOnce(): void
    {
        $exception = new ParsingException("broken");

        $this->assertSame("ParsingException (Error #100): broken", $exception->getMessage());
        $this->assertSame(100, $exception->getCode());
    }


    public function testOtherExceptionsCarryTheirShortClassNameAndErrorCode(): void
    {
        $unsupported = new UnsupportedFormatException("broken");
        $unknown     = new UnknownFormatException("broken");

        $this->assertSame("UnsupportedFormatException (Error #102): broken", $unsupported->getMessage());
        $this->assertSame(102, $unsupported->getCode());
        $this->assertSame("UnknownFormatException (Error #106): broken", $unknown->getMessage());
        $this->assertSame(106, $unknown->getCode());
    }


    public function testLineNumberIsAppendedToTheMessage(): void
    {
        $exception = new ParsingException("broken", 12);

        $this->assertSame("ParsingException (Error #100): broken (line 12)", $exception->getMessage());
        $this->assertSame(12, $exception->getLineNumber());
    }


    public function testLineNumberIsNullWhenTheParserDoesNotKnowIt(): void
    {
        $this->assertNull((new ParsingException("broken"))->getLineNumber());
    }


    public static function parsersThatKnowTheLine(): array
    {
        return [
            "MicroDVD line without frames" => [new MicroDvdParser(), "{0}{25}first\n\nsecond\n", 3, new ReadOptions(formats: [new MicroDvdReadOptions(25)])],
            "ASS event with few fields"    => [new AssParser(), "[Events]\nFormat: Layer, Start, End, Text\n\nDialogue: 0\n", 4],
            "ASS event format without End" => [new AssParser(), "[Events]\nFormat: Start, Text\nDialogue: 0:00:01.00,text\n", 3],
            "MPSub unknown line"           => [new MpSubParser(), "FORMAT=TIME\n\n0 1\nfirst\n\nsecond\n", 6],
            "MPSub negative duration"      => [new MpSubParser(), "FORMAT=TIME\n0 -1\ntext\n", 2],
            "MPSub cue without text"       => [new MpSubParser(), "FORMAT=TIME\n0 1\n\n", 2],
            "MPSub unknown FORMAT"         => [new MpSubParser(), "TITLE=x\nFORMAT=FAST\n", 2],
            "MPSub frame rate 0"           => [new MpSubParser(), "TITLE=x\nFORMAT=0\n", 2],
            "SubViewer 1 header"           => [new SubViewerParser(), "[TITLE]\n\ntext\n" . SubViewerParser::START_SCRIPT . "\n", 3],
            "SubViewer 2 header"           => [new SubViewerParser(), "[INFORMATION]\n[TITLE]x\ntext\n", 3],
        ];
    }


    #[DataProvider("parsersThatKnowTheLine")]
    public function testParsersPassTheLineNumber(SubtitleParser $parser, string $content, int $lineNumber, ReadOptions $options = new ReadOptions()): void
    {
        try {
            $parser->parse($content, $options);
            $this->fail("The parser did not throw.");
        } catch (ParsingException $exception) {
            $this->assertSame($lineNumber, $exception->getLineNumber());
            $this->assertStringEndsWith(" (line $lineNumber)", $exception->getMessage());
        }
    }
}
