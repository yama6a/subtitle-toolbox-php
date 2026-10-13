<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Parsers\Mcc;
use SubtitleToolbox\Parsers\MccParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Parsers\SpruceStlParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\WebVttParser;

/**
 * FormatDataSchema checks the types of the format data fields that the formatters read.
 * So setFormatData(), fromArray() and JsonParser reject a bad field with its path before a formatter fails on it.
 * Other fields pass as they are.
 *
 * The format data of a format is an object of fields, or "strings" for an object of strings with any keys.
 * A type is one of these. A "?" in front of a type name, such as "?string" or "?list", also allows null.
 * - "string", "int", "bool", "number", "scalar", "ttiBlock" or "timeBase"
 * - ["list", type]: an array of values of one type
 * - ["names", type]: an object whose keys are names
 * - ["object", [key => type]]: named fields. A "!" in front of a key marks a required field.
 * - ["range", min, max]: an integer in a range
 * - ["oneOf", list of strings]: one of these strings
 * - ["keys", list of names, type]: an object with only these keys
 *
 * @internal
 */
final class FormatDataSchema
{
    private const STRINGS = ["list", "string"];

    public const CSV_MAX_COLUMNS = 1000;

    private const CSV_TIME_FORMATS = [
        CsvTimeFormat::Seconds->value,
        CsvTimeFormat::Dot->value,
        CsvTimeFormat::Comma->value,
        CsvTimeFormat::Frames->value,
        CsvTimeFormat::Milliseconds->value,
    ];

    private const ATTRIBUTES = ["names", "string"];

    private const TTI_BLOCKS = ["list", "ttiBlock"];

    private const FILE = [
        AssParser::FORMAT_DATA_KEY                => [
            "sectionOrder"       => self::STRINGS,
            "scriptInfoComments" => self::STRINGS,
            "scriptInfo"         => self::STRINGS,
            "stylesSection"      => "?string",
            "styleFormat"        => ["?list", "string"],
            "styles"             => ["list", self::STRINGS],
            "eventFormat"        => self::STRINGS,
            "commentEvents"      => ["list", self::ATTRIBUTES],
            "sections"           => ["list", self::STRINGS],
        ],
        CsvParser::FORMAT_DATA_KEY                => [
            "delimiter"     => ["oneOf", CsvReadOptions::DELIMITERS],
            "!header"       => ["?list", "string"],
            "!roles"        => ["keys", ["identifier", "start", "end", "duration", "speaker", "text"], ["range", 0, self::CSV_MAX_COLUMNS - 1]],
            "!width"        => ["range", 0, self::CSV_MAX_COLUMNS],
            "timeFormat"    => ["oneOf", self::CSV_TIME_FORMATS],
            "frameRate"     => "?number",
            "quotedColumns" => ["list", ["range", 0, self::CSV_MAX_COLUMNS - 1]],
        ],
        FfMetadataChaptersParser::FORMAT_DATA_KEY => [
            "tags"    => ["list", "?string"],
            "streams" => ["list", self::STRINGS],
        ],
        IttParser::FORMAT_DATA_KEY                => [
            "frameRate"           => "string",
            "frameRateMultiplier" => "string",
        ],
        LyricsParser::FORMAT_DATA_KEY             => [
            "idTags"          => self::STRINGS,
            "spacedWordTimes" => "bool",
        ],
        MccParser::FORMAT_DATA_KEY                => [
            "version"   => ["oneOf", Mcc::VERSIONS],
            "frameRate" => ["oneOf", Mcc::FRAME_RATE_NAMES],
            "dropFrame" => "bool",
            "header"    => self::STRINGS,
        ],
        MicroDvdParser::FORMAT_DATA_KEY           => [
            "frameRate"     => "number",
            "frameRateLine" => "bool",
        ],
        MpSubParser::FORMAT_DATA_KEY              => "strings",
        SamiParser::FORMAT_DATA_KEY               => [
            "style"     => "?string",
            "class"     => "?string",
            "samiParam" => "?string",
        ],
        SccParser::FORMAT_DATA_KEY                => [
            "dropFrame" => "bool",
        ],
        SpruceStlParser::FORMAT_DATA_KEY          => [
            "frameRate" => "number",
            "header"    => self::STRINGS,
            "separator" => "string",
            "lineBreak" => "string",
        ],
        EbuStlParser::FORMAT_DATA_KEY             => [
            "gsi"                        => self::STRINGS,
            "startOfProgrammeSubtracted" => "bool",
            "firstSubtitleNumber"        => "?int",
            "comments"                   => ["list", ["object", ["!text" => "string", "!blocks" => self::TTI_BLOCKS]]],
        ],
        SubViewerParser::FORMAT_DATA_KEY          => [
            "header" => self::STRINGS,
            "style"  => "?string",
        ],
        TtmlParser::FORMAT_DATA_KEY               => [
            "namespace"  => "string",
            "namespaces" => self::ATTRIBUTES,
            "head"       => "string",
            "attributes" => self::ATTRIBUTES,
            "body"       => self::ATTRIBUTES,
        ],
        WebVttParser::FORMAT_DATA_KEY             => [
            "header"      => "string",
            "headerLines" => self::STRINGS,
            "regions"     => ["list", self::STRINGS],
            "styles"      => self::STRINGS,
        ],
    ];

