<?php

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

function pedra_ai_process_collection(array $collection): void
{
  global $conf, $page;

  include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php');
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_metadata.php');
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/pedra_api.class.php');
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/functions.inc.php');

  $api_key = $conf['pedra_ai_api_key'] ?? '';
  $suffix  = $conf['pedra_ai_suffix']  ?? '_pedra';

  $valid_ops = unserialize(PEDRA_AI_OPERATIONS);

  $operation = isset($_POST['pedra_ai_op']) && in_array($_POST['pedra_ai_op'], $valid_ops)
    ? $_POST['pedra_ai_op']
    : ($conf['pedra_ai_default_op'] ?? 'enhance');

  $save_mode = isset($_POST['pedra_ai_save_mode']) && in_array($_POST['pedra_ai_save_mode'], ['new', 'overwrite'])
    ? $_POST['pedra_ai_save_mode']
    : ($conf['pedra_ai_save_mode'] ?? 'new');

  // common.inc.php applies addslashes to $_POST — strip before API use
  $str = function(string $key, string $default = ''): string {
    $val = $_POST[$key] ?? $default;
    return stripslashes(trim(is_array($val) ? $default : (string) $val));
  };
  // Per-image param: checks $_POST[$key][$image_id] first, falls back to $_POST[$key] (scalar)
  $str_for = function(string $key, int $image_id, string $default = '') use (&$str): string {
    $val = $_POST[$key] ?? null;
    if (is_array($val) && isset($val[$image_id])) {
      return stripslashes(trim((string) $val[$image_id]));
    }
    return $str($key, $default);
  };
  $bool = function(string $key): bool {
    return !empty($_POST[$key]) && $_POST[$key] !== '0';
  };

  if (empty($api_key)) {
    $page['errors'][] = l10n('Pedra AI: API key not configured. Please configure the plugin first.');
    return;
  }

  // Global params (shared across all images regardless of per-photo mode)
  $global_creativity      = $str('pedra_ai_creativity', 'Medium');
  $global_preserve_win    = $bool('pedra_ai_preserve_windows');
  $global_reno_furnish    = $bool('pedra_ai_reno_furnish');
  $global_preserve_framing = $bool('pedra_ai_preserve_framing');
  $global_room_type       = $str('pedra_ai_room_type', 'Living room');

  $client     = new PedraApiClient($api_key);
  $success_ct = 0;
  $error_ct   = 0;

  foreach ($collection as $raw_id) {
    $image_id = (int) $raw_id;
    if ($image_id <= 0) continue;

    // Build per-image extra params (supports both per-photo arrays and scalar fallbacks)
    $extra = [];

    switch ($operation) {
      case 'furnish':
        $room_type = $str_for('pedra_ai_room_type', $image_id, 'Living room');
        $style     = $str_for('pedra_ai_style', $image_id, 'Modern');
        if (empty($room_type)) { $error_ct++; pedra_ai_log_job($image_id, $operation, 'error', null, 'room type required'); continue 2; }
        if (empty($style))     { $error_ct++; pedra_ai_log_job($image_id, $operation, 'error', null, 'style required'); continue 2; }
        $extra['roomType']   = $room_type;
        $extra['style']      = $style;
        $extra['creativity'] = $global_creativity;
        break;

      case 'renovation':
        $style = $str_for('pedra_ai_style', $image_id, 'Modern');
        if (empty($style)) { $error_ct++; pedra_ai_log_job($image_id, $operation, 'error', null, 'style required'); continue 2; }
        $extra['style']      = $style;
        $extra['creativity'] = $global_creativity;
        if ($global_preserve_win) {
          $extra['preserveWindows'] = true;
        }
        if ($global_reno_furnish) {
          $extra['furnish']  = true;
          $extra['roomType'] = empty($global_room_type) ? 'Auto' : $global_room_type;
        }
        break;

      case 'edit_via_prompt':
        $prompt = $str_for('pedra_ai_prompt', $image_id);
        if (empty($prompt)) { $error_ct++; pedra_ai_log_job($image_id, $operation, 'error', null, 'prompt required'); continue 2; }
        $extra['prompt'] = $prompt;
        break;

      case 'blur':
        $objects = $str_for('pedra_ai_objects_to_blur', $image_id);
        if (empty($objects)) { $error_ct++; pedra_ai_log_job($image_id, $operation, 'error', null, 'objectsToBlur required'); continue 2; }
        $extra['objectsToBlur'] = $objects;
        break;

      case 'enhance':
      case 'enhance_and_correct_perspective':
        if ($global_preserve_framing) {
          $extra['preserveOriginalFraming'] = true;
        }
        break;
    }

    set_time_limit(180);
    pedra_ai_log_job($image_id, $operation, 'processing', null, null);

    try {
      $image_info = get_image_infos($image_id);
      if (!$image_info) {
        throw new RuntimeException('Image #' . $image_id . ' not found');
      }

      $image_url = pedra_ai_get_image_url($image_info);
      $result    = $client->process($operation, $image_url, $extra);

      if (!$result['success']) {
        throw new RuntimeException($result['error']);
      }

      $result_url   = $result['urls'][0];
      $tmp_path     = pedra_ai_download_url($result_url);
      $new_image_id = null;

      if ($save_mode === 'overwrite') {
        pedra_ai_overwrite_image($image_id, $tmp_path);
      } else {
        $new_image_id = pedra_ai_save_as_new_image($image_id, $tmp_path, $suffix);
      }

      pedra_ai_log_job($image_id, $operation, 'done', $result_url, null, $new_image_id, $extra['creativity'] ?? 'Medium');
      $success_ct++;

    } catch (RuntimeException $e) {
      $error_ct++;
      pedra_ai_log_job($image_id, $operation, 'error', null, $e->getMessage());
    }
  }

  if ($success_ct > 0) {
    $page['infos'][] = sprintf(
      l10n('Pedra AI: %d photo(s) processed successfully (%s, %s mode).'),
      $success_ct, $operation,
      $save_mode === 'overwrite' ? l10n('overwrite') : l10n('new photo')
    );
  }
  if ($error_ct > 0) {
    $page['errors'][] = sprintf(
      l10n('Pedra AI: %d photo(s) failed. Operation: %s.'),
      $error_ct, $operation
    );
  }

  invalidate_user_cache();
}
