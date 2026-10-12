<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use JsonException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\FormatOptionsList;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * The base class of the parsers of this library. Only the library extends it.
 * Its protected members are not API and can change in any release.
 */
abstract class SubtitleParser
{
    /**
     * The FormatReadOptions class that this parser reads from ReadOptions::$formats, or null for none.
     *
     * @var class-string<FormatReadOptions>|null
     */
    protected const FORMAT_OPTIONS = null;

    // parse() strips the UTF-8 BOM of a text format only.
    protected const BINARY = false;

    // In lenient mode, parse() replaces invalid UTF-8 with U+FFFD for a parser that cannot read it. Strict mode throws.
    protected const REPLACES_INVALID_UTF8 = false;

    private const WHITE_SPACE = " \t\n\r";

    // DOS editors end a file with one or more Ctrl-Z characters.
    private const END_OF_FILE_REGEX = '/\x1A+(?=[ \t\r\n]*$)/D';

    // C0 control characters except tab, LF and CR, and DEL. U+200E, U+200F and other format characters stay.
    private const CONTROL_CHARACTER_REGEX = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    // Lenient mode swaps the times of a cue that ends before it starts only up to this duration in seconds, as a typo.
    private const MAX_SWAPPED_DURATION = 30;

    protected ReadOptions $options;

    private ?FormatReadOptions $formatOptions = null;

    /** @var list<ParseWarning> */
    protected array $warnings = [];

    private bool $removedControlCharacters = false;


    /**
     * Reads $content, which must be UTF-8 for a text format.
     * In a text format, it drops Ctrl-Z characters at the end and removes the other C0 control characters and DEL.
     * A NUL throws in strict mode.
     * Text content without anything but white space and a BOM gives an empty Subtitle.
     * In lenient mode, Subtitle::getParseWarnings() returns what the parser skipped or repaired.
     */
    final public function parse(string $content, ?ReadOptions $options = null): Subtitle
    {
        $options ??= new ReadOptions();
        $this->useOptions($options);

        if (!static::BINARY) {
            $content = $this->removeControlCharacters(StringHelpers::removeUtf8Bom($this->checkUtf8($content)));
            if (trim($content, self::WHITE_SPACE) === "") {
                return new Subtitle();
            }
        }

        $subtitle = $this->read($content);
        $skipped  = array_filter($this->warnings, fn (ParseWarning $warning): bool => $warning->action === ParseWarningAction::Skipped);
        if ($subtitle->getCues() === [] && $skipped === []) {
            $lineNumber = $this->findTextLineWithoutCues($content);
            if ($lineNumber !== null) {
                $this->fail(new ParsingException("The file has text but no cues.", $lineNumber), $lineNumber, null, []);
            }
        }

        return $subtitle->setParseWarnings($this->warnings);
    }


    abstract protected function read(string $content): Subtitle;


    /**
     * Warns in lenient mode about content that is not valid UTF-8. A parser that replaces invalid UTF-8 throws in
     * strict mode, and reads the bad bytes as U+FFFD in lenient mode.
     */
    private function checkUtf8(string $content): string
    {
        $offset = StringHelpers::findInvalidUtf8Offset($content);
        if ($offset === null) {
            return $content;
        }

        $message = self::invalidUtf8Message($offset);
        if (!$this->replacesInvalidUtf8($content)) {
            if ($this->options->lenient) {
                $this->warn($message, null, null, [], ParseWarningAction::Repaired);
            }

            return $content;
        }
        if (!$this->options->lenient) {
            throw new ParsingException($message);
        }

        $this->warn("$message The parser read the bad bytes as U+FFFD.", null, null, [], ParseWarningAction::Repaired);

        return StringHelpers::replaceInvalidUtf8($content);
    }


    /**
     * Warns in lenient mode about a stream whose byte at $offset is not valid UTF-8. The stream readers of text formats
     * that keep invalid UTF-8 call it, and the warning comes first, as in parse().
     *
     * @internal
     */
    public function warnInvalidUtf8(int $offset): void
    {
        if ($this->options->lenient) {
            array_unshift($this->warnings, new ParseWarning(self::invalidUtf8Message($offset), null, null, [], ParseWarningAction::Repaired));
        }
    }


