<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Http\HttpClient;

/**
 * Sends a request of a translation engine again after HTTP 429, 500, 502, 503 or 504.
 *
 * @internal
 */
final class HttpRetry
{
    private const WAIT_SECONDS = [1, 2, 4];

    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private const MAX_WAIT_SECONDS = 60;

    /**
     * Replaces sleep() in the tests.
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $sleep = null;


    /**
     * Calls $client->post() and calls it again up to 3 times, after 1, 2 and 4 seconds, while the status asks for a retry.
     * An answer with a valid Retry-After header sets the wait instead, up to 60 seconds.
     *
     * @param list<string> $headers
     * @return array{0: int, 1: string, 2?: list<string>}
     */
    public static function post(HttpClient $client, string $url, array $headers, string $body): array
    {
        foreach (self::WAIT_SECONDS as $seconds) {
            $response = $client->post($url, $headers, $body);
            if (!in_array($response[0], self::RETRY_STATUSES, true)) {
                return $response;
            }
            (self::$sleep ?? sleep(...))(self::retryAfter($response[2] ?? []) ?? $seconds);
        }

        return $client->post($url, $headers, $body);
    }


    /**
     * Returns the seconds of the Retry-After header, cut to 60, or null when it is missing or invalid. The header holds
     * seconds or an HTTP date. A date counts from the Date header of the same answer.
     *
     * @param list<string> $headerLines
     */
    private static function retryAfter(array $headerLines): ?int
    {
        $values = [];
        foreach ($headerLines as $line) {
            $parts = explode(":", $line, 2);
            if (count($parts) === 2) {
                $values[strtolower(trim($parts[0]))] ??= trim($parts[1]);
            }
        }

        $retryAfter = $values["retry-after"] ?? "";
        if (preg_match('/^\d+$/', $retryAfter) === 1) {
            return min(self::MAX_WAIT_SECONDS, (int) $retryAfter);
        }

        $until = strtotime($retryAfter);
        $now   = strtotime($values["date"] ?? "");
        if ($until === false || $now === false) {
            return null;
        }

        return max(0, min(self::MAX_WAIT_SECONDS, $until - $now));
    }
}
