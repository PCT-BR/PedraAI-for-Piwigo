<?php

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class pedra_ai_maintain extends PluginMaintain
{
  public function install($plugin_version, &$errors = [])
  {
    global $conf, $prefixeTable;

    $jobs_table = $prefixeTable . 'pedra_ai_jobs';

    $query = '
CREATE TABLE IF NOT EXISTS `' . $jobs_table . '` (
  `id`         int(11) unsigned     NOT NULL AUTO_INCREMENT,
  `image_id`   mediumint(8) unsigned NOT NULL,
  `operation`  varchar(50)          NOT NULL,
  `status`     enum("pending","processing","done","error") NOT NULL DEFAULT "pending",
  `result_url` varchar(512)         DEFAULT NULL,
  `error_msg`  text                 DEFAULT NULL,
  `created_at` datetime             NOT NULL,
  `updated_at` TIMESTAMP            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `image_id` (`image_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

    pwg_query($query);

    if (!isset($conf['pedra_ai_api_key'])) {
      conf_update_param('pedra_ai_api_key', '', true);
    }
    if (!isset($conf['pedra_ai_default_op'])) {
      conf_update_param('pedra_ai_default_op', 'enhance', true);
    }
    if (!isset($conf['pedra_ai_save_mode'])) {
      conf_update_param('pedra_ai_save_mode', 'new', true);
    }
    if (!isset($conf['pedra_ai_suffix'])) {
      conf_update_param('pedra_ai_suffix', '_pedra', true);
    }
  }

  public function activate($plugin_version, &$errors = [])
  {
    // Nothing extra needed on activation
  }

  public function deactivate()
  {
    // Nothing needed on deactivation; config and data are preserved
  }

  public function uninstall()
  {
    global $prefixeTable;

    $jobs_table = $prefixeTable . 'pedra_ai_jobs';
    pwg_query('DROP TABLE IF EXISTS `' . $jobs_table . '`;');

    conf_delete_param([
      'pedra_ai_api_key',
      'pedra_ai_default_op',
      'pedra_ai_save_mode',
      'pedra_ai_suffix',
    ]);
  }
}
