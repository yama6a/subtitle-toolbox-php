<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use SubtitleToolbox\Container\MpegTs\AribFixtureWriter as W;

require_once __DIR__ . "/TsFixtureWriter.php";
require_once __DIR__ . "/AribFixtureWriter.php";

/**
 * Builds the ARIB caption fixtures in the shape of Japanese ISDB and Brazilian ISDB-Tb recordings. generate.php writes them
 * to tests/files/mpegts/. All text is written for this repository.
 */
final class AribFixtures
{
    public const FILES = [
        "arib_japanese.ts"     => "japanese",
        "arib_brazilian.m2ts" => "brazilian",
    ];

    public const VIDEO_PID   = 0x0111;
    public const CAPTION_PID = 0x0130;
    public const PMT_PID     = 0x01F0;


    /**
     * A caption stream in Japanese. The video starts at 10 s and the captions at 11 s. Times in the comments count from the video.
     */
    public static function japanese(): string
    {
        $writer = self::writer();
        $glyphs = W::drcs([[0x4121, 4, 18, 36, W::GLYPH_FF5F], [0x4122, 4, 18, 36, W::GLYPH_FF60], [0x4123, 4, 36, 36, W::GLYPH_51DC],
                           [0x4124, 2, 16, 18, W::GLYPH_FRAME]]);
        // ESC 0x29 0x20 0x41 puts the set DRCS-1 into G1, so that LS1 invokes it in GL. LS0 goes back to the kanji set.
        $drcs   = fn (string $codes): string => "\x1B\x29\x20\x41" . W::LS1 . $codes . W::LS0 . "\x1B\x29\x4A";
        $start  = W::CS . W::setup();

        $statements = [
            // 1.0: the management data and two rows, with furigana over 今日 and 天気, and a yellow row.
            "1.0" => W::text($start . W::position(6, 3) . W::kanji("今日はいい天気です。") . W::SSZ . W::dots(300, 390) . W::hiragana("きょう")
                             . W::dots(500, 390) . W::hiragana("てんき") . W::NSZ . W::position(7, 3) . W::YELLOW . W::kanji("明日は雨でしょう。")),
            "4.0" => W::text(W::CS),
            // 4.5: DRCS glyphs. Three are known, the frame is not.
            "4.5" => $glyphs . W::text($start . W::position(6, 4) . $drcs("\x21") . W::kanji("拍手") . $drcs("\x22") . W::position(7, 4)
                                       . $drcs("\x23") . W::kanji("とした声") . $drcs("\x24")),
            // 6.0: top left, with alphanumerics at middle size and normal size. TIME waits 2 s, then CS clears the screen.
            "6.0" => W::text($start . W::position(0, 0) . W::kanji("はい、") . W::MSZ . W::LS1 . "OK" . W::LS0 . W::NSZ . W::LS1 . "OK" . W::LS0
                             . W::wait(20) . W::CS),
        ];

        $writer->privatePes(self::CAPTION_PID, self::pts(1.0), W::management(["jpn"]));
        foreach ($statements as $time => $units) {
            $writer->privatePes(self::CAPTION_PID, self::pts((float) $time), W::statement($units));
        }
        // A repeat of the management data with the same data group, as broadcasters send it every few seconds.
        $writer->privatePes(self::CAPTION_PID, self::pts(7.0), W::management(["jpn"]));
        $writer->videoPes(self::VIDEO_PID, self::pts(10.0));

        return $writer->bytes();
    }


    /**
     * A caption stream in Portuguese with the Latin sets, in 192-byte M2TS packets. The video starts at 10 s.
     */
    public static function brazilian(): string
    {
        $writer = self::writer();
        $start  = W::CS . W::setup();
        $writer->privatePes(self::CAPTION_PID, self::pts(0.5), W::management(["por"]));
        // SS3 takes the music note 0x21 from G3, the Latin special set.
        $writer->privatePes(self::CAPTION_PID, self::pts(1.0), W::statement(W::text(
            $start . W::position(6, 4) . "\x1D\x21 " . W::latin("Ação e emoção") . " \x1D\x21" . W::position(7, 4) . W::CYAN
            . W::latin("Olá, você está aí?"),
        )));
        $writer->privatePes(self::CAPTION_PID, self::pts(3.5), W::statement(W::text(W::CS)));
        $writer->privatePes(self::CAPTION_PID, self::pts(4.0), W::statement(W::text(
            $start . W::position(7, 8) . W::latin("Até amanhã, João.") . W::wait(15) . W::CS,
        )));
        $writer->videoPes(self::VIDEO_PID, self::pts(8.0));

        return TsFixtureWriter::m2ts($writer->bytes());
    }


    private static function writer(): TsFixtureWriter
    {
        // The stream identifier descriptor with the component tag 0x30 and the data component descriptor of captions, 0x0008,
        // with the additional_arib_caption_info byte of ARIB TR-B14.
        $descriptors = "\x52\x01\x30" . "\xFD\x03\x00\x08\x3D";
        $writer      = new TsFixtureWriter();
        $writer->tables(1, self::PMT_PID, self::VIDEO_PID, [[self::VIDEO_PID, 0x02, ""], [self::CAPTION_PID, 0x06, $descriptors]]);
        $writer->videoPes(self::VIDEO_PID, self::pts(0.0));

        return $writer;
    }


    private static function pts(float $seconds): int
    {
        return (int) round((10.0 + $seconds) * 90000);
    }
}
