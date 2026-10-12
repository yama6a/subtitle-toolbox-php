<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;

/**
 * @see https://datatracker.ietf.org/doc/html/rfc8216#section-3.5
 */
final class TimestampMap
{
    public const HEADER_NAME = "X-TIMESTAMP-MAP";

    public const CLOCK_RATE = 90000;

    public const MPEGTS_WRAP = 8589934592;

    private const LOCAL_PATTERN = "/^(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})$/";


    /**
     * Creates the map that says the WebVTT cue time $local in seconds plays at the 90 kHz MPEG-2 timestamp $mpegts.
     */
    public function __construct(
        public readonly int $mpegts,
        public readonly float $local = 0,
    ) {
        if ($mpegts < 0 || $mpegts >= self::MPEGTS_WRAP) {
            throw new InvalidArgumentException("The MPEGTS value must be a 33-bit timestamp from 0 to " .
                                               (self::MPEGTS_WRAP - 1) . ", got $mpegts.");
        }

        if (!is_finite($local) || $local < 0) {
            throw new InvalidArgumentException("The LOCAL time must be a finite number that is not negative, got " . OptionChecks::text($local) . ".");
        }
    }


    /**
     * Parses a header line such as X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:900000, in either attribute order.
     */
    public static function fromHeader(string $line): self
    {
        if (!self::isHeader($line)) {
            throw new ParsingException("The line \"$line\" does not start with " . self::HEADER_NAME . "=.");
        }

        $attributes = [];
        foreach (explode(",", substr(trim($line), strlen(self::HEADER_NAME) + 1)) as $attribute) {
            [$name, $value]                = array_pad(explode(":", trim($attribute), 2), 2, "");
            $attributes[strtoupper($name)] = $value;
        }

        if (!preg_match("/^\d+$/", $attributes["MPEGTS"] ?? "")
            || !preg_match(self::LOCAL_PATTERN, $attributes["LOCAL"] ?? "", $local)) {
            throw new ParsingException("The " . self::HEADER_NAME . " header \"$line\" needs a LOCAL cue time and an " .
                                       "integer MPEGTS value.");
        }

        $seconds = Timecode::toSeconds((int) $local[1], (int) $local[2], (int) $local[3], $local[4]);

        return new self((int) $attributes["MPEGTS"], $seconds);
    }


    /**
     * Returns the map from the header lines of a parsed WebVTT subtitle, or null when it has none.
     */
    public static function fromSubtitle(Subtitle $subtitle): ?self
    {
        foreach ($subtitle->findFormatData(WebVttParser::FORMAT_DATA_KEY)["headerLines"] ?? [] as $line) {
            if (self::isHeader($line)) {
                return self::fromHeader($line);
            }
        }

        return null;
    }


    /**
     * Returns whether the WebVTT header line is an X-TIMESTAMP-MAP line.
     *
     * @internal
     */
    public static function isHeader(string $line): bool
    {
        return str_starts_with(trim($line), self::HEADER_NAME . "=");
    }


    /**
     * Returns the WebVTT header lines without the X-TIMESTAMP-MAP lines.
     *
     * @internal
     *
     * @param list<string> $headerLines
     * @return list<string>
     */
    public static function withoutHeader(array $headerLines): array
    {
        return array_values(array_filter($headerLines, fn (string $line): bool => !self::isHeader($line)));
    }


    /**
     * Returns the header line, for example X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:900000.
     *
     * @internal
     */
    public function toHeader(): string
    {
        return sprintf("%s=LOCAL:%s,MPEGTS:%d", self::HEADER_NAME, Markup::coreTimestamp($this->local), $this->mpegts);
    }


    /**
     * Returns the seconds to add to a cue time to get its time after the stream start $streamStartPts.
     */
    public function offset(int $streamStartPts): float
    {
        // RFC 8216 section 3.5 asks clients to allow for 33-bit timestamps that wrapped.
        $ticks = $this->mpegts - $streamStartPts;
        if ($ticks >= self::MPEGTS_WRAP / 2) {
            $ticks -= self::MPEGTS_WRAP;
        } elseif ($ticks < -self::MPEGTS_WRAP / 2) {
            $ticks += self::MPEGTS_WRAP;
        }

        return $ticks / self::CLOCK_RATE - $this->local;
    }
}