    private static function invalidUtf8Message(int $offset): string
    {
        return "The content is not valid UTF-8. The first bad byte is at offset $offset. Pass --encoding.";
    }


    /**
     * Returns REPLACES_INVALID_UTF8. A parser that reads several formats overrides it to decide by $content.
     */
    protected function replacesInvalidUtf8(string $content): bool
    {
        return static::REPLACES_INVALID_UTF8;
    }


    /**
     * Returns true for a parser of a binary format, which reads bytes rather than text.
     *
     * @internal
     */
    final public static function readsBinary(): bool
    {
        return static::BINARY;
    }


    /**
     * Yields $lines like parse() cleans the content: without Ctrl-Z at the end and without the other C0 control characters and DEL.
     *
     * @param iterable<int, string> $lines keyed by the 0-based line number
     *
     * @return Generator<int, string>
     *
     * @internal
     */
    public function withoutControlCharacters(iterable $lines): Generator
    {
        $held      = null;
        $heldKey   = 0;
        $heldBlank = [];
        foreach ($lines as $key => $line) {
            if ($held !== null && trim($line, self::WHITE_SPACE) === "") {
                $heldBlank[$key] = $line;
                continue;
            }
            if ($held !== null) {
                yield $heldKey => $this->removeLineControlCharacters($held, $heldKey + 1);
                foreach ($heldBlank as $blankKey => $blank) {
                    yield $blankKey => $blank;
                }
                [$held, $heldBlank] = [null, []];
            }
            if (preg_match(self::END_OF_FILE_REGEX, $line) === 1) {
                [$held, $heldKey] = [$line, $key];
                continue;
            }
            yield $key => $this->removeLineControlCharacters($line, $key + 1);
        }

        if ($held !== null) {
            yield $heldKey => $this->removeLineControlCharacters(preg_replace(self::END_OF_FILE_REGEX, "", $held), $heldKey + 1);
            foreach ($heldBlank as $blankKey => $blank) {
                yield $blankKey => $blank;
            }
        }
    }


    /**
     * Returns $content before the control characters go. WebVttParser replaces NUL as the WebVTT spec says.
     */
    protected function replaceNul(string $content): string
    {
        return $content;
    }


