<?php

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Process a collection of Piwigo image IDs through the Pedra AI API.
 * Called from the Batch Manager via the element_set_global_action hook.
 * The CSRF token has already been validated by batch_manager_global.php (line 30).
 *
 * @param array $collection  Array of image IDs (integers as strings)
 */
function pedra_ai_process_collection(array $collection): void
{
  global $conf, $page;

  include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php');
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_metadata.php');
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/pedra_api.class.php');
  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/include/functions.inc.php');

  $api_key   = $conf['pedra_ai_api_key'] ?? '';
  $suffix    = $conf['pedra_ai_suffix']     ?? '_pedra';

  $valid_ops = unserialize(PEDRA_AI_OPERATIONS);

  $operation = isset($_POST['pedra_ai_op']) && in_array($_POST['pedra_ai_op'], $valid_ops)
                 ? $_POST['pedra_ai_op']
                 : ($conf['pedra_ai_default_op'] ?? 'enhance');

  $save_mode = isset($_POST['pedra_ai_save_mode']) && in_array($_POST['pedra_ai_save_mode'], ['new', 'overwrite'])
                 ? $_POST['pedra_ai_save_mode']
                 : ($conf['pedra_ai_save_mode'] ?? 'new');

  $prompt = trim($_POST['pedra_ai_prompt'] ?? '');

  // Guard: API key required
  if (empty($api_key)) {
    $page['errors'][] = l10n('Pedra AI: API key not configured. Please configure the plugin first.');
    return;
  }

  // Guard: prompt required for edit_via_prompt
  if ($operation === 'edit_via_prompt' && empty($prompt)) {
    $page['errors'][] = l10n('Pedra AI: a prompt is required for the edit_via_prompt operation.');
    return;
  }

  $client      = new PedraApiClient($api_key);
  $success_ct  = 0;
  $error_ct    = 0;
  $extra        = ($operation === 'edit_via_prompt') ? ['prompt' => $prompt] : [];

  foreach ($collection as $raw_id) {
    $image_id = (int) $raw_id;
    if ($image_id <= 0) {
      continue;
    }

    // Reset the execution clock per image — Pedra takes up to 30s + download overhead
    set_time_limit(180);

    pedra_ai_log_job($image_id, $operation, 'processing', null, null);

    try {
      $image_info = get_image_infos($image_id);
      if (!$image_info) {
        throw new RuntimeException('Image #' . $image_id . ' not found');
      }

      $b64    = pedra_ai_image_to_base64($image_info);
      $result = $client->process($operation, $b64, $extra);

      if (!$result['success']) {
        throw new RuntimeException($result['error']);
      }

      // Pedra returns an array of URLs; we use the first result
      $result_url = $result['urls'][0];
      $tmp_path   = pedra_ai_download_url($result_url);

      if ($save_mode === 'overwrite') {
        pedra_ai_overwrite_image($image_id, $tmp_path);
      } else {
        pedra_ai_save_as_new_image($image_id, $tmp_path, $suffix);
      }

      pedra_ai_log_job($image_id, $operation, 'done', $result_url, null);
      $success_ct++;

    } catch (RuntimeException $e) {
      $error_ct++;
      pedra_ai_log_job($image_id, $operation, 'error', null, $e->getMessage());
      // Log and continue — don't abort the entire batch for a single failure
    }
  }

  if ($success_ct > 0) {
    $page['infos'][] = sprintf(
      l10n('Pedra AI: %d photo(s) processed successfully (%s, %s mode).'),
      $success_ct,
      $operation,
      $save_mode === 'overwrite' ? l10n('overwrite') : l10n('new photo')
    );
  }

  if ($error_ct > 0) {
    $page['errors'][] = sprintf(
      l10n('Pedra AI: %d photo(s) failed. Operation: %s.'),
      $error_ct,
      $operation
    );
  }

  invalidate_user_cache();
}
