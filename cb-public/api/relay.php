<?php
// /public_html/api/relay.php
// Proxy to OpenAI /v1/responses with JSON / JSON-Schema structured outputs.
// - Optional env overrides: CB_OPENAI_ORG, CB_OPENAI_PROJECT, CB_OPENAI_MODEL

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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ---- Load API key (ENV or secure file) ----
$apiKey = '';
if ($env = getenv('CB_OPENAI_KEY')) { $apiKey = $env; }
if (!$apiKey) {
  $candidates = [
    dirname(__DIR__, 1) . '/../secure/cb_keys.php',
    dirname(__DIR__, 2) . '/secure/cb_keys.php',
    dirname(__DIR__, 3) . '/secure/cb_keys.php',
    dirname(__DIR__, 1) . '/secure/cb_keys.php',
  ];
  foreach ($candidates as $path) {
    if (is_readable($path)) {
      $ret = include $path;
      if (isset($CB_OPENAI_KEY) && $CB_OPENAI_KEY) { $apiKey = $CB_OPENAI_KEY; }
      if (!$apiKey && isset($OPENAI_API_KEY) && $OPENAI_API_KEY) { $apiKey = $OPENAI_API_KEY; }
      if (!$apiKey && defined('CB_OPENAI_KEY')) { $apiKey = constant('CB_OPENAI_KEY'); }
      if (!$apiKey && defined('OPENAI_API_KEY')) { $apiKey = constant('OPENAI_API_KEY'); }
      if (!$apiKey && is_array($ret)) {
        if (!empty($ret['CB_OPENAI_KEY'])) { $apiKey = $ret['CB_OPENAI_KEY']; }
        if (!$apiKey && !empty($ret['OPENAI_API_KEY'])) { $apiKey = $ret['OPENAI_API_KEY']; }
      }
      if ($apiKey) break;
    }
  }
}
if (!$apiKey) { error_log('relay.php: OpenAI key not found.'); http_response_code(500); echo json_encode(['error'=>'Server API key not configured.']); exit; }

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!$payload) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

// Default model from env if client omitted it
if (empty($payload['model'])) {
  $defaultModel = getenv('CB_OPENAI_MODEL');
  if ($defaultModel) { $payload['model'] = $defaultModel; }
}

// If client didn't specify response_format, default to json_object (safe)
if (!isset($payload['response_format'])) {
  $payload['response_format'] = ['type'=>'json_object'];
}

// ---- Call OpenAI Responses API ----
$openaiUrl = 'https://api.openai.com/v1/responses';
$headers = [
  'Authorization: Bearer ' . $apiKey,
  'Content-Type: application/json'
];
if ($org = getenv('CB_OPENAI_ORG'))     { $headers[] = 'OpenAI-Organization: ' . $org; }
if ($proj = getenv('CB_OPENAI_PROJECT')){ $headers[] = 'OpenAI-Project: ' . $proj; }

$ch = curl_init($openaiUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 60,
  CURLOPT_HEADER => true
]);

$rawResp = curl_exec($ch);
if ($rawResp === false) {
  $err = curl_error($ch);
  curl_close($ch);
  http_response_code(502);
  echo json_encode(['error'=>'Upstream request failed','detail'=>$err]);
  exit;
}
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$respHeaders = substr($rawResp, 0, $headerSize);
$respBody = substr($rawResp, $headerSize);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Forward selected headers
foreach (explode("\r\n", $respHeaders) as $line) {
  if (stripos($line, 'x-request-id:') === 0) header('X-Upstream-Request-Id: ' . trim(substr($line, 13)));
  if (stripos($line, 'x-ratelimit-') === 0) header($line);
}

http_response_code($httpCode);
echo $respBody;
