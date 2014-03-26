<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed.');

class UpdraftPlus_BackupModule_webdav {

	// backup method: takes an array, and shovels them off to the cloud storage
	public function backup($backup_array) {

		global $updraftplus;

		$addon_exists = apply_filters('updraft_webdav_exists', 'no');
		if ($addon_exists !== 'yes') {
			$updraftplus->log('You do not have the UpdraftPlus WebDAV add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->log(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/'), 'error');
			return false;
		}

		// Do our uploading stuff...

		// If successful, then you must do this:
		// $updraftplus->uploaded_file($file);

		return apply_filters('updraft_webdav_upload_files', null, $backup_array);

	}

	// delete method: takes a file name (base name) (or array thereof), and removes it from the cloud storage
	public function delete($files, $method_obj = false) {

		global $updraftplus;

		$addon_exists = apply_filters('updraft_webdav_exists', 'no');
		if ($addon_exists !== 'yes') {
			$updraftplus->log('You do not have the UpdraftPlus WebDAV add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->log(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/'), 'error');
			return false;
		}

		return apply_filters('updraft_webdav_delete_files', false, $files, $method_obj);

	}

	public function listfiles($match = 'backup_') {
		return apply_filters('updraft_webdav_listfiles', new WP_Error('no_addon', sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/')), $match);
	}

	// download method: takes a file name (base name), and removes it from the cloud storage
	public function download($file) {

		global $updraftplus;

		$addon_exists = apply_filters('updraft_webdav_exists', 'no');
		if ($addon_exists !== 'yes') {
			$updraftplus->log('You do not have the UpdraftPlus WebDAV add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->log(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/'), 'error');
			return false;
		}

		return apply_filters('updraft_webdav_download_file', false, $file);

	}

	// config_print: prints out table rows for the configuration screen
	// Your rows need to have a class exactly matching your method (in this example, webdav), and also a class of updraftplusmethod
	// Note that logging is not available from this context; it will do nothing.
	public function config_print() {

		$link = sprintf(__('%s support is available as an add-on','updraftplus'),'WebDAV').' - <a href="http://updraftplus.com/shop/webdav/">'.__('follow this link to get it','updraftplus');

		$default = <<<ENDHERE
		<tr class="updraftplusmethod webdav">
			<th>WebDAV:</th>
			<td>$link</a></td>
		</tr>
ENDHERE;

		echo apply_filters('updraft_webdav_config_print', $default);
	}

	public function config_print_javascript_onready() {
		do_action('updraft_webdav_config_javascript');
	}

	public function credentials_test() {
		do_action('updraft_webdav_credentials_test');
		die;
	}

}
