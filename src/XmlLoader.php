<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use DOMDocument;
use LibXMLError;

/**
 * Loads XML and HTML with the libxml errors collected, not printed.
 * It restores the libxml error setting of the caller, also when the load throws.
 *
 * @internal
 */
final class XmlLoader
{
    /**
     * Returns the document, or null when $xml is empty or not well-formed. $error receives the last libxml error and $firstError the first.
     * With $recover, libxml repairs what it can and returns null only when no root element is left.
     */
    public static function xml(string $xml, ?LibXMLError &$error = null, bool $recover = false, ?LibXMLError &$firstError = null): ?DOMDocument
    {
        $xml      = self::declareUtf8($xml);
        $document = new DOMDocument();
        // PHP 8.3 has no LIBXML_RECOVER constant.
        $document->recover = $recover;
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET blocks network access. Without LIBXML_NOENT and LIBXML_DTDLOAD, libxml loads no external entity.
            // Without LIBXML_BIGLINES, libxml reports line 65535 for every element after that line.
            $loaded     = $xml !== "" && $document->loadXML($xml, LIBXML_NONET | LIBXML_BIGLINES);
            $error      = libxml_get_last_error() ?: null;
            $firstError = libxml_get_errors()[0] ?? null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $document->documentElement !== null ? $document : null;
    }


    /**
     * Removes the text before the XML declaration, a comment, a DOCTYPE or the root element that $rootPattern matches.
     * Line breaks replace that text after the XML declaration, so libxml still reports the line numbers of the file.
     * $skipped receives the removed text without white space at its ends.
     */
    public static function skipLeadingText(string $xml, string $rootPattern, ?string &$skipped = null): string
    {
        $skipped = "";
        $start   = strlen($xml) - strlen(ltrim($xml));
        if (!str_starts_with(ltrim($xml), "<")) {
            if (!preg_match('/<(?:\?xml\s|!--|!DOCTYPE\s|' . $rootPattern . '[\s>\/])/', $xml, $match, PREG_OFFSET_CAPTURE)) {
                return $xml;
            }
            $start = $match[0][1];
        }
        if ($start === 0) {
            return $xml;
        }

        $prefix     = substr($xml, 0, $start);
        $skipped    = trim($prefix);
        $lineBreaks = str_repeat("\n", preg_match_all('/\r\n?|\n/', $prefix));
        $xml        = substr($xml, $start);
        $declEnd    = str_starts_with($xml, "<?xml") ? strpos($xml, "?>") : false;

        return $declEnd === false ? $lineBreaks . $xml : substr($xml, 0, $declEnd + 2) . $lineBreaks . substr($xml, $declEnd + 2);
    }


    /**
     * Subtitle::fromString() converts the input to UTF-8 but keeps the XML declaration, such as encoding="ISO-8859-1".
     * libxml would then decode the UTF-8 bytes a second time, or reject them for a UTF-16 declaration.
     * Some tools also declare UTF-16 for UTF-8 files.
     * Raw UTF-16 bytes hold zero bytes, so the pattern leaves them alone.
     */
    private static function declareUtf8(string $xml): string
    {
        return preg_replace(
            '/\A(\xEF\xBB\xBF)?(<\?xml\s[^>]*?\bencoding\s*=\s*)(["\'])[^"\']*\3/i',
            '${1}${2}${3}UTF-8${3}',
            $xml,
            1
        ) ?? $xml;
    }


    public static function html(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
