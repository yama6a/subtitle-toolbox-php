<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextCaseTest extends TestCase
{
    /**
     * The Unicode Final_Sigma rule needs a cased letter before the sigma and none after it, case-ignorable characters skipped.
     *
     * @return array<string, array{string, string}>
     */
    public static function sigmas(): array
    {
        return [
            "after a letter without case"       => ["\u{4E2D}\u{03A3}", "\u{4E2D}\u{03C3}"],
            "before an apostrophe and a letter" => ["\u{0391}\u{03A3}'\u{0392}", "\u{03B1}\u{03C3}'\u{03B2}"],
            "end of a word"                     => ["\u{039F}\u{0394}\u{039F}\u{03A3}", "\u{03BF}\u{03B4}\u{03BF}\u{03C2}"],
        ];
    }


    #[DataProvider("sigmas")]
    public function testLowerFollowsTheFinalSigmaRule(string $text, string $expected): void
    {
        $this->assertSame($expected, TextCase::lower($text, false));
    }
}