    private const CUE = [
        AssParser::FORMAT_DATA_KEY                => [
            "fields" => self::ATTRIBUTES,
            "text"   => "string",
        ],
        CsvParser::FORMAT_DATA_KEY                => [
            "columns" => ["list", "scalar"],
        ],
        FfMetadataChaptersParser::FORMAT_DATA_KEY => [
            "timeBase" => "timeBase",
            "tags"     => self::STRINGS,
        ],
        CueImage::FORMAT_DATA_KEY                 => [
            "png"          => "string",
            "x"            => "int",
            "y"            => "int",
            "width"        => "int",
            "height"       => "int",
            "screenWidth"  => "int",
            "screenHeight" => "int",
            "forced"       => "bool",
        ],
        LyricsParser::FORMAT_DATA_KEY             => [
            "endLine" => "bool",
        ],
        MicroDvdParser::FORMAT_DATA_KEY           => [
            "lines" => ["list", ["object", ["!codes" => "string", "!color" => "?string", "!tags" => self::STRINGS, "otherCodes" => "string"]]],
        ],
        SamiParser::FORMAT_DATA_KEY               => [
            "paragraphs" => ["list", ["object", ["!attributes" => self::ATTRIBUTES, "!html" => "string"]]],
        ],
        SpruceStlParser::FORMAT_DATA_KEY          => [
            "commands" => self::STRINGS,
        ],
        SubRipParser::FORMAT_DATA_KEY             => [
            "coordinates" => ["object", ["!x1" => "int", "!x2" => "int", "!y1" => "int", "!y2" => "int"]],
        ],
        EbuStlParser::FORMAT_DATA_KEY             => [
            "subtitleGroupNumber" => "int",
            "cumulativeStatus"    => "int",
            "verticalPosition"    => "int",
            "justificationCode"   => "int",
            "blocks"              => self::TTI_BLOCKS,
        ],
        TtmlParser::FORMAT_DATA_KEY               => [
            "attributes" => self::ATTRIBUTES,
            "div"        => self::ATTRIBUTES,
        ],
        WebVttParser::FORMAT_DATA_KEY             => [
            "vertical" => "string",
            "line"     => "string",
            "position" => "string",
            "size"     => "string",
            "align"    => "string",
            "region"   => "string",
        ],
    ];


    /**
     * Returns the error message for the first field of $data with a wrong type, or null.
     * $data is the file format data under the format data key $key.
     *
     * @param string $path the path of $data, for example "formatData.ass"
     */
    public static function checkFile(string $key, array $data, string $path): ?string
    {
        return self::check(self::FILE[$key] ?? null, $data, $path);
    }


    /**
     * Returns the error message for the first field of $data with a wrong type, or null.
     * $data is the cue format data under the format data key $key.
     *
     * @param string $path the path of $data, for example "cues[3].formatData.ass"
     */
    public static function checkCue(string $key, array $data, string $path): ?string
    {
        return self::check(self::CUE[$key] ?? null, $data, $path);
    }