    private function removeControlCharacters(string $content): string
    {
        $content = $this->replaceNul(preg_replace(self::END_OF_FILE_REGEX, "", $content));
        $nul     = strpos($content, "\0");
        if ($nul !== false) {
            $this->rejectNul(count($this->lines(substr($content, 0, $nul))));
        }
        if (preg_match(self::CONTROL_CHARACTER_REGEX, $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $content;
        }

        $this->warnControlCharacters(count($this->lines(substr($content, 0, $matches[0][1]))));

        return preg_replace(self::CONTROL_CHARACTER_REGEX, "", $content);
    }


    private function removeLineControlCharacters(string $line, int $lineNumber): string
    {
        $line = $this->replaceNul($line);
        if (str_contains($line, "\0")) {
            $this->rejectNul($lineNumber);
        }
        $cleaned = preg_replace(self::CONTROL_CHARACTER_REGEX, "", $line);
        if ($cleaned !== $line && !$this->removedControlCharacters) {
            $this->warnControlCharacters($lineNumber);
        }

        return $cleaned;
    }


    // A NUL is never text. It marks UTF-16 or UTF-32 content read with the wrong encoding.
    private function rejectNul(int $lineNumber): void
    {
        if (!$this->options->lenient) {
            throw new ParsingException("Line $lineNumber has a NUL character. Check the encoding of the file.", $lineNumber);
        }
    }


    private function warnControlCharacters(int $lineNumber): void
    {
        $this->removedControlCharacters = true;
        if ($this->options->lenient) {
            $this->warn(
                "The file has control characters, the first on line $lineNumber. The parser removed them.",
                $lineNumber,
                null,
                [],
                ParseWarningAction::Repaired
            );
        }
    }


    /**
     * Returns the first line of text that the parser read neither as a cue nor as a header, or null.
     * parse() calls it for content that gave no cues and no skipped blocks. Only parsers that skip such text without an error override it.
     */
    protected function findTextLineWithoutCues(string $content): ?int
    {
        return null;
    }


    /**
     * Returns the FORMAT_OPTIONS object of ReadOptions::$formats, or the defaults of FORMAT_OPTIONS when the list has none.
     * useOptions() builds the defaults once per read.
     */
    protected function formatOptions(): FormatReadOptions
    {
        return $this->formatOptions;
    }


    /**
     * Sets the options for the next read and clears the warnings.
     *
     * @internal
     */
    public function useOptions(ReadOptions $options): static
    {
        $class = static::FORMAT_OPTIONS;
        $this->options                  = $options;
        $this->formatOptions            = $class === null ? null : FormatOptionsList::find($options->formats, $class) ?? new $class();
        $this->warnings                 = [];
        $this->removedControlCharacters = false;

        return $this;
    }


    /**
     * Returns the warnings of the last read in lenient mode.
     *
     * @return list<ParseWarning>
     *
     * @internal
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }


    /**
     * Throws $exception in strict mode, and records that the parser skipped the block in lenient mode.
     *
     * @param list<string> $block
     */
    protected function fail(ParsingException $exception, ?int $lineNumber, ?int $blockIndex, array $block): void
    {
        if (!$this->options->lenient) {
            throw $exception;
        }

        $this->warnings[] = ParseWarning::skipped($exception, $lineNumber, $blockIndex, $block);
    }


    /**
     * Returns $text in double quotes, cut to 60 characters, for an error message.
     */
    protected static function quote(string $text): string
    {
        return '"' . (mb_strlen($text, "UTF-8") > 60 ? mb_substr($text, 0, 57, "UTF-8") . "..." : $text) . '"';
    }


    /**
     * @param list<string> $block
     */
    protected function warn(string $message, ?int $lineNumber, ?int $blockIndex, array $block, ParseWarningAction $action): void
    {
        $this->warnings[] = new ParseWarning($message, $lineNumber, $blockIndex, $block, $action);
    }


    /**
     * Throws in strict mode and warns in lenient mode for text that XmlLoader::skipLeadingText() removed.
     */
    protected function skipTextBeforeXml(string $content, string $skipped): void
    {
        $lineNumber = 1 + substr_count($content, "\n", 0, (int) strpos($content, $skipped));
        $lines      = $this->lines($skipped);
        $message    = "The file has text before the XML: \"$lines[0]\".";
        if (!$this->options->lenient) {
            throw new ParsingException($message, $lineNumber);
        }

        $this->warn("$message The parser skipped it.", $lineNumber, null, $lines, ParseWarningAction::Repaired);
    }


    /**
     * Returns [$start, $end] when the cue does not end before it starts. Otherwise strict mode throws.
     * Lenient mode swaps the times and warns when $canSwap is true and the cue then lasts MAX_SWAPPED_DURATION or less. Otherwise it throws too.
     *
     * @param list<string> $block
     *
     * @return array{float, float}
     */
    protected function orderedTimes(float $start, float $end, ?int $lineNumber, ?int $blockIndex, array $block, bool $canSwap = true): array
    {
        if ($end >= $start) {
            return [$start, $end];
        }

        $message = "The cue ends at $end s, before it starts at $start s.";
        if (!$this->options->lenient || !$canSwap || $start - $end > self::MAX_SWAPPED_DURATION) {
            throw new ParsingException($message, $lineNumber);
        }

        $this->warn("$message The parser swapped the times.", $lineNumber, $blockIndex, $block, ParseWarningAction::Repaired);

        return [$end, $start];
    }


    /**
     * Returns $seconds, or throws when the time $text reaches MAX_HOURS.
     */
    protected static function boundedTime(float $seconds, string $text, ?int $lineNumber): float
    {
        if ($seconds >= Timecode::MAX_HOURS * 3600) {
            throw new ParsingException("The time \"$text\" is not below " . Timecode::MAX_HOURS . " hours.", $lineNumber);
        }

        return $seconds;
    }


    /**
     * Returns $seconds, or throws when the time in the JSON field $path reaches MAX_HOURS.
     */
    protected static function boundedField(float $seconds, string $path): float
    {
        if ($seconds >= Timecode::MAX_HOURS * 3600) {
            throw new ParsingException("The field $path is not below " . Timecode::MAX_HOURS . " hours.");
        }

        return $seconds;
    }


    /**
     * Throws when a core word timestamp in $lines reaches MAX_HOURS.
     *
     * @param list<string> $lines
     */
    protected static function checkWordTimestamps(array $lines, ?int $lineNumber): void
    {
        foreach ($lines as $line) {
            preg_match_all(Markup::WORD_TIMESTAMP_REGEX, $line, $timestamps);
            foreach ($timestamps[1] as $timestamp) {
                self::boundedTime(Markup::wordTimestampSeconds($timestamp), substr($timestamp, 1, -1), $lineNumber);
            }
        }
    }


    /**
     * Decodes $content as a JSON object. An invalid UTF-8 byte becomes U+FFFD, so one broken byte does not fail the file.
     *
     * @return array<string, mixed>
     */
    protected function decodeJsonObject(string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            throw new ParsingException("The content is not valid JSON: {$exception->getMessage()}.");
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ParsingException("The JSON root must be an object.");
        }

        return $data;
    }


