<?php

require_once(UPDRAFTPLUS_DIR.'/methods/s3.php');

class UpdraftPlus_BackupModule_dreamobjects extends UpdraftPlus_BackupModule_s3 {

	function set_endpoint($obj, $region) {
		$config = self::get_config();
		global $updraftplus;
		$updraftplus->log("Set endpoint: ".$config['endpoint']);
		$obj->setEndpoint($config['endpoint']);
	}

	function get_config() {
		return array(
			'login' => UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_login'),
			'pass' => UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_pass'),
			'remote_path' => UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_remote_path'),
			'whoweare' => 'DreamObjects',
			'whoweare_long' => 'DreamObjects',
			'key' => 'dreamobjects',
			'endpoint' => 'objects.dreamhost.com'
		);
	}

	public static function config_print() {
		self::config_print_engine('dreamobjects', 'DreamObjects', 'DreamObjects', 'DreamObjects', 'https://panel.dreamhost.com/index.cgi?tree=storage.dreamhostobjects', '<a href="http://dreamhost.com/cloud/dreamobjects/"><img alt="DreamObjects" src="'.UPDRAFTPLUS_URL.'/images/dreamobjects_logo-horiz-2013.png"></a>');
	}

	public static function config_print_javascript_onready() {
		self::config_print_javascript_onready_engine('dreamobjects', 'DreamObjects');
	}

	public static function credentials_test() {
		self::credentials_test_engine(self::get_config());
	}

}
?>
