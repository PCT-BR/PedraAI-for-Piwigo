<?php

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH . 'admin/include/functions_plugins.inc.php');

check_status(ACCESS_ADMINISTRATOR);

$operations = unserialize(PEDRA_AI_OPERATIONS);

// ---------------------------------------------------------------------------
// Handle form submission
// ---------------------------------------------------------------------------

if (!empty($_POST) && isset($_POST['pedra_submit'])) {
  check_pwg_token();

  $api_key      = trim($_POST['pedra_ai_api_key'] ?? '');
  $server_url   = rtrim(trim($_POST['pedra_ai_server_url']   ?? ''), '/');
  $server_token = trim($_POST['pedra_ai_server_token'] ?? '');
  $default_op = in_array($_POST['pedra_ai_default_op'] ?? '', $operations)
                  ? $_POST['pedra_ai_default_op']
                  : 'enhance';
  $save_mode  = in_array($_POST['pedra_ai_save_mode'] ?? '', ['new', 'overwrite'])
                  ? $_POST['pedra_ai_save_mode']
                  : 'new';
  $suffix     = preg_replace('/[^a-z0-9_-]/i', '', $_POST['pedra_ai_suffix'] ?? '_pedra');
  if (empty($suffix)) {
    $suffix = '_pedra';
  }

  // Credits: empty string means "not tracked" (null); otherwise must be non-negative integer
  $credits_raw = trim($_POST['pedra_ai_credits'] ?? '');
  $credits     = ($credits_raw !== '') ? max(0, (int) $credits_raw) : null;

  conf_update_param('pedra_ai_api_key',      $api_key,                                  true);
  conf_update_param('pedra_ai_default_op',   $default_op,                               true);
  conf_update_param('pedra_ai_save_mode',    $save_mode,                                true);
  conf_update_param('pedra_ai_suffix',       $suffix,                                   true);
  conf_update_param('pedra_ai_credits',      $credits !== null ? (string) $credits : '', true);
  conf_update_param('pedra_ai_server_url',   $server_url,                               true);
  conf_update_param('pedra_ai_server_token', $server_token,                             true);

  // Refresh $conf so template sees new values immediately
  $conf['pedra_ai_api_key']      = $api_key;
  $conf['pedra_ai_default_op']   = $default_op;
  $conf['pedra_ai_save_mode']    = $save_mode;
  $conf['pedra_ai_suffix']       = $suffix;
  $conf['pedra_ai_credits']      = $credits !== null ? (string) $credits : '';
  $conf['pedra_ai_server_url']   = $server_url;
  $conf['pedra_ai_server_token'] = $server_token;

  $page['infos'][] = l10n('Configuration saved');
}

// ---------------------------------------------------------------------------
// Template
// ---------------------------------------------------------------------------

$action = get_admin_plugin_menu_link(PHPWG_PLUGINS_PATH . 'pedra_ai/admin/pedra_ai_config.php');

$template->set_filenames([
  'pedra_ai_config' => PHPWG_PLUGINS_PATH . 'pedra_ai/admin/tpl/pedra_ai_config.tpl',
]);

$credits_conf = $conf['pedra_ai_credits'] ?? '';
$template->assign([
  'pedra_ai_api_key'      => $conf['pedra_ai_api_key']      ?? '',
  'pedra_ai_default_op'   => $conf['pedra_ai_default_op']   ?? 'enhance',
  'pedra_ai_save_mode'    => $conf['pedra_ai_save_mode']     ?? 'new',
  'pedra_ai_suffix'       => $conf['pedra_ai_suffix']        ?? '_pedra',
  'pedra_ai_credits'      => ($credits_conf !== '') ? (int) $credits_conf : null,
  'pedra_ai_server_url'   => $conf['pedra_ai_server_url']   ?? '',
  'pedra_ai_server_token' => $conf['pedra_ai_server_token'] ?? '',
  'pedra_ai_operations'   => $operations,
  'F_ACTION'              => $action,
  'PWG_TOKEN'             => get_pwg_token(),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'pedra_ai_config');
