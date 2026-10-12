<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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


    public function testSubViewerStrictModeReadsABadTimeLineAsText(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.00,00:00:03.00\nOne\n\n00:00:04.00,00:00:0x.00\nTwo\n", new ReadOptions());

        $this->assertSame(["One", "00:00:04.00,00:00:0x.00", "Two"], $subtitle->getCues()[0]->getLines());
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

        $strict = (new SubViewerParser())->parse($content, new ReadOptions());
        $this->assertSame(["First", $line, "Hello"], $strict->getCues()[0]->getLines());
    }


    public function testSubViewer1SkipsABrokenHeaderLine(): void
    {
        $subtitle = (new SubViewerParser())->parse("[TITLE]\nMarket\nbroken\n" . SubViewerParser::START_SCRIPT . "\n[00:00:01]\nHello\n[00:00:02]\n", new ReadOptions(lenient: true));

        $this->assertSame("Hello", $subtitle->getCues()[0]->getText());
        $this->assertSame([[3, 0, self::SKIPPED, "The line \"broken\" is not a SubViewer 1 header tag."]], TestSubtitles::warnings($subtitle));
    }
}
