<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\TranslationException;

/**
 * The HttpClient of the engines when their options pass none.
 *
 * @internal
 */
final class CurlHttpClient implements HttpClient
{
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const TIMEOUT_SECONDS = 120;

    private const ANSWER_BYTES_BASE = 1048576;

    private const ANSWER_BYTES_PER_REQUEST_BYTE = 16;


    /**
     * Throws InvalidArgumentException when PHP has no ext-curl.
     */
    public function __construct()
    {
        if (!self::isAvailable()) {
            throw new InvalidArgumentException("PHP has no ext-curl, which the translation engines need. " .
                                               "Install the PHP curl extension.");
        }
    }


    /**
     * Returns true when PHP has ext-curl.
     */
    public static function isAvailable(): bool
    {
        return function_exists("curl_init");
    }


    /**
     * Stops reading at 1 MiB plus 16 bytes for each byte of $body. An answer holds the translations of the request, so its
     * size follows the request. The 1 MiB covers the JSON frame and the error pages of the services.
     */
    public function post(string $url, array $headers, string $body): array
    {
        $host        = parse_url($url, PHP_URL_HOST);
        $limit       = self::ANSWER_BYTES_BASE + self::ANSWER_BYTES_PER_REQUEST_BYTE * strlen($body);
        $answer      = "";
        $tooLong     = false;
        $headerLines = [];
        $handle      = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_WRITEFUNCTION  => function ($handle, string $chunk) use (&$answer, &$tooLong, $limit): int {
                if (strlen($answer) + strlen($chunk) > $limit) {
                    $tooLong = true;

                    return 0;
                }
                $answer .= $chunk;

                return strlen($chunk);
            },
            // An interim answer such as "100 Continue" has header lines of its own. Each status line starts a new list.
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headerLines): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, "HTTP/")) {
                    $headerLines = [];
                } elseif ($trimmed !== "") {
                    $headerLines[] = $trimmed;
                }

                return strlen($line);
            },
        ]);
        if (curl_exec($handle) === false) {
            if ($tooLong) {
                throw new TranslationException("The answer from $host has more than $limit bytes.");
            }
            // A base URL can hold a user name and a password, so the message names the host only.
            throw new TranslationException("The request to $host failed: " . curl_error($handle));
        }

        return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $answer, $headerLines];
    }
}
