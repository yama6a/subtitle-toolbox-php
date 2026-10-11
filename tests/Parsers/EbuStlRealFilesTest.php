<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\EbuStlReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\RealFiles;

class EbuStlRealFilesTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/stl/real/";


    private static function realFilesDir(): string
    {
        return "stl/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::EbuStl;
    }


    public static function realFileProvider(): array
    {
        return [
            "astisub_opn"           => ["astisub_opn.stl", 5, 0.0, 0.32, "Title: Test-OPN\nStory:\nLang: opn\nConfig: 1", Alignment::BottomLeft,
                                        72.16, 77.36, "<i>TEST ÆæØøÅå</i>\n<i>#123&amp;\"@!?`++?'*\$£-</i>", Alignment::BottomCenter],
            "bakery_teletext_25fps" => ["bakery_teletext_25fps.stl", 6, 36001.0, 36003.48, "The ovens are warm.\nBread is ready at six.", Alignment::BottomCenter,
                                        36030.96, 36033.0, "Good night.", Alignment::BottomCenter],
            "harbour_open_30fps"    => ["harbour_open_30fps.stl", 5, 1.5, 6.0, "Le vent tourne au nord.", Alignment::BottomCenter,
                                        60.0, 62.967, "Le port ferme à minuit.\n<i>Fin</i>", Alignment::BottomCenter],
            "library_hebrew"        => ["library_hebrew.stl", 3, 1.0, 3.0, "הספרייה פתוחה עד שמונה.", Alignment::BottomRight, 6.4, 9.0, "תודה ולהתראות.", Alignment::BottomRight],
            "market_arabic"         => ["market_arabic.stl", 2, 1.0, 3.0, "السوق مفتوح اليوم.", Alignment::BottomRight, 3.4, 6.0, "الخبز طازج؟\nنعم، من الفرن.", Alignment::BottomRight],
            "train_cyrillic"        => ["train_cyrillic.stl", 2, 2.0, 4.0, "Поезд отправляется в семь часов.", Alignment::BottomCenter,
                                        5.0, 8.0, "Следующая станция:\n<font color=\"#00ff00\">Северный вокзал.</font>", Alignment::BottomCenter],
            "weather_greek"         => ["weather_greek.stl", 3, 1.0, 3.0, "Αύριο θα βρέξει.", Alignment::BottomCenter, 6.4, 9.0, "Ο άνεμος είναι ασθενής.", Alignment::BottomCenter],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        int $cueCount,
        float $firstStart,
        float $firstEnd,
        string $firstText,
        Alignment $firstAlignment,
        float $lastStart,
        float $lastEnd,
        string $lastText,
        Alignment $lastAlignment
    ): void {
        $cues = array_values($this->parseFile($file)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText, $firstAlignment], $this->describeCue($cues[0]));
        $this->assertSame([$lastStart, $lastEnd, $lastText, $lastAlignment], $this->describeCue(end($cues)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripsByteForByte(string $file): void
    {
        $raw = file_get_contents(self::DIR . $file);

        $this->assertSame(bin2hex($raw), bin2hex(Subtitle::fromString($raw, Format::EbuStl)->toString(Format::EbuStl)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileKeepsCuesWhenTheFormatterEncodesTheTextAgain(string $file): void
    {
        $subtitle = $this->parseFile($file);
        foreach ($subtitle->getCues() as $cue) {
            $cue->setFormatData("stl", ["blocks" => []] + $cue->findFormatData("stl"));
        }

        $reparsed = Subtitle::fromString($subtitle->toString(Format::EbuStl), Format::EbuStl);

        $this->assertSame(array_map($this->describeCue(...), $subtitle->getCues()), array_map($this->describeCue(...), $reparsed->getCues()));
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
    }


    public function testRealFileMetadataAndGsiFields(): void
    {
        $subtitle = $this->parseFile("harbour_open_30fps.stl");
        $gsi      = $subtitle->findFormatData("stl")["gsi"];

        $this->assertSame(["title" => "Météo du port", "language" => "fr"], $subtitle->getAllMetadata());
        $this->assertSame(["850", "STL30.01", "0", "00", "002", "15", "FRA"], [
            $gsi["CPN"], $gsi["DFC"], $gsi["DSC"], $gsi["CCT"], $gsi["TNG"], $gsi["MNR"], $gsi["CO"],
        ]);
    }


    public function testRealFileKeepsPositionGroupAndCumulativeStatus(): void
    {
        $cues = array_values($this->parseFile("harbour_open_30fps.stl")->getCues());
        $keys = array_flip(["subtitleGroupNumber", "cumulativeStatus", "verticalPosition", "justificationCode"]);

        $this->assertSame([Alignment::TopCenter, Alignment::MiddleLeft, Alignment::BottomRight], [$cues[1]->getAlignment(), $cues[2]->getAlignment(), $cues[3]->getAlignment()]);
        $this->assertSame(
            ["subtitleGroupNumber" => 0, "cumulativeStatus" => 3, "verticalPosition" => 7, "justificationCode" => 1],
            array_intersect_key($cues[2]->findFormatData("stl"), $keys)
        );
        $this->assertSame("<u>Les bateaux</u>\n<u>restent au port.</u>", $cues[2]->getText());
        $this->assertSame(1, $cues[3]->findFormatData("stl")["subtitleGroupNumber"]);
    }


    public function testRealFileTeletextCodesExtensionBlocksCommentsAndUserData(): void
    {
        $subtitle = $this->parseFile("bakery_teletext_25fps.stl");
        $cues     = array_values($subtitle->getCues());

        $this->assertSame("<font color=\"#ff0000\">Fresh</font> rolls are <i>warm</i> today.", $cues[1]->getText());
        $this->assertSame("Café <i>crème</i> $3 or £2\n<u>Äpfel</u> und Straße", $cues[2]->getText());
        $this->assertSame(Alignment::TopLeft, $cues[2]->getAlignment());
        $this->assertSame("Sign: \u{2018}Open\u{2019} \u{00A4}", $cues[3]->getText());
        $this->assertSame(Alignment::MiddleRight, $cues[3]->getAlignment());
        $this->assertCount(2, $cues[3]->findFormatData("stl")["blocks"]);
        $this->assertCount(4, $cues[4]->getLines());
        $this->assertSame("<font color=\"#ffff00\">Rye bread and white bread are baked</font>", $cues[4]->getLines()[2]);
        $this->assertEquals([new Comment("Check the price list before air.", 3)], $subtitle->getComments());
    }


    public function testRealFileStartOfProgrammeCanBeSubtracted(): void
    {
        $raw      = file_get_contents(self::DIR . "bakery_teletext_25fps.stl");
        $subtitle = (new EbuStlParser())->parse($raw, new ReadOptions(formats: [new EbuStlReadOptions(subtractStartOfProgramme: true)]));
        $cues     = array_values($subtitle->getCues());

        $this->assertSame([1.0, 3.48], [$cues[0]->getStart(), $cues[0]->getEnd()]);
        $this->assertSame([30.96, 33.0], [$cues[5]->getStart(), $cues[5]->getEnd()]);
        $this->assertSame(bin2hex($raw), bin2hex($subtitle->toString(Format::EbuStl)));
    }
}
