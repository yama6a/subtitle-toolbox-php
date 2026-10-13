<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Formatters\Options\MccWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Mcc;
use SubtitleToolbox\Parsers\MccParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Writes MCC (MacCaption) files with one line per frame that holds CEA-608 data, as ffmpeg writes them.
 * The CEA-608 field 1 data are the byte pairs that SccFormatter writes. Field 2 holds null pairs, and the CEA-708 part
 * holds empty triplets, as MacCaption writes them for CEA-608 captions.
 *
 * @see Mcc
 */
final class MccFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = MccWriteOptions::class;

    private const DEFAULT_FRAME_RATE = "29.97";
    private const DEFAULT_VERSION    = "2.0";
    private const CREATION_PROGRAM   = "Subtitle Toolbox";

    private const NULL_PAIR = 0x8080;

    // A CEA-608 byte pair lasts 1001/30000 s.
    private const CEA608_NUMERATOR   = 30000;
    private const CEA608_DENOMINATOR = 1001;


    /**
     * @throws UnwritableContentException for a cue that SCC cannot hold, see SccFormatter::format().
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options     ??= new WriteOptions();
        $formatOptions = $this->formatOptions($options);
        $data          = $subtitle->findFormatData(MccParser::FORMAT_DATA_KEY) ?? [];
        $frameRate     = $formatOptions->frameRate === null ? $data["frameRate"] ?? self::DEFAULT_FRAME_RATE : Mcc::frameRateKey($formatOptions->frameRate);
        [$labels, $numerator, $denominator, $code, $tripletCount] = Mcc::FRAME_RATES[$frameRate];
        $dropFrame = $denominator !== 1 && $labels !== 24 && ($formatOptions->dropFrame ?? $data["dropFrame"] ?? true);
        $version   = $data["version"] ?? self::DEFAULT_VERSION;
        if ($dropFrame && $labels === 60) {
            // Version 1.0 lists no 60DF time code rate.
            $version = "2.0";
        }

        $byFrame = [];
        foreach ((new SccFormatter())->captionWords($subtitle, false) as $frame608 => $word) {
            $byFrame[(int) round($frame608 * self::CEA608_DENOMINATOR * $numerator / (self::CEA608_NUMERATOR * $denominator))][] = $word;
        }

        $lines    = "";
        $rate     = new FrameRate($numerator / $denominator);
        $sequence = 0;
        foreach ($byFrame as $frame => $words) {
            $triplets = [];
            foreach ($words as $word) {
                $triplets[] = [Mcc::NTSC_FIELD_1, true, $word];
            }
            $triplets[] = [Mcc::NTSC_FIELD_2, true, self::NULL_PAIR];
            $triplets   = array_pad($triplets, $tripletCount, [Mcc::DTVCC_DATA, false, 0]);

            [$hours, $minutes, $seconds, $frames] = Timecode::frameNumber($frame, $rate, $dropFrame);
            $packet  = Mcc::writePacket($code, $sequence++ & 0xFFFF, $triplets);
            $lines  .= sprintf("%02d:%02d:%02d:%02d\t%s", $hours, $minutes, $seconds, $frames, Mcc::encodeHex($packet)) . LineEnding::Lf->value;
        }
        $output = $this->header($version, $labels . ($dropFrame ? "DF" : ""), $data["header"] ?? [], $lines) . $lines;

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Writes the header text, then the fields of the header format data or new ones, then the time code rate.
     * A new UUID comes from the hash of the data lines, so the same captions give the same file on the same day.
     *
     * @param array<string, string> $fields
     */
    private function header(string $version, string $timeCodeRate, array $fields, string $lines): string
    {
        $fields = array_replace(["UUID" => self::uuid(sha1($lines, true)), "Creation Program" => self::CREATION_PROGRAM, "Creation Date" => gmdate("l, F j, Y")], $fields);
        $fields["Time Code Rate"] = $timeCodeRate;

        $output = sprintf(Mcc::HEADER, $version, Mcc::headerRates($version)) . LineEnding::Lf->value . LineEnding::Lf->value;
        foreach ($fields as $name => $value) {
            $output .= "$name=$value" . LineEnding::Lf->value;
        }

        return $output . LineEnding::Lf->value;
    }


    /**
     * Returns a name-based UUID of version 5 from a SHA-1 hash, as RFC 9562 defines it.
     */
    private static function uuid(string $hash): string
    {
        $bytes    = substr($hash, 0, 16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x50);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return strtoupper(vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($bytes), 4)));
    }
}
