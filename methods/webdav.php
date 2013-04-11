<?php

class UpdraftPlus_BackupModule_webdav {

	// backup method: takes an array, and shovels them off to the cloud storage
	function backup($backup_array) {

		global $updraftplus;

		$addon_exists = apply_filters('updraft_webdav_exists', 'no');
		if ($addon_exists !== 'yes') {
			$updraftplus->log('You do not have the UpdraftPlus WebDAV add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->error(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/'));
			return false;
		}

		// Do our uploading stuff...

		// If successful, then you must do this:
		// $updraftplus->uploaded_file($file);

		do_action('updraft_webdav_upload_files', $backup_array, $this);

	}

	// delete method: takes a file name (base name), and removes it from the cloud storage
	function delete($file, $method_obj) {

		global $updraftplus;

		$addon_exists = apply_filters('updraft_webdav_exists', 'no');
		if ($addon_exists !== 'yes') {
			$updraftplus->log('You do not have the UpdraftPlus WebDAV add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->error(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/'));
			return false;
		}

		do_action('updraft_webdav_delete_file', $file, $method_obj);

	}

	// download method: takes a file name (base name), and removes it from the cloud storage
	function download($file) {

		global $updraftplus;

		$addon_exists = apply_filters('updraft_webdav_exists', 'no');
		if ($addon_exists !== 'yes') {
			$updraftplus->log('You do not have the UpdraftPlus WebDAV add-on installed - get it from http://updraftplus.com/shop/');
			$updraftplus->error(sprintf(__('You do not have the UpdraftPlus %s add-on installed - get it from %s','updraftplus'),'WebDAV','http://updraftplus.com/shop/'));
			return false;
		}

		do_action('updraft_webdav_download_file', $file);

	}

	// config_print: prints out table rows for the configuration screen
	// Your rows need to have a class exactly matching your method (in this example, webdav), and also a class of updraftplusmethod
	// Note that logging is not available from this context; it will do nothing.
	public static function config_print() {

		$link = sprintf(__('%s support is available as an add-on','updraftplus'),'WebDAV').' - <a href="http://updraftplus.com/shop/webdav/">'.__('follow this link to get it','updraftplus');

		$default = <<<ENDHERE
		<tr class="updraftplusmethod webdav">
			<th>WebDAV:</th>
			<td>$link</a></td>
		</tr>
ENDHERE;

		echo apply_filters('updraft_webdav_config_print', $default);
	}

	public static function config_print_javascript_onready() {
		do_action('updraft_webdav_config_javascript');
	}

	public static function credentials_test() {

		do_action('updraft_webdav_credentials_test');

		die;

	}

}