<?php
/**
 * Webhook receiver — called by the processing server when a video job completes.
 *
 * The server POSTs JSON with header X-Pedra-Signature: HMAC-SHA256(body, webhook_token).
 * We verify the signature, then download and save the result video.
 *
 * Must respond with 200 within ~10 seconds (server timeout).
 * Heavy work (download + save) is fast enough for typical video CDN links.
 */

ob_start();
if (!defined('PHPWG_ROOT_PATH')) {
  define('PHPWG_ROOT_PATH', dirname(dirname(dirname(__FILE__))) . '/');
}
include(PHPWG_ROOT_PATH . 'include/common.inc.php');
ini_set('display_errors', 0);
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');

global $conf;

include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/functions.inc.php');

// ── 1. Read raw body and signature ──────────────────────────────────────────

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_PEDRA_SIGNATURE'] ?? '';

if ($raw === '' || $sig === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Missing body or signature']);
  exit;
}

// ── 2. Decode payload and look up job ───────────────────────────────────────

$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['job_id'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid payload']);
  exit;
}

// Lazy migration (adds job_id / server_job_id if absent)
pedra_ai_migrate_jobs_table();

$escaped_job_id = pwg_db_real_escape_string($payload['job_id']);
$result         = pwg_query('SELECT * FROM `' . PEDRA_AI_JOBS_TABLE . '` WHERE `job_id` = "' . $escaped_job_id . '" LIMIT 1');
$job            = pwg_db_fetch_assoc($result);

if (!$job) {
  http_response_code(404);
  echo json_encode(['error' => 'Job not found: ' . $payload['job_id']]);
  exit;
}

// ── 3. Verify HMAC ──────────────────────────────────────────────────────────

$secret   = $conf['pedra_ai_server_token'] ?? '';
$expected = hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expected, $sig)) {
  http_response_code(403);
  echo json_encode(['error' => 'Invalid signature']);
  exit;
}

// ── 4. Already delivered? (idempotency) ─────────────────────────────────────

if ($job['status'] === 'done' || $job['status'] === 'error') {
  http_response_code(200);
  echo json_encode(['ok' => true, 'note' => 'already processed']);
  exit;
}

// ── 5. Process result ────────────────────────────────────────────────────────

$job_db_id = (int) $job['id'];
$image_id  = (int) $job['image_id'];

if (($payload['status'] ?? '') === 'done') {
  $result_url = $payload['result_url'] ?? '';
  if (empty($result_url)) {
    pwg_query('UPDATE `' . PEDRA_AI_JOBS_TABLE . '` SET `status`="error", `error_msg`="Webhook missing result_url" WHERE `id`=' . $job_db_id);
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'missing result_url']);
    exit;
  }

  try {
    include_once(PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php');

    $tmp_path     = pedra_ai_download_url($result_url);
    $suffix       = $conf['pedra_ai_suffix'] ?? '_pedra';
    $new_image_id = pedra_ai_save_as_new_image($image_id, $tmp_path, $suffix);

    pwg_query(
      'UPDATE `' . PEDRA_AI_JOBS_TABLE . '`'
      . ' SET `status`="done"'
      . ', `result_url`="' . pwg_db_real_escape_string($result_url) . '"'
      . ', `new_image_id`=' . $new_image_id
      . ' WHERE `id`=' . $job_db_id
    );

    invalidate_user_cache();

  } catch (RuntimeException $e) {
    pwg_query(
      'UPDATE `' . PEDRA_AI_JOBS_TABLE . '`'
      . ' SET `status`="error"'
      . ', `error_msg`="' . pwg_db_real_escape_string(substr($e->getMessage(), 0, 500)) . '"'
      . ' WHERE `id`=' . $job_db_id
    );
  }
} else {
  // status === 'error'
  $error_msg = pwg_db_real_escape_string(substr($payload['error'] ?? 'Processing failed', 0, 500));
  pwg_query('UPDATE `' . PEDRA_AI_JOBS_TABLE . '` SET `status`="error", `error_msg`="' . $error_msg . '" WHERE `id`=' . $job_db_id);
}

http_response_code(200);
echo json_encode(['ok' => true]);
exit;
