<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ReadOptions;

class Cavena890ParserTest extends TestCase
{
    /**
     * Returns a block of 1 s to 2 s with the bytes of the lower line.
     */
    public static function block(int $number, string $lower): string
    {
        return pack("n", $number) . "\0\0\x19\0\0\x32\x54" . str_repeat("\0", 7) . str_repeat("\x7F", 51) . str_repeat("\0", 6) .
               str_pad($lower, 51, "\x7F") . "\0\0\0\0";
    }


    public function testReadsDashesAndDropsColorsAndFillers(): void
    {
        $subtitle = (new Cavena890Parser())->parse(Cavena890::newHeader() . self::block(16, "\xBE Red \xF1fox\xF7 \x00\x1D"), new ReadOptions());

        $this->assertSame("- Red fox å", $subtitle->getCues()[0]->getText());
    }


    public static function chineseLines(): array
    {
        return [
            "surrogate pair"           => ["\x54\x09\xD8\x42\xDF\xB7", "吉𠮷"],
            "lone high surrogate"      => ["\x54\x09\xD8\x42\x91\xCE", "吉野"],
            "7Fh as the last low byte" => ["\x5C\x9B\x5C\x7F", "岛屿"],
            "51 bytes, odd length"     => [str_repeat("\x54\x09", 25) . "\x41", str_repeat("吉", 25) . "䄀"],
        ];
    }


    #[DataProvider("chineseLines")]
    public function testReadsChineseLines(string $lower, string $text): void
    {
        $header = substr_replace(Cavena890::newHeader(), "\x90", Cavena890::LANGUAGE_1, 1);
        $block  = substr_replace(self::block(16, ""), str_pad($lower, 51, "\0"), Cavena890::LINE_2, 51);

        $this->assertSame($text, (new Cavena890Parser())->parse($header . $block, new ReadOptions())->getCues()[0]->getText());
    }


    public static function headers(): array
    {
        return [
            "Danish code"    => [substr_replace(Cavena890::newHeader(), "\x07", Cavena890::LANGUAGE_1, 1), "scandinavian"],
            "Hebrew font"    => [substr_replace(Cavena890::newHeader(), "HEBNOA", Cavena890::FONT_2, 6), "hebrew"],
            "Arabic line 2"  => [substr_replace(Cavena890::newHeader(), "\x80", Cavena890::LANGUAGE_2, 1), "arabic"],
            "Russian font"   => [substr_replace(Cavena890::newHeader(), "KYRIL4", Cavena890::FONT_1, 6), "russian"],
            "Chinese code"   => [substr_replace(Cavena890::newHeader(), "\x90", Cavena890::LANGUAGE_1, 1), "chinese"],
            "English"        => [Cavena890::newHeader(), "latin"],
        ];
    }


    #[DataProvider("headers")]
    public function testHeaderNamesTheVariant(string $header, string $variant): void
    {
        $this->assertSame($variant, (new Cavena890Parser())->parse($header, new ReadOptions())->findFormatData("cavena890")["variant"]);
    }


    public function testShortHeaderThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A Cavena 890 file starts with a header of 388 bytes.");

        (new Cavena890Parser())->parse(str_repeat("\0", 100), new ReadOptions());
    }


    public function testIncompleteBlockThrowsInStrictModeAndIsCutInLenientMode(): void
    {
        $content = Cavena890::newHeader() . self::block(16, "One") . substr(self::block(32, "Two"), 0, 50);
        try {
            (new Cavena890Parser())->parse($content, new ReadOptions());
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertStringContainsString("The blocks of a Cavena 890 file have 128 bytes each.", $exception->getMessage());
        }

        $subtitle = (new Cavena890Parser())->parse($content, new ReadOptions(lenient: true));

        $this->assertSame(["One"], array_map(fn ($cue): string => $cue->getText(), $subtitle->getCues()));
        $this->assertCount(1, $subtitle->getParseWarnings());
    }
}
