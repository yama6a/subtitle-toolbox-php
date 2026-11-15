<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;

class ControlCharactersTest extends TestCase
{
    /**
     * Each case holds the format and a file with "%s" where the text of the first cue goes, on line 4.
     *
     * @return array<string, array{Format, string}>
     */
    public static function formats(): array
    {
        return [
            "SubRip"    => [Format::SubRip, "\n1\n00:00:01,000 --> 00:00:02,000\n%s\n\n2\n00:00:03,000 --> 00:00:04,000\nLast cue.\n"],
            "WebVTT"    => [Format::WebVtt, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n%s\n\n00:00:03.000 --> 00:00:04.000\nLast cue.\n"],
            "SBV"       => [Format::Sbv, "\n\n0:00:01.000,0:00:02.000\n%s\n\n0:00:03.000,0:00:04.000\nLast cue.\n"],
            "SubViewer" => [Format::SubViewer, "[INFORMATION]\n[END INFORMATION]\n00:00:01.00,00:00:02.00\n%s\n\n00:00:03.00,00:00:04.00\nLast cue.\n"],
            "ASS"       => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n\n" .
                                         "Dialogue: 0,0:00:01.00,0:00:02.00,Default,,0,0,0,,%s\nDialogue: 0,0:00:03.00,0:00:04.00,Default,,0,0,0,,Last cue.\n"],
        ];
    }


    /**
     * @return array<string, array{Format, string, string}>
     */
    public static function controlCharacters(): array
    {
        $cases = [];
        foreach (self::formats() as $name => [$format, $file]) {
            foreach (["form feed" => "\f", "vertical tab" => "\x0B", "escape" => "\x1B", "DEL" => "\x7F", "NUL" => "\0"] as $character => $byte) {
                if ($format !== Format::WebVtt || $byte !== "\0") {
                    $cases["$name, $character"] = [$format, $file, $byte];
                }
            }
        }

        return $cases;
    }


    #[DataProvider("controlCharacters")]
    public function testStrictModeRemovesControlCharactersWithoutWarningAndThrowsForNul(Format $format, string $file, string $byte): void
    {
        if ($byte === "\0") {
            $this->expectException(ParsingException::class);
            $this->expectExceptionMessage("Line 4 has a NUL character. Check the encoding of the file. (line 4)");
        }

        $subtitle = Subtitle::fromString(sprintf($file, "The fer{$byte}ry leaves."), $format);

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testStrictStreamReaderThrowsForANul(): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, sprintf(self::formats()["SubRip"][1], "The fer\0ry leaves."));
        rewind($stream);

