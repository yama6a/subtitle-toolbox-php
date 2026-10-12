<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\WebVttParser;

/**
 * @internal
 */
trait ArrayConversion
{
    public const ARRAY_VERSION = 1;


    /**
     * Returns the subtitle as an array of scalars in the shape that docs/json.md documents.
     */
    public function toArray(bool $withFormatData = true): array
    {
        $this->sortStartChanges();
        $cues = [];
        foreach ($this->cues as $cue) {
            $cueArray = [
                "start"      => $cue->getStart(),
                "end"        => $cue->getEnd(),
                "lines"      => array_values($cue->getLines()),
                "identifier" => $cue->getIdentifier(),
                "alignment"  => $cue->getAlignment() === Alignment::BottomCenter ? null : $cue->getAlignment()->value,
            ];
            if ($cue->isForced()) {
                $cueArray["forced"] = true;
            }
            if ($withFormatData) {
                $cueArray["formatData"] = $cue->getAllFormatData();
            }
            $cues[] = $cueArray;
        }

        $array = [
            "version"  => self::ARRAY_VERSION,
            "metadata" => $this->metadata,
            "comments" => array_map(fn (Comment $comment): array => ["text" => $comment->text, "beforeCueIndex" => $comment->beforeCueIndex],
                                    $this->comments),
        ];
        if ($withFormatData) {
            $array["formatData"] = $this->formatData;
        }
        $array["cues"] = $cues;

        return $array;
    }


    /**
     * Builds a subtitle from the array that toArray() returns, and keeps the cue order.
     * A bad field throws ParsingException with the path of the field.
     */
    public static function fromArray(array $data): self
    {
        return self::fromArrayLeavingOut($data, [], static fn (ParsingException $exception): never => throw $exception);
    }


    /**
     * Builds a subtitle as fromArray() does, but leaves out each bad cue, comment, metadata field and format data entry.
     * $reject gets each of them. The cues in $skippedCues stay out too.
     * The comments after a left-out cue move up by one cue, so they stay before the same cue.
     *
     * @internal
     *
     * @param array<int, true>                                     $skippedCues
     * @param \Closure(ParsingException, string, int|string): void $reject      gets the error, the top-level field and the key of the entry
     */
    public static function fromArrayLeavingOut(array $data, array $skippedCues, \Closure $reject): self
    {
        if (!array_key_exists("version", $data)) {
            throw new ParsingException("The field version is missing.");
        }
        if ($data["version"] !== self::ARRAY_VERSION) {
            throw new ParsingException("The field version must be " . self::ARRAY_VERSION . ".");
        }

        $subtitle = new self();
        self::arrayConversionReadMetadata($subtitle, $data, $reject);
        self::arrayConversionReadFormatData($subtitle, $data, $reject);
        $skippedCues = self::arrayConversionReadCues($subtitle, $data, $skippedCues, $reject);
        self::arrayConversionReadComments($subtitle, $data, $skippedCues, $reject);
        $subtitle->holdCuesInStartOrder();

        return $subtitle;
    }


    /**
     * @param \Closure(ParsingException, string, int|string): void $reject
     */
    private static function arrayConversionReadMetadata(self $subtitle, array $data, \Closure $reject): void
    {
        foreach (self::arrayConversionMap($data, "metadata") as $key => $value) {
            try {
                if (!is_string($value)) {
                    throw new ParsingException("The field metadata.$key must be a string.");
                }
            } catch (ParsingException $exception) {
                $reject($exception, "metadata", $key);
                continue;
            }
            $subtitle->setMetadata((string)$key, $value);
        }
    }


    /**
     * @param \Closure(ParsingException, string, int|string): void $reject
     */
    private static function arrayConversionReadFormatData(self $subtitle, array $data, \Closure $reject): void
    {
        foreach (self::arrayConversionMap($data, "formatData") as $format => $formatData) {
            try {
                self::arrayConversionCheckFormatData($format, $formatData, "formatData.$format", false);
            } catch (ParsingException $exception) {
                $reject($exception, "formatData", $format);
                continue;
            }
            $subtitle->setFormatData($format, $formatData);
        }
    }


    /**
     * Returns $skippedCues with the bad cues added.
     *
     * @param array<int, true>                                     $skippedCues
     * @param \Closure(ParsingException, string, int|string): void $reject
     * @return array<int, true>
     */
    private static function arrayConversionReadCues(self $subtitle, array $data, array $skippedCues, \Closure $reject): array
    {
        if (!is_array($data["cues"] ?? null) || !array_is_list($data["cues"])) {
            throw new ParsingException("The field cues must be a list.");
        }
        foreach ($data["cues"] as $index => $cueData) {
            if (isset($skippedCues[$index])) {
                continue;
            }
            try {
                $subtitle->cues[] = self::arrayConversionCue($cueData, "cues[$index]");
            } catch (ParsingException $exception) {
                $reject($exception, "cues", $index);
                $skippedCues[$index] = true;
            }
        }

        return $skippedCues;
    }


