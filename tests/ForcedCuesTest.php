<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Diff\CueDifferenceKind;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\FakeOcrEngine;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\ReadOptions;

require_once __DIR__ . "/Ocr/FakeOcrEngine.php";

class ForcedCuesTest extends TestCase
{
    private const DIR = __DIR__ . "/files/forced/";

    private const SRT = "1\n00:00:01,000 --> 00:00:02,000\nHello\n\n2\n00:00:03,000 --> 00:00:04,000\nEXIT\n\n" .
                        "3\n00:00:05,000 --> 00:00:06,000\nBye\n";


    private function forcedFlags(Subtitle $subtitle): array
    {
        return array_values(array_map(fn (SubtitleCue $cue): bool => $cue->isForced(), $subtitle->getCues()));
    }


    private function srtWithForcedSecondCue(): Subtitle
    {
        $subtitle = Subtitle::fromString(self::SRT, Format::SubRip);
        $subtitle->getCues()[1]->setForced(true);

        return $subtitle;
    }


    public function testCueIsNotForcedByDefault(): void
    {
        $cue = new SubtitleCue(1, 2, "Hello");

        $this->assertFalse($cue->isForced());
        $this->assertTrue($cue->setForced(true)->isForced());
        $this->assertFalse($cue->setForced(false)->isForced());
    }


    public function testToArrayWritesForcedOnlyForForcedCues(): void
    {
        $cues = $this->srtWithForcedSecondCue()->toArray()["cues"];

        $this->assertSame(["start", "end", "lines", "identifier", "alignment", "formatData"], array_keys($cues[0]));
        $this->assertSame(["start", "end", "lines", "identifier", "alignment", "forced", "formatData"], array_keys($cues[1]));
        $this->assertTrue($cues[1]["forced"]);
        $this->assertSame(["start", "end", "lines", "identifier", "alignment"], array_keys($this->srtWithForcedSecondCue()->toArray(false)["cues"][0]));
        $this->assertTrue($this->srtWithForcedSecondCue()->toArray(false)["cues"][1]["forced"]);
    }


    public function testJsonKeepsTheFlagAndVersionOne(): void
    {
        $json = $this->srtWithForcedSecondCue()->toString(Format::Json);

        $this->assertStringStartsWith('{"version":1,', $json);
        $this->assertSame(1, substr_count($json, '"forced":true'));
        $this->assertStringContainsString('"alignment":null,"forced":true,"formatData"', $json);
        $this->assertSame([false, true, false], $this->forcedFlags(Subtitle::fromString($json, Format::Json)));
        $this->assertStringNotContainsString("forced", Subtitle::fromString(self::SRT, Format::SubRip)->toString(Format::Json));
    }


    public function testFromArrayReadsTheFlag(): void
    {
        $subtitle = Subtitle::fromArray(["version" => 1, "cues" => [
            ["start" => 1, "end" => 2, "lines" => ["A"], "forced" => true],
            ["start" => 3, "end" => 4, "lines" => ["B"], "forced" => false],
            ["start" => 5, "end" => 6, "lines" => ["C"]],
        ]]);

        $this->assertSame([true, false, false], $this->forcedFlags($subtitle));
    }


    public function testFromArrayRejectsAFlagThatIsNotABoolean(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field cues[0].forced must be a boolean.");

        Subtitle::fromArray(["version" => 1, "cues" => [["start" => 1, "end" => 2, "lines" => [], "forced" => "true"]]]);
    }


    public function testForcedOnlyReturnsACopyWithTheForcedCues(): void
    {
        $subtitle = $this->srtWithForcedSecondCue();
        $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, "en");
        $subtitle->addComment("before cue 1", 0);
        $subtitle->addComment("before cue 2", 1);
        $subtitle->addComment("at the end", 3);

        $forced = $subtitle->withForcedCuesOnly();

