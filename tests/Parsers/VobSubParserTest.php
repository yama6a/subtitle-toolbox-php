<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\FakeOcrEngine;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require_once __DIR__ . "/../Ocr/FakeOcrEngine.php";

class VobSubParserTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/vobsub/";

    private const IDX_HEADER = "# VobSub index file, v7 (do not modify this line!)\nsize: 720x576\n" .
                               "palette: 000000, f0f0f0, cccccc, 999999, 3333fa, 1111bb, fa3333, bb1111, " .
                               "33fa33, 11bb11, fafa33, bbbb11, fa33fa, bb11bb, 33fafa, 11bbbb\n";

    // A 2x2 unit: pixel value 1 everywhere, pattern color 1 (f0f0f0), opaque, at x 100 and y 200, without a stop command.
    private const SMALL_UNIT = "\x00\x1E\x00\x06\x90\x90" .
                               "\x00\x00\x00\x06\x03\x00\x10\x04\x00\xF0\x05\x06\x40\x65\x0C\x80\xC9\x06\x00\x04\x00\x05\x01\xFF";

    // A unit with only a stop command, as DVDs use to clear the screen.
    private const CLEAR_UNIT = "\x00\x0A\x00\x04\x00\x00\x00\x04\x02\xFF";


    private function parseFixture(string $name, int|string|null $track = null): Subtitle
    {
        $options = new ReadOptions(formats: [new VobSubReadOptions(
            file_get_contents(self::DIR . "$name.idx"),
            track: is_int($track) ? $track : null,
            language: is_string($track) ? $track : null,
        )]);

        return (new VobSubParser())->parse(file_get_contents(self::DIR . "$name.sub"), $options);
    }


    /**
     * @return array{float, float, int, int, int, int, int, int, bool}
     */
    private function describeImageCue(SubtitleCue $cue): array
    {
        $image = CueImage::fromCue($cue);

        return [$cue->getStart(), $cue->getEnd(), $image->x, $image->y, $image->width, $image->height,
                $image->screenWidth, $image->screenHeight, $image->forced];
    }


    /**
     * @return list<int> 0xRRGGBBAA in row-major order
     */
    private function readPixels(CueImage $image): array
    {
        $png    = $image->png;
        $data   = "";
        $offset = 8;
        while ($offset < strlen($png)) {
            $length = unpack("N", $png, $offset)[1];
            if (substr($png, $offset + 4, 4) === "IDAT") {
                $data .= substr($png, $offset + 8, $length);
            }
            $offset += 12 + $length;
        }

        $rows   = str_split(gzuncompress($data), 1 + 4 * $image->width);
        $pixels = [];
        foreach ($rows as $row) {
            $this->assertSame("\0", $row[0]);
            array_push($pixels, ...array_values(unpack("N*", substr($row, 1))));
        }
        $this->assertCount($image->width * $image->height, $pixels);

        return $pixels;
    }


    private function pixelAt(CueImage $image, int $x, int $y): string
    {
        return sprintf("%08x", $this->readPixels($image)[$y * $image->width + $x]);
    }


    /**
     * Packs each unit into one MPEG-2 pack with 2 bytes of pack stuffing, and returns the .sub content and the fileposes.
     *
     * @param list<string> $units
     * @return array{string, list<int>}
     */
    private function makeSub(array $units, int $track = 0): array
    {
        $sub      = "";
        $fileposes = [];
        foreach ($units as $unit) {
            $fileposes[] = strlen($sub);
            $body        = "\x81\x00\x00" . chr(0x20 + $track) . $unit;
            $sub        .= "\x00\x00\x01\xBA\x44\x00\x04\x00\x04\x01\x01\x89\xC3\xFA\xFF\xFF" .
                           "\x00\x00\x01\xBD" . pack("n", strlen($body)) . $body;
        }

        return [$sub, $fileposes];
    }


    public static function fixtureProvider(): array
    {
        return [
            "first track"         => ["two-tracks-pal", null, "en", 5,
                                      [1.5, 4.003, 210, 500, 300, 40, 720, 576, false],
                                      [20.0, 25.0, 10, 10, 64, 16, 720, 576, false]],
            "track by language"   => ["two-tracks-pal", "DE", "de", 2,
                                      [3.0, 3.99, 309, 470, 101, 25, 720, 576, false],
                                      [3724.456, 3726.447, 0, 0, 50, 10, 720, 576, false]],
            "track by index"      => ["two-tracks-pal", 1, "de", 2,
                                      [3.0, 3.99, 309, 470, 101, 25, 720, 576, false],
                                      [3724.456, 3726.447, 0, 0, 50, 10, 720, 576, false]],
            "ntsc first track"    => ["custom-colors-ntsc", null, "ja", 1,
                                      [0.5, 1.501, 280, 440, 160, 20, 720, 480, false],
                                      [0.5, 1.501, 280, 440, 160, 20, 720, 480, false]],
            "ntsc substream 0x22" => ["custom-colors-ntsc", 2, "fr", 2,
                                      [61.0, 62.502, 240, 420, 240, 36, 720, 480, false],
                                      [125.125, 125.626, 320, 30, 80, 12, 720, 480, true]],
        ];
    }


    #[DataProvider("fixtureProvider")]
    public function testFixtureParses(string $name, int|string|null $track, string $language, int $cueCount,
                                      array $firstCue, array $lastCue): void
    {
        $subtitle = $this->parseFixture($name, $track);
        $cues     = $subtitle->getCues();

        $this->assertSame($language, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $this->describeImageCue($cues[0]));
        $this->assertSame($lastCue, $this->describeImageCue(end($cues)));
        foreach ($cues as $cue) {
            $this->assertSame([], $cue->getLines());
        }
    }


    public function testCueTimesUseStartAndStopDelaysAndOtherwiseTheNextCue(): void
    {
        $cues = array_map(fn (SubtitleCue $cue): array => $this->describeImageCue($cue), $this->parseFixture("two-tracks-pal")->getCues());

        $this->assertSame([
            [1.5, 4.003, 210, 500, 300, 40, 720, 576, false],       // stop at 220 * 1024 / 90000 s
            [5.762, 8.254, 260, 40, 200, 24, 720, 576, true],       // forced start at 45 units, stop at 264 units
            [9.0, 11.0, 300, 520, 120, 30, 720, 576, false],        // no stop, ends at the next cue
            [11.0, 13.002, 0, 544, 720, 32, 720, 576, false],       // spans six 2048 byte packs
            [20.0, 25.0, 10, 10, 64, 16, 720, 576, false],          // no stop and no next cue, ends 5 s later
        ], $cues);
    }


    public function testDecodesPaletteColorsAndAlphaAtKnownPixels(): void
    {
        $cues = $this->parseFixture("two-tracks-pal")->getCues();

        $first = CueImage::fromCue($cues[0]);
        $this->assertSame("00000000", $this->pixelAt($first, 0, 0));      // background, palette 0, alpha 0
        $this->assertSame("000000ff", $this->pixelAt($first, 2, 2));      // emphasis 2, palette 0
        $this->assertSame("fa3333ff", $this->pixelAt($first, 3, 3));      // emphasis 1, palette 6, bottom field
        $this->assertSame("f0f0f0ff", $this->pixelAt($first, 3, 4));      // pattern, palette 1, top field
        $this->assertSame("f0f0f0ff", $this->pixelAt($first, 11, 3));
        $this->assertSame("00000000", $this->pixelAt($first, 299, 39));

        $forced = CueImage::fromCue($cues[1]);
        $this->assertSame("f0f0f0ff", $this->pixelAt($forced, 2, 2));     // emphasis 2, palette 1
        $this->assertSame("3333fa88", $this->pixelAt($forced, 3, 3));     // emphasis 1, palette 4, alpha 8
        $this->assertSame("fafa33ff", $this->pixelAt($forced, 3, 4));     // pattern, palette 10
    }


    public function testDecodesEveryPixelOfAUnitThatSpansSeveralPacks(): void
    {
        $image  = CueImage::fromCue($this->parseFixture("two-tracks-pal")->getCues()[3]);
        $colors = [0x00000000, 0xF0F0F0FF, 0xFA3333FF, 0x33FA33FF];

        $expected = [];
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 720; $x++) {
                $expected[] = $colors[($x + 2 * $y) % 4];
            }
        }
        $this->assertSame($expected, $this->readPixels($image));
    }


    public function testDecodesAnOddHeightWithMoreTopFieldLines(): void
    {
        $image = CueImage::fromCue($this->parseFixture("two-tracks-pal", "de")->getCues()[0]);

        $this->assertSame("000000ff", $this->pixelAt($image, 50, 22));    // outline, top field
        $this->assertSame("fa3333ff", $this->pixelAt($image, 50, 21));    // bottom field
        $this->assertSame("000000ff", $this->pixelAt($image, 98, 10));    // outline at the last column
        $this->assertSame("00000000", $this->pixelAt($image, 100, 24));   // last line, top field
    }


    public function testCustomColorsReplaceThePaletteAndTheAlpha(): void
    {
        $image = CueImage::fromCue($this->parseFixture("custom-colors-ntsc", "fr")->getCues()[0]);

        $this->assertSame("00000000", $this->pixelAt($image, 0, 0));      // tridx 1 for the background
        $this->assertSame("ff0000ff", $this->pixelAt($image, 2, 2));
        $this->assertSame("808080ff", $this->pixelAt($image, 3, 3));
        $this->assertSame("ffffffff", $this->pixelAt($image, 3, 4));
    }


    public function testOcrSetsTheTextAndKeepsTheImages(): void
    {
        $subtitle = $this->parseFixture("two-tracks-pal", "de");
        $engine   = new FakeOcrEngine(["Der Zug", "<i>fährt ab.</i>"]);

        $subtitle->recognizeText($engine, "deu");

        $this->assertCount(2, $engine->calls);
        $this->assertSame("deu", $engine->calls[0]["language"]);
        $this->assertSame(101, $engine->calls[0]["image"]->width);
        $this->assertSame(["Der Zug", "<i>fährt ab.</i>"], $subtitle->getCues()[1]->getLines());
        $this->assertTrue(CueImage::isImageCue($subtitle->getCues()[1]));
        $this->assertSame("\xEF\xBB\xBF1\n00:00:03,000 --> 00:00:03,990\nDer Zug\n<i>fährt ab.</i>\n\n" .
                          "2\n01:02:04,456 --> 01:02:06,447\nDer Zug\n<i>fährt ab.</i>\n",
                          $subtitle->toString(Format::SubRip));
    }


    public function testTextFormatterThrowsWithoutOcr(): void
    {
        $this->expectException(ImageCueWithoutTextException::class);

        $this->parseFixture("two-tracks-pal")->toString(Format::SubRip);
    }


    public function testUnitWithoutImageEndsThePreviousCueAndAddsNoCue(): void
    {
        [$sub, $fileposes] = $this->makeSub([self::SMALL_UNIT, self::CLEAR_UNIT], 3);
        $idx = self::IDX_HEADER . "id: --, index: 3\n" .
               sprintf("timestamp: 00:00:10:000, filepos: %09x\n", $fileposes[0]) .
               sprintf("timestamp: 00:00:11:200, filepos: %09x\n", $fileposes[1]);

        $subtitle = (new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx)]));

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame([10.0, 11.2, 100, 200, 2, 2, 720, 576, false], $this->describeImageCue($subtitle->getCues()[0]));
        $this->assertSame("f0f0f0ff", $this->pixelAt(CueImage::fromCue($subtitle->getCues()[0]), 1, 1));
    }


    public function testSkipsTheChangeColorAndContrastCommand(): void
    {
        $unit              = "\x00\x25" . substr(self::SMALL_UNIT, 2, 8) . "\x07\x00\x06\x0F\xFF\xFF\xFF" . substr(self::SMALL_UNIT, 10);
        [$sub, $fileposes] = $this->makeSub([$unit]);
        $idx               = self::IDX_HEADER . "id: en, index: 0\n" . sprintf("timestamp: 00:00:01:000, filepos: %09x\n", $fileposes[0]);

        $this->assertSame([1.0, 6.0, 100, 200, 2, 2, 720, 576, false],
                          $this->describeImageCue((new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx)]))->getCues()[0]));
    }


    public function testDelayLinesAddUpWithinATrack(): void
    {
        [$sub, $fileposes] = $this->makeSub([self::SMALL_UNIT]);
        $idx = self::IDX_HEADER . "delay: 00:00:05:000\nid: en, index: 0\ndelay: 00:00:01:000\ndelay: -00:00:00:250\n" .
               sprintf("timestamp: 00:00:10:000, filepos: %09x\n", $fileposes[0]);

        $this->assertSame(10.75, (new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx)]))->getCues()[0]->getStart());
    }


    public function testSkipsPacketsOfOtherTracks(): void
    {
        [$other]           = $this->makeSub([self::SMALL_UNIT], 1);
        [$sub, $fileposes] = $this->makeSub([substr(self::SMALL_UNIT, 0, 10)]);
        $sub              .= $other . $this->makeSub([substr(self::SMALL_UNIT, 10)])[0];
        $idx               = self::IDX_HEADER . "id: en, index: 0\n" . sprintf("timestamp: 00:00:01:000, filepos: %09x\n", $fileposes[0]);

        $this->assertSame([1.0, 6.0, 100, 200, 2, 2, 720, 576, false],
                          $this->describeImageCue((new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx)]))->getCues()[0]));
    }


    public static function invalidIndexProvider(): array
    {
        return [
            "no header line"        => ["size: 720x576\nid: en, index: 0\n", null,
                                        "The .idx content does not start with the \"VobSub index file\" line."],
            "no size"               => ["# VobSub index file, v7\npalette: " . str_repeat("000000, ", 15) . "000000\nid: en, index: 0\n", null,
                                        "The .idx content has no size line."],
            "no palette"            => ["# VobSub index file, v7\nsize: 720x576\nid: en, index: 0\n", null,
                                        "The .idx content has no palette line."],
            "short palette"         => [self::IDX_HEADER . "palette: 000000, ffffff\n", null,
                                        "The .idx line \"palette: 000000, ffffff\" needs 16 colors as hex RGB."],
            "no track"              => [self::IDX_HEADER, null, "The .idx content has no \"id:\" line."],
            "unknown language"      => [self::IDX_HEADER . "id: en, index: 0\n", "fr", "The .idx content has no track with language \"fr\"."],
            "timestamp before id"   => [self::IDX_HEADER . "timestamp: 00:00:01:000, filepos: 000000000\n", null,
                                        "The .idx timestamp line \"timestamp: 00:00:01:000, filepos: 000000000\" comes before any id line."],
            "100000 hours"          => [self::IDX_HEADER . "id: en, index: 0\ntimestamp: 100000:00:00:000, filepos: 0\n", null,
                                        "The time \"timestamp: 100000:00:00:000, filepos: 0\" is not below 100000 hours."],
            "delay past the limit"  => [self::IDX_HEADER . "id: en, index: 0\ndelay: 99999:59:59:000\ntimestamp: 00:00:01:000, filepos: 0\n", null,
                                        "The time \"timestamp: 00:00:01:000, filepos: 0 with delay 359999999s\" is not below 100000 hours."],
            "invalid timestamp"     => [self::IDX_HEADER . "id: en, index: 0\ntimestamp: 1.5, filepos: 0\n", null,
                                        "The .idx time \"timestamp: 1.5, filepos: 0\" is not valid."],
        ];
    }


    #[DataProvider("invalidIndexProvider")]
    public function testInvalidIndexThrows(string $idx, ?string $language, string $message): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($message);

        (new VobSubParser())->parse("", new ReadOptions(formats: [new VobSubReadOptions($idx, language: $language)]));
    }


    public function testTrackAndLanguageSelectTheTrackTogether(): void
    {
        $idx = file_get_contents(self::DIR . "two-tracks-pal.idx");
        $sub = file_get_contents(self::DIR . "two-tracks-pal.sub");

        $byIndex = (new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx, track: 1)]));
        $this->assertSame("de", $byIndex->findMetadata(Subtitle::METADATA_LANGUAGE));

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The .idx content has no track with index 1 and language \"en\".");
        (new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx, 1, "en")]));
    }


    public function testWithoutVobSubReadOptionsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("VobSub needs the .idx content. Set VobSubReadOptions::\$idx.");

        Subtitle::fromString(file_get_contents(self::DIR . "two-tracks-pal.sub"), Format::VobSub);
    }


    public function testFileposOutsideTheSubContentThrows(): void
    {
        $idx = file_get_contents(self::DIR . "two-tracks-pal.idx");
        $sub = file_get_contents(self::DIR . "two-tracks-pal.sub");

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The filepos 000002000 of timestamp 11.000 is outside the .sub content of 8192 bytes.");

        (new VobSubParser())->parse(substr($sub, 0, 0x2000), new ReadOptions(formats: [new VobSubReadOptions($idx)]));
    }


    public function testLenientModeSkipsAPacketThatTheEndOfTheFileCutOff(): void
    {
        $sub     = file_get_contents(self::DIR . "text-pal.sub");
        $cut     = substr($sub, 0, intdiv(strlen($sub) * 9, 10));
        $options = fn (bool $lenient): ReadOptions => new ReadOptions(lenient: $lenient, formats: [new VobSubReadOptions(file_get_contents(self::DIR . "text-pal.idx"))]);

        $subtitle = (new VobSubParser())->parse($cut, $options(true));

        $this->assertEquals(array_slice($this->parseFixture("text-pal")->getCues(), 0, 5), $subtitle->getCues());
        $this->assertEquals([new ParseWarning("The .sub packet at byte 14350 is longer than the content.", null, 5, [], ParseWarningAction::Skipped)],
                            $subtitle->getParseWarnings());

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The .sub packet at byte 14350 is longer than the content.");
        (new VobSubParser())->parse($cut, $options(false));
    }


    public function testLenientModeSkipsADamagedPacketAndReadsTheNextOnes(): void
    {
        $idx    = file_get_contents(self::DIR . "two-tracks-pal.idx");
        $sub    = file_get_contents(self::DIR . "two-tracks-pal.sub");
        preg_match_all("/filepos: ([0-9a-f]+)/", $idx, $matches);
        $sub[(int) hexdec($matches[1][1])] = "\xFF";

        $subtitle = (new VobSubParser())->parse($sub, new ReadOptions(lenient: true, formats: [new VobSubReadOptions($idx, track: 0)]));

        $expected = $this->parseFixture("two-tracks-pal", 0)->getCues();
        unset($expected[1]);
        $this->assertSame(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), array_values($expected)),
                          array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $subtitle->getCues()));
        $this->assertSame([1, ParseWarningAction::Skipped], [$subtitle->getParseWarnings()[0]->blockIndex, $subtitle->getParseWarnings()[0]->action]);
    }


    public function testTruncatedUnitThrows(): void
    {
        [$sub] = $this->makeSub([self::SMALL_UNIT]);
        $sub   = substr($sub, 0, -4);
        $idx   = self::IDX_HEADER . "id: en, index: 0\ntimestamp: 00:00:01:000, filepos: 000000000\n";

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The .sub packet at byte 16 is longer than the content.");

        (new VobSubParser())->parse($sub, new ReadOptions(formats: [new VobSubReadOptions($idx)]));
    }


    public function testContentWithoutStartCodeThrows(): void
    {
        $idx = self::IDX_HEADER . "id: en, index: 0\ntimestamp: 00:00:01:000, filepos: 000000000\n";

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The .sub content has no complete subtitle packet at filepos 000000000.");

        (new VobSubParser())->parse("1\n00:00:01,000 --> 00:00:02,000\nText\n", new ReadOptions(formats: [new VobSubReadOptions($idx)]));
    }
}
