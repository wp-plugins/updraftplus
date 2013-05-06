<?php

// Options handling
if (!defined ('ABSPATH')) die ('No direct access allowed');

class UpdraftPlus_Options {

	public static function user_can_manage() {
		return current_user_can('manage_options');
	}

	public static function get_updraft_option($option, $default = null) {
		return get_option($option, $default);
	}

	public static function update_updraft_option($option, $value) {
		update_option($option, $value);
	}

	public static function delete_updraft_option($option) {
		delete_option($option, $value);
	}

	public static function add_admin_pages() {
		global $updraftplus_admin;
		add_submenu_page('options-general.php', 'UpdraftPlus', __('UpdraftPlus Backups','updraftplus'), "manage_options", "updraftplus", array($updraftplus_admin, "settings_output"));
	}

	public static function options_form_begin() {
		echo '<form method="post" action="options.php">';
		settings_fields('updraft-options-group');
	}

	public static function admin_init() {

		global $updraftplus, $updraftplus_admin;
		register_setting('updraft-options-group', 'updraft_interval', array($updraftplus, 'schedule_backup') );
		register_setting('updraft-options-group', 'updraft_interval_database', array($updraftplus, 'schedule_backup_database') );
		register_setting('updraft-options-group', 'updraft_retain', array($updraftplus, 'retain_range') );
		register_setting('updraft-options-group', 'updraft_retain_db', array($updraftplus, 'retain_range') );
		register_setting('updraft-options-group', 'updraft_encryptionphrase');
		register_setting('updraft-options-group', 'updraft_service' );

		register_setting('updraft-options-group', 'updraft_s3_login' );
		register_setting('updraft-options-group', 'updraft_s3_pass' );
		register_setting('updraft-options-group', 'updraft_s3_remote_path' );

		register_setting('updraft-options-group', 'updraft_cloudfiles_authurl' );
		register_setting('updraft-options-group', 'updraft_cloudfiles_user' );
		register_setting('updraft-options-group', 'updraft_cloudfiles_apikey' );
		register_setting('updraft-options-group', 'updraft_cloudfiles_path' );

		register_setting('updraft-options-group', 'updraft_sftp_settings' );
		register_setting('updraft-options-group', 'updraft_webdav_settings' );

		register_setting('updraft-options-group', 'updraft_dropbox_appkey' );
		register_setting('updraft-options-group', 'updraft_dropbox_secret' );
		register_setting('updraft-options-group', 'updraft_dropbox_folder' );

		register_setting('updraft-options-group', 'updraft_ssl_nossl', 'absint' );
		register_setting('updraft-options-group', 'updraft_ssl_useservercerts', 'absint' );
		register_setting('updraft-options-group', 'updraft_ssl_disableverify', 'absint' );

		register_setting('updraft-options-group', 'updraft_googledrive_clientid', array($updraftplus, 'googledrive_clientid_checkchange') );
		register_setting('updraft-options-group', 'updraft_googledrive_secret' );
		register_setting('updraft-options-group', 'updraft_googledrive_remotepath', array($updraftplus_admin, 'googledrive_remove_folderurlprefix') );

		register_setting('updraft-options-group', 'updraft_ftp_login' );
		register_setting('updraft-options-group', 'updraft_ftp_pass' );
		register_setting('updraft-options-group', 'updraft_ftp_remote_path' );
		register_setting('updraft-options-group', 'updraft_server_address' );
		register_setting('updraft-options-group', 'updraft_dir', array($updraftplus_admin, 'prune_updraft_dir_prefix') );
		register_setting('updraft-options-group', 'updraft_email');
		register_setting('updraft-options-group', 'updraft_delete_local', 'absint' );
		register_setting('updraft-options-group', 'updraft_debug_mode', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_plugins', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_themes', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_uploads', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_others', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_wpcore', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_wpcore_exclude' );
		register_setting('updraft-options-group', 'updraft_include_more', 'absint' );
		register_setting('updraft-options-group', 'updraft_include_more_path' );
		register_setting('updraft-options-group', 'updraft_include_others_exclude' );

		register_setting('updraft-options-group', 'updraft_starttime_files', array('UpdraftPlus_Options', 'hourminute') );
		register_setting('updraft-options-group', 'updraft_starttime_db', array('UpdraftPlus_Options', 'hourminute') );

		register_setting('updraft-options-group', 'updraft_disable_ping', array('UpdraftPlus_Options', 'pingfilter') );

		global $pagenow;
		if (is_multisite() && $pagenow == 'options-general.php' && isset($_REQUEST['page']) && 'updraftplus' == substr($_REQUEST['page'], 0, 11)) {
			add_action('admin_notices', array('UpdraftPlus_Options', 'show_admin_warning_multisite') );
		}

	}

	public static function pingfilter($disable) {
		return apply_filters('updraftplus_pingfilter', $disable);
	}

	public static function hourminute($pot) {
		if (preg_match("/^[0-2][0-9]:[0-5][0-9]$/", $pot)) return $pot;
		if ('' == $pot) return date('H:i', time()+300);
		return '00:00';
	}

	public static function show_admin_warning_multisite() {

		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>UpdraftPlus warning:</strong> This is a WordPress multi-site (a.k.a. network) installation. <a href="http://updraftplus.com">WordPress Multisite is supported, with extra features, by UpdraftPlus Premium, or the Multisite add-on</a>. Without upgrading, UpdraftPlus allows <strong>every</strong> blog admin who can modify plugin settings to back up (and hence access the data, including passwords, from) and restore (including with customised modifications, e.g. changed passwords) <strong>the entire network</strong>. (This applies to all WordPress backup plugins unless they have been explicitly coded for multisite compatibility).', "error");

	}

}

add_action('admin_init', array('UpdraftPlus_Options', 'admin_init'));
add_action('admin_menu', array('UpdraftPlus_Options', 'add_admin_pages'));

?>