        $this->assertCount(1, $forced->getCues());
        $this->assertSame([3.0, 4.0, "EXIT", true], [$forced->getCues()[0]->getStart(), $forced->getCues()[0]->getEnd(),
                                                     $forced->getCues()[0]->getText(), $forced->getCues()[0]->isForced()]);
        $this->assertNotSame($subtitle->getCues()[1], $forced->getCues()[0]);
        $this->assertEquals([new Comment("before cue 2", 0)], $forced->getComments());
        $this->assertSame("en", $forced->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertCount(3, $subtitle->getCues());
        $this->assertCount(3, $subtitle->getComments());
        $this->assertSame([], (new Subtitle())->withForcedCuesOnly()->getCues());
    }


    public function testForcedOnlyKeepsTheLastCommentWhenTheLastCueIsForced(): void
    {
        $subtitle = Subtitle::fromString(self::SRT, Format::SubRip);
        $subtitle->getCues()[2]->setForced(true);
        $subtitle->addComment("at the end", 3);

        $this->assertEquals([new Comment("at the end", 1)], $subtitle->withForcedCuesOnly()->getComments());
    }


    public function testCueImageSetsTheFlag(): void
    {
        $image = new CueImage("png", 0, 0, 1, 1, 10, 10, true);

        $this->assertTrue($image->toCue(new SubtitleCue(1, 2))->isForced());
        $this->assertFalse((new CueImage("png", 0, 0, 1, 1, 10, 10))->toCue((new SubtitleCue(1, 2))->setForced(true))->isForced());
    }


    public function testPgsCuesCarryTheFlagThroughOcr(): void
    {
        $subtitle = (new PgsParser())->parse(file_get_contents(__DIR__ . "/files/pgs/shapes_1080p.sup"), new ReadOptions());
        $this->assertSame([false, false, true, true, false, false], $this->forcedFlags($subtitle));

        $subtitle->recognizeText(new FakeOcrEngine(["Platform 4"]), "eng");

        $this->assertSame([false, false, true, true, false, false], $this->forcedFlags($subtitle));
        $this->assertSame([8.0, 9.5, "Platform 4"], [$subtitle->getCues()[2]->getStart(), $subtitle->getCues()[2]->getEnd(),
                                                    $subtitle->getCues()[2]->getText()]);
        $this->assertCount(2, $subtitle->withForcedCuesOnly()->getCues());
        $this->assertStringStartsWith("1\n00:00:08,000 --> 00:00:09,500\n{\\an8}Platform 4\n\n",
                                      StringHelpers::removeUtf8Bom($subtitle->withForcedCuesOnly()->toString(Format::SubRip)));
    }


    public function testVobSubCuesCarryTheFlagThroughOcr(): void
    {
        $dir      = __DIR__ . "/files/vobsub/";
        $subtitle = (new VobSubParser())->parse(file_get_contents($dir . "two-tracks-pal.sub"), new ReadOptions(formats: [new VobSubReadOptions(file_get_contents($dir . "two-tracks-pal.idx"))]));
        $this->assertSame([false, true, false, false, false], $this->forcedFlags($subtitle));

        $subtitle->recognizeText(new FakeOcrEngine(["Exit"]), "eng");

        $this->assertSame([false, true, false, false, false], $this->forcedFlags($subtitle));
    }


    public function testDiffReportsAChangedFlagAsATextChange(): void
    {
        $old = Subtitle::fromString(self::SRT, Format::SubRip);
        $new = $this->srtWithForcedSecondCue();

        $differences = SubtitleDiff::compare($old, $new);

        $this->assertCount(1, $differences);
        $this->assertSame([CueDifferenceKind::TextChanged, 1, 1],
                          [$differences[0]->kind, $differences[0]->oldIndex, $differences[0]->newIndex]);
        $this->assertSame("text changed: old cue 2, new cue 2\n" .
                          "- 00:00:03.000 --> 00:00:04.000\n  EXIT\n" .
                          "+ 00:00:03.000 --> 00:00:04.000 forced\n  EXIT\n",
                          SubtitleDiff::toText($differences));
        $this->assertTrue(SubtitleDiff::isEqual($new, $this->srtWithForcedSecondCue()));
    }