        try {
            iterator_to_array((new SubRipStreamReader())->read($stream));
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertSame(4, $exception->getLineNumber());
        }
    }


    #[DataProvider("controlCharacters")]
    public function testLenientModeRemovesControlCharactersWithOneWarning(Format $format, string $file, string $byte): void
    {
        $subtitle = Subtitle::fromString(sprintf($file, "The fer{$byte}ry {$byte}leaves."), $format, new ReadOptions(lenient: true));

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertEquals(
            [new ParseWarning("The file has control characters, the first on line 4. The parser removed them.", 4, null, [], ParseWarningAction::Repaired)],
            $subtitle->getParseWarnings()
        );
    }


    #[DataProvider("formats")]
    public function testFormatCharactersAndTabsStay(Format $format, string $file): void
    {
        $text     = "\u{200E}The\u{200D}ferry\u{200F} leaves.";
        $subtitle = Subtitle::fromString(sprintf($file, $text), $format, new ReadOptions(lenient: true));

        $this->assertSame($text, $subtitle->getCues()[0]->getText());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    #[DataProvider("formats")]
    public function testATrailingCtrlZIsIgnoredInBothModes(Format $format, string $file): void
    {
        foreach (["\x1A", "\x1A\x1A\r\n", "\r\n\x1A"] as $end) {
            foreach ([false, true] as $lenient) {
                $subtitle = Subtitle::fromString(sprintf($file, "The ferry leaves.") . $end, $format, new ReadOptions(lenient: $lenient));

                $this->assertSame(["The ferry leaves.", "Last cue."], array_map(fn ($cue): string => $cue->getText(), $subtitle->getCues()));
                $this->assertSame([], $subtitle->getParseWarnings());
            }
        }
    }


    public function testWebVttReplacesNulInBothModesWithoutWarning(): void
    {
        foreach ([false, true] as $lenient) {
            $subtitle = Subtitle::fromString("WEBVTT\n\n00:01.000 --> 00:02.000\nH\0i\n", Format::WebVtt, new ReadOptions(lenient: $lenient));

            $this->assertSame("H\u{FFFD}i", $subtitle->getCues()[0]->getText());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    /**
     * Each case holds the format and a file with "%s" where the escaped text of the first cue goes.
     *
     * @return array<string, array{Format, string}>
     */
    public static function escapingFormats(): array
    {
        return [
            "Whisper"      => [Format::Whisper, '{"segments":[{"start":1,"end":2,"text":"%s"}]}'],
            "library JSON" => [Format::Json, '{"version":1,"cues":[{"start":1.0,"end":2.0,"lines":["%s"]}]}'],
            "SAMI"         => [Format::Sami, "<SAMI><BODY><SYNC Start=1000><P>%s<SYNC Start=2000><P>&nbsp;</BODY></SAMI>"],
        ];
    }


    #[DataProvider("escapingFormats")]
    public function testEscapedControlCharactersGoWithOneWarningInLenientMode(Format $format, string $file): void
    {
        [$bell, $escape] = $format === Format::Sami ? ["&#7;", "&#x1B;"] : ["\\u0007", "\\u001b"];

        $subtitle = Subtitle::fromString(sprintf($file, "The fer{$bell}ry {$escape}leaves."), $format, new ReadOptions(lenient: true));

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertEquals(
            [new ParseWarning("The file has control characters. The parser removed them.", null, null, [], ParseWarningAction::Repaired)],
            $subtitle->getParseWarnings()
        );
    }


    #[DataProvider("escapingFormats")]
    public function testEscapedControlCharactersGoWithoutWarningInStrictMode(Format $format, string $file): void
    {
        $subtitle = Subtitle::fromString(sprintf($file, $format === Format::Sami ? "The fer&#127;ry leaves." : "The fer\\u007fry leaves."), $format);

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testAnEscapedNulThrowsInStrictModeAndGoesInLenientMode(): void
    {
        $file = self::escapingFormats()["Whisper"][1];

        $subtitle = Subtitle::fromString(sprintf($file, "The fer\\u0000ry leaves."), Format::Whisper, new ReadOptions(lenient: true));
        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertCount(1, $subtitle->getParseWarnings());

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file has an escaped NUL character.");
        Subtitle::fromString(sprintf($file, "The fer\\u0000ry leaves."), Format::Whisper);
    }


    public function testARawAndAnEscapedControlCharacterGiveOneWarning(): void
    {
        $file = sprintf(self::escapingFormats()["Whisper"][1], "The fer\\u0007ry leaves.") . "\x0B";

        $subtitle = Subtitle::fromString($file, Format::Whisper, new ReadOptions(lenient: true));

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertEquals(
            [new ParseWarning("The file has control characters, the first on line 1. The parser removed them.", 1, null, [], ParseWarningAction::Repaired)],
            $subtitle->getParseWarnings()
        );
    }


    public function testLibraryJsonFormatDataKeepsEscapedControlCharacters(): void
    {
        $file = '{"version":1,"formatData":{"x":{"spare":"\\u0000\\u0007"}},"cues":[{"start":1.0,"end":2.0,"lines":["a\\u0007b"],"formatData":{"x":{"b":"\\u0007"}}}]}';

        $subtitle = Subtitle::fromString($file, Format::Json, new ReadOptions(lenient: true));

        $this->assertSame("ab", $subtitle->getCues()[0]->getText());
        $this->assertSame(["spare" => "\0\x07"], $subtitle->findFormatData("x"));
        $this->assertSame(["b" => "\x07"], $subtitle->getCues()[0]->findFormatData("x"));
    }


    public function testSubRipOutputOfAnEscapedControlCharacterReadsBack(): void
    {
        $subtitle = Subtitle::fromString('{"segments":[{"start":1,"end":2,"text":"ab\\u0007c"}]}', Format::Whisper);

        $this->assertSame("abc", Subtitle::fromString($subtitle->toString(Format::SubRip), Format::SubRip)->getCues()[0]->getText());
    }


    /**
     * @return array<string, array{SubRipStreamReader|WebVttStreamReader, Format, string}>
     */
    public static function streams(): array
    {
        $formats = self::formats();
        $file    = fn (Format $format): string => sprintf($formats[$format === Format::SubRip ? "SubRip" : "WebVTT"][1], "The\f fer\0ry\x7F.") . "\r\n\x1A";

        return [
            "SubRip" => [new SubRipStreamReader(new ReadOptions(lenient: true)), Format::SubRip, $file(Format::SubRip)],
            "WebVTT" => [new WebVttStreamReader(new ReadOptions(lenient: true)), Format::WebVtt, $file(Format::WebVtt)],
        ];
    }


    #[DataProvider("streams")]
    public function testStreamReadersGiveTheCuesAndWarningsOfTheParser(SubRipStreamReader|WebVttStreamReader $reader, Format $format, string $content): void
    {
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));
        $stream   = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        $this->assertEquals($subtitle->getCues(), iterator_to_array($reader->read($stream)));
        $this->assertEquals($subtitle->getParseWarnings(), $reader->getWarnings());
        $this->assertCount(1, $reader->getWarnings());
    }
}
