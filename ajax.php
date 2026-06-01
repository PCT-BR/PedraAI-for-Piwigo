<?php
// Buffer everything from common.inc.php (PHP errors, warnings displayed as HTML)
ob_start();

if (!defined('PHPWG_ROOT_PATH')) {
  define('PHPWG_ROOT_PATH', dirname(dirname(dirname(__FILE__))) . '/');
}
include(PHPWG_ROOT_PATH . 'include/common.inc.php');

// Prevent PHP errors from mixing with our JSON response from this point on
ini_set('display_errors', 0);
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');

if (!is_admin()) {
  http_response_code(403);
  echo json_encode(['stat' => 'fail', 'message' => 'Unauthorized']);
  exit;
}

if (empty($_POST['pwg_token']) || get_pwg_token() !== $_POST['pwg_token']) {
  http_response_code(403);
  echo json_encode(['stat' => 'fail', 'message' => 'Invalid token']);
  exit;
}

$image_ids = array_filter(array_map('intval', (array)($_POST['image_ids'] ?? [])));
if (empty($image_ids)) {
  echo json_encode(['stat' => 'fail', 'message' => 'No images']);
  exit;
}

include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/admin/pedra_ai_process.php');

// Safety-net buffer: converts any die() or stray echo to JSON before it reaches the client.
// PHP calls the callback even when die() terminates the script.
ob_start(function($output) {
  $t = trim($output);
  if ($t === '' || $t[0] === '{' || $t[0] === '[') return $output;
  return json_encode(['stat' => 'fail', 'errors' => [strip_tags($t)], 'infos' => []]);
});

pedra_ai_process_collection($image_ids);

global $page;
echo json_encode([
  'stat'   => empty($page['errors']) ? 'ok' : 'fail',
  'infos'  => $page['infos']  ?? [],
  'errors' => $page['errors'] ?? [],
]);

ob_end_flush();
