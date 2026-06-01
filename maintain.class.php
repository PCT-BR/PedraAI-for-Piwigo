<?php

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class pedra_ai_maintain extends PluginMaintain
{
  public function install($plugin_version, &$errors = [])
  {
    global $conf, $prefixeTable;

    $jobs_table = $prefixeTable . 'pedra_ai_jobs';

    // Full schema including columns added after v1.0 (job_id, server_job_id, new_image_id)
    $query = '
CREATE TABLE IF NOT EXISTS `' . $jobs_table . '` (
  `id`            int(11) unsigned     NOT NULL AUTO_INCREMENT,
  `job_id`        varchar(80)          DEFAULT NULL,
  `server_job_id` varchar(80)          DEFAULT NULL,
  `image_id`      mediumint(8) unsigned NOT NULL,
  `operation`     varchar(50)          NOT NULL,
  `status`        enum("pending","processing","done","error") NOT NULL DEFAULT "pending",
  `result_url`    varchar(512)         DEFAULT NULL,
  `new_image_id`  int(11)              DEFAULT NULL,
  `error_msg`     text                 DEFAULT NULL,
  `created_at`    datetime             NOT NULL,
  `updated_at`    TIMESTAMP            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `image_id` (`image_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

    pwg_query($query);

    $defaults = [
      'pedra_ai_api_key'      => '',
      'pedra_ai_default_op'   => 'enhance',
      'pedra_ai_save_mode'    => 'new',
      'pedra_ai_suffix'       => '_pedra',
      'pedra_ai_credits'      => '',
      'pedra_ai_server_url'   => '',
      'pedra_ai_server_token' => '',
    ];
    foreach ($defaults as $key => $value) {
      if (!isset($conf[$key])) {
        conf_update_param($key, $value, true);
      }
    }
  }

  public function activate($plugin_version, &$errors = [])
  {
    // Migrate existing installs: add columns introduced after v1.0
    global $prefixeTable;
    $jobs_table = $prefixeTable . 'pedra_ai_jobs';

    $cols_to_add = [
      'job_id'        => 'VARCHAR(80) DEFAULT NULL AFTER `id`',
      'server_job_id' => 'VARCHAR(80) DEFAULT NULL AFTER `job_id`',
      'new_image_id'  => 'INT(11) DEFAULT NULL AFTER `result_url`',
    ];
    foreach ($cols_to_add as $col => $definition) {
      $check = pwg_query("SHOW COLUMNS FROM `" . $jobs_table . "` LIKE '" . $col . "'");
      if (pwg_db_num_rows($check) === 0) {
        pwg_query("ALTER TABLE `" . $jobs_table . "` ADD COLUMN `" . $col . "` " . $definition);
      }
    }
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
      'pedra_ai_credits',
      'pedra_ai_server_url',
      'pedra_ai_server_token',
    ]);
  }
}
