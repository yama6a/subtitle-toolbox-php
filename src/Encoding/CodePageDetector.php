<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

/**
 * Picks the code page of text that is not UTF-8 and has no BOM.
 * It decodes the bytes from 0x80 with each candidate and scores the result. Letters of a language that uses the
 * code page score, other letters, symbols, mixed scripts and odd letter case cost. The alphabets list the letters of
 * the standard spelling of each language. For Chinese and Japanese, they list about 700 of the most common Han
 * characters, and other Han characters neither score nor cost.
 *
 * @internal
 */
final class CodePageDetector
{
    // The order breaks ties. Windows-1252 decodes ISO-8859-1 text the same, so ISO-8859-1 never wins a tie.
    // CP932 and CP950 are the Windows forms of Shift_JIS and Big5. iconv decodes the byte 0x5C as a yen sign in Shift_JIS.
    private const CANDIDATES = [
        "Windows-1252" => ["fr", "de", "es", "pt", "it", "nl", "da", "sv", "fi", "is", "ca", "sq"],
        "Windows-1250" => ["cs", "sk", "pl", "hu", "sl", "hr", "ro", "sq", "de"],
        "Windows-1251" => ["ru", "uk", "be", "bg", "sr", "mk"],
        "Windows-1254" => ["tr"],
        "Windows-1253" => ["el"],
        "Windows-1256" => ["ar"],
        "Windows-1255" => ["he"],
        "Windows-1257" => ["lt", "lv", "et"],
        "Windows-1258" => ["vi"],
        "Windows-874"  => ["th"],
        "KOI8-R"       => ["ru", "bg"],
        "KOI8-U"       => ["uk", "ru", "be"],
        "ISO-8859-1"   => ["fr", "de", "es", "pt", "it", "nl", "da", "sv", "fi", "is", "ca", "sq"],
        "ISO-8859-2"   => ["cs", "sk", "pl", "hu", "sl", "hr", "ro", "sq", "de"],
        "ISO-8859-5"   => ["ru", "uk", "be", "bg", "sr", "mk"],
        "ISO-8859-6"   => ["ar"],
        "ISO-8859-7"   => ["el"],
        "ISO-8859-9"   => ["tr"],
        "CP949"        => ["ko"],
        "CP932"        => ["ja"],
        "EUC-JP"       => ["ja"],
        "GBK"          => ["zh-Hans"],
        "CP950"        => ["zh-Hant"],
    ];

    private const MULTI_BYTE = ["CP949", "CP932", "EUC-JP", "GBK", "CP950"];

    // Han characters that are common in Simplified Chinese text, and their Traditional Chinese forms. The Traditional forms come
    // from STCharacters.txt and TWVariants.txt of OpenCC, Apache-2.0, see THIRD_PARTY_NOTICES.md.
    private const HAN_SIMPLIFIED = "的一是不了在人有我他这个们中来上大为和国地到以说时要就出会可也你对生能而子那得于着下自之年过发后作里用道行所然家种事成方" .
                                   "多经么去法学如都同现当没动面起看定天分还进好小部其些主样理心她本前开但因只从想实日军者意无力它与长把机十民第公此已工使情" .
                                   "明性知全三又关点正业外将两高间由问很最重并物手应战向头文体政美相见被利什二等产或新己制身果加西斯月话合回特代内信表化老给" .
                                   "世位次度门任常先海通教儿原东声提立及比员解水名真论处走义各入几口认条平系气题活尔更别打女变四神总何电数安少报才结反受目太" .
                                   "量再感建务做接必场件计管期市直德资命山金指克许统区保至队形社便空决治展马科司五基眼书非则听白却界达光放强即像难且权思王象" .
                                   "完设式色路记南品住告类求据程北边死张该交规万取拉格望觉术领共确传师观清今切院让识候带导争运笑飞风步改收根干造言联持组每济" .
                                   "车亲极林服快办议往元英士证近失转夫令准布始怎呢存未远叫台单影具罗字爱击流备兵连调深商算质团集百需价花党华城石级整府离况亚" .
                                   "请技际约示复病息究线似官火断精满支视消越器容照须九增研写称企八功吗包片史委乎查轻易早曾除农找装广显吧阿李标谈吃图念六引历" .
                                   "首医局突专费号尽另周较注语仅考落青随选列武红响虽推势参希古众构房半节土投某案黑维革划敢讨啊呀嘛哦哪谁您俺咱喂嗯哈哎唉呗啦" .
                                   "咯嘿跟帮够怕忘睡醒哭喝玩买卖送借拿穿脱洗跑跳坐站躺晚昨午夜末星钟秒刻钱块毛票卡街窗桌椅床屋楼店饭菜米肉鱼鸡蛋汤茶酒咖啡奶" .
                                   "糖冰热冷累饿渴疼药校朋友哥姐弟妹爸妈爷孩宝贝叔姨警察律客板班休假钥匙伞脑邮闻故麻烦危险幸福乐兴害紧担喜欢厌恨懂诉答释忙谢" .
                                   "迎";