    /**
     * @param array<int, true>                                     $skippedCues
     * @param \Closure(ParsingException, string, int|string): void $reject
     */
    private static function arrayConversionReadComments(self $subtitle, array $data, array $skippedCues, \Closure $reject): void
    {
        foreach (self::arrayConversionList($data, "comments") as $index => $comment) {
            try {
                self::arrayConversionCheckComment($comment, "comments[$index]");
            } catch (ParsingException $exception) {
                $reject($exception, "comments", $index);
                continue;
            }
            $before = $comment["beforeCueIndex"];
            $subtitle->addComment($comment["text"], $before - count(array_filter(array_keys($skippedCues), fn (int $cue): bool => $cue < $before)));
        }
    }


    private static function arrayConversionCheckComment(mixed $comment, string $path): void
    {
        if (!is_array($comment)) {
            throw new ParsingException("The field $path must be an object.");
        }
        if (!is_string($comment["text"] ?? null)) {
            throw new ParsingException("The field $path.text must be a string.");
        }
        if (!is_int($comment["beforeCueIndex"] ?? null) || $comment["beforeCueIndex"] < 0) {
            throw new ParsingException("The field $path.beforeCueIndex must be an integer of 0 or more.");
        }
    }


    private static function arrayConversionCue(mixed $cueData, string $path): SubtitleCue
    {
        if (!is_array($cueData)) {
            throw new ParsingException("The field $path must be an object.");
        }
        foreach (["start", "end"] as $key) {
            $time = $cueData[$key] ?? null;
            if (!is_int($time) && (!is_float($time) || !is_finite($time))) {
                throw new ParsingException("The field $path.$key must be a number.");
            }
        }
        if (!is_array($cueData["lines"] ?? null) || !array_is_list($cueData["lines"])) {
            throw new ParsingException("The field $path.lines must be a list.");
        }
        foreach ($cueData["lines"] as $index => $line) {
            if (!is_string($line)) {
                throw new ParsingException("The field $path.lines[$index] must be a string.");
            }
        }

        $identifier = $cueData["identifier"] ?? null;
        if (!is_string($identifier) && $identifier !== null) {
            throw new ParsingException("The field $path.identifier must be a string or null.");
        }
        $alignment = $cueData["alignment"] ?? Alignment::BottomCenter->value;
        $alignment = is_int($alignment) ? Alignment::tryFrom($alignment) : null;
        if ($alignment === null) {
            throw new ParsingException("The field $path.alignment must be an integer from 1 to 9 or null.");
        }

        $forced = $cueData["forced"] ?? false;
        if (!is_bool($forced)) {
            throw new ParsingException("The field $path.forced must be a boolean.");
        }

        $cue = (new SubtitleCue($cueData["start"], $cueData["end"], $cueData["lines"]))
            ->setIdentifier($identifier)
            ->setAlignment($alignment)
            ->setForced($forced);
        foreach (self::arrayConversionMap($cueData, "formatData", "$path.") as $format => $formatData) {
            self::arrayConversionCheckFormatData($format, $formatData, "$path.formatData.$format", true);
            // JSON from 3.x has no stored alignment. Without it, the formatters would drop the stored position.
            if (in_array($format, [WebVttParser::FORMAT_DATA_KEY, TtmlParser::FORMAT_DATA_KEY], true)
                && $formatData !== [] && !array_key_exists("alignment", $formatData) && $alignment !== Alignment::BottomCenter) {
                $formatData["alignment"] = $alignment->value;
            }
            $cue->setFormatData($format, $formatData);
        }

        return $cue;
    }


    private static function arrayConversionCheckFormatData(int|string $format, mixed $value, string $path, bool $isCue): void
    {
        if (!is_array($value)) {
            throw new ParsingException("The field $path must be an object.");
        }
        $problem = $isCue ? FormatDataSchema::checkCue((string) $format, $value, $path) : FormatDataSchema::checkFile((string) $format, $value, $path);
        if ($problem !== null) {
            throw new ParsingException($problem);
        }
    }


    private static function arrayConversionMap(array $data, string $key, string $pathPrefix = ""): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new ParsingException("The field $pathPrefix$key must be an object.");
        }

        return $value;
    }


    private static function arrayConversionList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ParsingException("The field $key must be a list.");
        }

        return $value;
    }
}
