<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

require_once __DIR__ . "/TsFixtureWriter.php";

/**
 * Builds the MPEG-TS test fixtures. generate.php writes them to tests/files/mpegts/. All text is written for this repository.
 * Row text holds G0 codes: in the German set "{" is "ä", "}" is "ü" and "~" is "ß".
 */
final class TsFixtures
{
    public const FILES = [
        "teletext.ts" => "teletext",
        "one_page.ts" => "onePage",
    ];

    public const VIDEO_PID    = 0x0100;
    public const TELETEXT_PID = 0x0200;
    public const PMT_PID      = 0x1000;

    // C12 to C14 of the German and the English national option, with C12 as bit 0.
    private const GERMAN  = 4;
    private const ENGLISH = 0;


    /**
     * The video starts at 10 s and the teletext stream at 10.5 s. Times in the comments count from the video.
     */
    public static function teletext(): string
    {
        $box    = fn (string $text): string => TsFixtureWriter::START_BOX . $text . TsFixtureWriter::END_BOX;
        $header = TsFixtureWriter::header(...);
        $row    = TsFixtureWriter::row(...);

        // A single bit error in the page units of a header, which Hamming 8/4 corrects.
        $damaged    = $header(888, true, self::GERMAN);
        $damaged[2] = chr(ord($damaged[2]) ^ 0x40);

        $writer = new TsFixtureWriter();
        $writer->tables(1, self::PMT_PID, self::VIDEO_PID, [
            [self::VIDEO_PID, 0x02, ""],
            [self::TELETEXT_PID, 0x06, TsFixtureWriter::teletextDescriptor([
                ["eng", TsFixtureWriter::INITIAL_PAGE, 100],
                ["deu", TsFixtureWriter::SUBTITLE_PAGE, 888],
                ["deu", TsFixtureWriter::HEARING_IMPAIRED_PAGE, 777],
                ["eng", TsFixtureWriter::SUBTITLE_PAGE, 801],
                ["pol", TsFixtureWriter::SUBTITLE_PAGE, 802],
                ["eng", TsFixtureWriter::SUBTITLE_PAGE, 301],
            ])],
        ]);
        $writer->videoPes(self::VIDEO_PID, self::pts(0));

        $end   = TsFixtureWriter::fillingHeader(...);
        $pages = [
            // 888 in German: double height rows, a box and a yellow row. The header of page 100 in magazine 1 does not end
            // the transmission of 888 in parallel mode.
            "0.5" => [$header(888, true, self::GERMAN), $row(888, 20, TsFixtureWriter::DOUBLE_HEIGHT . $box("Der Zug f{hrt um acht Uhr.")),
                      $header(100, true, self::ENGLISH, false), $row(100, 1, "Index"),
                      $row(888, 22, TsFixtureWriter::DOUBLE_HEIGHT . "\x03" . $box("- Wir m}ssen uns beeilen!")), $end(8), $end(1)],
            // 777 for hearing impaired people.
            "1.0" => [$header(777, true, self::GERMAN), $row(777, 22, $box("(Leise Musik)")), $end(7)],
            // 801 with English in the PMT and the Russian Cyrillic set from packet X/28.
            "1.5" => [$header(801, true, 1), TsFixtureWriter::designation(801, 28, 4, 1), $row(801, 22, $box("Priwet, mir.")), $end(8)],
            // 777 adds a row without the erase flag.
            "2.0" => [$header(777, false, self::GERMAN), $row(777, 23, $box("Hallo?")), $end(7)],
            // 802 in Polish takes the Polish set from the language of the PMT.
            "2.5" => [$header(802, true, self::ENGLISH), $row(802, 22, $box("Dzie\$ dobry, ]_d~!")), $end(8)],
            // 888 with a cyan and a white run on one row and text outside the box.
            "3.0" => [$damaged, $row(888, 21, "\x06" . $box("- Ja?\x07- Nein.") . " Hidden"),
                      $row(888, 22, "\x02" . $box("Gr}~e aus der Stra~e.")), $end(8)],
            "4.0" => [$header(777, true, self::GERMAN), $end(7)],
            "4.5" => [$header(801, true, 1), $end(8)],
            "5.0" => [$header(888, true, self::GERMAN), $end(8)],
            "5.5" => [$header(802, true, self::ENGLISH), $end(8)],
            // 301 with English in the PMT and the Greek set from packet M/29 of magazine 3.
            "6.0" => [TsFixtureWriter::designation(301, 29, 6, 7), $header(301, true, 7), $row(301, 22, $box("Jakgl]qa")), $end(3),
                      $header(888, true, self::GERMAN), $row(888, 21, $box("Es ist 20 Uhr.")), $end(8)],
            // 888 sends the same page again. The header of page 899 ends its transmission, so row 23 of 899 is not part of 888.
            "8.0" => [$header(888, true, self::GERMAN), $row(888, 21, $box("Es ist 20 Uhr."))],
            "8.5" => [$header(899, true, self::GERMAN, false), $row(899, 23, "Andere Seite"), $end(8)],
            "9.0" => [$header(301, true, 7), $end(3)],
            "10.0" => [$header(888, true, self::GERMAN), $end(8)],
            // The last page has no erase after it. It ends with the last PES packet of the stream.
            "11.0" => [$header(888, true, self::GERMAN), $row(888, 22, $box("Ende.")), $end(8)],
            "12.0" => [$end(1)],
        ];
        foreach ($pages as $seconds => $packets) {
            $writer->teletextPes(self::TELETEXT_PID, self::pts((float) $seconds), $packets);
        }

        return $writer->bytes();
    }


    /**
     * One English subtitle page.
     */
    public static function onePage(): string
    {
        $box    = fn (string $text): string => TsFixtureWriter::START_BOX . $text . TsFixtureWriter::END_BOX;
        $writer = new TsFixtureWriter();
        $writer->tables(1, self::PMT_PID, self::VIDEO_PID, [
            [self::VIDEO_PID, 0x02, ""],
            [self::TELETEXT_PID, 0x06, TsFixtureWriter::teletextDescriptor([["eng", TsFixtureWriter::SUBTITLE_PAGE, 888]])],
        ]);
        $writer->videoPes(self::VIDEO_PID, self::pts(0));
        $pages = [
            "1.0" => [TsFixtureWriter::header(888, true), TsFixtureWriter::row(888, 22, $box("The ferry leaves at noon.")),
                      TsFixtureWriter::fillingHeader(8)],
            "3.5" => [TsFixtureWriter::header(888, true), TsFixtureWriter::row(888, 20, $box("Tickets cost 4 euros.")),
                      TsFixtureWriter::row(888, 22, "\x03" . $box("- Cash only?")), TsFixtureWriter::fillingHeader(8)],
            "6.0" => [TsFixtureWriter::header(888, true), TsFixtureWriter::fillingHeader(8)],
        ];
        foreach ($pages as $seconds => $packets) {
            $writer->teletextPes(self::TELETEXT_PID, self::pts((float) $seconds), $packets);
        }

        return $writer->bytes();
    }


    private static function pts(float $seconds): int
    {
        return (int) round((10 + $seconds) * 90000);
    }
}
