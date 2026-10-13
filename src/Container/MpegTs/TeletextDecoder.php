<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

/**
 * Decodes one teletext page from the PES data of a teletext stream into the text that the page shows over time.
 *
 * Teletext packets: ETS 300 706. Teletext in PES packets: ETSI EN 300 472.
 *
 * @internal
 */
final class TeletextDecoder
{
    // The data units of EN 300 472 that hold a teletext packet, and the size of each.
    private const DATA_UNITS     = [0x02, 0x03];
    private const DATA_UNIT_SIZE = 44;

    private const ROWS = 23;

    // Spacing attributes of ETS 300 706 table 26.
    private const WHITE     = 7;
    private const END_BOX   = 0x0A;
    private const START_BOX = 0x0B;

    private const COLORS = ["#000000", "#ff0000", "#00ff00", "#ffff00", "#0000ff", "#ff00ff", "#00ffff"];

    /** @var array<int, int> Hamming 8/4 byte to its 4 data bits, or -1 for 2 bit errors */
    private static array $hamming84 = [];

    private static string $bytes = "";

    private static string $reversedBytes = "";

    private readonly int $magazine;

    private bool $receiving = false;

    private int $start = 0;

    /** @var array<int, string> the 40 bytes of each row that the page shows */
    private array $rows = [];

    private bool $boxed = false;

    private int $nationalOption = 0;

    private ?int $pageGroup = null;

    private ?int $magazineGroup = null;

    /** @var list<array{int, list<string>}> */
    private array $states = [];


    /**
     * @param int $page          the magazine (0 for 8) times 256 plus the page byte, for example 0x088 for page 888
     * @param int $number        the page number in messages, for example 888
     * @param int $languageGroup the character set group of the language of the page
     */
    public function __construct(private readonly int $page, private readonly int $number, private readonly int $languageGroup)
    {
        $this->magazine = $page >> 8;
        if (self::$hamming84 === []) {
            self::buildTables();
        }
    }


    /**
     * Reads the data field of a PES packet with its time.
     */
    public function add(string $data, int $time): void
    {
        $identifier = $data === "" ? 0 : ord($data[0]);
        if ($identifier < 0x10 || $identifier > 0x1F) {
            return;
        }

        $length = strlen($data);
        for ($offset = 1; $offset + 2 <= $length; $offset += 2 + $unitLength) {
            $unitLength = ord($data[$offset + 1]);
            if ($unitLength === self::DATA_UNIT_SIZE && in_array(ord($data[$offset]), self::DATA_UNITS, true) && $offset + 2 + $unitLength <= $length) {
                // EN 300 472 holds the bits of each byte in the order of transmission, the reverse of ETS 300 706.
                $this->packet(strtr(substr($data, $offset + 4, 42), self::$bytes, self::$reversedBytes), $time);
            }
        }
    }


    /**
     * Returns the start time of each state of the page and the lines it shows. A state without lines clears the page.
     *
     * @return list<array{int, list<string>}>
     */
    public function finish(): array
    {
        if ($this->receiving) {
            $this->endTransmission();
        }

        return $this->states;
    }


    private function packet(string $packet, int $time): void
    {
        $address = self::hamming($packet, 0) | self::hamming($packet, 1) << 4;
        if ($address < 0) {
            return;
        }

        $magazine = $address & 0x07;
        $row      = $address >> 3;
        if ($row === 0) {
            $this->header($packet, $magazine, $time);
        } elseif ($magazine !== $this->magazine) {
            return;
        } elseif ($row <= self::ROWS && $this->receiving) {
            $this->rows[$row] = substr($packet, 2, 40);
        } elseif ($row === 28 && $this->receiving || $row === 29) {
            $this->characterSetGroup($packet, $row);
        }
    }


    /**
     * Reads a page header. It ends the transmission of the page, and starts it again when it is the header of the page.
     */
    private function header(string $packet, int $magazine, int $time): void
    {
        $bytes = array_map(fn (int $index): int => self::hamming($packet, $index), range(2, 9));
        $valid = min($bytes) >= 0;
        [$units, $tens, , $subcode2, , $subcode4, , $control] = $bytes;
        // A header with 2 bit errors still ends the page in its magazine, so that the rows after it do not join the page.
        if ($this->receiving && ($magazine === $this->magazine || ($valid && ($control & 0x01) !== 0))) {
            $this->endTransmission();
        }
        if (!$valid || ($magazine << 8 | $tens << 4 | $units) !== $this->page) {
            return;
        }

        $this->receiving      = true;
        $this->start          = $time;
        $this->boxed          = ($subcode4 & 0x0C) !== 0;
        $this->nationalOption = $control >> 1;
        $this->pageGroup      = null;
        if (($subcode2 & 0x08) !== 0) {
            $this->rows = [];
        }
    }


    /**
     * Reads the group of the default G0 set from triplet 1 of packet X/28/0 format 1 or M/29/0.
     */
    private function characterSetGroup(string $packet, int $row): void
    {
        $designation = self::hamming($packet, 2);
        $triplet     = self::hamming2418(substr($packet, 3, 3));
        if (($designation !== 0 && $designation !== 4) || $triplet === null || ($row === 28 && ($triplet & 0x0F) !== 0)) {
            return;
        }

        if ($row === 28) {
            $this->pageGroup = $triplet >> 10 & 0x0F;
        } else {
            $this->magazineGroup = $triplet >> 10 & 0x0F;
        }
    }


