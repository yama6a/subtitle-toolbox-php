<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\SubtitleCue;

final class CueImage
{
    public const FORMAT_DATA_KEY = "image";

    /** Largest width or height of an image in pixels. */
    public const MAX_SIDE = 7680;

    /** Largest number of pixels of an image: a full 3840x2160 frame. */
    public const MAX_PIXELS = 3840 * 2160;

    private const INTEGER_KEYS = ["x", "y", "width", "height", "screenWidth", "screenHeight"];


    /**
     * Holds a PNG bitmap with its top left position and its size in pixels on a screen of the given size.
     */
    public function __construct(
        public readonly string $png,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $screenWidth,
        public readonly int $screenHeight,
        public readonly bool $forced = false,
    ) {
        if ($width < 1 || $height < 1 || $screenWidth < 1 || $screenHeight < 1) {
            throw new InvalidArgumentException("Cannot create a cue image of {$width}x{$height} pixels on a screen of " .
                                               "{$screenWidth}x{$screenHeight} pixels: every size must be at least 1.");
        }
        self::checkSize($width, $height, "create a cue image");
    }


    /**
     * Throws InvalidArgumentException when an image of $width x $height pixels does not fit MAX_SIDE and MAX_PIXELS.
     * The message is "Cannot $action: " and the reason, for example "Cannot decode the PNG: an image of ...".
     *
     * @internal
     */
    public static function checkSize(int $width, int $height, string $action): void
    {
        $tooLarge = self::sizeLimitError($width, $height);
        if ($tooLarge !== null) {
            throw new InvalidArgumentException("Cannot $action: $tooLarge");
        }
    }


    /**
     * Returns why an image of $width x $height pixels is too large, or null when it fits MAX_SIDE and MAX_PIXELS.
     *
     * @internal
     */
    public static function sizeLimitError(int $width, int $height): ?string
    {
        if ($width <= self::MAX_SIDE && $height <= self::MAX_SIDE && $width * $height <= self::MAX_PIXELS) {
            return null;
        }

        return "an image of {$width}x{$height} pixels is larger than the limit of " . self::MAX_SIDE . " pixels per side " .
               "and " . self::MAX_PIXELS . " pixels in total.";
    }


    /**
     * Returns true when the cue holds an image in the format data key "image", with or without text lines.
     *
     * @internal
     */
    public static function isImageCue(SubtitleCue $cue): bool
    {
        return $cue->findFormatData(self::FORMAT_DATA_KEY) !== [];
    }


    /**
     * Reads the image from the format data key "image" of the cue.
     */
    public static function fromCue(SubtitleCue $cue): self
    {
        $data = $cue->findFormatData(self::FORMAT_DATA_KEY);
        if ($data === []) {
            throw new InvalidArgumentException("Cannot read the image of cue {$cue->getStart()} to {$cue->getEnd()}: " .
                                               "the cue holds no image.");
        }

        foreach (self::INTEGER_KEYS as $key) {
            if (!is_int($data[$key] ?? null)) {
                throw new InvalidArgumentException("Cannot read the image of cue {$cue->getStart()} to {$cue->getEnd()}: " .
                                                   "the image data has no integer \"$key\".");
            }
        }
        if (!is_string($data["png"] ?? null)) {
            throw new InvalidArgumentException("Cannot read the image of cue {$cue->getStart()} to {$cue->getEnd()}: " .
                                               "the image data has no string \"png\".");
        }

        return new self($data["png"], $data["x"], $data["y"], $data["width"], $data["height"],
                        $data["screenWidth"], $data["screenHeight"], (bool)($data["forced"] ?? false));
    }


    /**
     * Writes the image to the format data key "image" of the cue and keeps its text lines.
     * It also sets the forced flag of the cue.
     */
    public function applyTo(SubtitleCue $cue): void
    {
        $cue->setForced($this->forced)->setFormatData(self::FORMAT_DATA_KEY, [
            "png"          => $this->png,
            "x"            => $this->x,
            "y"            => $this->y,
            "width"        => $this->width,
            "height"       => $this->height,
            "screenWidth"  => $this->screenWidth,
            "screenHeight" => $this->screenHeight,
            "forced"       => $this->forced,
        ]);
    }
}
