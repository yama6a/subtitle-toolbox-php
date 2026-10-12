<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class ArgumentsTest extends TestCase
{
    /**
     * @return list<Option>
     */
    private static function spec(): array
    {
        return [
            Option::flag("force", "Overwrite."),
            Option::value("by", "SECONDS", "Seconds."),
            Option::value("output", "PATH", "Output.", "o"),
            Option::repeatable("brackets", "PAIR", "Pairs."),
            Option::value("fps", "RATE", "Frame rate."),
            Option::value("video-fps", "RATE", "Video frame rate."),
        ];
    }


    public function testParsesOptionFormsAndPositionals(): void
    {
        $arguments = Arguments::parse(["a.srt", "--by", "-2.5", "-", "--force", "-o", "out.srt", "--brackets={}", "--brackets", "**"], self::spec());

        $this->assertSame(["a.srt", "-"], $arguments->positionals);
        $this->assertTrue($arguments->has("force"));
        $this->assertSame(-2.5, $arguments->float("by"));
        $this->assertSame("out.srt", $arguments->value("output"));
        $this->assertSame(["{}", "**"], $arguments->values("brackets"));
        $this->assertNull($arguments->value("missing"));
        $this->assertSame([], $arguments->values("missing"));
    }


    public function testEqualsSignAndDoubleDash(): void
    {
        $arguments = Arguments::parse(["--by=-1", "--", "--force", "-o"], self::spec());

        $this->assertSame(-1.0, $arguments->float("by"));
        $this->assertSame(["--force", "-o"], $arguments->positionals);
        $this->assertFalse($arguments->has("force"));
    }


    public function testNumberGetters(): void
    {
        $arguments = Arguments::parse(["--by", "3", "--output", "0"], self::spec());

        $this->assertSame(3.0, $arguments->positiveFloat("by"));
        $this->assertSame(3, $arguments->positiveInt("by"));
        $this->assertNull($arguments->positiveInt("missing"));
        $this->assertNull($arguments->positiveFloat("missing"));
        $this->assertSame(0, $arguments->int("output", 0));
        $this->assertSame(3, $arguments->int("by", 1, 3));
        $this->assertNull($arguments->int("missing", 0));
        $this->assertSame(0.0, $arguments->nonNegativeFloat("output"));
        $this->assertNull($arguments->nonNegativeFloat("missing"));
    }


    public function testSecondsTakeNumbersAndTimecodes(): void
    {
        foreach (["2.5" => 2.5, "-2.5" => -2.5, "-00:00:02,500" => -2.5, "01:02:03.456" => 3723.456, "01:02.5" => 62.5] as $value => $seconds) {
            $this->assertSame($seconds, Arguments::parse(["--by", (string) $value], self::spec())->seconds("by"));
        }
        $this->assertSame(1.2, Arguments::parse(["--by=00:00:01.2"], self::spec())->positiveSeconds("by"));
        $this->assertSame(0.0, Arguments::parse(["--by", "00:00"], self::spec())->nonNegativeSeconds("by"));
        $this->assertNull(Arguments::parse([], self::spec())->seconds("by"));
    }


    public function testChoiceIgnoresCaseAndReturnsTheAllowedSpelling(): void
    {
        $arguments = Arguments::parse(["--by", "Top-BOTTOM"], self::spec());

        $this->assertSame("top-bottom", $arguments->choice("by", ["stack", "top-bottom"]));
        $this->assertNull($arguments->choice("missing", ["stack"]));
    }


    public function testRateFallsBackToFps(): void
    {
        $this->assertSame(25.0, Arguments::parse(["--fps", "25"], self::spec())->rate("video-fps"));
        $this->assertSame(24.0, Arguments::parse(["--fps", "25", "--video-fps", "24"], self::spec())->rate("video-fps"));
        $this->assertNull(Arguments::parse([], self::spec())->rate("video-fps"));
    }


    public function testRateChecksFpsAlsoWhenTheOwnOptionIsGiven(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The option --fps must be greater than 0.");

        Arguments::parse(["--fps", "0", "--video-fps", "24"], self::spec())->rate("video-fps");
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            "unknown long option"  => [["--nope"], "Unknown option --nope."],
            "unknown short option" => [["-x"], "Unknown option -x."],
            "flag with a value"    => [["--force=yes"], "The option --force takes no value."],
            "missing value"        => [["--by"], "The option --by needs a value."],
            "value given twice"    => [["--by", "1", "--by", "2"], "The option --by is given twice."],
        ];
    }


    #[DataProvider("invalidArguments")]
    public function testInvalidArgumentsFail(array $argv, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse($argv, self::spec());
    }


    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            "not a number"       => ["float", "abc", "The option --by needs a number, got \"abc\"."],
            "infinite"           => ["float", "1e999", "The option --by needs a finite number, got \"1e999\"."],
            "negative infinite"  => ["float", "-1e999", "The option --by needs a finite number, got \"-1e999\"."],
            "infinite positive"  => ["positiveFloat", "1e999", "The option --by needs a finite number, got \"1e999\"."],
            "zero"               => ["positiveFloat", "0", "The option --by must be greater than 0."],
            "fraction for int"   => ["positiveInt", "1.5", "The option --by needs a whole number of 1 or more, got \"1.5\"."],
            "zero for int"       => ["positiveInt", "0", "The option --by needs a whole number of 1 or more, got \"0\"."],
            "int overflow"       => ["positiveInt", "99999999999999999999", "The option --by needs a whole number of 1 or more, got \"99999999999999999999\"."],
            "negative"           => ["nonNegativeFloat", "-0.5", "The option --by must not be negative."],
            "infinite non-neg"   => ["nonNegativeFloat", "1e999", "The option --by needs a finite number, got \"1e999\"."],
            "five fields"        => ["seconds", "1:2:3:4:5", "The option --by needs seconds or a timecode such as 00:01:02.500, got \"1:2:3:4:5\"."],
            "frames"             => ["seconds", "00:00:01:12", "The option --by needs seconds or a timecode such as 00:01:02.500, got \"00:00:01:12\"."],
            "lone minus"         => ["seconds", "-", "The option --by needs seconds or a timecode such as 00:01:02.500, got \"-\"."],
            "two minus signs"    => ["seconds", "--00:01", "The option --by needs seconds or a timecode such as 00:01:02.500, got \"--00:01\"."],
            "infinite seconds"   => ["seconds", "1e999", "The option --by needs a finite number, got \"1e999\"."],
            "zero timecode"      => ["positiveSeconds", "00:00", "The option --by must be greater than 0."],
            "negative timecode"  => ["nonNegativeSeconds", "-00:00:00.5", "The option --by must not be negative."],
        ];
    }


    #[DataProvider("invalidNumbers")]
    public function testInvalidNumbersFail(string $getter, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse(["--by", $value], self::spec())->$getter("by");
    }


    /**
     * @return array<string, array{string, int, ?int, string}>
     */
    public static function invalidWholeNumbers(): array
    {
        return [
            "fraction"     => ["1.5", 0, 9, "The option --by needs a whole number from 0 to 9, got \"1.5\"."],
            "below min"    => ["0", 1, 9, "The option --by needs a whole number from 1 to 9, got \"0\"."],
            "above max"    => ["10", 1, 9, "The option --by needs a whole number from 1 to 9, got \"10\"."],
            "negative"     => ["-1", 0, null, "The option --by needs a whole number of 0 or more, got \"-1\"."],
            "too large"    => ["99999999999999999999", 0, null, "The option --by needs a whole number of 0 or more, got \"99999999999999999999\"."],
            "plus sign"    => ["+1", 0, null, "The option --by needs a whole number of 0 or more, got \"+1\"."],
            "empty"        => ["", 0, null, "The option --by needs a whole number of 0 or more, got \"\"."],
            "exponent"     => ["1e3", 0, null, "The option --by needs a whole number of 0 or more, got \"1e3\"."],
        ];
    }


    #[DataProvider("invalidWholeNumbers")]
    public function testInvalidWholeNumbersFail(string $value, int $min, ?int $max, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse(["--by", $value], self::spec())->int("by", $min, $max);
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidChoices(): array
    {
        return [
            "one choice"    => [["lf"], "The option --by must be lf, got \"cr\"."],
            "two choices"   => [["lf", "crlf"], "The option --by must be lf or crlf, got \"cr\"."],
            "three choices" => [["k", "kf", "ko"], "The option --by must be k, kf or ko, got \"cr\"."],
        ];
    }


    /**
     * @param non-empty-list<string> $allowed
     */
    #[DataProvider("invalidChoices")]
    public function testInvalidChoicesFail(array $allowed, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse(["--by", "cr"], self::spec())->choice("by", $allowed);
    }
}
