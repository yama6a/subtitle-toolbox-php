<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\HearingImpaired\HearingImpairedReport;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRules;

class HearingImpairedRemoverTest extends \PHPUnit\Framework\TestCase
{
    private const FILES = __DIR__ . "/files/hearing-impaired/";


    private static function apply(Subtitle $subtitle, ?HearingImpairedOptions $options = null): Subtitle
    {
        HearingImpairedRemover::apply($subtitle, $options ?? new HearingImpairedOptions());

        return $subtitle;
    }


    private function remove(string $text, ?HearingImpairedOptions $options = null): array
    {
        return TestSubtitles::texts(self::apply(TestSubtitles::fromTexts([$text]), $options));
    }


    public function testRealSubRipFileParsesAndRoundTrips(): void
    {
        $content  = file_get_contents(self::FILES . "own_sdh.srt");
        $subtitle = Subtitle::fromString($content, Format::SubRip);
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(14, $cues);
        $this->assertSame([1.0, 3.0, "[TRAIN WHISTLE BLOWS]"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([39.5, 42.0, "- # The wheels go round #\n- It stopped raining. (birds chirping)"],
                          [$cues[13]->getStart(), $cues[13]->getEnd(), $cues[13]->getText()]);
        $this->assertSame($content, $subtitle->toString(Format::SubRip, new WriteOptions(lineEnding: LineEnding::Crlf, bom: true)));
    }


    public function testRealSubRipFileWithDefaultOptions(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_sdh.srt"), Format::SubRip);

        $this->assertEquals(new HearingImpairedReport(6, 3),
                            HearingImpairedRemover::apply($subtitle, new HearingImpairedOptions()));
        $this->assertSame(file_get_contents(self::FILES . "own_sdh_removed.srt"),
                          $subtitle->toString(Format::SubRip, new WriteOptions(lineEnding: LineEnding::Crlf, bom: true)));
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testRealSubRipFileWithAllOptions(): void
    {
        $subtitle = self::apply(Subtitle::fromString(file_get_contents(self::FILES . "own_sdh.srt"), Format::SubRip),
                                new HearingImpairedOptions(
                                    speakerLabelsUpperCaseOnly: false,
                                    customBrackets: [["{", "}"]],
                                    lyrics: true,
                                ));

        $this->assertSame(file_get_contents(self::FILES . "own_sdh_removed_all_options.srt"),
                          $subtitle->toString(Format::SubRip));
    }


    public function testRealWebVttFileParsesAndRoundTrips(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_sdh.vtt"), Format::WebVtt);
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(8, $cues);
        $this->assertSame([1.0, 3.0, "[wind howling]"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([21.5, 24.0, "<i>[door opens]\n(footsteps)</i>"],
                          [$cues[7]->getStart(), $cues[7]->getEnd(), $cues[7]->getText()]);

        $again = Subtitle::fromString($subtitle->toString(Format::WebVtt), Format::WebVtt);
        $this->assertSame(TestSubtitles::texts($subtitle), TestSubtitles::texts($again));
        $this->assertEquals($subtitle->getComments(), $again->getComments());
    }


    public function testRealWebVttFileWithDefaultOptions(): void
    {
        $subtitle = self::apply(Subtitle::fromString(file_get_contents(self::FILES . "own_sdh.vtt"), Format::WebVtt));

        $this->assertSame(file_get_contents(self::FILES . "own_sdh_removed.vtt"), $subtitle->toString(Format::WebVtt));
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testExamplesOfTheIssue(): void
    {
        $this->assertSame([], $this->remove("[DOOR SLAMS]"));
        $this->assertSame(["You came back."], $this->remove("(laughs) You came back."));
        $this->assertSame(["Where were you?"], $this->remove("JOHN: Where were you?"));
        $this->assertSame(["- No!\n- Yes."], $this->remove("- [gasps] No!\n- MARY: Yes."));
        $this->assertSame(["# Never gonna stop #"], $this->remove("# Never gonna stop #"));
        $this->assertSame([], $this->remove("# Never gonna stop #", new HearingImpairedOptions(lyrics: true)));
        $this->assertSame(["<i>Over here.</i>"], $this->remove("<i>(whispering) Over here.</i>"));
    }


    public function testRemovesSpacesAroundAnAnnotation(): void
    {
        $this->assertSame(["Wait for it."], $this->remove("Wait (sighs) for it."));
        $this->assertSame(["Wait for it."], $this->remove("Wait for it (sighs)."));
        $this->assertSame(["Wait."], $this->remove("Wait. [door closes]"));
        $this->assertSame(["- No!"], $this->remove("-[gasps] No!"));
    }


    public function testBracketsAcrossTagsAndLines(): void
    {
        $this->assertSame(["Hello."], $this->remove("<font color=\"#ff0000\">[BELL]</font> Hello."));
        $this->assertSame(["Hello."], $this->remove("[BELL <i>RINGS</i>] Hello."));
        $this->assertSame(["Hi."], $this->remove("(woman\nspeaking) Hi."));
        $this->assertSame(["Hi."], $this->remove("(MARY): Hi."));
        $this->assertSame(["Hi."], $this->remove("[[echo]] Hi."));
        $this->assertSame(["Hi (there"], $this->remove("Hi (there"));
    }


    public function testKeepsTagsAndEntities(): void
    {
        $this->assertSame(["<i>Hello</i>"], $this->remove("<i>[sighs]\nHello</i>"));
        $this->assertSame(["<i>Hello</i>"], $this->remove("<i>Hello\n[sighs]</i>"));
        $this->assertSame(["<v Anna>Good morning."], $this->remove("<v Anna>(coughs) Good morning."));
        $this->assertSame(["Fish &amp; &lt;3"], $this->remove("Fish &amp; (chips) &lt;3"));
        $this->assertSame(["Hi &amp; bye"], $this->remove("Hi &amp; bye"));
        $this->assertSame(["- Hi.\n- Bye."], $this->remove("- Hi.\n- (laughs) Bye."));
    }


    public function testSpeakerLabels(): void
    {
        $this->assertSame(["Run!"], $this->remove("MAN 2: Run!"));
        $this->assertSame(["Sit."], $this->remove("DR. O'NEIL: Sit."));
        $this->assertSame(["Hi."], $this->remove("ÉMILE: Hi."));
        $this->assertSame(["Note: this stays."], $this->remove("Note: this stays."));
        $this->assertSame(["THE 8:15 TRAIN"], $this->remove("THE 8:15 TRAIN"));
        $this->assertSame(["Where?"], $this->remove("JOHN:\nWhere?"));
        $this->assertSame(["Hi."], $this->remove("Baker: Hi.", new HearingImpairedOptions(speakerLabelsUpperCaseOnly: false)));
        $this->assertSame(["JOHN: Hi."], $this->remove("JOHN: Hi.", new HearingImpairedOptions(speakerLabels: false)));
    }


    public function testMusicLines(): void
    {
        $this->assertSame([], $this->remove("♪ ♪"));
        $this->assertSame([], $this->remove("- #"));
        $this->assertSame(["Hi."], $this->remove("♫\nHi."));
        $this->assertSame(["♪ la la ♪", "#1 fan"], TestSubtitles::texts(self::apply(TestSubtitles::fromTexts(["♪ la la ♪", "#1 fan"]))));
        $this->assertSame(["#1 fan"], TestSubtitles::texts(self::apply(TestSubtitles::fromTexts(["♪ la la ♪", "#1 fan"]),
                                                                       new HearingImpairedOptions(lyrics: true))));
        $this->assertSame(["Hi."], $this->remove("♪ The rain\nkeeps falling ♪\nHi.", new HearingImpairedOptions(lyrics: true)));
        $this->assertSame(["♪ ♪"], $this->remove("♪ ♪", new HearingImpairedOptions(musicOnlyLines: false)));
    }


    public function testDialogueDashes(): void
    {
        $this->assertSame(["Is it open?"], $this->remove("- Is it open?\n- (laughs)"));
        $this->assertSame(["Yes?"], $this->remove("- JOHN:\n- Yes?"));
        $this->assertSame(["- A.\n- B."], $this->remove("- A.\n- [sighs]\n- B."));
        $this->assertSame(["- Is it open?"], $this->remove("- Is it open? (laughs)"));
    }


    public function testCustomBrackets(): void
    {
        $this->assertSame(["Hi {laughs}"], $this->remove("Hi {laughs}"));
        $this->assertSame(["{\\an8}Hi"], $this->remove("{\\an8}Hi {laughs}", new HearingImpairedOptions(customBrackets: [["{", "}"]])));
        $this->assertSame(["Hi"], $this->remove("Hi *sighs*", new HearingImpairedOptions(customBrackets: [["*", "*"]])));
        $this->assertSame(["[A] (B)"], $this->remove("[A] (B)", new HearingImpairedOptions(squareBrackets: false, parentheses: false)));
    }


    public function testRejectsInvalidCustomBrackets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HearingImpairedOptions(customBrackets: [["{", ""]]);
    }


    public function testRemovesEmptyCuesAndKeepsComments(): void
    {
        $subtitle = TestSubtitles::fromTexts(["first", "<i>[MUSIC]</i>", "[DOOR]\n(SIGHS)", "last"]);
        $subtitle->addCue(new SubtitleCue(10, 11, ""));
        $subtitle->addComment("before music", 1)->addComment("before door", 2)->addComment("before last", 3);

        $this->assertEquals(new HearingImpairedReport(3, 2),
                            HearingImpairedRemover::apply($subtitle, new HearingImpairedOptions()));

        $this->assertSame(["first", "last", ""], TestSubtitles::texts($subtitle));
        $this->assertEquals([
            new Comment("before music", 1),
            new Comment("before door", 1),
            new Comment("before last", 1),
        ], $subtitle->getComments());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testKeepsInvalidUtf8(): void
    {
        $this->assertSame(["\xE9t\xE9 [x]"], $this->remove("\xE9t\xE9 [x]"));
    }


    public function testIsAnnotation(): void
    {
        $options = new HearingImpairedOptions();

        $this->assertTrue(HearingImpairedRemover::isAnnotation("[DOOR SLAMS]", $options));
        $this->assertTrue(HearingImpairedRemover::isAnnotation("JOHN: Hi.", $options));
        $this->assertTrue(HearingImpairedRemover::isAnnotation("♪ ♪", $options));
        $this->assertFalse(HearingImpairedRemover::isAnnotation("Note: this stays.", $options));
        $this->assertFalse(HearingImpairedRemover::isAnnotation("♪ la la ♪", $options));
        $this->assertTrue(HearingImpairedRemover::isAnnotation("♪ la la ♪", new HearingImpairedOptions(lyrics: true)));
    }


    public function testRemoveFromTextReturnsTheRemainingText(): void
    {
        $options = new HearingImpairedOptions();

        $this->assertSame("Hi.", HearingImpairedRemover::removeFromText("JOHN: Hi.", $options));
        $this->assertSame("", HearingImpairedRemover::removeFromText("[DOOR SLAMS]", $options));
        $this->assertSame("Note: this stays.", HearingImpairedRemover::removeFromText("Note: this stays.", $options));
    }


    public function testHearingImpairedOptionsBuildsNoSubtitle(): void
    {
        $this->assertSame(["__construct"], array_map(
            fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(HearingImpairedOptions::class))->getMethods()
        ));
    }


    public function testNoBreakSpaceCountsAsSpaceInTheDashAndBlankTagRules(): void
    {
        $options = new HearingImpairedOptions();

        $this->assertSame("Is it open?", HearingImpairedRemover::removeFromText("\u{00A0}- Is it open?\n- (laughs)", $options));
        $this->assertSame("Hi", HearingImpairedRemover::removeFromText("Hi [x]<i>\u{00A0}</i>", $options));
        $this->assertSame("A.", HearingImpairedRemover::removeFromText("\u{2000}-\u{2000}A.\n- [x]", $options));
    }
}
