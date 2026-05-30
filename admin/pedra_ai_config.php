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

  $api_key    = trim($_POST['pedra_ai_api_key'] ?? '');
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

  conf_update_param('pedra_ai_api_key',    $api_key,    true);
  conf_update_param('pedra_ai_default_op', $default_op, true);
  conf_update_param('pedra_ai_save_mode',  $save_mode,  true);
  conf_update_param('pedra_ai_suffix',     $suffix,     true);

  // Refresh $conf so template sees new values immediately
  $conf['pedra_ai_api_key']    = $api_key;
  $conf['pedra_ai_default_op'] = $default_op;
  $conf['pedra_ai_save_mode']  = $save_mode;
  $conf['pedra_ai_suffix']     = $suffix;

  $page['infos'][] = l10n('Configuration saved');
}

// ---------------------------------------------------------------------------
// Template
// ---------------------------------------------------------------------------

$action = get_admin_plugin_menu_link(PHPWG_PLUGINS_PATH . 'pedra_ai/admin/pedra_ai_config.php');

$template->set_filenames([
  'pedra_ai_config' => PHPWG_PLUGINS_PATH . 'pedra_ai/admin/tpl/pedra_ai_config.tpl',
]);

$template->assign([
  'pedra_ai_api_key'    => $conf['pedra_ai_api_key']    ?? '',
  'pedra_ai_default_op' => $conf['pedra_ai_default_op'] ?? 'enhance',
  'pedra_ai_save_mode'  => $conf['pedra_ai_save_mode']  ?? 'new',
  'pedra_ai_suffix'     => $conf['pedra_ai_suffix']     ?? '_pedra',
  'pedra_ai_operations' => $operations,
  'F_ACTION'            => $action,
  'PWG_TOKEN'           => get_pwg_token(),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'pedra_ai_config');
