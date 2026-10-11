<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\Options\EbuStlReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

class EbuStlParserTest extends TestCase
{
    public static function gsi(string $characterCodeTable = "00", string $diskFormat = "STL25.01", string $displayStandard = "1"): string
    {
        return str_pad("850{$diskFormat}{$displayStandard}{$characterCodeTable}09", 1024);
    }


    public static function tti(int $number, string $text, int $extension = 0xFF, int $comment = 0, int $vertical = 22, int $justification = 2): string
    {
        return chr(0) . pack("v", $number) . chr($extension) . "\0" . "\0\0\x01\x05" . "\0\0\x02\x0A" .
               chr($vertical) . chr($justification) . chr($comment) . str_pad($text, 112, "\x8F");
    }


    public function testReadsTheExampleTextFieldOfTheIssue(): void
    {
        $subtitle = (new EbuStlParser())->parse(self::gsi() . self::tti(1, "\x80Caf\xC2e\x81\x8A"), new ReadOptions());
        $cue      = $subtitle->getCues()[0];

        $this->assertSame(["<i>Café</i>"], $cue->getLines());
        $this->assertSame([1.2, 2.4], [$cue->getStart(), $cue->getEnd()]);
        $this->assertSame("en", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertNull($subtitle->findMetadata(Subtitle::METADATA_TITLE));
    }


    public function testJoinsExtensionBlocksAndSkipsUserData(): void
    {
        $subtitle = (new EbuStlParser())->parse(self::gsi() .
            self::tti(1, str_repeat("a", 111) . "\xC2", 0x00) .
            self::tti(1, "e and more", 0xFE) .
            self::tti(1, "e end", 0x01) .
            self::tti(1, "", 0xFF) .
            self::tti(2, "Next"), new ReadOptions());

        $this->assertCount(2, $subtitle->getCues());
        $this->assertSame(str_repeat("a", 111) . "é end", $subtitle->getCues()[0]->getText());
        $this->assertCount(4, $subtitle->getCues()[0]->findFormatData("stl")["blocks"]);
    }


    public function testSkipsCommentBlocksAndKeepsThemAsComments(): void
    {
        $subtitle = (new EbuStlParser())->parse(self::gsi() .
            self::tti(1, "First") . self::tti(2, "\x80Note\x81\x8Asecond row", comment: 1) . self::tti(3, "Second"), new ReadOptions());

        $this->assertSame(["First", "Second"], array_map(fn ($cue) => $cue->getText(), $subtitle->getCues()));
        $this->assertEquals([new Comment("Note\nsecond row", 1)], $subtitle->getComments());
    }


    public function testCommentBlockStaysBeforeItsSubtitleWhenTheSubtitlesAreOutOfTimeOrder(): void
    {
        $content  = file_get_contents(__DIR__ . "/../files/stl/real/shop_comments_out_of_order.stl");
        $subtitle = (new EbuStlParser())->parse($content, new ReadOptions());

        $this->assertSame(["The door opens.", "The shop is open.", "The shop closes."], array_map(fn ($cue) => $cue->getText(), $subtitle->getCues()));
        $this->assertEquals([
            new Comment("The door opens before the shop.", 0),
            new Comment("End of the shop scene.", 3),
        ], $subtitle->getComments());
    }


    public static function textFields(): array
    {
        return [
            "colour resets on a new row"   => ["\x01red\x8Awhite", ["<font color=\"#ff0000\">red</font>", "white"]],
            "italics last over a new row"  => ["\x80one\x8Atwo\x81 three", ["<i>one</i>", "<i>two</i> three"]],
            "nested styles close in order" => ["\x80\x82both\x81 under\x83", ["<i><u>both</u></i> <u>under</u>"]],
            "white text has no tag"        => ["\x07plain", ["plain"]],
            "colour between words"         => ["a\x03b", ["a <font color=\"#ffff00\">b</font>"]],
            "boxing codes are dropped"     => ["\x84boxed\x85", ["boxed"]],
            "double new line"              => ["one\x8A\x8Atwo", ["one", "two"]],
            "markup characters escaped"    => ["a < b & c", ["a &lt; b &amp; c"]],
            "undefined bytes dropped"      => ["a\xA6\xC9b\x7F", ["ab"]],
        ];
    }


    #[DataProvider("textFields")]
    public function testConvertsTextFieldCodesToCoreMarkup(string $textField, array $lines): void
    {
        $this->assertSame($lines, (new EbuStlParser())->parse(self::gsi() . self::tti(1, $textField), new ReadOptions())->getCues()[0]->getLines());
    }


    public static function positions(): array
    {
        return [
            "teletext top left"      => ["1", 1, 1, 7],
            "teletext middle right"  => ["1", 12, 3, 6],
            "teletext bottom centre" => ["1", 22, 2, 2],
            "unchanged presentation" => ["1", 22, 0, 2],
            "open top"               => ["0", 0, 2, 8],
            "open bottom of 23 rows" => ["0", 20, 2, 2],
        ];
    }


    #[DataProvider("positions")]
    public function testMapsPositionAndJustificationToAlignment(string $displayStandard, int $vertical, int $justification, int $alignment): void
    {
        $subtitle = (new EbuStlParser())->parse(self::gsi(displayStandard: $displayStandard) .
            self::tti(1, "Text", vertical: $vertical, justification: $justification), new ReadOptions());

        $this->assertSame($alignment, $subtitle->getCues()[0]->getAlignment());
        $this->assertSame($vertical, $subtitle->getCues()[0]->findFormatData("stl")["verticalPosition"]);
        $this->assertSame($justification, $subtitle->getCues()[0]->findFormatData("stl")["justificationCode"]);
    }


    public function testReadsThirtyFramesPerSecond(): void
    {
        $cue = (new EbuStlParser())->parse(self::gsi(diskFormat: "STL30.01") . self::tti(1, "Text"), new ReadOptions())->getCues()[0];

        $this->assertSame([1.167, 2.333], [$cue->getStart(), $cue->getEnd()]);
    }


    public function testSubtractedStartOfProgrammeDoesNotGoBelowZero(): void
    {
        $gsi = substr_replace(self::gsi(), "00000200", 256, 8);
        $cue = (new EbuStlParser())->parse($gsi . self::tti(1, "Text"), new ReadOptions(formats: [new EbuStlReadOptions(subtractStartOfProgramme: true)]))->getCues()[0];

        $this->assertSame([0.0, 0.4], [$cue->getStart(), $cue->getEnd()]);
    }


    public static function invalidFiles(): array
    {
        return [
            "too short"            => [str_repeat(" ", 1023), "starts with a GSI block of 1024 bytes"],
            "partial TTI block"    => [self::gsi() . str_repeat("\x8F", 100), "must have 128 bytes each"],
            "unknown disk format"  => [str_pad("850STL24.01", 1024), "is not STL25.01 or STL30.01"],
            "unknown code table"   => [self::gsi("05"), "The character code table \"05\""],
        ];
    }


    #[DataProvider("invalidFiles")]
    public function testRejectsInvalidFiles(string $content, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new EbuStlParser())->parse($content, new ReadOptions());
    }


    public function testAFileWithoutTtiBlocksHasNoCues(): void
    {
        $subtitle = (new EbuStlParser())->parse(self::gsi(), new ReadOptions());

        $this->assertSame([], $subtitle->getCues());
        $this->assertSame(0, $subtitle->findFormatData("stl")["counts"]["TNB"]);
    }
}