    /**
     * Returns $formatData with $data under $key, as setFormatData() of Subtitle and SubtitleCue store it.
     * An empty $data removes the key.
     *
     * @throws InvalidArgumentException when a field of $data has the wrong type.
     */
    public static function withData(array $formatData, string $key, array $data, bool $isCue): array
    {
        $problem = $isCue ? self::checkCue($key, $data, "formatData.$key") : self::checkFile($key, $data, "formatData.$key");
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        if ($data === []) {
            unset($formatData[$key]);
        } else {
            $formatData[$key] = $data;
        }

        return $formatData;
    }


    /**
     * @param array<string, string|array>|"strings"|null $fields
     */
    private static function check(array|string|null $fields, array $data, string $path): ?string
    {
        if ($fields === "strings") {
            return self::checkType(self::STRINGS, $data, $path);
        }

        return $fields !== null && $data !== [] ? self::checkType(["object", $fields], $data, $path) : null;
    }


    private static function checkType(string|array $type, mixed $value, string $path): ?string
    {
        $name = is_array($type) ? $type[0] : $type;
        if (str_starts_with($name, "?")) {
            return $value === null ? null : self::checkType(is_array($type) ? [substr($name, 1), ...array_slice($type, 1)] : substr($name, 1), $value, $path);
        }

        $valid = match ($name) {
            "string"   => is_string($value),
            "int"      => is_int($value),
            "bool"     => is_bool($value),
            "number"   => is_int($value) || (is_float($value) && is_finite($value)),
            "scalar"   => is_scalar($value) || $value === null,
            "ttiBlock" => is_string($value) && preg_match('/^[0-9A-Fa-f]{256}$/', $value) === 1,
            "timeBase" => is_string($value) && preg_match('/^[1-9]\d*\/[1-9]\d*$/', $value) === 1,
            "range"    => is_int($value) && $value >= $type[1] && $value <= $type[2],
            "oneOf"    => in_array($value, $type[1], true),
            default    => is_array($value),
        };
        if (!$valid) {
            return "The field $path must be " . self::describe($type) . ".";
        }
        if (!is_array($type) || in_array($type[0], ["range", "oneOf"], true)) {
            return null;
        }

        return match ($type[0]) {
            "list"   => self::checkItems($type[1], $value, $path),
            "names"  => self::checkNames($type[1], $value, $path),
            "keys"   => self::checkKeys($type[1], $type[2], $value, $path),
            "object" => self::checkFields($type[1], $value, $path),
        };
    }


    private static function checkItems(string|array $type, array $value, string $path): ?string
    {
        foreach ($value as $key => $item) {
            $problem = self::checkType($type, $item, self::child($path, $key));
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }


    private static function checkNames(string|array $type, array $value, string $path): ?string
    {
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return "The field $path must be an object whose keys are names, not numbers.";
            }
            $problem = self::checkType($type, $item, "$path.$key");
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }


    /**
     * @param list<string> $names
     */
    private static function checkKeys(array $names, string|array $type, array $value, string $path): ?string
    {
        foreach ($value as $key => $item) {
            if (!in_array($key, $names, true)) {
                return "The field $path must hold only the keys " . implode(", ", $names) . ".";
            }
            $problem = self::checkType($type, $item, "$path.$key");
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }


    /**
     * @param array<string, string|array> $fields
     */
    private static function checkFields(array $fields, array $value, string $path): ?string
    {
        foreach ($fields as $key => $type) {
            $name = ltrim($key, "!");
            if (!array_key_exists($name, $value)) {
                if ($key !== $name) {
                    return "The field $path.$name is missing.";
                }
                continue;
            }
            $problem = self::checkType($type, $value[$name], "$path.$name");
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }


    private static function child(string $path, int|string $key): string
    {
        return is_int($key) ? "{$path}[$key]" : "$path.$key";
    }


    private static function describe(string|array $type): string
    {
        return match (is_array($type) ? $type[0] : $type) {
            "string"   => "a string",
            "int"      => "an integer",
            "bool"     => "a boolean",
            "number"   => "a finite number",
            "scalar"   => "a string, a number, a boolean or null",
            "ttiBlock" => "a TTI block of 256 hexadecimal digits",
            "timeBase" => "a time base such as \"1/1000\"",
            "range"    => "an integer from $type[1] to $type[2]",
            "oneOf"    => "one of " . implode(", ", array_map(fn (string $value): string => json_encode($value, JSON_THROW_ON_ERROR), $type[1])),
            "list"     => "a list or an object",
            default    => "an object",
        };
    }
}
