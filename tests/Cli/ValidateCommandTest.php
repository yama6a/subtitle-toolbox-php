<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\Validation\ValidationViolation;

class ValidateCommandTest extends TestCase
{
    /**
     * @param list<string> $argv
     */
    private static function arguments(array $argv): Arguments
    {
        return Arguments::parse($argv, [
            Option::value("max-cpl", "CHARS", "Characters per line."),
            Option::value("dialogue-dash", "STYLE", "Dash style."),
            Option::flag("check-overlaps", "Overlaps."),
        ]);
    }


    public function testRulesKeepTheFieldsWithoutAnOption(): void
    {
        $base = new ValidationRules(maxCharactersPerLine: 42, requireCues: true, noNegativeDuration: true);

        $rules = ValidateCommand::rules(self::arguments([]), $base);

        $this->assertEquals($base, $rules);
    }


    public function testRuleOptionsOverrideTheBase(): void
    {
        $base = new ValidationRules(maxCharactersPerLine: 42, noEmptyCues: true, requireCues: true);

        $rules = ValidateCommand::rules(self::arguments(["--max-cpl", "30", "--dialogue-dash", "- ", "--check-overlaps"]), $base);

        $this->assertEquals(new ValidationRules(maxCharactersPerLine: 30, noOverlap: true, noEmptyCues: true,
            dialogueDashStyle: DialogueDashStyle::HyphenSpace, requireCues: true), $rules);
    }


    public function testViolationWithoutACueHasNoCueNumber(): void
    {
        $violation = new ValidationViolation(null, ValidationRule::RequireCues, 0, null);

        $this->assertSame("empty.srt: requireCues 0\n", ValidateCommand::violationLine("empty.srt", $violation));
        $this->assertSame(["cueIndex" => null, "rule" => "requireCues", "value" => 0, "infinite" => false, "limit" => null],
            ValidateCommand::violationJson($violation));
    }


    public function testViolationOfACueHasItsNumber(): void
    {
        $violation = new ValidationViolation(0, ValidationRule::MaxCharactersPerLine, 45, 42);

        $this->assertSame("a.srt: cue 1: maxCharactersPerLine 45, limit 42\n", ValidateCommand::violationLine("a.srt", $violation));
        $this->assertSame(["cueIndex" => 0, "rule" => "maxCharactersPerLine", "value" => 45, "infinite" => false, "limit" => 42],
            ValidateCommand::violationJson($violation));
    }
}
