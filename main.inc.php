<?php
/*
Plugin Name: Pedra AI
Version: 1.2.0
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
add_event_handler('loc_begin_index',              'pedra_ai_assign_template_vars');
add_event_handler('loc_after_page_header',        'pedra_ai_inject_widget');
add_event_handler('loc_begin_element_set_global', 'pedra_ai_batch_manager_register');
add_event_handler('element_set_global_action',    'pedra_ai_batch_manager_action');
add_event_handler('loc_begin_admin',              'pedra_ai_admin_menu');

// ---------------------------------------------------------------------------

function pedra_ai_init()
{
  load_language('plugin.lang', PHPWG_PLUGINS_PATH . 'pedra_ai/language/');
}

function pedra_ai_inject_widget()
{
  global $conf;

  // Only visible to administrators
  if (!is_admin()) {
    return;
  }
  // Requires an API key to be useful
  if (empty($conf['pedra_ai_api_key'])) {
    return;
  }

  $jobs_url        = get_absolute_root_url() . 'plugins/pedra_ai/jobs.php';
  $jobs_url_credits = $jobs_url . '?credits=1';
  $stored_credits  = ($conf['pedra_ai_credits'] ?? '') !== '' ? (int) $conf['pedra_ai_credits'] : null;
  $config_url      = htmlspecialchars(
    get_root_url() . 'admin.php?page=plugin&section=pedra_ai/admin.php'
  );

  $label_title       = l10n('Pedra AI — Recent jobs');
  $label_no_jobs     = l10n('No recent jobs (24h)');
  $label_processing  = l10n('processing…');
  $label_view        = l10n('View photo →');
  $label_credits_pfx = l10n('credits remaining');
  $label_free_note   = l10n('Free plan — limited credits');

  $badge_html = $stored_credits !== null
    ? '<span id="pedra-credit-badge" title="' . $label_credits_pfx . '">' . $stored_credits . '</span>'
    : '<span id="pedra-credit-badge" style="display:none"></span>';

  echo <<<HTML
<div id="pedra-ai-widget">
  <button id="pedra-widget-btn" title="{$label_title}" aria-expanded="false">
    ⚡{$badge_html}
  </button>
  <div id="pedra-widget-panel" role="dialog" aria-label="{$label_title}" hidden>
    <div class="pedra-panel-head">
      <strong>{$label_title}</strong>
      <span id="pedra-credits-line"></span>
      <a href="{$config_url}" class="pedra-config-link">⚙</a>
    </div>
    <div id="pedra-jobs-list"><em>{$label_no_jobs}</em></div>
  </div>
</div>
<style>
#pedra-ai-widget{position:fixed;bottom:18px;right:18px;z-index:10000;font-family:sans-serif;font-size:13px}
#pedra-widget-btn{background:#1a1a2e;color:#f0c040;border:none;border-radius:50px;padding:7px 13px;cursor:pointer;font-size:15px;font-weight:bold;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;gap:5px}
#pedra-widget-btn:hover{background:#2c2c54}
#pedra-credit-badge{background:#f0c040;color:#1a1a2e;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;min-width:20px;text-align:center}
#pedra-widget-panel{background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.18);width:320px;max-height:440px;overflow-y:auto;position:absolute;bottom:48px;right:0;padding:0}
.pedra-panel-head{background:#1a1a2e;color:#fff;padding:10px 14px;border-radius:7px 7px 0 0;display:flex;align-items:center;gap:8px}
.pedra-panel-head strong{flex:1}
#pedra-credits-line{font-size:12px;opacity:.85;white-space:nowrap}
.pedra-config-link{color:#f0c040;text-decoration:none;font-size:15px}
#pedra-jobs-list{padding:10px 14px}
.pedra-job{padding:5px 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:flex-start;gap:6px;font-size:12px}
.pedra-job:last-child{border-bottom:none}
.pedra-job-icon{font-size:14px;min-width:16px}
.pedra-job-body{flex:1;min-width:0}
.pedra-job-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pedra-job-op{color:#888;font-size:11px}
.pedra-job-link{font-size:11px;color:#0073aa;text-decoration:none}
</style>
<script>
(function($){
  var JOBS_URL         = <?= json_encode($jobs_url) ?>;
  var JOBS_CREDITS_URL = <?= json_encode($jobs_url_credits) ?>;
  var labelProcessing  = <?= json_encode($label_processing) ?>;
  var labelNoJobs      = <?= json_encode($label_no_jobs) ?>;
  var labelView        = <?= json_encode($label_view) ?>;
  var labelCreditsPfx  = <?= json_encode($label_credits_pfx) ?>;
  var labelFreeNote    = <?= json_encode($label_free_note) ?>;

  var isOpen = false;

  function statusIcon(status) {
    return {done:'✅', error:'❌', processing:'⏳', pending:'⏳'}[status] || '•';
  }

  function renderJobs(jobs) {
    if (!jobs || jobs.length === 0) {
      return '<em>' + labelNoJobs + '</em>';
    }
    return jobs.map(function(j) {
      var link = j.photo_url
        ? '<a class="pedra-job-link" href="' + j.photo_url + '" target="_blank">' + labelView + '</a>'
        : (j.status === 'processing' ? '<em>' + labelProcessing + '</em>' : '');
      var err  = j.error ? '<span style="color:#c0392b;font-size:11px">' + j.error.substring(0, 80) + '</span>' : '';
      return '<div class="pedra-job">'
           + '<span class="pedra-job-icon">' + statusIcon(j.status) + '</span>'
           + '<div class="pedra-job-body">'
           + '<div class="pedra-job-name">' + j.name + '</div>'
           + '<div class="pedra-job-op">' + j.operation + '</div>'
           + err + link
           + '</div></div>';
    }).join('');
  }

  function updateCredits(plan, credits) {
    if (credits === null || credits === undefined) return;
    var badge = $('#pedra-credit-badge');
    badge.text(credits).show();
    var line  = '';
    if (plan && plan !== 'unknown') {
      line = credits + ' ' + labelCreditsPfx + ' (' + plan + ')';
      if (plan === 'free') line += ' — ' + labelFreeNote;
    } else {
      line = credits + ' ' + labelCreditsPfx;
    }
    $('#pedra-credits-line').text(line);
    // Update batch manager panel if present
    $('#pedra-batch-credits').text(credits + ' ' + labelCreditsPfx);
  }

  $('#pedra-widget-btn').on('click', function() {
    isOpen = !isOpen;
    var panel = $('#pedra-widget-panel');
    panel.prop('hidden', !isOpen);
    $(this).attr('aria-expanded', isOpen);

    if (isOpen) {
      // 1. Fetch jobs immediately (fast, no credit API call)
      $.getJSON(JOBS_URL, function(data) {
        $('#pedra-jobs-list').html(renderJobs(data.jobs));
        // Show stored credits while waiting for live fetch
        if (data.remaining_credits !== null) {
          updateCredits(null, data.remaining_credits);
        }
      });

      // 2. Fetch live credits from Pedra API in background
      $.getJSON(JOBS_CREDITS_URL, function(data) {
        if (data.live_credits !== null && data.live_credits !== undefined) {
          updateCredits(data.live_plan, data.live_credits);
        }
      });
    }
  });

  // Close panel when clicking outside
  $(document).on('click', function(e) {
    if (isOpen && !$(e.target).closest('#pedra-ai-widget').length) {
      isOpen = false;
      $('#pedra-widget-panel').prop('hidden', true);
      $('#pedra-widget-btn').attr('aria-expanded', false);
    }
  });
})(jQuery);
</script>
HTML;
}

function pedra_ai_assign_template_vars()
{
  global $template, $conf;
  $server_ready = !empty($conf['pedra_ai_server_url']) && !empty($conf['pedra_ai_server_token']);
  $template->assign('PEDRA_SERVER_CONFIGURED', $server_ready);
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

  // Expose whether the processing server is configured so templates can show/hide video UI
  $server_ready = !empty($conf['pedra_ai_server_url']) && !empty($conf['pedra_ai_server_token']);
  $template->assign('PEDRA_SERVER_CONFIGURED', $server_ready);

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

  $credits_conf = ($conf['pedra_ai_credits'] ?? '') !== '' ? (int) $conf['pedra_ai_credits'] : null;
  $credits_display = $credits_conf !== null
    ? '<span id="pedra-batch-credits" style="margin-left:8px;color:#888;font-size:12px">'
      . $credits_conf . ' ' . l10n('credits remaining') . '</span>'
    : '<span id="pedra-batch-credits" style="display:none"></span>';

  $html  = '<div class="pedra-ai-action" style="padding:10px 0">';
  $html .= '<p style="margin:0 0 8px;font-size:12px;color:#555">'
         . l10n('Credits will be fetched automatically when you open the ⚡ widget.')
         . $credits_display . '</p>';
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
