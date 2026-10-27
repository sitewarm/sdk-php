<?php

// Router for PHP's built-in server, used by HardeningTest to exercise the real cURL transport.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('HIT_LOG');
if ($log !== false && $log !== '') {
    file_put_contents($log, $path . "\n", FILE_APPEND);
}

switch ($path) {
    case '/v1/sites/ok':
        header('Content-Type: application/json');
        echo '{"id":"s1"}';
        break;
    case '/v1/sites/redir':
        header('Location: http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/other', true, 302);
        break;
    case '/v1/sites/broken':
        header('Content-Type: application/json');
        header('Content-Length: 100');
        echo '{"id"';
        break;
    case '/v1/sites/big':
        header('Content-Type: application/json');
        for ($i = 0; $i < 40; $i++) {
            echo str_repeat('x', 1 << 20);
            flush();
        }
        break;
    default:
        http_response_code(404);
}
