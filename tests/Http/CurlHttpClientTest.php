<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\TranslationException;

require_once __DIR__ . "/LocalServer.php";
require_once __DIR__ . "/WithoutCurl.php";

class CurlHttpClientTest extends TestCase
{
    private const ROUTER = __DIR__ . "/../files/translation/fake-server.php";


    #[RequiresPhpExtension("curl")]
    public function testPostsToALocalServerAndReadsTheStatusAndTheBody(): void
    {
        $server = LocalServer::start(self::ROUTER);
        try {
            $client = new CurlHttpClient();

            [$status, $body] = $client->post("$server->url/echo?status=418", ["Content-Type: application/json"], '{"a": "ä"}');
            $this->assertSame(418, $status);
            $this->assertSame(["method" => "POST", "contentType" => "application/json", "body" => '{"a": "ä"}'], json_decode($body, true));

            [$status, $body] = $client->post("$server->url/echo", [], "x");
            $this->assertSame(200, $status);
            $this->assertSame("x", json_decode($body, true)["body"]);
        } finally {
            $server->stop();
        }
    }


    #[RequiresPhpExtension("curl")]
    public function testReturnsTheHeaderLinesOfTheResponse(): void
    {
        $server = LocalServer::start(self::ROUTER);
        try {
            $response = (new CurlHttpClient())->post("$server->url/echo?status=429&header=" . rawurlencode("Retry-After: 30"), [], "x");

            $this->assertSame(429, $response[0]);
            $this->assertContains("Retry-After: 30", $response[2]);
            $this->assertNotContains("", $response[2]);
        } finally {
            $server->stop();
        }
    }


    #[RequiresPhpExtension("curl")]
    public function testStopsReadingAtOneMebibytePlus16BytesPerRequestByte(): void
    {
        $server = LocalServer::start(self::ROUTER);
        try {
            $client = new CurlHttpClient();
            $limit  = 1048576 + 16 * 2;

            $response = $client->post("$server->url/echo?pad=" . ($limit - 200), [], "ab");
            $this->assertSame(200, $response[0]);
            $this->assertGreaterThan($limit - 200, strlen($response[1]));

            try {
                $client->post("$server->url/echo?pad=$limit", [], "ab");
                $this->fail("No exception");
            } catch (TranslationException $exception) {
                $this->assertSame("TranslationException (Error #109): The answer from 127.0.0.1 has more than $limit bytes.", $exception->getMessage());
            }
        } finally {
            $server->stop();
        }
    }


    #[RequiresPhpExtension("curl")]
    public function testAFailedConnectionNamesTheHostAndNotTheQuery(): void
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $port   = (int)substr(strrchr(stream_socket_get_name($socket, false), ":"), 1);
        fclose($socket);

        try {
            (new CurlHttpClient())->post("http://127.0.0.1:$port/v2?key=secret-key", [], "");
            $this->fail("No exception");
        } catch (TranslationException $exception) {
            $this->assertStringStartsWith("TranslationException (Error #109): The request to 127.0.0.1 failed: ", $exception->getMessage());
            $this->assertStringNotContainsString("secret-key", $exception->getMessage());
        }
    }


    #[RequiresPhpExtension("curl")]
    public function testWithoutTheCurlExtensionTheClientIsNotAvailableAndNamesIt(): void
    {
        $this->assertTrue(CurlHttpClient::isAvailable());
        $this->assertSame([0, "false", ""], WithoutCurl::run(["-r", "require " . var_export(dirname(__DIR__, 2) . "/vendor/autoload.php", true) .
                                                                "; echo var_export(" . CurlHttpClient::class . "::isAvailable(), true);"]));

        $exception = WithoutCurl::exception("new " . CurlHttpClient::class . "();");

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertSame("PHP has no ext-curl, which the translation engines need. Install the PHP curl extension.",
                          $exception->getMessage());
    }
}
