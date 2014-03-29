<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed.');

class UpdraftPlus_BackupModule_ViaAddon {

	private $method;
	private $description;

	public function __construct($method, $description) {
		$this->method = $method;
		$this->description = $description;
	}

	public function backup($backup_array) {

		global $updraftplus;

		if (!class_exists('UpdraftPlus_Addons_RemoteStorage_'.$this->method)) {
			$updraftplus->log("You do not have the UpdraftPlus ".$this->method.' add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->log(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'), $this->description ,'http://updraftplus.com/shop/'), 'error');
			return false;
		}

		return apply_filters('updraft_'.$this->method.'_upload_files', null, $backup_array);

	}

	public function delete($files, $method_obj = false) {

		global $updraftplus;

		if (!class_exists('UpdraftPlus_Addons_RemoteStorage_'.$this->method)) {
			$updraftplus->log('You do not have the UpdraftPlus '.$this->method.' add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->log(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'), $this->description, 'http://updraftplus.com/shop/'), 'error');
			return false;
		}

		return apply_filters('updraft_'.$this->method.'_delete_files', false, $files, $method_obj);

	}

	public function listfiles($match = 'backup_') {
		return apply_filters('updraft_'.$this->method.'_listfiles', new WP_Error('no_addon', sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'), $this->description, 'http://updraftplus.com/shop/')), $match);
	}

	// download method: takes a file name (base name), and removes it from the cloud storage
	public function download($file) {

		global $updraftplus;

		if (!class_exists('UpdraftPlus_Addons_RemoteStorage_'.$this->method)) {
			$updraftplus->log('You do not have the UpdraftPlus '.$this->method.' add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->log(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'), $this->description, 'http://updraftplus.com/shop/'), 'error');
			return false;
		}

		return apply_filters('updraft_'.$this->method.'_download_file', false, $file);

	}

	public function config_print() {

		$link = sprintf(__('%s support is available as an add-on','updraftplus'), $this->description).' - <a href="http://updraftplus.com/shop/'.$this->method.'/">'.__('follow this link to get it','updraftplus');

		$default = '
		<tr class="updraftplusmethod '.$this->method.'">
			<th>'.$this->description.':</th>
			<td>'.$link.'</a></td>
		</tr>';

		echo apply_filters('updraft_'.$this->method.'_config_print', $default);
	}

	public function config_print_javascript_onready() {
		do_action('updraft_'.$this->method.'_config_javascript');
	}

	public function credentials_test() {
		do_action('updraft_'.$this->method.'_credentials_test');
		die;
	}

}
