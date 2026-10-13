<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\RealFiles;

class Cavena890RealFilesTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/cavena890/real/";


    private static function realFilesDir(): string
    {
        return "cavena890/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Cavena890;
    }


    public static function realFileProvider(): array
    {
        return [
            "English"      => ["harbour_english.890", "latin", 4, 1.0, 4.0, "The ferry leaves at nine.\nTickets are at the north gate.", Alignment::BottomCenter,
                               13.0, 16.0, "Hold on, it is cold.\nWait for the signal.\nReady?", Alignment::BottomCenter],
            "Danish"       => ["ferry_danish.890", "scandinavian", 2, 1.0, 4.0, "Færgen sejler klokken ni.", Alignment::BottomCenter,
                               5.0, 8.0, "Øl og smørrebrød på dækket.\nÅen er stille i aften.", Alignment::BottomCenter],
            "Hebrew"       => ["library_hebrew.890", "hebrew", 2, 1.0, 4.0, "הספרייה פתוחה עד שמונה.\nתודה ולהתראות!", Alignment::BottomCenter,
                               5.0, 8.0, "קומה 3, חדר 12", Alignment::BottomCenter],
            "Russian"      => ["train_russian.890", "russian", 2, 1.0, 4.0, "Поезд отправляется в семь.", Alignment::BottomCenter,
                               5.0, 8.0, "Следующая остановка: Северный вокзал.", Alignment::BottomCenter],
            "Greek"        => ["weather_greek.890", "greek", 2, 1.0, 4.0, "Αύριο θα βρέξει.", Alignment::BottomCenter,
                               5.0, 8.0, "Ο άνεμος είναι ασθενής.", Alignment::BottomCenter],
            "Arabic"       => ["market_arabic.890", "arabic", 1, 1.0, 4.0, "السوق مفتوح اليوم.\nالخبز طازج؟", Alignment::BottomCenter,
                               1.0, 4.0, "السوق مفتوح اليوم.\nالخبز طازج؟", Alignment::BottomCenter],
            "Chinese"      => ["noodle_chinese.890", "chinese", 2, 1.0, 4.0, "面包店七点开门。", Alignment::BottomCenter,
                               5.0, 8.0, "<i>面包</i>还是热的。\n好吃。", Alignment::BottomCenter],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        string $variant,
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
        $subtitle = $this->parseFile($file);
        $cues     = array_values($subtitle->getCues());

        $this->assertSame($variant, $subtitle->findFormatData("cavena890")["variant"]);
        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText, $firstAlignment], $this->describeCue($cues[0]));
        $this->assertSame([$lastStart, $lastEnd, $lastText, $lastAlignment], $this->describeCue(end($cues)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileLoadsByItsExtensionAndRoundTripsByteForByte(string $file): void
    {
        $raw = file_get_contents(self::DIR . $file);

        $this->assertNull(Format::detect($raw));
        $this->assertSame(bin2hex($raw), bin2hex(Subtitle::load(self::DIR . $file)->toString(Format::Cavena890)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileComesOutTheSameWhenTheFormatterEncodesTheTextAgain(string $file): void
    {
        $raw      = file_get_contents(self::DIR . $file);
        $subtitle = Subtitle::fromString($raw, Format::Cavena890);
        foreach ($subtitle->getCues() as $cue) {
            $cue->setFormatData("cavena890", []);
        }

        $this->assertSame(bin2hex($raw), bin2hex($subtitle->toString(Format::Cavena890)));
    }


    public function testRealFileItalicsAndAlignment(): void
    {
        $cues = array_values($this->parseFile("harbour_english.890")->getCues());

        $this->assertSame([Alignment::BottomLeft, "<i>Café crème, Grüße aus Köln.</i>"], [$cues[1]->getAlignment(), $cues[1]->getText()]);
        $this->assertSame([Alignment::BottomRight, "Mind the step, <i>señor</i>."], [$cues[2]->getAlignment(), $cues[2]->getText()]);
        $this->assertCount(2, $cues[3]->findFormatData("cavena890")["blocks"]);
    }
}
