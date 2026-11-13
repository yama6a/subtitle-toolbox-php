<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Http\FakeHttpClient;

class HttpRetryTest extends TestCase
{
    /** @var list<int> */
    private array $waits = [];


    protected function setUp(): void
    {
        HttpRetry::$sleep = function (int $seconds): void {
            $this->waits[] = $seconds;
        };
    }


    protected function tearDown(): void
    {
        HttpRetry::$sleep = null;
    }


    /**
     * @return array<string, array{list<string>, int}>
     */
    public static function retryAfterHeaders(): array
    {
        $date = "Date: Wed, 21 Oct 2015 07:28:00 GMT";

        return [
            "30 seconds"                  => [["Retry-After: 30"], 30],
            "lower case name"             => [["retry-after:  5 "], 5],
            "more than 60 seconds"        => [["Retry-After: 3600"], 60],
            "a date 30 seconds later"     => [[$date, "Retry-After: Wed, 21 Oct 2015 07:28:30 GMT"], 30],
            "a date after the Date line"  => [["Retry-After: Wed, 21 Oct 2015 07:28:30 GMT", $date], 30],
            "a date in the past"          => [[$date, "Retry-After: Wed, 21 Oct 2015 07:27:00 GMT"], 0],
            "a date far away"             => [[$date, "Retry-After: Wed, 21 Oct 2016 07:28:00 GMT"], 60],
            "a date without a Date line"  => [["Retry-After: Wed, 21 Oct 2015 07:28:30 GMT"], 1],
            "text"                        => [["Retry-After: soon"], 1],
            "a negative number"           => [["Retry-After: -5"], 1],
            "no header"                   => [["Content-Type: text/plain", "HTTP/1.1 429 Too Many Requests"], 1],
        ];
    }


    /**
     * @param list<string> $headerLines
     */
    #[DataProvider("retryAfterHeaders")]
    public function testTheFirstWaitFollowsRetryAfter(array $headerLines, int $seconds): void
    {
        $client = new FakeHttpClient([[429, "", $headerLines], [200, "ok"]]);

        $response = HttpRetry::post($client, "http://localhost/", [], "{}");

        $this->assertSame(200, $response[0]);
        $this->assertSame([$seconds], $this->waits);
    }


    public function testEachRetryReadsTheHeadersOfItsOwnAnswer(): void
    {
        $client = new FakeHttpClient([[429, "", ["Retry-After: 7"]], [503, ""], [429, "", ["Retry-After: 90"]], [200, "ok"]]);

        HttpRetry::post($client, "http://localhost/", [], "{}");

        $this->assertSame([7, 2, 60], $this->waits);
    }
}
