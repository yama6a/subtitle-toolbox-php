<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Container\ContainerFormat;
use SubtitleToolbox\Container\ContainerReader;
use SubtitleToolbox\Container\Containers;
use SubtitleToolbox\Container\SubtitleTrack;
use SubtitleToolbox\Encoding\DecodedText;
use SubtitleToolbox\Exceptions\CueNotFoundException;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnknownFormatException;
use SubtitleToolbox\Exceptions\UnsupportedFormatException;
use SubtitleToolbox\Formatters\ImageFormatter;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrLanguage;
use SubtitleToolbox\Ocr\OcrRunner;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\Parsers\VobSubParser;


final class Subtitle implements \IteratorAggregate, \Countable
{
    use Retiming;
    use Validation;
    use CueEditing;
    use Fixes;
    use TextTransforms;
    use CueLookup;
    use ArrayConversion;
    use ShortCueMerging;

    /** @var array<int, SubtitleCue> */
    private array $cues = [];

    public const METADATA_TITLE    = "title";
    public const METADATA_AUTHOR   = "author";
    public const METADATA_ARTIST   = "artist";
    public const METADATA_ALBUM    = "album";
    public const METADATA_LANGUAGE = "language";

    /** @var array<string, string> */
    private array $metadata = [];

    /** @var list<Comment> */
    private array $comments = [];

    /** @var array<string, array> */
    private array $formatData = [];

    /** @var list<ParseWarning> */
    private array $parseWarnings = [];

    private ?string $sourceEncoding = null;

    private ?Format $format = null;


    /**
     * Copies the cues too, so edits on the copy leave the original unchanged.
     */
    public function __clone()
    {
        $this->cues           = array_map(fn (SubtitleCue $cue): SubtitleCue => clone $cue, $this->cues);
        $this->cueLookupIndex = null;
    }


    /**
     * Drops the cue lookup cache, because its edit count is only valid in the process that built it.
     */
    public function __wakeup(): void
    {
        $this->cueLookupIndex = null;
    }


    /**
     * Reads the file at $path in $format. An MKV, WebM or MP4 file throws, see loadTrack().
     * For Format::VobSub, $path is the .idx or the .sub file, and the other file must lie next to it.
     * The content of the .idx file replaces VobSubReadOptions::$idx.
     */
    public static function load(string $path, Format $format, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
        if (($container = self::containerOf($path)) !== null) {
            throw new UnsupportedFormatException("$path is an " . Containers::label($container) . " file. Call loadTrack() with a track number.");
        }
        if ($format !== Format::VobSub) {
            return self::fromString(self::readFile($path), $format, $options);
        }

        (new VobSubParser())->useOptions($options);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $isIdx     = strtolower($extension) === "idx";
        $other     = self::pairedFile($path, $isIdx ? "sub" : "idx");
        [$idxPath, $subPath] = $isIdx ? [$path, $other] : [$other, $path];

        $vobSubOptions = OptionsCopy::with($options, ["format" => OptionsCopy::with($options->format ?? new VobSubReadOptions(), [
            "idx" => StringHelpers::decode(self::readFile($idxPath), $options->encoding)->content,
        ])]);

        return self::parseUtf8(self::readFile($subPath), Format::VobSub, $vobSubOptions);
    }


    /**
     * Reads the file at $path in the format that its content shows, else in the format of its extension.
     * It tries only formats whose isAutoDetected() is true. An MKV, WebM or MP4 file must hold exactly 1 subtitle track.
     * An empty or whitespace-only file gives an empty subtitle in the format of its extension.
     *
     * @throws UnknownFormatException when no such format matches.
     */
    public static function loadAutoDetectFormat(string $path, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
        if (($container = self::containerOf($path)) !== null) {
            return self::readOnlyTrack(Containers::open($path), $container, $options);
        }

        $content = self::readFile($path);
        $decoded = StringHelpers::decode($content, $options->encoding, !self::isBinary(Format::detect($content)));
        if (trim(StringHelpers::removeUtf8Bom($decoded->content)) === "" && ($byPath = self::formatOfExtension($path)) !== null) {
            return (new self())->setFormat($byPath);
        }
        $format = self::detectFormat($decoded->content, $path) ?? throw new UnknownFormatException(self::unknownFormatMessage("load()"));

        return $format === Format::VobSub ? self::load($path, $format, $options) : self::parseDecoded($decoded, $format, $options);
    }


    /**
     * Reads the subtitle track with the number $trackNumber of an MKV, WebM or MP4 file.
     * The codec of the track picks the parser. tracks() lists the track numbers.
     */
    public static function loadTrack(string $path, int $trackNumber, ?ReadOptions $options = null): self
    {
        return self::readTrack(Containers::open(self::checkedPath($path)), $trackNumber, $options ?? new ReadOptions());
    }


