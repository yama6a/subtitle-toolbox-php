<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Formatters\Options\HtmlTranscriptWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\Formatters\Options\PlainTextWriteOptions;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\TimestampMap;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Ocr\TesseractOcrOptions;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Validation\ValidationRules;

class OptionChecksTest extends TestCase
{
    /**
     * Each closure passes $value to one numeric parameter.
     *
     * @return array<string, array{Closure(float): mixed, float}>
     */
    public static function numericParameters(): array
    {
        $parameters = [
            "FrameRate"                                => fn (float $value) => new FrameRate($value),
            "ReadOptions lastCueDuration"              => fn (float $value) => new ReadOptions(lastCueDuration: $value),
            "CueLimits minDuration"                    => fn (float $value) => new CueLimits(minDuration: $value),
            "CueLimits maxDuration"                    => fn (float $value) => new CueLimits(maxDuration: $value),
            "CueLimits maxCharactersPerSecond"         => fn (float $value) => new CueLimits(maxCharactersPerSecond: $value),
            "MergeShortCuesOptions maxGap"             => fn (float $value) => new MergeShortCuesOptions(maxGap: $value),
            "ResegmentOptions maxWordGap"              => fn (float $value) => new ResegmentOptions(ResegmentMode::ByWords, maxWordGap: $value),
            "CsvWriteOptions frameRate"                => fn (float $value) => new CsvWriteOptions(frameRate: $value),
            "MicroDvdWriteOptions frameRate"           => fn (float $value) => new MicroDvdWriteOptions($value),
            "MpSubWriteOptions frameRate"              => fn (float $value) => new MpSubWriteOptions($value),
            "EbuStlWriteOptions frameRate"             => fn (float $value) => new EbuStlWriteOptions($value),
            "IttWriteOptions frameRate"                => fn (float $value) => new IttWriteOptions($value),
            "CsvReadOptions frameRate"                 => fn (float $value) => new CsvReadOptions(frameRate: $value),
            "MicroDvdReadOptions frameRate"            => fn (float $value) => new MicroDvdReadOptions($value),
            "ChapterReadOptions mediaDuration"         => fn (float $value) => new ChapterReadOptions($value),
            "ShotChangeOptions frameRate"              => fn (float $value) => new ShotChangeOptions($value),
            "ShotChangeOptions shotChanges"            => fn (float $value) => new ShotChangeOptions(24, [1.0, $value]),
            "HlsSegmentOptions segmentDuration"        => fn (float $value) => new HlsSegmentOptions(segmentDuration: $value),
            "HlsSegmentOptions local"                  => fn (float $value) => new HlsSegmentOptions(local: $value),
            "HlsSegmentOptions mediaDuration"          => fn (float $value) => new HlsSegmentOptions(mediaDuration: $value),
            "TimestampMap local"                       => fn (float $value) => new TimestampMap(0, $value),
            "ReferenceSyncOptions minOffset"           => fn (float $value) => new ReferenceSyncOptions(new Subtitle(), minOffset: $value),
            "ReferenceSyncOptions maxOffset"           => fn (float $value) => new ReferenceSyncOptions(new Subtitle(), maxOffset: $value),
            "ReferenceSyncOptions splitPenalty"        => fn (float $value) => new ReferenceSyncOptions(new Subtitle(), splitPenalty: $value),
            "SubtitleDiffOptions timeTolerance"        => fn (float $value) => new SubtitleDiffOptions($value),
            "DualSubtitleOptions timeTolerance"        => fn (float $value) => new DualSubtitleOptions(timeTolerance: $value),
            "ProfanityOptions padding"                 => fn (float $value) => new ProfanityOptions(["hell"], padding: $value),
            "GlyphOcrOptions italicSlant"              => fn (float $value) => new GlyphOcrOptions(italicSlant: $value),
            "TesseractOcrOptions scale"                => fn (float $value) => new TesseractOcrOptions(scale: $value),
            "ValidationRules minDuration"              => fn (float $value) => new ValidationRules(minDuration: $value),
            "ValidationRules minGap"                   => fn (float $value) => new ValidationRules(minGap: $value),
            "ValidationRules minSecondsPerWord"        => fn (float $value) => new ValidationRules(minSecondsPerWord: $value),
            "Subtitle::scale() factor"                 => fn (float $value) => (new Subtitle())->scale($value),
            "Subtitle::extendShortCues() minDuration"  => fn (float $value) => (new Subtitle())->extendShortCues($value),
            "Subtitle::removeDuplicateCues() maxGap"   => fn (float $value) => (new Subtitle())->removeDuplicateCues($value),
            "Subtitle::fixOverlaps() minGap"           => fn (float $value) => (new Subtitle())->fixOverlaps($value),
            "Subtitle::addLeadInOut() leadIn"          => fn (float $value) => (new Subtitle())->addLeadInOut($value, 0),
            "Subtitle::addLeadInOut() leadOut"         => fn (float $value) => (new Subtitle())->addLeadInOut(0, $value),
            "Subtitle::addLeadInOut() minGap"          => fn (float $value) => (new Subtitle())->addLeadInOut(0, 0, $value),
            "Subtitle::limitLongCues() maxDuration"    => fn (float $value) => (new Subtitle())->limitLongCues($value),
            "SpeechReference mediaDuration"            => fn (float $value) => SpeechReference::fromFfmpegSilencedetect("", $value),
        ];

        $cases = [];
        foreach ($parameters as $name => $create) {
            foreach (["NAN" => NAN, "INF" => INF, "-INF" => -INF] as $label => $value) {
                $cases["$name: $label"] = [$create, $value];
            }
        }

        // INF turns a paragraph gap or a maximum validation limit off.
        $acceptInfinity = [
            "ValidationRules maxCharactersPerSecond"  => fn (float $value) => new ValidationRules(maxCharactersPerSecond: $value),
            "ValidationRules maxDuration"             => fn (float $value) => new ValidationRules(maxDuration: $value),
            "ValidationRules maxWordsPerMinute"       => fn (float $value) => new ValidationRules(maxWordsPerMinute: $value),
            "HtmlTranscriptWriteOptions paragraphGap" => fn (float $value) => new HtmlTranscriptWriteOptions($value),
            "PlainTextWriteOptions paragraphGap"      => fn (float $value) => new PlainTextWriteOptions(paragraphGap: $value),
        ];
        foreach ($acceptInfinity as $name => $create) {
            foreach (["NAN" => NAN, "-INF" => -INF] as $label => $value) {
                $cases["$name: $label"] = [$create, $value];
            }
        }

        return $cases;
    }


