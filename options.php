<?php

// Options handling
if (!defined ('ABSPATH')) die ('No direct access allowed');

class UpdraftPlus_Options {

	function user_can_manage() {
		return current_user_can('manage_options');
	}

	function get_updraft_option($option, $default = null) {
		return get_option($option, $default);
	}

	function update_updraft_option($option, $value) {
		update_option($option, $value);
	}

	function delete_updraft_option($option) {
		delete_option($option, $value);
	}

	function add_admin_pages() {
		global $updraftplus;
		add_submenu_page('options-general.php', "UpdraftPlus", "UpdraftPlus", "manage_options", "updraftplus", array($updraftplus, "settings_output"));
	}

	function options_form_begin() {
		echo '<form method="post" action="options.php">';
		settings_fields('updraft-options-group');
	}

	function admin_init() {

		global $updraftplus;
		register_setting('updraft-options-group', 'updraft_interval', array($updraftplus, 'schedule_backup') );
		register_setting('updraft-options-group', 'updraft_interval_database', array($updraftplus, 'schedule_backup_database') );
		register_setting('updraft-options-group', 'updraft_retain', array($updraftplus, 'retain_range') );
		register_setting('updraft-options-group', 'updraft_retain_db', array($updraftplus, 'retain_range') );
		register_setting('updraft-options-group', 'updraft_encryptionphrase');
		register_setting('updraft-options-group', 'updraft_service' );

		register_setting('updraft-options-group', 'updraft_s3_login' );
		register_setting('updraft-options-group', 'updraft_s3_pass' );
		register_setting('updraft-options-group', 'updraft_s3_remote_path' );

		register_setting('updraft-options-group', 'updraft_dropbox_appkey' );
		register_setting('updraft-options-group', 'updraft_dropbox_secret' );
		register_setting('updraft-options-group', 'updraft_dropbox_folder' );

		register_setting('updraft-options-group', 'updraft_googledrive_clientid' );
		register_setting('updraft-options-group', 'updraft_googledrive_secret' );
		register_setting('updraft-options-group', 'updraft_googledrive_remotepath' );
		register_setting('updraft-options-group', 'updraft_ftp_login' );
		register_setting('updraft-options-group', 'updraft_ftp_pass' );
		register_setting('updraft-options-group', 'updraft_ftp_remote_path' );
		register_setting('updraft-options-group', 'updraft_server_address' );
		register_setting('updraft-options-group', 'updraft_dir' );
		register_setting('updraft-options-group', 'updraft_email');
		register_setting('updraft-options-group', 'updraft_delete_local', 'absint' );
		register_setting('updraft-options-group', 'updraft_debug_mode', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_plugins', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_themes', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_uploads', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_others', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_others_exclude' );

		if (is_multisite()) {
			add_action('admin_notices', array('UpdraftPlus_Options', 'show_admin_warning_multisite') );
		}

	}

	function show_admin_warning_multisite() {

		global $updraftplus;

		$updraftplus->show_admin_warning('<strong>UpdraftPlus warning:</strong> This is a WordPress multi-site installation. UpdraftPlus does not support multi-site installations securely. <strong>Every</strong> blog admin can both back up (and hence access the data, including passwords, from) and restore (including with customised modifications, e.g. changed passwords) <strong>the entire network</strong>. Unless you are the only admin user across the entire network, you should immediately de-active UpdraftPlus. (This applies to all WordPress backup plugins unless they have been explicitly coded for multisite compatibility).', "error");

	}


}

add_action('admin_init', array('UpdraftPlus_Options', 'admin_init'));
add_action('admin_menu', array('UpdraftPlus_Options', 'add_admin_pages'));

?>