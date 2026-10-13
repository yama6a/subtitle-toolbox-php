<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * The dashes that start a dialogue line.
 *
 * @internal
 */
final class DialogueDash
{
    /** The hyphen, U+2010, the en dash and the em dash, as the body of a regex character class. */
    public const CHARACTERS = '\-\x{2010}\x{2013}\x{2014}';

    // The bidi controls that can come before the dash, such as the U+202B of fixRightToLeft().
    private const LEADING_BIDI_CONTROLS = '/^[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*/u';

    // A dash before a digit, such as "-20 degrees", is a minus sign and starts no dialogue.
    public const REGEX = '/^[' . self::CHARACTERS . '](?![' . self::CHARACTERS . '])[ \t\x{00A0}]*(?=[^\s\p{N}])/u';


    /**
     * Returns the leading bidi controls of $text and the text after them. Text that is not valid UTF-8 has none.
     *
     * @return array{string, string}
     */
    public static function splitLeadingBidiControls(string $text): array
    {
        $controls = preg_match(self::LEADING_BIDI_CONTROLS, $text, $match) === 1 ? $match[0] : "";

        return [$controls, substr($text, strlen($controls))];
    }
}