    private const HAN_TRADITIONAL = "的一是不了瞭在人有我他這個們中來上大為和國地到以說時要就出會可也你對生能而子那得於著下自之年過發髮後后作裡里用道行所然家" .
                                    "種事成方多經麼去法學如都同現當沒動面麵起看定天分還進好小部其些主樣理心她本前開但因只隻從想實日軍者意無力它與長把機十民第" .
                                    "公此已工使情明性知全三又關點正業外將兩高間由問很最重並物手應戰向頭文體政美相見被利什二等產或新己制製身果加西斯月話合回特" .
                                    "代內信表錶化老給世位次度門任常先海通教兒原東聲提立及比員解水名真論處走義各入幾口認條平系係繫氣題活爾更別打女變四神總何電" .
                                    "數安少報才結反受目太量再感建務做接必場件計管期市直德資命山金指克許統區保至隊形社便空決治展馬科司五基眼書非則聽白卻界達光" .
                                    "放強即像難且權思王象完設式色路記南品住告類求據程北邊死張該交規萬取拉格望覺術領共確傳師觀清今切院讓識候帶導爭運笑飛風步改" .
                                    "收根幹乾干造言聯持組每濟車親極林服快辦議往元英士證近失轉夫令準布佈始怎呢存未遠叫臺颱台單影具俱羅字愛擊流備兵連調深商算質" .
                                    "團集百需價花黨華城石級整府離況亞請技際約示復複覆病息究線似官火斷精滿支視消越器容照須九增研寫稱企八功嗎包片史委乎查輕易早" .
                                    "曾除農找裝廣顯吧阿李標談吃圖念唸六引歷曆首醫局突專費號盡儘另周週較注註語僅考落青隨選列武紅響雖推勢參希古眾構房半節土投某" .
                                    "案黑維革劃敢討啊呀嘛哦哪誰您俺咱喂餵嗯哈哎唉唄啦咯嘿跟幫夠怕忘睡醒哭喝玩買賣送借藉拿穿脫洗跑跳坐座站躺晚昨午夜末星鐘秒刻" .
                                    "錢塊毛票卡街窗桌椅床屋樓店飯菜米肉魚雞蛋湯茶酒咖啡奶糖冰熱冷累餓渴疼藥校朋友哥姐弟妹爸媽爺孩寶貝叔姨警察律客板闆班休假鑰" .
                                    "匙傘腦郵聞故麻煩危險幸福樂興害緊擔喜歡厭恨懂訴答釋忙謝迎";

