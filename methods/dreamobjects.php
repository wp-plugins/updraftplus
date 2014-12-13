<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed.');

require_once(UPDRAFTPLUS_DIR.'/methods/s3.php');

# Migrate options to new-style storage - Jan 2014
if (!is_array(UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects')) && '' != UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_login', '')) {
	$opts = array(
		'accesskey' => UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_login'),
		'secretkey' => UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_pass'),
		'path' => UpdraftPlus_Options::get_updraft_option('updraft_dreamobjects_remote_path'),
	);
	UpdraftPlus_Options::update_updraft_option('updraft_dreamobjects', $opts);
	UpdraftPlus_Options::delete_updraft_option('updraft_dreamobjects_login');
	UpdraftPlus_Options::delete_updraft_option('updraft_dreamobjects_pass');
	UpdraftPlus_Options::delete_updraft_option('updraft_dreamobjects_remote_path');
}

class UpdraftPlus_BackupModule_dreamobjects extends UpdraftPlus_BackupModule_s3 {

	protected function set_endpoint($obj, $region) {
		$config = $this->get_config();
		global $updraftplus;
		$updraftplus->log("Set endpoint: ".$config['endpoint']);
		$obj->setEndpoint($config['endpoint']);
	}

	public function get_credentials() {
		return array('updraft_dreamobjects');
	}

	protected function get_config() {
		global $updraftplus;
		$opts = $updraftplus->get_job_option('updraft_dreamobjects');
		if (!is_array($opts)) $opts = array('accesskey' => '', 'secretkey' => '', 'path' => '');
		$opts['whoweare'] = 'DreamObjects';
		$opts['whoweare_long'] = 'DreamObjects';
		$opts['key'] = 'dreamobjects';
		$opts['endpoint'] = 'objects.dreamhost.com';
		return $opts;
	}

	public function config_print() {
		$this->config_print_engine('dreamobjects', 'DreamObjects', 'DreamObjects', 'DreamObjects', 'https://panel.dreamhost.com/index.cgi?tree=storage.dreamhostobjects', '<a href="http://dreamhost.com/cloud/dreamobjects/"><img alt="DreamObjects" src="'.UPDRAFTPLUS_URL.'/images/dreamobjects_logo-horiz-2013.png"></a>');
	}

	public function config_print_javascript_onready() {
		$this->config_print_javascript_onready_engine('dreamobjects', 'DreamObjects');
	}

	public function credentials_test() {
		$this->credentials_test_engine($this->get_config());
	}

}
?>
