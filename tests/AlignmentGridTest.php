<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AlignmentGridTest extends TestCase
{
    /**
     * @return array<string, array{Alignment, int, int}>
     */
    public static function cells(): array
    {
        return [
            "bottom left"   => [Alignment::BottomLeft, AlignmentGrid::BOTTOM_ROW, AlignmentGrid::LEFT],
            "bottom center" => [Alignment::BottomCenter, AlignmentGrid::BOTTOM_ROW, AlignmentGrid::CENTER],
            "bottom right"  => [Alignment::BottomRight, AlignmentGrid::BOTTOM_ROW, AlignmentGrid::RIGHT],
            "middle left"   => [Alignment::MiddleLeft, AlignmentGrid::MIDDLE_ROW, AlignmentGrid::LEFT],
            "middle center" => [Alignment::MiddleCenter, AlignmentGrid::MIDDLE_ROW, AlignmentGrid::CENTER],
            "middle right"  => [Alignment::MiddleRight, AlignmentGrid::MIDDLE_ROW, AlignmentGrid::RIGHT],
            "top left"      => [Alignment::TopLeft, AlignmentGrid::TOP_ROW, AlignmentGrid::LEFT],
            "top center"    => [Alignment::TopCenter, AlignmentGrid::TOP_ROW, AlignmentGrid::CENTER],
            "top right"     => [Alignment::TopRight, AlignmentGrid::TOP_ROW, AlignmentGrid::RIGHT],
        ];
    }


    #[DataProvider("cells")]
    public function testMapsAlignmentsToRowsAndColumns(Alignment $alignment, int $row, int $column): void
    {
        self::assertSame($alignment, AlignmentGrid::of($row, $column));
        self::assertSame($row, AlignmentGrid::row($alignment));
        self::assertSame($column, AlignmentGrid::column($alignment));
    }
}
