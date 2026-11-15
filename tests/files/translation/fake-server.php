<?php

declare(strict_types=1);

// Router script for "php -S" that echoes the method, the content type and the body as JSON, with the status of the
// query parameter "status". The query parameter "header" adds that response header line. The parameter "pad" adds
// that many spaces to the answer.

header("Content-Type: application/json");
http_response_code((int)($_GET["status"] ?? 200));
if (isset($_GET["header"])) {
    header($_GET["header"]);
}
echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "contentType" => $_SERVER["CONTENT_TYPE"] ?? null, "body" => file_get_contents("php://input")]);
echo str_repeat(" ", (int)($_GET["pad"] ?? 0));
