<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\TextEncoding;

class PacParserTest extends TestCase
{
    private const FOOTER = "\xFF" . "\0\0\0\0\0\0\0\0\0\0\0\x11\0dummy end of file";


    /**
     * Returns a PAC file with one record per text, 1 s to 2 s, number from 0.
     */
    public static function pac(string ...$texts): string
    {
        $records = "";
        foreach (array_values($texts) as $number => $text) {
            $bytes    = "\xFE\x02\x03$text";
            $records .= "\0" . pack("v", $number) . "\x60" . pack("v4", 0, 100, 0, 200) . pack("v", strlen($bytes) + 1) . "\x0B" . $bytes;
        }

        return "\x01" . str_repeat("\0", 19) . $records . self::FOOTER;
    }


    public function testReadsTheCodePageOfTheLanguageOfTheStoryRecord(): void
    {
        $subtitle = (new PacParser())->parse(self::pac("STORY:News\xFE\x02\x03LANG:RUS", "Lf&"), new ReadOptions());

        $this->assertSame("cyrillic", $subtitle->findFormatData("pac")["codePage"]);
        $this->assertSame("Да.", $subtitle->getCues()[0]->getText());
    }


    public function testEncodingWinsOverTheStoryRecord(): void
    {
        $subtitle = (new PacParser())->parse(self::pac("STORY:News\xFE\x02\x03LANG:RUS", "Hello"), new ReadOptions(encoding: TextEncoding::Windows1252));

        $this->assertSame("latin", $subtitle->findFormatData("pac")["codePage"]);
        $this->assertSame("Hello", $subtitle->getCues()[0]->getText());
    }


    public function testEncodingWithoutPacCodePageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("PAC files have no code page for the encoding \"KOI8-R\".");

        (new PacParser())->parse(self::pac("Hello"), new ReadOptions(encoding: TextEncoding::Koi8R));
    }


    public function testTextThatNoCodePageFitsThrowsInStrictModeAndWarnsInLenientMode(): void
    {
        $content = self::pac("\xC3\xC4");
        try {
            (new PacParser())->parse($content, new ReadOptions());
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertStringContainsString("The code page of the PAC file is not clear. No code page gives text in its script.", $exception->getMessage());
        }

        $subtitle = (new PacParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertSame("latin", $subtitle->findFormatData("pac")["codePage"]);
        $this->assertSame(ParseWarningAction::Repaired, $subtitle->getParseWarnings()[0]->action);
        $this->assertStringContainsString("The parser read it as latin.", $subtitle->getParseWarnings()[0]->message);
    }


    public function testTextThatSeveralCodePagesFitIsUnclear(): void
    {
        $subtitle = (new PacParser())->parse(self::pac("Lf"), new ReadOptions(lenient: true));

        $this->assertStringContainsString("These code pages fit: greek, cyrillic.", $subtitle->getParseWarnings()[0]->message);
    }


    public static function brokenFiles(): array
    {
        $file = self::pac("Hello");

        return [
            "header"       => ["\x01\0\0", "A PAC file starts with the byte 01h and 19 zero bytes.", 0],
            "length"       => [substr_replace($file, "\xFF\x00", 32, 2), "The PAC record at byte 20 is not complete.", 0],
            "mark"         => [substr_replace($file, "\x50", 23, 1), "The PAC record at byte 20 is not complete.", 0],
            "end block"    => [substr_replace($file, "\x07", -strlen(self::FOOTER), 1), "is neither a PAC record nor the end block.", 1],
            "minute 60"    => [substr_replace($file, pack("v", 60), 24, 2), "Subtitle number 0 has a time code that is not valid.", 0],
            "frame 30"     => [substr_replace($file, pack("v", 130), 26, 2), "Subtitle number 0 has a time code that is not valid.", 0],
        ];
    }


    #[DataProvider("brokenFiles")]
    public function testBrokenFileThrowsInStrictModeAndSkipsInLenientMode(string $content, string $message, int $cues): void
    {
        try {
            (new PacParser())->parse($content, new ReadOptions());
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        if ($message === "A PAC file starts with the byte 01h and 19 zero bytes.") {
            return;
        }
        $subtitle = (new PacParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertCount($cues, $subtitle->getCues());
        $this->assertStringContainsString($message, $subtitle->getParseWarnings()[0]->message);
    }


    public function testSkipsColorsAndCodesAndReadsFfAsSpace(): void
    {
        $subtitle = (new PacParser())->parse(self::pac("\x03Red\xFFtext\x1FC1 end\x1FCcomment"), new ReadOptions());

        $this->assertSame("Red text end", $subtitle->getCues()[0]->getText());
    }


    public function testItalicsCodeTakesThePlaceOfASpace(): void
    {
        $subtitle = (new PacParser())->parse(self::pac("Go<now>, he said<quietly"), new ReadOptions());

        $this->assertSame("Go <i>now</i>, he said <i>quietly</i>", $subtitle->getCues()[0]->getText());
    }


    public function testDetectsPacButNotTextThatStartsWithTheSameByte(): void
    {
        $this->assertSame(Format::Pac, Format::detect(self::pac("Hello")));
        $this->assertNull(Format::detect("\x01" . str_repeat("\0", 19) . "\x01\x02\x03"));
    }
}
