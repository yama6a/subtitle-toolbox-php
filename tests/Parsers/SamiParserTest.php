<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Validation\ValidationRules;

class SamiParserTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/sami/real/";


    public static function realFiles(): array
    {
        return [
            "mantas-done smi" => [
                "mantas_smi.smi", 2, "en-US",
                [137.4, 140.4, ["Passengers, we're now", "arriving at the central station."]],
                [3740.5, 3742.5, ["Thank you, conductor."]],
            ],
            "mantas-done formatted" => [
                "mantas_smi_formatted.smi", 3, "en-US",
                [9.209, 12.312, ["( bell ringing )"]],
                [17.35, 22.35, ["we watch the fields go by"]],
            ],
            "multi language" => [
                "multi_language.smi", 3, "ko-KR",
                [1.0, 4.2, ["빵집은 아침 여섯 시에 문을 엽니다."]],
                [8.0, 10.5, ["<b>기차</b>가 곧 출발합니다."]],
            ],
            "pysubs2 source id" => [
                "pysubs2_source_id.smi", 9, "en-US-CC",
                [0.0, 0.01, ["Weather Desk"]],
                [73.0, 78.0, ["End of:", "Weather Report for Tuesday"]],
            ],
            "subsrt sample" => [
                "subsrt_sample.smi", 6, "kr-KR",
                [185.113, 187.035, ["500번 열차가 도착합니다,"]],
                [335.453, 342.46, ["<font color=\"#ffff00\"><b>출발! 출발!</b></font>"]],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, string $language, array $first, array $last): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Sami);
        $cues     = $subtitle->getCues();

        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
        $this->assertCount($cueCount, $cues);
        $this->assertSame($language, $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertSame($last, [end($cues)->getStart(), end($cues)->getEnd(), end($cues)->getLines()]);
    }


    public function testLanguageClassOptionPicksTheClass(): void
    {
        $subtitle = (new SamiParser())->parse(file_get_contents(self::DIR . "multi_language.smi"), new ReadOptions(formats: [new SamiReadOptions("ENCC")]));
        $cues     = $subtitle->getCues();

        $this->assertSame("en-US", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame("ENCC", $subtitle->findFormatData("sami")["class"]);
        $this->assertSame([
            [1.0, 4.2, ["The bakery opens at six in the morning."]],
            [5.0, 8.0, ["It is <font color=\"#ffff00\">raining</font> today.", "Take an umbrella."]],
            [8.0, 10.5, ["<i>The train</i> leaves soon."]],
            [11.0, 13.0, ["Bread &amp; coffee cost less than 5 euros."]],
        ], array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues));
    }


    public function testLanguageClassIsCaseInsensitive(): void
    {
        $subtitle = (new SamiParser())->parse(file_get_contents(self::DIR . "multi_language.smi"), new ReadOptions(formats: [new SamiReadOptions("frcc")]));

        $this->assertSame("fr-FR", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame("FRCC", $subtitle->findFormatData("sami")["class"]);
        $this->assertSame(["Il <font color=\"#ffff00\">pleut</font> aujourd'hui.", "Prenez un parapluie."], $subtitle->getCues()[1]->getLines());
    }


    public function testUnknownLanguageClassThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The SAMI file has no class DECC.");

        (new SamiParser())->parse(file_get_contents(self::DIR . "multi_language.smi"), new ReadOptions(formats: [new SamiReadOptions("DECC")]));
    }


    public function testStyleBlockAndHeaderGoToFormatData(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "mantas_smi.smi"), Format::Sami);
        $data     = $subtitle->findFormatData("sami");

        $this->assertSame("file", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("ENUSCC", $data["class"]);
        $this->assertSame("\n  Metrics {time:ms;}\n  Spec {MSFT:1.0;}\n", $data["samiParam"]);
        $this->assertStringStartsWith("\n<!--\n  P { font-family: Arial;", $data["style"]);
        $this->assertStringEndsWith(".ENUSCC { name: English; lang: en-US ; SAMIType: CC ; }\n-->\n", $data["style"]);
    }


    public function testSourceIdParagraphIsKeptInCueFormatData(): void
    {
        $cues = Subtitle::fromString(file_get_contents(self::DIR . "pysubs2_source_id.smi"), Format::Sami)->getCues();

        $this->assertSame([
            "paragraphs" => [
                ["attributes" => ["id" => "Source"], "html" => "End of:"],
                ["attributes" => [], "html" => "Weather Report for Tuesday"],
            ],
            "lines"      => ["End of:", "Weather Report for Tuesday"],
        ], end($cues)->findFormatData("sami"));
    }


    public function testInlineTagsBecomeCoreMarkup(): void
    {
        $cues = $this->parseBody(
            "<SYNC Start=0><P Class=ENCC>The <B>train <I>leaves <U>at <S>nine</S></U></I></B>\n" .
            "<SYNC Start=1000><P Class=ENCC><STRIKE>old</STRIKE> <font color=red>red</font> <font color=\"00FF00\">green</font>\n" .
            "<SYNC Start=2000><P Class=ENCC><font face=Arial color=#ABC>short</font> <font face=Arial>face</font> <span>span</span>\n" .
            "<SYNC Start=3000><P Class=ENCC>&nbsp;\n"
        );

        $this->assertSame([
            ["The <b>train <i>leaves <u>at <s>nine</s></u></i></b>"],
            ["<s>old</s> <font color=\"#ff0000\">red</font> <font color=\"#00ff00\">green</font>"],
            ["short face span"],
        ], array_map(fn ($cue): array => $cue->getLines(), $cues));
    }


    public function testBreakStartsALineAndSourceLineBreaksAreSpaces(): void
    {
        $cues = $this->parseBody("<SYNC Start=0><P Class=ENCC>\n  one\n  two<BR>three<br/>&nbsp;<br>&lt;four&gt; &amp; &#039;five&#039;\n<SYNC Start=1500><P Class=ENCC>&nbsp;\n");

        $this->assertSame(["one two", "three", "&lt;four&gt; &amp; 'five'"], $cues[0]->getLines());
        $this->assertSame(1.5, $cues[0]->getEnd());
    }


    public function testSyncWithoutParagraphEndsTheCueOfEveryClass(): void
    {
        $raw = "<SAMI><BODY>\n" .
               "<SYNC Start=1000><P Class=ENCC>one<P Class=FRCC>un\n" .
               "<SYNC Start=2000>&nbsp;\n" .
               "<SYNC Start=3000><P Class=ENCC>two\n" .
               "<SYNC Start=3500><P Class=FRCC>deux\n" .
               "<SYNC Start=4000><P Class=ENCC>&nbsp;\n" .
               "</BODY></SAMI>";

        $english = (new SamiParser())->parse($raw, new ReadOptions(lastCueDuration: 1, formats: [new SamiReadOptions("ENCC")]))->getCues();
        $french  = (new SamiParser())->parse($raw, new ReadOptions(lastCueDuration: 1, formats: [new SamiReadOptions("FRCC")]))->getCues();

        $this->assertSame([[1.0, 2.0], [3.0, 4.0]], array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd()], $english));
        $this->assertSame([[1.0, 2.0], [3.5, 4.5]], array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd()], $french));
    }


    public function testFileWithoutClassesReadsEveryParagraph(): void
    {
        $subtitle = Subtitle::fromString("<sami><body><sync start=500><p>one<sync start=900><p>two<sync start=1200><p>&nbsp;</body></sami>", Format::Sami);

        $this->assertNull($subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame([], $subtitle->findFormatData("sami"));
        $this->assertSame([[0.5, 0.9, "one"], [0.9, 1.2, "two"]], array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues()));
    }


    public function testDefaultClassIsTheFirstParagraphClassWithoutStyleBlock(): void
    {
        $subtitle = Subtitle::fromString("<SAMI><BODY><SYNC Start=0><P Class=FRCC>un<P Class=ENCC>one\n<SYNC Start=900><P Class=ENCC>two</BODY></SAMI>", Format::Sami);

        $this->assertSame(["class" => "FRCC"], $subtitle->findFormatData("sami"));
        $this->assertSame(["un"], array_map(fn ($cue): string => $cue->getText(), $subtitle->getCues()));
    }


    public function testParagraphClassThatNoStyleRuleDefinesIsRead(): void
    {
        $content  = file_get_contents(self::DIR . "undefined_class.smi");
        $expected = [[1.0, 3.5, ["Hello"]], [4.0, 6.0, ["The ferry leaves at noon."]]];
        $describe = fn (Subtitle $subtitle): array => array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues());

        $strict = (new SamiParser())->parse($content);
        $this->assertSame($expected, $describe($strict));
        $this->assertSame("ENCC", $strict->findFormatData("sami")["class"]);
        $this->assertNull($strict->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame([], $strict->getParseWarnings());

        $lenient  = (new SamiParser())->parse($content, new ReadOptions(lenient: true));
        $warnings = $lenient->getParseWarnings();
        $this->assertSame($expected, $describe($lenient));
        $this->assertCount(1, $warnings);
        $this->assertSame("No <P> class matches a class of the STYLE block. The parser read the class ENCC.", $warnings[0]->message);
        $this->assertSame(12, $warnings[0]->lineNumber);
        $this->assertSame(["<SYNC Start=1000><P Class=ENCC>Hello"], $warnings[0]->block);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
    }


    public function testFirstStyleClassThatAParagraphUsesIsRead(): void
    {
        $subtitle = (new SamiParser())->parse(file_get_contents(self::DIR . "unused_first_class.smi"));

        $this->assertSame(
            [[1.0, 3.5, ["The museum opens at nine."]], [4.0, 6.0, ["Tickets are sold at the door."]]],
            array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues())
        );
        $this->assertSame("ENUSCC", $subtitle->findFormatData("sami")["class"]);
        $this->assertSame("en-US", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testNegativeStartIsReadAsZeroAndBareNbspEndsTheCue(): void
    {
        $content  = file_get_contents(self::DIR . "negative_start_bare_nbsp.smi");
        $expected = [[0.0, 1.0, ["The tide is out."]], [2.0, 4.5, ["Boats wait on the sand."]]];
        $describe = fn (Subtitle $subtitle): array => array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues());

        $strict = (new SamiParser())->parse($content);
        $this->assertSame($expected, $describe($strict));
        $this->assertSame([], $strict->getParseWarnings());

        $lenient  = (new SamiParser())->parse($content, new ReadOptions(lenient: true));
        $warnings = $lenient->getParseWarnings();
        $this->assertSame($expected, $describe($lenient));
        $this->assertCount(1, $warnings);
        $this->assertSame("SYNC tag 1 has a negative Start. The parser read it as 0.", $warnings[0]->message);
        $this->assertSame(10, $warnings[0]->lineNumber);
        $this->assertSame(0, $warnings[0]->blockIndex);
        $this->assertSame(["<SYNC Start=-200><P Class=ENUSCC>The tide is out."], $warnings[0]->block);
        $this->assertSame(ParseWarningAction::Repaired, $warnings[0]->action);
    }


    public function testSyncsAreSortedByStart(): void
    {
        $cues = $this->parseBody("<SYNC Start=3000><P Class=ENCC>two\n<SYNC Start=1000><P Class=ENCC>one\n<SYNC Start=4000><P Class=ENCC>&nbsp;\n");

        $this->assertSame([[1.0, 3.0, "one"], [3.0, 4.0, "two"]], array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $cues));
    }


    public function testLastCueDurationOption(): void
    {
        $cues = (new SamiParser())->parse("<SAMI><BODY><SYNC Start=1000><P Class=ENCC>one</BODY></SAMI>", new ReadOptions(lastCueDuration: 2.5))->getCues();

        $this->assertSame(3.5, $cues[0]->getEnd());
    }


    public function testSyncWithoutStartThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("SYNC tag 2 has no valid Start attribute.");

        $this->parseBody("<SYNC Start=0><P Class=ENCC>one\n<SYNC End=1000><P Class=ENCC>two\n");
    }


    public function testInputThatIsNotUtf8Throws(): void
    {
        $content = "<SAMI>\n<BODY>\n<SYNC Start=0><P Class=KRCC>\xbf\xc0\xb4\xc3\n</BODY>\n</SAMI>\n";

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The content is not valid UTF-8. The first bad byte is at offset 42. Pass --encoding.");
        (new SamiParser())->parse($content, new ReadOptions());
    }


    public function testBrokenHtmlRaisesNoWarningAndKeepsTheLibxmlSetting(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            $cues = $this->parseBody("<SYNC Start=0><P Class=ENCC><b>open <i>tags</p></div></font>\n<SYNC Start=900><P Class=ENCC>&nbsp;\n");

            $this->assertFalse(libxml_use_internal_errors());
            $this->assertSame(["<b>open <i>tags</i></b>"], $cues[0]->getLines());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }


    private function parseBody(string $body): array
    {
        return Subtitle::fromString("<SAMI>\n<BODY>\n$body</BODY>\n</SAMI>\n", Format::Sami)->getCues();
    }


    public function testReadsTheBodyAfterAHeadOfMoreThanOneMegabyte(): void
    {
        $content = file_get_contents(__DIR__ . "/../files/sami/real/subsrt_sample.smi");
        $large   = str_replace("</HEAD>", "<!-- " . str_repeat("Padding before the body. ", 50000) . "-->\n</HEAD>", $content);
        $read    = fn (string $sami): array => array_map(
            fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()],
            Subtitle::fromString($sami, Format::Sami)->getCues()
        );

        $this->assertGreaterThan(1_000_000, strlen($large));
        $this->assertSame($read($content), $read($large));
    }
}
