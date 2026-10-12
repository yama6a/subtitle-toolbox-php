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
use SubtitleToolbox\Tests\Support\TestSubtitles;

/**
 * Checks the special cases of lenient mode in the SubViewer parser.
 */
class LenientSubViewerTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/lenient/";

    private const SKIPPED  = ParseWarningAction::Skipped;
    private const REPAIRED = ParseWarningAction::Repaired;


    public function testSubViewerKeepsTheTextOfTheSkippedCueInTheWarning(): void
    {
        $subtitle = (new SubViewerParser())->parse(file_get_contents(self::DIR . "bad_time_line.sub"), new ReadOptions(lenient: true));

        $this->assertSame(["00:00:04.00,00:00:0x.00", "Apples are cheap today."], $subtitle->getParseWarnings()[1]->block);
    }


    public function testSubViewerStrictModeThrowsForATimingLineWithABadTime(): void
    {
        $content = "[INFORMATION]\n[TITLE]Market day\n[END INFORMATION]\n[SUBTITLE]\n" .
                   "00:00:01.00,00:00:03.00\nThe market opens at eight.\n\n" .
                   "00:00:04.00,00:00:0x.00\nApples are cheap today.\n\n" .
                   "00:00:07.00,00:00:09.00\nThe stalls close at noon.\n";

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The timing line \"00:00:04.00,00:00:0x.00\" is not in the form hh:mm:ss.ff,hh:mm:ss.ff. (line 8)");

        (new SubViewerParser())->parse($content, new ReadOptions());
    }


    public function testSubViewerStrictModeKeepsATextLineWithACommaAndATime(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.00,00:00:03.00\nWe meet at 10:30, at the gate.\n", new ReadOptions());

        $this->assertSame(["We meet at 10:30, at the gate."], $subtitle->getCues()[0]->getLines());
    }


    public static function looseSubViewerTimingLines(): array
    {
        return [
            "4 fraction digits" => ["00:00:01.5000,00:00:03.0000"],
            "no fraction"       => ["00:00:01.5,00:00:03"],
            "1-digit fields"    => ["0:0:1.50,0:0:3.00"],
        ];
    }


    #[DataProvider("looseSubViewerTimingLines")]
    public function testSubViewerReadsALooseTimingLineOnlyInLenientMode(string $line): void
    {
        $content = "00:00:00.00,00:00:01.00\nFirst\n\n$line\nHello\n";

        $subtitle = (new SubViewerParser())->parse($content, new ReadOptions(lenient: true));
        $this->assertSame([[0.0, 1.0, "First"], [1.5, 3.0, "Hello"]], TestSubtitles::describe($subtitle));
        $this->assertSame([self::REPAIRED], array_map(fn (ParseWarning $warning) => $warning->action, $subtitle->getParseWarnings()));
        $this->assertStringContainsString("00:00:01.50,00:00:03.00\nHello", $subtitle->toString(Format::SubViewer));

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The timing line \"$line\" is not in the form hh:mm:ss.ff,hh:mm:ss.ff. (line 4)");
        (new SubViewerParser())->parse($content, new ReadOptions());
    }


    public function testSubViewer1SkipsABrokenHeaderLine(): void
    {
        $subtitle = (new SubViewerParser())->parse("[TITLE]\nMarket\nbroken\n" . SubViewerParser::START_SCRIPT . "\n[00:00:01]\nHello\n[00:00:02]\n", new ReadOptions(lenient: true));

        $this->assertSame("Hello", $subtitle->getCues()[0]->getText());
        $this->assertSame([[3, 0, self::SKIPPED, "The line \"broken\" is not a SubViewer 1 header tag."]], TestSubtitles::warnings($subtitle));
    }
}
