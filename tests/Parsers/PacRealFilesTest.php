<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Alignment;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\RealFiles;

class PacRealFilesTest extends TestCase
{
    use RealFiles;


    private const DIR = __DIR__ . "/../files/pac/real/";


    private static function realFilesDir(): string
    {
        return "pac/real/";
    }


    private static function realFilesFormat(): Format
    {
        return Format::Pac;
    }


    public static function realFileProvider(): array
    {
        return [
            "Latin"               => ["bakery_latin.pac", "latin", 5, 1.0, 3.48, "The ovens are warm.\nBread is ready at six.", Alignment::BottomCenter,
                                      3600.0, 3602.96, "#1 [rye] Good night.", Alignment::BottomCenter],
            "30 fps"              => ["harbour_30fps.pac", "latin", 2, 1.9, 3.967, "Ships leave at dawn.", Alignment::BottomCenter,
                                      5.5, 7.0, "The harbour closes at ten.", Alignment::BottomCenter],
            "Turkish"             => ["bazaar_turkish.pac", "latin-turkish", 2, 1.0, 3.0, "Çarşı saat dokuzda açılıyor.", Alignment::BottomCenter,
                                      4.0, 6.0, "Ağaç gölgesinde çay içelim.\nIğdır, İzmir ve Muğla", Alignment::BottomCenter],
            "Greek"               => ["weather_greek.pac", "greek", 3, 1.0, 3.0, "Αύριο θα βρέξει.", Alignment::BottomCenter,
                                      6.4, 9.0, "Η θερμοκρασία πέφτει\nστους 12 βαθμούς.", Alignment::BottomCenter],
            "Cyrillic"            => ["train_cyrillic.pac", "cyrillic", 3, 2.0, 4.0, "Поезд отправляется в семь часов.", Alignment::BottomCenter,
                                      9.0, 11.0, "Ещё чай, пожалуйста?", Alignment::BottomCenter],
            "Arabic"              => ["market_arabic.pac", "arabic", 2, 1.0, 3.0, "السوق مفتوح اليوم.\nالخبز طازج؟", Alignment::BottomRight,
                                      3.4, 6.0, "نعم، من الفرن 24 ساعة.", Alignment::BottomRight],
            "Hebrew"              => ["library_hebrew.pac", "hebrew", 2, 1.0, 3.0, "הספרייה פתוחה עד שמונה.\nתודה ולהתראות.", Alignment::BottomRight,
                                      6.4, 9.0, "קומה 3, חדר abc", Alignment::BottomRight],
            "Thai"                => ["temple_thai.pac", "thai", 2, 1.0, 3.0, "วัดเปิดตอนเจ็ดโมง", Alignment::BottomCenter,
                                      4.0, 6.0, "ขอบคุณครับ\nห้องสมุดปิดแล้ว", Alignment::BottomCenter],
            "Chinese simplified"  => ["noodle_chinese_simplified.pac", "chinese-simplified", 2, 1.0, 3.0, "面包店七点开门。", Alignment::BottomCenter,
                                      4.0, 6.0, "面包还是热的。\nOK", Alignment::BottomCenter],
            "Chinese traditional" => ["noodle_chinese_traditional.pac", "chinese-traditional", 2, 1.0, 3.0, "麵包店七點開門。", Alignment::BottomCenter,
                                      4.0, 6.0, "麵包還是熱的。", Alignment::BottomCenter],
            "Korean"              => ["cafe_korean.pac", "korean", 2, 1.0, 3.0, "빵집은 일곱 시에 문을 엽니다.", Alignment::BottomCenter,
                                      4.0, 6.0, "빵이 아직 따뜻해요.", Alignment::BottomCenter],
            "Japanese"            => ["station_japanese.pac", "japanese", 2, 1.0, 3.0, "パン屋は七時に開きます。", Alignment::BottomCenter,
                                      4.0, 6.0, "パンはまだ温かいです。", Alignment::BottomCenter],
            "Unicode"             => ["bakery_unicode.fpc", "unicode", 2, 1.0, 3.0, "Grüße aus Køge.\n東京 \u{2013} Αθήνα", Alignment::BottomCenter,
                                      4.0, 6.0, "<i>Fresh bread.</i>", Alignment::BottomLeft],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        string $codePage,
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

        $this->assertSame($codePage, $subtitle->findFormatData("pac")["codePage"]);
        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText, $firstAlignment], $this->describeCue($cues[0]));
        $this->assertSame([$lastStart, $lastEnd, $lastText, $lastAlignment], $this->describeCue(end($cues)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileIsDetectedAndRoundTripsByteForByte(string $file): void
    {
        $raw = file_get_contents(self::DIR . $file);

        $this->assertSame(Format::Pac, Format::detect($raw));
        $this->assertSame(bin2hex($raw), bin2hex(Subtitle::fromString($raw)->toString(Format::Pac)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileComesOutTheSameWhenTheFormatterEncodesTheTextAgain(string $file): void
    {
        $raw      = file_get_contents(self::DIR . $file);
        $subtitle = Subtitle::fromString($raw, Format::Pac);
        foreach ($subtitle->getCues() as $cue) {
            $cue->setFormatData("pac", []);
        }

        $this->assertSame(bin2hex($raw), bin2hex($subtitle->toString(Format::Pac)));
    }


    public function testRealFileItalicsAndAlignment(): void
    {
        $cues = array_values($this->parseFile("bakery_latin.pac")->getCues());

        $this->assertSame("Fresh rolls are <i>warm</i> today.", $cues[1]->getText());
        $this->assertSame("<i>Café crème £3 or §2</i>\nÄpfel, Straße und Køge \u{2013} 5 €", $cues[2]->getText());
        $this->assertSame(Alignment::TopLeft, $cues[2]->getAlignment());
        $this->assertSame(Alignment::MiddleRight, $cues[3]->getAlignment());
    }


    public function testRealFileKeepsTheStoryRecordOutOfTheCues(): void
    {
        $data = $this->parseFile("bakery_latin.pac")->findFormatData("pac");

        $this->assertSame(1, $data["firstNumber"]);
        $this->assertStringContainsString(bin2hex("STORY:Bakery News"), $data["header"]);
    }


    public function testEncodingNamesTheCodePage(): void
    {
        $raw = file_get_contents(self::DIR . "weather_greek.pac");

        $subtitle = Subtitle::fromString($raw, null, new ReadOptions(encoding: "ISO-8859-5"));

        $this->assertSame("cyrillic", $subtitle->findFormatData("pac")["codePage"]);
        $this->assertSame("Фѓqшщ рф иqутушю", $subtitle->getCues()[0]->getText());
        $this->assertSame(bin2hex($raw), bin2hex($subtitle->toString(Format::Pac)));
    }
}
