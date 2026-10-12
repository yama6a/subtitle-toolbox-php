<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;

class TextEncodingTest extends TestCase
{
    private const DIR = __DIR__ . "/files/encoding/";

    // Each case keeps the letters that it can encode. Every case can encode at least one of them.
    private const LETTERS = [
        "é", "ß", "ø", "€", "œ", "ą", "ő", "č", "ğ", "ā", "ŵ", "ț", "ħ", "Ж", "ї", "Ω", "א", "ع", "ก", "ệ",
        "ソ", "表", "中", "國", "똠", "한", "╬", "½",
    ];


    public static function cases(): array
    {
        $cases = [];
        foreach (TextEncoding::cases() as $case) {
            $cases[$case->name] = [$case];
        }

        return $cases;
    }


    private static function sample(TextEncoding $encoding): string
    {
        $letters = array_filter(
            self::LETTERS,
            fn (string $letter) => @iconv($encoding->value, "UTF-8", (string)@iconv("UTF-8", $encoding->value, $letter)) === $letter
        );

        return "1\n00:00:01,000 --> 00:00:02,500\nText " . implode(" ", $letters) . "\n";
    }


    #[DataProvider("cases")]
    public function testIconvKnowsTheName(TextEncoding $encoding): void
    {
        $this->assertNotFalse(@iconv($encoding->value, "UTF-8", ""));
        $this->assertNotFalse(@iconv("UTF-8", $encoding->value, "A"));
    }


    #[DataProvider("cases")]
    public function testCaseConvertsLikeItsName(TextEncoding $encoding): void
    {
        $text  = self::sample($encoding);
        $bytes = iconv("UTF-8", $encoding->value, $text);

        $this->assertNotSame("1\n00:00:01,000 --> 00:00:02,500\nText \n", $text);
        $this->assertSame($text, StringHelpers::convertToUtf8($bytes, $encoding));
        $this->assertSame(StringHelpers::convertToUtf8($bytes, $encoding->value), StringHelpers::convertToUtf8($bytes, $encoding));
        $this->assertEquals(
            Subtitle::fromString($bytes, Format::SubRip, new ReadOptions(encoding: $encoding->value)),
            Subtitle::fromString($bytes, Format::SubRip, new ReadOptions(encoding: $encoding))
        );
    }


    public static function fixtures(): array
    {
        return [
            "French Windows-1252"  => ["french-windows-1252.srt", TextEncoding::Windows1252, Format::SubRip],
            "Russian Windows-1251" => ["russian-windows-1251.srt", TextEncoding::Windows1251, Format::SubRip],
            "Japanese Shift_JIS"   => ["japanese-shift_jis.srt", TextEncoding::ShiftJis, Format::SubRip],
            "Korean CP949 SAMI"    => ["korean-cp949.smi", TextEncoding::Cp949, Format::Sami],
            "Notepad UTF-16LE"     => ["notepad-utf-16le.vtt", TextEncoding::Utf16Le, Format::WebVtt],
        ];
    }


    #[DataProvider("fixtures")]
    public function testFixtureLoadsThroughTheCase(string $file, TextEncoding $encoding, Format $format): void
    {
        $path   = self::DIR . $file;
        $byCase = Subtitle::load($path, $format, new ReadOptions(encoding: $encoding));
        $byName = Subtitle::load($path, $format, new ReadOptions(encoding: $encoding->value));

        $this->assertCount(3, $byCase->getCues());
        $this->assertEquals($byName, $byCase);
        $this->assertEquals($byName, Subtitle::fromString((string)file_get_contents($path), options: new ReadOptions(encoding: $encoding)));
    }


    public function testReadOptionsStoresTheName(): void
    {
        $this->assertSame("Windows-1252", (new ReadOptions(encoding: TextEncoding::Windows1252))->encoding);
        $this->assertSame("CP1125", (new ReadOptions(encoding: "CP1125"))->encoding);
        $this->assertNull((new ReadOptions())->encoding);
    }


    public function testUnknownNameStillThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The encoding \"ISO-8859-12\" is unknown.");
        new ReadOptions(encoding: "ISO-8859-12");
    }


    public function testInvalidBytesThrowForTheCaseAsForTheName(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Cannot convert the content from UTF-16LE to UTF-8.");
        StringHelpers::convertToUtf8("\x00\xD8", TextEncoding::Utf16Le);
    }
}