    private const ALPHABETS = [
        "fr" => "àâæçéèêëîïôœùûüÿÀÂÆÇÉÈÊËÎÏÔŒÙÛÜŸ",
        "de" => "äöüßÄÖÜ",
        "es" => "áéíñóúüÁÉÍÑÓÚÜ",
        "pt" => "áâãàçéêíóôõúÁÂÃÀÇÉÊÍÓÔÕÚ",
        "it" => "àèéìíîòóùúÀÈÉÌÍÎÒÓÙÚ",
        "nl" => "áéèëíïóöúüÁÉÈËÍÏÓÖÚÜ",
        "da" => "æøåéÆØÅÉ",
        "sv" => "åäöéÅÄÖÉ",
        "fi" => "äöåšžÄÖÅŠŽ",
        "is" => "áðéíóúýþæöÁÐÉÍÓÚÝÞÆÖ",
        "ca" => "àçéèíïòóúüÀÇÉÈÍÏÒÓÚÜ",
        "sq" => "çëÇË",
        "cs" => "áčďéěíňóřšťúůýžÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ",
        "sk" => "áäčďéíĺľňóôŕšťúýžÁÄČĎÉÍĹĽŇÓÔŔŠŤÚÝŽ",
        "pl" => "ąćęłńóśźżĄĆĘŁŃÓŚŹŻ",
        "hu" => "áéíóöőúüűÁÉÍÓÖŐÚÜŰ",
        "sl" => "čšžČŠŽ",
        "hr" => "čćđšžČĆĐŠŽ",
        "ro" => "ăâîșşțţĂÂÎȘŞȚŢ",
        "tr" => "çğıöşüâîûÇĞÖŞÜÂÎÛİ",
        "lt" => "ąčęėįšųūžĄČĘĖĮŠŲŪŽ",
        "lv" => "āčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽ",
        "et" => "äõöüšžÄÕÖÜŠŽ",
        "vi" => "àáâãèéêìíòóôõùúýăđĩũơưạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹÀÁÂÃÈÉÊÌÍÒÓÔÕÙÚÝĂĐĨŨƠƯẠẢẤẦẨẪẬẮẰẲẴẶẸẺẼẾỀỂỄỆỈỊỌỎỐỒỔỖỘỚỜỞỠỢỤỦỨỪỬỮỰỲỴỶỸ",
        "el" => "αβγδεζηθικλμνξοπρσςτυφχψωάέήίόύώϊϋΐΰΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩΆΈΉΊΌΎΏΪΫ",
        "ru" => "абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ",
        "uk" => "абвгґдеєжзиіїйклмнопрстуфхцчшщьюяАБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ",
        "be" => "абвгдеёжзійклмнопрстуўфхцчшыьэюяАБВГДЕЁЖЗІЙКЛМНОПРСТУЎФХЦЧШЫЬЭЮЯ",
        "bg" => "абвгдежзийклмнопрстуфхцчшщъьюяАБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЬЮЯ",
        "sr" => "абвгдђежзијклљмнњопрстћуфхцчџшАБВГДЂЕЖЗИЈКЛЉМНЊОПРСТЋУФХЦЧЏШ",
        "mk" => "абвгдѓежзѕијклљмнњопрстќуфхцчџшАБВГДЃЕЖЗЅИЈКЛЉМНЊОПРСТЌУФХЦЧЏШ",
        "zh-Hans" => self::HAN_SIMPLIFIED,
        "zh-Hant" => self::HAN_TRADITIONAL,
        "ja"      => self::HAN_SIMPLIFIED . self::HAN_TRADITIONAL,
    ];

    // Letters of these scripts that the alphabet of the language does not have are rare, not foreign, and add 0.
    private const RARE_LETTERS = ["zh-Hans" => '/\p{Han}/u', "zh-Hant" => '/\p{Han}/u', "ja" => '/\p{Han}/u', "ko" => '/\p{Hangul}/u'];

    // The Korean alphabet is the 2,350 common syllables of KS X 1001, which EUC-KR encodes. Other syllables are rare.
    private const KOREAN_SYLLABLES = [0xAC00, 0xD7A3];

    /** Unicode ranges of the Arabic, Hebrew and Thai letters, vowel marks and tone marks, and the Japanese kana. */
    private const RANGES = [
        "ar" => [[0x0621, 0x063A], [0x0640, 0x0652], [0x0679, 0x0679], [0x067E, 0x067E], [0x0686, 0x0686], [0x0688, 0x0688],
                 [0x0691, 0x0691], [0x0698, 0x0698], [0x06A9, 0x06A9], [0x06AF, 0x06AF], [0x06BA, 0x06BA], [0x06BE, 0x06BE],
                 [0x06C1, 0x06C1], [0x06CC, 0x06CC], [0x06D2, 0x06D2]],
        "he" => [[0x05B0, 0x05C7], [0x05D0, 0x05EA]],
        "th" => [[0x0E01, 0x0E3A], [0x0E40, 0x0E5B]],
        "ja" => [[0x3005, 0x3005], [0x3041, 0x3096], [0x309D, 0x309E], [0x30A1, 0x30FA], [0x30FC, 0x30FE]],
    ];

    private const PUNCTUATION = "\u{00A0}\u{2018}\u{2019}\u{201A}\u{201C}\u{201D}\u{201E}\u{2013}\u{2014}\u{2026}\u{00AB}\u{00BB}" .
                                "\u{2039}\u{203A}\u{00BF}\u{00A1}\u{00B0}\u{00B7}\u{20AC}\u{00A3}\u{00A7}\u{00A9}\u{00AE}\u{2122}" .
                                "\u{2022}\u{00B4}\u{00D7}\u{00B1}\u{00BD}\u{00BC}\u{00BE}\u{2116}\u{00A2}\u{00A5}\u{00B9}\u{00B2}" .
                                "\u{00B3}\u{00B6}\u{266A}\u{2027}\u{3000}、。〃〈〉《》「」『』【】〔〕〜・！＂＃％＆＇（）＊＋，－．／：；＜＝＞？＠［］＿｛｝～" .
                                "０１２３４５６７８９";

    // CJK text has no spaces between words, so its punctuation sits between letters.
    private const CJK_PUNCTUATION = '\x{3000}-\x{303F}\x{FF00}-\x{FFEF}';