    /**
     * Returns true for an int or float that is finite and 0 or more. A time in a JSON format must pass it.
     */
    protected static function isTime(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && OptionChecks::isNonNegativeFinite($value);
    }


    /**
     * Returns the end of the cue at $index: the first later start in $starts.
     * Without one, the end is the start plus ReadOptions::$lastCueDuration.
     *
     * @param list<float> $starts
     */
    protected function endAtNextStart(array $starts, int $index): float
    {
        for ($next = $index + 1; $next < count($starts); $next++) {
            if ($starts[$next] > $starts[$index]) {
                return $starts[$next];
            }
        }

        return Timecode::roundToMilliseconds($starts[$index] + $this->options->lastCueDuration);
    }


    /**
     * Sorts the chapters by start and sets their ends.
     * A chapter ends at its value in $ends, else at the start of the next chapter.
     * The last chapter ends at ChapterReadOptions::$mediaDuration, but not before it starts.
     *
     * @param list<SubtitleCue> $chapters
     * @param array<int, float|null> $ends the end that the file gives, keyed like $chapters
     *
     * @return list<SubtitleCue>
     */
    protected function endChapters(array $chapters, array $ends = []): array
    {
        uasort($chapters, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());
        $keys     = array_keys($chapters);
        $chapters = array_values($chapters);
        foreach ($chapters as $index => $cue) {
            $next = $chapters[$index + 1] ?? null;
            $cue->setEnd($ends[$keys[$index]] ?? $next?->getStart() ?? max($cue->getStart(), $this->formatOptions()->mediaDuration ?? 0));
        }

        return $chapters;
    }


    /**
     * Returns the lines of $content without the line endings. A line ends at LF, CR LF or CR.
     *
     * @return list<string>
     */
    protected function lines(string $content): array
    {
        return explode(LineEnding::Lf->value, StringHelpers::normalizeEOLs($content));
    }


    /**
     * Splits $text at each "|", the line break of MPL2 and TMPlayer. Returns the trimmed lines that are not empty.
     *
     * @return list<string>
     */
    protected function pipeLines(string $text): array
    {
        return array_values(array_filter(array_map("trim", explode("|", $text)), fn (string $line): bool => $line !== ""));
    }


