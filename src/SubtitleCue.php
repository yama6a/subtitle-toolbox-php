<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SubtitleCue
{
    private float $start = 0;

    private float $end = 0;

    /** @var list<string> */
    private array $lines = [];

    private ?string $identifier = null;

    private Alignment $alignment = Alignment::BottomCenter;

    private bool $forced = false;

    /** @var array<string, array> */
    private array $formatData = [];


    /**
     * @param string|list<string> $lines
     */
    public function __construct(float $start = 0, float $end = 0, string|array $lines = "")
    {
        $this->setStart($start);
        $this->setEnd($end);
        $this->setLines($lines);
    }


    public function getStart(): float
    {
        return Timecode::roundToMilliseconds($this->start);
    }


    public function setStart(float $start): self
    {
        $before      = $this->start;
        $this->start = Timecode::roundToMilliseconds($start);
        if (CueHolders::isHeld($this)) {
            CueHolders::startChanged($this, $before);
            CueHolders::timeChanged($this);
        }

        return $this;
    }


    public function getEnd(): float
    {
        return Timecode::roundToMilliseconds($this->end);
    }


    public function setEnd(float $end): self
    {
        $this->end = Timecode::roundToMilliseconds($end);
        if (CueHolders::isHeld($this)) {
            CueHolders::timeChanged($this);
        }

        return $this;
    }


    /**
     * @return list<string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }


    /**
     * Sets the lines from a list, or from a string with one line per "\n".
     *
     * @param string|list<string> $lines
     */
    public function setLines(string|array $lines): self
    {
        if (is_string($lines)) {
            $lines = explode(LineEnding::Lf->value, $lines);
        }

        $this->lines = [];
        foreach ($lines as $line) {
            $line = StringHelpers::cleanString($line);
            if ($line !== "") {
                $this->lines[] = $line;
            }
        }

        return $this;
    }


    public function getText(): string
    {
        return implode(LineEnding::Lf->value, $this->lines);
    }


    public function addLine(string $line): self
    {
        $line = StringHelpers::cleanString($line);
        if ($line !== '') {
            $this->lines[] = $line;
        }

        return $this;
    }


    /**
     * Replaces the time of each word timestamp in the lines, such as <00:00:02.000>, with $map(seconds).
     * A time below 0 becomes 0.
     *
     * @param callable(float): float $map
     */
    public function mapWordTimestamps(callable $map): self
    {
        $this->lines = array_map(fn (string $line): string => Markup::mapWordTimestamps($line, $map), $this->lines);

        return $this;
    }


    /**
     * Moves the start, the end and each word timestamp to $map(seconds). A time below 0 becomes 0.
     *
     * @internal
     * @param callable(float): float $map
     */
    public function mapTimes(callable $map): self
    {
        return $this->setStart(max(0.0, $map($this->getStart())))
                    ->setEnd(max(0.0, $map($this->getEnd())))
                    ->mapWordTimestamps($map);
    }


    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }


    public function setIdentifier(?string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }


    /**
     * Returns Alignment::BottomCenter for a cue without a position, the default of every format.
     */
    public function getAlignment(): Alignment
    {
        return $this->alignment;
    }


    public function setAlignment(Alignment $alignment): self
    {
        $this->alignment = $alignment;

        return $this;
    }


    public function isForced(): bool
    {
        return $this->forced;
    }


    /**
     * Marks the cue as a forced narrative, which the player shows also when the viewer has turned subtitles off.
     */
    public function setForced(bool $forced): self
    {
        $this->forced = $forced;

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
     * Returns the format data of all formats, keyed by format.
     *
     * @return array<string, array>
     */
    public function getAllFormatData(): array
    {
        return $this->formatData;
    }


    /**
     * Stores $data under $key. An empty array removes the key.
     *
     * @throws InvalidArgumentException when a field that a formatter reads has the wrong type, as fromArray() checks it.
     */
    public function setFormatData(string $key, array $data): self
    {
        $this->formatData = FormatDataSchema::withData($this->formatData, $key, $data, true);

        return $this;
    }
}
