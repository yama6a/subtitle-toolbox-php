<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRules;

class TextTransformsTest extends \PHPUnit\Framework\TestCase
{
    private const FILES = __DIR__ . "/files/transforms/";


    private function parseCaptions(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . "own_cea608_caps.vtt"), Format::WebVtt);
    }


    private function parseMultilingual(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . "own_multilingual_caps.srt"), Format::SubRip);
    }


    public function testRealCaptionFileParsesAndRoundTrips(): void
    {
        $subtitle = $this->parseCaptions();
        $cues     = $subtitle->getCues();

        $this->assertCount(5, $cues);
        $this->assertSame([1.001, 3.336, ">> GOOD MORNING. THE 8:15 TRAIN\nTO MAIN STREET IS LATE."],
                          [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([10.177, 12.846, "THE BAKERY CAFÉ ON PLATFORM 2\nIS OPEN. ÄPFEL, STRASSE?"],
                          [$cues[4]->getStart(), $cues[4]->getEnd(), $cues[4]->getText()]);

        $again = Subtitle::fromString($subtitle->toString(Format::WebVtt), Format::WebVtt);
        $this->assertSame(TestSubtitles::texts($subtitle), TestSubtitles::texts($again));
        $this->assertEquals($subtitle->getComments(), $again->getComments());
    }


    public function testRealCaptionFileInSentenceCase(): void
    {
        $subtitle = $this->parseCaptions();

        $this->assertSame($subtitle, $subtitle->changeCase(CaseMode::Sentence));
        $this->assertSame(file_get_contents(self::FILES . "own_cea608_caps_sentence.vtt"),
                          $subtitle->toString(Format::WebVtt));
    }


    public function testRealRunOnCaptionsContinueSentencesAcrossCues(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_cea608_run_on.vtt"), Format::WebVtt);

        $this->assertSame(file_get_contents(self::FILES . "own_cea608_run_on_sentence.vtt"),
                          $subtitle->changeCase(CaseMode::Sentence)->toString(Format::WebVtt));
    }


    public function testRealSoundCuesAndSpeakerLabelsStartSentences(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_cea608_sound_cues.vtt"), Format::WebVtt);

        $this->assertSame(file_get_contents(self::FILES . "own_cea608_sound_cues_sentence.vtt"),
                          $subtitle->changeCase(CaseMode::Sentence)->toString(Format::WebVtt));
    }


    public function testRealCaptionFileCleanedUp(): void
    {
        $subtitle = $this->parseCaptions()
            ->replaceText('/\[[^\]]*\]/', "", new ReplaceTextOptions(regex: true))
            ->replaceText('/\.{4,}/', "...", new ReplaceTextOptions(regex: true))
            ->stripFormatting();

        $this->assertSame(file_get_contents(self::FILES . "own_cea608_caps_cleaned.vtt"),
                          $subtitle->toString(Format::WebVtt));
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testRealMultilingualFileParsesAndRoundTrips(): void
    {
        $subtitle = $this->parseMultilingual();
        $cues     = $subtitle->getCues();

        $this->assertCount(4, $cues);
        $this->assertSame([1.0, 3.5, "<b>ΟΔΟΣ ΣΤΑΘΜΟΥ 4</b>"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([8.5, 10.0, "<i>RAIN &lt;3 &amp; SNOW</i>"], [$cues[3]->getStart(), $cues[3]->getEnd(), $cues[3]->getText()]);

        $again = Subtitle::fromString($subtitle->toString(Format::SubRip), Format::SubRip);
        $this->assertSame(TestSubtitles::texts($subtitle), TestSubtitles::texts($again));
    }


    public function testRealMultilingualFileInLowerCase(): void
    {
        $this->assertSame([
            "<b>οδος σταθμου 4</b>",
            "große bäckerei. öffnet um 6 uhr!",
            "<font color=\"#ffff00\">i\u{307}stasyon kapisi işikli.</font>",
            "<i>rain &lt;3 &amp; snow</i>",
        ], TestSubtitles::texts($this->parseMultilingual()->changeCase(CaseMode::Lower)));
    }


    public function testTurkishRulesApplyToEveryCue(): void
    {
        $this->assertSame([
            "<b>Οδος σταθμου 4</b>",
            "große bäckereı. Öffnet um 6 uhr!",
            "<font color=\"#ffff00\">İstasyon kapısı ışıklı.</font>",
            "<i>Raın &lt;3 &amp; snow</i>",
        ], TestSubtitles::texts($this->parseMultilingual()->changeCase(CaseMode::Sentence, "tr-TR")));
    }


    public function testUpperCaseKeepsTagsAndEntities(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>stop</i>", "tom &amp; <font color=\"#ff0000\">jerry</font> &lt;3"]);

        $this->assertSame(["<i>STOP</i>", "TOM &amp; <font color=\"#ff0000\">JERRY</font> &lt;3"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Upper)));
    }


    public function testUpperCaseOfGermanAndTurkish(): void
    {
        $this->assertSame(["STRASSE", "ISTANBUL"], TestSubtitles::texts(TestSubtitles::fromTexts(["straße", "istanbul"])->changeCase(CaseMode::Upper)));
        $this->assertSame(["İSTANBUL KAPI"], TestSubtitles::texts(TestSubtitles::fromTexts(["istanbul kapı"])->changeCase(CaseMode::Upper, "tr")));
        $this->assertSame(["istanbul kapı"], TestSubtitles::texts(TestSubtitles::fromTexts(["İSTANBUL KAPI"])->changeCase(CaseMode::Lower, "az")));
    }


    public function testLowerCaseUsesGreekFinalSigma(): void
    {
        $this->assertSame(["οδος σας, σ"], TestSubtitles::texts(TestSubtitles::fromTexts(["ΟΔΟΣ ΣΑΣ, Σ"])->changeCase(CaseMode::Lower)));
    }


    public function testCaseChangeKeepsBytesOfInvalidUtf8(): void
    {
        $this->assertSame(["CAF\xe9 <i>NO\xebL</i>"], TestSubtitles::texts(TestSubtitles::fromTexts(["caf\xe9 <i>no\xebl</i>"])->changeCase(CaseMode::Upper)));
    }


    public function testSentenceCase(): void
    {
        $subtitle = TestSubtitles::fromTexts([
            "WHERE ARE YOU GOING? HOME.",
            "<i>WAIT...</i> <b>WHAT?!</b> \"NO.\" OK.",
            "- READ WWW.EXAMPLE.COM.\n- 3.5 KM, THEN STOP!",
            "STRASSE. ßAD",
        ]);

        $this->assertSame([
            "Where are you going? Home.",
            "<i>Wait...</i> <b>What?!</b> \"No.\" Ok.",
            "- Read www.example.com.\n- 3.5 km, then stop!",
            "Strasse. Ssad",
        ], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testSentenceContinuesInTheNextCue(): void
    {
        $subtitle = TestSubtitles::fromTexts(["WE WENT TO THE", "STORE ON MONDAY, AND I THINK I SAW JOHN.", "THEN \"BYE.\"", "<i>SO…</i>", "OK"]);

        $this->assertSame(["We went to the", "store on monday, and I think I saw john.", "Then \"bye.\"", "<i>So…</i>", "Ok"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testSpeakerChangeAndDialogueDashStartASentence(): void
    {
        $subtitle = TestSubtitles::fromTexts(["WAIT FOR", ">> ME AT THE", ">>>STATION", "AND\n- WHY\n\u{2013} FOR", "<i>- LUNCH</i>", "AT", "-20 DEGREES"]);

        $this->assertSame(["Wait for", ">> Me at the", ">>>Station", "and\n- Why\n\u{2013} For", "<i>- Lunch</i>", "at", "-20 degrees"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testAnnotationCuesStartASentenceInInvalidUtf8(): void
    {
        $subtitle = TestSubtitles::fromTexts(["CAF\xe9 [BELL]", "WAIT \xe9", "NO\xeb JOHN:", "GO \xe9", "(LAUGHS) \xe9", "\u{266A} LA \xe9 \u{266A}", "OK"]);

        $this->assertSame(["Caf\xe9 [bell]", "Wait \xe9", "no\xeb john:", "Go \xe9", "(Laughs) \xe9", "\u{266A} La \xe9 \u{266A}", "Ok"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    #[DataProvider("abbreviationProvider")]
    public function testAbbreviationPeriodEndsNoSentenceBeforeACommaOrALowerCaseWord(string $input, string $expected): void
    {
        $this->assertSame([$expected], TestSubtitles::texts(TestSubtitles::fromTexts([$input])->changeCase(CaseMode::Sentence)));
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function abbreviationProvider(): array
    {
        return [
            "i.e. and comma"          => ["it fits, i.e., now or never", "It fits, i.e., now or never"],
            "e.g. and comma"          => ["TAKE ONE, E.G., SOON.", "Take one, e.g., soon."],
            "e.g. before lower case"  => ["Fruit, e.g. apples.", "Fruit, e.g. apples."],
            "vs. before lower case"   => ["Red vs. blue", "Red vs. blue"],
            "etc. before upper case"  => ["Apples, pears, etc. Then we left.", "Apples, pears, etc. Then we left."],
            "etc. in upper case text" => ["APPLES, ETC. THEN WE LEFT.", "Apples, etc. Then we left."],
            "comma after a question"  => ["\"WHY?,\" HE ASKED.", "\"Why?,\" he asked."],
            "tag after i.e."          => ["We mean <i>i.e.</i> this one.", "We mean <i>i.e.</i> this one."],
            "space, tag after i.e."   => ["We mean <i>i.e. </i>this one.", "We mean <i>i.e. </i>this one."],
            "tag before upper case"   => ["We mean <i>e.g. </i>Then we left.", "We mean <i>e.g. </i>Then we left."],
        ];
    }


    #[DataProvider("sentenceGapProvider")]
    public function testGapStartsASentence(float $start, string $expected): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(0.0, 1.0, "WAIT FOR"), new SubtitleCue($start, $start + 1, "ME")]);

        $this->assertSame(["Wait for", $expected], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    /**
     * @return array<string, array{float, string}>
     */
    public static function sentenceGapProvider(): array
    {
        return [
            "1.999 s" => [2.999, "me"],
            "2 s"     => [3.0, "Me"],
            "5 s"     => [6.0, "Me"],
        ];
    }


    #[DataProvider("englishIProvider")]
    public function testEnglishPronounIInUpperCase(?string $language, string $expected): void
    {
        $subtitle = TestSubtitles::fromTexts(["WELL, I'M HERE AND I'LL STAY. I\u{2019}VE, I'D, I-I, HI, PI, 3I, I'S, I.E."]);

        $this->assertSame([$expected], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence, $language)));
    }


    /**
     * @return array<string, array{?string, string}>
     */
    public static function englishIProvider(): array
    {
        $english = "Well, I'm here and I'll stay. I\u{2019}ve, I'd, I-I, hi, pi, 3i, i's, i.e.";

        return [
            "no language" => [null, $english],
            "en"          => ["en", $english],
            "en-GB"       => ["en-GB", $english],
            "de"          => ["de", "Well, i'm here and i'll stay. I\u{2019}ve, i'd, i-i, hi, pi, 3i, i's, i.e."],
        ];
    }


    public function testEnglishPronounIIsDecidedOnTheWholeLine(): void
    {
        $subtitle = TestSubtitles::fromTexts(["WE &amp; I'LL GO\nI.E., NOW.", "W<b>I</b>NDOW AND I", "<i>I</i> <b>AM</b> H<i>I</i>"]);

        $this->assertSame(["We &amp; I'll go\ni.e., now.", "W<b>i</b>ndow and I", "<i>I</i> <b>am</b> h<i>i</i>"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testSentenceCaseWritesTheEnglishIInInvalidUtf8(): void
    {
        $this->assertSame(["Caf\xE9 I think"], TestSubtitles::texts(TestSubtitles::fromTexts(["CAF\xE9 I THINK"])->changeCase(CaseMode::Sentence)));
    }


    /**
     * @return array<string, array{string, \Closure(Subtitle): Subtitle, string}>
     */
    public static function entityProvider(): array
    {
        return [
            "upper keeps &nbsp;"          => ["yes &nbsp; x", fn (Subtitle $s): Subtitle => $s->changeCase(CaseMode::Upper), "YES &nbsp; X"],
            "upper keeps &#233;"          => ["yes &#233; x", fn (Subtitle $s): Subtitle => $s->changeCase(CaseMode::Upper), "YES &#233; X"],
            "upper keeps &amp; escaped"   => ["yes &amp; &nbsp; x", fn (Subtitle $s): Subtitle => $s->changeCase(CaseMode::Upper), "YES &amp; &nbsp; X"],
            "sentence case pronoun I"     => ["Yes, i &nbsp; x", fn (Subtitle $s): Subtitle => $s->changeCase(CaseMode::Sentence), "Yes, I &nbsp; x"],
            "escaped text changes case"   => ["Q&amp;A; FLUX", fn (Subtitle $s): Subtitle => $s->changeCase(CaseMode::Lower), "q&amp;a; flux"],
            "mapText keeps &nbsp;"        => ["x &nbsp; x", fn (Subtitle $s): Subtitle => $s->mapText(fn (string $text): string => str_replace("x", "y", $text)), "y &nbsp; y"],
        ];
    }


    /**
     * @param \Closure(Subtitle): Subtitle $change
     */
    #[DataProvider("entityProvider")]
    public function testEntitiesStayInAChangedTextRun(string $line, \Closure $change, string $expected): void
    {
        $this->assertSame([$expected], TestSubtitles::texts($change(TestSubtitles::fromTexts([$line]))));
    }


    public function testInvalidUtf8CueEndsASentence(): void
    {
        $subtitle = TestSubtitles::fromTexts(["\xFF END.", "NEXT"]);

        $this->assertSame(["\xFF End.", "Next"], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testFixRightToLeftWrapsRightToLeftLinesInsideTheirTags(): void
    {
        $subtitle = Subtitle::load(self::FILES . "own_rtl.srt", Format::SubRip)->fixRightToLeft();

        $this->assertSame(file_get_contents(self::FILES . "own_rtl_fixed.srt"), $subtitle->toString(Format::SubRip));
        $this->assertSame(file_get_contents(self::FILES . "own_rtl_fixed.srt"), $subtitle->fixRightToLeft()->toString(Format::SubRip));
    }


    public function testEachWrappedLineGetsItsOwnRightToLeftEmbedding(): void
    {
        $golden = file_get_contents(self::FILES . "own_rtl_long_wrapped.srt");

        $this->assertSame($golden, Subtitle::load(self::FILES . "own_rtl_long.srt", Format::SubRip)->fixRightToLeft()->wrapLines(20)->toString(Format::SubRip));
        $this->assertSame($golden, Subtitle::load(self::FILES . "own_rtl_long.srt", Format::SubRip)->wrapLines(20)->fixRightToLeft()->toString(Format::SubRip));
    }


    public function testEachUnwrappedLineGetsItsOwnRightToLeftEmbedding(): void
    {
        $subtitle = Subtitle::load(self::FILES . "own_rtl_long_wrapped.srt", Format::SubRip)->unwrapLines();

        $this->assertSame(["\u{202B}אתה יכול לסגור את החלון, בבקשה?\u{202C}", "<i>\u{202B}ראית את החתולה</i> <i>שלי בגינה?\u{202C}</i>"],
                          array_slice(TestSubtitles::texts($subtitle), 0, 2));
    }


    public function testFixRightToLeftMovesSentenceEndPunctuationFromTheStartToTheEnd(): void
    {
        $subtitle = Subtitle::load(self::FILES . "own_rtl_reversed.srt", Format::SubRip)->fixRightToLeft();

        $this->assertSame(file_get_contents(self::FILES . "own_rtl_reversed_fixed.srt"), $subtitle->toString(Format::SubRip));
        $this->assertSame(file_get_contents(self::FILES . "own_rtl_reversed_fixed.srt"), $subtitle->fixRightToLeft()->toString(Format::SubRip));
    }


    #[DataProvider("rightToLeftProvider")]
    public function testFixRightToLeft(string $input, string $expected): void
    {
        $this->assertSame([$expected], TestSubtitles::texts(TestSubtitles::fromTexts([$input])->fixRightToLeft()));
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function rightToLeftProvider(): array
    {
        return [
            "Arabic"                => ["مرحبا، كيف حالك؟", "\u{202B}مرحبا، كيف حالك؟\u{202C}"],
            "tags stay outside"     => ["<i>שלום!</i>", "<i>\u{202B}שלום!\u{202C}</i>"],
            "override stays out"    => ["{\\an8}مرحبا.", "{\\an8}\u{202B}مرحبا.\u{202C}"],
            "each line"             => ["שלום.\nHi.\n- مرحبا.", "\u{202B}שלום.\u{202C}\nHi.\n\u{202B}- مرحبا.\u{202C}"],
            "already fixed"         => ["<i>\u{202B}שלום!\u{202C}</i>", "<i>\u{202B}שלום!\u{202C}</i>"],
            "marks inside the text" => ["\u{202B}שלום\u{202C} <b>!</b>", "\u{202B}שלום <b>!\u{202C}</b>"],
            "left to right"         => ["Hello.", "Hello."],
            "Arabic digits only"    => ["١٢٣.", "١٢٣."],
            "drawing"               => ["{\\p1}m 0 0 l 10 10{\\p0}", "{\\p1}m 0 0 l 10 10{\\p0}"],
            "tags only"             => ["<i></i>", "<i></i>"],
            "punctuation first"     => [".מחר", "\u{202B}מחר.\u{202C}"],
            "space after it"        => ["?! מה", "\u{202B}מה?!\u{202C}"],
            "tag after it"          => [".<i>מחר</i>", "<i>\u{202B}מחר.\u{202C}</i>"],
            "earlier fix"           => ["\u{202B}.מחר\u{202C}", "\u{202B}מחר.\u{202C}"],
            "left-to-right first"   => [".Hello", ".Hello"],
        ];
    }


    #[DataProvider("bidiControlProvider")]
    public function testRemoveBidiControls(string $input, string $expected): void
    {
        $this->assertSame([$expected], TestSubtitles::texts(TestSubtitles::fromTexts([$input])->removeBidiControls()));
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function bidiControlProvider(): array
    {
        return [
            "embedding"         => ["\u{202B}مرحبا؟\u{202C}", "مرحبا؟"],
            "marks inside tags" => ["<i>\u{200F}שלום\u{200E}</i>", "<i>שלום</i>"],
            "override"          => ["Hello \u{202E}dlrow", "Hello dlrow"],
            "isolates"          => ["\u{2066}a\u{2069} \u{2067}b\u{2069} \u{2068}c\u{2069}\nd\u{202A}e\u{202D}", "a b c\nde"],
            "no-break space"    => ["a\u{00A0}b", "a\u{00A0}b"],
            "invalid UTF-8"     => ["\xFF\u{200F}a", "\xFFa"],
        ];
    }


    public function testRemoveBidiControlsUndoesFixRightToLeft(): void
    {
        $original = Subtitle::load(self::FILES . "own_rtl.srt", Format::SubRip);
        $fixed    = Subtitle::load(self::FILES . "own_rtl_fixed.srt", Format::SubRip);

        $this->assertSame($original->toString(Format::SubRip), $fixed->removeBidiControls()->toString(Format::SubRip));
    }


    public function testGermanKeepsALoneLowerCaseI(): void
    {
        $this->assertSame(["Ich bin da, i."], TestSubtitles::texts(TestSubtitles::fromTexts(["ICH BIN DA, I."])->changeCase(CaseMode::Sentence, "de")));
    }


    public function testLowerCaseKeepsTheEnglishPronounI(): void
    {
        $this->assertSame(["i think"], TestSubtitles::texts(TestSubtitles::fromTexts(["I THINK"])->changeCase(CaseMode::Lower)));
    }


    public function testReplaceTextMatchesVisibleTextOnly(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>Colour</i> me surprised", "Tom &amp; Jerry", "<font color=\"#ff0000\">red</font> amp"]);

        $subtitle->replaceText("Colour", "Color")->replaceText("&", "and")->replaceText("amp", "lamp")->replaceText("ff", "XX");

        $this->assertSame(["<i>Color</i> me surprised", "Tom and Jerry", "<font color=\"#ff0000\">red</font> lamp"],
                          TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextEscapesTheReplacement(): void
    {
        $subtitle = TestSubtitles::fromTexts(["I love it", "<b>x</b>"]);

        $subtitle->replaceText("love", "<3 & more")->replaceText("x", "<b>");

        $this->assertSame(["I &lt;3 &amp; more it", "<b>&lt;b&gt;</b>"], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextKeepsUnescapedWebVttCharacters(): void
    {
        $subtitle = TestSubtitles::fromTexts([">> TOM & JERRY", ">> A &amp; B"]);

        $subtitle->replaceText("TOM", "Tom &lt;")->replaceText("A", "a");

        $this->assertSame([">> Tom &amp;lt; & JERRY", ">> a &amp; B"], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextWithRegex(): void
    {
        $subtitle = TestSubtitles::fromTexts(["Wait.....", "<i>colour</i> and COLOUR"]);

        $subtitle->replaceText('/\.{4,}/', "...", new ReplaceTextOptions(regex: true))->replaceText('/col(ou)r/', 'col$1r!', new ReplaceTextOptions(regex: true, caseSensitive: false));

        $this->assertSame(["Wait...", "<i>colour!</i> and colOUr!"], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextCaseInsensitiveWithoutRegex(): void
    {
        $subtitle = TestSubtitles::fromTexts(["Ärger and ärger", "Price: \$1"]);

        $subtitle->replaceText("ÄRGER", "joy", new ReplaceTextOptions(caseSensitive: false))->replaceText("price", '$1 \1', new ReplaceTextOptions(caseSensitive: false));

        $this->assertSame(["joy and joy", '$1 \1: $1'], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextRejectsInvalidInput(): void
    {
        $subtitle = TestSubtitles::fromTexts(["text"]);

        try {
            $subtitle->replaceText("", "x");
            $this->fail("An empty search must throw.");
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $subtitle->replaceText("/(/", "x", new ReplaceTextOptions(regex: true));
    }


    public function testStripFormatting(): void
    {
        $texts = ["<b>Run</b>, <font color=\"#ff0000\">now</font>!", "<b><i>Run</i></b> &amp; <v Fred>hide"];

        $this->assertSame(["Run, now!", "Run &amp; hide"], TestSubtitles::texts(TestSubtitles::fromTexts([...$texts])->stripFormatting()));
        $this->assertSame(["Run, now!", "<i>Run</i> &amp; hide"],
                          TestSubtitles::texts(TestSubtitles::fromTexts([...$texts])->stripFormatting(["i"])));
    }


    public function testStripFormattingKeepsWordTimestamps(): void
    {
        $line = "<b>One</b> <00:00:01.500>two <00:00:02.000><i>three</i>";

        $this->assertSame(["One <00:00:01.500>two <00:00:02.000>three"],
                          TestSubtitles::texts(TestSubtitles::fromTexts([$line])->stripFormatting()));
        $this->assertSame(["One two <i>three</i>"], TestSubtitles::texts(TestSubtitles::fromTexts([$line])->stripFormatting(["i"], false)));
    }


    public function testMapTextGetsDecodedTextRunsAndTheCue(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>a &amp; b</i> c", "&lt;d&gt;"]);
        $calls    = [];

        $subtitle->mapText(function (string $text, SubtitleCue $cue) use (&$calls): string {
            $calls[] = [$text, $cue->getStart()];

            return "[$text]";
        });

        $this->assertSame([["a & b", 0.0], [" c", 0.0], ["<d>", 1.0]], $calls);
        $this->assertSame(["<i>[a &amp; b]</i>[ c]", "[&lt;d&gt;]"], TestSubtitles::texts($subtitle));
    }


    public function testMapTextKeepsUnchangedRunsByteForByte(): void
    {
        $subtitle = TestSubtitles::fromTexts(["a&nbsp;b <i>&#39;c&#39;</i>"]);

        $subtitle->mapText(fn (string $text): string => $text);

        $this->assertSame(["a&nbsp;b <i>&#39;c&#39;</i>"], TestSubtitles::texts($subtitle));
    }


    public function testMapLinesGetsFullLines(): void
    {
        $subtitle = TestSubtitles::fromTexts(["one\n<i>two</i>"]);

        $subtitle->mapLines(fn (string $line, SubtitleCue $cue): string => "<font color=\"#ffff00\">$line</font>");

        $this->assertSame(["<font color=\"#ffff00\">one</font>\n<font color=\"#ffff00\"><i>two</i></font>"],
                          TestSubtitles::texts($subtitle));
    }


    public function testRemovesCuesThatBecomeEmptyAndKeepsComments(): void
    {
        $subtitle = TestSubtitles::fromTexts(["first", "<i>[MUSIC]</i>", "[DOOR]\n[MUSIC]", "last"]);
        $subtitle->addCue(new SubtitleCue(10, 11, ""));
        $subtitle->addComment("before music", 1)->addComment("before door", 2)->addComment("before last", 3);

        $subtitle->replaceText('/\[\w+\]/', "", new ReplaceTextOptions(regex: true));

        $this->assertSame(["first", "last", ""], TestSubtitles::texts($subtitle));
        $this->assertEquals([
            new Comment("before music", 1),
            new Comment("before door", 1),
            new Comment("before last", 1),
        ], $subtitle->getComments());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }
}
