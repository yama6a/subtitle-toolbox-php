<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../files/fixing/generate.php";

class CommonErrorFixerTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";

    private const ALL_OFF = [
        "doubleSpaces"                 => false,
        "spaceBeforePunctuation"       => false,
        "missingSpaceAfterPunctuation" => false,
        "unbalancedTags"               => false,
        "emptyTags"                    => false,
        "dialogueDashes"               => false,
        "ellipsis"                     => false,
        "ocrLowercaseL"                => false,
        "ocrPipe"                      => false,
        "ocrZeroInWords"               => false,
    ];


    /**
     * @param list<string> $lines
     * @return array{list<string>, list<AppliedFix>}
     */
    private static function fixLines(array $lines, ?CommonErrorOptions $options = null): array
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, $lines));
        $fixes    = CommonErrorFixer::apply($subtitle, $options ?? new CommonErrorOptions(language: "en"))->fixes;

        return [array_values($subtitle->getCues()[0]->getLines()), $fixes];
    }


    /**
     * Each case: the rule, the language, the lines before and the lines after.
     *
     * @return array<string, array{string, ?string, list<string>, list<string>}>
     */
    public static function ruleCases(): array
    {
        return [
            "double spaces across a tag"         => ["doubleSpaces", "en", ["Hi <i> there</i>"], ["Hi <i>there</i>"]],
            "two non-breaking spaces"            => ["doubleSpaces", "en", ["Hi\u{00A0}\u{00A0}there"], ["Hi there"]],
            "space before a question mark"       => ["spaceBeforePunctuation", "en", ["Really ?"], ["Really?"]],
            "space before a comma and a period"  => ["spaceBeforePunctuation", "de", ["Ja , gut ."], ["Ja, gut."]],
            "French keeps the space before ?"    => ["spaceBeforePunctuation", "fr", ["Quoi ? Non , merci !"], ["Quoi ? Non, merci !"]],
            "space before an ellipsis stays"     => ["spaceBeforePunctuation", "en", ["Wait ... now"], ["Wait ... now"]],
            "space before a smiley stays"        => ["spaceBeforePunctuation", "en", ["Great :)"], ["Great :)"]],
            "missing space after a period"       => ["missingSpaceAfterPunctuation", "en", ["Stop.Now"], ["Stop. Now"]],
            "numbers, URLs and abbreviations"    => ["missingSpaceAfterPunctuation", "en", ["1.5 www.example.com e.g. U.S.Army ASP.NET"],
                                                     ["1.5 www.example.com e.g. U.S.Army ASP.NET"]],
            "missing space after , ! ? and ..."  => ["missingSpaceAfterPunctuation", "es", ["Hola,Ana!¿Vienes?Sí...vale"],
                                                     ["Hola, Ana! ¿Vienes? Sí... vale"]],
            "unclosed tag"                       => ["unbalancedTags", "en", ["<i>Hello"], ["<i>Hello</i>"]],
            "tag over two lines"                 => ["unbalancedTags", "en", ["<i>Hello", "world</i>"], ["<i>Hello", "world</i>"]],
            "unclosed tags close in reverse"     => ["unbalancedTags", "en", ["<b><font color=\"#ff0000\">Hi", "you"],
                                                     ["<b><font color=\"#ff0000\">Hi", "you</font></b>"]],
            "stray closing tag"                  => ["unbalancedTags", "en", ["Hello</i> there"], ["Hello there"]],
            "speaker tag needs no closing tag"   => ["unbalancedTags", "en", ["<v Anna>Hello"], ["<v Anna>Hello"]],
            "closing tag of a WebVTT class tag"  => ["unbalancedTags", "en", ["<b.loud>Hello</b> there"], ["<b.loud>Hello</b> there"]],
            "empty tag"                          => ["emptyTags", "en", ["Hi <i></i>there"], ["Hi there"]],
            "nested empty tags"                  => ["emptyTags", "en", ["Hi <b><i> </i></b>there"], ["Hi there"]],
            "dialogue dashes"                    => ["dialogueDashes", "en", ["-Hi.", "-Hello."], ["- Hi.", "- Hello."]],
            "en dash in italics"                 => ["dialogueDashes", "en", ["<i>\u{2013}Hi.</i>"], ["<i>- Hi.</i>"]],
            "negative number"                    => ["dialogueDashes", "en", ["-5 degrees."], ["-5 degrees."]],
            "double dash"                        => ["dialogueDashes", "en", ["--and then"], ["--and then"]],
            "spaced dots"                        => ["ellipsis", "en", ["Well. . . no."], ["Well... no."]],
            "four dots"                          => ["ellipsis", "en", ["Well...."], ["Well..."]],
            "lt's, l'm and l'll"                 => ["ocrLowercaseL", "en", ["lt's late. l'm here, l'll go."], ["It's late. I'm here, I'll go."]],
            "standalone l"                       => ["ocrLowercaseL", "en", ["lf l can, l will.", "-l see."], ["If I can, I will.", "-I see."]],
            "units and names stay"               => ["ocrLowercaseL", "en", ["5 lbs, 2 l, Al and llamas."], ["5 lbs, 2 l, Al and llamas."]],
            "l in upper case words"              => ["ocrLowercaseL", "it", ["NON LO SO. lL MlO."], ["NON LO SO. IL MIO."]],
            "German"                             => ["ocrLowercaseL", "de", ["lch weiß. lst das lhr Hund?"], ["Ich weiß. Ist das Ihr Hund?"]],
            "French"                             => ["ocrLowercaseL", "fr", ["ll dit. lls vont à l'hôtel."], ["Il dit. Ils vont à l'hôtel."]],
            "Spanish keeps ll"                   => ["ocrLowercaseL", "es", ["lr a la isla. lván llama."], ["Ir a la isla. Iván llama."]],
            "other languages"                    => ["ocrLowercaseL", "it", ["lo sono lt"], ["lo sono lt"]],
            "pipe at a word start"               => ["ocrPipe", "en", ["|t was"], ["It was"]],
            "pipe after lower case"              => ["ocrPipe", "de", ["Wi|| he|fen"], ["Will helfen"]],
            "standalone pipe in English"         => ["ocrPipe", "en", ["Yes, | know. |'m here."], ["Yes, I know. I'm here."]],
            "pipe and apostrophe in French"      => ["ocrPipe", "fr", ["|'homme | 2"], ["l'homme | 2"]],
            "zero in an upper case word"         => ["ocrZeroInWords", "en", ["D0N'T"], ["DON'T"]],
            "zero in lower case words"           => ["ocrZeroInWords", "en", ["0nly the n0rth. 0nce"], ["Only the north. Once"]],
            "numbers stay"                       => ["ocrZeroInWords", "en", ["007, 2.0, 10am, 0s and 3D0"], ["007, 2.0, 10am, 0s and 3D0"]],
            "entities stay escaped"              => ["ocrLowercaseL", "en", ["&lt;lt&gt; &amp; l"], ["&lt;It&gt; &amp; I"]],
            "dialogue on one line"               => ["dialogueOnOneLine", "en", ["- Hi. - Hello."], ["- Hi.", "- Hello."]],
            "dialogue on one line in italics"    => ["dialogueOnOneLine", "en", ["<i>- Hi. - Hello.</i>"], ["<i>- Hi.</i>", "<i>- Hello.</i>"]],
            "dialogue without the first dash"    => ["dialogueOnOneLine", "en", ["Hi. - Hello."], ["- Hi.", "- Hello."]],
            "dash after a question in a tag"     => ["dialogueOnOneLine", "en", ["<font color=\"#ffff00\">Why?</font> -Because."],
                                                     ["<font color=\"#ffff00\">- Why?</font>", "-Because."]],
            "dialogue over two lines"            => ["dialogueOnOneLine", "en", ["Hi. - Hello,", "how are you?"], ["- Hi.", "- Hello, how are you?"]],
            "dash inside a sentence"             => ["dialogueOnOneLine", "en", ["A well-known - and loved - song."], ["A well-known - and loved - song."]],
            "dialogue already on two lines"      => ["dialogueOnOneLine", "en", ["- Hi.", "- Hello."], ["- Hi.", "- Hello."]],
            "three speakers stay"                => ["dialogueOnOneLine", "en", ["- Hi. - Hello. - Hey."], ["- Hi. - Hello. - Hey."]],
            "negative number after a sentence"   => ["dialogueOnOneLine", "en", ["It's cold. -5 degrees."], ["It's cold. -5 degrees."]],
            "lone i"                             => ["loneLowercaseI", "en", ["i think i can."], ["I think I can."]],
            "lone i in a tag"                    => ["loneLowercaseI", "en", ["<i>i</i> know"], ["<i>I</i> know"]],
            "lone i with contractions"           => ["loneLowercaseI", "en", ["i'm sure, i\u{2019}ll go, i'd and i've"],
                                                     ["I'm sure, I\u{2019}ll go, I'd and I've"]],
            "i in words and abbreviations"       => ["loneLowercaseI", "en", ["see i.e. here, www.i.com, iPhone, w<b>i</b>th"],
                                                     ["see i.e. here, www.i.com, iPhone, w<b>i</b>th"]],
            "lone i in German"                   => ["loneLowercaseI", "de", ["i think"], ["i think"]],
            "lone i without a language"          => ["loneLowercaseI", null, ["i think"], ["i think"]],
            "lone i in invalid UTF-8 stays"      => ["loneLowercaseI", "en", ["caf\xE9 i think"], ["caf\xE9 i think"]],
            "first cue starts a sentence"        => ["sentenceStartCase", "en", ["hello."], ["Hello."]],
            "line after a sentence end"          => ["sentenceStartCase", "en", ["- Hello.", "- <i>\"where</i> are you?"],
                                                     ["- Hello.", "- <i>\"Where</i> are you?"]],
            "line after a comma"                 => ["sentenceStartCase", "en", ["So I said,", "no way."], ["So I said,", "no way."]],
            "word with an inner capital"         => ["sentenceStartCase", "en", ["iPhone is here."], ["iPhone is here."]],
            "music note and a dash before"       => ["sentenceStartCase", "en", ["\u{266A} la la!", "- \u{00E9}t\u{00E9}."],
                                                     ["\u{266A} La la!", "- \u{00C9}t\u{00E9}."]],
            "Turkish dotted i"                   => ["sentenceStartCase", "tr", ["iyi."], ["\u{0130}yi."]],
            "hash music signs"                   => ["musicNotes", "en", ["# Happy birthday #"], ["\u{266A} Happy birthday \u{266A}"]],
            "star music signs"                   => ["musicNotes", "en", ["* Happy birthday *"], ["\u{266A} Happy birthday \u{266A}"]],
            "music sign in a tag"                => ["musicNotes", "en", ["<i># la la la</i>"], ["<i>\u{266A} la la la</i>"]],
            "music sign after a dash"            => ["musicNotes", "en", ["- # la la", "- * oh *"], ["- \u{266A} la la", "- \u{266A} oh \u{266A}"]],
            "music sign as the whole line"       => ["musicNotes", "en", ["#"], ["\u{266A}"]],
            "signs that are no music"            => ["musicNotes", "en", ["Room #5, *sigh*", "C# code", "#1 fan"],
                                                     ["Room #5, *sigh*", "C# code", "#1 fan"]],
            "double apostrophes"                 => ["doubleApostrophes", "en", ["''Hi,'' she said."], ["\"Hi,\" she said."]],
            "double apostrophes in a tag"        => ["doubleApostrophes", "en", ["<i>''Run!''</i>"], ["<i>\"Run!\"</i>"]],
            "double apostrophes after It's"      => ["doubleApostrophes", "en", ["It's ''the'' one"], ["It's \"the\" one"]],
            "double U+2019"                      => ["doubleApostrophes", "en", ["\u{2019}\u{2019}Go\u{2019}\u{2019}"], ["\"Go\""]],
            "single apostrophes"                 => ["doubleApostrophes", "en", ["rock 'n' roll 'til dawn"], ["rock 'n' roll 'til dawn"]],
            "apostrophes in a tag attribute"     => ["doubleApostrophes", "en", ["<font face=''>Hi</font>"], ["<font face=''>Hi</font>"]],
            "period after a question"            => ["unneededPeriods", "en", ["Really?."], ["Really?"]],
            "period after a bang in a tag"       => ["unneededPeriods", "en", ["<i>Stop!.</i>"], ["<i>Stop!</i>"]],
            "period inside a line"               => ["unneededPeriods", "en", ["What?. Why?"], ["What? Why?"]],
            "ellipsis after a question"          => ["unneededPeriods", "en", ["What?...", "Wait!..", "Really?\u{2026}"],
                                                     ["What?...", "Wait!..", "Really?\u{2026}"]],
            "text without case"                  => ["sentenceStartCase", "ja", ["\u{3053}\u{3093}\u{306B}\u{3061}\u{306F}\u{3002}"],
                                                     ["\u{3053}\u{3093}\u{306B}\u{3061}\u{306F}\u{3002}"]],
        ];
    }


    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    #[DataProvider("ruleCases")]
    public function testRuleFixesTheTextBetweenTags(string $rule, ?string $language, array $before, array $after): void
    {
        $only = array_merge(self::ALL_OFF, [$rule => true]);

        [$lines, $fixes] = self::fixLines($before, new CommonErrorOptions($language, ...$only));

        $this->assertSame($after, $lines);
        $this->assertSame($before === $after ? [] : [$rule], array_map(fn (AppliedFix $fix): string => $fix->rule->value, $fixes));
    }


    /**
     * Each set: the input file, the language, the replace list or null and the expected output file.
     *
     * @return array<string, array{string, string, ?string, string}>
     */
    public static function fileSets(): array
    {
        $list = self::FILES . "fixing/user_OCRFixReplaceList.xml";

        return [
            "web download"           => ["fixing/web-errors.srt", "en", null, "fixing/web-errors.fixed.srt"],
            "PGS OCR, English"       => ["fixing/ocr-en.ocr.srt", "en", null, "fixing/ocr-en.fixed.srt"],
            "PGS OCR, German"        => ["fixing/ocr-de.ocr.srt", "de", null, "fixing/ocr-de.fixed.srt"],
            "PGS OCR, French"        => ["fixing/ocr-fr.ocr.srt", "fr", null, "fixing/ocr-fr.fixed.srt"],
            "PGS OCR, Spanish"       => ["fixing/ocr-es.ocr.srt", "es", null, "fixing/ocr-es.fixed.srt"],
            "PGS OCR, 1080p"         => ["fixing/text_1080p.ocr.srt", "en", null, "fixing/text_1080p.fixed.srt"],
            "VobSub OCR, user list"  => ["fixing/text-pal.ocr.srt", "en", $list, "fixing/text-pal.fixed.srt"],
        ];
    }


    #[DataProvider("fileSets")]
    public function testFixesTheFileAsTheGoldenFile(string $input, string $language, ?string $list, string $golden): void
    {
        $content  = file_get_contents(self::FILES . $input);
        $subtitle = Subtitle::fromStringAutoDetectFormat($content);
        $options  = new CommonErrorOptions(language: $language,
                                           replaceList: $list === null ? null : OcrReplaceList::fromSubtitleEditXml(file_get_contents($list)));
        $lineEnd  = str_contains($content, "\r\n") ? "\r\n" : "\n";

        $fixes = CommonErrorFixer::apply($subtitle, $options)->fixes;

        $this->assertStringEqualsFile(self::FILES . $golden,
                                      $subtitle->toString(Format::SubRip, new WriteOptions(lineEnding: LineEnding::from($lineEnd))));
        $this->assertNotEmpty($fixes);
        $this->assertSame([], CommonErrorFixer::apply(Subtitle::fromStringAutoDetectFormat(file_get_contents(self::FILES . $golden)), $options)->fixes);
    }


    public function testOptionalRulesFixTheFileAsTheGoldenFile(): void
    {
        $subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents(self::FILES . "fixing/optional-rules.srt"));
        $defaults = new CommonErrorOptions();
        $optional = array_filter(array_map(fn (CommonErrorRule $rule): string => $rule->value, CommonErrorRule::cases()),
                                 fn (string $name): bool => ($defaults->$name ?? null) === false);
        $options  = new CommonErrorOptions("en", ...array_fill_keys($optional, true));

        CommonErrorFixer::apply($subtitle, $options);

        $this->assertSame(["doubleApostrophes", "loneLowercaseI", "unneededPeriods", "dialogueOnOneLine", "musicNotes", "sentenceStartCase"], array_values($optional));
        $this->assertStringEqualsFile(self::FILES . "fixing/optional-rules.fixed.srt", $subtitle->toString(Format::SubRip));
        $this->assertSame([], CommonErrorFixer::apply(Subtitle::fromStringAutoDetectFormat(file_get_contents(self::FILES . "fixing/optional-rules.fixed.srt")),
                                                      $options)->fixes);
    }


    public function testListsEachFixOfTheWebFileWithTheTextBeforeAndAfter(): void
    {
        $subtitle = Subtitle::fromStringAutoDetectFormat(file_get_contents(self::FILES . "fixing/web-errors.srt"));

        $fixes = CommonErrorFixer::apply($subtitle, new CommonErrorOptions(language: "en"))->fixes;

        $this->assertSame([
            [0, "doubleSpaces"], [0, "spaceBeforePunctuation"], [1, "missingSpaceAfterPunctuation"], [2, "unbalancedTags"],
            [3, "emptyTags"], [4, "dialogueDashes"], [5, "ellipsis"], [7, "spaceBeforePunctuation"], [8, "unbalancedTags"],
            [9, "missingSpaceAfterPunctuation"], [10, "unbalancedTags"],
        ], array_map(fn (AppliedFix $fix): array => [$fix->cueIndex, $fix->rule->value], $fixes));
        $this->assertSame("Hello <i> there</i>, how was the trip ?", $fixes[0]->before);
        $this->assertSame("Hello <i>there</i>, how was the trip ?", $fixes[0]->after);
        $this->assertSame("Hello <i>there</i>, how was the trip ?", $fixes[1]->before);
        $this->assertSame("<i>The station was closed,\nso we walked.</i>", $fixes[3]->after);
    }


    public function testTheOcrFixturesMatchTheGeneratorAndTheEngineOutput(): void
    {
        foreach (array_keys(OCR_CUES) as $language) {
            $sup = file_get_contents(self::FILES . "fixing/ocr-$language.sup");
            $this->assertSame($sup, ocrFixture($language));

            $this->assertStringEqualsFile(self::FILES . "fixing/ocr-$language.ocr.srt", ocrWithErrors((new PgsParser())->parse($sup, new ReadOptions())));
        }
        foreach (imageFixtures() as $name => $subtitle) {
            $this->assertStringEqualsFile(self::FILES . "fixing/$name", ocrWithErrors($subtitle));
        }
    }


    public function testFixesTheOcrTextOfImageCuesAndKeepsTheImages(): void
    {
        $subtitle = (new PgsParser())->parse(file_get_contents(self::FILES . "fixing/ocr-fr.sup"), new ReadOptions());
        $this->assertSame([], CommonErrorFixer::apply($subtitle, new CommonErrorOptions(language: "fr"))->fixes);

        $subtitle->recognizeText(new GlyphOcrEngine(new GlyphOcrOptions(GlyphDatabase::latin(), lineContext: false)));
        $fixes = CommonErrorFixer::apply($subtitle, new CommonErrorOptions(language: "fr"))->fixes;

        $this->assertSame(["ll pleut. lls restent à la maison.", "Il pleut. Ils restent à la maison."],
                          [$fixes[0]->before, $fixes[0]->after]);
        $this->assertSame(["Il cherche l'h*tel.", "Il est o* ?"], $subtitle->getCues()[1]->getLines());
        $this->assertTrue(CueImage::isImageCue($subtitle->getCues()[0]));
    }


    public function testPreviewListsTheFixesAndChangesNothing(): void
    {
        $content  = file_get_contents(self::FILES . "fixing/web-errors.srt");
        $subtitle = Subtitle::fromStringAutoDetectFormat($content);
        $before   = $subtitle->toString(Format::SubRip);

        $preview = CommonErrorFixer::preview($subtitle, new CommonErrorOptions(language: "en"))->fixes;

        $this->assertSame($before, $subtitle->toString(Format::SubRip));
        $this->assertEquals(CommonErrorFixer::apply(Subtitle::fromStringAutoDetectFormat($content), new CommonErrorOptions(language: "en"))->fixes, $preview);
    }


    public function testAllFixesOffChangesNothing(): void
    {
        $content  = file_get_contents(self::FILES . "fixing/ocr-en.ocr.srt");
        $subtitle = Subtitle::fromStringAutoDetectFormat($content);

        $this->assertSame([], CommonErrorFixer::apply($subtitle, new CommonErrorOptions("en", ...self::ALL_OFF))->fixes);
        $this->assertSame($content, $subtitle->toString(Format::SubRip));
    }


    public function testTakesTheLanguageFromTheMetadataWhenTheOptionIsNull(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "lt is l."))->setMetadata(Subtitle::METADATA_LANGUAGE, "en-GB");

        CommonErrorFixer::apply($subtitle, new CommonErrorOptions());

        $this->assertSame("It is I.", $subtitle->getCues()[0]->getText());
        $this->assertSame([], self::fixLines(["lt is l."], new CommonErrorOptions(language: "nl"))[1]);
    }


    public function testDialogueOnOneLineIsOffByDefaultAndTakesTheDashStyle(): void
    {
        $this->assertSame(["- Hi. - Hello."], self::fixLines(["- Hi. - Hello."])[0]);
        $this->assertSame(["\u{2013} Hi.", "\u{2013} Hello."],
                          self::fixLines(["Hi. - Hello."], new CommonErrorOptions(dialogueDashStyle: DialogueDashStyle::EnDashSpace, dialogueOnOneLine: true))[0]);
    }


    public function testLoneLowercaseIIsOffByDefault(): void
    {
        $this->assertSame(["i think"], self::fixLines(["i think"], new CommonErrorOptions(language: "en"))[0]);
    }


    /**
     * Each case: the text of the cue before, the cue and the cue after the fix.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function sentenceStartCases(): array
    {
        return [
            "after a full stop"    => ["I'm home.", "where are you?", "Where are you?"],
            "after a question"     => ["Really?", "<i>yes.</i>", "<i>Yes.</i>"],
            "after a quote"        => ["\"Stop!\"", "fine.", "Fine."],
            "after an ellipsis"    => ["I was going to...", "stay home.", "stay home."],
            "after U+2026"         => ["I was going to\u{2026}", "stay home.", "stay home."],
            "after a comma"        => ["So I said,", "no way.", "no way."],
            "inner capital"        => ["Done.", "eBay is here.", "eBay is here."],
        ];
    }


    #[DataProvider("sentenceStartCases")]
    public function testSentenceStartCaseLooksAtTheCueBefore(string $previous, string $text, string $after): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, $previous))->addCue(new SubtitleCue(3, 4, $text));

        CommonErrorFixer::apply($subtitle, new CommonErrorOptions("en", ...[...self::ALL_OFF, "sentenceStartCase" => true]));

        $this->assertSame([$previous, $after], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()));
    }


    public function testSentenceStartCaseIsOffByDefault(): void
    {
        $this->assertSame(["hello."], self::fixLines(["hello."], new CommonErrorOptions(language: "en"))[0]);
    }


    public function testDoubleApostrophesIsOffByDefault(): void
    {
        $this->assertSame(["''Hi''"], self::fixLines(["''Hi''"], new CommonErrorOptions(language: "en"))[0]);
    }


    public function testUnneededPeriodsIsOffByDefault(): void
    {
        $this->assertSame(["Really?."], self::fixLines(["Really?."], new CommonErrorOptions(language: "en"))[0]);
    }


    public function testMusicNotesIsOffByDefault(): void
    {
        $this->assertSame(["# La la #"], self::fixLines(["# La la #"], new CommonErrorOptions(language: "en"))[0]);
    }


    public function testUnicodeEllipsisReplacesAllEllipses(): void
    {
        [$lines] = self::fixLines(["Well... no. . . yes....", "Wait\u{2026}"], new CommonErrorOptions(language: "en", unicodeEllipsis: true));

        $this->assertSame(["Well\u{2026} no\u{2026} yes\u{2026}", "Wait\u{2026}"], $lines);
    }


    public function testDialogueDashTakesTheStyleOfTheOption(): void
    {
        $this->assertSame(["-Hi.", "-Hello."], self::fixLines(["- Hi.", "-  Hello."], new CommonErrorOptions(dialogueDashStyle: DialogueDashStyle::Hyphen))[0]);
        $this->assertSame(["\u{2013} Hi."], self::fixLines(["-Hi."], new CommonErrorOptions(dialogueDashStyle: DialogueDashStyle::EnDashSpace))[0]);
    }


    public function testRemovesACueThatTheReplaceListEmptiesAndKeepsTheIndexOfTheFix(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Keep"))->addCue(new SubtitleCue(3, 4, "<i>Subtitles by Jane</i>"))
                                    ->addCue(new SubtitleCue(5, 6, "lt's me"));
        $options  = new CommonErrorOptions(language: "en", replaceList: new OcrReplaceList(wholeLines: ["Subtitles by Jane" => ""]));

        $fixes = CommonErrorFixer::apply($subtitle, $options)->fixes;

        $this->assertSame([[1, "replaceList", "<i></i>"], [1, "emptyTags", ""], [2, "ocrLowercaseL", "It's me"]],
                          array_map(fn (AppliedFix $fix): array => [$fix->cueIndex, $fix->rule->value, $fix->after], $fixes));
        $this->assertSame(["Keep", "It's me"], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()));
    }


    /**
     * Each case: the list, the lines before and the lines after.
     *
     * @return array<string, array{OcrReplaceList, list<string>, list<string>}>
     */
    public static function replaceListCases(): array
    {
        return [
            "whole word with punctuation"   => [new OcrReplaceList(wholeWords: ["Teh" => "The"]), ["\"Teh. Teh, Tehran"],
                                                ["\"The. The, Tehran"]],
            "whole word with its comma"     => [new OcrReplaceList(wholeWords: ["l,m" => "I'm"]), ["l,m in."], ["I'm in."]],
            "partial word, then whole word" => [new OcrReplaceList(wholeWords: ["will" => "will"], partialWordsAlways: ["II" => "ll"]),
                                                ["wiII it"], ["will it"]],
            "whole line"                    => [new OcrReplaceList(wholeLines: ["H ey." => "Hey."]), ["H ey.", "H ey. Hi"], ["Hey.", "H ey. Hi"]],
            "begin of a line and sentence"  => [new OcrReplaceList(beginLines: ["lgot " => "I got "]), ["- lgot it. lgot it"],
                                                ["- I got it. I got it"]],
            "end of the cue"                => [new OcrReplaceList(endLines: [" sin" => " sir."]), ["Yes, sin", "Yes, sin"],
                                                ["Yes, sin", "Yes, sir."]],
            "between word boundaries"       => [new OcrReplaceList(partialLines: ["aren '1'" => "aren't"]), ["We aren '1' late, aren '1'x"],
                                                ["We aren't late, aren '1'x"]],
            "always in a line"              => [new OcrReplaceList(partialLinesAlways: ["Apollo 1 3" => "Apollo 13"]), ["Apollo 1 3!"],
                                                ["Apollo 13!"]],
            "regular expression"            => [new OcrReplaceList(regularExpressions: ['/\b1 (know|will)\b/u' => 'I $1']), ["1 know 1 will"],
                                                ["I know I will"]],
        ];
    }


    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    #[DataProvider("replaceListCases")]
    public function testReplaceListAppliesEachSection(OcrReplaceList $list, array $before, array $after): void
    {
        $only = array_merge(self::ALL_OFF, ["replaceList" => $list]);

        $this->assertSame($after, self::fixLines($before, new CommonErrorOptions("en", ...$only))[0]);
    }


    public function testEndLinesAddNoPeriodWhenTheNextCueContinuesTheSentence(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "I asked mothen"))->addCue(new SubtitleCue(2.5, 4, "<i>and</i> she said"))
                                    ->addCue(new SubtitleCue(5, 6, "Ask mothen"));
        $options  = new CommonErrorOptions(language: "en", replaceList: new OcrReplaceList(endLines: [" mothen" => " mother."]));

        CommonErrorFixer::apply($subtitle, $options);

        $this->assertSame(["I asked mothen", "<i>and</i> she said", "Ask mother."],
                          array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()));
    }


    public function testSkipsImageCuesWithoutText(): void
    {
        $subtitle = (new Subtitle())->addCue((new CueImage("png", 0, 0, 1, 1, 1, 1))->toCue(new SubtitleCue(1, 2)));

        $this->assertSame([], CommonErrorFixer::apply($subtitle, new CommonErrorOptions())->fixes);
        $this->assertCount(1, $subtitle->getCues());
    }
}
