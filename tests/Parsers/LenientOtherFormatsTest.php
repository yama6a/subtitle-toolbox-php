<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Comment;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Tests\Support\TestSubtitles;

/**
 * Checks the special cases of lenient mode in the formats with 1 or 2 such cases.
 */
class LenientOtherFormatsTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/lenient/";

    private const SKIPPED  = ParseWarningAction::Skipped;
    private const REPAIRED = ParseWarningAction::Repaired;


    public static function looseAssTimes(): array
    {
        return [
            "4 fraction digits" => ["0:00:01.5000", 1.5],
            "no fraction"       => ["0:00:01", 1.0],
            "comma"             => ["0:00:01,50", 1.5],
            "colon"             => ["0:00:01:50", 1.5],
        ];
    }


    #[DataProvider("looseAssTimes")]
    public function testAssReadsALooseTimeOnlyInLenientMode(string $time, float $seconds): void
    {
        $content = "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" .
                   "Dialogue: 0,$time,0:00:03.00,Default,,0,0,0,,Hello\n";

        $subtitle = (new AssParser())->parse($content, new ReadOptions(lenient: true));
        $this->assertSame([[$seconds, 3.0, "Hello"]], TestSubtitles::describe($subtitle));
        $this->assertSame([self::REPAIRED], array_map(fn (ParseWarning $warning) => $warning->action, $subtitle->getParseWarnings()));
        $this->assertStringContainsString(sprintf("Dialogue: 0,0:00:%05.2f,0:00:03.00,", $seconds), $subtitle->toString(Format::Ass));

        $this->expectException(ParsingException::class);
        (new AssParser())->parse($content, new ReadOptions());
    }


    public function testMpSubSkipsABadFormatLineAndReadsTheTimesAsSeconds(): void
    {
        $subtitle = (new MpSubParser())->parse("FORMAT=PAL\n\n1 2\nHello\n", new ReadOptions(lenient: true));

        $this->assertEquals([[1, 3, "Hello"]], TestSubtitles::describe($subtitle));
        $this->assertSame([[1, 0, self::SKIPPED, "The FORMAT value \"PAL\" is not known."]], TestSubtitles::warnings($subtitle));
    }


    public function testSamiWarningHoldsTheLinesOfTheSkippedSync(): void
    {
        $subtitle = (new SamiParser())->parse(file_get_contents(self::DIR . "bad_sync_start.smi"), new ReadOptions(lenient: true));

        $this->assertSame(["<SYNC Start=><P Class=ENCC>Cut the grass."], $subtitle->getParseWarnings()[0]->block);
    }


    public function testEbuStlStrictModeKeepsACueWithABadTimeCode(): void
    {
        $content = substr(file_get_contents(self::DIR . "bad_time_code.stl"), 0, EbuStl::GSI_BLOCK_SIZE + 3 * EbuStl::TTI_BLOCK_SIZE);

        $this->assertCount(3, (new EbuStlParser())->parse($content, new ReadOptions())->getCues());
    }


    public function testJsonMovesTheCommentsToTheCueNumbersAfterTheSkip(): void
    {
        $subtitle = (new JsonParser())->parse(file_get_contents(self::DIR . "missing_end.json"), new ReadOptions(lenient: true));

        $this->assertEquals([new Comment("Platform changes", 1)], $subtitle->getComments());
        $this->assertSame(['{"start":4,"lines":["It leaves from platform two."]}'], $subtitle->getParseWarnings()[0]->block);
    }


    public function testJsonWithAnErrorOutsideTheCuesStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The field version is missing.");
        (new JsonParser())->parse('{"cues": []}', new ReadOptions(lenient: true));
    }


    public function testWhisperCppSkipsASegmentWithoutOffsets(): void
    {
        $content = '{"transcription": [{"offsets": {"from": 0, "to": 2000}, "text": " Hello"}, {"text": " Lost"},' .
                   ' {"offsets": {"from": 3000, "to": 4000}, "text": " Bye"}]}';
        $subtitle = (new WhisperJsonParser())->parse($content, new ReadOptions(lenient: true));

        $this->assertEquals([[0, 2, "Hello"], [3, 4, "Bye"]], TestSubtitles::describe($subtitle));
        $this->assertSame([[null, 1, self::SKIPPED, "The field transcription[1].offsets.from must be a number."]], TestSubtitles::warnings($subtitle));
    }
}
