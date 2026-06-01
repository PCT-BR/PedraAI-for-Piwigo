<?php
ob_start();
if (!defined('PHPWG_ROOT_PATH')) {
  define('PHPWG_ROOT_PATH', dirname(dirname(dirname(__FILE__))) . '/');
}
include(PHPWG_ROOT_PATH . 'include/common.inc.php');
ini_set('display_errors', 0);
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');

if (!is_admin()) {
  echo json_encode(['stat' => 'fail', 'jobs' => []]);
  exit;
}

// Lazy migration: add new_image_id if missing (for installs before this column existed)
$col_check = pwg_query("SHOW COLUMNS FROM `" . PEDRA_AI_JOBS_TABLE . "` LIKE 'new_image_id'");
if (pwg_db_num_rows($col_check) === 0) {
  pwg_query("ALTER TABLE `" . PEDRA_AI_JOBS_TABLE . "` ADD COLUMN `new_image_id` INT(11) DEFAULT NULL AFTER `result_url`");
}

$query = '
SELECT j.id, j.image_id, j.operation, j.status, j.error_msg, j.created_at, j.new_image_id,
       i.name AS img_name, i.file AS img_file
  FROM ' . PEDRA_AI_JOBS_TABLE . ' j
  LEFT JOIN ' . IMAGES_TABLE . ' i ON i.id = j.image_id
  WHERE j.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
  ORDER BY j.created_at DESC
  LIMIT 50
;';

$jobs   = [];
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
  $target_id = ($row['new_image_id'] ?? null) ?: $row['image_id'];
  $photo_url = null;
  if ($row['status'] === 'done' && $target_id) {
    $photo_url = get_absolute_root_url() . 'picture.php?image_id=' . (int) $target_id;
  }
  $jobs[] = [
    'id'           => (int) $row['id'],
    'image_id'     => (int) $row['image_id'],
    'new_image_id' => $row['new_image_id'] ? (int) $row['new_image_id'] : null,
    'operation'    => $row['operation'],
    'status'       => $row['status'],
    'error'        => $row['error_msg'],
    'name'         => $row['img_name'] ?: $row['img_file'] ?: ('#' . $row['image_id']),
    'created_at'   => $row['created_at'],
    'photo_url'    => $photo_url,
  ];
}

$credits_raw = $conf['pedra_ai_credits'] ?? '';
$remaining   = ($credits_raw !== '') ? (int) $credits_raw : null;

// Live credit fetch — only when caller explicitly requests it (?credits=1)
// so normal widget polls stay fast. On success, auto-sync the stored counter.
$live_plan    = null;
$live_credits = null;

if (!empty($_GET['credits']) && !empty($conf['pedra_ai_api_key'])) {
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/pedra_api.class.php');
  $client = new PedraApiClient($conf['pedra_ai_api_key']);
  $result = $client->getCredits();

  if ($result['success']) {
    $live_plan    = $result['plan'];
    $live_credits = $result['creditsRemaining'];

    // Auto-sync the stored counter so the batch manager shows up-to-date credits
    if ($live_credits !== $remaining) {
      conf_update_param('pedra_ai_credits', (string) $live_credits, true);
      $conf['pedra_ai_credits'] = (string) $live_credits;
      $remaining = $live_credits;
    }
  }
}

echo json_encode([
  'stat'             => 'ok',
  'jobs'             => $jobs,
  'remaining_credits'=> $remaining,
  'live_plan'        => $live_plan,
  'live_credits'     => $live_credits,
]);
