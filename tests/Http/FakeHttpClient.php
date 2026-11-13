<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

/**
 * Records each request and answers with the next queued response, or with the response that $respond builds.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, headers: list<string>, body: array}> */
    public array $requests = [];

    /** @var list<array{0: int, 1: string, 2?: list<string>}> */
    private array $responses;


    /**
     * @param list<array{0: int, 1: string, 2?: list<string>}>                          $responses
     * @param (\Closure(array): array{0: int, 1: string, 2?: list<string>})|null $respond   builds a response from the decoded request body
     */
    public function __construct(array $responses = [], private readonly ?\Closure $respond = null)
    {
        $this->responses = $responses;
    }


    public function post(string $url, array $headers, string $body): array
    {
        $decoded          = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->requests[] = ["url" => $url, "headers" => $headers, "body" => $decoded];

        return $this->respond !== null ? ($this->respond)($decoded) : array_shift($this->responses);
    }
}
