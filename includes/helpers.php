<?php
function json_response($data = null, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data ?? new stdClass());
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitize_string($s): string {
    return trim(filter_var($s, FILTER_SANITIZE_STRING));
}

function require_method(array $methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        json_response(['error' => 'Method not allowed'], 405);
    }
}

function get_param(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}

function post_param(string $key, $default = null) {
    return $_POST[$key] ?? $default;
}
?>

