<?php
/*
Plugin Name: Pedra AI
Version: 1.0.0
Description: Real estate photo processing via Pedra AI API (virtual staging, renovation, enhancement, etc.)
Plugin URI: https://pedra.ai
Author: Piwigo User
Author URI:
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

// Define jobs table constant using the configured DB prefix
global $prefixeTable;
if (!defined('PEDRA_AI_JOBS_TABLE')) {
  define('PEDRA_AI_JOBS_TABLE', $prefixeTable . 'pedra_ai_jobs');
}

// Pedra AI available operations
define('PEDRA_AI_OPERATIONS', serialize([
  'furnish',
  'empty_room',
  'renovation',
  'edit_via_prompt',
  'remove_object',
  'enhance',
  'enhance_and_correct_perspective',
  'sky_blue',
  'blur',
]));

// Credit cost per operation. Furnish/renovation cost 2 credits on Medium, 1 on High.
// All other operations cost 1 credit. Used for the manual credits counter.
define('PEDRA_AI_CREDIT_COSTS', serialize([
  'furnish'                         => ['medium' => 2, 'high' => 1, 'default' => 2],
  'renovation'                      => ['medium' => 2, 'high' => 1, 'default' => 2],
  'empty_room'                      => ['default' => 1],
  'edit_via_prompt'                  => ['default' => 1],
  'remove_object'                   => ['default' => 1],
  'enhance'                         => ['default' => 1],
  'enhance_and_correct_perspective' => ['default' => 1],
  'sky_blue'                        => ['default' => 1],
  'blur'                            => ['default' => 1],
]));

add_event_handler('init',                         'pedra_ai_init');
add_event_handler('loc_begin_element_set_global', 'pedra_ai_batch_manager_register');
add_event_handler('element_set_global_action',    'pedra_ai_batch_manager_action');
add_event_handler('loc_begin_admin',              'pedra_ai_admin_menu');

// ---------------------------------------------------------------------------

function pedra_ai_init()
{
  load_language('plugin.lang', PHPWG_PLUGINS_PATH . 'pedra_ai/language/');
}

function pedra_ai_admin_menu()
{
  global $template;

  $link = get_admin_plugin_menu_link(PHPWG_PLUGINS_PATH . 'pedra_ai/admin/pedra_ai_config.php');
  $template->assign('PEDRA_AI_ADMIN_LINK', $link);
  $template->set_prefilter('menubar', 'pedra_ai_add_menu_entry');
}

function pedra_ai_add_menu_entry($content, &$smarty)
{
  global $template;

  $link  = $template->get_template_vars('PEDRA_AI_ADMIN_LINK');
  $entry = '<li><a href="' . $link . '">Pedra AI</a></li>';

  // Insert after the last </li> before the closing </ul> of the Tools section
  $pattern = '#(</ul>\s*</li>\s*</ul>\s*</div>)#s';
  $replace  = $entry . '$1';
  $result   = preg_replace($pattern, $replace, $content, 1);

  return $result ?: $content;
}

// ---------------------------------------------------------------------------
// Batch Manager integration
// ---------------------------------------------------------------------------

function pedra_ai_batch_manager_register()
{
  global $template, $conf;

  if (empty($conf['pedra_ai_api_key'])) {
    return;
  }

  $template->append('element_set_global_plugins_actions', [
    'ID'      => 'pedra_ai',
    'NAME'    => l10n('Pedra AI Processing'),
    'CONTENT' => pedra_ai_get_action_html(),
  ]);
}

function pedra_ai_batch_manager_action($action, $collection)
{
  if ('pedra_ai' !== $action) {
    return;
  }
  if (empty($collection)) {
    return;
  }

  include_once(PHPWG_PLUGINS_PATH . 'pedra_ai/admin/pedra_ai_process.php');
  pedra_ai_process_collection($collection);
}

function pedra_ai_get_action_html(): string
{
  global $conf;

  $operations  = unserialize(PEDRA_AI_OPERATIONS);
  $default_op  = $conf['pedra_ai_default_op'] ?? 'enhance';
  $save_mode   = $conf['pedra_ai_save_mode']  ?? 'new';
  $suffix      = htmlspecialchars($conf['pedra_ai_suffix'] ?? '_pedra', ENT_QUOTES);

  $html  = '<div class="pedra-ai-action" style="padding:10px 0">';
  $html .= '<label style="display:block;margin-bottom:8px"><strong>' . l10n('Operation') . ':</strong> ';
  $html .= '<select name="pedra_ai_op" style="margin-left:6px">';

  foreach ($operations as $op) {
    $selected = ($op === $default_op) ? ' selected' : '';
    $html .= '<option value="' . htmlspecialchars($op, ENT_QUOTES) . '"' . $selected . '>'
           . htmlspecialchars($op, ENT_QUOTES) . '</option>';
  }

  $html .= '</select></label>';
  $html .= '<label style="display:block;margin-bottom:8px">' . l10n('Prompt (edit_via_prompt only)') . ': ';
  $html .= '<input type="text" name="pedra_ai_prompt" placeholder="' . l10n('Describe modifications...') . '" style="width:380px;margin-left:6px"></label>';
  $html .= '<label style="display:inline-block;margin-right:16px">';
  $html .= '<input type="radio" name="pedra_ai_save_mode" value="new"' . ($save_mode === 'new' ? ' checked' : '') . '> ';
  $html .= l10n('Save as new photo') . ' <em style="color:#888">(' . l10n('suffix') . ': ' . $suffix . ')</em></label>';
  $html .= '<label style="display:inline-block">';
  $html .= '<input type="radio" name="pedra_ai_save_mode" value="overwrite"' . ($save_mode === 'overwrite' ? ' checked' : '') . '> ';
  $html .= l10n('Overwrite original') . '</label>';
  $html .= '</div>';

  return $html;
}
