<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Encoding\Cea708Encoder;
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
 * Writes MCC (MacCaption) files with one line per frame that holds caption data, as ffmpeg writes them.
 * The CEA-608 field 1 data are the byte pairs that SccFormatter writes with its fit option. Field 2 holds null pairs.
 * The CEA-708 data are the service blocks of Cea708Encoder, one DTVCC packet per frame.
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

    // A DTVCC packet starts with a header byte, then a service block header byte for service 1.
    private const SERVICE_1 = 0x20;

    // A CEA-608 byte pair lasts 1001/30000 s.
    private const CEA608_NUMERATOR   = 30000;
    private const CEA608_DENOMINATOR = 1001;


    /**
     * @throws UnwritableContentException for a cue that SCC cannot hold with SccWriteOptions::$fit, see SccFormatter::format().
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
        foreach ((new SccFormatter())->captionWords($subtitle, true) as $frame608 => $word) {
            $byFrame[(int) round($frame608 * self::CEA608_DENOMINATOR * $numerator / (self::CEA608_NUMERATOR * $denominator))][] = $word;
        }
        // A packet holds a header byte and a block header byte. A cc_data triplet holds 2 bytes.
        $capacity = 2 * ($tripletCount - 2) - 2;
        $blocks   = (new Cea708Encoder())->blocks($subtitle, fn (float $seconds): int => (int) round($seconds * $numerator / $denominator), $capacity);
        $frames   = array_unique([...array_keys($byFrame), ...array_keys($blocks)]);
        sort($frames);

        $lines    = "";
        $rate     = new FrameRate($numerator / $denominator);
        $sequence = 0;
        foreach ($frames as $frame) {
            $triplets = [];
            foreach ($byFrame[$frame] ?? [self::NULL_PAIR] as $word) {
                $triplets[] = [Mcc::NTSC_FIELD_1, true, $word];
            }
            $triplets[] = [Mcc::NTSC_FIELD_2, true, self::NULL_PAIR];
            if (isset($blocks[$frame])) {
                array_push($triplets, ...self::dtvccTriplets($blocks[$frame], $sequence));
            }
            $triplets = array_pad($triplets, $tripletCount, [Mcc::DTVCC_DATA, false, 0]);

            [$hours, $minutes, $seconds, $frames] = Timecode::frameNumber($frame, $rate, $dropFrame);
            $packet  = Mcc::writePacket($code, $sequence++ & 0xFFFF, $triplets);
            $lines  .= sprintf("%02d:%02d:%02d:%02d\t%s", $hours, $minutes, $seconds, $frames, Mcc::encodeHex($packet)) . LineEnding::Lf->value;
        }
        $output = $this->header($version, $labels . ($dropFrame ? "DF" : ""), $data["header"] ?? [], $lines) . $lines;

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * Returns the triplets of a DTVCC packet with one service block. The packet header holds a sequence number from
     * 0 to 3 and the packet size in pairs of bytes.
     *
     * @return list<array{int, bool, int}>
     */
    private static function dtvccTriplets(string $block, int $sequence): array
    {
        $packet  = chr(self::SERVICE_1 | strlen($block)) . $block;
        $packet .= strlen($packet) % 2 === 0 ? "\0" : "";
        $packet  = chr(($sequence & 3) << 6 | intdiv(strlen($packet) + 1, 2)) . $packet;

        $triplets = [];
        foreach (str_split($packet, 2) as $index => $pair) {
            $triplets[] = [$index === 0 ? Mcc::DTVCC_START : Mcc::DTVCC_DATA, true, unpack("n", $pair)[1]];
        }

        return $triplets;
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
