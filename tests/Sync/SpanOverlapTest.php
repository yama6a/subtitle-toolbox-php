<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\TimeRanges;

class SpanOverlapTest extends TestCase
{
    /** @return list<array{float, float}> */
    private static function spans(string $file): array
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sync/$file"), Format::SubRip);

        return TimeRanges::merged(array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], array_values($subtitle->getCues())));
    }


    /**
     * @return array<string, array{list<array{float, float}>, list<array{float, float}>, float, float}>
     */
    public static function spanLists(): array
    {
        return [
            "2 target spans, 1 reference span" => [[[0.0, 1.0], [2.0, 4.0]], [[0.5, 3.0]], -5.0, 5.0],
            "a long target span, touching reference spans, an offset range off the grid" => [
                [[1.0, 9.5]], [[0.0, 0.25], [0.25, 2.0], [7.75, 8.0]], -2.35, 1.15,
            ],
            "real files, German at 25 fps against English" => [
                self::spans("own_target_de_25fps.srt"), self::spans("own_reference_en.srt"), -60.0, 60.0,
            ],
        ];
    }


    /**
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     */
    #[DataProvider("spanLists")]
    public function testOverlapPerOffsetMatchesOverlapAtEachGridOffset(array $target, array $reference, float $minOffset, float $maxOffset): void
    {
        $perOffset = SpanOverlap::overlapPerOffset($target, $reference, $minOffset, $maxOffset);

        $this->assertCount((int)floor(($maxOffset - $minOffset) / SpanOverlap::COARSE_STEP + 1e-9) + 1, $perOffset);
        foreach ($perOffset as $index => $overlap) {
            $offset = $minOffset + $index * SpanOverlap::COARSE_STEP;
            $this->assertEqualsWithDelta(SpanOverlap::overlap($target, $reference, $offset), $overlap, 1e-6, "offset $offset");
        }
    }


    public function testOverlapPerSpanAddsUpToOverlap(): void
    {
        $target    = self::spans("own_target_de_25fps.srt");
        $reference = self::spans("own_reference_en.srt");

        $perSpan = SpanOverlap::overlapPerSpan($target, $reference, 1.3);

        $this->assertCount(count($target), $perSpan);
        $this->assertEqualsWithDelta(SpanOverlap::overlap($target, $reference, 1.3), array_sum($perSpan), 1e-9);
        $this->assertSame([0.0, 0.0], SpanOverlap::overlapPerSpan([[0.0, 1.0], [5.0, 6.0]], [[2.0, 3.0]], 0));
        $this->assertSame(3.5, SpanOverlap::totalTime([[0.0, 1.0], [2.0, 4.5]]));
    }
}
