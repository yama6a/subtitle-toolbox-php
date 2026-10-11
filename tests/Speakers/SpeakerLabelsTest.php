<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\WriteOptions;

class SpeakerLabelsTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/speakers/";

    private static function noBom(): WriteOptions
    {
        return new WriteOptions(bom: false);
    }


    /**
     * @return list<list<string>>
     */
    private static function lines(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => $cue->getLines(), array_values($subtitle->getCues()));
    }


    private static function apply(Subtitle $subtitle, SpeakerLabelOptions $options): Subtitle
    {
        SpeakerLabels::apply($subtitle, $options);

        return $subtitle;
    }


    private static function voices(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . "voices.vtt"), Format::WebVtt);
    }


    private static function whisper(string $path): Subtitle
    {
        $parser = new WhisperJsonParser();

        return $parser->parse(file_get_contents(self::FILES . $path), new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true)]));
    }


    public function testVoicesFileParsesAndRoundTrips(): void
    {
        $subtitle = self::voices();
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(7, $cues);
        $this->assertSame([1.0, 3.2, "<v Anna>Is the ferry on time today?"],
                          [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([15.2, 17.4, "<v Clara>We meet again next week.</v>"],
                          [$cues[6]->getStart(), $cues[6]->getEnd(), $cues[6]->getText()]);
        $this->assertSame(file_get_contents(self::FILES . "voices.vtt"), $subtitle->toString(Format::WebVtt, self::noBom()));
    }


    public function testSdhLabelsFileParsesAndRoundTrips(): void
    {
        $content  = file_get_contents(self::FILES . "sdh_labels.srt");
        $subtitle = Subtitle::fromString($content, Format::SubRip);
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(7, $cues);
        $this->assertSame([1.0, 3.0, "JOHN: The gate opens at nine."], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([14.2, 16.0, "MARY: Next time,\nCHLOÉ: drives."], [$cues[6]->getStart(), $cues[6]->getEnd(), $cues[6]->getText()]);
        $this->assertSame($content, $subtitle->toString(Format::SubRip, new WriteOptions(lineEnding: LineEnding::Crlf, bom: false)));
    }


    public function testListCountsTheCuesOfEachSpeakerInOrderOfAppearance(): void
    {
        $this->assertSame(["Anna" => 3, "Ben" => 3, "Clara" => 2], SpeakerLabels::list(self::voices()));
        $this->assertSame([], SpeakerLabels::list(TestSubtitles::fromTexts(["No speaker here."])));
        $this->assertSame(["Ben" => 1], SpeakerLabels::list(TestSubtitles::fromTexts(["<v Ben>Hi.\n<v Ben>Again."])));
    }


    public function testListKeepsTheDecodedNames(): void
    {
        $this->assertSame(["Tom & Jerry" => 1, "O'Neil" => 1],
                          SpeakerLabels::list(TestSubtitles::fromTexts(["<v Tom &amp; Jerry>Hi. <v O&#39;Neil>Hello."])));
    }


    public function testRenameChangesOnlyTheGivenSpeakers(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<v SPEAKER_00>Where?", "<v.loud SPEAKER_01>Home.</v>", "<v SPEAKER_02>Here."]);

        $report = SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(rename: [
            "SPEAKER_00" => "Anna",
            "SPEAKER_01" => "O'Neil & Son",
            "SPEAKER_02" => "Sam \"Ace\" Reed",
            "SPEAKER_03" => "Nobody",
        ]));

        $this->assertEquals(new SpeakerLabelReport(3), $report);
        $this->assertSame([["<v Anna>Where?"], ["<v.loud O'Neil &amp; Son>Home.</v>"], ["<v Sam \"Ace\" Reed>Here."]],
                          self::lines($subtitle));
    }


    public function testToPrefixFile(): void
    {
        $subtitle = self::voices();
        $report   = SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Prefix));

        $this->assertEquals(new SpeakerLabelReport(6), $report);
        $this->assertSame(file_get_contents(self::FILES . "voices_prefix.srt"), $subtitle->toString(Format::SubRip, self::noBom()));
    }


    /**
     * @return array<string, array{string, bool, string, list<string>}>
     */
    public static function prefixCases(): array
    {
        return [
            "upper case"                => ["<v Anna>Where were you?", true, ": ", ["ANNA: Where were you?"]],
            "name as it is"             => ["<v Anna>Where were you?", false, ": ", ["Anna: Where were you?"]],
            "own separator"             => ["<v Anna>Where?", true, " - ", ["ANNA - Where?"]],
            "UTF-8 name"                => ["<v Zoë>Ja.", true, ": ", ["ZOË: Ja."]],
            "escaped name"              => ["<v Tom &amp; Jerry>Hi.", true, ": ", ["TOM &amp; JERRY: Hi."]],
            "speaker over two lines"    => ["<v Anna>Where are\nyou going?", true, ": ", ["ANNA: Where are", "you going?"]],
            "change in the middle"      => ["<v Anna>Where? <v Ben>Home.", true, ": ", ["ANNA: Where?", "BEN: Home."]],
            "change inside italics"     => ["<v Anna><i>Where? <v Ben>Home.</i>", true, ": ", ["ANNA: <i>Where?</i>", "BEN: <i>Home.</i>"]],
            "text after the end tag"    => ["<v Anna>Hi</v> there", true, ": ", ["ANNA: Hi", "there"]],
            "word timestamps"           => ["<v Anna><00:00:01.000>Hi <00:00:01.500>there", true, ": ",
                                            ["ANNA: <00:00:01.000>Hi <00:00:01.500>there"]],
            "same speaker twice"        => ["<v Anna>Hi.\n<v Anna>Again.", true, ": ", ["ANNA: Hi.", "Again."]],
            "no speaker"                => ["Hi <b>there</b>.", true, ": ", ["Hi <b>there</b>."]],
        ];
    }


    /**
     * @param list<string> $expected
     */
    #[DataProvider("prefixCases")]
    public function testToPrefix(string $text, bool $upperCase, string $separator, array $expected): void
    {
        $this->assertSame([$expected], self::lines(self::apply(TestSubtitles::fromTexts([$text]), new SpeakerLabelOptions(
            to: SpeakerStyle::Prefix,
            writeUpperCase: $upperCase,
            separator: $separator,
        ))));
    }


    public function testToDialogueDashesFile(): void
    {
        $subtitle = self::voices();

        SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes));
        $this->assertSame(file_get_contents(self::FILES . "voices_dashes.srt"), $subtitle->toString(Format::SubRip, self::noBom()));
    }


    /**
     * @return array<string, array{string, DialogueDashStyle, list<string>}>
     */
    public static function dashCases(): array
    {
        return [
            "two lines"            => ["<v Anna>Where?\n<v Ben>Home.", DialogueDashStyle::HyphenSpace, ["- Where?", "- Home."]],
            "one line"             => ["<v Anna>Where? <v Ben>Home.", DialogueDashStyle::HyphenSpace, ["- Where?", "- Home."]],
            "one speaker"          => ["<v Anna>Where are\nyou going?", DialogueDashStyle::HyphenSpace, ["Where are", "you going?"]],
            "text without speaker" => ["Where?\n<v Ben>Home.", DialogueDashStyle::HyphenSpace, ["- Where?", "- Home."]],
            "dash already there"   => ["<v Anna>- Where?\n<v Ben>Home.", DialogueDashStyle::HyphenSpace, ["- Where?", "- Home."]],
            "own dash"             => ["<v Anna>Where?\n<v Ben>Home.", DialogueDashStyle::Hyphen, ["-Where?", "-Home."]],
            "three speakers"       => ["<v Anna>One.\n<v Ben>Two.\n<v Clara>Three.", DialogueDashStyle::HyphenSpace, ["- One.", "- Two.", "- Three."]],
        ];
    }


    /**
     * @param list<string> $expected
     */
    #[DataProvider("dashCases")]
    public function testToDialogueDashes(string $text, DialogueDashStyle $dash, array $expected): void
    {
        $this->assertSame([$expected], self::lines(self::apply(TestSubtitles::fromTexts([$text]), new SpeakerLabelOptions(to: SpeakerStyle::DialogueDashes, dialogueDashStyle: $dash))));
    }


    public function testToColoursFile(): void
    {
        $subtitle = self::voices();

        SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Colors));
        $this->assertSame(file_get_contents(self::FILES . "voices_colors.srt"), $subtitle->toString(Format::SubRip, self::noBom()));
    }


    public function testToColoursUsesTheBbcOrderAndStartsAgainAfterTheLastColour(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<v A>1", "<v B>2", "<v C>3", "<v D>4", "<v E>5", "<v A>6 <v B>7"]);

        $this->assertSame([
            ['<font color="#ffffff">1</font>'],
            ['<font color="#ffff00">2</font>'],
            ['<font color="#00ffff">3</font>'],
            ['<font color="#00ff00">4</font>'],
            ['<font color="#ffffff">5</font>'],
            ['<font color="#ffffff">6</font>', '<font color="#ffff00">7</font>'],
        ], self::lines(self::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Colors))));
    }


    public function testToColoursWithOwnColours(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<v A>Hi.\nthere", "No speaker.", "<v B>Bye."]);

        $this->assertSame([['<font color="#ff0000">Hi.</font>', '<font color="#ff0000">there</font>'], ["No speaker."],
                           ['<font color="#00ff00">Bye.</font>']],
                          self::lines(self::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Colors, colors: ["#FF0000", "#00ff00"]))));
    }


    /**
     * @return array<string, array{array}>
     */
    public static function invalidColours(): array
    {
        return [
            "empty list"   => [[]],
            "colour name"  => [["yellow"]],
            "short colour" => [["#ff0"]],
            "no string"    => [[0xffff00]],
        ];
    }


    #[DataProvider("invalidColours")]
    public function testOptionsRejectInvalidColours(array $colours): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpeakerLabelOptions(to: SpeakerStyle::Colors, colors: $colours);
    }


    public function testFromPrefixFile(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "sdh_labels.srt"), Format::SubRip);

        SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(readPrefixes: true));
        $this->assertSame(file_get_contents(self::FILES . "sdh_labels_voices.vtt"), $subtitle->toString(Format::WebVtt, self::noBom()));
    }


    /**
     * @return array<string, array{string, bool, list<string>}>
     */
    public static function fromPrefixCases(): array
    {
        return [
            "upper case label"        => ["JOHN: Hi.", true, ["<v John>Hi."]],
            "title, dot and quote"    => ["DR. O'NEIL: Yes.", true, ["<v Dr. O'Neil>Yes."]],
            "number"                  => ["MAN 2: Run!", true, ["<v Man 2>Run!"]],
            "UTF-8 label"             => ["ÉMILE: Salut.", true, ["<v Émile>Salut."]],
            "mixed case label"        => ["Note: this stays.", true, ["Note: this stays."]],
            "mixed case allowed"      => ["Baker: Hi.", false, ["<v Baker>Hi."]],
            "dialogue dashes"         => ["- JOHN: Hi.\n- MARY: Bye.", true, ["<v John>Hi.", "<v Mary>Bye."]],
            "inside italics"          => ["<i>JOHN: Hi.</i>", true, ["<v John><i>Hi.</i>"]],
            "label on its own line"   => ["JOHN:\nHi there.", true, ["<v John>Hi there."]],
            "label is the whole cue"  => ["JOHN:", true, ["JOHN:"]],
            "no space after colon"    => ["JOHN:Hi.", true, ["JOHN:Hi."]],
            "label later in the line" => ["Then JOHN: Hi.", true, ["Then JOHN: Hi."]],
            "time in the line"        => ["At 10:30 we go.", true, ["At 10:30 we go."]],
            "escaped text"            => ["JOHN: Fish &amp; chips.", true, ["<v John>Fish &amp; chips."]],
        ];
    }


    /**
     * @param list<string> $expected
     */
    #[DataProvider("fromPrefixCases")]
    public function testFromPrefix(string $text, bool $upperCaseOnly, array $expected): void
    {
        $this->assertSame([$expected], self::lines(self::apply(TestSubtitles::fromTexts([$text]), new SpeakerLabelOptions(
            readPrefixes: true,
            readUpperCaseOnly: $upperCaseOnly,
        ))));
    }


    public function testFromPrefixAndToPrefixRoundTrip(): void
    {
        $subtitle = TestSubtitles::fromTexts(["JOHN: Hi.", "DR. O'NEIL: Yes.\nMARY: No."]);

        SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(readPrefixes: true, to: SpeakerStyle::Prefix));

        $this->assertSame([["JOHN: Hi."], ["DR. O'NEIL: Yes.", "MARY: No."]], self::lines($subtitle));
    }


    public function testVoicesSurviveWebVttAndTtml(): void
    {
        $subtitle = self::apply(TestSubtitles::fromTexts(["DR. O'NEIL: Yes.\nMARY: No."]), new SpeakerLabelOptions(readPrefixes: true));

        $vtt  = Subtitle::fromString($subtitle->toString(Format::WebVtt), Format::WebVtt);
        $ttml = Subtitle::fromString($subtitle->toString(Format::Ttml), Format::Ttml);

        $this->assertSame(["Dr. O'Neil" => 1, "Mary" => 1], SpeakerLabels::list($vtt));
        $this->assertSame(["Dr. O'Neil" => 1, "Mary" => 1], SpeakerLabels::list($ttml));
    }


    public function testCuesWithoutSpeakersStayUnchanged(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>Hi</i>  there"]);
        $before   = self::lines($subtitle);

        foreach (SpeakerStyle::cases() as $style) {
            $this->assertEquals(new SpeakerLabelReport(0), SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: $style)));
        }

        $this->assertSame($before, self::lines($subtitle));
    }


    public function testWhisperSpeakerVoicesAreOffByDefault(): void
    {
        $subtitle = (new WhisperJsonParser())->parse(file_get_contents(self::FILES . "whisper_cpp_diarize.json"), new ReadOptions());

        $this->assertSame([["Did you lock the back door?"], ["Yes, and the window."], ["Both of us checked it twice."], ["Then we can go."]],
                          self::lines($subtitle));
        $this->assertSame([], SpeakerLabels::list($subtitle));
    }


    public function testWhisperCppDiarizeFile(): void
    {
        $subtitle = self::whisper("whisper_cpp_diarize.json");
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(4, $cues);
        $this->assertSame([0.0, 2.4, "<v 0>Did you lock the back door?"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([6.3, 8.0, "<v 0>Then we can go."], [$cues[3]->getStart(), $cues[3]->getEnd(), $cues[3]->getText()]);
        $this->assertSame("?", $cues[2]->findFormatData("whisper")["speaker"]);
        $this->assertSame([0 => 2, 1 => 1, "?" => 1], SpeakerLabels::list($subtitle));
        $this->assertSame(file_get_contents(self::FILES . "whisper_cpp_diarize.vtt"), $subtitle->toString(Format::WebVtt, self::noBom()));
    }


    public function testWhisperXDiarizeFileWithRenameAndPrefix(): void
    {
        $subtitle = self::whisper("../whisper/real/whisperx_diarize.json");

        $this->assertSame("<v SPEAKER_00>The market opens on Saturday.", $subtitle->getCues()[0]->getText());
        $this->assertSame("SPEAKER_00", $subtitle->getCues()[0]->findFormatData("whisper")["speaker"]);

        SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(rename: ["SPEAKER_00" => "Anna", "SPEAKER_01" => "Ben"]));
        $this->assertSame(["Anna" => 1, "Ben" => 2], SpeakerLabels::list($subtitle));

        SpeakerLabels::apply($subtitle, new SpeakerLabelOptions(to: SpeakerStyle::Prefix));
        $this->assertSame(file_get_contents(self::FILES . "whisperx_diarize_prefix.srt"), $subtitle->toString(Format::SubRip, self::noBom()));
    }


    public function testWhisperSpeakerVoicesWithWordTimestampsAndEscaping(): void
    {
        $json   = '{"segments": [{"start": 0, "end": 2, "text": " Hi.", "speaker": "O\'Neil & <Son>",' .
                  ' "words": [{"word": "Hi.", "start": 0.5, "end": 1}]}, {"start": 2, "end": 3, "text": "Bye.", "speaker": " "},' .
                  ' {"start": 3, "end": 4, "text": "Yes.", "speaker": 5}]}';
        $parser = new WhisperJsonParser();

        $this->assertSame([["<v O'Neil &amp; &lt;Son&gt;><00:00:00.500>Hi."], ["Bye."], ["Yes."]], self::lines($parser->parse($json, new ReadOptions(formats: [new TranscriptReadOptions(speakerVoices: true, wordTimestamps: true)]))));
    }


    public function testStyleHasStringValues(): void
    {
        $this->assertSame(["prefix", "dialogueDashes", "colors"], array_map(fn (SpeakerStyle $style): string => $style->value, SpeakerStyle::cases()));
        $this->assertSame(SpeakerStyle::DialogueDashes, SpeakerStyle::from("dialogueDashes"));
    }
}