    public static function realFileProvider(): array
    {
        return [
            "w3c_imsc11_forced"   => ["w3c_imsc11_forced.ttml", Format::Ttml,
                                      [1.0, 6.0, "Lycée"], [4.0, 6.0, "Nous étions inscrits au même lycée."], [true, false]],
            "forced_inheritance"  => ["forced_inheritance.ttml", Format::Ttml,
                                      [1.0, 3.0, "Wir gehen zum Hafen."], [10.0, 12.0, "Der Zug fährt um acht."],
                                      [false, true, true, true, false]],
            "forced_signs_2398"   => ["forced_signs_2398.itt", Format::Itt,
                                      [2.002, 4.505, "SECTOR 7 AHEAD"], [17.184, 19.019, "<i>Mill Road 2 km</i>"],
                                      [true, false, false, true, false, true]],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(string $file, Format $format, array $first, array $last, array $flags): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), $format);
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(count($flags), $cues);
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($last, [end($cues)->getStart(), end($cues)->getEnd(), end($cues)->getText()]);
        $this->assertSame($flags, $this->forcedFlags($subtitle));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsTheFlags(string $file, Format $format, array $first, array $last, array $flags): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), $format);
        $output   = $subtitle->toString($format);
        $reparsed = Subtitle::fromString($output, $format);

        $this->assertSame($flags, $this->forcedFlags($reparsed));
        $this->assertSame(array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()),
                          array_map(fn (SubtitleCue $cue): string => $cue->getText(), $reparsed->getCues()));
        $this->assertSame($output, $reparsed->toString($format));
        $this->assertSame($flags, $this->forcedFlags(Subtitle::fromString($subtitle->toString(Format::Json), Format::Json)));
    }


    public function testTtmlFormatterKeepsTheFileWhenTheFlagComesFromTheRegion(): void
    {
        $output = Subtitle::fromString(file_get_contents(self::DIR . "w3c_imsc11_forced.ttml"), Format::Ttml)->toString(Format::Ttml);

        $this->assertSame(1, substr_count($output, "itts:forcedDisplay"));
        $this->assertStringContainsString('<p begin="00:00:01.000" end="00:00:06.000" region="r1">Lycée</p>', $output);
    }


    public function testTtmlFormatterWritesTheFlagOnTheParagraph(): void
    {
        $output = $this->srtWithForcedSecondCue()->toString(Format::Ttml);

        $this->assertStringContainsString(' xmlns:itts="http://www.w3.org/ns/ttml/profile/imsc1#styling"', $output);
        $this->assertStringContainsString('<p begin="00:00:03.000" end="00:00:04.000" region="bottomCenter" itts:forcedDisplay="true">EXIT</p>', $output);
        $this->assertSame(1, substr_count($output, "itts:forcedDisplay"));
        $this->assertStringNotContainsString("itts", Subtitle::fromString(self::SRT, Format::SubRip)->toString(Format::Ttml));
    }


    public function testTtmlFormatterWritesAClearedFlag(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "forced_inheritance.ttml"), Format::Ttml);
        $subtitle->getCues()[3]->setForced(false);
        $subtitle->getCues()[4]->setForced(true);

        $output = $subtitle->toString(Format::Ttml);

        $this->assertStringContainsString('<p begin="00:00:08.000" end="00:00:09.500" region="bottom" itts:forcedDisplay="false">BAHNHOF</p>', $output);
        $this->assertStringContainsString('<p begin="00:00:10.000" end="00:00:12.000" itts:forcedDisplay="true" region="bottom">Der Zug fährt um acht.</p>', $output);
        $this->assertSame([false, true, true, false, true], $this->forcedFlags(Subtitle::fromString($output, Format::Ttml)));
    }


    public function testIttFormatterWritesTheFlagOnTheParagraph(): void
    {
        $output = $this->srtWithForcedSecondCue()->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)]));

        $this->assertStringContainsString('xmlns:itts="http://www.w3.org/ns/ttml/profile/imsc1#styling"', $output);
        $this->assertStringContainsString('<p begin="00:00:03:00" end="00:00:04:00" region="bottom" itts:forcedDisplay="true">EXIT</p>', $output);
        $this->assertSame([false, true, false], $this->forcedFlags(Subtitle::fromString($output, Format::Itt)));
        $this->assertStringNotContainsString("itts", Subtitle::fromString(self::SRT, Format::SubRip)
                                                         ->toString(Format::Itt, new WriteOptions(formats: [new IttWriteOptions(frameRate: 25)])));
    }
}