    /**
     * Returns the subtitle tracks of an MKV, WebM or MP4 file.
     *
     * @return list<SubtitleTrack>
     */
    public static function tracks(string $path): array
    {
        return Containers::open(self::checkedPath($path))->getSubtitleTracks();
    }


    /**
     * Reads $content in $format. MKV, WebM and MP4 content throws, see loadTrack().
     * A UTF-16 or UTF-32 BOM, or else ReadOptions::$encoding such as "Windows-1252", sets the encoding to convert from.
     * Valid UTF-8 content without zero bytes stays as is. See docs/encodings.md for UTF-16 without a BOM.
     */
    public static function fromString(string $content, Format $format, ?ReadOptions $options = null): self
    {
        if (($container = Containers::detect($content)) !== null) {
            throw new UnsupportedFormatException("The content is an " . Containers::label($container) . " file. Call loadTrack() with a track number.");
        }
        $options ??= new ReadOptions();

        return self::parseDecoded(StringHelpers::decode($content, $options->encoding, !self::isBinary($format)), $format, $options);
    }


    /**
     * Reads $content in the format that Format::detect() finds. It tries only formats whose isAutoDetected() is true.
     * MKV, WebM and MP4 content must hold exactly 1 subtitle track.
     *
     * @throws UnknownFormatException when no such format matches.
     */
    public static function fromStringAutoDetectFormat(string $content, ?ReadOptions $options = null): self
    {
        $options ??= new ReadOptions();
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
     * Returns the format that load(), loadAutoDetectFormat(), loadTrack(), a container reader or a fromString call read.
     * Returns null for a subtitle from new Subtitle() or fromArray(). For an MKV track, it is the format of the track codec.
     */
    public function getFormat(): ?Format
    {
        return $this->format;
    }


    /**
     * Returns the encoding that load(), loadAutoDetectFormat() or a fromString call read the text from, for example
     * "UTF-8", "UTF-16LE" or "Windows-1252". Returns null for a binary format, an MKV, WebM or MP4 track, and a subtitle
     * that no parser read. See docs/encodings.md for the order of the checks.
     */
    public function findSourceEncoding(): ?string
    {
        return $this->sourceEncoding;
    }


    /**
     * Sets the format that getFormat() returns.
     *
     * @internal
     */
    public function setFormat(?Format $format): self
    {
        $this->format = $format;

        return $this;
    }


    private static function parseDecoded(DecodedText $decoded, Format $format, ReadOptions $options): self
    {
        $subtitle                 = self::parseUtf8($decoded->content, $format, $options);
        $subtitle->sourceEncoding = self::isBinary($format) ? null : $decoded->encoding;
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


    private static function parseUtf8(string $content, Format $format, ReadOptions $options): self
    {
        $parserClass = FormatRegistry::parserClass($format)
            ?? throw new UnsupportedFormatException("The format {$format->value} can be written but not read.");

        $subtitle         = (new $parserClass())->parse($content, $options);
        $subtitle->format = $format;

        return $subtitle;
    }


    private static function readTrack(ContainerReader $reader, int $track, ReadOptions $options): self
    {
        return $reader->extract($track, $options);
    }


    private static function readOnlyTrack(ContainerReader $reader, ContainerFormat $container, ReadOptions $options): self
    {
        $tracks = $reader->getSubtitleTracks();
        $label  = Containers::label($container);
        if (count($tracks) !== 1) {
            throw new UnsupportedFormatException($tracks === [] ? "The $label file has no subtitle track." :
                "The $label file has " . count($tracks) . " subtitle tracks. Call loadTrack() with one of them:\n" .
                implode("\n", array_map(fn (SubtitleTrack $track): string => "  $track->number: " . $track->describe(), $tracks)));
        }

        return self::readTrack($reader, $tracks[0]->number, $options);
    }


    /**
     * Returns the format of the UTF-8 $content as loadAutoDetectFormat() and fromStringAutoDetectFormat() pick it, or null.
     * Without detection, it falls back to the extension of $path.
     *
     * @internal
     */
    public static function detectFormat(string $content, ?string $path = null): ?Format
    {
        $format      = Format::detect($content);
        $byExtension = $path === null ? null : Format::fromPath($path);
        // Detection returns TTML for an iTT file. IttParser reads the same cues and keeps the iTT timing.
        if ($format === Format::Ttml && $byExtension === Format::Itt) {
            return Format::Itt;
        }

        return $format ?? ($path === null ? null : self::formatOfExtension($path));
    }


    private static function formatOfExtension(string $path): ?Format
    {
        $byExtension = Format::fromPath($path);

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


    /**
     * Returns what the parser skipped or repaired in lenient mode. A subtitle that no parser read has none.
     *
     * @return list<ParseWarning>
     */
    public function getParseWarnings(): array
    {
        return $this->parseWarnings;
    }


    /**
     * @internal
     *
     * @param list<ParseWarning> $warnings
     */
    public function setParseWarnings(array $warnings): self
    {
        $this->parseWarnings = $warnings;

        return $this;
    }


    /**
     * Writes the subtitle to $path in $format, else in the format of the extension of $path. See toString().
     */
    public function save(string $path, ?Format $format = null, ?WriteOptions $options = null): void
    {
        $format ??= Format::fromPath($path)
            ?? throw new UnsupportedFormatException("The extension of $path names no format. Pass a format to save().");
        $content  = $this->toString($format, $options ?? new WriteOptions());

        if (@file_put_contents($path, $content) === false) {
            throw new InvalidArgumentException("Cannot write the file $path.");
        }
    }


    /**
     * Writes the subtitle in $format and throws on an image cue without text, unless the format writes images.
     * MicroDVD and iTT take the frame rate from the options, else from the format data of their parser.
     * TSV writes tabs and CSV from a TSV load writes commas, unless CsvWriteOptions::$delimiter is set.
     */
    public function toString(Format $format, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $options = $this->withFormatDefaults($format, $options);
        $formatterClass = FormatRegistry::formatterClass($format)
            ?? throw new UnsupportedFormatException("The format {$format->value} can be read but not written.");

        $subtitle = $this;
        if (!is_subclass_of($formatterClass, ImageFormatter::class)) {
            $imageCueIndexes = array_keys(array_filter($this->cues, fn (SubtitleCue $cue): bool =>
                CueImage::isImageCue($cue) && $cue->getLines() === []));

            if ($imageCueIndexes !== [] && !$options->skipImageCues) {
                throw new ImageCueWithoutTextException("Cue #{$imageCueIndexes[0]} holds an image but no text. " .
                                                       "Run recognizeText() first, or pass WriteOptions(skipImageCues: true).");
            }
            if ($imageCueIndexes !== []) {
                $subtitle = $this->withoutCues($imageCueIndexes);
            }
        }

        return (new $formatterClass())->format($subtitle, $options);
    }


    private function withFormatDefaults(Format $format, WriteOptions $options): WriteOptions
    {
        $formatOptions = $options->format;
        $delimiter = match (true) {
            $format === Format::Tsv                                   => "\t",
            $format === Format::Csv && $this->format === Format::Tsv => ",",
            default                                                   => null,
        };
        $csv = $formatOptions ?? new CsvWriteOptions();
        if ($delimiter !== null && $csv instanceof CsvWriteOptions && $csv->delimiter === null) {
            $formatOptions = OptionsCopy::with($csv, ["delimiter" => $delimiter]);
        }
        if ($format === Format::MicroDvd && ($formatOptions === null || ($formatOptions instanceof MicroDvdWriteOptions && $formatOptions->frameRate === null))) {
            $formatOptions = OptionsCopy::with($formatOptions ?? new MicroDvdWriteOptions(), [
                "frameRate" => $this->findFormatData(MicroDvdParser::FORMAT_DATA_KEY)["frameRate"]
                    ?? throw new InvalidArgumentException("MicroDVD output needs the frame rate of the video. Set MicroDvdWriteOptions::\$frameRate."),
            ]);
        }
        if ($format === Format::Itt && ($formatOptions === null || ($formatOptions instanceof IttWriteOptions && $formatOptions->frameRate === null))
            && !isset($this->findFormatData(IttParser::FORMAT_DATA_KEY)["frameRate"])) {
            throw new InvalidArgumentException("iTT output needs the frame rate of the video. Set IttWriteOptions::\$frameRate.");
        }

        return $formatOptions === $options->format ? $options : OptionsCopy::with($options, ["format" => $formatOptions]);
    }


    /**
     * @return array<int, SubtitleCue>
     */
    public function getCues(): array
    {
        return $this->cues;
    }


    /**
     * Adds the cue and sorts the cues by start time.
     */
    public function addCue(SubtitleCue $cue): self
    {
        return $this->addCues([$cue]);
    }


    /**
     * Adds the cues and sorts all cues by start time once. Each comment stays before the cue it came before.
     *
     * @param iterable<SubtitleCue> $cues
     */
    public function addCues(iterable $cues): self
    {
        $anchors = CommentAnchors::of($this->cues, $this->comments);
        foreach ($cues as $cue) {
            if (!$cue instanceof SubtitleCue) {
                throw new InvalidArgumentException("addCues() takes SubtitleCue objects only, got " . get_debug_type($cue) . ".");
            }
            $this->cues[] = $cue;
        }

        $this->sortCues();
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Removes the cue at $cueIndex and numbers the remaining cues from 0 again.
     *
     * @throws CueNotFoundException when no cue has the index $cueIndex.
     */
    public function removeCue(int $cueIndex): self
    {
        if (!array_key_exists($cueIndex, $this->cues)) {
            throw new CueNotFoundException("Cannot remove cue $cueIndex: the cue does not exist.");
        }

        unset($this->cues[$cueIndex]);

        return $this->reIndexCues();
    }


    /**
     * Sorts the cues by start time and numbers them from 0. Each comment stays before its cue.
     */
    public function reIndexCues(): self
    {
        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $this->sortCues();
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Returns a copy with the metadata, the format data and the format of this subtitle, but without cues and comments.
     *
     * @internal
     */
    public function emptyCopy(): self
    {
        $copy           = clone $this;
        $copy->cues     = [];
        $copy->comments = [];

        return $copy;
    }


    /**
     * Sets the lines that $linesOf returns for each cue, or keeps the cue as is when it returns null.
     * Then it removes, in one pass, each cue that had text before and has none after.
     * $hasText decides, Markup::hasVisibleText() by default.
     *
     * @param callable(SubtitleCue, int): ?list<string> $linesOf
     * @param ?callable(array<string>): bool            $hasText
     *
     * @return \SplObjectStorage<SubtitleCue, true> the removed cues
     *
     * @internal
     */
    public function setLinesAndRemoveEmptied(callable $linesOf, ?callable $hasText = null): \SplObjectStorage
    {
        $hasText ??= Markup::hasVisibleText(...);
        $emptied   = new \SplObjectStorage();
        foreach ($this->cues as $index => $cue) {
            $before = $cue->getLines();
            $lines  = $linesOf($cue, $index);
            if ($lines === null) {
                continue;
            }

            $cue->setLines($lines);
            if ($hasText($before) && !$hasText($cue->getLines())) {
                $emptied[$cue] = true;
            }
        }
        if ($emptied->count() > 0) {
            $this->removeCuesWhere(fn (SubtitleCue $cue): bool => isset($emptied[$cue]));
        }

        return $emptied;
    }


    public function findMetadata(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }


    /**
     * Sets one metadata value, or removes the key when the value is null.
     */
    public function setMetadata(string $key, ?string $value): self
    {
        if ($value === null) {
            unset($this->metadata[$key]);
        } else {
            $this->metadata[$key] = $value;
        }

        return $this;
    }


    /**
     * @return array<string, string>
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }


    /**
     * @return list<Comment>
     */
    public function getComments(): array
    {
        return $this->comments;
    }


    /**
     * Adds a comment that a formatter writes before the cue at the given index, or after the last cue.
     */
    public function addComment(string $text, int $beforeCueIndex): self
    {
        if ($beforeCueIndex < 0) {
            throw new InvalidArgumentException("The cue index of a comment must not be negative, got $beforeCueIndex.");
        }

        $this->comments = CommentAnchors::sorted([...$this->comments, new Comment($text, $beforeCueIndex)]);

        return $this;
    }


    /**
     * Returns the data under $key, the value of a Format case such as "ass", or an empty array.
     */
    public function findFormatData(string $key): array
    {
        return $this->formatData[$key] ?? [];
    }


    /**
     * Stores $data under $key. An empty array removes the key.
     *
     * @throws InvalidArgumentException when a field that a formatter reads has the wrong type, as fromArray() checks it.
     */
    public function setFormatData(string $key, array $data): self
    {
        $this->formatData = FormatDataSchema::withData($this->formatData, $key, $data, false);

        return $this;
    }


    private function sortCues(): void
    {
        $this->cues = CueList::inStartOrder($this->cues);
    }


    /**
     * Sets the lines of every image cue without text to the text that $engine reads, see OcrRunner.
     *
     * @param OcrLanguage|string|null $language An OcrLanguage case, or the name of any installed Tesseract model, for
     *                                          example a custom trained model. php-glyph-ocr ignores it.
     */
    public function recognizeText(OcrEngine $engine, OcrLanguage|string|null $language = null): self
    {
        (new OcrRunner($engine))->run($this, $language);

        return $this;
    }


    /**
     * @param list<int> $cueIndexes
     */
    private function withoutCues(array $cueIndexes): self
    {
        $copy = clone $this;
        $kept = array_diff_key($this->cues, array_flip($cueIndexes));

        $copy->cues     = array_values($kept);
        $copy->comments = CommentAnchors::comments($copy->cues, $this->comments, CommentAnchors::of($kept, $this->comments));

        return $copy;
    }

}
