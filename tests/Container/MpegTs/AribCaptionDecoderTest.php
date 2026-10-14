<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Container\MpegTs\AribFixtureWriter as W;

require_once __DIR__ . "/../../files/mpegts/generator/AribFixtureWriter.php";

class AribCaptionDecoderTest extends TestCase
{
    public function testDecodesKanjiKanaColorsAndMiddleSizeText(): void
    {
        $body = W::CS . W::setup() . W::position(6, 3) . W::kanji("今日は") . W::hiragana("いい") . W::kanji("天気です。") . W::APR
                . W::YELLOW . W::kanji("明日も") . W::MSZ . W::LS1 . "OK!" . W::LS0 . W::kanji("、") . W::NSZ . W::LS1 . "OK" . W::LS0;

        $this->assertSame(
            [[1.0, ["今日はいい天気です。", "<font color=\"#ffff00\">明日もOK!､ＯＫ</font>"], Alignment::BottomCenter]],
            self::states(self::decode([[1.0, W::statement(W::text($body))]])),
        );
    }


    public function testWritesSmallTextAboveARowAsRubyOfTheCharactersBelowIt(): void
    {
        // The row 6 starts at x 290 and has its top at y 390. 天気 takes x 490 to 570, its furigana x 500 to 560.
        $body = W::CS . W::setup() . W::position(6, 3) . W::kanji("今日はいい天気です。") . W::SSZ . W::dots(500, 390) . W::hiragana("てんき")
                . W::dots(300, 390) . W::hiragana("きょう");

        $this->assertSame(
            [[0.0, ["<ruby>今日<rt>きょう</rt></ruby>はいい<ruby>天気<rt>てんき</rt></ruby>です。"], Alignment::BottomCenter]],
            self::states(self::decode([[0.0, W::statement(W::text($body))]])),
        );
    }


    public function testKeepsFuriganaWithoutARowBelowAsALine(): void
    {
        $body = W::CS . W::setup() . W::SSZ . W::dots(500, 420) . W::hiragana("てんき");

        $this->assertSame([[0.0, ["てんき"], Alignment::BottomCenter]], self::states(self::decode([[0.0, W::statement(W::text($body))]])));
    }


    public function testTimeWaitsAndClearScreenEndTheText(): void
    {
        $decoder = self::decode([
            [1.0, W::statement(W::text(W::CS . W::setup() . W::position(7, 0) . W::kanji("はい") . W::wait(25) . W::CS))],
            [5.0, W::statement(W::text(W::CS . W::setup() . W::position(0, 0) . W::kanji("上")))],
            [6.0, W::statement(W::text(W::CS))],
        ]);

        $this->assertSame(
            [[1.0, ["はい"], Alignment::BottomLeft], [3.5, [], Alignment::BottomCenter], [5.0, ["上"], Alignment::TopLeft], [6.0, [], Alignment::BottomCenter]],
            self::states($decoder),
        );
    }


    public function testMapsKnownDrcsGlyphsAndWarnsForOthers(): void
    {
        $glyphs = W::drcs([[0x4121, 4, 18, 36, W::GLYPH_FF5F], [0x4122, 4, 18, 36, W::GLYPH_FF60], [0x4123, 4, 36, 36, W::GLYPH_51DC],
                           [0x4124, 2, 16, 18, W::GLYPH_FRAME]]);
        // ESC 0x29 0x20 0x41 puts the set DRCS-1 into G1. LS1 invokes it in GL.
        $text    = W::text(W::CS . W::setup() . W::position(6, 3) . "\x1B\x29\x20\x41" . W::LS1 . "\x21\x23\x22\x24\x25\x24" . W::LS0);
        $decoder = self::decode([[2.0, W::statement($glyphs . $text)]]);

        $this->assertSame([[2.0, ["｟凜｠〓〓〓"], Alignment::BottomCenter]], self::states($decoder));
        $this->assertSame(
            [
                [2.0, "The glyph of the character 0x24 of the set DRCS-1 (16x18 dots, MD5 " . md5(hex2bin(W::GLYPH_FRAME))
                      . ") has no known character. The reader wrote U+3013 for it."],
                [2.0, "The character 0x25 of the set DRCS-1 has no glyph in the stream. The reader wrote U+3013 for it."],
            ],
            array_map(fn (array $warning): array => [$warning[0] / 90000.0, $warning[1]], $decoder->warnings()),
        );
    }


    public function testDrawsAProfileCGlyphTwiceAsLargeToFindIt(): void
    {
        // A 16 by 18 glyph with 2 levels whose 36 by 36 form is not in the table: the decoder tries the forms and gives up.
        $decoder = self::decode([[0.0, W::statement(W::drcs([[0x4121, 2, 16, 18, str_repeat("00", 36)]]) . W::text("\x1B\x29\x20\x41\x0E\x21"))]]);

        $this->assertSame([[0.0, ["〓"], Alignment::TopLeft]], self::states($decoder));
    }


