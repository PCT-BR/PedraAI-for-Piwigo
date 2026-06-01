<?php
/**
 * Signed URL endpoint — lets the processing server fetch a Piwigo file
 * (photo or video) without requiring a session, even from private albums.
 *
 * URL format:
 *   /plugins/pedra_ai/serve.php?image_id=42&expires=1717503600&token=HMAC
 *
 * The HMAC is SHA-256 keyed on SERVER_TOKEN: hash_hmac('sha256', "{id}:{expires}", token)
 */

// Buffer any output from common.inc.php (PHP notices, debug output, etc.)
ob_start();
if (!defined('PHPWG_ROOT_PATH')) {
  define('PHPWG_ROOT_PATH', dirname(dirname(dirname(__FILE__))) . '/');
}
include(PHPWG_ROOT_PATH . 'include/common.inc.php');
ini_set('display_errors', 0);
ob_end_clean();

global $conf;

// ── 1. Parse and validate parameters ────────────────────────────────────────

$image_id = (int) ($_GET['image_id'] ?? 0);
$expires  = (int) ($_GET['expires']  ?? 0);
$token    = (string) ($_GET['token'] ?? '');

if ($image_id <= 0 || $expires <= 0 || $token === '') {
  http_response_code(400);
  exit('Missing parameters');
}

// ── 2. Check expiry ──────────────────────────────────────────────────────────

if ($expires < time()) {
  http_response_code(403);
  exit('URL expired');
}

// ── 3. Verify HMAC signature ─────────────────────────────────────────────────

$secret   = $conf['pedra_ai_server_token'] ?? '';
if (empty($secret)) {
  http_response_code(503);
  exit('Processing server not configured');
}

$expected = hash_hmac('sha256', $image_id . ':' . $expires, $secret);
if (!hash_equals($expected, $token)) {
  http_response_code(403);
  exit('Invalid signature');
}

// ── 4. Resolve and stream the file ──────────────────────────────────────────

$image_info = get_image_infos($image_id);
if (!$image_info) {
  http_response_code(404);
  exit('Image not found');
}

$rel_path  = $image_info['path'];
$abs_path  = realpath(PHPWG_ROOT_PATH . $rel_path);

if ($abs_path === false || !is_file($abs_path)) {
  http_response_code(404);
  exit('File not found on disk');
}

$mime = mime_content_type($abs_path) ?: 'application/octet-stream';
$size = filesize($abs_path);
$name = basename($abs_path);

header('Content-Type: '        . $mime);
header('Content-Length: '      . $size);
header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

readfile($abs_path);
exit;
