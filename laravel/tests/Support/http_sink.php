<?php

/**
 * A stand-in SMS provider for the end-to-end copy only (the built-in HTTP SMS driver points at it):
 * `HTTP_SINK_FILE=<file> php -S 127.0.0.1:8815 tests/Support/http_sink.php`. Every request is appended to the file as
 * one JSON line — method, path, query, headers, body — and answered 200 `{"status":"success"}`, so a test can read
 * the texts (and their codes) the kit sent. It sends nothing anywhere.
 */
$file = getenv('HTTP_SINK_FILE') ?: sys_get_temp_dir().'/http_sink.jsonl';
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
file_put_contents($file, json_encode([
    'at' => date('c'), 'method' => $_SERVER['REQUEST_METHOD'], 'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'query' => $_GET, 'headers' => $headers, 'body' => file_get_contents('php://input'),
])."\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo json_encode(['status' => 'success']);

return true;
