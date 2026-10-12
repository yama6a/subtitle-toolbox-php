<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\ContainerReader;
use SubtitleToolbox\Container\Containers;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Encoding\DecodedText;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnknownFormatException;
use SubtitleToolbox\Exceptions\UnsupportedFormatException;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;

/**
 * Reads files and strings for Subtitle::load() and the other read methods of Subtitle, and picks the format.
 *
 * @internal
 */
final class SubtitleReader
{
    public static function load(string $path, Format $format, ReadOptions $options): Subtitle
    {
        if (($container = self::containerOf($path)) !== null) {
            throw new UnsupportedFormatException("$path is an " . Containers::label($container) . " file. Call loadTrack() with a track number.");
        }
        if ($format !== Format::VobSub) {
            return self::fromString(self::readFile($path), $format, $options);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $isIdx     = strtolower($extension) === "idx";
        $other     = self::pairedFile($path, $isIdx ? "sub" : "idx");
        [$idxPath, $subPath] = $isIdx ? [$path, $other] : [$other, $path];

        $vobSub        = FormatOptionsList::find($options->formats, VobSubReadOptions::class) ?? new VobSubReadOptions();
        $vobSubOptions = OptionsCopy::with($options, ["formats" => FormatOptionsList::with($options->formats, OptionsCopy::with($vobSub, [
            "idx" => StringHelpers::decode(self::readFile($idxPath), $options->encoding)->content,
        ]))]);

        return self::parseUtf8(self::readFile($subPath), Format::VobSub, $vobSubOptions);
    }


    public static function loadAutoDetect(string $path, ReadOptions $options): Subtitle
    {
        if (($container = self::containerOf($path)) !== null) {
            return self::readOnlyTrack(Containers::open($path), $container, $options);
        }

        $content = self::readFile($path);
        $decoded = StringHelpers::decode($content, $options->encoding, !self::isBinary(Format::detect($content)));
        if (trim(StringHelpers::removeUtf8Bom($decoded->content)) === "" && ($byPath = self::formatOfExtension($path)) !== null) {
            return (new Subtitle())->setFormat($byPath);
        }
        $format = self::detectFormat($decoded->content, $path) ?? throw new UnknownFormatException(self::unknownFormatMessage("load()"));

        return $format === Format::VobSub ? self::load($path, $format, $options) : self::parseDecoded($decoded, $format, $options);
    }


    public static function loadTrack(string $path, int $trackNumber, ReadOptions $options): Subtitle
    {
        return Containers::open(self::checkedPath($path))->extract($trackNumber, $options);
    }


    /**
     * @return list<SubtitleTrack>
     */
    public static function tracks(string $path): array
    {
        return Containers::open(self::checkedPath($path))->getSubtitleTracks();
    }


    public static function fromString(string $content, Format $format, ReadOptions $options): Subtitle
    {
        if (($container = Containers::detect($content)) !== null) {
            throw new UnsupportedFormatException("The content is an " . Containers::label($container) . " file. Call loadTrack() with a track number.");
        }

        return self::parseDecoded(StringHelpers::decode($content, $options->encoding, !self::isBinary($format)), $format, $options);
    }


    public static function fromStringAutoDetect(string $content, ReadOptions $options): Subtitle
    {
        if (($container = Containers::detect($content)) !== null) {
            $stream = fopen("php://temp", "w+b");
            fwrite($stream, $content);
            rewind($stream);

            return self::readOnlyTrack(Containers::open($stream), $container, $options);
        }

        $decoded = StringHelpers::decode($content, $options->encoding, !self::isBinary(Format::detect($content)));
        $format  = self::detectFormat($decoded->content) ?? throw new UnknownFormatException(self::unknownFormatMessage("fromString()"));

        return self::parseDecoded($decoded, $format, $options);
    }


    /**
     * Returns the format of the UTF-8 $content as loadAutoDetect() and fromStringAutoDetect() pick it, or null.
     * Without detection, it falls back to the extension of $path.
     */
    public static function detectFormat(string $content, ?string $path = null): ?Format
    {
        $format      = Format::detect($content);
        $byExtension = $path === null ? null : Format::tryFromPath($path);
        // Detection returns TTML for an iTT file. IttParser reads the same cues and keeps the iTT timing.
        if ($format === Format::Ttml && $byExtension === Format::Itt) {
            return Format::Itt;
        }

        return $format ?? ($path === null ? null : self::formatOfExtension($path));
    }


    private static function parseDecoded(DecodedText $decoded, Format $format, ReadOptions $options): Subtitle
    {
        $subtitle = self::parseUtf8($decoded->content, $format, $options);
        $subtitle->setSourceEncoding(self::isBinary($format) ? null : $decoded->encoding);
        if ($options->lenient && $decoded->warning !== null) {
            $warning = new ParseWarning($decoded->warning, null, null, [], ParseWarningAction::Repaired);
            $subtitle->setParseWarnings([$warning, ...$subtitle->getParseWarnings()]);
        }

        return $subtitle;
    }


    private static function isBinary(?Format $format): bool
    {
        $parserClass = $format === null ? null : FormatRegistry::parserClass($format);

        return $parserClass !== null && $parserClass::readsBinary();
    }


    private static function parseUtf8(string $content, Format $format, ReadOptions $options): Subtitle
    {
        $parserClass = FormatRegistry::parserClass($format)
            ?? throw new UnsupportedFormatException("The format {$format->value} can be written but not read.");

        return (new $parserClass())->parse($content, $options)->setFormat($format);
    }


    private static function readOnlyTrack(ContainerReader $reader, ContainerFormat $container, ReadOptions $options): Subtitle
    {
        $tracks = $reader->getSubtitleTracks();
        $label  = Containers::label($container);
        if (count($tracks) !== 1) {
            throw new UnsupportedFormatException($tracks === [] ? "The $label file has no subtitle track." :
                "The $label file has " . count($tracks) . " subtitle tracks. Call loadTrack() with one of them:\n" .
                implode("\n", array_map(fn (SubtitleTrack $track): string => "  $track->number: " . $track->describe(), $tracks)));
        }

        return $reader->extract($tracks[0]->number, $options);
    }


    private static function formatOfExtension(string $path): ?Format
    {
        $byExtension = Format::tryFromPath($path);

        // An extension that a format without detection also uses, such as .json for Deepgram, says nothing.
        return $byExtension !== null && self::extensionOnlyOfAutoDetectedFormats($path) && $byExtension->canRead() ? $byExtension : null;
    }


    private static function unknownFormatMessage(string $call): string
    {
        return "Format detection found no subtitle format. Call $call with a format. " .
                                          "Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram.";
    }


    private static function extensionOnlyOfAutoDetectedFormats(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach (Format::cases() as $format) {
            if (!$format->isAutoDetected() && in_array($extension, $format->extensions(), true)) {
                return false;
            }
        }

        return true;
    }


    private static function pairedFile(string $path, string $extension): string
    {
        $own  = pathinfo($path, PATHINFO_EXTENSION);
        $stem = match (true) {
            $own !== ""                => substr($path, 0, -strlen($own)),
            str_ends_with($path, ".") => $path,
            default                    => "$path.",
        };
        foreach ([$extension, strtoupper($extension)] as $candidate) {
            if (is_file($stem . $candidate)) {
                return $stem . $candidate;
            }
        }

        throw new InvalidArgumentException("VobSub needs the .$extension file next to $path, but $stem$extension does not exist.");
    }


    private static function checkedPath(string $path): string
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("The file $path does not exist.");
        }

        return $path;
    }


    private static function readFile(string $path, ?int $length = null): string
    {
        $content = @file_get_contents(self::checkedPath($path), false, null, 0, $length);
        if ($content === false) {
            throw new InvalidArgumentException("Cannot read the file $path.");
        }

        return $content;
    }


    private static function containerOf(string $path): ?ContainerFormat
    {
        return Containers::detect(self::readFile($path, Containers::HEAD_LENGTH));
    }
}