    public function testReadsTheAdditionalSymbolsAndKanjiOfRows85To94(): void
    {
        $body = W::CS . W::setup() . W::position(6, 3) . "\x75\x21\x7A\x21\x7C\x21\x7E\x21";

        $this->assertSame([[0.0, ["\u{3402}\u{26CC}\u{27A1}\u{2160}"], Alignment::BottomCenter]], self::states(self::decode([[0.0, W::statement(W::text($body))]])));
    }


    public function testReadsTheOtherSetsAndMovesTheActivePosition(): void
    {
        // G3 becomes katakana and LS3R invokes it in GR. APB steps back so that ウ replaces イ, and PAPF leaves 2 empty cells.
        // G2 becomes the JIS X 0201 katakana for SS2. COL selects palette 1 and its color 8.
        $body = W::CS . W::setup() . W::position(6, 3) . "\x1B\x2B\x31\x1B\x7C\xA2\xA4\x08\xA6\x16\x42\x1B\x2A\x49\x19\x31"
                . "\x90\x20\x41\x90\x48" . W::kanji("色");

        $this->assertSame(
            [[0.0, ["アウ ア<font color=\"#00ffaa\">色</font>"], Alignment::BottomCenter]],
            self::states(self::decode([[0.0, W::statement(W::text($body))]])),
        );
    }


    public function testRunsTheDefaultMacros(): void
    {
        // LS1 puts the alphanumeric set into GL. Macro 0x60 through SS3 puts the kanji set back.
        $body = W::CS . W::setup() . W::position(6, 3) . W::LS1 . "A" . "\x1D\x60" . W::kanji("字");

        $this->assertSame([[0.0, ["Ａ字"], Alignment::BottomCenter]], self::states(self::decode([[0.0, W::statement(W::text($body))]])));
    }


    public function testRepeatsACharacter(): void
    {
        $body = W::CS . W::setup() . W::position(6, 3) . "\x98\x43" . W::kanji("ー") . W::kanji("！");

        $this->assertSame([[0.0, ["ーーー！"], Alignment::BottomCenter]], self::states(self::decode([[0.0, W::statement(W::text($body))]])));
    }


    public function testReadsTheLatinSetsOfBrazilianCaptions(): void
    {
        // SS3 takes one character from G3, the Latin special set: 0x21 is the music note.
        $body = W::CS . W::setup() . W::position(6, 3) . "\x1D\x21 " . W::latin("Ação já, señor.") . W::APR . "\x91\x40" . W::latin("Até logo!");
        $decoder = self::decode([[0.0, W::management(["por"])], [1.0, W::statement(W::text($body))]]);

        $this->assertSame([[1.0, ["\u{266A} Ação já, señor.", "Até logo!"], Alignment::BottomCenter]], self::states($decoder));
    }


    public function testReadsUtf8CaptionsOfTheUcsCoding(): void
    {
        // The UCS coding sends the C1 codes, such as CSI 0x9B, as UTF-8.
        $body    = str_replace("\x9B", "\xC2\x9B", W::CS . W::setup()) . W::position(6, 0) . "Salamat. \xC2\x83Oo";
        $decoder = self::decode([[0.0, W::management(["tgl"], "", 0, true)], [1.0, W::statement(W::text($body))]]);

        $this->assertSame([[1.0, ["Salamat. <font color=\"#ffff00\">Oo</font>"], Alignment::BottomCenter]], self::states($decoder));
    }


    public function testSkipsARepeatedManagementGroupAndTheSecondLanguage(): void
    {
        $second  = substr_replace(W::statement(W::text(W::kanji("英"))), chr(0x02 << 2), 3, 1);
        $decoder = self::decode([
            [0.0, W::management(["jpn"])],
            [0.5, W::management(["por"])],
            [1.0, W::statement(W::text(W::CS . W::setup() . W::position(6, 7) . W::kanji("日")))],
            [2.0, $second],
        ]);

        $this->assertSame([[1.0, ["日"], Alignment::BottomCenter]], self::states($decoder));
    }


    public function testReadsTheLanguageOfManagementData(): void
    {
        $this->assertSame(["por", null], [AribCaptionDecoder::language(W::management(["por"])), AribCaptionDecoder::language(W::statement(""))]);
    }


    public function testStopsAtTruncatedCodes(): void
    {
        foreach (["\x1B", "\x1B\x24", "\x9B\x31\x32", "\x90", "\x1C\x40", "\x19", "\x95\x40", "\x9D\x29\x31", W::kanji("字")[0]] as $codes) {
            $this->assertSame([], self::states(self::decode([[0.0, W::statement(W::text($codes))]])), bin2hex($codes));
        }
    }


    /**
     * @param list<array{float, string}> $packets
     */
    private static function decode(array $packets): AribCaptionDecoder
    {
        $decoder = new AribCaptionDecoder("jpn");
        foreach ($packets as [$seconds, $data]) {
            $decoder->add($data, (int) ($seconds * 90000));
        }

        return $decoder;
    }


    /**
     * @return list<array{float, list<string>, Alignment}>
     */
    private static function states(AribCaptionDecoder $decoder): array
    {
        return array_values(array_map(fn (array $state): array => [$state[0] / 90000.0, $state[1], $state[2]],
                                      array_filter($decoder->finish(), fn (array $state): bool => $state[1] !== [] || $state[0] > 0)));
    }
}