    private const NON_LATIN = '[\p{Greek}\p{Cyrillic}\p{Arabic}\p{Hebrew}\p{Thai}]';

    // A Thai vowel or tone mark above or below a letter that follows no consonant, or a leading vowel that no consonant follows.
    private const THAI_MISPLACED_MARK = '/(?<![\x{0E01}-\x{0E2E}\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}])[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]|' .
                                        '[\x{0E40}-\x{0E44}](?![\x{0E01}-\x{0E2E}])/u';

    private const SAMPLE_BYTES = 65536;

    // sample() looks for lines with a byte from 0x80 in this many bytes from the start.
    private const SCAN_BYTES = 16 * self::SAMPLE_BYTES;

    // Each non-ASCII letter or symbol adds this score when the language or the punctuation list has it, and the other score when not.
    private const KNOWN_CHARACTER_SCORE   = 1;
    private const UNKNOWN_CHARACTER_SCORE = -2;

    // Each case change inside a word, lowercase line start and change between Latin and another script costs this.
    private const CASE_PENALTY = 2;

    private const SYMBOL_IN_WORD_PENALTY = 3;

    // Candidates compare by the score per non-ASCII character, since a CJK character takes 2 bytes and a single-byte code page decodes them to 2 characters.
    private const MIN_SCORE_PER_CHARACTER = 0.5;

    /** @var array<string, array<string, true>>|null */
    private static ?array $letters = null;

    /** @var array<string, true>|null */
    private static ?array $punctuation = null;


    /**
     * Returns the code page and the UTF-8 text of $str, or null when no candidate gives plausible text.
     *
     * @return array{string, string}|null
     */
    public static function detect(string $str): ?array
    {
        $sample = self::sample($str);
        if ($sample === "") {
            return null;
        }

        $scores = [];
        $broken = [];
        foreach (array_keys(self::CANDIDATES) as $encoding) {
            $score = self::score($sample, $encoding);
            if ($score === null && in_array($encoding, self::MULTI_BYTE, true)) {
                $score             = self::score(self::decodableLines($sample, $encoding), $encoding);
                $broken[$encoding] = true;
            }
            if ($score !== null) {
                $scores[$encoding] = $score;
            }
        }
        // arsort() keeps the order of equal scores, so the candidate order breaks ties.
        arsort($scores);

        foreach ($scores as $encoding => $score) {
            if ($score <= 0) {
                break;
            }
            $converted = isset($broken[$encoding]) ? false : @iconv($encoding, "UTF-8", $str);
            if ($converted !== false) {
                return [$encoding, $converted];
            }
            // A CJK file with a byte that its code page cannot decode, such as a cut last character, reads as garbage in any other code page.
            if (in_array($encoding, self::MULTI_BYTE, true)) {
                return null;
            }
        }

        return null;
    }


    /** Returns the lines of $sample that $encoding decodes without an error. */
    private static function decodableLines(string $sample, string $encoding): string
    {
        return implode("\n", array_filter(explode("\n", $sample), fn (string $line): bool => @iconv($encoding, "UTF-8", $line) !== false));
    }


    /** Returns the lines with a byte from 0x80, up to SAMPLE_BYTES. */
    private static function sample(string $str): string
    {
        preg_match_all('/^[^\n]*[\x80-\xFF][^\n]*$/m', substr($str, 0, self::SCAN_BYTES), $lines);
        $sample = implode("\n", $lines[0]);

        // A cut inside a CJK character makes its code page drop out. No byte below 0x40 is the second byte of one.
        return strlen($sample) > self::SAMPLE_BYTES || strlen($str) > self::SCAN_BYTES
            ? rtrim(substr($sample, 0, self::SAMPLE_BYTES), "\x40..\xFF")
            : $sample;
    }


    /**
     * Returns the score per non-ASCII character of $sample decoded as $encoding, or null when a byte is undefined or
     * decodes to a C1 control. A score of at most MIN_SCORE_PER_CHARACTER marks implausible text and returns 0.
     */
    private static function score(string $sample, string $encoding): ?float
    {
        $text = @iconv($encoding, "UTF-8", $sample);
        if ($text === false || preg_match('/[\x{80}-\x{9F}]/u', $text) === 1) {
            return null;
        }

        preg_match_all('/[^\x00-\x7F]/u', $text, $matches);
        $counts  = array_count_values($matches[0]);
        $letters = self::letterCounts($counts);
        $score   = self::letterScore($letters, $encoding) + self::symbolScore(array_diff_key($counts, $letters)) - self::penalty($text);
        $score  /= max(1, count($matches[0]));

        return $score > self::MIN_SCORE_PER_CHARACTER ? $score : 0.0;
    }


