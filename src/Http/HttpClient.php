<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

/**
 * Sends the requests of DeepLEngine, GoogleTranslateEngine and OpenAiCompatibleEngine.
 * Implement it to use another HTTP library or a proxy.
 */
interface HttpClient
{
    /**
     * Sends $body to $url with POST and returns the status code and the body of the response. It returns error statuses
     * such as 403. It throws TranslationException only when no response arrives.
     *
     * @param list<string> $headers header lines such as "Content-Type: application/json"
     * @return array{0: int, 1: string}
     */
    public function post(string $url, array $headers, string $body): array;
}
