<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\MccReadOptions;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\SccRollUp;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MccParserTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/";

    private const HEADER = "File Format=MacCaption_MCC V2.0\nTime Code Rate=30DF\n\n";

    private const CEA608_CUES = [
        [2.302, 4.671, ["THE WINTER TIMETABLE", "STARTS ON MONDAY."], Alignment::BottomCenter],
        [5.472, 7.007, ["<i>[ANNOUNCER]</i>"], Alignment::BottomCenter],
        [8.475, 11.011, ["THE COAST TRAIN LEAVES AT", "6:50, TEN MINUTES EARLIER."], Alignment::BottomCenter],
    ];

    // The CEA-608 command EDM (Erase Displayed Memory) with parity.
    private const EDM = 0x942C;


    public static function sameCuesAsScc(): array
    {
        return [
            "CaptionMaker shape at 29.97 fps"   => ["captionmaker_popon_df.mcc", "captionmaker_popon_df.scc", SccRollUp::Screen],
            "59.94 fps with field 2 lines"      => ["v2_5994df_fields.mcc", "rollup_news_ndf.scc", SccRollUp::Screen],
            "59.94 fps with roll-up rows"       => ["v2_5994df_fields.mcc", "rollup_news_ndf.scc", SccRollUp::Lines],
        ];
    }


    #[DataProvider("sameCuesAsScc")]
    public function testReadsTheCuesOfTheSccFileWithTheSameBytePairs(string $mcc, string $scc, SccRollUp $rollUp): void
    {
        $options = new ReadOptions(formats: [new SccReadOptions(rollUp: $rollUp)]);

        $this->assertSame(
            Subtitle::load(self::DIR . "scc/real/$scc", options: $options)->toArray()["cues"],
            Subtitle::load(self::DIR . "mcc/real/$mcc", options: $options)->toArray()["cues"],
        );
    }


    public static function services(): array
    {
        return [
            "service 1 by default" => [new ReadOptions(), [
                [2.302, 5.472, ["L'horaire d'hiver", "débute lundi\u{2026}"], Alignment::BottomCenter],
                [5.472, 7.007, ["<i>\u{266A} Annonce \u{266A}</i>"], Alignment::TopLeft],
                [8.475, 11.011, ['<font color="#ffff00">Le train côtier part</font>', "à 6 h 50."], Alignment::BottomCenter],
            ]],
            "service 2"            => [new ReadOptions(formats: [new MccReadOptions(service: 2)]), [
                [2.302, 7.007, ["El horario de invierno", "empieza el lunes."], Alignment::BottomCenter],
            ]],
            "service 3 without captions gives CEA-608" => [new ReadOptions(formats: [new MccReadOptions(service: 3)]), self::CEA608_CUES],
            "CEA-608 only"         => [new ReadOptions(formats: [new MccReadOptions(service: null)]), self::CEA608_CUES],
        ];
    }


    #[DataProvider("services")]
    public function testReadsTheCea708ServiceAndElseTheCea608Captions(ReadOptions $options, array $cues): void
    {
        $subtitle = Subtitle::load(self::DIR . "mcc/real/captionmaker_608_708.mcc", Format::Mcc, $options);

        $this->assertSame($cues, array_map(fn (SubtitleCue $cue): array => [round($cue->getStart(), 3), round($cue->getEnd(), 3), $cue->getLines(),
                                                                         $cue->getAlignment()], $subtitle->getCues()));
    }


    public function testServiceOptionTakes1To63(): void
    {
        $this->assertSame(63, (new MccReadOptions(service: 63))->service);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The CEA-708 service must be from 1 to 63, got 64.");

        new MccReadOptions(service: 64);
    }


    public static function formatData(): array
    {
        return [
            "CaptionMaker shape"    => ["captionmaker_popon_df.mcc", "2.0", "29.97", true],
            "23.976 fps"            => ["v1_23976_timecode.mcc", "1.0", "23.976", false],
            "59.94 fps drop-frame"  => ["v2_5994df_fields.mcc", "2.0", "59.94", true],
            "ffmpeg at 30 fps"      => ["ffmpeg_painton_v1.mcc", "1.0", "30", false],
        ];
    }


    #[DataProvider("formatData")]
    public function testStoresTheVersionAndTheFrameRate(string $file, string $version, string $frameRate, bool $dropFrame): void
    {
        $subtitle = Subtitle::load(self::DIR . "mcc/real/$file");

        $data = $subtitle->findFormatData("mcc");
        $this->assertSame([$version, $frameRate, $dropFrame], [$data["version"], $data["frameRate"], $data["dropFrame"]]);
        $this->assertSame(Format::Mcc, $subtitle->findFormat());
    }


    public function testKeepsTheHeaderFieldsWithoutTheTimeCodeRate(): void
    {
        $this->assertSame(
            ["UUID" => "6F1D3C2A-4B5E-4F60-8A71-92B3C4D5E6F7", "Creation Program" => "Subtitle Toolbox fixture generator",
             "Creation Date" => "Monday, October 12, 2026", "Creation Time" => "09:14:05"],
            Subtitle::load(self::DIR . "mcc/real/captionmaker_popon_df.mcc")->findFormatData("mcc")["header"],
        );
    }


    public function testReadsTheTimeCodeRateAsTheFrameRateWithoutAPacket(): void
    {
        $subtitle = Subtitle::fromString("File Format=MacCaption_MCC V1.0\nTime Code Rate=25\n");

        $this->assertSame(["1.0", "25", false], array_values(array_slice($subtitle->findFormatData("mcc"), 0, 3)));
    }


    public function testReadsTheAliasLettersAndLowerCaseHex(): void
    {
        $this->assertSame("\xFA\0\0\xFA\0\0\xFB\x80\x80\xFC\x80\x80\xFD\x80\x80\x96\x69\x61\x01\xE1\0\0\0\0\x9F\xA0",
                          Mcc::decodeHex("HPQRSTUZ9fA0", 1));
        $packet = self::packet(self::EDM);
        $this->assertSame([4, "\x00\x94\x2C"],
                          Mcc::readPacket(Mcc::decodeHex(strtolower(bin2hex(Mcc::decodeHex($packet, 1))), 1), 1));
    }


    public function testSkipsAnAncPacketOfOtherData(): void
    {
        // An ANC packet with the DID 0x41 and the SDID 0x05 holds AFD data, not captions.
        $this->assertNull(Mcc::readPacket(Mcc::decodeHex("4105080800000000000000005E", 1), 1));
        $this->assertSame([], (new MccParser())->parse(self::HEADER . "00:00:00:00\t4105080800000000000000005E\n")->getCues());
    }


    public function testReadsAPacketWithoutSections(): void
    {
        $this->assertSame([4, ""], Mcc::readPacket("\x61\x01\x07\x96\x69\x07\x4F\x43\x00\x00", 1));
    }


    public function testANullPairEndsALineOfTheCaptionData(): void
    {
        // RDC, PAC row 15, "AB", a null pair, then "CD": the null pair splits the paint-on data into 2 states.
        $words = [0x9429, 0x9429, 0x94E0, 0x94E0, 0xC1C2, 0x8080, 0x43C4];
        $lines = "";
        foreach ($words as $frame => $word) {
            $lines .= sprintf("00:00:01:%02d\t%s\n", $frame, self::packet($word));
        }
        $lines .= "00:00:03:00\t" . self::packet(self::EDM) . "\n";

        $cues = (new MccParser())->parse(self::HEADER . $lines)->getCues();

        $this->assertSame([["AB"], ["ABCD"]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $cues));
    }


    public function testThrowsForAFileWithoutAnMccHeader(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("An MCC file must start with the line \"File Format=MacCaption_MCC V2.0\" or \"File Format=MacCaption_MCC V1.0\". (line 2)");

        (new MccParser())->parse("\nScenarist_SCC V1.0\n");
    }


    public static function brokenLines(): array
    {
        return [
            "no time code"       => ["captions", "The MCC line \"captions\" does not start with a time code."],
            "invalid letter"     => ["00:00:00:00\tT49SV", "The MCC data has the invalid character \"V\" at position 5."],
            "half a byte"        => ["00:00:00:00\tT4", "The MCC data ends with half a byte."],
            "no CDP identifier"  => ["00:00:00:00\tT0A9670ZZZZZZZZZZZZZZ", "The data holds no caption distribution packet."],
            "short cc_data"      => ["00:00:00:00\tT0BS0B4F43ZZ72F4FC", "The caption distribution packet is shorter than its 20 cc_data triplets."],
        ];
    }


    #[DataProvider("brokenLines")]
    public function testThrowsForABrokenLineInStrictMode(string $line, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("$message (line 4)");

        (new MccParser())->parse(self::HEADER . "$line\n");
    }


    #[DataProvider("brokenLines")]
    public function testSkipsABrokenLineInLenientMode(string $line, string $message): void
    {
        $subtitle = (new MccParser())->parse(self::HEADER . "$line\n00:00:01:00\t" . self::packet(self::EDM) . "\n", new ReadOptions(lenient: true));

        $this->assertSame([[$message, 4, ParseWarningAction::Skipped]],
                          array_map(fn ($warning): array => [$warning->message, $warning->lineNumber, $warning->action], $subtitle->getParseWarnings()));
    }


    public function testReadsAFileWithoutATimeCodeRateAs30DfInLenientMode(): void
    {
        $content = "File Format=MacCaption_MCC V2.0\n\n00:00:01:00\t" . self::packet(self::EDM) . "\n";

        try {
            (new MccParser())->parse($content);
            $this->fail("A file without a Time Code Rate must throw in strict mode.");
        } catch (ParsingException $exception) {
            $this->assertSame("The MCC file has no Time Code Rate line.", $exception->getRawMessage());
        }
        $subtitle = (new MccParser())->parse($content, new ReadOptions(lenient: true));
        $this->assertSame(["29.97", true], [$subtitle->findFormatData("mcc")["frameRate"], $subtitle->findFormatData("mcc")["dropFrame"]]);
        $this->assertSame("The MCC file has no Time Code Rate line. The parser read 30DF.", $subtitle->getParseWarnings()[0]->message);
    }


    public function testThrowsForAnUnknownTimeCodeRate(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The MCC Time Code Rate \"25DF\" is unknown.");

        (new MccParser())->parse("File Format=MacCaption_MCC V2.0\nTime Code Rate=25DF\n");
    }


    public function testDetectsMcc(): void
    {
        $this->assertSame(Format::Mcc, Format::detect(file_get_contents(self::DIR . "mcc/real/ffmpeg_rollup.mcc")));
        $this->assertSame(Format::Mcc, Format::tryFromPath("show.mcc"));
    }


    /**
     * Returns an MCC packet at 29.97 fps with one field 1 pair, as MccFormatter writes it.
     */
    private static function packet(int $word): string
    {
        return Mcc::encodeHex(Mcc::writePacket(4, 0, [[Mcc::NTSC_FIELD_1, true, $word], [Mcc::NTSC_FIELD_2, true, 0x8080],
                                                   ...array_fill(0, 18, [Mcc::DTVCC_DATA, false, 0])]));
    }
}
