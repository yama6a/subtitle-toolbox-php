<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Tests\Support\TestSubtitles;

/**
 * Checks the special cases of lenient mode in the WebVTT parser.
 */
class LenientWebVttTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/lenient/";


    public function testWebVttWithoutTheSignatureAndWithASubRipTimingLineStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");
        (new WebVttParser())->parse("1\n00:00:01,000 --> 00:00:02,000\ntext\n", new ReadOptions(lenient: true));
    }


    public function testWebVttWithoutTheSignatureAndWithoutCuesStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");
        (new WebVttParser())->parse("Hello\n\nWorld\n", new ReadOptions(lenient: true));
    }


    public function testWebVttWithADamagedSignatureKeepsTheSkippedLinesInTheWarning(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "damaged_signature.vtt"), new ReadOptions(lenient: true));

        $this->assertSame(["WEBVTS", "Kind: captions"], $subtitle->getParseWarnings()[0]->block);
        $this->assertEquals([new Comment("closing time", 1)], $subtitle->getComments());
        $this->assertSame(["line" => "0", "alignment" => 8], $subtitle->getCues()[0]->findFormatData("vtt"));
    }


    public function testWebVttWithoutTheSignatureKeepsTheCueIdentifiers(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "missing_signature.vtt"), new ReadOptions(lenient: true));

        $this->assertSame(["1", "2"], array_map(fn (SubtitleCue $cue): ?string => $cue->findIdentifier(), $subtitle->getCues()));
    }


    public function testWebVttStreamReaderWithoutTheSignatureStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file does not start with WEBVTT.");
        iterator_to_array((new WebVttStreamReader(new ReadOptions(lenient: true)))->read(self::DIR . "bad_timestamp.srt"));
    }


    public function testWebVttLineNumbersCountTheEmptyLinesBeforeTheSignature(): void
    {
        $subtitle = (new WebVttParser())->parse("\n\nWEBVTT\n\nbroken\n\n00:00:01.000 --> 00:00:02.000\ntext\n", new ReadOptions(lenient: true));

        $this->assertSame(5, $subtitle->getParseWarnings()[0]->lineNumber);
    }


    public function testWebVttKeepsANoteAfterAnEmptyLineApartFromTheCue(): void
    {
        $content  = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\nThe ferry leaves.\n\nNOTE checked\n\nSTYLE\n::cue { color: red }\n";
        $subtitle = (new WebVttParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals([[1, 2, "The ferry leaves."]], TestSubtitles::describe($subtitle));
        $this->assertSame("checked", $subtitle->getComments()[0]->text);
        $this->assertCount(2, $subtitle->getParseWarnings());
    }


    public function testWebVttKeepsTheHeaderLinesBeforeTheSplit(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "missing_empty_line.vtt"), new ReadOptions(lenient: true));

        $this->assertSame(["headerLines" => ["Kind: captions", "Language: en"]], $subtitle->findFormatData("vtt"));
    }


    public function testWebVttJoinedFilesSkipTheSecondHeaderAndTheStyleBlockWithWarnings(): void
    {
        $subtitle = (new WebVttParser())->parse(file_get_contents(self::DIR . "joined_files.vtt"), new ReadOptions(lenient: true));

        $this->assertSame([["One"], ["Two"]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $subtitle->getCues()));
        $warnings = $subtitle->getParseWarnings();
        $this->assertSame([6, 9], array_map(fn ($warning): ?int => $warning->lineNumber, $warnings));
        $this->assertSame(["WEBVTT", "Kind: captions"], $warnings[0]->block);
        $this->assertSame(["STYLE", "::cue { color: red }"], $warnings[1]->block);
    }


    public function testWebVttJoinedFilesStillThrowInStrictMode(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #2 is not a WebVTT cue, comment, style or region. The line is \"WEBVTT\".");
        (new WebVttParser())->parse(file_get_contents(self::DIR . "joined_files.vtt"));
    }


    public function testWebVttStreamReaderSkipsTheSecondHeaderOfJoinedFiles(): void
    {
        $reader = new WebVttStreamReader(new ReadOptions(lenient: true));
        $cues   = iterator_to_array($reader->read(self::DIR . "joined_files.vtt"), false);

        $this->assertSame([["One"], ["Two"]], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $cues));
        $this->assertSame([6, 9], array_map(fn ($warning): ?int => $warning->lineNumber, $reader->getWarnings()));
    }
}
