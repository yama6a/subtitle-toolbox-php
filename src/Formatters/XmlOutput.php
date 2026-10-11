<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMDocument;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\XmlLoader;

/**
 * Checks the text that the TTML, iTT and SAMI formatters write.
 *
 * @internal
 */
final class XmlOutput
{
    /**
     * htmlspecialchars() returns "" for invalid UTF-8, and libxml rejects it, so such text must not reach either.
     *
     * @throws UnwritableContentException when $text is not valid UTF-8.
     */
    public static function requireUtf8(string $text): string
    {
        if (!StringHelpers::isValidUtf8($text)) {
            throw new UnwritableContentException(
                "The output cannot hold text that is not valid UTF-8. Read a file in another encoding with ReadOptions::\$encoding set to its source encoding."
            );
        }

        return $text;
    }


    /**
     * Removes the characters that XML 1.0 forbids: the C0 controls except tab, line feed and carriage return, U+FFFE and U+FFFF.
     *
     * @throws UnwritableContentException when $text is not valid UTF-8.
     */
    public static function text(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', "", self::requireUtf8($text));
    }

    /**
     * @throws UnwritableContentException when $xml is not well-formed.
     */
    public static function load(string $xml): DOMDocument
    {
        return XmlLoader::xml(self::requireUtf8($xml)) ?? throw new UnwritableContentException("The formatter wrote XML that is not well-formed.");
    }
}
