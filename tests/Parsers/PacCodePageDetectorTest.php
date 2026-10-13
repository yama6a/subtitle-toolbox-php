<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PacCodePageDetectorTest extends TestCase
{
    public static function texts(): array
    {
        return [
            "English"             => ["latin", ["Hello there.", "We open the shop at nine.", "Is the bread ready yet?"]],
            "English, short"      => ["latin", ["OK."]],
            "German"              => ["latin", ["Die Bäckerei öffnet um sieben Uhr.", "Möchten Sie Brötchen?"]],
            "French"              => ["latin", ["Le pain est prêt à huit heures.", "Ça coûte deux euros."]],
            "Spanish"             => ["latin", ["El panadero abre la tienda a las siete.", "¿Quieres pan con mantequilla?"]],
            "Danish"              => ["latin", ["Bageriet åbner klokken syv.", "Vil du have rugbrød?"]],
            "Polish"              => ["latin", ["Piekarnia jest otwarta od siódmej.", "Ile kosztuje bułka?"]],
            "Czech"               => ["latin", ["Pekárna otevírá v sedm hodin.", "Chléb je ještě teplý."]],
            "Italian"             => ["latin", ["Il fornaio apre alle sette.", "Quanto costa il cornetto?"]],
            "Turkish"             => ["latin-turkish", ["Fırın saat yedide açılıyor.", "Bir simit kaç lira? Ağaç dağ."]],
            "Greek"               => ["greek", ["Ο φούρνος ανοίγει στις επτά.", "Πόσο κοστίζει το κουλούρι;"]],
            "Greek, one word"     => ["greek", ["Καλημέρα."]],
            "Russian"             => ["cyrillic", ["Пекарня открывается в семь.", "Сколько стоит булочка?"]],
            "Russian, one word"   => ["cyrillic", ["Да."]],
            "Hebrew"              => ["hebrew", ["המאפייה נפתחת בשבע.", "הלחם עדיין חם."]],
            "Arabic"              => ["arabic", ["المخبز يفتح في السابعة.", "الخبز ما زال ساخنا."]],
            "Thai"                => ["thai", ["ร้านขนมปังเปิดเจ็ดโมง", "ขนมปังยังอุ่นอยู่"]],
            "Korean"              => ["korean", ["빵집은 일곱 시에 문을 엽니다.", "빵이 아직 따뜻해요."]],
            "Japanese"            => ["japanese", ["パン屋は七時に開きます。", "パンはまだ温かいです。"]],
            "Chinese simplified"  => ["chinese-simplified", ["面包店七点开门。", "面包还是热的。"]],
            "Chinese traditional" => ["chinese-traditional", ["麵包店七點開門。", "麵包還是熱的。"]],
        ];
    }


    #[DataProvider("texts")]
    public function testDetectsTheCodePageThatWroteTheText(string $codePage, array $lines): void
    {
        $runs = array_map(fn (string $line): array => Pac::lines("\xFE\x02\x03" . PacCodePage::from($codePage)->encodeLine($line))[0]["runs"], $lines);

        $this->assertSame([PacCodePage::from($codePage), null], PacCodePageDetector::detect($runs));
    }
}
