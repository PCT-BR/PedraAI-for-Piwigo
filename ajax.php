<?php
// Buffer everything from common.inc.php (PHP errors, warnings displayed as HTML)
ob_start();

if (!defined('PHPWG_ROOT_PATH')) {
  define('PHPWG_ROOT_PATH', dirname(dirname(dirname(__FILE__))) . '/');
}
include(PHPWG_ROOT_PATH . 'include/common.inc.php');

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

$image_ids = array_filter(array_map('intval', (array) ($_POST['image_ids'] ?? [])));
if (empty($image_ids)) {
  echo json_encode(['stat' => 'fail', 'message' => 'No images']);
  exit;
}

$operation = trim($_POST['pedra_ai_op'] ?? '');

// ── Video operations → async server route ────────────────────────────────────

$video_ops = ['create_video', 'video_create', 'video_furnish', 'video'];
if (in_array($operation, $video_ops, true)) {
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/functions.inc.php');
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/pedra_server.class.php');
  pedra_ai_migrate_jobs_table();

  global $conf;

  $server_url   = $conf['pedra_ai_server_url']   ?? '';
  $server_token = $conf['pedra_ai_server_token']  ?? '';

  if (empty($server_url) || empty($server_token)) {
    echo json_encode(['stat' => 'fail', 'errors' => ['Processing server not configured. Set Server URL and Server Token in plugin settings.'], 'infos' => []]);
    exit;
  }

  $client = new PedraServerClient($server_url, $server_token);

  // Build params.images array from POST
  // Each image_id maps to an effect; per-image effects sent as pedra_video_effect[{id}]
  $effects_map = $_POST['pedra_video_effect'] ?? [];
  $default_effect = 'zoom-in';

  $images = [];
  foreach ($image_ids as $image_id) {
    $effect = 'zoom-in';
    if (is_array($effects_map) && isset($effects_map[$image_id])) {
      $effect = in_array($effects_map[$image_id], ['zoom-in', 'zoom-out', 'static', 'transition'], true)
              ? $effects_map[$image_id]
              : 'zoom-in';
    }
    $images[] = [
      'imageUrl' => pedra_ai_signed_url($image_id),
      'effect'   => $effect,
    ];
  }

  // Global video params
  $str = function(string $key): string {
    $v = $_POST[$key] ?? '';
    return stripslashes(trim(is_array($v) ? '' : (string) $v));
  };
  $bool_post = function(string $key): bool {
    return !empty($_POST[$key]) && $_POST[$key] !== '0';
  };

  $params = ['images' => $images];
  if ($bool_post('pedra_video_vertical'))   $params['isVertical']  = true;
  $ending_title    = $str('pedra_video_ending_title');
  $ending_subtitle = $str('pedra_video_ending_subtitle');
  $prop_chars      = $str('pedra_video_prop_chars');
  if ($ending_title    !== '') $params['endingTitle']                = $ending_title;
  if ($ending_subtitle !== '') $params['endingSubtitle']             = $ending_subtitle;
  if ($prop_chars      !== '') $params['propertyCharacteristics']    = $prop_chars;

  // Submit one job per request (all images → one video)
  $job_id     = 'piwigo-' . implode('-', $image_ids) . '-' . time();
  $webhook_url = get_absolute_root_url() . 'plugins/pedra_ai/webhook.php';

  // Store one job row per source image (for widget display), mark first as the lead
  $lead_image_id = reset($image_ids);
  $db_job_id = pedra_ai_log_job(
    $lead_image_id,
    $operation,
    'processing',
    null,
    null,
    null,
    'Medium',
    $job_id
  );

  $result = $client->submitJob([
    'job_id'     => $job_id,
    'type'       => 'video',
    'operation'  => $operation,
    'params'     => $params,
    'webhook'    => ['url' => $webhook_url, 'token' => $server_token],
    'meta'       => ['image_id' => $lead_image_id, 'piwigo_url' => get_absolute_root_url()],
  ]);

  if (!$result['success']) {
    // Update DB row to error
    pwg_query('UPDATE `' . PEDRA_AI_JOBS_TABLE . '` SET `status`="error", `error_msg`="' . pwg_db_real_escape_string(substr($result['error'], 0, 500)) . '" WHERE `id`=' . $db_job_id);
    echo json_encode(['stat' => 'fail', 'errors' => [$result['error']], 'infos' => []]);
    exit;
  }

  // Store server_job_id for polling
  $server_job_id = $result['data']['server_job_id'] ?? '';
  if ($server_job_id !== '') {
    pwg_query('UPDATE `' . PEDRA_AI_JOBS_TABLE . '` SET `server_job_id`="' . pwg_db_real_escape_string($server_job_id) . '" WHERE `id`=' . $db_job_id);
  }

  echo json_encode([
    'stat'          => 'ok',
    'infos'         => [l10n('Pedra AI: video job submitted — processing in background. Check the ⚡ widget for result.')],
    'errors'        => [],
    'server_job_id' => $server_job_id,
  ]);
  exit;
}

// ── Photo operations → direct Pedra API (existing synchronous flow) ──────────

include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/admin/pedra_ai_process.php');

// Safety-net: converts any die() or stray echo to JSON before reaching the client
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