    /**
     * @param array<string, int> $counts the count of each non-ASCII character
     * @return array<string, int> the counts of the letters and marks
     */
    private static function letterCounts(array $counts): array
    {
        return array_filter($counts, fn (string $char): bool => preg_match('/[\p{L}\p{M}]/u', $char) === 1, ARRAY_FILTER_USE_KEY);
    }


    /**
     * Returns the score of the language of $encoding that fits $letters best.
     *
     * @param array<string, int> $letters
     */
    private static function letterScore(array $letters, string $encoding): float
    {
        $best = $letters === [] ? 0.0 : -INF;
        foreach (self::CANDIDATES[$encoding] as $code) {
            $alphabet = self::letters()[$code];
            $rare     = self::RARE_LETTERS[$code] ?? null;
            $score    = 0.0;
            foreach ($letters as $char => $count) {
                $score += match (true) {
                    isset($alphabet[$char])                          => self::KNOWN_CHARACTER_SCORE,
                    $rare !== null && preg_match($rare, $char) === 1 => 0,
                    default                                          => self::UNKNOWN_CHARACTER_SCORE,
                } * $count;
            }
            $best = max($best, $score);
        }

        return $best;
    }


    /**
     * @param array<string, int> $symbols the count of each non-ASCII character that is no letter
     */
    private static function symbolScore(array $symbols): float
    {
        $score = 0.0;
        foreach ($symbols as $char => $count) {
            $score += (isset(self::punctuation()[$char]) ? self::KNOWN_CHARACTER_SCORE : self::UNKNOWN_CHARACTER_SCORE) * $count;
        }

        return $score;
    }


    /**
     * Returns the cost of odd letter case, mixed scripts, symbols inside words and Thai marks out of place.
     */
    private static function penalty(string $text): float
    {
        // Lines in capitals are common in subtitles. Other lines with more capitals than small letters are not.
        $extraCapitals = 0;
        foreach (explode("\n", $text) as $line) {
            $lower          = preg_match_all('/(?=[^\x00-\x7F])\p{Ll}/u', $line);
            $extraCapitals += $lower === 0 ? 0 : max(0, preg_match_all('/(?=[^\x00-\x7F])\p{Lu}/u', $line) - $lower);
        }
        $caseChanges = 0;
        preg_match_all('/\p{Ll}\p{Lu}/u', $text, $pairs);
        foreach ($pairs[0] as $pair) {
            $caseChanges += strlen($pair) > 2 ? 1 : 0;
        }
        preg_match_all('/^[^\p{L}\n]*([^\x00-\x7F])/mu', $text, $starts);
        $lowercaseStarts = preg_match_all('/\p{Ll}/u', implode("", $starts[1]));
        $mixedScripts    = preg_match_all('/\p{Latin}(?=' . self::NON_LATIN . ')|' . self::NON_LATIN . '(?=\p{Latin})/u', $text);
        $symbolsInWords  = preg_match_all('/(?<=\p{L})[^\p{L}\p{M}\s\x00-\x7F\x{2019}\x{00B7}' . self::CJK_PUNCTUATION . '](?=\p{L})/u', $text);
        $misplacedMarks  = preg_match_all(self::THAI_MISPLACED_MARK, $text);

        return $extraCapitals + self::CASE_PENALTY * ($caseChanges + $lowercaseStarts + $mixedScripts) + self::SYMBOL_IN_WORD_PENALTY * ($symbolsInWords + $misplacedMarks);
    }


    /** @return array<string, array<string, true>> */
    private static function letters(): array
    {
        if (self::$letters === null) {
            foreach (self::ALPHABETS as $code => $alphabet) {
                self::$letters[$code] = array_fill_keys(preg_split('//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY), true);
            }
            foreach (self::RANGES as $code => $ranges) {
                foreach ($ranges as [$first, $last]) {
                    for ($codePoint = $first; $codePoint <= $last; $codePoint++) {
                        self::$letters[$code][mb_chr($codePoint, "UTF-8")] = true;
                    }
                }
            }
            [$first, $last] = self::KOREAN_SYLLABLES;
            for ($codePoint = $first; $codePoint <= $last; $codePoint++) {
                $syllable = mb_chr($codePoint, "UTF-8");
                if (@iconv("UTF-8", "EUC-KR", $syllable) !== false) {
                    self::$letters["ko"][$syllable] = true;
                }
            }
        }

        return self::$letters;
    }


    /** @return array<string, true> */
    private static function punctuation(): array
    {
        return self::$punctuation ??= array_fill_keys(preg_split('//u', self::PUNCTUATION, -1, PREG_SPLIT_NO_EMPTY), true);
    }
}
