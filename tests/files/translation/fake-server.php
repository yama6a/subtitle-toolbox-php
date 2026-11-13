<?php

declare(strict_types=1);

// Router script for "php -S" that plays DeepL on /v2/translate, Google Cloud Translation on /language/translate/v2 and an
// OpenAI-compatible service on /v1/chat/completions. A request to /v1/chat/completions without a key has the key "".
// The keys "forbidden", "busy" and "quota" give HTTP 403, 429 and 456. "bad-request" gives the recorded error of each service.
// Any other path echoes the method, the headers and the body as JSON, with the status of the query parameter "status".
// The query parameter "header" adds that response header line. The parameter "pad" adds that many spaces to the answer.
// With FAKE_SERVER_LOG set, each request appends one JSON line with its path, key and body to that file.

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$body = file_get_contents("php://input");
$key  = match ($path) {
    "/v2/translate"           => preg_replace('/^DeepL-Auth-Key /', "", $_SERVER["HTTP_AUTHORIZATION"] ?? ""),
    "/language/translate/v2"  => $_SERVER["HTTP_X_GOOG_API_KEY"] ?? "",
    "/v1/chat/completions"    => preg_replace('/^Bearer /', "", $_SERVER["HTTP_AUTHORIZATION"] ?? ""),
    default                   => null,
};

if (getenv("FAKE_SERVER_LOG")) {
    file_put_contents(getenv("FAKE_SERVER_LOG"), json_encode(["path" => $path, "key" => $key, "body" => json_decode($body, true)]) . "\n", FILE_APPEND);
}

header("Content-Type: application/json");
if ($key === null) {
    http_response_code((int)($_GET["status"] ?? 200));
    if (isset($_GET["header"])) {
        header($_GET["header"]);
    }
    echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "contentType" => $_SERVER["CONTENT_TYPE"] ?? null, "body" => $body]);
    echo str_repeat(" ", (int)($_GET["pad"] ?? 0));

    return;
}

$file  = ["/v2/translate" => "deepl", "/language/translate/v2" => "google", "/v1/chat/completions" => "openai"][$path];
$error = ["forbidden" => 403, "busy" => 429, "quota" => 456, "bad-request" => 400][$key] ?? null;
if ($error !== null) {
    http_response_code($error);
    echo $key === "bad-request" ? file_get_contents(__DIR__ . "/{$file}_error.json") : '{"message": "error"}';

    return;
}

echo file_get_contents(__DIR__ . ($file === "google" ? "/google_fr.json" : "/{$file}_de.json"));