    private function endTransmission(): void
    {
        $this->receiving = false;
        $characters      = TeletextCharacters::g0($this->pageGroup ?? $this->magazineGroup ?? $this->languageGroup, $this->nationalOption, $this->number);

        ksort($this->rows);
        $lines = [];
        foreach ($this->rows as $row) {
            $line = $this->rowText($row, $characters);
            if ($line !== "") {
                $lines[] = $line;
            }
        }
        $this->states[] = [$this->start, $lines];
    }


    /**
     * Returns the text of a row with <font> tags for colors other than white. A subtitle page shows only the text in boxes.
     * Spacing attributes take a cell each and show as a space.
     *
     * @param list<string> $characters
     */
    private function rowText(string $row, array $characters): string
    {
        $runs  = [];
        $color = self::WHITE;
        $inBox = false;
        for ($column = 0; $column < 40; $column++) {
            $byte      = ord($row[$column]);
            $code      = self::hasOddParity($byte) ? $byte & 0x7F : 0x20;
            $character = $code < 0x20 || ($this->boxed && !$inBox) ? " " : $characters[$code - 0x20];
            if ($character !== " " && ($runs === [] || $runs[array_key_last($runs)][0] !== $color)) {
                $runs[] = [$color, $character];
            } elseif ($runs !== []) {
                $runs[array_key_last($runs)][1] .= $character;
            }

            // The colors and the box attributes are set-after: they apply from the next cell on.
            if ($code < self::WHITE + 1) {
                $color = $code;
            }
            $inBox = $code === self::START_BOX || ($inBox && $code !== self::END_BOX);
        }

        $text = "";
        foreach ($runs as [$runColor, $runText]) {
            $words = rtrim($runText, " ");
            $text .= ($runColor === self::WHITE ? $words : "<font color=\"" . self::COLORS[$runColor] . "\">$words</font>") .
                     substr($runText, strlen($words));
        }

        return rtrim($text, " ");
    }


    private static function hasOddParity(int $byte): bool
    {
        return substr_count(decbin($byte), "1") % 2 === 1;
    }


    /**
     * Returns the 4 data bits of the Hamming 8/4 byte at $index, with 1 bit error corrected, or -1.
     */
    private static function hamming(string $packet, int $index): int
    {
        return self::$hamming84[ord($packet[$index])];
    }


    /**
     * Returns the 18 data bits of a Hamming 24/18 triplet, with 1 bit error corrected, or null for 2 bit errors.
     * Bits 1 to 23 form a Hamming code with odd parity checks at bits 1, 2, 4, 8 and 16. Bit 24 gives all 24 bits odd parity.
     */
    private static function hamming2418(string $triplet): ?int
    {
        $bits     = ord($triplet[0]) | ord($triplet[1]) << 8 | ord($triplet[2]) << 16;
        $syndrome = 0;
        for ($position = 1; $position <= 23; $position++) {
            if (($bits >> ($position - 1) & 1) === 1) {
                $syndrome ^= $position;
            }
        }
        $syndrome ^= 0x1F;
        if (substr_count(decbin($bits), "1") % 2 === 1) {
            if ($syndrome !== 0) {
                return null;
            }
        } elseif ($syndrome !== 0) {
            if ($syndrome > 23) {
                return null;
            }
            $bits ^= 1 << ($syndrome - 1);
        }

        return ($bits >> 2 & 0x01) | ($bits >> 4 & 0x07) << 1 | ($bits >> 8 & 0x7F) << 4 | ($bits >> 16 & 0x7F) << 11;
    }


    private static function buildTables(): void
    {
        $codes = [];
        for ($value = 0; $value < 16; $value++) {
            [$d1, $d2, $d3, $d4] = [$value & 1, $value >> 1 & 1, $value >> 2 & 1, $value >> 3 & 1];
            [$p1, $p2, $p3]      = [1 ^ $d1 ^ $d3 ^ $d4, 1 ^ $d1 ^ $d2 ^ $d4, 1 ^ $d1 ^ $d2 ^ $d3];
            $p4                  = 1 ^ $p1 ^ $d1 ^ $p2 ^ $d2 ^ $p3 ^ $d3 ^ $d4;
            $codes[$value]       = $p1 | $d1 << 1 | $p2 << 2 | $d2 << 3 | $p3 << 4 | $d3 << 5 | $p4 << 6 | $d4 << 7;
        }

        for ($byte = 0; $byte < 256; $byte++) {
            self::$hamming84[$byte] = -1;
            foreach ($codes as $value => $code) {
                if (substr_count(decbin($byte ^ $code), "1") <= 1) {
                    self::$hamming84[$byte] = $value;
                }
            }
            self::$bytes         .= chr($byte);
            self::$reversedBytes .= chr(bindec(strrev(str_pad(decbin($byte), 8, "0", STR_PAD_LEFT))));
        }
    }
}