    /**
     * @param Closure(float): mixed $create
     */
    #[DataProvider("numericParameters")]
    public function testNanAndInfinityThrow(Closure $create, float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create($value);
    }


    /**
     * @return array<string, array{Closure(): mixed, string}>
     */
    public static function invalidValues(): array
    {
        return [
            "negative minimum duration"  => [fn () => new CueLimits(minDuration: -1), "The minimum duration must not be negative, got -1."],
            "NAN in the message"         => [fn () => new CueLimits(maxDuration: NAN), "The maximum duration and the maximum characters per second must be " .
                                                                                     "greater than 0, got NAN and null."],
            "minimum above maximum"      => [fn () => new CueLimits(minDuration: 8, maxDuration: 5),
                                             "The minimum duration must not be greater than the maximum duration, got 8 and 5."],
            "frame rate 0"               => [fn () => new FrameRate(0), "The frame rate must be greater than 0, got 0."],
            "MicroDVD frame rate NAN"    => [fn () => new MicroDvdWriteOptions(NAN), "The MicroDVD frame rate must be greater than 0, got NAN."],
            "MPSub frame rate INF"       => [fn () => new MpSubWriteOptions(INF), "The MPSub frame rate must be a positive integer, got INF."],
            "CSV frame rate 0"           => [fn () => new CsvWriteOptions(frameRate: 0), "The CSV frame rate must be greater than 0, got 0."],
            "ITT frame rate NAN"         => [fn () => new IttWriteOptions(NAN), "The ITT formatter accepts the frame rates 23.976, 24, 25, 29.97 and 30, got NAN."],
            "negative paragraph gap"     => [fn () => new PlainTextWriteOptions(paragraphGap: -1), "The paragraph gap must be 0 or more seconds, got -1."],
            "negative media duration"    => [fn () => new ChapterReadOptions(-1), "The media duration must be 0 or more seconds, got -1."],
            "shot change NAN"            => [fn () => new ShotChangeOptions(24, [NAN]), "The shot change time must be a finite number, got NAN."],
            "negative snap"              => [fn () => new DualSubtitleOptions(timeTolerance: -1), "The time tolerance -1 must not be negative."],
            "italic slant 2"             => [fn () => new GlyphOcrOptions(italicSlant: 2.0), "The italic slant must be from 0 to 1, got 2."],
            "offset beyond a day"        => [fn () => new ReferenceSyncOptions(new Subtitle(), maxOffset: 1e20), "The maximum offset must be from -86400 to 86400 seconds, got 1.0E+20."],
            "negative maximum lines"     => [fn () => new ValidationRules(maxLinesPerCue: -1), "The limit maxLinesPerCue must be 0 or more, got -1."],
            "NAN maximum duration"       => [fn () => new ValidationRules(maxDuration: NAN), "The limit maxDuration must be 0 or more, got NAN."],
            "INF minimum gap"            => [fn () => new ValidationRules(minGap: INF), "The limit minGap must be a finite number of 0 or more, got INF."],
            "Tesseract scale NAN"        => [fn () => new TesseractOcrOptions(scale: NAN), "The scale must be from 1 to 8, got NAN."],
            "empty language class"       => [fn () => new SamiReadOptions(" "), "The language class must not be empty."],
            "empty language"             => [fn () => new VobSubReadOptions(language: " "), "The language must not be empty."],
        ];
    }


    /**
     * @param Closure(): mixed $create
     */
    #[DataProvider("invalidValues")]
    public function testInvalidValueKeepsItsMessage(Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }


    public function testParagraphGapAcceptsInfinity(): void
    {
        $this->assertSame(INF, (new HtmlTranscriptWriteOptions(INF))->paragraphGap);
        $this->assertSame(INF, (new PlainTextWriteOptions(paragraphGap: INF))->paragraphGap);
    }


    public function testMaximumValidationLimitAcceptsInfinity(): void
    {
        $rules = new ValidationRules(maxCharactersPerSecond: INF, maxDuration: INF, maxWordsPerMinute: INF);

        $this->assertSame([INF, INF, INF], [$rules->maxCharactersPerSecond, $rules->maxDuration, $rules->maxWordsPerMinute]);
    }


    public function testCueLimitsAcceptEqualMinimumAndMaximumDuration(): void
    {
        $limits = new CueLimits(minDuration: 5, maxDuration: 5);

        $this->assertSame([5.0, 5.0], [$limits->minDuration, $limits->maxDuration]);
    }


    public function testChapterMediaDurationAcceptsZero(): void
    {
        $this->assertSame(0.0, (new ChapterReadOptions(0))->mediaDuration);
    }
}
