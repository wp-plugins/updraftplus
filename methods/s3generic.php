<?php

require_once(UPDRAFTPLUS_DIR.'/methods/s3.php');

class UpdraftPlus_BackupModule_s3generic extends UpdraftPlus_BackupModule_s3 {

	function set_endpoint($obj, $region = '') {
		$config = self::get_config();
		$endpoint = ($region != '' && $region != 'n/a') ? $region : $config['endpoint'];
		global $updraftplus;
		$updraftplus->log("Set endpoint: $endpoint");
		$obj->setEndpoint($endpoint);
	}

	function get_config() {
		return array(
			'login' => UpdraftPlus_Options::get_updraft_option('updraft_s3generic_login'),
			'pass' => UpdraftPlus_Options::get_updraft_option('updraft_s3generic_pass'),
			'remote_path' => UpdraftPlus_Options::get_updraft_option('updraft_s3generic_remote_path'),
			'whoweare' => 'S3',
			'whoweare_long' => __('S3 (Compatible)', 'updraftplus'),
			'key' => 's3generic',
			'endpoint' => UpdraftPlus_Options::get_updraft_option('updraft_s3generic_endpoint')
		);
	}

	public static function config_print() {
		// 5th parameter = control panel URL
		// 6th = image HTML
		self::config_print_engine('s3generic', 'S3', __('S3 (Compatible)', 'updraftplus'), 'S3', '', '', true);
	}

	public static function config_print_javascript_onready() {
		self::config_print_javascript_onready_engine('s3generic', 'S3');
	}

	public static function credentials_test() {
		self::credentials_test_engine(self::get_config());
	}

}
?>
