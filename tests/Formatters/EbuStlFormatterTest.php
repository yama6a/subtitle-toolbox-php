<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Parsers\EbuStl;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class EbuStlFormatterTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/stl/real/";


    private static function ttiBlocks(string $stl): array
    {
        return str_split(substr($stl, 1024), 128);
    }


    public function testWritesAValidGsiBlockForASubtitleFromAnotherFormat(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,480\nHello\n\n2\n00:00:03,000 --> 00:00:04,000\nBye\n", Format::SubRip)
            ->setMetadata(Subtitle::METADATA_TITLE, "Harbour")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en-GB");

        $stl = $subtitle->toString(Format::EbuStl);
        $gsi = EbuStl::readGsi(substr($stl, 0, 1024));

        $this->assertSame(1024 + 2 * 128, strlen($stl));
        $this->assertSame(
            ["850", "STL25.01", "1", "00", "09", "Harbour", "00002", "00002", "001", "40", "23", "1", "00000000", "00000100", "1", "1"],
            array_values(array_intersect_key($gsi, array_flip([
                "CPN", "DFC", "DSC", "CCT", "LC", "OPT", "TNB", "TNS", "TNG", "MNC", "MNR", "TCS", "TCP", "TCF", "TND", "DSN",
            ])))
        );
        $this->assertMatchesRegularExpression('/^\d{6}$/', $gsi["CD"]);
        $this->assertSame($gsi["CD"], $gsi["RD"]);
        $this->assertSame(str_repeat(" ", 576), substr($stl, 448, 576));
        $this->assertSame(
            bin2hex("\x00\x01\x00\xFF\x00" . "\x00\x00\x01\x00" . "\x00\x00\x02\x0C" . "\x17\x02\x00" . "Hello"),
            bin2hex(rtrim(self::ttiBlocks($stl)[0], "\x8F"))
        );
        $this->assertSame(2, unpack("v", self::ttiBlocks($stl)[1], 1)[1]);
    }


    public function testMncIsTheLongestRowAndRowsStayAsWritten(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/../files/scc/fit/own_ferry.srt", Format::SubRip);
        $stl      = $subtitle->toString(Format::EbuStl);

        $this->assertSame("46", EbuStl::readGsi(substr($stl, 0, 1024))["MNC"]);
        $this->assertSame("The ferry to Split leaves from the old harbour", Subtitle::fromString($stl, Format::EbuStl)->getCues()[0]->getLines()[0]);
    }


    public function testMncCountsALetterWithADiacriticOnce(): void
    {
        $stl = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<i>" . str_repeat("é", 41) . "</i>"))->toString(Format::EbuStl);

        $this->assertSame("41", EbuStl::readGsi(substr($stl, 0, 1024))["MNC"]);
    }


    public function testWritesThirtyFramesPerSecond(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1.5, 2.967, "Hello"));
        $stl      = $subtitle->toString(Format::EbuStl, new WriteOptions(formats: [new EbuStlWriteOptions(frameRate: 30)]));

        $this->assertSame("STL30.01", substr($stl, 3, 8));
        $this->assertSame("\x00\x00\x01\x0F\x00\x00\x02\x1D", substr(self::ttiBlocks($stl)[0], 5, 8));
    }


    public function testRejectsOtherFrameRates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("25 or 30 fps");

        (new Subtitle())->toString(Format::EbuStl, new WriteOptions(formats: [new EbuStlWriteOptions(frameRate: 24)]));
    }


    public function testSplitsLongTextIntoExtensionBlocks(): void
    {
        $line     = str_repeat("Fresh bread from the stone oven. ", 4);
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, [$line, $line, "Ça va"]));

        $stl    = $subtitle->toString(Format::EbuStl);
        $blocks = self::ttiBlocks($stl);

        $this->assertSame([0x00, 0x01, 0xFF], array_map(fn (string $block): int => ord($block[3]), $blocks));
        $this->assertSame("00003", substr($stl, 238, 5));
        $this->assertSame("00001", substr($stl, 243, 5));
        $this->assertSame("\x8F", substr($blocks[2], -1));
        $this->assertSame($subtitle->getCues()[0]->getText(), Subtitle::fromString($stl)->getCues()[0]->getText());
    }


    public function testKeepsADiacriticInTheTextFieldOfItsLetter(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, str_repeat("a", 111) . "é"));
        $blocks   = self::ttiBlocks($subtitle->toString(Format::EbuStl));

        $this->assertSame(str_repeat("a", 111) . "\x8F", substr($blocks[0], 16));
        $this->assertSame("\xC2e", rtrim(substr($blocks[1], 16), "\x8F"));
    }


    public static function markup(): array
    {
        return [
            "italics and underline"  => ["<i>warm</i> and <u>fresh</u>", "\x80warm\x81 and \x82fresh\x83"],
            "teletext colours"       => ["<font color=\"#FF0000\">Fresh</font> rolls", "\x01Fresh\x07rolls"],
            "single quoted colour"   => ["<font color='#FF0000'>Fresh</font> rolls", "\x01Fresh\x07rolls"],
            "nested colours"         => ["<font color=\"#00ff00\">a <font color=\"#0000ff\">b</font> c</font>", "\x02a\x04b\x02c"],
            "upper case font tag"    => ["<FONT COLOR=\"#FF0000\">Fresh</FONT> rolls", "\x01Fresh\x07rolls"],
            "unquoted upper colour"  => ["<font color=#FF0000>Fresh</font> rolls", "\x01Fresh\x07rolls"],
            "other attributes"       => ["<font face=\"Arial\" color=\"#FF0000\">Fresh</font> rolls", "\x01Fresh\x07rolls"],
            "font without colour"    => ["<font color=\"#00ff00\">a <font face=\"Arial\">b</font> c</font>", "\x02a b c"],
            "other colours dropped"  => ["<font color=\"#123456\">dark</font> text", "dark text"],
            "open tags closed"       => ["<i><b>bold</b> italic", "\x80bold italic\x81"],
            "entities decoded"       => ["a &lt; b &amp; c", "a < b & c"],
            "text like a tag"        => ["a < b > c", "a < b > c"],
            "text like a tag at end" => ["<i>a</i>< b > c", "\x80a\x81< b > c"],
            "dollar and currency"    => ["$5 or 5\u{00A4}", "\xA45 or 5\x24"],
            "outside the table"      => ["\u{4E2D} ok", "? ok"],
            "underline over 2 lines" => ["<u>under\nline</u>", "\x82under\x83\x8A\x82line\x83"],
            "<c> color over 2 lines" => ["<c.yellow>Mind\nthe gap</c>", "\x03Mind\x8A\x03the gap"],
        ];
    }


    #[DataProvider("markup")]
    public function testConvertsCoreMarkupToTextFieldCodes(string $line, string $textField): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, $line));

        $this->assertSame(bin2hex($textField), bin2hex(rtrim(substr(self::ttiBlocks($subtitle->toString(Format::EbuStl))[0], 16), "\x8F")));
    }


    /**
     * @return array<string, array{string, int, list<string>}>
     */
    public static function stylesOverTwoLines(): array
    {
        return [
            "italics"                => ["srt/real/own_styled.srt", 6, ["<i>Italics over two lines</i>", "<i>that close on the second line</i>"]],
            "italics that stay open" => ["fixing/web-errors.srt", 2, ["<i>The station was closed,</i>", "<i>so we walked.</i>"]],
            "color that stays open"  => ["fixing/web-errors.srt", 10, ["<font color=\"#ffff00\">Next stop:</font>", "<font color=\"#ffff00\">the harbour</font>"]],
            "italics in WebVTT"      => ["hearing-impaired/own_sdh.vtt", 7, ["<i>[door opens]</i>", "<i>(footsteps)</i>"]],
        ];
    }


    #[DataProvider("stylesOverTwoLines")]
    public function testStyleThatSpansTwoLinesStylesBoth(string $file, int $index, array $lines): void
    {
        $stl = Subtitle::load(__DIR__ . "/../files/$file", Format::tryFromPath($file))->toString(Format::EbuStl);

        $this->assertSame($lines, Subtitle::fromString($stl, Format::EbuStl)->getCues()[$index]->getLines());
    }


    public function testStripAllOptionWritesNoStyleCodes(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "bakery_teletext_25fps.stl"), Format::EbuStl);
        $stl      = $subtitle->toString(Format::EbuStl, new WriteOptions(stripTags: true));

        $this->assertSame("Fresh rolls are warm today.", Subtitle::fromString($stl)->getCues()[1]->getText());
    }


    public static function alignments(): array
    {
        return [
            "default"       => [Alignment::BottomCenter, 23, 2],
            "bottom left"   => [Alignment::BottomLeft, 23, 1],
            "middle centre" => [Alignment::MiddleCenter, 12, 2],
            "top right"     => [Alignment::TopRight, 1, 3],
        ];
    }


    #[DataProvider("alignments")]
    public function testWritesPositionAndJustificationForTheAlignment(Alignment $alignment, int $vertical, int $justification): void
    {
        $subtitle = (new Subtitle())->addCue((new SubtitleCue(1, 2, "One row"))->setAlignment($alignment));
        $block    = self::ttiBlocks($subtitle->toString(Format::EbuStl))[0];

        $this->assertSame([$vertical, $justification], [ord($block[13]), ord($block[14])]);
    }


    public function testRetimedCueKeepsItsTextBytesAndChangedAlignmentGetsANewPosition(): void
    {
        $raw      = file_get_contents(self::DIR . "bakery_teletext_25fps.stl");
        $subtitle = Subtitle::fromString($raw, Format::EbuStl);
        $subtitle->shift(1);
        $subtitle->getCues()[1]->setAlignment(Alignment::TopCenter);

        $original = self::ttiBlocks($raw);
        $written  = self::ttiBlocks($subtitle->toString(Format::EbuStl));

        $this->assertSame(substr($original[0], 16), substr($written[0], 16));
        $this->assertSame("\x0A\x00\x02\x00\x0A\x00\x04\x0C", substr($written[0], 5, 8));
        $this->assertSame(substr($original[1], 16), substr($written[1], 16));
        $this->assertSame([1, 2], [ord($written[1][13]), ord($written[1][14])]);
        $this->assertSame("10000200", substr($subtitle->toString(Format::EbuStl), 264, 8));
    }


    public function testChangedMetadataReplacesTheGsiFields(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "harbour_open_30fps.stl"), Format::EbuStl)
            ->setMetadata(Subtitle::METADATA_TITLE, "Port de pêche")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "nl-BE");

        $stl = $subtitle->toString(Format::EbuStl);

        $this->assertSame(str_pad("Port de p\x88che", 32), substr($stl, 16, 32));
        $this->assertSame("2A", substr($stl, 14, 2));
        $this->assertSame("STL30.01", substr($stl, 3, 8));
    }


    public function testWritesCommentsAsCommentBlocks(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->addComment("Check the spelling", 0);
        $blocks   = self::ttiBlocks($subtitle->toString(Format::EbuStl));

        $this->assertCount(2, $blocks);
        $this->assertSame([1, 0], [ord($blocks[0][15]), ord($blocks[1][15])]);
        $this->assertSame("Check the spelling", rtrim(substr($blocks[0], 16), "\x8F"));
        $this->assertSame([1, 2], [unpack("v", $blocks[0], 1)[1], unpack("v", $blocks[1], 1)[1]]);
    }


    public function testWritesACommentWithACueIndexPastTheLastCueAtTheEnd(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->addComment("Check the ending", 5);

        $this->assertSame(["Check the ending"], array_column(Subtitle::fromString($subtitle->toString(Format::EbuStl), Format::EbuStl)->getComments(), "text"));
    }


    public function testWritesTheTextOfOtherCharacterCodeTables(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "weather_greek.stl"), Format::EbuStl);
        $subtitle->getCues()[0]->setLines(["Καλημέρα"]);

        $stl = $subtitle->toString(Format::EbuStl);

        $this->assertSame("\xCA\xE1\xEB\xE7\xEC\xDD\xF1\xE1", rtrim(substr(self::ttiBlocks($stl)[0], 16), "\x8F"));
        $this->assertSame("Καλημέρα", Subtitle::fromString($stl)->getCues()[0]->getText());
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function characterCodeTables(): array
    {
        return [
            "Cyrillic" => ["train_cyrillic.stl", "01"],
            "Arabic"   => ["market_arabic.stl", "02"],
            "Greek"    => ["weather_greek.stl", "03"],
            "Hebrew"   => ["library_hebrew.stl", "04"],
            "Latin"    => ["bakery_teletext_25fps.stl", "00"],
        ];
    }


    #[DataProvider("characterCodeTables")]
    public function testSubtitleFromAnotherFormatGetsTheTableThatHoldsItsText(string $file, string $table): void
    {
        $srt = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::EbuStl)->toString(Format::SubRip);

        $stl = Subtitle::fromString($srt, Format::SubRip)->toString(Format::EbuStl);

        $this->assertSame($table, EbuStl::readGsi(substr($stl, 0, 1024))["CCT"]);
        $this->assertSame($srt, Subtitle::fromString($stl, Format::EbuStl)->toString(Format::SubRip));
    }


    public function testTableWithTheFewestMissingCharactersWins(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "— Поезд «Стрела» уходит."));

        $stl = $subtitle->toString(Format::EbuStl);

        $this->assertSame("01", EbuStl::readGsi(substr($stl, 0, 1024))["CCT"]);
        $this->assertSame("? Поезд ?Стрела? уходит.", Subtitle::fromString($stl)->getCues()[0]->getText());
    }
}
