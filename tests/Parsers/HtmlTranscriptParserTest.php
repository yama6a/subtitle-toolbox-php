<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

class HtmlTranscriptParserTest extends TestCase
{
    public function testReadsAFullDocumentWithParagraphsUpToTheNextTime(): void
    {
        $html = "<!DOCTYPE html>\r\n<html>\r\n<head><title>Episode 4</title></head>\r\n<body>\r\n" .
                "  <cite>Anna:</cite>\r\n  <time>0:05</time>\r\n  <p class=\"line\">Hello<br>there.</p>\r\n  <p>Come <b>in</b>.</p>\r\n" .
                "  <time>1:02:03.5</time>\r\n  <P>&lt;3 &amp; bye</P>\r\n</body>\r\n</html>\r\n";

        $this->assertSame([
            [5.0, 3723.5, ["<v Anna>Hello", "there.", "Come in."]],
            [3723.5, 3728.5, ["&lt;3 &amp; bye"]],
        ], TestSubtitles::describeLines((new HtmlTranscriptParser())->parse($html, new ReadOptions())));
    }


    public function testACiteNamesOnlyTheNextParagraph(): void
    {
        $html = "<cite>Dr. O'Neil :</cite><time>0:00</time><p>Hi.</p><time>0:02</time><p>Bye.</p>";

        $this->assertSame([[0.0, 2.0, ["<v Dr. O'Neil>Hi."]], [2.0, 5.0, ["Bye."]]],
                          TestSubtitles::describeLines((new HtmlTranscriptParser())->parse($html, new ReadOptions(lastCueDuration: 3))));
    }


    public function testThrowsForAParagraphWithoutTime(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The paragraph has no <time>. (line 2)");

        (new HtmlTranscriptParser())->parse("<time>0:00</time><p>Hi.</p>\n<cite>Ben:</cite>\n<p>Bye.</p>", new ReadOptions());
    }


    public function testThrowsForABadTime(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"soon\" is not valid. (line 3)");

        (new HtmlTranscriptParser())->parse("<cite>Ben:</cite>\n\n<time>soon</time>\n<p>Bye.</p>", new ReadOptions());
    }


    public function testSkipsABadParagraphInLenientMode(): void
    {
        $subtitle = (new HtmlTranscriptParser())->parse("<time>0:00</time><p>Hi.</p>\n<time>0:61</time><p>Oops.</p>\n<time>0:04</time><p>Bye.</p>", new ReadOptions(lenient: true));

        $this->assertSame([[0.0, 4.0, ["Hi."]], [4.0, 9.0, ["Bye."]]], TestSubtitles::describeLines($subtitle));
        $this->assertSame([["The time \"0:61\" is not valid.", 2, 1, ParseWarningAction::Skipped]], array_map(
            fn (ParseWarning $warning): array => [$warning->message, $warning->lineNumber, $warning->blockIndex, $warning->action],
            $subtitle->getParseWarnings()
        ));
    }
}