    /**
     * Yields the trimmed lines of each block between empty lines, keyed by the 1-based number of its first line.
     * The keys of $lines are the 0-based line numbers. An input without text yields no block.
     *
     * @return Generator<int, list<string>>
     */
    protected function splitAtEmptyLines(iterable $lines): Generator
    {
        $block = [];
        $start = 1;
        foreach ($lines as $index => $line) {
            $line = trim(StringHelpers::normalizeSpaces(StringHelpers::removeUtf8Bom($line)));
            if ($line !== "") {
                if ($block === []) {
                    $start = $index + 1;
                }
                $block[] = $line;
                continue;
            }
            if ($block !== []) {
                yield $start => $block;
                $block = [];
            }
        }

        if ($block !== []) {
            yield $start => $block;
        }
    }


    /**
     * Yields the blocks of splitAtEmptyLines() and joins a block of cue text to the block before it, when that block has a timing line.
     * So an empty line inside the cue text, or between the timing line and the text, does not end the cue.
     * In lenient mode, it warns for each join.
     * $isOtherBlock returns true for a block that is no cue text, such as a WebVTT NOTE block.
     *
     * @see CueBlockRules::isCueTextBlock()
     *
     * @param iterable<int, list<string>> $blocks
     * @param (callable(list<string>): bool)|null $isOtherBlock
     *
     * @return Generator<int, list<string>>
     */
    protected function joinCueTextBlocks(iterable $blocks, CueBlockRules $rules, ?callable $isOtherBlock = null): Generator
    {
        $pending     = null;
        $pendingLine = 0;
        $blockIndex  = 0;
        foreach ($blocks as $lineNumber => $block) {
            if ($pending !== null && $rules->isCueTextBlock($block)
                && ($isOtherBlock === null || !$isOtherBlock($block))
                && $rules->hasTimingLine($pending)) {
                if ($this->options->lenient) {
                    $this->warn(
                        "Block #$blockIndex has an empty line before line $lineNumber inside the cue. The parser kept the text after it in the cue.",
                        $lineNumber,
                        $blockIndex,
                        $block,
                        ParseWarningAction::Repaired
                    );
                }
                $pending = array_merge($pending, $block);
                continue;
            }
            if ($pending !== null) {
                yield $pendingLine => $pending;
                $blockIndex++;
            }
            $pending     = $block;
            $pendingLine = $lineNumber;
        }

        if ($pending !== null) {
            yield $pendingLine => $pending;
        }
    }


    /**
     * Returns the parts of a block that CueBlockRules::splitAtTimingLines() returns. In lenient mode, it warns for each split.
     *
     * @param list<string> $block
     *
     * @return array<int, list<string>>
     */
    protected function repairMissingEmptyLines(array $block, int $lineNumber, int $blockIndex, CueBlockRules $rules): array
    {
        $parts = $rules->splitAtTimingLines($block);
        foreach (array_keys($parts) as $offset) {
            if ($offset > 0 && $this->options->lenient) {
                $this->warn(
                    "Block #$blockIndex has no empty line before line " . ($lineNumber + $offset) . ". The parser split the block there.",
                    $lineNumber + $offset,
                    $blockIndex,
                    $block,
                    ParseWarningAction::Repaired
                );
            }
        }

        return $parts;
    }


    /**
     * Returns the cues of the parts that repairMissingEmptyLines() returns.
     * $parsePart gets a part and the number of its first line, and returns its cue.
     * In lenient mode, a part for which $parsePart throws is skipped with a warning.
     *
     * @param list<string> $block
     * @param callable(list<string>, int): SubtitleCue $parsePart
     *
     * @return list<SubtitleCue>
     */
    protected function parseRepairedBlock(array $block, int $lineNumber, int $blockIndex, CueBlockRules $rules, callable $parsePart): array
    {
        $cues = [];
        foreach ($this->repairMissingEmptyLines($block, $lineNumber, $blockIndex, $rules) as $offset => $part) {
            try {
                $cues[] = $parsePart($part, $lineNumber + $offset);
            } catch (ParsingException $exception) {
                $this->fail($exception, $exception->getLineNumber() ?? $lineNumber + $offset, $blockIndex, $part);
            }
        }

        return $cues;
    }
}
