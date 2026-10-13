<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class EncodingTest extends TestCase
{
    private const DIR = __DIR__ . "/files/encoding/";

    private static function windowsOutput(): WriteOptions
    {
        return new WriteOptions(lineEnding: LineEnding::Crlf, bom: false);
    }


    public static function legacyFiles(): array
    {
        return [
            "French Windows-1252"  => [
                "french-windows-1252.srt", "Windows-1252", Format::SubRip,
                [1.0, 3.5, "Le café est fermé à midi."],
                [70.1, 72.9, "<i>Où êtes-vous, Noël ?</i>"],
            ],
            "Russian Windows-1251" => [
                "russian-windows-1251.srt", "Windows-1251", Format::SubRip,
                [2.0, 4.0, "Привет, как дела?"],
                [9.0, 11.5, "Ёлка стоит в углу."],
            ],
            "Japanese Shift_JIS"   => [
                "japanese-shift_jis.srt", "Shift_JIS", Format::SubRip,
                [1.2, 3.0, "こんにちは、元気ですか？"],
                [7.0, 9.8, "駅まで歩きましょう。"],
            ],
            "Korean CP949 SAMI"    => [
                "korean-cp949.smi", "CP949", Format::Sami,
                [1.0, 3.5, "안녕하세요.\n기차가 곧 도착합니다."],
                [7.0, 9.0, "<font color=\"#ffff00\">감사합니다.</font>"],
            ],
        ];
    }


    public function testUtf8FileStaysIntactWithAnotherSourceEncoding(): void
    {
        $options = new ReadOptions(encoding: TextEncoding::Windows1256);
        $utf8    = Subtitle::load(self::DIR . "arabic-utf-8.srt", Format::SubRip, $options);
        $legacy  = Subtitle::load(self::DIR . "arabic-windows-1256.srt", Format::SubRip, $options);

        $this->assertSame("مرحبا", $utf8->getCues()[0]->getText());
        $this->assertSame("شكرا جزيلا.", $utf8->getCues()[2]->getText());
        $this->assertSame($utf8->toString(Format::SubRip), $legacy->toString(Format::SubRip));
    }


    #[DataProvider("legacyFiles")]
    public function testLegacyFileParsesWithItsSourceEncoding(
        string $file, string $encoding, Format $format, array $first, array $last
    ): void {
        $raw      = file_get_contents(self::DIR . $file);
        $subtitle = Subtitle::fromString($raw, $format, new ReadOptions(encoding: $encoding));
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($last, [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame($raw, iconv("UTF-8", $encoding, $subtitle->toString($format, self::windowsOutput())));
    }


    #[DataProvider("legacyFiles")]
    public function testFormatDetectionWorksOnTheConvertedContent(string $file, string $encoding, Format $format): void
    {
        $detected = Subtitle::fromString(file_get_contents(self::DIR . $file), options: new ReadOptions(encoding: $encoding));

        $this->assertEquals(Subtitle::fromString(file_get_contents(self::DIR . $file), $format, new ReadOptions(encoding: $encoding)), $detected);
    }


    public function testCp949SamiIsDetected(): void
    {
        $raw      = file_get_contents(self::DIR . "korean-cp949.smi");
        $subtitle = Subtitle::fromString($raw, Format::Sami);

        $this->assertSame("기차 안내", $subtitle->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("똠방각하가 왔습니다.", $subtitle->getCues()[1]->getText());
        $this->assertSame("CP949", $subtitle->findSourceEncoding());
        $this->assertEquals($subtitle, Subtitle::fromString($raw, Format::Sami, new ReadOptions(encoding: "CP949")));
    }


    public function testKoreanCp949IsNotReadAsHebrewOrArabic(): void
    {
        $cues = [
            "안녕하세요, 오늘 날씨가 정말 좋네요.\n\n2\n00:00:03,000 --> 00:00:04,000\n우리 공원에 산책하러 갈까요?",
            "<i>노란</i> 글자",
        ];
        foreach ($cues as $text) {
            $subtitle = Subtitle::fromString(iconv("UTF-8", "CP949", "1\n00:00:01,000 --> 00:00:02,000\n$text\n"), Format::SubRip);

            $this->assertSame(explode("\n\n", $text)[0], $subtitle->getCues()[0]->getText());
            $this->assertSame("CP949", $subtitle->findSourceEncoding());
        }
    }


    public function testCp932KeepsTheBackslashesOfAss(): void
    {
        $subtitle = Subtitle::load(self::DIR . "japanese-cp932.ass", Format::Ass);

        $this->assertSame(["<i>このバスは空港へ行きますか？</i>", "時刻表はソファの上です。"], $subtitle->getCues()[1]->getLines());
        $this->assertSame("CP932", $subtitle->findSourceEncoding());
    }


    public function testShiftJisKeepsTheBackslashesOfAss(): void
    {
        foreach ([TextEncoding::ShiftJis, "SJIS", "shift_jis"] as $encoding) {
            $subtitle = Subtitle::load(self::DIR . "japanese-cp932.ass", Format::Ass, new ReadOptions(encoding: $encoding));

            $this->assertSame(["<i>このバスは空港へ行きますか？</i>", "時刻表はソファの上です。"], $subtitle->getCues()[1]->getLines());
            $this->assertSame($encoding instanceof TextEncoding ? $encoding->value : $encoding, $subtitle->findSourceEncoding());
        }
        $this->assertSame("a\\b~c", StringHelpers::convertToUtf8("a\\b~c", TextEncoding::ShiftJis));
    }


    public static function codePageFiles(): array
    {
        return [
            "Windows-1250" => ["czech-windows-1250.srt", "Windows-1250", "Děkuji, už musím jít domů."],
            "Windows-1251" => ["russian-windows-1251.srt", "Windows-1251", "Ёлка стоит в углу."],
            "Windows-1252" => ["french-windows-1252.srt", "Windows-1252", "<i>Où êtes-vous, Noël ?</i>"],
            "Windows-1253" => ["greek-windows-1253.srt", "Windows-1253", "Ευχαριστώ, πρέπει να φύγω."],
            "Windows-1254" => ["turkish-windows-1254.srt", "Windows-1254", "Teşekkürler, şimdi eve gitmeliyim."],
            "Windows-1256" => ["arabic-windows-1256.srt", "Windows-1256", "شكرا جزيلا."],
            "Windows-874"  => ["thai-windows-874.srt", "Windows-874", "ขอบคุณมาก ฉันต้องกลับบ้านแล้ว"],
            "TIS-620"      => ["thai-tis-620.srt", "Windows-874", "ฉันลืมกุญแจไว้ที่บ้านอีกแล้ว\nร้านกาแฟตรงหัวมุมเปิดแล้ว"],
            "Windows-1257" => ["estonian-windows-1257.srt", "Windows-1257", "Unustasin jälle võtmed koju!\nNurgal olev kohvik on juba avatud."],
            "ISO-8859-2"   => ["polish-iso-8859-2.srt", "ISO-8859-2", "Dziękuję, muszę już iść."],
            "ISO-8859-6"   => ["arabic-iso-8859-6.srt", "ISO-8859-6", "شكرا، يجب أن أعود إلى البيت."],
            "KOI8-R"       => ["russian-koi8-r.srt", "KOI8-R", "Спасибо, мне пора домой."],
            "GBK"          => ["chinese-gbk.srt", "GBK", "谢谢，我得回家了。"],
            "CP950"        => ["chinese-big5.srt", "CP950", "謝謝，我得回家了。"],
            "CP932"        => ["japanese-shift_jis.srt", "CP932", "駅まで歩きましょう。"],
            "EUC-JP"       => ["japanese-euc-jp.srt", "EUC-JP", "ありがとう、もう帰らなきゃ。"],
        ];
    }


    #[DataProvider("codePageFiles")]
    public function testCodePageIsDetectedWithoutSourceEncoding(string $file, string $encoding, string $lastText): void
    {
        $raw     = file_get_contents(self::DIR . $file);
        $strict  = Subtitle::fromString($raw, Format::SubRip);
        $lenient = Subtitle::load(self::DIR . $file, options: new ReadOptions(lenient: true));

        $this->assertSame($lastText, $strict->getCues()[2]->getText());
        $this->assertSame($encoding, $strict->findSourceEncoding());
        $this->assertSame([], $strict->getParseWarnings());
        $this->assertEquals($strict->getCues(), $lenient->getCues());
        $this->assertSame($encoding, $lenient->findSourceEncoding());
        $this->assertSame(
            [["The content is not UTF-8. Detection picked $encoding. Set ReadOptions::\$encoding if that is wrong.", null, ParseWarningAction::Repaired]],
            array_map(fn (ParseWarning $warning) => [$warning->message, $warning->lineNumber, $warning->action], $lenient->getParseWarnings())
        );
        $this->assertEquals($strict, Subtitle::fromString($raw, Format::SubRip, new ReadOptions(encoding: $encoding)));
    }


    public function testCodePageIsDetectedInAss(): void
    {
        $subtitle = Subtitle::load(__DIR__ . "/files/ass/own_windows_1252.ass", Format::Ass);

        $this->assertSame(["<v Hélène>Le café ouvre à sept heures."], $subtitle->getCues()[0]->getLines());
        $this->assertSame("Windows-1252", $subtitle->findSourceEncoding());
    }


    public function testSourceEncodingWinsOverDetection(): void
    {
        $raw      = file_get_contents(self::DIR . "french-windows-1252.srt");
        $subtitle = Subtitle::fromString($raw, Format::SubRip, new ReadOptions(encoding: TextEncoding::Windows1250, lenient: true));

        $this->assertSame("<i>Oů ętes-vous, Noël ?</i>", $subtitle->getCues()[2]->getText());
        $this->assertSame("Windows-1250", $subtitle->findSourceEncoding());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testUtf8AndBomInputIsNotDetected(): void
    {
        $utf8 = Subtitle::load(self::DIR . "arabic-utf-8.srt", Format::SubRip, new ReadOptions(lenient: true));
        $bom  = Subtitle::load(self::DIR . "notepad-utf-16le.vtt", Format::WebVtt, new ReadOptions(lenient: true));

        $this->assertSame(["UTF-8", []], [$utf8->findSourceEncoding(), $utf8->getParseWarnings()]);
        $this->assertSame(["UTF-16LE", []], [$bom->findSourceEncoding(), $bom->getParseWarnings()]);
        $this->assertSame("Ã©", Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000\nÃ©\n", Format::SubRip)->getCues()[0]->getText());
        $this->assertNull((new Subtitle())->findSourceEncoding());
    }


    public function testUtf8WithABrokenByteIsNotDetected(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000\nCafé crème, caf\xE9 noir\n", Format::SubRip);

        $this->assertSame("Café crème, caf\xE9 noir", $subtitle->getCues()[0]->getText());
        $this->assertSame("UTF-8", $subtitle->findSourceEncoding());
    }


    public static function filesWithOneBadByte(): array
    {
        return [
            "SubRip"    => ["one-bad-byte.srt", Format::SubRip],
            "WebVTT"    => ["one-bad-byte.vtt", Format::WebVtt],
            "SBV"       => ["one-bad-byte.sbv", Format::Sbv],
            "ASS"       => ["one-bad-byte.ass", Format::Ass],
            "SubViewer" => ["one-bad-byte.sub", Format::SubViewer],
            "CSV"       => ["one-bad-byte.csv", Format::Csv],
        ];
    }


    #[DataProvider("filesWithOneBadByte")]
    public function testTextFormatKeepsAnInvalidByteAndWarnsInLenientMode(string $file, Format $format): void
    {
        $path    = self::DIR . "invalid-utf-8/$file";
        $offset  = strpos(file_get_contents($path), "f\xE9 noir") + 1;
        $strict  = Subtitle::load($path, $format);
        $lenient = Subtitle::load($path, $format, new ReadOptions(lenient: true));

        foreach ([$strict, $lenient] as $subtitle) {
            $this->assertSame(["Le café ouvre à midi.", "Caf\xE9 noir, s'il vous plaît."],
                              array_map(fn (SubtitleCue $cue) => $cue->getText(), $subtitle->getCues()));
            $this->assertSame("UTF-8", $subtitle->findSourceEncoding());
        }
        $this->assertSame([], $strict->getParseWarnings());
        $this->assertSame(
            [["The content is not valid UTF-8. The first bad byte is at offset $offset. Set ReadOptions::\$encoding to the source encoding.", ParseWarningAction::Repaired]],
            array_map(fn (ParseWarning $warning) => [$warning->message, $warning->action], $lenient->getParseWarnings())
        );
    }


    public static function xmlFilesWithOneBadByte(): array
    {
        return [
            "SAMI" => ["one-bad-byte.smi", Format::Sami],
            "TTML" => ["one-bad-byte.ttml", Format::Ttml],
            "iTT"  => ["one-bad-byte.ttml", Format::Itt],
            "YouTube transcript XML" => ["one-bad-byte.srv1", Format::YouTubeTimedText],
        ];
    }


    #[DataProvider("xmlFilesWithOneBadByte")]
    public function testXmlParsersReadAnInvalidByteAsReplacementCharacterInLenientMode(string $file, Format $format): void
    {
        $path     = self::DIR . "invalid-utf-8/$file";
        $offset   = strpos(file_get_contents($path), "f\xE9 noir") + 1;
        $subtitle = Subtitle::load($path, $format, new ReadOptions(lenient: true));

        $this->assertSame(["Le café ouvre à midi.", "Caf\u{FFFD} noir, s'il vous plaît."],
                          array_map(fn (SubtitleCue $cue) => $cue->getText(), $subtitle->getCues()));
        $this->assertSame(
            [["The content is not valid UTF-8. The first bad byte is at offset $offset. Set ReadOptions::\$encoding to the source encoding. The parser read the bad bytes as U+FFFD.",
              ParseWarningAction::Repaired]],
            array_map(fn (ParseWarning $warning) => [$warning->message, $warning->action], $subtitle->getParseWarnings())
        );
    }


    #[DataProvider("xmlFilesWithOneBadByte")]
    public function testXmlParsersThrowForAnInvalidByteInStrictMode(string $file, Format $format): void
    {
        $path   = self::DIR . "invalid-utf-8/$file";
        $offset = strpos(file_get_contents($path), "f\xE9 noir") + 1;

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The content is not valid UTF-8. The first bad byte is at offset $offset. Set ReadOptions::\$encoding to the source encoding.");
        Subtitle::load($path, $format);
    }


    public function testYouTubeJsonReadsAnInvalidByteAsReplacementCharacterInBothModes(): void
    {
        $path    = self::DIR . "invalid-utf-8/one-bad-byte.json3";
        $offset  = strpos(file_get_contents($path), "f\xE9 noir") + 1;
        $strict  = Subtitle::load($path, Format::YouTubeTimedText);
        $lenient = Subtitle::load($path, Format::YouTubeTimedText, new ReadOptions(lenient: true));

        foreach ([$strict, $lenient] as $subtitle) {
            $this->assertSame(["Le café ouvre à midi.", "Caf\u{FFFD} noir, s'il vous plaît."],
                              array_map(fn (SubtitleCue $cue) => $cue->getText(), $subtitle->getCues()));
        }
        $this->assertSame([], $strict->getParseWarnings());
        $this->assertSame(
            [["The content is not valid UTF-8. The first bad byte is at offset $offset. Set ReadOptions::\$encoding to the source encoding.", ParseWarningAction::Repaired]],
            array_map(fn (ParseWarning $warning) => [$warning->message, $warning->action], $lenient->getParseWarnings())
        );
    }


    public function testTextInAnotherEncodingFamilyKeepsItsBytes(): void
    {
        $raw = iconv("UTF-8", "CP866", "1\r\n00:00:01,000 --> 00:00:02,000\r\nПривет, как дела?\r\n");

        $this->assertSame(Subtitle::fromString($raw, Format::SubRip)->getCues()[0]->getText(), explode("\r\n", $raw)[2]);
        $this->assertSame("UTF-8", Subtitle::fromString($raw, Format::SubRip)->findSourceEncoding());
    }


    public function testUtf16LeFileWithBomParsesWithoutSourceEncoding(): void
    {
        $raw      = file_get_contents(self::DIR . "notepad-utf-16le.vtt");
        $subtitle = Subtitle::fromString($raw);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([1.0, 3.0, "Grüße aus Köln!"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([7.0, 9.0, "<b>Ende</b>"], [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame("Saved with Notepad", $subtitle->getComments()[0]->text);
        $this->assertEquals($subtitle, Subtitle::fromString($raw, Format::WebVtt, new ReadOptions(encoding: "Windows-1252")));

        $output = $subtitle->toString(Format::WebVtt, self::windowsOutput());
        $again  = Subtitle::fromString("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", $output), Format::WebVtt);
        $this->assertSame($output, $again->toString(Format::WebVtt, self::windowsOutput()));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $cues),
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $again->getCues())
        );
    }


    public static function utf16FilesWithoutBom(): array
    {
        $files = [];
        foreach (["UTF-16LE", "UTF-16BE"] as $encoding) {
            foreach (["srt" => Format::SubRip, "vtt" => Format::WebVtt, "sbv" => Format::Sbv, "ass" => Format::Ass,
                         "smi" => Format::Sami, "ttml" => Format::Ttml, "sub" => Format::MicroDvd, "csv" => Format::Csv] as $extension => $format) {
                foreach ([false, true] as $lenient) {
                    $name         = strtolower($encoding) . "-no-bom.$extension";
                    $mode         = $lenient ? "lenient" : "strict";
                    $files["$name, $mode"] = [$name, $encoding, $format, $lenient];
                }
            }
        }

        return $files;
    }


    #[DataProvider("utf16FilesWithoutBom")]
    public function testUtf16FileWithoutBomParses(string $file, string $encoding, Format $format, bool $lenient): void
    {
        $path    = self::DIR . "utf-16-no-bom/$file";
        $options = new ReadOptions(lenient: $lenient);
        $loaded  = [Subtitle::load($path, $format, $options), Subtitle::fromString(file_get_contents($path), $format, $options)];
        $loaded[] = Subtitle::load($path, options: $options);
        if ($format !== Format::Csv) {
            $loaded[] = Subtitle::fromString(file_get_contents($path), options: $options);
        }

        foreach ($loaded as $subtitle) {
            $this->assertSame($format, $subtitle->findFormat());
            $this->assertSame(
                [[1.0, 3.0, "The café opens at nine."], [4.0, 6.5, "Grüße from the harbor."]],
                array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues())
            );
            $warnings = array_map(fn (ParseWarning $warning) => [$warning->message, $warning->action], $subtitle->getParseWarnings());
            $this->assertSame($lenient ? [["The content is $encoding without a BOM.", ParseWarningAction::Repaired]] : [], $warnings);
        }
    }


    public function testUtf16WithoutBomKeepsAnExplicitUtf16SourceEncoding(): void
    {
        $raw = file_get_contents(self::DIR . "utf-16-no-bom/utf-16le-no-bom.srt");

        $this->assertSame("The café opens at nine.", Subtitle::fromString($raw, Format::SubRip, new ReadOptions(encoding: "UTF-16LE"))->getCues()[0]->getText());
        $this->assertSame("The café opens at nine.", Subtitle::fromString($raw, Format::SubRip, new ReadOptions(encoding: "Windows-1252"))->getCues()[0]->getText());
    }


    public static function xmlFilesThatDeclareUtf16(): array
    {
        return [
            "TTML, UTF-8 bytes"           => ["utf-8-declared-utf-16.ttml", Format::Ttml, [1.0, 2.5, "Le café ouvre à midi."]],
            "TTML, UTF-16 LE with BOM"    => ["utf-16le-bom.ttml", Format::Ttml, [1.0, 2.5, "Le café ouvre à midi."]],
            "iTT, UTF-8 bytes"            => ["utf-8-declared-utf-16.ttml", Format::Itt, [1.0, 2.5, "Le café ouvre à midi."]],
            "YouTube, UTF-16 LE with BOM" => ["youtube-utf-16le-bom.srv1", Format::YouTubeTimedText, [0.5, 2.5, "café on the corner"]],
        ];
    }


    #[DataProvider("xmlFilesThatDeclareUtf16")]
    public function testXmlThatDeclaresUtf16Parses(string $file, Format $format, array $firstCue): void
    {
        $cue = Subtitle::fromString(file_get_contents(self::DIR . $file), $format)->getCues()[0];

        $this->assertSame($firstCue, [$cue->getStart(), $cue->getEnd(), $cue->getText()]);
    }


    public function testUtf16TtmlRoundTrips(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "utf-16le-bom.ttml"), Format::Ttml);
        $output   = $subtitle->toString(Format::Ttml);
        $again    = Subtitle::fromString("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", $output), Format::Ttml);

        $this->assertSame(["Le café ouvre à midi.", "À bientôt."], array_map(fn (SubtitleCue $cue) => $cue->getText(), $again->getCues()));
        $this->assertSame($output, $again->toString(Format::Ttml));
    }
}
