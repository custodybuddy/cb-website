<?php
// /public_html/api/relay.php
// Proxy to OpenAI /v1/responses with JSON / JSON-Schema structured outputs.
// - Optional env overrides: CB_OPENAI_ORG, CB_OPENAI_PROJECT, CB_OPENAI_MODEL

function send_json_error(string $message, int $statusCode, array $extra = []): void {
  http_response_code($statusCode);
  echo json_encode(array_merge(['error' => $message], $extra));
  exit;
}

function resolve_api_key(): string {
  if ($env = getenv('CB_OPENAI_KEY')) {
    return $env;
  }

  $candidates = [
    dirname(__DIR__, 1) . '/../secure/cb_keys.php',
    dirname(__DIR__, 2) . '/secure/cb_keys.php',
    dirname(__DIR__, 3) . '/secure/cb_keys.php',
    dirname(__DIR__, 1) . '/secure/cb_keys.php',
  ];

  foreach ($candidates as $path) {
    if (!is_readable($path)) {
      continue;
    }

    unset($CB_OPENAI_KEY, $OPENAI_API_KEY);
    $ret = include $path;

    if (!empty($CB_OPENAI_KEY ?? null)) {
      return $CB_OPENAI_KEY;
    }

    if (!empty($OPENAI_API_KEY ?? null)) {
      return $OPENAI_API_KEY;
    }

    if (defined('CB_OPENAI_KEY') && constant('CB_OPENAI_KEY')) {
      return constant('CB_OPENAI_KEY');
    }

    if (defined('OPENAI_API_KEY') && constant('OPENAI_API_KEY')) {
      return constant('OPENAI_API_KEY');
    }

    if (is_array($ret)) {
      if (!empty($ret['CB_OPENAI_KEY'])) {
        return $ret['CB_OPENAI_KEY'];
      }

      if (!empty($ret['OPENAI_API_KEY'])) {
        return $ret['OPENAI_API_KEY'];
      }
    }
  }

  return '';
}

function decode_request_payload(): array {
  $raw = file_get_contents('php://input');
  $payload = json_decode($raw, true);

  if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
    send_json_error('Invalid JSON', 400);
  }

  if (!is_array($payload)) {
    send_json_error('JSON payload must decode to an object', 400);
  }

  return $payload;
}

function apply_payload_defaults(array $payload): array {
  if (empty($payload['model'])) {
    $defaultModel = getenv('CB_OPENAI_MODEL');
    if ($defaultModel) {
      $payload['model'] = $defaultModel;
    }
  }

  if (!isset($payload['response_format'])) {
    $payload['response_format'] = ['type' => 'json_object'];
  }

  return $payload;
}

function emit_cors_headers(): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $allowed = [
    'https://custodybuddy.com',
    'https://www.custodybuddy.com',
    'https://app.custodybuddy.com'
  ];

  if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
  } else {
    header('Access-Control-Allow-Origin: https://custodybuddy.com');
  }

  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
  header('Content-Type: application/json; charset=utf-8');
}

function guard_request_method(): void {
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Method not allowed', 405);
  }
}

emit_cors_headers();
guard_request_method();

$apiKey = resolve_api_key();
if (!$apiKey) {
  error_log('relay.php: OpenAI key not found.');
  send_json_error('Server API key not configured.', 500);
}

$payload = apply_payload_defaults(decode_request_payload());

function build_upstream_headers(string $apiKey): array {
  $headers = [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
  ];

  if ($org = getenv('CB_OPENAI_ORG')) {
    $headers[] = 'OpenAI-Organization: ' . $org;
  }

  if ($proj = getenv('CB_OPENAI_PROJECT')) {
    $headers[] = 'OpenAI-Project: ' . $proj;
  }

  return $headers;
}

function forward_select_headers(string $rawHeaders): void {
  foreach (explode("\r\n", $rawHeaders) as $line) {
    if (stripos($line, 'x-request-id:') === 0) {
      header('X-Upstream-Request-Id: ' . trim(substr($line, 13)));
    }

    if (stripos($line, 'x-ratelimit-') === 0) {
      header($line);
    }
  }
}

$openaiUrl = 'https://api.openai.com/v1/responses';
$ch = curl_init($openaiUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => build_upstream_headers($apiKey),
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 60,
  CURLOPT_HEADER => true
]);

$rawResp = curl_exec($ch);
if ($rawResp === false) {
  $err = curl_error($ch);
  curl_close($ch);
  send_json_error('Upstream request failed', 502, ['detail' => $err]);
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$respHeaders = substr($rawResp, 0, $headerSize);
$respBody = substr($rawResp, $headerSize);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

forward_select_headers($respHeaders);

http_response_code($httpCode);
echo $respBody;
