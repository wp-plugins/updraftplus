<?php

if (!defined ('ABSPATH')) die('No direct access allowed');

// Admin-area code lives here. This gets called in admin_menu, earlier than admin_init

global $updraftplus_admin;
if (!is_a($updraftplus_admin, 'UpdraftPlus_Admin')) $updraftplus_admin = new UpdraftPlus_Admin();

class UpdraftPlus_Admin {

	public $logged = array();

	public function __construct() {
		$this->admin_init();
	}

	private function admin_init() {

		add_action('core_upgrade_preamble', array($this, 'core_upgrade_preamble'));
		add_action('admin_action_upgrade-plugin', array($this, 'admin_action_upgrade_pluginortheme'));
		add_action('admin_action_upgrade-theme', array($this, 'admin_action_upgrade_pluginortheme'));

		add_action('admin_head', array($this,'admin_head'));
		add_filter((is_multisite() ? 'network_admin_' : '').'plugin_action_links', array($this, 'plugin_action_links'), 10, 2);
		add_action('wp_ajax_updraft_download_backup', array($this, 'updraft_download_backup'));
		add_action('wp_ajax_updraft_ajax', array($this, 'updraft_ajax_handler'));
		add_action('wp_ajax_plupload_action', array($this,'plupload_action'));
		add_action('wp_ajax_plupload_action2', array($this,'plupload_action2'));

		global $updraftplus, $wp_version, $pagenow;
		add_filter('updraftplus_dirlist_others', array($updraftplus, 'backup_others_dirlist'));
		add_filter('updraftplus_dirlist_uploads', array($updraftplus, 'backup_uploads_dirlist'));

		// First, the checks that are on all (admin) pages:

		$service = UpdraftPlus_Options::get_updraft_option('updraft_service');

		if (UpdraftPlus_Options::user_can_manage()) {
			if ('googledrive' === $service || (is_array($service) && in_array('googledrive', $service))) {
				$opts = UpdraftPlus_Options::get_updraft_option('updraft_googledrive');
				if (empty($opts)) {
					$clientid = UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid', '');
					$token = UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token', '');
				} else {
					$clientid = $opts['clientid'];
					$token = (empty($opts['token'])) ? '' : $opts['token'];
				}
				if (!empty($clientid) && empty($token)) add_action('all_admin_notices', array($this,'show_admin_warning_googledrive'));
			}
			if ('dropbox' === $service || (is_array($service) && in_array('dropbox', $service))) {
				$opts = UpdraftPlus_Options::get_updraft_option('updraft_dropbox');
				if (empty($opts['tk_request_token'])) {
					add_action('all_admin_notices', array($this,'show_admin_warning_dropbox') );
				}
			}
			if ('bitcasa' === $service || (is_array($service) && in_array('bitcasa', $service))) {
				$opts = UpdraftPlus_Options::get_updraft_option('updraft_bitcasa');
				if (!empty($opts['clientid']) && !empty($opts['secret']) && empty($opts['token'])) add_action('all_admin_notices', array($this,'show_admin_warning_bitcasa') );
			}
			if ('copycom' === $service || (is_array($service) && in_array('copycom', $service))) {
				$opts = UpdraftPlus_Options::get_updraft_option('updraft_copycom');
				if (!empty($opts['clientid']) && !empty($opts['secret']) && empty($opts['token'])) add_action('all_admin_notices', array($this,'show_admin_warning_copycom') );
			}
			if ($this->disk_space_check(1048576*35) === false) add_action('all_admin_notices', array($this, 'show_admin_warning_diskspace'));
		}

		// Next, the actions that only come on the UpdraftPlus page
		if ($pagenow != UpdraftPlus_Options::admin_page() || empty($_REQUEST['page']) || 'updraftplus' != $_REQUEST['page']) return;

		if (UpdraftPlus_Options::user_can_manage() && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON == true) {
			add_action('all_admin_notices', array($this, 'show_admin_warning_disabledcron'));
		}

		if (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
			@ini_set('display_errors',1);
			@error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
			add_action('all_admin_notices', array($this, 'show_admin_debug_warning'));
		}

		if (null === UpdraftPlus_Options::get_updraft_option('updraft_interval')) {
			add_action('all_admin_notices', array($this, 'show_admin_nosettings_warning'));
			$this->no_settings_warning = true;
		}

		# Avoid false positives, by attempting to raise the limit (as happens when we actually do a backup)
		@set_time_limit(900);
		$max_execution_time = (int)@ini_get('max_execution_time');
		if ($max_execution_time>0 && $max_execution_time<20) {
			add_action('all_admin_notices', array($this, 'show_admin_warning_execution_time'));
		}

		// LiteSpeed has a generic problem with terminating cron jobs
		if (isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false) {
			if (!is_file(ABSPATH.'.htaccess') || !preg_match('/noabort/i', file_get_contents(ABSPATH.'.htaccess'))) {
				add_action('all_admin_notices', array($this, 'show_admin_warning_litespeed'));
			}
		}

		if (version_compare($wp_version, '3.2', '<')) add_action('all_admin_notices', array($this, 'show_admin_warning_wordpressversion'));

		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

	}

	public function admin_enqueue_scripts() {

		wp_deregister_style('jquery-ui');
		wp_enqueue_style('jquery-ui', UPDRAFTPLUS_URL.'/includes/jquery-ui-1.8.22.custom.css'); 

		global $wp_version;
		if (version_compare($wp_version, '3.3', '<')) {
			# Require a newer jQuery (3.2.1 has 1.6.1, so we go for something not too much newer). We use .on() in a way that is incompatible with < 1.7
			wp_deregister_script('jquery');
			wp_register_script('jquery', 'https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js', false, '1.7.2', false);
			wp_enqueue_script('jquery');
			# No plupload until 3.3
			# Put in footer, to make sure that jQuery loads first
			wp_enqueue_script('updraftplus-admin-ui', UPDRAFTPLUS_URL.'/includes/updraft-admin-ui.js', array('jquery', 'jquery-ui-dialog'), '52', true);
		} else {
			wp_enqueue_script('updraftplus-admin-ui', UPDRAFTPLUS_URL.'/includes/updraft-admin-ui.js', array('jquery', 'jquery-ui-dialog', 'plupload-all'), '52');
		}

		wp_localize_script( 'updraftplus-admin-ui', 'updraftlion', array(
			'sendonlyonwarnings' => __('Send a report only when there are warnings/errors', 'updraftplus'),
			'wholebackup' => __('When the Email storage method is enabled, also send the entire backup', 'updraftplus'),
			'emailsizelimits' => esc_attr(sprintf(__('Be aware that mail servers tend to have size limits; typically around %s Mb; backups larger than any limits will likely not arrive.','updraftplus'), '10-20')),
			'rescanning' => __('Rescanning (looking for backups that you have uploaded manually into the internal backup store)...','updraftplus'),
			'rescanningremote' => __('Rescanning remote and local storage for backup sets...','updraftplus'),
			'enteremailhere' => esc_attr(__('To send to more than one address, separate each address with a comma.', 'updraftplus')),
			'excludedeverything' => __('If you exclude both the database and the files, then you have excluded everything!', 'updraftplus'),
			'restoreproceeding' => __('The restore operation has begun. Do not press stop or close your browser until it reports itself as having finished.', 'updraftplus'),
			'unexpectedresponse' => __('Unexpected response:','updraftplus'),
			'servererrorcode' => __('The web server returned an error code (try again, or check your web server logs)', 'updraftplus'),
			'newuserpass' => __("The new user's RackSpace console password is (this will not be shown again):", 'updraftplus'),
			'trying' => __('Trying...', 'updraftplus'),
			'calculating' => __('calculating...','updraftplus'),
			'begunlooking' => __('Begun looking for this entity','updraftplus'),
			'stilldownloading' => __('Some files are still downloading or being processed - please wait.', 'updraftplus'),
			'processing' => __('Processing files - please wait...', 'updraftplus'),
			'emptyresponse' => __('Error: the server sent an empty response.', 'updraftplus'),
			'warnings' => __('Warnings:','updraftplus'),
			'errors' => __('Errors:','updraftplus'),
			'jsonnotunderstood' => __('Error: the server sent us a response (JSON) which we did not understand.', 'updraftplus'),
			'errordata' => __('Error data:', 'updraftplus'),
			'error' => __('Error:','updraftplus'),
			'fileready' => __('File ready.','updraftplus'),
			'youshould' => __('You should:','updraftplus'),
			'deletefromserver' => __('Delete from your web server','updraftplus'),
			'downloadtocomputer' => __('Download to your computer','updraftplus'),
			'andthen' => __('and then, if you wish,', 'updraftplus'),
			'notunderstood' => __('Download error: the server sent us a response which we did not understand.', 'updraftplus'),
			'requeststart' => __('Requesting start of backup...', 'updraftplus'),
			'phpinfo' => __('PHP information', 'updraftplus'),
			'delete_old_dirs' => __('Delete Old Directories', 'updraftplus'),
			'raw' => __('Raw backup history', 'updraftplus'),
			'notarchive' => __('This file does not appear to be an UpdraftPlus backup archive (such files are .zip or .gz files which have a name like: backup_(time)_(site name)_(code)_(type).(zip|gz)).', 'updraftplus').' '.__('However, UpdraftPlus archives are standard zip/SQL files - so if you are sure that your file has the right format, then you can rename it to match that pattern.','updraftplus'),
			'notarchive2' => '<p>'.__('This file does not appear to be an UpdraftPlus backup archive (such files are .zip or .gz files which have a name like: backup_(time)_(site name)_(code)_(type).(zip|gz)).', 'updraftplus').'</p> '.apply_filters('updraftplus_if_foreign_then_premium_message', '<p><a href="http://updraftplus.com/shop/updraftplus-premium/">'.__('If this is a backup created by a different backup plugin, then UpdraftPlus Premium may be able to help you.', 'updraftplus').'</a></p>'),
			'makesure' => __('(make sure that you were trying to upload a zip file previously created by UpdraftPlus)','updraftplus'),
			'uploaderror' => __('Upload error:','updraftplus'),
			'notdba' => __('This file does not appear to be an UpdraftPlus encrypted database archive (such files are .gz.crypt files which have a name like: backup_(time)_(site name)_(code)_db.crypt.gz).','updraftplus'),
			'uploaderr' => __('Upload error', 'updraftplus'),
			'followlink' => __('Follow this link to attempt decryption and download the database file to your computer.','updraftplus'),
			'thiskey' => __('This decryption key will be attempted:','updraftplus'),
			'unknownresp' => __('Unknown server response:','updraftplus'),
			'ukrespstatus' => __('Unknown server response status:','updraftplus'),
			'uploaded' => __('The file was uploaded.','updraftplus'),
			'backupnow' => __('Backup Now', 'updraftplus'),
			'cancel' => __('Cancel', 'updraftplus'),
			'deletebutton' => __('Delete', 'updraftplus'),
			'createbutton' => __('Create', 'updraftplus'),
			'close' => __('Close', 'updraftplus'),
			'restore' => __('Restore', 'updraftplus'),
			'download' => __('Download log file', 'updraftplus')
		) );
	}

	public function core_upgrade_preamble() {
		if (!class_exists('UpdraftPlus_Addon_Autobackup')) {
			if (defined('UPDRAFTPLUS_NOADS_B')) return;
			# TODO: Remove legacy/wrong use of transient any time from 1 Jun 2014
			if (true == get_transient('updraftplus_dismissedautobackup')) return;
			$dismissed_until = UpdraftPlus_Options::get_updraft_option('updraftplus_dismissedautobackup', 0);
			if ($dismissed_until > time()) return;
		}
		?>
		<div id="updraft-autobackup" class="updated" style="padding: 6px; margin:8px 0px;">
			<?php if (!class_exists('UpdraftPlus_Addon_Autobackup')) { ?>
			<div style="float:right;"><a href="#" onclick="jQuery('#updraft-autobackup').slideUp(); jQuery.post(ajaxurl, {action: 'updraft_ajax', subaction: 'dismissautobackup', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce');?>' });"><?php echo sprintf(__('Dismiss (for %s weeks)', 'updraftplus'), 12); ?></a></div> <?php } ?>
			<h3 style="margin-top: 0px;"><?php _e('Be safe with an automatic backup','updraftplus');?></h3>
			<?php echo apply_filters('updraftplus_autobackup_blurb', __('UpdraftPlus Premium can  <strong>automatically</strong> take a backup of your plugins or themes and database before you update.', 'updraftplus').' <a href="http://updraftplus.com/shop/autobackup/">'.__('Be safe every time, without needing to remember - follow this link to learn more.' ,'updraftplus').'</a>'); ?>
		</div>
		<script>
		jQuery(document).ready(function() {
			jQuery('#updraft-autobackup').appendTo('.wrap p:first');
		});
		</script>
		<?php
	}

	public function admin_head() {

		global $pagenow;
		if ($pagenow != UpdraftPlus_Options::admin_page() || !isset($_REQUEST['page']) || 'updraftplus' != $_REQUEST['page']) return;

 		$chunk_size = min(wp_max_upload_size()-1024, 1024*1024*2);

		# The multiple_queues argument is ignored in plupload 2.x (WP3.9+) - http://make.wordpress.org/core/2014/04/11/plupload-2-x-in-wordpress-3-9/
		# max_file_size is also in filters as of plupload 2.x, but in its default position is still supported for backwards-compatibility. Likewise, our use of filters.extensions below is supported by a backwards-compatibility option (the current way is filters.mime-types.extensions

		$plupload_init = array(
			'runtimes' => 'html5,flash,silverlight,html4',
			'browse_button' => 'plupload-browse-button',
			'container' => 'plupload-upload-ui',
			'drop_element' => 'drag-drop-area',
			'file_data_name' => 'async-upload',
			'multiple_queues' => true,
			'max_file_size' => '100Gb',
			'chunk_size' => $chunk_size.'b',
			'url' => admin_url('admin-ajax.php'),
			'filters' => array(array('title' => __('Allowed Files'), 'extensions' => 'zip,tar,gz,bz2,crypt,sql,txt')),
			'multipart' => true,
			'multi_selection' => true,
			'urlstream_upload' => true,
			// additional post data to send to our ajax hook
			'multipart_params' => array(
				'_ajax_nonce' => wp_create_nonce('updraft-uploader'),
				'action' => 'plupload_action'
			)
		);
// 			'flash_swf_url' => includes_url('js/plupload/plupload.flash.swf'),
// 			'silverlight_xap_url' => includes_url('js/plupload/plupload.silverlight.xap'),

		# WP 3.9 updated to plupload 2.0 - https://core.trac.wordpress.org/ticket/25663
		if (is_file(ABSPATH.WPINC.'/js/plupload/Moxie.swf')) {
			$plupload_init['flash_swf_url'] = includes_url('js/plupload/Moxie.swf');
		} else {
			$plupload_init['flash_swf_url'] = includes_url('js/plupload/plupload.flash.swf');
		}

		if (is_file(ABSPATH.WPINC.'/js/plupload/Moxie.xap')) {
			$plupload_init['silverlight_xap_url'] = includes_url('js/plupload/Moxie.xap');
		} else {
			$plupload_init['silverlight_xap_url'] = includes_url('js/plupload/plupload.silverlight.swf');
		}

		?><script type="text/javascript">
			var updraft_plupload_config=<?php echo json_encode($plupload_init); ?>;
			var updraft_credentialtest_nonce='<?php echo wp_create_nonce('updraftplus-credentialtest-nonce');?>';
			var updraft_download_nonce='<?php echo wp_create_nonce('updraftplus_download');?>';
			var updraft_siteurl = '<?php echo esc_js(site_url());?>';
			var updraft_accept_archivename = <?php echo apply_filters('updraftplus_accept_archivename_js', "[]");?>;
			<?php
			$plupload_init['browse_button'] = 'plupload-browse-button2';
			$plupload_init['container'] = 'plupload-upload-ui2';
			$plupload_init['drop_element'] = 'drag-drop-area2';
			$plupload_init['multipart_params']['action'] = 'plupload_action2';
			$plupload_init['filters'] = array(array('title' => __('Allowed Files'), 'extensions' => 'crypt'));
			?>
			var updraft_plupload_config2=<?php echo json_encode($plupload_init); ?>;
			var updraft_downloader_nonce = '<?php wp_create_nonce("updraftplus_download"); ?>'
			<?php
				$overdue = $this->howmany_overdue_crons();
				if ($overdue >= 4) { ?>
			jQuery(document).ready(function(){
				setTimeout(function(){updraft_check_overduecrons();}, 11000);
				function updraft_check_overduecrons() {
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'checkoverduecrons', nonce: updraft_credentialtest_nonce }, function(data, response) {
						if ('success' == response) {
							try {
								resp = jQuery.parseJSON(data);
								if (resp.m) {
									jQuery('#updraft-insert-admin-warning').html(resp.m);
								}
							} catch(err) {
								console.log(data);
							}
						}
					});
				}
			});
			<?php } ?>
		</script>
		<style type="text/css">
			.updraft-backupentitybutton-disabled {
				background-color: transparent;
				border: none;
				color: #0074a2;
				text-decoration: underline;
				cursor: pointer;
				clear: none;
				float: left;
			}
			.updraft-backupentitybutton {
				margin-left: 8px;
			}
			.updraft-bigbutton {
				padding: 2px 0px;
				margin-right: 14px !important;
				font-size:22px !important;
				min-height: 32px;
				min-width: 180px;
			}
			.updraft_debugrow th {
				text-align: right;
				font-weight: bold;
				padding-right: 8px;
				min-width: 140px;
			}
			.updraft_debugrow td {
				min-width: 300px;
			}
			.updraftplus-morefiles-row-delete {
				cursor: pointer;
				color: red;
				font-size: 100%;
				font-weight: bold;
				border: 0px;
				border-radius: 3px;
				padding: 2px;
				margin: 0 6px;
			}
			.updraftplus-morefiles-row-delete:hover {
				cursor: pointer;
				color: white;
				background: red;
			}

		#updraft-wrap .form-table th {
			width: 230px;
		}
		.updraftplus-remove {
			background-color: #c00000;
			border: 1px solid #c00000;
			height: 22px;
			padding: 4px 3px 0;
			margin-right: 6px;
		}
		.updraft-viewlogdiv form {
			margin: 0;
			padding: 0;
		}
		.updraft-viewlogdiv {
			background-color: #ffffff;
			color: #000000;
			border: 1px solid #000000;
			height: 26px;
			padding: 0px;
			margin: 0 4px 0 0;
			border-radius: 3px;
		}
		.updraft-viewlogdiv input {
			border: none;
			background-color: transparent;
			margin:0px;
			padding: 3px 4px;
			font-size: 16px;
		}
		.updraft-viewlogdiv:hover {
			background-color: #000000;
			color: #ffffff;
			border: 1px solid #ffffff;
			cursor: pointer;
		}
		.updraft-viewlogdiv input:hover {
			color: #ffffff;
			cursor: pointer;
		}
		.updraftplus-remove a {
			color: white;
			padding: 4px 4px 0px;
		}
		.updraftplus-remove:hover {
			background-color: white;
			border: 1px solid #c00000;
		}
		.updraftplus-remove a:hover {
			color: #c00000;
		}
		.drag-drop #drag-drop-area2 {
			border: 4px dashed #ddd;
			height: 200px;
		}
		#drag-drop-area2 .drag-drop-inside {
			margin: 36px auto 0;
			width: 350px;
		}
		#filelist, #filelist2  {
			width: 100%;
		}
		#filelist .file, #filelist2 .file, #ud_downloadstatus .file, #ud_downloadstatus2 .file {
			padding: 5px;
			background: #ececec;
			border: solid 1px #ccc;
			margin: 4px 0;
		}
		#filelist .fileprogress, #filelist2 .fileprogress, #ud_downloadstatus .dlfileprogress, #ud_downloadstatus2 .dlfileprogress {
			width: 0%;
			background: #f6a828;
			height: 5px;
		}
		#ud_downloadstatus .raw, #ud_downloadstatus2 .raw {
			margin-top: 8px;
			clear:left;
		}
		#ud_downloadstatus .file, #ud_downloadstatus2 .file {
			margin-top: 8px;
		}
	
		</style>
		<?php

	}

	private function disk_space_check($space) {
		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();
		$disk_free_space = @disk_free_space($updraft_dir);
		if ($disk_free_space == false) return -1;
		return ($disk_free_space > $space) ? true : false;
	}

	# Adds the settings link under the plugin on the plugin screen.
	public function plugin_action_links($links, $file) {
		if (is_array($links) && $file == 'updraftplus/updraftplus.php'){
			$settings_link = '<a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus">'.__("Settings", "updraftplus").'</a>';
			array_unshift($links, $settings_link);
// 			$settings_link = '<a href="http://david.dw-perspective.org.uk/donate">'.__("Donate","UpdraftPlus").'</a>';
// 			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://updraftplus.com">'.__("Add-Ons / Pro Support","updraftplus").'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	public function admin_action_upgrade_pluginortheme() {

		if (isset($_GET['action']) && ($_GET['action'] == 'upgrade-plugin' || $_GET['action'] == 'upgrade-theme') && !class_exists('UpdraftPlus_Addon_Autobackup') && !defined('UPDRAFTPLUS_NOADS_B')) {

			# TODO: Remove legacy/erroneous use of transient any time after 1 Jun 2014
			$dismissed = get_transient('updraftplus_dismissedautobackup');
			if (true == $dismissed) return;
			$dismissed_until = UpdraftPlus_Options::get_updraft_option('updraftplus_dismissedautobackup', 0);
			if ($dismissed_until > time()) return;

			if ( 'upgrade-plugin' == $_GET['action'] ) {
				$title = __('Update Plugin');
				$parent_file = 'plugins.php';
				$submenu_file = 'plugins.php';
			} else {
				$title = __('Update Theme');
				$parent_file = 'themes.php';
				$submenu_file = 'themes.php';
			}

			require_once(ABSPATH.'wp-admin/admin-header.php');

			?>
			<div id="updraft-autobackup" class="updated" style="float:left; padding: 6px; margin:8px 0px;">
				<div style="float:right;"><a href="#" onclick="jQuery('#updraft-autobackup').slideUp(); jQuery.post(ajaxurl, {action: 'updraft_ajax', subaction: 'dismissautobackup', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce');?>' });"><?php echo sprintf(__('Dismiss (for %s weeks)', 'updraftplus'), 10); ?></a></div>
				<h3 style="margin-top: 0px;"><?php _e('Be safe with an automatic backup','updraftplus');?></h3>
				<p><?php echo __('UpdraftPlus Premium can  <strong>automatically</strong> take a backup of your plugins or themes and database before you update.', 'updraftplus').' <a href="http://updraftplus.com/shop/autobackup/">'.__('Be safe every time, without needing to remember - follow this link to learn more.' ,'updraftplus').'</a>'; ?></p>
			</div>
			<?php
		}
	}

	public function show_admin_warning($message, $class = "updated") {
		echo '<div class="updraftmessage '.$class.'">'."<p>$message</p></div>";
	}

	public function show_admin_nosettings_warning() {
		$this->show_admin_warning('<strong>'.__('Welcome to UpdraftPlus!', 'updraftplus').'</strong> '.__('To make a backup, just press the Backup Now button.', 'updraftplus').' <a href="#" id="updraft-navtab-settings2">'.__('To change any of the default settings of what is backed up, to configure scheduled backups, to send your backups to remote storage (recommended), and more, go to the settings tab.', 'updraftplus').'</a>');
	}

	public function show_admin_warning_execution_time() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('The amount of time allowed for WordPress plugins to run is very low (%s seconds) - you should increase it to avoid backup failures due to time-outs (consult your web hosting company for more help - it is the max_execution_time PHP setting; the recommended value is %s seconds or more)', 'updraftplus'), (int)@ini_get('max_execution_time'), 90));
	}

	public function show_admin_warning_disabledcron() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.__('The scheduler is disabled in your WordPress install, via the DISABLE_WP_CRON setting. No backups can run (even &quot;Backup Now&quot;) unless either you have set up a facility to call the scheduler manually, or until it is enabled.','updraftplus').' <a href="http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/#disablewpcron">'.__('Go here for more information.','updraftplus').'</a>', 'updated updraftplus-disable-wp-cron-warning');
	}

	public function show_admin_warning_diskspace() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('You have less than %s of free disk space on the disk which UpdraftPlus is configured to use to create backups. UpdraftPlus could well run out of space. Contact your the operator of your server (e.g. your web hosting company) to resolve this issue.','updraftplus'),'35 Mb'));
	}

	public function show_admin_warning_wordpressversion() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('UpdraftPlus does not officially support versions of WordPress before %s. It may work for you, but if it does not, then please be aware that no support is available until you upgrade WordPress.', 'updraftplus'), '3.2'));
	}

	public function show_admin_warning_litespeed() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('Your website is hosted using the %s web server.','updraftplus'),'LiteSpeed').' <a href="http://updraftplus.com/faqs/i-am-having-trouble-backing-up-and-my-web-hosting-company-uses-the-litespeed-webserver/">'.__('Please consult this FAQ if you have problems backing up.', 'updraftplus').'</a>');
	}

	public function show_admin_debug_warning() {
		$this->show_admin_warning('<strong>'.__('Notice','updraftplus').':</strong> '.__('UpdraftPlus\'s debug mode is on. You may see debugging notices on this page not just from UpdraftPlus, but from any other plugin installed. Please try to make sure that the notice you are seeing is from UpdraftPlus before you raise a support request.', 'updraftplus').'</a>');
	}

	public function show_admin_warning_overdue_crons($howmany) {
		$ret = '<div class="updraftmessage updated"><p>';
		$ret .= '<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('WordPress has a number (%d) of scheduled tasks which are overdue. Unless this is a development site, this probably means that the scheduler in your WordPress install is not working.', 'updraftplus'), $howmany).' <a href="http://updraftplus.com/faqs/scheduler-wordpress-installation-working/">'.__('Read this page for a guide to possible causes and how to fix it.', 'updraftplus').'</a>';
		$ret .= '</p></div>';
		return $ret;
	}

	public function show_admin_warning_dropbox() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&action=updraftmethod-dropbox-auth&updraftplus_dropboxauth=doit">'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Dropbox','Dropbox').'</a>');
	}

	public function show_admin_warning_bitcasa() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&action=updraftmethod-bitcasa-auth&updraftplus_bitcasaauth=doit">'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Bitcasa','Bitcasa').'</a>');
	}

	public function show_admin_warning_copycom() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&action=updraftmethod-copycom-auth&updraftplus_copycomauth=doit">'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Copy.Com','Copy').'</a>');
	}

	public function show_admin_warning_googledrive() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&action=updraftmethod-googledrive-auth&updraftplus_googleauth=doit">'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Google Drive','Google Drive').'</a>');
	}

	// This options filter removes ABSPATH off the front of updraft_dir, if it is given absolutely and contained within it
	public function prune_updraft_dir_prefix($updraft_dir) {
		if ('/' == substr($updraft_dir, 0, 1) || "\\" == substr($updraft_dir, 0, 1) || preg_match('/^[a-zA-Z]:/', $updraft_dir)) {
			$wcd = trailingslashit(WP_CONTENT_DIR);
			if (strpos($updraft_dir, $wcd) === 0) {
				$updraft_dir = substr($updraft_dir, strlen($wcd));
			}
			# Legacy
// 			if (strpos($updraft_dir, ABSPATH) === 0) {
// 				$updraft_dir = substr($updraft_dir, strlen(ABSPATH));
// 			}
		}
		return $updraft_dir;
	}

	public function updraft_download_backup() {

		@set_time_limit(900);

		global $updraftplus;
		if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'updraftplus_download')) die;
		if (!isset($_REQUEST['timestamp']) || !is_numeric($_REQUEST['timestamp']) ||  !isset($_REQUEST['type'])) exit;

		$findex = (isset($_REQUEST['findex'])) ? $_REQUEST['findex'] : 0;
		if (empty($findex)) $findex=0;

		$backupable_entities = $updraftplus->get_backupable_file_entities(true);
		$type_match = false;
		foreach ($backupable_entities as $type => $info) {
			if ($_REQUEST['type'] == $type) $type_match = true;
		}

		if (!$type_match && 'db' != substr($_REQUEST['type'], 0, 2)) exit;

		// Get the information on what is wanted
		$type = $_REQUEST['type'];
		$timestamp = $_REQUEST['timestamp'];

		// You need a nonce before you can set job data. And we certainly don't yet have one.
		$updraftplus->backup_time_nonce($timestamp);

		$debug_mode = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');

		// Set the job type before logging, as there can be different logging destinations
		$updraftplus->jobdata_set('job_type', 'download');
		$updraftplus->jobdata_set('job_time_ms', $updraftplus->job_time_ms);

		// Retrieve the information from our backup history
		$backup_history = $updraftplus->get_backup_history();
		// Base name
		$file = $backup_history[$timestamp][$type];

		// Deal with multi-archive sets
		if (is_array($file)) $file=$file[$findex];

		// Where it should end up being downloaded to
		$fullpath = $updraftplus->backups_dir_location().'/'.$file;

		if (isset($_GET['stage']) && '2' == $_GET['stage']) {
			$updraftplus->spool_file($type, $fullpath);
			die;
		}

		if (isset($_POST['stage']) && 'delete' == $_POST['stage']) {
			@unlink($fullpath);
			echo 'deleted';
			$updraftplus->log('The file has been deleted');
			die;
		}

		// TODO: FIXME: Failed downloads may leave log files forever (though they are small)
		// Note that log() assumes that the data is in _POST, not _GET
		if ($debug_mode) $updraftplus->logfile_open($updraftplus->nonce);

		set_error_handler(array($updraftplus, 'php_error'), E_ALL & ~E_STRICT);

		$updraftplus->log("Requested to obtain file: timestamp=$timestamp, type=$type, index=$findex");

		$itext = (empty($findex)) ? '' : $findex;
		$known_size = isset($backup_history[$timestamp][$type.$itext.'-size']) ? $backup_history[$timestamp][$type.$itext.'-size'] : 0;

		$services = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;
		if (is_string($services)) $services = array($services);

		$updraftplus->jobdata_set('service', $services);

		// Fetch it from the cloud, if we have not already got it

		$needs_downloading = false;

		if(!file_exists($fullpath)) {
			//if the file doesn't exist and they're using one of the cloud options, fetch it down from the cloud.
			$needs_downloading = true;
			$updraftplus->log('File does not yet exist locally - needs downloading');
		} elseif ($known_size>0 && filesize($fullpath) < $known_size) {
			$updraftplus->log("The file was found locally (".filesize($fullpath).") but did not match the size in the backup history ($known_size) - will resume downloading");
			$needs_downloading = true;
		} elseif ($known_size>0) {
			$updraftplus->log('The file was found locally and matched the recorded size from the backup history ('.round($known_size/1024,1).' Kb)');
		} else {
			$updraftplus->log('No file size was found recorded in the backup history. We will assume the local one is complete.');
			$known_size = filesize($fullpath);
		}

		// The AJAX responder that updates on progress wants to see this
		$updraftplus->jobdata_set('dlfile_'.$timestamp.'_'.$type.'_'.$findex, "downloading:$known_size:$fullpath");

		if ($needs_downloading) {
			$this->close_browser_connection();
			$is_downloaded = false;
			add_action('http_request_args', array($updraftplus, 'modify_http_options'));
			foreach ($services as $service) {
				if ($is_downloaded) continue;
				$download = $this->download_file($file, $service);
				if (is_readable($fullpath) && $download !== false) {
					clearstatcache();
					$updraftplus->log('Remote fetch was successful (file size: '.round(filesize($fullpath)/1024,1).' Kb)');
					$is_downloaded = true;
				} else {
					clearstatcache();
					if (0 === @filesize($fullpath)) @unlink($fullpath);
					$updraftplus->log('Remote fetch failed');
				}
			}
			remove_action('http_request_args', array($updraftplus, 'modify_http_options'));
		}

		// Now, spool the thing to the browser
		if(is_file($fullpath) && is_readable($fullpath)) {

			// That message is then picked up by the AJAX listener
			$updraftplus->jobdata_set('dlfile_'.$timestamp.'_'.$type.'_'.$findex, 'downloaded:'.filesize($fullpath).":$fullpath");

		} else {
			$updraftplus->jobdata_set('dlfile_'.$timestamp.'_'.$type.'_'.$findex, 'failed');
			$updraftplus->jobdata_set('dlerrors_'.$timestamp.'_'.$type.'_'.$findex, $updraftplus->errors);
			$updraftplus->log('Remote fetch failed. File '.$fullpath.' did not exist or was unreadable. If you delete local backups then remote retrieval may have failed.');
		}

		restore_error_handler();

		@fclose($updraftplus->logfile_handle);
		if (!$debug_mode) @unlink($updraftplus->logfile_name);

		exit;

	}

	private function close_browser_connection($txt = '') {
		// Close browser connection so that it can resume AJAX polling
		header('Content-Length: '.((!empty($txt)) ? 4+strlen($txt) : '0'));
		header('Connection: close');
		header('Content-Encoding: none');
		if (session_id()) session_write_close();
		echo "\r\n\r\n";
		echo $txt;
	}

	# Pass only a single service, as a string, into this function
	private function download_file($file, $service) {

		global $updraftplus;

		@set_time_limit(900);

		$updraftplus->log("Requested file from remote service: $service: $file");

		$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
		if (file_exists($method_include)) require_once($method_include);

		$objname = "UpdraftPlus_BackupModule_${service}";
		if (method_exists($objname, "download")) {
			$remote_obj = new $objname;
			return $remote_obj->download($file);
		} else {
			$updraftplus->log("Automatic backup restoration is not available with the method: $service.");
			$updraftplus->log("$file: ".sprintf(__("The backup archive for this file could not be found. The remote storage method in use (%s) does not allow us to retrieve files. To perform any restoration using UpdraftPlus, you will need to obtain a copy of this file and place it inside UpdraftPlus's working folder", 'updraftplus'), $service)." (".$this->prune_updraft_dir_prefix($updraftplus->backups_dir_location()).")", 'error');
			return false;
		}

	}

	public function updraft_ajax_handler() {

		global $updraftplus;

		$nonce = (empty($_REQUEST['nonce'])) ? "" : $_REQUEST['nonce'];
		if (!wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce') || empty($_REQUEST['subaction'])) die('Security check');
		if (isset($_REQUEST['subaction']) && 'lastlog' == $_REQUEST['subaction']) {
			echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '('.__('Nothing yet logged', 'updraftplus').')'));
		} elseif (isset($_GET['subaction']) && 'activejobs_list' == $_GET['subaction']) {
			$download_status = array();
			if (!empty($_GET['downloaders'])) {
				foreach(explode(':', $_GET['downloaders']) as $downloader) {
					# prefix, timestamp, entity, index
					if (preg_match('/^([^,]+),(\d+),([-a-z]+|db[0-9]+),(\d+)$/', $downloader, $matches)) {
						$updraftplus->nonce = $matches[2];
						$status = $this->download_status($matches[2], $matches[3], $matches[4]);
						if (is_array($status)) {
							$status['base'] = $matches[1];
							$status['timestamp'] = $matches[2];
							$status['what'] = $matches[3];
							$status['findex'] = (empty($matches[4])) ? '0' : $matches[4];
							$download_status[] = $status;
						}
					}
				}
			}

			if (!empty($_GET['oneshot'])) {
				$job_id = get_site_option('updraft_oneshotnonce', false);
				$active_jobs = (false === $job_id) ? '' : $this->print_active_job($job_id, true);
			} else {
				$active_jobs = $this->print_active_jobs();
			}

			$logupdate_array = array();
			if (!empty($_REQUEST['log_fetch'])){
				if(isset($_REQUEST['log_nonce'])){
					$log_nonce = $_REQUEST['log_nonce'];
					$log_pointer = isset($_REQUEST['log_pointer']) ? absint($_REQUEST['log_pointer']) : 0;
					$logupdate_array = $this->fetch_log($log_nonce, $log_pointer);
				}
			}

			echo json_encode(array(
				'l' => htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '('.__('Nothing yet logged', 'updraftplus').')')),
				'j' => $active_jobs,
				'ds' => $download_status,
				'u' => $logupdate_array
			));
		} elseif (isset($_REQUEST['subaction']) && 'callwpaction' == $_REQUEST['subaction'] && !empty($_REQUEST['wpaction'])) {
			ob_start();

			$res = '<em>Request received: </em>';

			if (preg_match('/^([^:]+)+:(.*)$/', stripslashes($_REQUEST['wpaction']), $matches)) {
				$action = $matches[1];
				if (null === ($args = json_decode($matches[2], true))) {
					$res .= "The parameters (should be JSON) could not be decoded";
					$action = false;
				} else {
					$res .= "Will despatch action: ".htmlspecialchars($action).", parameters: ".htmlspecialchars(implode(',', $args));
				}
			} else {
				$action = $_REQUEST['wpaction'];
				$res .= "Will despatch action: ".htmlspecialchars($action).", no parameters";
			}

			echo json_encode(array('r' => $res));
			$ret = ob_get_clean();
			ob_end_clean();
			$this->close_browser_connection($ret);
			if (!empty($action)) {
				if (!empty($args)) {
					do_action_ref_array($action, $args);
				} else {
					do_action($action);
				}
			}
			die;
		} elseif (isset($_REQUEST['subaction']) && 'httpget' == $_REQUEST['subaction']) {
			if (empty($_REQUEST['uri'])) {
				echo json_encode(array('r' => ''));
				die;
			}
			$uri = $_REQUEST['uri'];
			if (!empty($_REQUEST['curl'])) {
				if (!function_exists('curl_exec')) {
					echo json_encode(array('e' => 'No Curl installed'));
					die;
				}
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $uri);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_FAILONERROR, true);
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_setopt($ch, CURLOPT_VERBOSE, true);
				curl_setopt($ch, CURLOPT_STDERR, $output=fopen('php://temp', "w+"));
				$response = curl_exec($ch);
				$error = curl_error($ch);
				$getinfo = curl_getinfo($ch);
				curl_close($ch);
				$resp = array();
				if (false === $response) {
					$resp['e'] = htmlspecialchars($error);
					# json_encode(array('e' => htmlspecialchars($error)));
				}
				$resp['r'] = (empty($response)) ? '' : htmlspecialchars(substr($response, 0, 2048));
				rewind($output);
				$verb = stream_get_contents($output);
				if (!empty($verb)) $resp['r'] = htmlspecialchars($verb)."\n\n".$resp['r'];
				echo json_encode($resp);
// 				echo json_encode(array('r' => htmlspecialchars(substr($response, 0, 2048))));
			} else {
				$response = wp_remote_get($uri, array('timeout' => 10));
				if (is_wp_error($response)) {
					echo json_encode(array('e' => htmlspecialchars($response->get_error_message())));
					die;
				}
				echo json_encode(array('r' => $response['response']['code'].': '.htmlspecialchars(substr($response['body'], 0, 2048))));
			}
			die;
		} elseif (isset($_REQUEST['subaction']) && 'dismissautobackup' == $_REQUEST['subaction']) {
			UpdraftPlus_Options::update_updraft_option('updraftplus_dismissedautobackup', time() + 84*86400);
		} elseif (isset($_REQUEST['subaction']) && 'dismissexpiry' == $_REQUEST['subaction']) {
			UpdraftPlus_Options::update_updraft_option('updraftplus_dismissedexpiry', time() + 14*86400);
		} elseif (isset($_REQUEST['subaction']) && 'poplog' == $_REQUEST['subaction']){ 

			echo json_encode($this->fetch_log($_REQUEST['backup_nonce']));

		} elseif (isset($_GET['subaction']) && 'restore_alldownloaded' == $_GET['subaction'] && isset($_GET['restoreopts']) && isset($_GET['timestamp'])) {

			$backups = $updraftplus->get_backup_history();
			$updraft_dir = $updraftplus->backups_dir_location();

			$timestamp = (int)$_GET['timestamp'];
			if (!isset($backups[$timestamp])) {
				echo json_encode(array('m' => '', 'w' => '', 'e' => __('No such backup set exists', 'updraftplus')));
				die;
			}

			$mess = array();
			parse_str($_GET['restoreopts'], $res);

			if (isset($res['updraft_restore'])) {

				set_error_handler(array($this, 'get_php_errors'), E_ALL & ~E_STRICT);

				$elements = array_flip($res['updraft_restore']);

				$warn = array(); $err = array();

				@set_time_limit(900);
				$max_execution_time = (int)@ini_get('max_execution_time');

				if ($max_execution_time>0 && $max_execution_time<61) {
					$warn[] = sprintf(__('The PHP setup on this webserver allows only %s seconds for PHP to run, and does not allow this limit to be raised. If you have a lot of data to import, and if the restore operation times out, then you will need to ask your web hosting company for ways to raise this limit (or attempt the restoration piece-by-piece).', 'updraftplus'), $max_execution_time);
				}

				if (isset($backups[$timestamp]['native']) && false == $backups[$timestamp]['native']) {
					$warn[] = __('This backup set was not known by UpdraftPlus to be created by the current WordPress installation, but was found in remote storage.', 'updraftplus').' '.__('You should make sure that this really is a backup set intended for use on this website, before you restore (rather than a backup set of an unrelated website that was using the same storage location).', 'updraftplus');
				}

				if (isset($elements['db'])) {
					// Analyse the header of the database file + display results
					list ($mess2, $warn2, $err2, $info) = $this->analyse_db_file($timestamp, $res);
					$mess = array_merge($mess, $mess2);
					$warn = array_merge($warn, $warn2);
					$err = array_merge($err, $err2);
					foreach ($backups[$timestamp] as $bid => $bval) {
						if ('db' != $bid && 'db' == substr($bid, 0, 2) && '-size' != substr($bid, -5, 5)) {
							$warn[] = __('Only the WordPress database can be restored; you will need to deal with the external database manually.', 'updraftplus');
							break;
						}
					}
				}

				$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);
				$backupable_plus_db = $backupable_entities;
				$backupable_plus_db['db'] = array('path' => 'path-unused', 'description' => __('Database', 'updraftplus'));

				if (!empty($backups[$timestamp]['meta_foreign'])) {
					$foreign_known = apply_filters('updraftplus_accept_archivename', array());
					if (!is_array($foreign_known) || empty($foreign_known[$backups[$timestamp]['meta_foreign']])) {
						$err[] = sprintf(__('Backup created by unknown source (%s) - cannot be restored.', 'updraftplus'), $backups[$timestamp]['meta_foreign']);
					} else {
						# For some reason, on PHP 5.5 passing by reference in a single array stopped working with apply_filters_ref_array (though not with do_action_ref_array).
						$backupable_plus_db = apply_filters_ref_array("updraftplus_importforeign_backupable_plus_db", array($backupable_plus_db, array($foreign_known[$backups[$timestamp]['meta_foreign']], &$mess, &$warn, &$err)));
					}
				}

				foreach ($backupable_plus_db as $type => $info) {
					if (!isset($elements[$type])) continue;
					$whatwegot = $backups[$timestamp][$type];
					if (is_string($whatwegot)) $whatwegot = array($whatwegot);
					$expected_index = 0;
					$missing = '';
					ksort($whatwegot);
					$outof = false;
					foreach ($whatwegot as $index => $file) {
						if (preg_match('/\d+of(\d+)\.zip/', $file, $omatch)) { $outof = max($matches[1], 1); }
						if ($index != $expected_index) {
							$missing .= ($missing == '') ? (1+$expected_index) : ",".(1+$expected_index);
						}
						if (!file_exists($updraft_dir.'/'.$file)) {
							$err[] = sprintf(__('File not found (you need to upload it): %s', 'updraftplus'), $updraft_dir.'/'.$file);
						} elseif (filesize($updraft_dir.'/'.$file) == 0) {
							$err[] = sprintf(__('File was found, but is zero-sized (you need to re-upload it): %s', 'updraftplus'), $file);
						} else {
							$itext = (0 == $index) ? '' : $index;
							if (!empty($backups[$timestamp][$type.$itext.'-size']) && $backups[$timestamp][$type.$itext.'-size'] != filesize($updraft_dir.'/'.$file)) {
								if (empty($warn['doublecompressfixed'])) {
									$warn[] = sprintf(__('File (%s) was found, but has a different size (%s) from what was expected (%s) - it may be corrupt.', 'updraftplus'), $file, filesize($updraft_dir.'/'.$file), $backups[$timestamp][$type.$itext.'-size']);
								}
							}
							do_action_ref_array("updraftplus_checkzip_$type", array($updraft_dir.'/'.$file, &$mess, &$warn, &$err));
						}
						$expected_index++;
					}
					do_action_ref_array("updraftplus_checkzip_end_$type", array(&$mess, &$warn, &$err));
					# Detect missing archives where they are missing from the end of the set
					if ($outof>0 && $expected_index < $outof) {
						for ($j = $expected_index; $j<$outof; $j++) {
							$missing .= ($missing == '') ? (1+$j) : ",".(1+$j);
						}
					}
					if ('' != $missing) {
						$warn[] = sprintf(__("This multi-archive backup set appears to have the following archives missing: %s", 'updraftplus'), $missing.' ('.$info['description'].')');
					}
				}

				if (0 == count($err) && 0 == count($warn)) {
					$mess_first = __('The backup archive files have been successfully processed. Now press Restore again to proceed.', 'updraftplus');
				} elseif (0 == count($err)) {
					$mess_first = __('The backup archive files have been processed, but with some warnings. If all is well, then now press Restore again to proceed. Otherwise, cancel and correct any problems first.', 'updraftplus');
				} else {
					$mess_first = __('The backup archive files have been processed, but with some errors. You will need to cancel and correct any problems before retrying.', 'updraftplus');
				}

				if (count($this->logged) >0) {
					foreach ($this->logged as $lwarn) $warn[] = $lwarn;
				}
				restore_error_handler();

				echo json_encode(array('m' => '<p>'.$mess_first.'</p>'.implode('<br>', $mess), 'w' => implode('<br>', $warn), 'e' => implode('<br>', $err)));
			}

		} elseif (isset($_POST['backup_timestamp']) && 'deleteset' == $_REQUEST['subaction']) {
			$backups = $updraftplus->get_backup_history();
			$timestamp = $_POST['backup_timestamp'];
			if (!isset($backups[$timestamp])) {
				echo json_encode(array('result' => 'error', 'message' => __('Backup set not found', 'updraftplus')));
				die;
			}

			// You need a nonce before you can set job data. And we certainly don't yet have one.
			$updraftplus->backup_time_nonce();
			// Set the job type before logging, as there can be different logging destinations
			$updraftplus->jobdata_set('job_type', 'delete');
			$updraftplus->jobdata_set('job_time_ms', $updraftplus->job_time_ms);

			if (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
				$updraftplus->logfile_open($updraftplus->nonce);
				set_error_handler(array($updraftplus, 'php_error'), E_ALL & ~E_STRICT);
			}

			$updraft_dir = $updraftplus->backups_dir_location();
			$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

			$nonce = isset($backups[$timestamp]['nonce']) ? $backups[$timestamp]['nonce'] : '';

			$delete_from_service = array();

			if (isset($_POST['delete_remote']) && 1==$_POST['delete_remote']) {
				// Locate backup set
				if (isset($backups[$timestamp]['service'])) {
					$services = is_string($backups[$timestamp]['service']) ? array($backups[$timestamp]['service']) : $backups[$timestamp]['service'];
					if (is_array($services)) {
						foreach ($services as $service) {
							if ($service != 'none') $delete_from_service[] = $service;
						}
					}
				}
			}

			$files_to_delete = array();
			foreach ($backupable_entities as $key => $ent) {
				if (isset($backups[$timestamp][$key])) {
					$files_to_delete[$key] = $backups[$timestamp][$key];
				}
			}
			// Delete DB
			if (isset($backups[$timestamp]['db'])) $files_to_delete['db'] = $backups[$timestamp]['db'];

			// Also delete the log
			if ($nonce && !UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
				$files_to_delete['log'] = "log.$nonce.txt";
			}

			unset($backups[$timestamp]);
			UpdraftPlus_Options::update_updraft_option('updraft_backup_history', $backups);

			$message = '';

			$local_deleted = 0;
			$remote_deleted = 0;
			add_action('http_request_args', array($updraftplus, 'modify_http_options'));
			foreach ($files_to_delete as $key => $files) {
				# Local deletion
				if (is_string($files)) $files=array($files);
				foreach ($files as $file) {
					if (is_file($updraft_dir.'/'.$file)) {
						if (@unlink($updraft_dir.'/'.$file)) $local_deleted++;
					}
				}
				if ('log' != $key && count($delete_from_service) > 0) {
					foreach ($delete_from_service as $service) {
						if ('email' == $service) continue;
						if (file_exists(UPDRAFTPLUS_DIR."/methods/$service.php")) require_once(UPDRAFTPLUS_DIR."/methods/$service.php");
						$objname = "UpdraftPlus_BackupModule_".$service;
						$deleted = -1;
						if (class_exists($objname)) {
							# TODO: Re-use the object (i.e. prevent repeated connection setup/teardown)
							$remote_obj = new $objname;
							$deleted = $remote_obj->delete($files);
						}
						if ($deleted === -1) {
							//echo __('Did not know how to delete from this cloud service.', 'updraftplus');
						} elseif ($deleted !== false) {
							$remote_deleted = $remote_deleted + count($files);
						} else {
							// Do nothing
						}
					}
				}
			}
			remove_action('http_request_args', array($updraftplus, 'modify_http_options'));
			$message .= __('The backup set has been removed.', 'updraftplus')."\n";
			$message .= sprintf(__('Local archives deleted: %d', 'updraftplus'),$local_deleted)."\n";
			$message .= sprintf(__('Remote archives deleted: %d', 'updraftplus'),$remote_deleted)."\n";

			$updraftplus->log("Local archives deleted: ".$local_deleted);
			$updraftplus->log("Remote archives deleted: ".$remote_deleted);

			print json_encode(array('result' => 'success', 'message' => $message));

			if (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
				restore_error_handler();
			}


		} elseif ('rawbackuphistory' == $_REQUEST['subaction']) {

			echo '<h3 id="ud-debuginfo-rawbackups">'.__('Known backups (raw)', 'updraftplus').'</h3><pre>';
			var_dump($updraftplus->get_backup_history());
			echo '</pre>';

			echo '<h3 id="ud-debuginfo-files">Files</h3><pre>';
			$updraft_dir = $updraftplus->backups_dir_location();
			$raw_output = array();
			$d = dir($updraft_dir);
			while (false !== ($entry = $d->read())) {
				$fp = $updraft_dir.'/'.$entry;
				$mtime = filemtime($fp);
				if (is_dir($fp)) {
					$size = '       d';
				} elseif (is_link($fp)) {
					$size = '       l';
				} elseif (is_file($fp)) {
					$size = sprintf("%8.1f", round(filesize($fp)/1024, 1)).' '.gmdate('r', $mtime);
				} else {
					$size = '       ?';
				}
				if (preg_match('/^log\.(.*)\.txt$/', $entry, $lmatch)) $entry = '<a target="_top" href="?action=downloadlog&page=updraftplus&updraftplus_backup_nonce='.htmlspecialchars($lmatch[1]).'">'.$entry.'</a>';
				$raw_output[$mtime] = empty($raw_output[$mtime]) ? sprintf("%s %s\n", $size, $entry) : $raw_output[$mtime].sprintf("%s %s\n", $size, $entry);
			}
			@$d->close();
			krsort($raw_output, SORT_NUMERIC);
			foreach ($raw_output as $line) echo $line;
			echo '</pre>';

			echo '<h3 id="ud-debuginfo-options">'.__('Options (raw)', 'updraftplus').'</h3>';
			$opts = $this->get_settings_keys();
			asort($opts);
			// <tr><th>'.__('Key','updraftplus').'</th><th>'.__('Value','updraftplus').'</th></tr>
			echo '<table><thead></thead><tbody>';
			foreach ($opts as $opt) {
				echo '<tr><td>'.htmlspecialchars($opt).'</td><td>'.htmlspecialchars(print_r(UpdraftPlus_Options::get_updraft_option($opt), true)).'</td>';
			}
			echo '</tbody></table>';

			do_action('updraftplus_showrawinfo');

		} elseif ('countbackups' == $_REQUEST['subaction']) {
			$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			$backup_history = (is_array($backup_history))?$backup_history:array();
			#echo sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history));
			echo __('Existing Backups', 'updraftplus').' ('.count($backup_history).')';
		} elseif ('ping' == $_REQUEST['subaction']) {
			// The purpose of this is to detect brokenness caused by extra line feeds in plugins/themes - before it breaks other AJAX operations and leads to support requests
			echo 'pong';
		} elseif ('checkoverduecrons' == $_REQUEST['subaction']) {
			$how_many_overdue = $this->howmany_overdue_crons();
			if ($how_many_overdue >= 4) echo json_encode(array('m' => $this->show_admin_warning_overdue_crons($how_many_overdue)));
		} elseif ('delete_old_dirs' == $_REQUEST['subaction']) {
			$this->delete_old_dirs_go(false);
		} elseif ('phpinfo' == $_REQUEST['subaction']) {
			phpinfo(INFO_ALL ^ (INFO_CREDITS | INFO_LICENSE));

			echo '<h3 id="ud-debuginfo-constants">'.__('Constants', 'updraftplus').'</h3>';
			$opts = @get_defined_constants();
			ksort($opts);
			// <tr><th>'.__('Key','updraftplus').'</th><th>'.__('Value','updraftplus').'</th></tr>
			echo '<table><thead></thead><tbody>';
			foreach ($opts as $key => $opt) {
				echo '<tr><td>'.htmlspecialchars($key).'</td><td>'.htmlspecialchars(print_r($opt, true)).'</td>';
			}
			echo '</tbody></table>';

		} elseif ('doaction' == $_REQUEST['subaction'] && !empty($_REQUEST['subsubaction']) && 'updraft_' == substr($_REQUEST['subsubaction'], 0, 8)) {
			do_action($_REQUEST['subsubaction']);
		} elseif ('backupnow' == $_REQUEST['subaction']) {

			$backupnow_nocloud = (empty($_REQUEST['backupnow_nocloud'])) ? false : true;
			$event = (!empty($_REQUEST['backupnow_nofiles'])) ? 'updraft_backupnow_backup_database' : ((!empty($_REQUEST['backupnow_nodb'])) ? 'updraft_backupnow_backup' : 'updraft_backupnow_backup_all');

			$msg = '<strong>'.__('Start backup','updraftplus').':</strong> '.htmlspecialchars(__('OK. You should soon see activity in the "Last log message" field below.','updraftplus'));
			$this->close_browser_connection($msg);

			do_action($event, apply_filters('updraft_backupnow_options', array('nocloud' => $backupnow_nocloud)));

			# Old-style: schedule an event in 5 seconds time. This has the advantage of testing out the scheduler, and alerting the user if it doesn't work... but has the disadvantage of not working in that case.
			# I don't think the </div>s should be here - in case this is ever re-activated
// 			if (wp_schedule_single_event(time()+5, $event, array($backupnow_nocloud)) === false) {
// 				$updraftplus->log("A backup run failed to schedule");
// 				echo __("Failed.", 'updraftplus')."</div>";
// 			} else {
// 				echo htmlspecialchars(__('OK. You should soon see activity in the "Last log message" field below.','updraftplus'))." <a href=\"http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/\"><br>".__('Nothing happening? Follow this link for help.','updraftplus')."</a></div>";
// 				$updraftplus->log("A backup run has been scheduled");
// 			}

		} elseif (isset($_GET['subaction']) && 'lastbackup' == $_GET['subaction']) {
			echo $this->last_backup_html();
		} elseif (isset($_GET['subaction']) && 'activejobs_delete' == $_GET['subaction'] && isset($_GET['jobid'])) {

			$cron = get_option('cron');
			$found_it = 0;
			foreach ($cron as $time => $job) {
				if (isset($job['updraft_backup_resume'])) {
					foreach ($job['updraft_backup_resume'] as $hook => $info) {
						if (isset($info['args'][1]) && $info['args'][1] == $_GET['jobid']) {
							$args = $cron[$time]['updraft_backup_resume'][$hook]['args'];
							wp_unschedule_event($time, 'updraft_backup_resume', $args);
							if (!$found_it) echo json_encode(array('ok' => 'Y', 'm' => __('Job deleted', 'updraftplus')));
							$found_it = 1;
						}
					}
				}
			}

			if (!$found_it) echo json_encode(array('ok' => 'N', 'm' => __('Could not find that job - perhaps it has already finished?', 'updraftplus')));

		} elseif (isset($_GET['subaction']) && 'diskspaceused' == $_GET['subaction'] && isset($_GET['entity'])) {
			if ('updraft' == $_GET['entity']) {
				echo $this->recursive_directory_size($updraftplus->backups_dir_location());
			} else {
				$backupable_entities = $updraftplus->get_backupable_file_entities(true, false);
				if (!empty($backupable_entities[$_GET['entity']])) {
					# Might be an array
					$basedir = $backupable_entities[$_GET['entity']];
					$dirs = apply_filters('updraftplus_dirlist_'.$_GET['entity'], $basedir);
					echo $this->recursive_directory_size($dirs, $updraftplus->get_exclude($_GET['entity']), $basedir);
				} else {
					_e('Error', 'updraftplus');
				}
			}
		} elseif (isset($_GET['subaction']) && 'historystatus' == $_GET['subaction']) {
			$remotescan = (isset($_GET['remotescan']) && $_GET['remotescan'] == 1);
			$rescan = ($remotescan || (isset($_GET['rescan']) && $_GET['rescan'] == 1));
			if ($rescan) $messages = $this->rebuild_backup_history($remotescan);

			$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			$backup_history = (is_array($backup_history)) ? $backup_history : array();
			$output = $this->existing_backup_table($backup_history);

			if (!empty($messages) && is_array($messages)) {
				$noutput = '<div style="margin-left: 100px; margin-top: 10px;"><ul style="list-style: disc inside;">';
				foreach ($messages as $msg) {
					$noutput .= '<li>'.(($msg['desc']) ? $msg['desc'].': ' : '').'<em>'.$msg['message'].'</em></li>';
				}
				$noutput .= '</ul></div>';
				$output = $noutput.$output;
			}

// 			echo @json_encode(array('n' => sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history)), 't' => $output));
			echo @json_encode(array('n' => sprintf(__('Existing Backups', 'updraftplus').' (%d)', count($backup_history)), 't' => $output));
		} elseif (isset($_GET['subaction']) && 'downloadstatus' == $_GET['subaction'] && isset($_GET['timestamp']) && isset($_GET['type'])) {

			$findex = (isset($_GET['findex'])) ? $_GET['findex'] : '0';
			if (empty($findex)) $findex = '0';
			$updraftplus->nonce = $_GET['timestamp'];

			echo json_encode($this->download_status($_GET['timestamp'], $_GET['type'], $findex));

		} elseif (isset($_POST['subaction']) && $_POST['subaction'] == 'credentials_test') {
			$method = (preg_match("/^[a-z0-9]+$/", $_POST['method'])) ? $_POST['method'] : "";

			require_once(UPDRAFTPLUS_DIR."/methods/$method.php");
			$objname = "UpdraftPlus_BackupModule_$method";

			$this->logged = array();
			# TODO: Add action for WP HTTP SSL stuff
			set_error_handler(array($this, 'get_php_errors'), E_ALL & ~E_STRICT);
			if (method_exists($objname, "credentials_test")) {
				$obj = new $objname;
				$obj->credentials_test();
			}
			if (count($this->logged) >0) {
				echo "\n\n".__('Messages:', 'updraftplus')."\n";
				foreach ($this->logged as $err) {
					echo "* $err\n";
				}
			}
			restore_error_handler();
		}
		die;

	}

	public function fetch_log($backup_nonce, $log_pointer=0) {
		global $updraftplus;
 
		if (empty($backup_nonce)) {
			list($mod_time, $log_file, $nonce) = $updraftplus->last_modified_log();
		} else {
			$nonce = $backup_nonce;
		}

		if (!preg_match('/^[0-9a-f]+$/', $nonce)) die('Security check');
		
		$log_content = '';
		$new_pointer = $log_pointer;
		
		if (!empty($nonce)) {
			$updraft_dir = $updraftplus->backups_dir_location();
			
			$potential_log_file = $updraft_dir."/log.".$nonce.".txt";

			if (is_readable($potential_log_file)){
				
				$templog_array = array();
				$log_file = fopen($potential_log_file, "r");
				if ($log_pointer > 0) fseek($log_file, $log_pointer);
				
				while (($buffer = fgets($log_file, 4096)) !== false) {
					$templog_array[] = $buffer;
				}
				if (!feof($log_file)) {
					$templog_array[] = __('Error: unexpected file read fail', 'updraftplus');
				}
				
				$new_pointer = ftell($log_file);
				$log_content = implode("", $templog_array);
				
			} else {
				$log_content .= __('The log file could not be read.','updraftplus');
			}

		} else {
			$log_content .= __('The log file could not be read.','updraftplus');
		}
		
		$ret_array = array(
			'html' => $log_content,
			'nonce' => $nonce,
			'pointer' => $new_pointer
		);
		
		return $ret_array;
	}

	public function howmany_overdue_crons() {
		$how_many_overdue = 0;
		if (function_exists('_get_cron_array') || (is_file(ABSPATH.WPINC.'/cron.php') && include_once(ABSPATH.WPINC.'/cron.php') && function_exists('_get_cron_array'))) {
			$crons = _get_cron_array();
			if (is_array($crons)) {
				$timenow = time();
				foreach ($crons as $jt => $job) {
					if ($jt < $timenow) {
						$how_many_overdue++;
					}
				}
			}
		}
		return $how_many_overdue;
	}

	public function get_php_errors($errno, $errstr, $errfile, $errline) {
		global $updraftplus;
		if (0 == error_reporting()) return true;
		$logline = $updraftplus->php_error_to_logline($errno, $errstr, $errfile, $errline);
		$this->logged[] = $logline;
		# Don't pass it up the chain (since it's going to be output to the user always)
		return true;
	}

	private function download_status($timestamp, $type, $findex) {
		global $updraftplus;
		$response = array( 'm' => $updraftplus->jobdata_get('dlmessage_'.$timestamp.'_'.$type.'_'.$findex).'<br>' );
		if ($file = $updraftplus->jobdata_get('dlfile_'.$timestamp.'_'.$type.'_'.$findex)) {
			if ('failed' == $file) {
				$response['e'] = __('Download failed','updraftplus').'<br>';
				$errs = $updraftplus->jobdata_get('dlerrors_'.$timestamp.'_'.$type.'_'.$findex);
				if (is_array($errs) && !empty($errs)) {
					$response['e'] .= '<ul style="list-style: disc inside;">';
					foreach ($errs as $err) {
						if (is_array($err)) {
							$response['e'] .= '<li>'.htmlspecialchars($err['message']).'</li>';
						} else {
							$response['e'] .= '<li>'.htmlspecialchars($err).'</li>';
						}
					}
					$response['e'] .= '</ul>';
				}
			} elseif (preg_match('/^downloaded:(\d+):(.*)$/', $file, $matches) && file_exists($matches[2])) {
				$response['p'] = 100;
				$response['f'] = $matches[2];
				$response['s'] = (int)$matches[1];
				$response['t'] = (int)$matches[1];
				$response['m'] = __('File ready.', 'updraftplus');
			} elseif (preg_match('/^downloading:(\d+):(.*)$/', $file, $matches) && file_exists($matches[2])) {
				// Convert to bytes
				$response['f'] = $matches[2];
				$total_size = (int)max($matches[1], 1);
				$cur_size = filesize($matches[2]);
				$response['s'] = $cur_size;
				$file_age = time() - filemtime($matches[2]);
				if ($file_age > 20) $response['a'] = time() - filemtime($matches[2]);
				$response['t'] = $total_size;
				$response['m'] .= __("Download in progress", 'updraftplus').' ('.round($cur_size/1024).' / '.round(($total_size/1024)).' Kb)';
				$response['p'] = round(100*$cur_size/$total_size);
			} else {
				$response['m'] .= __('No local copy present.', 'updraftplus');
				$response['p'] = 0;
				$response['s'] = 0;
				$response['t'] = 1;
			}
		}
		return $response;
	}

	private function analyse_db_file($timestamp, $res, $db_file = false, $header_only = false) {

		$mess = array(); $warn = array(); $err = array(); $info = array();

		global $updraftplus, $wp_version;
		include(ABSPATH.WPINC.'/version.php');

		$updraft_dir = $updraftplus->backups_dir_location();

		if (false === $db_file) {
			# This attempts to raise the maximum packet size. This can't be done within the session, only globally. Therefore, it has to be done before the session starts; in our case, during the pre-analysis.
			$updraftplus->get_max_packet_size();

			$backup = $updraftplus->get_backup_history($timestamp);
			if (!isset($backup['nonce']) || !isset($backup['db'])) return array($mess, $warn, $err, $info);

			$db_file = (is_string($backup['db'])) ? $updraft_dir.'/'.$backup['db'] : $updraft_dir.'/'.$backup['db'][0];
		}

		if (!is_readable($db_file)) return array($mess, $warn, $err, $info);

		// Encrypted - decrypt it
		if ($updraftplus->is_db_encrypted($db_file)) {

			$encryption = empty($res['updraft_encryptionphrase']) ? UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase') : $res['updraft_encryptionphrase'];

			if (!$encryption) {
				if (class_exists('UpdraftPlus_Addon_MoreDatabase')) {
					$err[] = sprintf(__('Error: %s', 'updraftplus'), __('Decryption failed. The database file is encrypted, but you have no encryption key entered.', 'updraftplus'));
				} else {
					$err[] = sprintf(__('Error: %s', 'updraftplus'), __('Decryption failed. The database file is encrypted.', 'updraftplus'));
				}
				return array($mess, $warn, $err, $info);
			}

			$ciphertext = $updraftplus->decrypt($db_file, $encryption);

			if ($ciphertext) {
				$new_db_file = $updraft_dir.'/'.basename($db_file, '.crypt');
				if (!file_put_contents($new_db_file, $ciphertext)) {
					$err[] = __('Failed to write out the decrypted database to the filesystem.','updraftplus');
					return array($mess, $warn, $err, $info);
				}
				$db_file = $new_db_file;
			} else {
				$err[] = __('Decryption failed. The most likely cause is that you used the wrong key.','updraftplus');
				return array($mess, $warn, $err, $info);
			}
		}

		# Even the empty schema when gzipped comes to 1565 bytes; a blank WP 3.6 install at 5158. But we go low, in case someone wants to share single tables.
		if (filesize($db_file) < 1000) {
			$err[] = sprintf(__('The database is too small to be a valid WordPress database (size: %s Kb).','updraftplus'), round(filesize($db_file)/1024, 1));
			return array($mess, $warn, $err, $info);
		}

		$is_plain = ('.gz' == substr($db_file, -3, 3)) ? false : true;

		$dbhandle = ($is_plain) ? fopen($db_file, 'r') : $this->gzopen_for_read($db_file, $warn, $err);
		if (!is_resource($dbhandle)) {
			$err[] =  __('Failed to open database file.', 'updraftplus');
			return array($mess, $warn, $err, $info);
		}

		# Analyse the file, print the results.

		$line = 0;
		$old_siteurl = '';
		$old_home = '';
		$old_table_prefix = '';
		$old_siteinfo = array();
		$gathering_siteinfo = true;
		$old_wp_version = '';
		$old_php_version = '';

		$tables_found = array();

		// TODO: If the backup is the right size/checksum, then we could restore the $line <= 100 in the 'while' condition and not bother scanning the whole thing? Or better: sort the core tables to be first so that this usually terminates early

		$wanted_tables = array('terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'users', 'usermeta');

		$migration_warning = false;

		# Don't set too high - we want a timely response returned to the browser
		@set_time_limit(90);

		while ((($is_plain && !feof($dbhandle)) || (!$is_plain && !gzeof($dbhandle))) && ($line<100 || (!$header_only && count($wanted_tables)>0))) {
			$line++;
			// Up to 1Mb
			$buffer = ($is_plain) ? rtrim(fgets($dbhandle, 1048576)) : rtrim(gzgets($dbhandle, 1048576));
			// Comments are what we are interested in
			if (substr($buffer, 0, 1) == '#') {
				if ('' == $old_siteurl && preg_match('/^\# Backup of: (http(.*))$/', $buffer, $matches)) {
					$old_siteurl = untrailingslashit($matches[1]);
					$mess[] = __('Backup of:', 'updraftplus').' '.htmlspecialchars($old_siteurl).((!empty($old_wp_version)) ? ' '.sprintf(__('(version: %s)', 'updraftplus'), $old_wp_version) : '');
					// Check for should-be migration
					if (!$migration_warning && $old_siteurl != untrailingslashit(site_url())) {
						$migration_warning = true;
						$powarn = apply_filters('updraftplus_dbscan_urlchange', sprintf(__('Warning: %s', 'updraftplus'), '<a href="http://updraftplus.com/shop/migrator/">'.__('This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus').'</a>'), $old_siteurl, $res);
						if (!empty($powarn)) $warn[] = $powarn;
					}
				} elseif ('' == $old_home && preg_match('/^\# Home URL: (http(.*))$/', $buffer, $matches)) {
					$old_home = untrailingslashit($matches[1]);
					// Check for should-be migration
					if (!$migration_warning && $old_home != home_url()) {
						$migration_warning = true;
						$powarn = apply_filters('updraftplus_dbscan_urlchange', sprintf(__('Warning: %s', 'updraftplus'), '<a href="http://updraftplus.com/shop/migrator/">'.__('This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus').'</a>'), $old_home, $res);
						if (!empty($powarn)) $warn[] = $powarn;
					}
				} elseif ('' == $old_wp_version && preg_match('/^\# WordPress Version: ([0-9]+(\.[0-9]+)+)(-[-a-z0-9]+,)?(.*)$/', $buffer, $matches)) {
					$old_wp_version = $matches[1];
					if (!empty($matches[3])) $old_wp_version .= substr($matches[3], 0, strlen($matches[3])-1);
					if (version_compare($old_wp_version, $wp_version, '>')) {
						//$mess[] = sprintf(__('%s version: %s', 'updraftplus'), 'WordPress', $old_wp_version);
						$warn[] = sprintf(__('You are importing from a newer version of WordPress (%s) into an older one (%s). There are no guarantees that WordPress can handle this.', 'updraftplus'), $old_wp_version, $wp_version);
					}
					if (preg_match('/running on PHP ([0-9]+\.[0-9]+)(\s|\.)/', $matches[4], $nmatches) && preg_match('/^([0-9]+\.[0-9]+)(\s|\.)/', PHP_VERSION, $cmatches)) {
						$old_php_version = $nmatches[1];
						$current_php_version = $cmatches[1];
						if (version_compare($old_php_version, $current_php_version, '>')) {
							//$mess[] = sprintf(__('%s version: %s', 'updraftplus'), 'WordPress', $old_wp_version);
							$warn[] = sprintf(__('The site in this backup was running on a webserver with version %s of %s. ', 'updraftplus'), $old_php_version, 'PHP').' '.sprintf(__('This is significantly newer than the server which you are now restoring onto (version %s).', 'updraftplus'), PHP_VERSION).' '.sprintf(__('You should only proceed if you cannot update the current server and are confident (or willing to risk) that your plugins/themes/etc. are compatible with the older %s version.', 'updraftplus'), 'PHP').' '.sprintf(__('Any support requests to do with %s should be raised with your web hosting company.', 'updraftplus'), 'PHP');
						}
					}
				} elseif ('' == $old_table_prefix && (preg_match('/^\# Table prefix: (\S+)$/', $buffer, $matches) || preg_match('/^-- Table prefix: (\S+)$/i', $buffer, $matches))) {
					$old_table_prefix = $matches[1];
// 					echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
				} elseif (empty($info['label']) && preg_match('/^\# Label: (.*)$/', $buffer, $matches)) {
					$info['label'] = $matches[1];
					$mess[] = __('Backup label:', 'updraftplus').' '.htmlspecialchars($info['label']);
				} elseif ($gathering_siteinfo && preg_match('/^\# Site info: (\S+)$/', $buffer, $matches)) {
					if ('end' == $matches[1]) {
						$gathering_siteinfo = false;
						// Sanity checks
						if (isset($old_siteinfo['multisite']) && !$old_siteinfo['multisite'] && is_multisite()) {
							// Just need to check that you're crazy
							if (!defined('UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE') ||  UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE != true) {
								$err[] =  sprintf(__('Error: %s', 'updraftplus'), __('You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus'));
								return array($mess, $warn, $err, $info);
							}
							// Got the needed code?
							if (!class_exists('UpdraftPlusAddOn_MultiSite') || !class_exists('UpdraftPlus_Addons_Migrator')) {
								 $err[] = sprintf(__('Error: %s', 'updraftplus'), __('To import an ordinary WordPress site into a multisite installation requires both the multisite and migrator add-ons.', 'updraftplus'));
								return array($mess, $warn, $err, $info);
							}
						} elseif (isset($old_siteinfo['multisite']) && $old_siteinfo['multisite'] && !is_multisite()) {
							$warn[] = __('Warning:', 'updraftplus').' '.__('Your backup is of a WordPress multisite install; but this site is not. Only the first site of the network will be accessible.', 'updraftplus').' <a href="http://codex.wordpress.org/Create_A_Network">'.__('If you want to restore a multisite backup, you should first set up your WordPress installation as a multisite.', 'updraftplus').'</a>';
						}
					} elseif (preg_match('/^([^=]+)=(.*)$/', $matches[1], $kvmatches)) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						if ('multisite' == $key && $val) {
							$mess[] = '<strong>'.__('Site information:','updraftplus').'</strong>'.' is a WordPress Network';
						}
						$old_siteinfo[$key]=$val;
					}
				}

			} elseif (preg_match('/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $buffer, $matches)) {
				$table = $matches[1];
				$tables_found[] = $table;
				if ($old_table_prefix) {
					// Remove prefix
					$table = $updraftplus->str_replace_once($old_table_prefix, '', $table);
					if (in_array($table, $wanted_tables)) {
						$wanted_tables = array_diff($wanted_tables, array($table));
					}
				}
			}
		}

		if ($is_plain) {
			@fclose($dbhandle);
		} else {
			@gzclose($dbhandle);
		}

/*        $blog_tables = "CREATE TABLE $wpdb->terms (
CREATE TABLE $wpdb->term_taxonomy (
CREATE TABLE $wpdb->term_relationships (
CREATE TABLE $wpdb->commentmeta (
CREATE TABLE $wpdb->comments (
CREATE TABLE $wpdb->links (
CREATE TABLE $wpdb->options (
CREATE TABLE $wpdb->postmeta (
CREATE TABLE $wpdb->posts (
        $users_single_table = "CREATE TABLE $wpdb->users (
        $users_multi_table = "CREATE TABLE $wpdb->users (
        $usermeta_table = "CREATE TABLE $wpdb->usermeta (
        $ms_global_tables = "CREATE TABLE $wpdb->blogs (
CREATE TABLE $wpdb->blog_versions (
CREATE TABLE $wpdb->registration_log (
CREATE TABLE $wpdb->site (
CREATE TABLE $wpdb->sitemeta (
CREATE TABLE $wpdb->signups (
*/

		$missing_tables = array();
		if ($old_table_prefix) {
			if (!$header_only) {
				foreach ($wanted_tables as $table) {
					if (!in_array($old_table_prefix.$table, $tables_found)) {
						$missing_tables[] = $table;
					}
				}
				if (count($missing_tables)>0) {
					$warn[] = sprintf(__('This database backup is missing core WordPress tables: %s', 'updraftplus'), implode(', ', $missing_tables));
				}
			}
		} else {
			if (empty($backup['meta_foreign'])) {
				$warn[] = __('UpdraftPlus was unable to find the table prefix when scanning the database backup.', 'updraftplus');
			}
		}

		return array($mess, $warn, $err, $info);

	}

	private function gzopen_for_read($file, &$warn, &$err) {
		if (!function_exists('gzopen') || !function_exists('gzread')) {
			$missing = '';
			if (!function_exists('gzopen')) $missing .= 'gzopen';
			if (!function_exists('gzread')) $missing .= ($missing) ? ', gzread' : 'gzread';
			$err[] = sprintf(__("Your web server's PHP installation has these functions disabled: %s.", 'updraftplus'), implode(', ', $missing)).' '.sprintf(__('Your hosting company must enable these functions before %s can work.', 'updraftplus'), __('restoration', 'updraftplus'));
			return false;
		}
		if (false === ($dbhandle = gzopen($file, 'r'))) return false;
		if (false === ($bytes = gzread($dbhandle, 3))) return false;
		# Double-gzipped?
		if ('H4sI' != base64_encode($bytes)) {
			if (0 === gzseek($dbhandle, 0)) {
				return $dbhandle;
			} else {
				@gzclose($dbhandle);
				return gzopen($file, 'r');
			}
		}
		# Yes, it's double-gzipped

		$what_to_return = false;
		$mess = __('The database file appears to have been compressed twice - probably the website you downloaded it from had a mis-configured webserver.', 'updraftplus');
		$messkey = 'doublecompress';
		$err_msg = '';

		if (false === ($fnew = fopen($file.".tmp", 'w')) || !is_resource($fnew)) {

			@gzclose($dbhandle);
			$err_msg = __('The attempt to undo the double-compression failed.', 'updraftplus');

		} else {

			@fwrite($fnew, $bytes);
			$emptimes = 0;
			while (!gzeof($dbhandle)) {
				$bytes = @gzread($dbhandle, 131072);
				if (empty($bytes)) {
					global $updraftplus;
					$emptimes++;
					$updraftplus->log("Got empty gzread ($emptimes times)");
					if ($emptimes>2) break;
				} else {
					@fwrite($fnew, $bytes);
				}
			}

			gzclose($dbhandle);
			fclose($fnew);
			# On some systems (all Windows?) you can't rename a gz file whilst it's gzopened
			if (!rename($file.".tmp", $file)) {
				$err_msg = __('The attempt to undo the double-compression failed.', 'updraftplus');
			} else {
				$mess .= ' '.__('The attempt to undo the double-compression succeeded.', 'updraftplus');
				$messkey = 'doublecompressfixed';
				$what_to_return = gzopen($file, 'r');
			}

		}

		$warn[$messkey] = $mess;
		if (!empty($err_msg)) $err[] = $err_msg;
		return $what_to_return;
	}

	public function upload_dir($uploads) {
		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();
		if (is_writable($updraft_dir)) $uploads['path'] = $updraft_dir;
		return $uploads;
	}

	// We do actually want to over-write
	public function unique_filename_callback($dir, $name, $ext) {
		return $name.$ext;
	}

	public function sanitize_file_name($filename) {
		// WordPress 3.4.2 on multisite (at least) adds in an unwanted underscore
		return preg_replace('/-db(.*)\.gz_\.crypt$/', '-db$1.gz.crypt', $filename);
	}

	public function plupload_action() {
		// check ajax nonce

		global $updraftplus;
		@set_time_limit(900);

		if (!UpdraftPlus_Options::user_can_manage()) exit;
		check_ajax_referer('updraft-uploader');

		$updraft_dir = $updraftplus->backups_dir_location();
		if (!@$updraftplus->really_is_writable($updraft_dir)) {
			echo json_encode(array('e' => sprintf(__("Backup directory (%s) is not writable, or does not exist.", 'updraftplus'), $updraft_dir).' '.__('You will find more information about this in the Settings section.', 'updraftplus')));
			exit;
		}

		add_filter('upload_dir', array($this, 'upload_dir'));
		add_filter('sanitize_file_name', array($this, 'sanitize_file_name'));
		// handle file upload

		$farray = array('test_form' => true, 'action' => 'plupload_action');

		$farray['test_type'] = false;
		$farray['ext'] = 'x-gzip';
		$farray['type'] = 'application/octet-stream';

		if (!isset($_POST['chunks'])) {
			$farray['unique_filename_callback'] = array($this, 'unique_filename_callback');
		}

		$status = wp_handle_upload(
			$_FILES['async-upload'],
			$farray
		);
		remove_filter('upload_dir', array($this, 'upload_dir'));
		remove_filter('sanitize_file_name', array($this, 'sanitize_file_name'));

		if (isset($status['error'])) {
			echo json_encode(array('e' => $status['error']));
			exit;
		}

		// If this was the chunk, then we should instead be concatenating onto the final file
		if (isset($_POST['chunks']) && isset($_POST['chunk']) && preg_match('/^[0-9]+$/',$_POST['chunk'])) {
			$final_file = basename($_POST['name']);
			if (!rename($status['file'], $updraft_dir.'/'.$final_file.'.'.$_POST['chunk'].'.zip.tmp')) {
				@unlink($status['file']);
				echo json_encode(array('e' => sprintf(__('Error: %s', 'updraftplus'), __('This file could not be uploaded', 'updraftplus'))));
				exit;
			}
			$status['file'] = $updraft_dir.'/'.$final_file.'.'.$_POST['chunk'].'.zip.tmp';

			// Final chunk? If so, then stich it all back together
			if ($_POST['chunk'] == $_POST['chunks']-1) {
				if ($wh = fopen($updraft_dir.'/'.$final_file, 'wb')) {
					for ($i=0 ; $i<$_POST['chunks']; $i++) {
						$rf = $updraft_dir.'/'.$final_file.'.'.$i.'.zip.tmp';
						if ($rh = fopen($rf, 'rb')) {
							while ($line = fread($rh, 32768)) fwrite($wh, $line);
							fclose($rh);
							@unlink($rf);
						}
					}
					fclose($wh);
					$status['file'] = $updraft_dir.'/'.$final_file;
					if ('.tar' == substr($final_file, -4, 4)) {
						if (file_exists($status['file'].'.gz')) unlink($status['file'].'.gz');
						if (file_exists($status['file'].'.bz2')) unlink($status['file'].'.bz2');
					} elseif ('.tar.gz' == substr($final_file, -7, 7)) {
						if (file_exists(substr($status['file'], 0, strlen($status['file'])-3))) unlink(substr($status['file'], 0, strlen($status['file'])-3));
						if (file_exists(substr($status['file'], 0, strlen($status['file'])-3).'.bz2')) unlink(substr($status['file'], 0, strlen($status['file'])-3).'.bz2');
					} elseif ('.tar.bz2' == substr($final_file, -8, 8)) {
						if (file_exists(substr($status['file'], 0, strlen($status['file'])-4))) unlink(substr($status['file'], 0, strlen($status['file'])-4));
						if (file_exists(substr($status['file'], 0, strlen($status['file'])-4).'.gz')) unlink(substr($status['file'], 0, strlen($status['file'])-3).'.gz');
					}
				}
			}

		}

		$response = array();
		if (!isset($_POST['chunks']) || (isset($_POST['chunk']) && $_POST['chunk'] == $_POST['chunks']-1)) {
			$file = basename($status['file']);
			if (!preg_match('/^log\.[a-f0-9]{12}\.txt/i', $file) && !preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?\.(zip|gz|gz\.crypt)$/i', $file, $matches)) {
				$accept = apply_filters('updraftplus_accept_archivename', array());
				if (is_array($accept)) {
					foreach ($accept as $acc) {
						if (preg_match('/'.$acc['pattern'].'/i', $file)) $accepted = $acc['desc'];
					}
				}
				if (!empty($accepted)) {
					$response['dm'] = sprintf(__('This backup was created by %s, and can be imported.', 'updraftplus'), $accepted);
				} else {
					@unlink($status['file']);
					echo json_encode(array('e' => sprintf(__('Error: %s', 'updraftplus'),__('Bad filename format - this does not look like a file created by UpdraftPlus','updraftplus'))));
					exit;
				}
			} else {
				$backupable_entities = $updraftplus->get_backupable_file_entities(true);
				$type = $matches[3];
				if ('db' != $type && !isset($backupable_entities[$type]) && !preg_match('/^log\.[a-f0-9]{12}\.txt/', $file)) {
					@unlink($status['file']);
					echo json_encode(array('e' => sprintf(__('Error: %s', 'updraftplus'),sprintf(__('This looks like a file created by UpdraftPlus, but this install does not know about this type of object: %s. Perhaps you need to install an add-on?','updraftplus'), htmlspecialchars($type)))));
					exit;
				}
			}
		}

		// send the uploaded file url in response
		$response['m'] = $status['url'];
		echo json_encode($response);
		exit;
	}

	# Database decrypter
	public function plupload_action2() {

		@set_time_limit(900);
		global $updraftplus;

		if (!UpdraftPlus_Options::user_can_manage()) exit;
		check_ajax_referer('updraft-uploader');

		$updraft_dir = $updraftplus->backups_dir_location();
		if (!is_writable($updraft_dir)) exit;

		add_filter('upload_dir', array($this, 'upload_dir'));
		add_filter('sanitize_file_name', array($this, 'sanitize_file_name'));
		// handle file upload

		$farray = array( 'test_form' => true, 'action' => 'plupload_action2' );

		$farray['test_type'] = false;
		$farray['ext'] = 'crypt';
		$farray['type'] = 'application/octet-stream';

		if (isset($_POST['chunks'])) {
// 			$farray['ext'] = 'zip';
// 			$farray['type'] = 'application/zip';
		} else {
			$farray['unique_filename_callback'] = array($this, 'unique_filename_callback');
		}

		$status = wp_handle_upload(
			$_FILES['async-upload'],
			$farray
		);
		remove_filter('upload_dir', array($this, 'upload_dir'));
		remove_filter('sanitize_file_name', array($this, 'sanitize_file_name'));

		if (isset($status['error'])) {
			echo 'ERROR:'.$status['error'];
			exit;
		}

		// If this was the chunk, then we should instead be concatenating onto the final file
		if (isset($_POST['chunks']) && isset($_POST['chunk']) && preg_match('/^[0-9]+$/',$_POST['chunk'])) {
			$final_file = basename($_POST['name']);
			rename($status['file'], $updraft_dir.'/'.$final_file.'.'.$_POST['chunk'].'.zip.tmp');
			$status['file'] = $updraft_dir.'/'.$final_file.'.'.$_POST['chunk'].'.zip.tmp';

			// Final chunk? If so, then stich it all back together
			if ($_POST['chunk'] == $_POST['chunks']-1) {
				if ($wh = fopen($updraft_dir.'/'.$final_file, 'wb')) {
					for ($i=0 ; $i<$_POST['chunks']; $i++) {
						$rf = $updraft_dir.'/'.$final_file.'.'.$i.'.zip.tmp';
						if ($rh = fopen($rf, 'rb')) {
							while ($line = fread($rh, 32768)) fwrite($wh, $line);
							fclose($rh);
							@unlink($rf);
						}
					}
					fclose($wh);
					$status['file'] = $updraft_dir.'/'.$final_file;
				}
			}

		}

		if (!isset($_POST['chunks']) || (isset($_POST['chunk']) && $_POST['chunk'] == $_POST['chunks']-1)) {
			$file = basename($status['file']);
			if (!preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-db([0-9]+)?\.(gz\.crypt)$/i', $file)) {

				@unlink($status['file']);
				echo 'ERROR:'.__('Bad filename format - this does not look like an encrypted database file created by UpdraftPlus','updraftplus');

				exit;
			}
		}

		// send the uploaded file url in response
// 		echo 'OK:'.$status['url'];
		echo 'OK:'.$file;
		exit;
	}

	public function settings_header() {
		global $updraftplus;
		?>

	<div class="wrap" id="updraft-wrap">
		<h1><?php echo $updraftplus->plugin_title; ?></h1>

		<a href="http://updraftplus.com">UpdraftPlus.Com</a> | <a href="https://updraftplus.com/news/"><?php _e('News','updraftplus');?></a>  | <a href="https://twitter.com/updraftplus"><?php _e('Twitter', 'updraftplus');?></a> | <?php if (!defined('UPDRAFTPLUS_NOADS_B')) { ?><a href="http://updraftplus.com/shop/updraftplus-premium/"><?php _e("Premium",'updraftplus');?></a>
| <?php } ?><a href="http://updraftplus.com/support/"><?php _e("Support",'updraftplus');?></a> | <a href="http://david.dw-perspective.org.uk"><?php _e("Lead developer's homepage",'updraftplus');?></a> | <?php if (1==0 && !defined('UPDRAFTPLUS_NOADS_B')) { ?><a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate"><?php _e('Donate', 'updraftplus');?></a> | <?php } ?><a href="http://updraftplus.com/support/frequently-asked-questions/">FAQs</a> | <a href="https://www.simbahosting.co.uk/s3/shop/"><?php _e('More plugins', 'updraftplus');?></a> - <?php _e('Version','updraftplus');?>: <?php echo $updraftplus->version; ?>
		<br>
		<?php
	}

	public function settings_output() {

		if (false == ($render = apply_filters('updraftplus_settings_page_render', true))) {
			do_action('updraftplus_settings_page_render_abort', $render);
			return;
		}

		do_action('updraftplus_settings_page_init');

		global $updraftplus;

		/*
		we use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credentials 
		for the WP_Filesystem. to do this WP outputs a form, but we don't pass our parameters via that. So the values are 
		passed back in as GET parameters.
		*/
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_restore' && isset($_REQUEST['backup_timestamp'])) {
			$backup_success = $this->restore_backup($_REQUEST['backup_timestamp']);
			if (empty($updraftplus->errors) && $backup_success === true) {
				// If we restored the database, then that will have out-of-date information which may confuse the user - so automatically re-scan for them.
				$this->rebuild_backup_history();
				echo '<p><strong>';
				$updraftplus->log_e('Restore successful!');
				echo '</strong></p>';
				$updraftplus->log("Restore successful");
				$s_val = 1;
				if (!empty($this->entities_to_restore) && is_array($this->entities_to_restore)) {
					foreach ($this->entities_to_restore as $k => $v) {
						if ('db' != $v) $s_val = 2;
					}
				}
				echo '<strong>'.__('Actions','updraftplus').':</strong> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&updraft_restore_success='.$s_val.'">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
				return;
			} elseif (is_wp_error($backup_success)) {
				echo '<p>';
				$updraftplus->log_e('Restore failed...');
				echo '</p>';
				$updraftplus->log_wp_error($backup_success);
				$updraftplus->log("Restore failed");
				$updraftplus->list_errors();
				echo '<strong>'.__('Actions','updraftplus').':</strong> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
				return;
			} elseif (false === $backup_success) {
				# This means, "not yet - but stay on the page because we may be able to do it later, e.g. if the user types in the requested information"
				return;
			}
		}

		if(isset($_REQUEST['action']) && 'updraft_delete_old_dirs' == $_REQUEST['action']) {
			$nonce = (empty($_REQUEST['_wpnonce'])) ? "" : $_REQUEST['_wpnonce'];
			if (!wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce')) die('Security check');
			$this->delete_old_dirs_go();
			return;
		}

		if(!empty($_REQUEST['action']) && 'updraftplus_broadcastaction' == $_REQUEST['action'] && !empty($_REQUEST['subaction'])) {
			$nonce = (empty($_REQUEST['nonce'])) ? "" : $_REQUEST['nonce'];
			if (!wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce')) die('Security check');
			do_action($_REQUEST['subaction']);
			return;
		}

		if(isset($_GET['error'])) $this->show_admin_warning(htmlspecialchars($_GET['error']), 'error');
		if(isset($_GET['message'])) $this->show_admin_warning(htmlspecialchars($_GET['message']));

		if(isset($_GET['action']) && $_GET['action'] == 'updraft_create_backup_dir' && isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'create_backup_dir')) {
			$created = $this->create_backup_dir();
			if(is_wp_error($created)) {
				echo '<p>'.__('Backup directory could not be created', 'updraftplus').'...<br/>';
				echo '<ul style="list-style: disc inside;">';
				foreach ($created->get_error_messages() as $key => $msg) {
					echo '<li>'.htmlspecialchars($msg).'</li>';
				}
				echo '</ul></p>';
			} elseif ($created !== false) {
				echo '<p>'.__('Backup directory successfully created.', 'updraftplus').'</p><br/>';
			}
			echo '<b>'.__('Actions','updraftplus').':</b> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus">'.__('Return to UpdraftPlus Configuration', 'updraftplus').'</a>';
			return;
		}

		echo '<div id="updraft_backup_started" class="updated fade" style="display:none; max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;"></div>';

		if(isset($_POST['action']) && 'updraft_backup_debug_all' == $_POST['action']) {
			$updraftplus->boot_backup(true,true);
		} elseif (isset($_POST['action']) && 'updraft_backup_debug_db' == $_POST['action']) {
			$updraftplus->boot_backup(false, true, false, true);
		} elseif (isset($_POST['action']) && 'updraft_wipesettings' == $_POST['action']) {
			$settings = $this->get_settings_keys();
			foreach ($settings as $s) UpdraftPlus_Options::delete_updraft_option($s);

			# These aren't in get_settings_keys() because they are always in the options table, regardless of context
			global $wpdb;
			$wpdb->query("DELETE FROM $wpdb->options WHERE ( option_name LIKE 'updraftplus_unlocked_%' OR option_name LIKE 'updraftplus_locked_%' OR option_name LIKE 'updraftplus_last_lock_time_%' OR option_name LIKE 'updraftplus_semaphore_%')");

			$site_options = array('updraft_oneshotnonce');
			foreach ($site_options as $s) delete_site_option($s);

			$this->show_admin_warning(__("Your settings have been wiped.", 'updraftplus'));
		}

		$this->settings_header();
		?>

			<div id="updraft-hidethis">
			<p><strong><?php _e('Warning:', 'updraftplus'); ?> <?php _e("If you can still read these words after the page finishes loading, then there is a JavaScript or jQuery problem in the site.", 'updraftplus'); ?> <a href="http://updraftplus.com/do-you-have-a-javascript-or-jquery-error/"><?php _e('Go here for more information.', 'updraftplus'); ?></a></strong></p>
			</div>

			<?php
			if(isset($_GET['updraft_restore_success'])) {
				echo "<div class=\"updated fade\" style=\"padding:8px;\"><strong>".__('Your backup has been restored.','updraftplus').'</strong>';
				if (2 == $_GET['updraft_restore_success']) echo ' '.__('Your old (themes, uploads, plugins, whatever) directories have been retained with "-old" appended to their name. Remove them when you are satisfied that the backup worked properly.');
				echo "</div>";
			}

			$ws_advert = $updraftplus->wordshell_random_advert(1);
			if ($ws_advert && empty($this->no_settings_warning)) { echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;">'.$ws_advert.'</div>'; }

			if(!$updraftplus->memory_check(64)) {?>
				<div class="updated" style="padding:8px;"><?php _e("Your PHP memory limit (set by your web hosting company) is very low. UpdraftPlus attempted to raise it but was unsuccessful. This plugin may struggle with a memory limit of less than 64 Mb  - especially if you have very large files uploaded (though on the other hand, many sites will be successful with a 32Mb limit - your experience may vary).",'updraftplus');?> <?php _e('Current limit is:','updraftplus');?> <?php echo $updraftplus->memory_check_current(); ?> Mb</div>
			<?php
			}

			if($this->scan_old_dirs(true)) $this->print_delete_old_dirs_form();

			if(!empty($updraftplus->errors)) {
				echo '<div class="error fade" style="padding:8px;">';
				$updraftplus->list_errors();
				echo '</div>';
			}

			$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			if (empty($backup_history)) {
				$this->rebuild_backup_history();
				$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			}
			$backup_history = (is_array($backup_history))?$backup_history:array();
			?>

		<h2 class="nav-tab-wrapper" style="margin: 14px 0px;">
		<?php
		$tabflag = 1;
		if (isset($_REQUEST['tab']) && 'addons' == $_REQUEST['tab']) { $tabflag = 5; }
		if (isset($_REQUEST['tab']) && 'expert' == $_REQUEST['tab']) { $tabflag = 4; }
		?>
		<a class="nav-tab <?php if(1 == $tabflag) echo 'nav-tab-active'; ?>" href="#updraft-navtab-status-content" id="updraft-navtab-status"><?php _e('Current Status', 'updraftplus');?></a>
		<a class="nav-tab" href="#updraft-navtab-backups-contents" id="updraft-navtab-backups"><?php echo __('Existing Backups', 'updraftplus').' ('.count($backup_history).')';?></a>
		<a class="nav-tab" id="updraft-navtab-settings" href="#updraft-navtab-settings-content"><?php _e('Settings', 'updraftplus');?></a>
		<a class="nav-tab<?php if(4 == $tabflag) echo ' nav-tab-active'; ?>" id="updraft-navtab-expert" href="#updraft-navtab-expert-content"><?php _e('Debugging / Expert Tools', 'updraftplus');?></a>
		<?php do_action('updraftplus_settings_afternavtabs'); ?>
		</h2>

		<?php
			$updraft_dir = $updraftplus->backups_dir_location();
			$backup_disabled = ($updraftplus->really_is_writable($updraft_dir)) ? '' : 'disabled="disabled"';
		?>
		
		<div id="updraft-poplog" >
			<pre id="updraft-poplog-content" style="white-space: pre-wrap;"></pre>
		</div>
		
		<div id="updraft-navtab-status-content" <?php if(1 != $tabflag) echo 'style="display:none;"'; ?>>

			<div id="updraft-insert-admin-warning"></div>

			<table class="form-table" style="float:left; clear: both;">
				<noscript>
				<tr>
					<th><?php _e('JavaScript warning','updraftplus');?>:</th>
					<td style="color:red"><?php _e('This admin interface uses JavaScript heavily. You either need to activate it within your browser, or to use a JavaScript-capable browser.','updraftplus');?></td>
				</tr>
				</noscript>

				<tr>
					<th><?php _e('Actions', 'updraftplus');?>:</th>
					<td>

					<?php 
						if ($backup_disabled) {
							$unwritable_mess = htmlspecialchars(__("The 'Backup Now' button is disabled as your backup directory is not writable (go to the 'Settings' tab and find the relevant option).", 'updraftplus'));
							$this->show_admin_warning($unwritable_mess, "error");
						}
					?>

					<button type="button" <?php echo $backup_disabled ?> class="button-primary updraft-bigbutton" <?php if ($backup_disabled) echo 'title="'.esc_attr(__('This button is disabled because your backup directory is not writable (see the settings).', 'updraftplus')).'" ';?> onclick="jQuery('#backupnow_label').val(''); jQuery('#updraft-backupnow-modal').dialog('open');"><?php _e('Backup Now', 'updraftplus');?></button>

					<button type="button" class="button-primary updraft-bigbutton" onclick="updraft_openrestorepanel();">
						<?php _e('Restore','updraftplus');?>
					</button>

					<button type="button" class="button-primary updraft-bigbutton" onclick="jQuery('#updraft-migrate-modal').dialog('open');"><?php _e('Clone/Migrate','updraftplus');?></button>

					</td>
				</tr>

				<?php
				// UNIX timestamp
				$next_scheduled_backup = wp_next_scheduled('updraft_backup');
				if ($next_scheduled_backup) {
					// Convert to GMT
					$next_scheduled_backup_gmt = gmdate('Y-m-d H:i:s', $next_scheduled_backup);
					// Convert to blog time zone
					$next_scheduled_backup = get_date_from_gmt($next_scheduled_backup_gmt, 'D, F j, Y H:i');
				} else {
					$next_scheduled_backup = __('Nothing currently scheduled','updraftplus');
				}
				
				$next_scheduled_backup_database = wp_next_scheduled('updraft_backup_database');
				if (UpdraftPlus_Options::get_updraft_option('updraft_interval_database',UpdraftPlus_Options::get_updraft_option('updraft_interval')) == UpdraftPlus_Options::get_updraft_option('updraft_interval')) {
					$next_scheduled_backup_database = ('Nothing currently scheduled' == $next_scheduled_backup) ? $next_scheduled_backup : __("At the same time as the files backup", 'updraftplus');
				} else {
					if ($next_scheduled_backup_database) {
						// Convert to GMT
						$next_scheduled_backup_database_gmt = gmdate('Y-m-d H:i:s', $next_scheduled_backup_database);
						// Convert to blog time zone
						$next_scheduled_backup_database = get_date_from_gmt($next_scheduled_backup_database_gmt, 'D, F j, Y H:i');
					} else {
						$next_scheduled_backup_database = __('Nothing currently scheduled', 'updraftplus');
					}
				}
				$current_time = get_date_from_gmt(gmdate('Y-m-d H:i:s'), 'D, F j, Y H:i');

				$last_backup_html = $this->last_backup_html();

				?>

				<script>var lastbackup_laststatus = '<?php echo esc_js($last_backup_html);?>';</script>

				<tr>
					<th><span title="<?php _e('All the times shown in this section are using WordPress\'s configured time zone, which you can set in Settings -> General', 'updraftplus'); ?>"><?php _e('Next scheduled backups', 'updraftplus');?>:</span></th>
					<td>
						<table style="border: 0px; padding: 0px; margin: 0 10px 0 0;">
						<tr>
						<td style="width: 124px; vertical-align:top; margin: 0px; padding: 0px;"><?php _e('Files','updraftplus'); ?>:</td><td style="color:blue; margin: 0px; padding: 0px;"><?php echo $next_scheduled_backup?></td>
						</tr><tr>
						<td style="width: 124px; vertical-align:top; margin: 0px; padding: 0px;"><?php _e('Database','updraftplus');?>: </td><td style="color:blue; margin: 0px; padding: 0px;"><?php echo $next_scheduled_backup_database?></td>
						</tr><tr>
						<td style="width: 124px; vertical-align:top; margin: 0px; padding: 0px;"><?php _e('Time now','updraftplus');?>: </td><td style="color:blue; margin: 0px; padding: 0px;"><?php echo $current_time?></td>
						</table>
					</td>
				</tr>
				<tr>
					<th><?php _e('Last backup job run:','updraftplus');?></th>
					<td id="updraft_last_backup"><?php echo $last_backup_html ?></td>
				</tr>
			</table>

			<br style="clear:both" />
			<table class="form-table">

				<?php $active_jobs = $this->print_active_jobs();?>
				<tr id="updraft_activejobsrow" style="<?php if (!$active_jobs) echo 'display:none;'; ?>">
					<th><?php _e('Backups in progress:', 'updraftplus');?></th>
					<td id="updraft_activejobs"><?php echo $active_jobs;?></td>
				</tr>

				<tr id="updraft_lastlogmessagerow">
					<th><?php _e('Last log message','updraftplus');?>:</th>
					<td>
						<span id="updraft_lastlogcontainer"><?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', __('(Nothing yet logged)','updraftplus'))); ?></span><br>
						<a href="?page=updraftplus&action=downloadlatestmodlog&wpnonce=<?php echo wp_create_nonce('updraftplus_download') ?>" class="updraft-log-link" onclick="event.preventDefault(); updraft_popuplog('');"><?php _e('Download most recently modified log file','updraftplus');?></a>
					</td>
				</tr>

				<!--<tr>
					<th><?php echo htmlspecialchars(__('Backups, logs & restoring','updraftplus')); ?>:</th>
					<td><a id="updraft_showbackups" href="#" title="<?php _e('Press to see available backups','updraftplus');?>" onclick="updraft_openrestorepanel(0); return false;"><?php echo sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history)); ?></a></td>
				</tr>-->

				<?php
				# Currently disabled - not sure who we want to show this to
				if (1==0 && !defined('UPDRAFTPLUS_NOADS_B')) {
					$feed = $updraftplus->get_updraftplus_rssfeed();
					if (is_a($feed, 'SimplePie')) {
						echo '<tr><th style="vertical-align:top;">'.__('Latest UpdraftPlus.com news:', 'updraftplus').'</th><td style="vertical-align:top;">';
						echo '<ul style="list-style: disc inside;">';
						foreach ($feed->get_items(0, 5) as $item) {
							echo '<li>';
							echo '<a href="'.esc_attr($item->get_permalink()).'">';
							echo htmlspecialchars($item->get_title());
							# D, F j, Y H:i
							echo "</a> (".htmlspecialchars($item->get_date('j F Y')).")";
							echo '</li>';
						}
						echo '</ul></td></tr>';
					}
				}
			?>
			</table>

<div id="updraft-migrate-modal" title="<?php _e('Migrate Site', 'updraftplus'); ?>">

<?php
	if (class_exists('UpdraftPlus_Addons_Migrator')) {
		echo '<p>'.str_replace('"', "&quot;", __('Migration of data from another site happens through the "Restore" button. A "migration" is ultimately the same as a restoration - but using backup archives that you import from another site. UpdraftPlus modifies the restoration operation appropriately, to fit the backup data to the new site.', 'updraftplus')).' '.sprintf(__('<a href="%s">Read this article to see step-by-step how it\'s done.</a>', 'updraftplus'),'http://updraftplus.com/faqs/how-do-i-migrate-to-a-new-site-location/');
	} else {
		echo '<p>'.__('Do you want to migrate or clone/duplicate a site?', 'updraftplus').'</p><p>'.__('Then, try out our "Migrator" add-on. After using it once, you\'ll have saved the purchase price compared to the time needed to copy a site by hand.', 'updraftplus').'</p><p><a href="http://updraftplus.com/shop/migrator/">'.__('Get it here.', 'updraftplus').'</a>';
	}
?>
</p>
</div>

<div id="updraft-iframe-modal">
	<div id="updraft-iframe-modal-innards">
	</div>
</div>

<div id="updraft-backupnow-modal" title="UpdraftPlus - <?php _e('Perform a one-time backup','updraftplus'); ?>">
	<p><?php _e("To proceed, press 'Backup Now'. Then, watch the 'Last Log Message' field for activity.", 'updraftplus');?></p>

	<p>
		<input type="checkbox" id="backupnow_nodb"> <label for="backupnow_nodb"><?php _e("Don't include the database in the backup", 'updraftplus'); ?></label><br>
		<input type="checkbox" id="backupnow_nofiles"> <label for="backupnow_nofiles"><?php _e("Don't include any files in the backup", 'updraftplus'); ?></label><br>
		<input type="checkbox" id="backupnow_nocloud"> <label for="backupnow_nocloud"><?php _e("Don't send this backup to remote storage", 'updraftplus'); ?></label>
	</p>

	<?php do_action('updraft_backupnow_modal_afteroptions'); ?>

	<p><?php _e('Does nothing happen when you attempt backups?','updraftplus');?> <a href="http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/"><?php _e('Go here for help.', 'updraftplus');?></a></p>
</div>

			<?php
			if (is_multisite() && !file_exists(UPDRAFTPLUS_DIR.'/addons/multisite.php')) {
				?>
				<h2>UpdraftPlus <?php _e('Multisite','updraftplus');?></h2>
				<table>
				<tr>
				<td>
				<p style="max-width:800px;"><?php echo __('Do you need WordPress Multisite support?','updraftplus').' <a href="http://updraftplus.com/">'. __('Please check out UpdraftPlus Premium, or the stand-alone Multisite add-on.','updraftplus');?></a>.</p>
				</td>
				</tr>
				</table>
			<?php } ?>

		</div>

		<div id="updraft-navtab-backups-content" style="display:none;">
			<?php $this->settings_downloadingandrestoring($backup_history); ?>
		</div>

		<div id="updraft-navtab-settings-content" style="display:none;">
			<h2 style="margin-top: 6px;"><?php _e('Configure Backup Contents And Schedule','updraftplus');?></h2>
			<?php UpdraftPlus_Options::options_form_begin(); ?>
				<?php $this->settings_formcontents($last_backup_html); ?>
			</form>
		</div>

		<div id="updraft-navtab-expert-content"<?php if(4 != $tabflag) echo ' style="display:none;"'; ?>>
			<?php $this->settings_expertsettings($backup_disabled); ?>
		</div>

		<?php
		do_action('updraftplus_settings_finish');
	}

	private function settings_downloadingandrestoring($backup_history = array()) {
		global $updraftplus;
	//<td class="download-backups" style="display:none; border: 2px dashed #aaa;">
	?>
		<div class="download-backups form-table" style="margin-top: 8px;">
				<!-- <h2><?php echo __('Existing Backups: Downloading And Restoring', 'updraftplus'); ?></h2> -->
				<p style="display:none; background-color:pink; padding:8px; margin:4px;border: 1px dotted;" id="ud-whitespace-warning">
					<?php echo '<strong>'.__('Warning','updraftplus').':</strong> '.__('Your WordPress installation has a problem with outputting extra whitespace. This can corrupt backups that you download from here.','updraftplus').' <a href="http://updraftplus.com/problems-with-extra-white-space/">'.__('Please consult this FAQ for help on what to do about it.', 'updraftplus').'</a>';?>
				</p>
				<p>
				<ul style="list-style: none inside; max-width: 800px; margin-top: 6px; margin-bottom: 12px;">
<!--
				<li><strong><?php _e('Downloading','updraftplus');?>:</strong> <?php _e("Following a link for Database/Plugins/Themes/Uploads/Others will make UpdraftPlus try to bring the backup file back from the remote storage (if any - e.g. Amazon S3, Dropbox, Google Drive, FTP) to your webserver. Then you will be allowed to download it to your computer. If the fetch from the remote storage stops progressing (wait 30 seconds to make sure), then press again to resume. Remember that you can also visit the cloud storage vendor's website directly.",'updraftplus');?></li>
				<li>
					<strong><?php _e('Restoring:','updraftplus');?></strong> <?php _e('Press the Restore button next to the chosen backup set.', 'updraftplus');?>
				</li>
-->

				<li title="<?php _e('This is a count of the contents of your Updraft directory','updraftplus');?>"><strong><?php _e('Web-server disk space in use by UpdraftPlus','updraftplus');?>:</strong> <span id="updraft_diskspaceused"><em><?php _e('calculating...', 'updraftplus'); ?></em></span> <a href="#" onclick="updraftplus_diskspace(); return false;"><?php _e('refresh','updraftplus');?></a></li>

				<li>
					<strong><?php _e('More tasks:','updraftplus');?></strong>
					<a href="#" onclick="jQuery('#updraft-plupload-modal').slideToggle(); return false;"><?php _e('Upload backup files','updraftplus');?></a>
					| <a href="#" onclick="updraft_updatehistory(1, 0); return false;" title="<?php echo __('Press here to look inside your UpdraftPlus directory (in your web hosting space) for any new backup sets that you have uploaded.', 'updraftplus').' '.__('The location of this directory is set in the expert settings, in the Settings tab.','updraftplus'); ?>"><?php _e('Rescan local folder for new backup sets','updraftplus');?></a>
					| <a href="#" onclick="updraft_updatehistory(1, 1); return false;" title="<?php _e('Press here to look inside any remote storage methods for any existing backup sets.','updraftplus'); ?>"><?php _e('Rescan remote storage','updraftplus');?></a>
				</li>
				<?php
					if (false !== strpos($_SERVER['HTTP_USER_AGENT'], 'Opera') || false !== strpos($_SERVER['HTTP_USER_AGENT'], 'OPR/')) { ?>
						<li><strong><?php _e('Opera web browser','updraftplus');?>:</strong> <?php _e('If you are using this, then turn Turbo/Road mode off.','updraftplus');?></li>
				<?php } ?>

				</ul>
				</p>

				<div id="updraft-plupload-modal" title="<?php _e('UpdraftPlus - Upload backup files','updraftplus'); ?>" style="width: 75%; margin: 16px; display:none; margin-left: 100px;">
				<p style="max-width: 610px;"><em><?php _e("Upload files into UpdraftPlus." ,'updraftplus');?> <?php echo htmlspecialchars(__('Or, you can place them manually into your UpdraftPlus directory (usually wp-content/updraft), e.g. via FTP, and then use the "rescan" link above.', 'updraftplus'));?></em></p>
					<?php
					global $wp_version;
					if (version_compare($wp_version, '3.3', '<')) {
						echo '<em>'.sprintf(__('This feature requires %s version %s or later', 'updraftplus'), 'WordPress', '3.3').'</em>';
					} else {
						?>
						<div id="plupload-upload-ui" style="width: 70%;">
							<div id="drag-drop-area">
								<div class="drag-drop-inside">
								<p class="drag-drop-info"><?php _e('Drop backup files here', 'updraftplus'); ?></p>
								<p><?php _ex('or', 'Uploader: Drop backup files here - or - Select Files'); ?></p>
								<p class="drag-drop-buttons"><input id="plupload-browse-button" type="button" value="<?php esc_attr_e('Select Files'); ?>" class="button" /></p>
								</div>
							</div>
							<div id="filelist">
							</div>
						</div>
						<?php 
					}
					?>

				</div>

					<div id="ud_downloadstatus"></div>
					<div id="updraft_existing_backups" style="margin-bottom:12px;">
						<?php
							print $this->existing_backup_table($backup_history);
						?>
					</div>
				</div>

		<div id="updraft-message-modal" title="UpdraftPlus">
			<div id="updraft-message-modal-innards" style="padding: 4px;">
			</div>
		</div>

		<div id="updraft-delete-modal" title="<?php _e('Delete backup set', 'updraftplus');?>">
		<form id="updraft_delete_form" method="post">
			<p style="margin-top:3px; padding-top:0">
				<?php _e('Are you sure that you wish to remove this backup set from UpdraftPlus?', 'updraftplus'); ?>
			</p>
			<fieldset>
				<input type="hidden" name="nonce" value="<?php echo wp_create_nonce('updraftplus-credentialtest-nonce');?>">
				<input type="hidden" name="action" value="updraft_ajax">
				<input type="hidden" name="subaction" value="deleteset">
				<input type="hidden" name="backup_timestamp" value="0" id="updraft_delete_timestamp">
				<input type="hidden" name="backup_nonce" value="0" id="updraft_delete_nonce">
				<div id="updraft-delete-remote-section"><input checked="checked" type="checkbox" name="delete_remote" id="updraft_delete_remote" value="1"> <label for="updraft_delete_remote"><?php _e('Also delete from remote storage', 'updraftplus');?></label><br>
				<p id="updraft-delete-waitwarning" style="display:none;"><em><?php _e('Deleting... please allow time for the communications with the remote storage to complete.', 'updraftplus');?></em></p>
				</div>
			</fieldset>
		</form>
		</div>

		<div id="updraft-restore-modal" title="UpdraftPlus - <?php _e('Restore backup','updraftplus');?>">
		<p><strong><?php _e('Restore backup from','updraftplus');?>:</strong> <span class="updraft_restore_date"></span></p>

		<div id="updraft-restore-modal-stage2">

			<p><strong><?php _e('Retrieving (if necessary) and preparing backup files...', 'updraftplus');?></strong></p>
			<div id="ud_downloadstatus2"></div>

			<div id="updraft-restore-modal-stage2a"></div>

		</div>

		<div id="updraft-restore-modal-stage1">
		<p><?php _e("Restoring will replace this site's themes, plugins, uploads, database and/or other content directories (according to what is contained in the backup set, and your selection).",'updraftplus');?> <?php _e('Choose the components to restore','updraftplus');?>:</p>
		<form id="updraft_restore_form" method="post">
			<fieldset>
				<input type="hidden" name="action" value="updraft_restore">
				<input type="hidden" name="backup_timestamp" value="0" id="updraft_restore_timestamp">
				<input type="hidden" name="meta_foreign" value="0" id="updraft_restore_meta_foreign">
				<?php

				# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
				if($updraftplus->detect_safe_mode()) {
					echo "<p><em>".__('Your web server has PHP\'s so-called safe_mode active.','updraftplus').' '.__('This makes time-outs much more likely. You are recommended to turn safe_mode off, or to restore only one entity at a time, <a href="http://updraftplus.com/faqs/i-want-to-restore-but-have-either-cannot-or-have-failed-to-do-so-from-the-wp-admin-console/">or to restore manually</a>.', 'updraftplus')."</em></p><br/>";
				}

					$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);
					foreach ($backupable_entities as $type => $info) {
						if (!isset($info['restorable']) || $info['restorable'] == true) {
							echo '<div><input id="updraft_restore_'.$type.'" type="checkbox" name="updraft_restore[]" value="'.$type.'"> <label id="updraft_restore_label_'.$type.'" for="updraft_restore_'.$type.'">'.$info['description'].'</label><br>';

							do_action("updraftplus_restore_form_$type");

							echo '</div>';
						} else {
							$sdescrip = isset($info['shortdescription']) ? $info['shortdescription'] : $info['description'];
							echo "<div style=\"margin: 8px 0;\"><em>".htmlspecialchars(sprintf(__('The following entity cannot be restored automatically: "%s".', 'updraftplus'), $sdescrip))." ".__('You will need to restore it manually.', 'updraftplus')."</em><br>".'<input id="updraft_restore_'.$type.'" type="hidden" name="updraft_restore[]" value="'.$type.'">';
							echo '</div>';
						}
					}
				?>
				<div><input id="updraft_restore_db" type="checkbox" name="updraft_restore[]" value="db"> <label for="updraft_restore_db"><?php _e('Database','updraftplus'); ?></label><br>

					<div id="updraft_restorer_dboptions" style="display:none; padding:12px; margin: 8px 0 4px; border: dashed 1px;"><h4 style="margin: 0px 0px 6px; padding:0px;"><?php echo sprintf(__('%s restoration options:','updraftplus'),__('Database','updraftplus')); ?></h4>

					<?php

					do_action("updraftplus_restore_form_db");

					if (!class_exists('UpdraftPlus_Addons_Migrator')) {

						echo '<a href="http://updraftplus.com/faqs/tell-me-more-about-the-search-and-replace-site-location-in-the-database-option/">'.__('You can search and replace your database (for migrating a website to a new location/URL) with the Migrator add-on - follow this link for more information','updraftplus').'</a>';

					}

					?>

					</div>

				</div>
			</fieldset>
		</form>
		<p><em><a href="http://updraftplus.com/faqs/what-should-i-understand-before-undertaking-a-restoration/" target="_new"><?php _e('Do read this helpful article of useful things to know before restoring.','updraftplus');?></a></em></p>
		</div>

		</div>

	<?php
	}

	public function settings_debugrow($head, $content) {
		echo "<tr class=\"updraft_debugrow\"><th style=\"vertical-align: top; padding-top: 6px;\">$head</th><td>$content</td></tr>";
	}

	private function settings_expertsettings($backup_disabled) {
		global $updraftplus, $wpdb;
		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);
		?>
			<div class="expertmode">
				<p style="font-size:125%;"><em><?php _e('Unless you have a problem, you can completely ignore everything here.', 'updraftplus');?></em></p>
				<table>
				<?php

				$this->settings_debugrow(__('Web server:','updraftplus'), htmlspecialchars($_SERVER["SERVER_SOFTWARE"]).' ('.htmlspecialchars(php_uname()).')');

				$this->settings_debugrow('ABSPATH:', htmlspecialchars(ABSPATH));
				$this->settings_debugrow('WP_CONTENT_DIR:', htmlspecialchars(WP_CONTENT_DIR));
				$this->settings_debugrow('WP_PLUGIN_DIR:', htmlspecialchars(WP_PLUGIN_DIR));
				$this->settings_debugrow('Table prefix:', htmlspecialchars($updraftplus->get_table_prefix()));
				$peak_memory_usage = memory_get_peak_usage(true)/1024/1024;
				$memory_usage = memory_get_usage(true)/1024/1024;
				$this->settings_debugrow(__('Peak memory usage','updraftplus').':', $peak_memory_usage.' MB');
				$this->settings_debugrow(__('Current memory usage','updraftplus').':', $memory_usage.' MB');
				$this->settings_debugrow(__('Memory limit', 'updraftplus').':', htmlspecialchars(ini_get('memory_limit')));
				$this->settings_debugrow(sprintf(__('%s version:','updraftplus'), 'PHP'), htmlspecialchars(phpversion()).' - <a href="admin-ajax.php?page=updraftplus&action=updraft_ajax&subaction=phpinfo&nonce='.wp_create_nonce('updraftplus-credentialtest-nonce').'" id="updraftplus-phpinfo">'.__('show PHP information (phpinfo)', 'updraftplus').'</a>');
				$this->settings_debugrow(sprintf(__('%s version:','updraftplus'), 'MySQL'), htmlspecialchars($wpdb->db_version()));
				if (function_exists('curl_version') && function_exists('curl_exec')) {
					$cv = curl_version();
					$cvs = $cv['version'].' / SSL: '.$cv['ssl_version'].' / libz: '.$cv['libz_version'];
				} else {
					$cvs = '-';
				}
				$this->settings_debugrow(sprintf(__('%s version:','updraftplus'), 'Curl'), htmlspecialchars($cvs));
				if (version_compare(phpversion(), '5.2.0', '>=') && extension_loaded('zip')) {
					$ziparchive_exists = __('Yes', 'updraftplus');
				} else {
					# First do class_exists, because method_exists still sometimes segfaults due to a rare PHP bug
					$ziparchive_exists = (class_exists('ZipArchive') && method_exists('ZipArchive', 'addFile')) ? __('Yes', 'updraftplus') : __('No', 'updraftplus');
				}
				$this->settings_debugrow('ZipArchive::addFile:', $ziparchive_exists);
				$binzip = $updraftplus->find_working_bin_zip(false, false);
				$this->settings_debugrow(__('zip executable found:', 'updraftplus'), ((is_string($binzip)) ? __('Yes').': '.$binzip : __('No')));
				$hosting_bytes_free = $updraftplus->get_hosting_disk_quota_free();
				if (is_array($hosting_bytes_free)) {
					$perc = round(100*$hosting_bytes_free[1]/(max($hosting_bytes_free[2], 1)), 1);
					$this->settings_debugrow(__('Free disk space in account:', 'updraftplus'), sprintf(__('%s (%s used)', 'updraftplus'), round($hosting_bytes_free[3]/1048576, 1)." Mb", "$perc %"));
				}
				
				$this->settings_debugrow(__('Plugins for debugging:', 'updraftplus'),'<a href="'.wp_nonce_url(self_admin_url('update.php?action=install-plugin&updraftplus_noautobackup=1&plugin=wp-crontrol'), 'install-plugin_wp-crontrol').'">WP Crontrol</a> | <a href="'.wp_nonce_url(self_admin_url('update.php?action=install-plugin&updraftplus_noautobackup=1&plugin=sql-executioner'), 'install-plugin_sql-executioner').'">SQL Executioner</a> | <a href="'.wp_nonce_url(self_admin_url('update.php?action=install-plugin&updraftplus_noautobackup=1&plugin=advanced-code-editor'), 'install-plugin_advanced-code-editor').'">Advanced Code Editor</a> '.(current_user_can('edit_plugins') ? '<a href="'.self_admin_url('plugin-editor.php?file=updraftplus/updraftplus.php').'">(edit UpdraftPlus)</a>' : '').' | <a href="'.wp_nonce_url(self_admin_url('update.php?action=install-plugin&updraftplus_noautobackup=1&plugin=wp-filemanager'), 'install-plugin_wp-filemanager').'">WP Filemanager</a>');

				$this->settings_debugrow("HTTP Get: ", '<input id="updraftplus_httpget_uri" type="text" style="width: 300px; height: 22px;"> <a href="#" id="updraftplus_httpget_go">'.__('Fetch', 'updraftplus').'</a> <a href="#" id="updraftplus_httpget_gocurl">'.__('Fetch', 'updraftplus').' (Curl)</a><p id="updraftplus_httpget_results"></p>');

				$this->settings_debugrow("Call WordPress action:", '<input id="updraftplus_callwpaction" type="text" style="width: 300px; height: 22px;"> <a href="#" id="updraftplus_callwpaction_go">'.__('Call', 'updraftplus').'</a><div id="updraftplus_callwpaction_results"></div>');

				$this->settings_debugrow('', '<a href="admin-ajax.php?page=updraftplus&action=updraft_ajax&subaction=backuphistoryraw&nonce='.wp_create_nonce('updraftplus-credentialtest-nonce').'" id="updraftplus-rawbackuphistory">'.__('Show raw backup and file list', 'updraftplus').'</a>');

				echo '</table>';

				do_action('updraftplus_debugtools_dashboard');

				if (!class_exists('UpdraftPlus_Addon_LockAdmin')) {
					echo '<p style="clear: left; max-width: 600px;"><a href="https://updraftplus.com/shop/updraftplus-premium/"><em>'.__('For the ability to lock access to UpdraftPlus settings with a password, upgrade to UpdraftPlus Premium.', 'updraftplus').'</em></a></p>';
				}

				echo '<h3>'.__('Total (uncompressed) on-disk data:','updraftplus').'</h3>';
				echo '<p style="clear: left; max-width: 600px;"><em>'.__('N.B. This count is based upon what was, or was not, excluded the last time you saved the options.', 'updraftplus').'</em></p><table>';

				foreach ($backupable_entities as $key => $info) {

					$sdescrip = preg_replace('/ \(.*\)$/', '', $info['description']);
					if (strlen($sdescrip) > 20 && isset($info['shortdescription'])) $sdescrip = $info['shortdescription'];

// 					echo '<div style="clear: left;float:left; width:150px;">'.ucfirst($sdescrip).':</strong></div><div style="float:left;"><span id="updraft_diskspaceused_'.$key.'"><em></em></span> <a href="#" onclick="updraftplus_diskspace_entity(\''.$key.'\'); return false;">'.__('count','updraftplus').'</a></div>';
					$this->settings_debugrow(ucfirst($sdescrip).':', '<span id="updraft_diskspaceused_'.$key.'"><em></em></span> <a href="#" onclick="updraftplus_diskspace_entity(\''.$key.'\'); return false;">'.__('count','updraftplus').'</a>');
				}

				?>

				</table></p>
				<p style="clear: left; padding-top: 20px; max-width: 600px; margin:0;"><?php _e('The buttons below will immediately execute a backup run, independently of WordPress\'s scheduler. If these work whilst your scheduled backups do absolutely nothing (i.e. not even produce a log file), then it means that your scheduler is broken.','updraftplus');?> <a href="http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/"><?php _e('Go here for more information.', 'updraftplus'); ?></a></p>

				<table border="0" style="border: none;">
				<tbody>
				<tr>
				<td>
				<form method="post" action="<?php echo add_query_arg(array('updraft_restore_success' => false, 'action' => false, 'page' => 'updraftplus')); ?>">
					<input type="hidden" name="action" value="updraft_backup_debug_all" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="<?php _e('Debug Full Backup','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will cause an immediate backup. The page will stall loading until it finishes (ie, unscheduled).','updraftplus'));?>'))" /></p>
				</form>
				</td><td>
				<form method="post" action="<?php echo add_query_arg(array('updraft_restore_success' => false, 'action' => false, 'page' => 'updraftplus')); ?>">
					<input type="hidden" name="action" value="updraft_backup_debug_db" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="<?php _e('Debug Database Backup','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will cause an immediate DB backup. The page will stall loading until it finishes (ie, unscheduled). The backup may well run out of time; really this button is only helpful for checking that the backup is able to get through the initial stages, or for small WordPress sites..','updraftplus'));?>'))" /></p>
				</form>
				</td>
				</tr>
				</tbody>
				</table>
				<h3><?php _e('Wipe Settings','updraftplus');?></h3>
				<p style="max-width: 600px;"><?php _e('This button will delete all UpdraftPlus settings (but not any of your existing backups from your cloud storage). You will then need to enter all your settings again. You can also do this before deactivating/deinstalling UpdraftPlus if you wish.','updraftplus');?></p>
				<form method="post" action="<?php echo add_query_arg(array('updraft_restore_success' => false, 'action' => false, 'page' => 'updraftplus')); ?>">
					<input type="hidden" name="action" value="updraft_wipesettings" />
					<p><input type="submit" class="button-primary" value="<?php _e('Wipe All Settings','updraftplus'); ?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will delete all your UpdraftPlus settings - are you sure you want to do this?'));?>'))" /></p>
				</form>
			</div>
		<?php
	}

	private function print_delete_old_dirs_form($include_blurb = true) {
		?>
			<?php if ($include_blurb) {
			?>
			<div id="updraft_delete_old_dirs_pagediv" class="updated" style="padding:8px;"><p> <?php _e('Your WordPress install has old directories from its state before you restored/migrated (technical information: these are suffixed with -old). You should press this button to delete them as soon as you have verified that the restoration worked.','updraftplus');?></p><?php } ?>
			<form method="post" onsubmit="return updraft_delete_old_dirs();" action="<?php echo add_query_arg(array('updraft_restore_success' => false, 'action' => false, 'page' => 'updraftplus')); ?>">
				<?php wp_nonce_field('updraftplus-credentialtest-nonce'); ?>
				<input type="hidden" name="action" value="updraft_delete_old_dirs">
				<input type="submit" class="button-primary" value="<?php echo esc_attr(__('Delete Old Directories', 'updraftplus'));?>"  />
			</form>
		<?php
			if ($include_blurb) echo '</div>';
		}


	private function print_active_jobs() {
		$cron = get_option('cron');
		if (!is_array($cron)) $cron = array();
// 		$found_jobs = 0;
		$ret = '';

		foreach ($cron as $time => $job) {
			if (isset($job['updraft_backup_resume'])) {
				foreach ($job['updraft_backup_resume'] as $hook => $info) {
					if (isset($info['args'][1])) {
// 						$found_jobs++;
						$job_id = $info['args'][1];
						$ret .= $this->print_active_job($job_id, false, $time, $info['args'][0]);
					}
				}
			}
		}

// 		if (0 == $found_jobs) $ret .= '<p><em>'.__('(None)', 'updraftplus').'</em></p>';
		return $ret;
	}

	private function print_active_job($job_id, $is_oneshot = false, $time = false, $next_resumption = false) {

		$ret = '';

		global $updraftplus;

		$jobdata = $updraftplus->jobdata_getarray($job_id);
		if (false == apply_filters('updraftplus_print_active_job_continue', true, $is_oneshot, $next_resumption, $jobdata)) return '';

		#if (!is_array($jobdata)) $jobdata = array();
		if (!isset($jobdata['backup_time'])) return '';

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		$began_at = (isset($jobdata['backup_time'])) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', (int)$jobdata['backup_time']), 'D, F j, Y H:i') : '?';

		$jobstatus = empty($jobdata['jobstatus']) ? 'unknown' : $jobdata['jobstatus'];
		$stage = 0;
		switch ($jobstatus) {
			# Stage 0
			case 'begun':
			$curstage = __('Backup begun', 'updraftplus');
			break;
			# Stage 1
			case 'filescreating':
			$stage = 1;
			$curstage = __('Creating file backup zips', 'updraftplus');
			if (!empty($jobdata['filecreating_substatus']) && isset($backupable_entities[$jobdata['filecreating_substatus']['e']]['description'])) {
			
				$sdescrip = preg_replace('/ \(.*\)$/', '', $backupable_entities[$jobdata['filecreating_substatus']['e']]['description']);
				if (strlen($sdescrip) > 20 && isset($jobdata['filecreating_substatus']['e']) && is_array($jobdata['filecreating_substatus']['e']) && isset($backupable_entities[$jobdata['filecreating_substatus']['e']]['shortdescription'])) $sdescrip = $backupable_entities[$jobdata['filecreating_substatus']['e']]['shortdescription'];
				$curstage .= ' ('.$sdescrip.')';
				if (isset($jobdata['filecreating_substatus']['i']) && isset($jobdata['filecreating_substatus']['t'])) {
					$stage = min(2, 1 + ($jobdata['filecreating_substatus']['i']/max($jobdata['filecreating_substatus']['t'],1)));
				}
			}
			break;
			case 'filescreated':
			$stage = 2;
			$curstage = __('Created file backup zips', 'updraftplus');
			break;

			# Stage 4
			case 'clouduploading':
			$stage = 4;
			$curstage = __('Uploading files to remote storage', 'updraftplus');
			if (isset($jobdata['uploading_substatus']['t']) && isset($jobdata['uploading_substatus']['i'])) {
				$t = max((int)$jobdata['uploading_substatus']['t'], 1);
				$i = min($jobdata['uploading_substatus']['i']/$t, 1);
				$p = min($jobdata['uploading_substatus']['p'], 1);
				$pd = $i + $p/$t;
				$stage = 4 + $pd;
				$curstage .= ' '.sprintf(__('(%s%%, file %s of %s)', 'updraftplus'), floor(100*$pd), $jobdata['uploading_substatus']['i']+1, $t);
			}
			break;
			case 'pruning':
			$stage = 5;
			$curstage = __('Pruning old backup sets', 'updraftplus');
			break;
			case 'resumingforerrors':
			$stage = -1;
			$curstage = __('Waiting until scheduled time to retry because of errors', 'updraftplus');
			break;
			# Stage 6
			case 'finished':
			$stage = 6;
			$curstage = __('Backup finished', 'updraftplus');
			break;
			default:

			# Database creation and encryption occupies the space from 2 to 4. Databases are created then encrypted, then the next databae is created/encrypted, etc.
			if ('dbcreated' == substr($jobstatus, 0, 9)) {
				$jobstatus = 'dbcreated';
				$whichdb = substr($jobstatus, 9);
				if (!is_numeric($whichdb)) $whichdb = 0;
				$howmanydbs = max((empty($jobdata['backup_database']) || !is_array($jobdata['backup_database'])) ? 1 : count($jobdata['backup_database']), 1);
				$perdbspace = 2/$howmanydbs;

				$stage = min(4, 2 + ($whichdb+2)*$perdbspace);

				$curstage = __('Created database backup', 'updraftplus');

			} elseif ('dbcreating' == substr($jobstatus, 0, 10)) {
				$whichdb = substr($jobstatus, 10);
				if (!is_numeric($whichdb)) $whichdb = 0;
				$howmanydbs = (empty($jobdata['backup_database']) || !is_array($jobdata['backup_database'])) ? 1 : count($jobdata['backup_database']);
				$perdbspace = 2/$howmanydbs;
				$jobstatus = 'dbcreating';

				$stage = min(4, 2 + $whichdb*$perdbspace);

				$curstage = __('Creating database backup', 'updraftplus');
				if (!empty($jobdata['dbcreating_substatus']['t'])) {
					$curstage .= ' ('.sprintf(__('table: %s', 'updraftplus'), $jobdata['dbcreating_substatus']['t']).')';
					if (!empty($jobdata['dbcreating_substatus']['i']) && !empty($jobdata['dbcreating_substatus']['a'])) {
						$substage = max(0.001, ($jobdata['dbcreating_substatus']['i'] / max($jobdata['dbcreating_substatus']['a'],1)));
						$stage += $substage * $perdbspace * 0.5;
					}
				}
			} elseif ('dbencrypting' == substr($jobstatus, 0, 12)) {
				$whichdb = substr($jobstatus, 12);
				if (!is_numeric($whichdb)) $whichdb = 0;
				$howmanydbs = (empty($jobdata['backup_database']) || !is_array($jobdata['backup_database'])) ? 1 : count($jobdata['backup_database']);
				$perdbspace = 2/$howmanydbs;
				$stage = min(4, 2 + $whichdb*$perdbspace + $perdbspace*0.5);
				$jobstatus = 'dbencrypting';
				$curstage = __('Encrypting database', 'updraftplus');
			} elseif ('dbencrypted' == substr($jobstatus, 0, 11)) {
				$whichdb = substr($jobstatus, 11);
				if (!is_numeric($whichdb)) $whichdb = 0;
				$howmanydbs = (empty($jobdata['backup_database']) || !is_array($jobdata['backup_database'])) ? 1 : count($jobdata['backup_database']);
				$jobstatus = 'dbencrypted';
				$perdbspace = 2/$howmanydbs;
				$stage = min(4, 2 + $whichdb*$perdbspace + $perdbspace);
				$curstage = __('Encrypted database', 'updraftplus');
			} else {
				$curstage = __('Unknown', 'updraftplus');
			}
		}

		$runs_started = (empty($jobdata['runs_started'])) ? array() : $jobdata['runs_started'];
		$time_passed = (empty($jobdata['run_times'])) ? array() : $jobdata['run_times'];
		$last_checkin_ago = -1;
		if (is_array($time_passed)) {
			foreach ($time_passed as $run => $passed) {
				if (isset($runs_started[$run])) {
					$time_ago = microtime(true) - ($runs_started[$run] + $time_passed[$run]);
					if ($time_ago < $last_checkin_ago || $last_checkin_ago == -1) $last_checkin_ago = $time_ago;
				}
			}
		}

		$next_res_after = $time-time();
		$next_res_txt = ($is_oneshot) ? '' : ' - '.sprintf(__("next resumption: %d (after %ss)", 'updraftplus'), $next_resumption, $next_res_after). ' ';
		$last_activity_txt = ($last_checkin_ago >= 0) ? ' - '.sprintf(__('last activity: %ss ago', 'updraftplus'), floor($last_checkin_ago)).' ' : '';

		if (($last_checkin_ago < 50 && $next_res_after>30) || $is_oneshot) {
			$show_inline_info = $last_activity_txt;
			$title_info = $next_res_txt;
		} else {
			$show_inline_info = $next_res_txt;
			$title_info = $last_activity_txt;
		}

		$ret .= '<div style="min-width: 480px; margin-top: 4px; clear:left; float:left; padding: 8px; border: 1px solid;" id="updraft-jobid-'.$job_id.'"><span style="font-weight:bold;" title="'.esc_attr(sprintf(__('Job ID: %s', 'updraftplus'), $job_id)).$title_info.'">'.$began_at.'</span> ';

		$ret .= $show_inline_info;
		$ret .= '- <a href="?page=updraftplus&action=downloadlog&updraftplus_backup_nonce='.$job_id.'" class="updraft-log-link" onclick="event.preventDefault(); updraft_popuplog(\''.$job_id.'\');">'.__('show log', 'updraftplus').'</a>';

		if (!$is_oneshot) $ret .=' - <a title="'.esc_attr(__('Note: the progress bar below is based on stages, NOT time. Do not stop the backup simply because it seems to have remained in the same place for a while - that is normal.', 'updraftplus')).'" href="javascript:updraft_activejobs_delete(\''.$job_id.'\')">'.__('delete schedule', 'updraftplus').'</a>';

		if (!empty($jobdata['warnings']) && is_array($jobdata['warnings'])) {
			$ret .= '<ul style="list-style: disc inside;">';
			foreach ($jobdata['warnings'] as $warning) {
				$ret .= '<li>'.sprintf(__('Warning: %s', 'updraftplus'), make_clickable(htmlspecialchars($warning))).'</li>';
			}
			$ret .= '</ul>';
		}

		$ret .= '<div style="border-radius: 4px; margin-top: 8px; padding-top: 4px;border: 1px solid #aaa; width: 100%; height: 22px; position: relative; text-align: center; font-style: italic;">';
		$ret .= htmlspecialchars($curstage);
		$ret .= '<div style="z-index:-1; position: absolute; left: 0px; top: 0px; text-align: center; background-color: #f6a828; height: 100%; width:'.(($stage>0) ? (ceil((100/6)*$stage)) : '0').'%"></div>';
		$ret .= '</div></div>';

		$ret .= '</div>';

		return $ret;

	}

	private function delete_old_dirs_go($show_return = true) {
		echo ($show_return) ? '<h1>UpdraftPlus - '.__('Remove old directories', 'updraftplus').'</h1>' : '<h2>'.__('Remove old directories', 'updraftplus').'</h2>';

		if($this->delete_old_dirs()) {
			echo '<p>'.__('Old directories successfully removed.','updraftplus').'</p><br/>';
		} else {
			echo '<p>',__('Old directory removal failed for some reason. You may want to do this manually.','updraftplus').'</p><br/>';
		}
		if ($show_return) echo '<b>'.__('Actions','updraftplus').':</b> <a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
	}

	//deletes the -old directories that are created when a backup is restored.
	private function delete_old_dirs() {
		global $wp_filesystem, $updraftplus;
		$credentials = request_filesystem_credentials(wp_nonce_url(UpdraftPlus_Options::admin_page_url()."?page=updraftplus&action=updraft_delete_old_dirs", 'updraftplus-credentialtest-nonce')); 
		WP_Filesystem($credentials);
		if ($wp_filesystem->errors->get_error_code()) { 
			foreach ($wp_filesystem->errors->get_error_messages() as $message)
				show_message($message); 
			exit; 
		}
		// From WP_CONTENT_DIR - which contains 'themes'
		$ret = $this->delete_old_dirs_dir($wp_filesystem->wp_content_dir());

		$updraft_dir = $updraftplus->backups_dir_location();
		if ($updraft_dir) {
			$ret4 = ($updraft_dir) ? $this->delete_old_dirs_dir($updraft_dir, false) : true;
		} else {
			$ret4 = true;
		}

// 		$ret2 = $this->delete_old_dirs_dir($wp_filesystem->abspath());
		$plugs = untrailingslashit($wp_filesystem->wp_plugins_dir());
		if ($wp_filesystem->is_dir($plugs.'-old')) {
			print "<strong>".__('Delete','updraftplus').": </strong>plugins-old: ";
			if(!$wp_filesystem->delete($plugs.'-old', true)) {
				$ret3 = false;
				print "<strong>".__('Failed', 'updraftplus')."</strong><br>";
			} else {
				$ret3 = true;
				print "<strong>".__('OK', 'updraftplus')."</strong><br>";
			}
		} else {
			$ret3 = true;
		}

		return $ret && $ret3 && $ret4;
	}

	private function delete_old_dirs_dir($dir, $wpfs = true) {

		$dir = trailingslashit($dir);

		global $wp_filesystem, $updraftplus;

		if ($wpfs) {
			$list = $wp_filesystem->dirlist($dir);
		} else {
			$list = scandir($dir);
		}
		if (!is_array($list)) return false;

		$ret = true;
		foreach ($list as $item) {
			$name = (is_array($item)) ? $item['name'] : $item;
			if ("-old" == substr($name, -4, 4)) {
				//recursively delete
				print "<strong>".__('Delete','updraftplus').": </strong>".htmlspecialchars($name).": ";

				if ($wpfs) {
					if(!$wp_filesystem->delete($dir.$name, true)) {
						$ret = false;
						echo "<strong>".__('Failed', 'updraftplus')."</strong><br>";
					} else {
						echo "<strong>".__('OK', 'updraftplus')."</strong><br>";
					}
				} else {
					if ($updraftplus->remove_local_directory($dir.$name)) {
						echo "<strong>".__('OK', 'updraftplus')."</strong><br>";
					} else {
						$ret = false;
						echo "<strong>".__('Failed', 'updraftplus')."</strong><br>";
					}
				}
			}
		}
		return $ret;
	}

	// The aim is to get a directory that is writable by the webserver, because that's the only way we can create zip files
	private function create_backup_dir() {

		global $wp_filesystem, $updraftplus;

		if (false === ($credentials = request_filesystem_credentials(UpdraftPlus_Options::admin_page().'?page=updraftplus&action=updraft_create_backup_dir&nonce='.wp_create_nonce('create_backup_dir')))) {
			return false;
		}

		if ( ! WP_Filesystem($credentials) ) {
			// our credentials were no good, ask the user for them again
			request_filesystem_credentials(UpdraftPlus_Options::admin_page().'?page=updraftplus&action=updraft_create_backup_dir&nonce='.wp_create_nonce('create_backup_dir'), '', true);
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();

		$default_backup_dir = $wp_filesystem->find_folder(dirname($updraft_dir)).basename($updraft_dir);

		$updraft_dir = ($updraft_dir) ? $wp_filesystem->find_folder(dirname($updraft_dir)).basename($updraft_dir) : $default_backup_dir;

		if (!$wp_filesystem->is_dir($default_backup_dir) && !$wp_filesystem->mkdir($default_backup_dir, 0775)) {
			$wperr = new WP_Error;
			if ( $wp_filesystem->errors->get_error_code() ) { 
				foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
					$wperr->add('mkdir_error', $message);
				}
				return $wperr; 
			} else {
				return new WP_Error('mkdir_error', __('The request to the filesystem to create the directory failed.', 'updraftplus'));
			}
		}

		if ($wp_filesystem->is_dir($default_backup_dir)) {

			if ($updraftplus->really_is_writable($updraft_dir)) return true;

			@$wp_filesystem->chmod($default_backup_dir, 0775);
			if ($updraftplus->really_is_writable($updraft_dir)) return true;

			@$wp_filesystem->chmod($default_backup_dir, 0777);

			if ($updraftplus->really_is_writable($updraft_dir)) {
				echo '<p>'.__('The folder was created, but we had to change its file permissions to 777 (world-writable) to be able to write to it. You should check with your hosting provider that this will not cause any problems', 'updraftplus').'</p>';
				return true;
			} else {
				@$wp_filesystem->chmod($default_backup_dir, 0775);
				$show_dir = (0 === strpos($default_backup_dir, ABSPATH)) ? substr($default_backup_dir, strlen(ABSPATH)) : $default_backup_dir;
				return new WP_Error('writable_error', __('The folder exists, but your webserver does not have permission to write to it.', 'updraftplus').' '.__('You will need to consult with your web hosting provider to find out how to set permissions for a WordPress plugin to write to the directory.', 'updraftplus').' ('.$show_dir.')');
			}
		}

		return true;
	}

	//scans the content dir to see if any -old dirs are present
	private function scan_old_dirs($print_as_comment = false) {
		global $updraftplus;
		$dirs = scandir(untrailingslashit(WP_CONTENT_DIR));
		if (!is_array($dirs)) $dirs = array();
		$dirs_u = @scandir($updraftplus->backups_dir_location());
		if (!is_array($dirs_u)) $dirs_u = array();
		foreach (array_merge($dirs, $dirs_u) as $dir) {
			if (preg_match('/-old$/', $dir)) {
				if ($print_as_comment) echo '<!--'.htmlspecialchars($dir).'-->';
				return true;
			}
		}
		# No need to scan ABSPATH - we don't backup there
		if (is_dir(untrailingslashit(WP_PLUGIN_DIR).'-old')) {
			if ($print_as_comment) echo '<!--'.htmlspecialchars(untrailingslashit(WP_PLUGIN_DIR).'-old').'-->';
			return true;
		}
		return false;
	}

	public function storagemethod_row($method, $header, $contents) {
		?>
			<tr class="updraftplusmethod <?php echo $method;?>">
				<th><?php echo $header;?></th>
				<td><?php echo $contents;?></td>
			</tr>
		<?php
	}

	private function last_backup_html() {

		global $updraftplus;

		$updraft_last_backup = UpdraftPlus_Options::get_updraft_option('updraft_last_backup');

		if ($updraft_last_backup) {

			// Convert to GMT, then to blog time
			$backup_time = (int)$updraft_last_backup['backup_time'];

			$print_time = get_date_from_gmt(gmdate('Y-m-d H:i:s', $backup_time), 'D, F j, Y H:i');

			if (empty($updraft_last_backup['backup_time_incremental'])) {
				$last_backup_text = "<span style=\"color:".(($updraft_last_backup['success']) ? 'green' : 'black').";\">".$print_time.'</span>';
			} else {
				$inc_time = get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraft_last_backup['backup_time_incremental']), 'D, F j, Y H:i');
				$last_backup_text = "<span style=\"color:".(($updraft_last_backup['success']) ? 'green' : 'black').";\">$inc_time</span> (".sprintf(__('incremental backup; base backup: %s', 'updraftplus'), $print_time).')';
			}

			$last_backup_text .= '<br>';

			// Show errors + warnings
			if (is_array($updraft_last_backup['errors'])) {
				foreach ($updraft_last_backup['errors'] as $err) {
					$level = (is_array($err)) ? $err['level'] : 'error';
					$message = (is_array($err)) ? $err['message'] : $err;
					$last_backup_text .= ('warning' == $level) ? "<span style=\"color:orange;\">" : "<span style=\"color:red;\">";
					if ('warning' == $level) {
						$message = sprintf(__("Warning: %s", 'updraftplus'), make_clickable(htmlspecialchars($message)));
					} else {
						$message = htmlspecialchars($message);
					}
					$last_backup_text .= $message;
					$last_backup_text .= '</span><br>';
				}
			}

			// Link log
			if (!empty($updraft_last_backup['backup_nonce'])) {
				$updraft_dir = $updraftplus->backups_dir_location();

				$potential_log_file = $updraft_dir."/log.".$updraft_last_backup['backup_nonce'].".txt";
				if (is_readable($potential_log_file)) $last_backup_text .= "<a href=\"?page=updraftplus&action=downloadlog&updraftplus_backup_nonce=".$updraft_last_backup['backup_nonce']."\" class=\"updraft-log-link\" onclick=\"event.preventDefault(); updraft_popuplog('".$updraft_last_backup['backup_nonce']."');\">".__('Download log file','updraftplus')."</a>";
			}

		} else {
			$last_backup_text =  "<span style=\"color:blue;\">".__('No backup has been completed.','updraftplus')."</span>";
		}

		return $last_backup_text;

	}

	public function get_intervals() {
		return apply_filters('updraftplus_backup_intervals', array(
			"manual" => _x("Manual", 'i.e. Non-automatic', 'updraftplus'),
			'every4hours' => sprintf(__("Every %s hours", 'updraftplus'), '4'),
			'every8hours' => sprintf(__("Every %s hours", 'updraftplus'), '8'),
			'twicedaily' => sprintf(__("Every %s hours", 'updraftplus'), '12'),
			'daily' => __("Daily", 'updraftplus'),
			'weekly' => __("Weekly", 'updraftplus'),
			'fortnightly' => __("Fortnightly", 'updraftplus'),
			'monthly' => __("Monthly", 'updraftplus')
		));
	}

	private function settings_formcontents($last_backup_html) {

		global $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location();

		?>
			<table class="form-table">
			<tr>
				<th><?php _e('File backup intervals','updraftplus'); ?>:</th>
				<td><select id="updraft_interval" name="updraft_interval" onchange="jQuery(document).trigger('updraftplus_interval_changed'); updraft_check_same_times();">
					<?php
					$intervals = $this->get_intervals();
					$selected_interval = UpdraftPlus_Options::get_updraft_option('updraft_interval','manual');
					foreach ($intervals as $cronsched => $descrip) {
						echo "<option value=\"$cronsched\" ";
						if ($cronsched == $selected_interval) echo 'selected="selected"';
						echo ">".htmlspecialchars($descrip)."</option>\n";
					}
					?>
					</select> <span id="updraft_files_timings"><?php echo apply_filters('updraftplus_schedule_showfileopts', '<input type="hidden" name="updraftplus_starttime_files" value="">'); ?></span>
					<?php
					echo __('and retain this many scheduled backups', 'updraftplus').': ';
					$updraft_retain = (int)UpdraftPlus_Options::get_updraft_option('updraft_retain', 2);
					$updraft_retain = ($updraft_retain > 0) ? $updraft_retain : 1;
					?> <input type="number" min="1" step="1" name="updraft_retain" value="<?php echo $updraft_retain ?>" style="width:48px;" />
					</td>
			</tr>

			<?php if (defined('UPDRAFTPLUS_EXPERIMENTAL') && UPDRAFTPLUS_EXPERIMENTAL) { ?>
			<tr id="updraft_incremental_row">
				<th><?php _e('Incremental file backup intervals', 'updraftplus'); ?>:</th>
				<td>
					<?php do_action('updraftplus_incremental_cell', $selected_interval); ?>
					<a href="http://updraftplus.com/support/tell-me-more-about-incremental-backups/"><em><?php _e('Tell me more about incremental backups', 'updraftplus'); ?><em></a>
					</td>
			</tr>
			<?php } ?>

			<?php apply_filters('updraftplus_after_file_intervals', false, $selected_interval); ?>
			<tr>
				<th><?php _e('Database backup intervals','updraftplus'); ?>:</th>
				<td><select id="updraft_interval_database" name="updraft_interval_database" onchange="updraft_check_same_times();">
					<?php
					foreach ($intervals as $cronsched => $descrip) {
						echo "<option value=\"$cronsched\" ";
						if ($cronsched == UpdraftPlus_Options::get_updraft_option('updraft_interval_database', UpdraftPlus_Options::get_updraft_option('updraft_interval'))) echo 'selected="selected"';
						echo ">$descrip</option>\n";
					}
					?>
					</select> <span id="updraft_db_timings"><?php echo apply_filters('updraftplus_schedule_showdbopts', '<input type="hidden" name="updraftplus_starttime_db" value="">'); ?></span>
					<?php
					echo __('and retain this many scheduled backups', 'updraftplus').': ';
					$updraft_retain_db = (int)UpdraftPlus_Options::get_updraft_option('updraft_retain_db', $updraft_retain);
					$updraft_retain_db = ($updraft_retain_db > 0) ? $updraft_retain_db : 1;
					?> <input type="number" min="1" step="1" name="updraft_retain_db" value="<?php echo $updraft_retain_db ?>" style="width:48px" />
			</td>
			</tr>
			<tr class="backup-interval-description">
				<td></td><td><div style="max-width:670px;"><p><?php echo htmlspecialchars(__('If you would like to automatically schedule backups, choose schedules from the dropdowns above.', 'updraftplus').' '.__('If the two schedules are the same, then the two backups will take place together.', 'updraftplus')); ?></p>
				<?php echo apply_filters('updraftplus_fixtime_ftinfo', '<p><strong>'.__('To fix the time at which a backup should take place,','updraftplus').' </strong> ('.__('e.g. if your server is busy at day and you want to run overnight','updraftplus').'), <a href="http://updraftplus.com/shop/updraftplus-premium/">'.htmlspecialchars(__('use UpdraftPlus Premium', 'updraftplus')).'</a></p>'); ?>
				</div></td>
			</tr>
			<tr>
				<th><?php _e('Include in files backup', 'updraftplus');?>:</th>
				<td>

			<?php
				$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);
				# The true (default value if non-existent) here has the effect of forcing a default of on.
				foreach ($backupable_entities as $key => $info) {
					$included = (UpdraftPlus_Options::get_updraft_option("updraft_include_$key", apply_filters("updraftplus_defaultoption_include_".$key, true))) ? 'checked="checked"' : "";
					if ('others' == $key || 'uploads' == $key) {

						$include_exclude = UpdraftPlus_Options::get_updraft_option('updraft_include_'.$key.'_exclude', ('others' == $key) ? UPDRAFT_DEFAULT_OTHERS_EXCLUDE : UPDRAFT_DEFAULT_UPLOADS_EXCLUDE);

						?><input id="updraft_include_<?php echo $key; ?>" type="checkbox" name="updraft_include_<?php echo $key; ?>" value="1" <?php echo $included; ?> /> <label <?php if ('others' == $key) echo 'title="'.sprintf(__('Your wp-content directory server path: %s', 'updraftplus'), WP_CONTENT_DIR).'"';?> for="updraft_include_<?php echo $key ?>"><?php echo ('others' == $key) ? __('Any other directories found inside wp-content', 'updraftplus') : htmlspecialchars($info['description']);?></label><br><?php
						
						$display = ($included) ? '' : 'style="display:none;"';

						echo "<div id=\"updraft_include_".$key."_exclude\" $display>";

							echo '<label for="updraft_include_'.$key.'_exclude">'.__('Exclude these:', 'updraftplus').'</label>';

							echo '<input title="'.__('If entering multiple files/directories, then separate them with commas. For entities at the top level, you can use a * at the start or end of the entry as a wildcard.', 'updraftplus').'" type="text" id="updraft_include_'.$key.'_exclude" name="updraft_include_'.$key.'_exclude" size="54" value="'.htmlspecialchars($include_exclude).'" />';

							echo '<br>';

						echo '</div>';

					} else {
						echo "<input id=\"updraft_include_$key\" type=\"checkbox\" name=\"updraft_include_$key\" value=\"1\" $included /><label for=\"updraft_include_$key\"".((isset($info['htmltitle'])) ? ' title="'.htmlspecialchars($info['htmltitle']).'"' : '')."> ".htmlspecialchars($info['description'])."</label><br>";
						do_action("updraftplus_config_option_include_$key");
					}
				}
			?>
				<p><?php echo apply_filters('updraftplus_admin_directories_description', __('The above directories are everything, except for WordPress core itself which you can download afresh from WordPress.org.', 'updraftplus').' <a href="http://updraftplus.com/shop/">'.htmlspecialchars(__('See also the "More Files" add-on from our shop.', 'updraftplus'))); ?></a></p>
				<?php if (1==0 && !defined('UPDRAFTPLUS_NOADS_B')) echo '<p><a href="http://wordshell.net">('.__('Use WordShell for automatic backup, version control and patching', 'updraftplus').').</a></p>';?>
				</td>
			</tr>

			</table>

			<h2><?php _e('Database Options','updraftplus');?></h2>

			<table class="form-table" style="width:900px;">

			<tr>
				<th><?php _e('Database encryption phrase','updraftplus');?>:</th>

				<td>
				<?php
					echo apply_filters('updraft_database_encryption_config', '<a href="http://updraftplus.com/shop/updraftplus-premium/">'.__("Don't want to be spied on? UpdraftPlus Premium can encrypt your database backup.", 'updraftplus').'</a> '.__('It can also backup external databases.', 'updraftplus'));
				?>
				</td>
			</tr>
			<tr class="backup-crypt-description">
				<td></td>

				<td>

				<a href="#" onclick="jQuery('#updraftplus_db_decrypt').val(jQuery('#updraft_encryptionphrase').val()); jQuery('#updraft-manualdecrypt-modal').slideToggle(); return false;"><?php _e('You can manually decrypt an encrypted database here.','updraftplus');?></a>

				<div id="updraft-manualdecrypt-modal" style="width: 85%; margin: 6px; display:none; margin-left: 100px;">
					<p><h3><?php _e("Manually decrypt a database backup file" ,'updraftplus');?></h3></p>

					<?php
					global $wp_version;
					if (version_compare($wp_version, '3.3', '<')) {
						echo '<em>'.sprintf(__('This feature requires %s version %s or later', 'updraftplus'), 'WordPress', '3.3').'</em>';
					} else {
					?>

					<div id="plupload-upload-ui2" style="width: 80%;">
						<div id="drag-drop-area2">
							<div class="drag-drop-inside">
								<p class="drag-drop-info"><?php _e('Drop encrypted database files (db.gz.crypt files) here to upload them for decryption'); ?></p>
								<p><?php _ex('or', 'Uploader: Drop db.gz.crypt files here to upload them for decryption - or - Select Files'); ?></p>
								<p class="drag-drop-buttons"><input id="plupload-browse-button2" type="button" value="<?php esc_attr_e('Select Files'); ?>" class="button" /></p>
								<p style="margin-top: 18px;"><?php _e('First, enter the decryption key','updraftplus')?>: <input id="updraftplus_db_decrypt" type="text" size="12"></input></p>
							</div>
						</div>
						<div id="filelist2">
						</div>
					</div>

					<?php } ?>

				</div>


				</td>
			</tr>

			<?php
				#'<a href="http://updraftplus.com/shop/updraftplus-premium/">'.__("This feature is part of UpdraftPlus Premium.", 'updraftplus').'</a>'
				$moredbs_config = apply_filters('updraft_database_moredbs_config', false);
				if (!empty($moredbs_config)) {
			?>

			<tr>
				<th><?php _e('Back up more databases', 'updraftplus');?>:</th>

				<td><?php

					echo $moredbs_config;

					?>

				</td>
			</tr>

			<?php } ?>

			</table>

			<h2><?php _e('Reporting','updraftplus');?></h2>

			<table class="form-table" style="width:900px;">

			<?php
				$report_rows = apply_filters('updraftplus_report_form', false);
				if (is_string($report_rows)) {
					echo $report_rows;
				} else {
			?>

			<tr>
				<th><?php _e('Email', 'updraftplus'); ?>:</th>
				<td>
					<?php
						$updraft_email = UpdraftPlus_Options::get_updraft_option('updraft_email');
					?>
					<input type="checkbox" id="updraft_email" name="updraft_email" value="<?php esc_attr_e(get_bloginfo('admin_email')); ?>"<?php if (!empty($updraft_email)) echo ' checked="checked"';?> > <br><label for="updraft_email"><?php echo sprintf(__("Check this box to have a basic report sent to your site's admin address (%s).",'updraftplus'), htmlspecialchars(get_bloginfo('admin_email'))); ?></label>
					<?php
						if (!class_exists('UpdraftPlus_Addon_Reporting')) echo '<a href="http://updraftplus.com/shop/reporting/">'.__('For more reporting features, use the Reporting add-on.', 'updraftplus').'</a>';
					?>
				</td>
			</tr>

			<?php } ?>

			</table>

			<h2><?php _e('Copying Your Backup To Remote Storage','updraftplus');?></h2>

			<?php
				$debug_mode = (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) ? 'checked="checked"' : "";
				// Should be one of s3, dropbox, ftp, googledrive, email, or whatever else is added
				$active_service = UpdraftPlus_Options::get_updraft_option('updraft_service');
			?>

			<table class="form-table" style="width:900px;">
			<tr>
				<th><?php _e('Choose your remote storage','updraftplus');?>:</th>
				<td><?php

					if (false === apply_filters('updraftplus_storage_printoptions', false, $active_service)) {
						if (is_array($active_service)) $active_service = $updraftplus->just_one($active_service);
						?>

						<select name="updraft_service" id="updraft-service">
						<option value="none" <?php
							if ('none' === $active_service) echo ' selected="selected"'; ?>><?php _e('None','updraftplus'); ?></option>
						<?php
						foreach ($updraftplus->backup_methods as $method => $description) {
							echo "<option value=\"$method\"";
							if ($active_service === $method || (is_array($active_service) && in_array($method, $active_service))) echo ' selected="selected"';
							echo '>'.$description;
							echo "</option>\n";
						}
						?>
						</select>

						<?php echo '<p><a href="http://updraftplus.com/shop/morestorage/">'.htmlspecialchars(__('You can send a backup to more than one destination with an add-on.','updraftplus')).'</a></p>'; ?>

						</td>
					</tr>

					<?php } ?>

					<tr class="updraftplusmethod none" style="display:none;">
						<td></td>
						<td><em><?php echo htmlspecialchars(__('If you choose no remote storage, then the backups remain on the web-server. This is not recommended (unless you plan to manually copy them to your computer), as losing the web-server would mean losing both your website and the backups in one event.', 'updraftplus'));?></em></td>
					</tr>

					<?php
						$method_objects = array();
						foreach ($updraftplus->backup_methods as $method => $description) {
							do_action('updraftplus_config_print_before_storage', $method);
							require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
							$call_method = 'UpdraftPlus_BackupModule_'.$method;
							$method_objects[$method] = new $call_method;
							$method_objects[$method]->config_print();
							do_action('updraftplus_config_print_after_storage', $method);
						}
					?>

			</table>
			<script type="text/javascript">
			/* <![CDATA[ */

				jQuery(document).ready(function() {
					<?php
						$really_is_writable = $updraftplus->really_is_writable($updraft_dir);
						if (!$really_is_writable) echo "jQuery('.backupdirrow').show();\n";
					?>
					<?php
						if (!empty($active_service)) {
							if (is_array($active_service)) {
								foreach ($active_service as $serv) {
									echo "jQuery('.${serv}').show();\n";
								}
							} else {
								echo "jQuery('.${active_service}').show();\n";
							}
						} else {
							echo "jQuery('.none').show();\n";
						}
						foreach ($updraftplus->backup_methods as $method => $description) {
							// already done: require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
							$call_method = "UpdraftPlus_BackupModule_$method";
							if (method_exists($call_method, 'config_print_javascript_onready')) {
								$method_objects[$method]->config_print_javascript_onready();
							}
						}
					?>
				});
			/* ]]> */
			</script>
			<table class="form-table" style="width:900px;">
			<tr>
				<td colspan="2"><h2><?php _e('Advanced / Debugging Settings','updraftplus'); ?></h2></td>
			</tr>

			<tr>
				<th><?php _e('Expert settings','updraftplus');?>:</th>
				<td><a id="enableexpertmode" href="#enableexpertmode"><?php _e('Show expert settings','updraftplus');?></a> - <?php _e("click this to show some further options; don't bother with this unless you have a problem or are curious.",'updraftplus');?> <?php do_action('updraftplus_expertsettingsdescription'); ?></td>
			</tr>
			<?php
			$delete_local = UpdraftPlus_Options::get_updraft_option('updraft_delete_local', 1);
			$split_every_mb = UpdraftPlus_Options::get_updraft_option('updraft_split_every', 500);
			if (!is_numeric($split_every_mb)) $split_every_mb = 500;
			if ($split_every_mb < UPDRAFTPLUS_SPLIT_MIN) $split_every_mb = UPDRAFTPLUS_SPLIT_MIN;
			?>

			<tr class="expertmode" style="display:none;">
				<th><?php _e('Debug mode','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_debug_mode" name="updraft_debug_mode" value="1" <?php echo $debug_mode; ?> /> <br><label for="updraft_debug_mode"><?php _e('Check this to receive more information and emails on the backup process - useful if something is going wrong.','updraftplus');?> <?php _e('This will also cause debugging output from all plugins to be shown upon this screen - please do not be surprised to see these.', 'updraftplus');?></label></td>
			</tr>

			<tr class="expertmode" style="display:none;">
				<th><?php _e('Split archives every:','updraftplus');?></th>
				<td><input type="text" name="updraft_split_every" id="updraft_split_every" value="<?php echo $split_every_mb ?>" size="5" /> Mb<br><?php echo sprintf(__('UpdraftPlus will split up backup archives when they exceed this file size. The default value is %s megabytes. Be careful to leave some margin if your web-server has a hard size limit (e.g. the 2 Gb / 2048 Mb limit on some 32-bit servers/file systems).','updraftplus'), 500); ?></td>
			</tr>

			<tr class="deletelocal expertmode" style="display:none;">
				<th><?php _e('Delete local backup','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_delete_local" name="updraft_delete_local" value="1" <?php if ($delete_local) echo 'checked="checked"'; ?>> <br><label for="updraft_delete_local"><?php _e('Check this to delete any superfluous backup files from your server after the backup run finishes (i.e. if you uncheck, then any files despatched remotely will also remain locally, and any files being kept locally will not be subject to the retention limits).','updraftplus');?></label></td>
			</tr>

			<tr class="expertmode backupdirrow" style="display:none;">
				<th><?php _e('Backup directory','updraftplus');?>:</th>
				<td><input type="text" name="updraft_dir" id="updraft_dir" style="width:525px" value="<?php echo htmlspecialchars($this->prune_updraft_dir_prefix($updraft_dir)); ?>" /></td>
			</tr>
			<tr class="expertmode backupdirrow" style="display:none;">
				<td></td><td><?php

					if($really_is_writable) {
						$dir_info = '<span style="color:green">'.__('Backup directory specified is writable, which is good.','updraftplus').'</span>';
					} else {
						$dir_info = '<span style="color:red">';
						if (!is_dir($updraft_dir)) {
							$dir_info .= __('Backup directory specified does <b>not</b> exist.','updraftplus');
						} else {
							$dir_info .= __('Backup directory specified exists, but is <b>not</b> writable.','updraftplus');
						}
						$dir_info .= ' <span style="font-size:110%;font-weight:bold"><a href="'.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&action=updraft_create_backup_dir&nonce='.wp_create_nonce('create_backup_dir').'">'.__('Click here to attempt to create the directory and set the permissions','updraftplus').'</a></span>, '.__('or, to reset this option','updraftplus').' <a href="#" onclick="jQuery(\'#updraft_dir\').val(\'updraft\'); return false;">'.__('click here','updraftplus').'</a>. '.__('If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.','updraftplus').'</span>';
					}

					echo $dir_info.' '.__("This is where UpdraftPlus will write the zip files it creates initially.  This directory must be writable by your web server. It is relative to your content directory (which by default is called wp-content).", 'updraftplus').' '.__("<b>Do not</b> place it inside your uploads or plugins directory, as that will cause recursion (backups of backups of backups of...).",'updraftplus');?></td>
			</tr>

			<tr class="expertmode" style="display:none;">
				<th><?php _e('Use the server\'s SSL certificates','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_ssl_useservercerts" name="updraft_ssl_useservercerts" value="1" <?php if (UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')) echo 'checked="checked"'; ?>> <br><label for="updraft_ssl_useservercerts"><?php _e('By default UpdraftPlus uses its own store of SSL certificates to verify the identity of remote sites (i.e. to make sure it is talking to the real Dropbox, Amazon S3, etc., and not an attacker). We keep these up to date. However, if you get an SSL error, then choosing this option (which causes UpdraftPlus to use your web server\'s collection instead) may help.','updraftplus');?></label></td>
			</tr>

			<tr class="expertmode" style="display:none;">
				<th><?php _e('Do not verify SSL certificates','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_ssl_disableverify" name="updraft_ssl_disableverify" value="1" <?php if (UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify')) echo 'checked="checked"'; ?>> <br><label for="updraft_ssl_disableverify"><?php _e('Choosing this option lowers your security by stopping UpdraftPlus from verifying the identity of encrypted sites that it connects to (e.g. Dropbox, Google Drive). It means that UpdraftPlus will be using SSL only for encryption of traffic, and not for authentication.','updraftplus');?> <?php _e('Note that not all cloud backup methods are necessarily using SSL authentication.', 'updraftplus');?></label></td>
			</tr>

			<tr class="expertmode" style="display:none;">
				<th><?php _e('Disable SSL entirely where possible', 'updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_ssl_nossl" name="updraft_ssl_nossl" value="1" <?php if (UpdraftPlus_Options::get_updraft_option('updraft_ssl_nossl')) echo 'checked="checked"'; ?>> <br><label for="updraft_ssl_nossl"><?php _e('Choosing this option lowers your security by stopping UpdraftPlus from using SSL for authentication and encrypted transport at all, where possible. Note that some cloud storage providers do not allow this (e.g. Dropbox), so with those providers this setting will have no effect.','updraftplus');?> <a href="http://updraftplus.com/faqs/i-get-ssl-certificate-errors-when-backing-up-andor-restoring/"><?php _e('See this FAQ also.', 'updraftplus');?></a></label></td>
			</tr>

			<?php do_action('updraftplus_configprint_expertoptions'); ?>

			<tr>
			<td></td>
			<td>
				<?php
					$ws_ad = $updraftplus->wordshell_random_advert(1);
					if ($ws_ad) {
				?>
				<p style="margin: 10px 0; padding: 10px; font-size: 140%; background-color: lightYellow; border-color: #E6DB55; border: 1px solid; border-radius: 4px;">
					<?php echo $ws_ad; ?>
				</p>
				<?php
					}
				?>
				</td>
			</tr>
			<tr>
				<td></td>
				<td>
					<input type="hidden" name="action" value="update" />
					<input type="submit" class="button-primary" value="<?php _e('Save Changes','updraftplus');?>" />
				</td>
			</tr>
		</table>
		<?php
	}

	public function show_double_warning($text, $extraclass = '', $echo = true) {

		$ret = "<div class=\"error updraftplusmethod $extraclass\"><p>$text</p></div>";
		$ret .= "<p style=\"border:1px solid; padding: 6px;\">$text</p>";

		if ($echo) echo $ret;
		return $ret;

	}

	public function optionfilter_split_every($value) {
		$value = absint($value);
		if (!$value >= UPDRAFTPLUS_SPLIT_MIN) $value = UPDRAFTPLUS_SPLIT_MIN;
		return $value;
	}

	public function curl_check($service, $has_fallback = false, $extraclass = '', $echo = true) {

		$ret = '';

		// Check requirements
		if (!function_exists("curl_init") || !function_exists('curl_exec')) {
		
			$ret .= $this->show_double_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('Your web server\'s PHP installation does not included a <strong>required</strong> (for %s) module (%s). Please contact your web hosting provider\'s support and ask for them to enable it.', 'updraftplus'), $service, 'Curl').' '.sprintf(__("Your options are 1) Install/enable %s or 2) Change web hosting companies - %s is a standard PHP component, and required by all cloud backup plugins that we know of.",'updraftplus'), 'Curl', 'Curl'), $extraclass, false);

		} else {
			$curl_version = curl_version();
			$curl_ssl_supported= ($curl_version['features'] & CURL_VERSION_SSL);
			if (!$curl_ssl_supported) {
				if ($has_fallback) {
					$ret .= '<p><strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__("Your web server's PHP/Curl installation does not support https access. Communications with %s will be unencrypted. ask your web host to install Curl/SSL in order to gain the ability for encryption (via an add-on).",'updraftplus'),$service).'</p>';
				} else {
					$ret .= $this->show_double_warning('<p><strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__("Your web server's PHP/Curl installation does not support https access. We cannot access %s without this support. Please contact your web hosting provider's support. %s <strong>requires</strong> Curl+https. Please do not file any support requests; there is no alternative.",'updraftplus'),$service).'</p>', $extraclass, false);
				}
			} else {
				$ret .= '<p><em>'.sprintf(__("Good news: Your site's communications with %s can be encrypted. If you see any errors to do with encryption, then look in the 'Expert Settings' for more help.", 'updraftplus'),$service).'</em></p>';
			}
		}
		if ($echo) {
			echo $ret;
		} else {
			return $ret;
		}
	}

	# If $basedirs is passed as an array, then $directorieses must be too
	private function recursive_directory_size($directorieses, $exclude = array(), $basedirs = '') {

		$size = 0;

		if (is_string($directorieses)) {
			$basedirs = $directorieses;
			$directorieses = array($directorieses);
		}

		if (is_string($basedirs)) $basedirs = array($basedirs);

		foreach ($directorieses as $ind => $directories) {
			if (!is_array($directories)) $directories=array($directories);

			$basedir = empty($basedirs[$ind]) ? $basedirs[0] : $basedirs[$ind];

			foreach ($directories as $dir) {
				if (is_file($dir)) {
					$size += @filesize($dir);
				} else {
					$suffix = ('' != $basedir) ? ((0 === strpos($dir, $basedir.'/')) ? substr($dir, 1+strlen($basedir)) : '') : '';
					$size += $this->recursive_directory_size_raw($basedir, $exclude, $suffix);
				}
			}

		}

// 		foreach ($basedirs as $ind => $basedir) {
// 
// 			$directories = $directorieses[$ind];
// 			if (!is_array($directories)) $directories=array($directories);
// 
// 			foreach ($directories as $dir) {
// error_log($dir);
// 				if (is_file($dir)) {
// 					$size += @filesize($dir);
// 				} else {
// 					$suffix = ('' != $basedir) ? ((0 === strpos($dir, $basedir.'/')) ? substr($dir, 1+strlen($basedir)) : '') : '';
// 					$size += $this->recursive_directory_size_raw($basedir, $exclude, $suffix);
// 				}
// 			}
// 
// 		}

		if ($size > 1073741824) {
			return round($size / 1073741824, 1).' Gb';
		} elseif ($size > 1048576) {
			return round($size / 1048576, 1).' Mb';
		} elseif ($size > 1024) {
			return round($size / 1024, 1).' Kb';
		} else {
			return round($size, 1).' b';
		}

	}

	private function recursive_directory_size_raw($prefix_directory, &$exclude = array(), $suffix_directory = '') {

		$directory = $prefix_directory.('' == $suffix_directory ? '' : '/'.$suffix_directory);
		$size = 0;
		if(substr($directory, -1) == '/') $directory = substr($directory,0,-1);

		if (!file_exists($directory) || !is_dir($directory) || !is_readable($directory)) return -1;
		if (file_exists($directory.'/.donotbackup')) return 0;

		if ($handle = opendir($directory)) {
			while (($file = readdir($handle)) !== false) {
				if ($file != '.' && $file != '..') {
					$spath = ('' == $suffix_directory) ? $file : $suffix_directory.'/'.$file;
					if (false !== ($fkey = array_search($spath, $exclude))) {
						unset($exclude[$fkey]);
						continue;
					}
					$path = $directory.'/'.$file;
					if(is_file($path)) {
						$size += filesize($path);
					} elseif(is_dir($path)) {
						$handlesize = $this->recursive_directory_size_raw($prefix_directory, $exclude, $suffix_directory.('' == $suffix_directory ? '' : '/').$file);
						if($handlesize >= 0) { $size += $handlesize; }# else { return -1; }
					}
				}
			}
			closedir($handle);
		}

		return $size;

	}

	private function existing_backup_table($backup_history = false) {

		global $updraftplus;

		if (false === $backup_history) $backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history=array();
		if (empty($backup_history)) return "<p><em>".__('You have not yet made any backups.', 'updraftplus')."</em></p>";

		$updraft_dir = $updraftplus->backups_dir_location();
		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		$accept = apply_filters('updraftplus_accept_archivename', array());
		if (!is_array($accept)) $accept = array();

		$ret = '<table style="margin-top: 20px; margin-left: 20px;">';
		$nonce_field = wp_nonce_field('updraftplus_download', '_wpnonce', true, false);

		//".__('Actions', 'updraftplus')."
		$ret .= "<thead>
			<tr style=\"margin-bottom: 4px;\">
				<th style=\"padding:0px 10px 6px; width: 172px;\">".__('Backup date', 'updraftplus')."</th>
				<th style=\"padding:0px 16px 6px; width: 426px;\">".__('Backup data (click to download)', 'updraftplus')."</th>
				<th style=\"padding:0px 0px 6px 1px; width: 272px;\">".__('Actions', 'updraftplus')."</th>
			</tr>
			<tr style=\"height:2px; padding:1px; margin:0px;\">
				<td colspan=\"4\" style=\"margin:0; padding:0\"><div style=\"height: 2px; background-color:#888888;\">&nbsp;</div></td>
			</tr>
		</thead>
		<tbody>";
// 		$ret .= "<thead>
// 		</thead>
// 		<tbody>";

		krsort($backup_history);
		foreach ($backup_history as $key => $backup) {

			# https://core.trac.wordpress.org/ticket/25331 explains why the following line is wrong
			# $pretty_date = date_i18n('Y-m-d G:i',$key);
			// Convert to blog time zone
// 			$pretty_date = get_date_from_gmt(gmdate('Y-m-d H:i:s', (int)$key), 'Y-m-d G:i');
			$pretty_date = get_date_from_gmt(gmdate('Y-m-d H:i:s', (int)$key), 'M d, Y G:i');

			$esc_pretty_date = esc_attr($pretty_date);
			$entities = '';

			$non = $backup['nonce'];
			$rawbackup = "<h2>$esc_pretty_date ($key)</h2><pre><p>".esc_attr(print_r($backup, true));
			if (!empty($non)) {
				$jd = $updraftplus->jobdata_getarray($non);
				if (!empty($jd) && is_array($jd)) {
					$rawbackup .= '</p><p>'.esc_attr(print_r($jd, true));
				}
			}
			$rawbackup .= '</p></pre>';

			$jobdata = $updraftplus->jobdata_getarray($non);

			$delete_button = $this->delete_button($key, $non, $backup);

			$date_label = $this->date_label($pretty_date, $key, $backup, $jobdata, $non);

			$ret .= <<<ENDHERE
		<tr id="updraft_existing_backups_row_$key">

			<td style="max-width: 140px;" class="updraft_existingbackup_date" data-rawbackup="$rawbackup">
				$date_label
			</td>
ENDHERE;

			$ret .= "<td>";
			if (empty($backup['meta_foreign']) || !empty($accept[$backup['meta_foreign']]['separatedb'])) {

				if (isset($backup['db'])) {
					$entities .= '/db=0/';

					// Set a flag according to whether or not $backup['db'] ends in .crypt, then pick this up in the display of the decrypt field.
					$db = is_array($backup['db']) ? $backup['db'][0] : $backup['db'];
					if ($updraftplus->is_db_encrypted($db)) $entities .= '/dbcrypted=1/';

					$ret .= $this->download_db_button('db', $key, $esc_pretty_date, $nonce_field, $backup);
				} else {
// 					$ret .= sprintf(_x('(No %s)','Message shown when no such object is available','updraftplus'), __('database', 'updraftplus'));
				}

				# External databases
				foreach ($backup as $bkey => $binfo) {
					if ('db' == $bkey || 'db' != substr($bkey, 0, 2) || '-size' == substr($bkey, -5, 5)) continue;
					$ret .= $this->download_db_button($bkey, $key, $esc_pretty_date, $nonce_field, $backup);
				}

			} else {
				# Foreign without separate db
				$entities = '/db=0/meta_foreign=1/';
			}

			if (!empty($backup['meta_foreign']) && !empty($accept[$backup['meta_foreign']]) && !empty($accept[$backup['meta_foreign']]['separatedb'])) {
				$entities .= '/meta_foreign=2/';
			}

			$download_buttons = $this->download_buttons($backup, $key, $accept, $entities, $esc_pretty_date, $nonce_field);

			$ret .= $download_buttons;

// 			$ret .="</td>";

			// No logs expected for foreign backups
			if (empty($backup['meta_foreign'])) {
// 				$ret .= '<td>';
// 				$ret .= $this->log_button($backup);
// 				$ret .= "</td>";
			}

				$ret .= "</td>";

			$ret .= '<td style="padding: 1px; margin:0px;">';
			$ret .= $this->restore_button($backup, $key, $pretty_date, $entities);
			$ret .= $delete_button;
			if (empty($backup['meta_foreign'])) $ret .= $this->log_button($backup);
			$ret .= '</td>';

			$ret .= '</tr>';

			$ret .= "<tr style=\"height:2px; padding:1px; margin:0px;\"><td colspan=\"4\" style=\"margin:0; padding:0\"><div style=\"height: 2px; background-color:#aaaaaa;\">&nbsp;</div></td></tr>";

		}

		$ret .= '</tbody></table>';
		return $ret;
	}

	private function download_db_button($bkey, $key, $esc_pretty_date, $nonce_field, $backup) {
		if (!empty($backup['meta_foreign']) && isset($accept[$backup['meta_foreign']])) {
			$desc_source = $accept[$backup['meta_foreign']]['desc'];
		} else {
			$desc_source = __('unknown source', 'updraftplus');
		}

		$ret = '';

		if ('db' == $bkey) {
			$dbt = empty($backup['meta_foreign']) ? esc_attr(__('Database','updraftplus')) : esc_attr(sprintf(__('Database (created by %s)', 'updraftplus'), $desc_source));
		} else {
			$dbt = __('External database','updraftplus').' ('.substr($bkey, 2).')';
		}

		$ret .= <<<ENDHERE
		<div style="float:left; clear: none;">
			<form id="uddownloadform_${bkey}_${key}_0" action="admin-ajax.php" onsubmit="return updraft_downloader('uddlstatus_', $key, '$bkey', '#ud_downloadstatus', '0', '$esc_pretty_date', true)" method="post">
				$nonce_field
				<input type="hidden" name="action" value="updraft_download_backup" />
				<input type="hidden" name="type" value="$bkey" />
				<input type="hidden" name="timestamp" value="$key" />
				<input type="submit" class="updraft-backupentitybutton" value="$dbt" />
			</form>
		</div>
ENDHERE;

		return $ret;
	}

	// Go through each of the file entities
	private function download_buttons($backup, $key, $accept, &$entities, $esc_pretty_date, $nonce_field) {
		global $updraftplus;
		$ret = '';
		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

// 		$colspan = 1;
// 		if (!empty($backup['meta_foreign'])) {
// 			$colspan = 2;
// 			if (empty($accept[$backup['meta_foreign']]['separatedb'])) $colspan++;
// 		}
// 		$ret .= (1 == $colspan) ? '<td>' : '<td colspan="'.$colspan.'">';

		$first_entity = true;

		foreach ($backupable_entities as $type => $info) {
			if (!empty($backup['meta_foreign']) && 'wpcore' != $type) continue;
// 			$colspan = 1;
// 			if (!empty($backup['meta_foreign'])) {
// 				$colspan = (1+count($backupable_entities));
// 				if (empty($accept[$backup['meta_foreign']]['separatedb'])) $colspan++;
// 			}
// 			$ret .= (1 == $colspan) ? '<td>' : '<td colspan="'.$colspan.'">';
			$ide = '';
			if ('wpcore' == $type) $wpcore_restore_descrip = $info['description'];
			if (empty($backup['meta_foreign'])) {
				$sdescrip = preg_replace('/ \(.*\)$/', '', $info['description']);
				if (strlen($sdescrip) > 20 && isset($info['shortdescription'])) $sdescrip = $info['shortdescription'];
			} else {
				$info['description'] = 'WordPress';

				if (isset($accept[$backup['meta_foreign']])) {
					$desc_source = $accept[$backup['meta_foreign']]['desc'];
					$ide .= sprintf(__('Backup created by: %s.', 'updraftplus'), $accept[$backup['meta_foreign']]['desc']).' ';
				} else {
					$desc_source = __('unknown source', 'updraftplus');
					$ide .= __('Backup created by unknown source (%s) - cannot be restored.', 'updraftplus').' ';
				}

				$sdescrip = (empty($accept[$backup['meta_foreign']]['separatedb'])) ? sprintf(__('Files and database WordPress backup (created by %s)', 'updraftplus'), $desc_source) : sprintf(__('Files backup (created by %s)', 'updraftplus'), $desc_source);
				if ('wpcore' == $type) $wpcore_restore_descrip = $sdescrip;
			}
			if (isset($backup[$type])) {
				if (!is_array($backup[$type])) $backup[$type]=array($backup[$type]);
				$howmanyinset = count($backup[$type]);
				$expected_index = 0;
				$index_missing = false;
				$set_contents = '';
				$entities .= "/$type=";
				$whatfiles = $backup[$type];
				ksort($whatfiles);
				foreach ($whatfiles as $findex => $bfile) {
					$set_contents .= ($set_contents == '') ? $findex : ",$findex";
					if ($findex != $expected_index) $index_missing = true;
					$expected_index++;
				}
				$entities .= $set_contents.'/';
				if (!empty($backup['meta_foreign'])) {
					$entities .= '/plugins=0//themes=0//uploads=0//others=0/';
				}
				$first_printed = true;
				foreach ($whatfiles as $findex => $bfile) {
					$ide .= __('Press here to download', 'updraftplus').' '.strtolower($info['description']);
					$pdescrip = ($findex > 0) ? $sdescrip.' ('.($findex+1).')' : $sdescrip;
					if (!$first_printed) {
						$ret .= '<div style="display:none;">';
					}
					if (count($backup[$type]) >0) {
						$ide .= ' '.sprintf(__('(%d archive(s) in set).', 'updraftplus'), $howmanyinset);
					}
					if ($index_missing) {
						$ide .= ' '.__('You appear to be missing one or more archives from this multi-archive set.', 'updraftplus');
					}

					if (!$first_entity) {
// 						$ret .= ', ';
					} else {
						$first_entity = false;
					} 

					$ret .= $this->download_button($type, $key, $findex, $info, $nonce_field, $ide, $pdescrip, $esc_pretty_date, $set_contents);

					if (!$first_printed) {
						$ret .= '</div>';
					} else {
						$first_printed = false;
					}
				}
			} else {
// 				$ret .= sprintf(_x('(No %s)','Message shown when no such object is available','updraftplus'), preg_replace('/\s\(.{12,}\)/', '', strtolower($sdescrip)));
			}
// 			$ret .= '</td>';
		}
// 			$ret .= '</td>';
		return $ret;
	}

	private function date_label($pretty_date, $key, $backup, $jobdata, $nonce) {
// 		$ret = apply_filters('updraftplus_showbackup_date', '<strong>'.$pretty_date.'</strong>', $backup, $jobdata, (int)$key);
		$ret = apply_filters('updraftplus_showbackup_date', $pretty_date, $backup, $jobdata, (int)$key);
		if (is_array($jobdata) && !empty($jobdata['resume_interval']) && (empty($jobdata['jobstatus']) || 'finished' != $jobdata['jobstatus'])) {
			$ret .= apply_filters('updraftplus_msg_unfinishedbackup', "<br><span title=\"".esc_attr(__('If you are seeing more backups than you expect, then it is probably because the deletion of old backup sets does not happen until a fresh backup completes.', 'updraftplus'))."\">".__('(Not finished)', 'updraftplus').'</span>', $jobdata, $nonce);
		}
		return $ret;
	}

	private function download_button($type, $key, $findex, $info, $nonce_field, $ide, $pdescrip, $esc_pretty_date, $set_contents) {
		$ret = <<<ENDHERE
			<div style="float: left; clear: none;">
				<form id="uddownloadform_${type}_${key}_${findex}" action="admin-ajax.php" onsubmit="return updraft_downloader('uddlstatus_', '$key', '$type', '#ud_downloadstatus', '$set_contents', '$esc_pretty_date', true)" method="post">
					$nonce_field
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="$type" />
					<input type="hidden" name="timestamp" value="$key" />
					<input type="hidden" name="findex" value="$findex" />
					<input type="submit" class="updraft-backupentitybutton" title="$ide" value="$pdescrip" />
				</form>
			</div>
ENDHERE;
		return $ret;
	}

	private function restore_button($backup, $key, $pretty_date, $entities) {
		$ret = <<<ENDHERE
		<div style="float:left; clear:none; margin-right: 6px;">
			<form method="post" action="">
				<input type="hidden" name="backup_timestamp" value="$key">
				<input type="hidden" name="action" value="updraft_restore" />
ENDHERE;
			if ($entities) {
				$show_data = $pretty_date;
				if (isset($backup['native']) && false == $backup['native']) {
					$show_data .= ' '.__('(backup set imported from remote storage)', 'updraftplus');
				}
				# jQuery('#updraft_restore_label_wpcore').html('".esc_js($wpcore_restore_descrip)."');
				$ret .= '<button title="'.__('After pressing this button, you will be given the option to choose which components you wish to restore','updraftplus').'" type="button" class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:16px !important; height:28px;" onclick="'."updraft_restore_setoptions('$entities');
				jQuery('#updraft_restore_timestamp').val('$key'); jQuery('.updraft_restore_date').html('$show_data'); ";
				$ret .= "updraft_restore_stage = 1; jQuery('#updraft-restore-modal').dialog('open'); jQuery('#updraft-restore-modal-stage1').show();jQuery('#updraft-restore-modal-stage2').hide(); jQuery('#updraft-restore-modal-stage2a').html(''); updraft_activejobs_update(true);\">".__('Restore', 'updraftplus').'</button>';
			}
			$ret .= "</form></div>\n";
		return $ret;
	}

	private function delete_button($key, $nonce, $backup) {
		$sval = ((isset($backup['service']) && $backup['service'] != 'email' && $backup['service'] != 'none')) ? '1' : '0';
// return '<div class="updraftplus-remove" style="float: left; clear:none; "padding-top:2px;padding-bottom:2px;font-size:16px !important; min-height:26px;text-align:center; font-weight:bold; border-radius: 2px;"><a style="text-decoration:none;" href="javascript:updraft_delete('.$key.', '.$nonce.', '.$sval.');" title="'.esc_attr(__('Delete this backup set', 'updraftplus')).'">'.__('Delete', 'updraftplus').'</a></div>';
// return '<div class="updraftplus-remove" style="float: left; clear:none; width: 27px; height: 27px; padding-top:0px; padding-bottom: 4px;font-size: 26px; text-align:center; font-weight:bold; border-radius: 7px;"><a style="text-decoration:none;" href="javascript:updraft_delete('.$key.', '.$nonce.', '.$sval.');" title="'.esc_attr(__('Delete this backup set', 'updraftplus')).'">×</a></div>';
// return '<div class="updraftplus-remove" style="float: left; clear:none; width: 20px; height: 20px; padding-top:0px; padding-bottom: 2px;font-size: 19px; text-align:center; font-weight:bold; border-radius: 4px;"><a style="text-decoration:none;" href="javascript:'."updraft_delete('$key', '$nonce', $sval);".'" title="'.esc_attr(__('Delete this backup set', 'updraftplus')).'">×</a></div>';
		return '<div class="updraftplus-remove" style="float: left; clear:none; font-size: 16px; text-align:center; border-radius: 4px;"><a style="text-decoration:none;" href="javascript:'."updraft_delete('$key', '$nonce', $sval);".'" title="'.esc_attr(__('Delete this backup set', 'updraftplus')).'">'.__('Delete', 'updraftplus').'</a></div>';
	}

	private function log_button($backup) {
		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();
		$ret = '';
		if (isset($backup['nonce']) && preg_match("/^[0-9a-f]{12}$/",$backup['nonce']) && is_readable($updraft_dir.'/log.'.$backup['nonce'].'.txt')) {
			$nval = $backup['nonce'];
			$lt = esc_attr(__('View Log','updraftplus'));
			$url = UpdraftPlus_Options::admin_page();
			$ret .= <<<ENDHERE
				<div style="float:left; clear:none;" class="updraft-viewlogdiv">
				<form action="$url" method="get">
					<input type="hidden" name="action" value="downloadlog" />
					<input type="hidden" name="page" value="updraftplus" />
					<input type="hidden" name="updraftplus_backup_nonce" value="$nval" />
					<input type="submit" value="$lt" class="updraft-log-link" onclick="event.preventDefault(); updraft_popuplog('$nval');" />
				</form>
				</div>
ENDHERE;
			return $ret;
		} else {
// 			return str_replace(' ', '&nbsp;', '('.__('No backup log)', 'updraftplus').')');
		}
	}

	// This function examines inside the updraft directory to see if any new archives have been uploaded. If so, it adds them to the backup set. (Non-present items are also removed, only if the service is 'none').
	// If $remotescan is set, then remote storage is also scanned
	public function rebuild_backup_history($remotescan = false) {

		# TODO: Make compatible with incremental naming scheme

		global $updraftplus;
		$messages = array();
		$gmt_offset = get_option('gmt_offset');

		# Array of nonces keyed by filename
		$known_files = array();
		# Array of backup times keyed by nonce
		$known_nonces = array();
		$changes = false;

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, false);

		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history = array();
		$updraft_dir = $updraftplus->backups_dir_location();
		if (!is_dir($updraft_dir)) return;

		$accept = apply_filters('updraftplus_accept_archivename', array());
		if (!is_array($accept)) $accept = array();
		// Process what is known from the database backup history; this means populating $known_files and $known_nonces
		foreach ($backup_history as $btime => $bdata) {
			$found_file = false;
			foreach ($bdata as $key => $values) {
				if ('db' != $key && !isset($backupable_entities[$key])) continue;
				// Record which set this file is found in
				if (!is_array($values)) $values=array($values);
				foreach ($values as $val) {
					if (!is_string($val)) continue;
					if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+(of[0-9]+)?)?+\.(zip|gz|gz\.crypt)$/i', $val, $matches)) {
						$nonce = $matches[2];
						if (isset($bdata['service']) && ($bdata['service'] === 'none' || (is_array($bdata['service']) && array('none') === $bdata['service'])) && !is_file($updraft_dir.'/'.$val)) {
							# File without remote storage is no longer present
						} else {
							$found_file = true;
							$known_files[$val] = $nonce;
							$known_nonces[$nonce] = (empty($known_nonces[$nonce]) || $known_nonces[$nonce]<100) ? $btime : min($btime, $known_nonces[$nonce]);
						}
					} else {
						$accepted = false;
						foreach ($accept as $fkey => $acc) {
							if (preg_match('/'.$acc['pattern'].'/i', $val)) $accepted = $fkey;
						}
						if (!empty($accepted) && (false != ($btime = apply_filters('updraftplus_foreign_gettime', false, $fkey, $val))) && $btime > 0) {
							$found_file = true;
							# Generate a nonce; this needs to be deterministic and based on the filename only
							$nonce = substr(md5($val), 0, 12);
							$known_files[$val] = $nonce;
							$known_nonces[$nonce] = (empty($known_nonces[$nonce]) || $known_nonces[$nonce]<100) ? $btime : min($btime, $known_nonces[$nonce]);
						}
					}
				}
			}
			if (!$found_file) {
				# File recorded as being without remote storage is no longer present - though it may in fact exist in remote storage, and this will be picked up later
				unset($backup_history[$btime]);
				$changes = true;
			}
		}

		$remotefiles = array();
		$remotesizes = array();
		# Scan remote storage and get back lists of files and their sizes
		# TODO: Make compatible with incremental naming
		if ($remotescan) {
			add_action('http_request_args', array($updraftplus, 'modify_http_options'));
			foreach ($updraftplus->backup_methods as $method => $desc) {
				require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
				$objname = 'UpdraftPlus_BackupModule_'.$method;
				$obj = new $objname;
				if (!method_exists($obj, 'listfiles')) continue;
				$files = $obj->listfiles('backup_');
				if (is_array($files)) {
					foreach ($files as $entry) {
						$n = $entry['name'];
						if (!preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+(of[0-9]+)?)?\.(zip|gz|gz\.crypt)$/i', $n, $matches)) continue;
						if (isset($remotefiles[$n])) {
							$remotefiles[$n][] = $method;
						} else {
							$remotefiles[$n] = array($method);
						}
						if (!empty($entry['size'])) {
							if (empty($remotesizes[$n]) || $remotesizes[$n] < $entry['size']) $remotesizes[$n] = $entry['size'];
						}
					}
				} elseif (is_wp_error($files)) {
					foreach ($files->get_error_codes() as $code) {
						if ('no_settings' == $code || 'no_addon' == $code || 'insufficient_php' == $code) continue;
						$messages[] = array(
							'method' => $method,
							'desc' => $desc,
							'code' => $code,
							'message' => $files->get_error_message($code)
						);
					}
				}
			}
			remove_action('http_request_args', array($updraftplus, 'modify_http_options'));
		}

		if (!$handle = opendir($updraft_dir)) return;

		// See if there are any more files in the local directory than the ones already known about
		while (false !== ($entry = readdir($handle))) {
			$accepted_foreign = false;
			$potmessage = false;
			if ('.' == $entry || '..' == $entry) continue;
			# TODO: Make compatible with Incremental naming
			if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+(of[0-9]+)?)?\.(zip|gz|gz\.crypt)$/i', $entry, $matches)) {
				// Interpret the time as one from the blog's local timezone, rather than as UTC
				# $matches[1] is YYYY-MM-DD-HHmm, to be interpreted as being the local timezone
				$btime2 = strtotime($matches[1]);
				$btime = (!empty($gmt_offset)) ? $btime2 - $gmt_offset*3600 : $btime2;
				$nonce = $matches[2];
				$type = $matches[3];
				if ('db' == $type) {
					$type .= $matches[4];
					$index = 0;
				} else {
					$index = (empty($matches[4])) ? '0' : (max((int)$matches[4]-1,0));
				}
				$itext = ($index == 0) ? '' : $index;
			} elseif (false != ($accepted_foreign = apply_filters('updraftplus_accept_foreign', false, $entry)) && false !== ($btime = apply_filters('updraftplus_foreign_gettime', false, $accepted_foreign, $entry))) {
				$nonce = substr(md5($entry), 0, 12);
				$type = (preg_match('/\.sql(\.(bz2|gz))?$/i', $entry) || preg_match('/-database-([-0-9]+)\.zip$/i', $entry)) ? 'db' : 'wpcore';
				$index = '0';
				$itext = '';
				$potmessage = array(
					'code' => 'foundforeign_'.md5($entry),
					'desc' => $entry,
					'method' => '',
					'message' => sprintf(__('Backup created by: %s.', 'updraftplus'), $accept[$accepted_foreign]['desc'])
				);
			} elseif ('.zip' == strtolower(substr($entry, -4, 4)) || preg_match('/\.sql(\.(bz2|gz))?$/i', $entry)) {
				$potmessage = array(
					'code' => 'possibleforeign_'.md5($entry),
					'desc' => $entry,
					'method' => '',
					'message' => __('This file does not appear to be an UpdraftPlus backup archive (such files are .zip or .gz files which have a name like: backup_(time)_(site name)_(code)_(type).(zip|gz)).', 'updraftplus').' <a href="http://updraftplus.com/shop/updraftplus-premium/">'.__('If this is a backup created by a different backup plugin, then UpdraftPlus Premium may be able to help you.', 'updraftplus').'</a>'
				);
				$messages[$potmessage['code']] = $potmessage;
				continue;
			} else {
				continue;
			}
			// The time from the filename does not include seconds. Need to identify the seconds to get the right time
			if (isset($known_nonces[$nonce])) {
				$btime_exact = $known_nonces[$nonce];
				# TODO: If the btime we had was more than 60 seconds earlier, then this must be an increment - we then need to change the $backup_history array accordingly. We can pad the '60 second' test, as there's no option to run an increment more frequently than every 4 hours (though someone could run one manually from the CLI)
				if ($btime > 100 && $btime_exact - $btime > 60 && !empty($backup_history[$btime_exact])) {
					# TODO: This needs testing
					# The code below assumes that $backup_history[$btime] is presently empty
					# Re-key array, indicating the newly-found time to be the start of the backup set
					$backup_history[$btime] = $backup_history[$btime_exact];
					unset($backup_history[$btime_exact]);
					$btime_exact = $btime;
				}
				$btime = $btime_exact;
			}
			if ($btime <= 100) continue;
			$fs = @filesize($updraft_dir.'/'.$entry);

			if (!isset($known_files[$entry])) {
				$changes = true;
				if (is_array($potmessage)) $messages[$potmessage['code']] = $potmessage;
				if ('db' == $type && !$accepted_foreign) {
				list ($mess, $warn, $err, $info) = $this->analyse_db_file(false, array(), $updraft_dir.'/'.$entry, true);
					if (!empty($info['label'])) {
						$backup_history[$btime]['label'] = $info['label'];
					}
				}
			}

			# TODO: Code below here has not been reviewed or adjusted for compatibility with incremental backups
			# Make sure we have the right list of services
			$current_services = (!empty($backup_history[$btime]) && !empty($backup_history[$btime]['service'])) ? $backup_history[$btime]['service'] : array();
			if (is_string($current_services)) $current_services = array($current_services);
			if (!is_array($current_services)) $current_services = array();
			if (!empty($remotefiles[$entry])) {
				if (0 == count(array_diff($current_services, $remotefiles[$entry]))) {
					$backup_history[$btime]['service'] = $remotefiles[$entry];
					$changes = true;
				}
				# Get the right size (our local copy may be too small)
				foreach ($remotefiles[$entry] as $rem) {
					if (!empty($rem['size']) && $rem['size'] > $fs) {
						$fs = $rem['size'];
						$changes = true;
					}
				}
				# Remove from $remotefiles, so that we can later see what was left over
				unset($remotefiles[$entry]);
			} else {
				# Not known remotely
				if (!empty($backup_history[$btime])) {
					if (empty($backup_history[$btime]['service']) || ('none' !== $backup_history[$btime]['service'] && ''  !== $backup_history[$btime]['service'] && array('none') !== $backup_history[$btime]['service'])) {
						$backup_history[$btime]['service'] = 'none';
						$changes = true;
					}
				} else {
					$backup_history[$btime]['service'] = 'none';
					$changes = true;
				}
			}

			$backup_history[$btime][$type][$index] = $entry;
			if ($fs > 0) $backup_history[$btime][$type.$itext.'-size'] = $fs;
			$backup_history[$btime]['nonce'] = $nonce;
			if (!empty($accepted_foreign)) $backup_history[$btime]['meta_foreign'] = $accepted_foreign;
		}

		# Any found in remote storage that we did not previously know about?
		# Compare $remotefiles with $known_files / $known_nonces, and adjust $backup_history
		if (count($remotefiles) > 0) {

			# $backup_history[$btime]['nonce'] = $nonce
			foreach ($remotefiles as $file => $services) {
				if (!preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+(of[0-9]+)?)?\.(zip|gz|gz\.crypt)$/i', $file, $matches)) continue;
				$nonce = $matches[2];
				$type = $matches[3];
				if ('db' == $type) {
					$index = 0;
					$type .= $matches[4];
				} else {
					$index = (empty($matches[4])) ? '0' : (max((int)$matches[4]-1,0));
				}
				$itext = ($index == 0) ? '' : $index;
				$btime2 = strtotime($matches[1]);
				$btime = (!empty($gmt_offset)) ? $btime2 - $gmt_offset*3600 : $btime2;

				if (isset($known_nonces[$nonce])) $btime = $known_nonces[$nonce];
				if ($btime <= 100) continue;
				# Remember that at this point, we already know that the file is not known about locally
				if (isset($backup_history[$btime])) {
					if (!isset($backup_history[$btime]['service']) || ((is_array($backup_history[$btime]['service']) && $backup_history[$btime]['service'] !== $services) || is_string($backup_history[$btime]['service']) && (1 != count($services) || $services[0] !== $backup_history[$btime]['service']))) {
						$changes = true;
						$backup_history[$btime]['service'] = $services;
						$backup_history[$btime]['nonce'] = $nonce;
					}
					if (!isset($backup_history[$btime][$type][$index])) {
						$changes = true;
						$backup_history[$btime][$type][$index] = $file;
						$backup_history[$btime]['nonce'] = $nonce;
						if (!empty($remotesizes[$file])) $backup_history[$btime][$type.$itext.'-size'] = $remotesizes[$file];
					}
				} else {
					$changes = true;
					$backup_history[$btime]['service'] = $services;
					$backup_history[$btime][$type][$index] = $file;
					$backup_history[$btime]['nonce'] = $nonce;
					if (!empty($remotesizes[$file])) $backup_history[$btime][$type.$itext.'-size'] = $remotesizes[$file];
					$backup_history[$btime]['native'] = false;
					$messages['nonnative'] = array(
						'message' => __('One or more backups has been added from scanning remote storage; note that these backups will not be automatically deleted through the "retain" settings; if/when you wish to delete them then you must do so manually.', 'updraftplus'),
						'code' => 'nonnative',
						'desc' => '',
						'method' => ''
					);
				}

			}
		}

		if ($changes) UpdraftPlus_Options::update_updraft_option('updraft_backup_history', $backup_history);

		return $messages;

	}

	// Return values: false = 'not yet' (not necessarily terminal); WP_Error = terminal failure; true = success
	private function restore_backup($timestamp) {

		@set_time_limit(900);

		global $wp_filesystem, $updraftplus;
		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>'.__('This backup does not exist in the backup history - restoration aborted. Timestamp:','updraftplus')." $timestamp</p><br/>";
			return new WP_Error('does_not_exist', __('Backup does not exist in the backup history', 'updraftplus'));
		}

		// request_filesystem_credentials passes on fields just via hidden name/value pairs.
		// Build array of parameters to be passed via this
		$extra_fields = array();
		if (isset($_POST['updraft_restore']) && is_array($_POST['updraft_restore'])) {
			foreach ($_POST['updraft_restore'] as $entity) {
				$_POST['updraft_restore_'.$entity] = 1;
				$extra_fields[] = 'updraft_restore_'.$entity;
			}
		}
		// Now make sure that updraft_restorer_ option fields get passed along to request_filesystem_credentials
		foreach ($_POST as $key => $value) {
			if (0 === strpos($key, 'updraft_restorer_')) $extra_fields[] = $key;
		}

		$credentials = request_filesystem_credentials(UpdraftPlus_Options::admin_page()."?page=updraftplus&action=updraft_restore&backup_timestamp=$timestamp", '', false, false, $extra_fields);
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			echo '<p><em><a href="http://updraftplus.com/faqs/asked-ftp-details-upon-restorationmigration-updates/">'.__('Why am I seeing this?', 'updraftplus').'</a></em></p>';
			foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
			exit;
		}

		# Set up logging
		$updraftplus->backup_time_nonce();
		$updraftplus->jobdata_set('job_type', 'restore');
		$updraftplus->jobdata_set('job_time_ms', $updraftplus->job_time_ms);
		$updraftplus->logfile_open($updraftplus->nonce);

		# Provide download link for the log file

		#echo '<p><a target="_new" href="?action=downloadlog&page=updraftplus&updraftplus_backup_nonce='.htmlspecialchars($updraftplus->nonce).'">'.__('Follow this link to download the log file for this restoration.', 'updraftplus').'</a></p>';

		# TODO: Automatic purging of old log files
		# TODO: Provide option to auto-email the log file

		//if we make it this far then WP_Filesystem has been instantiated and is functional (tested with ftpext, what about suPHP and other situations where direct may work?)
		echo '<h1>'.__('UpdraftPlus Restoration: Progress', 'updraftplus').'</h1><div id="updraft-restore-progress">';

		$this->show_admin_warning('<a target="_new" href="?action=downloadlog&page=updraftplus&updraftplus_backup_nonce='.htmlspecialchars($updraftplus->nonce).'">'.__('Follow this link to download the log file for this restoration (needed for any support requests).', 'updraftplus').'</a>');

		$updraft_dir = trailingslashit($updraftplus->backups_dir_location());
		$foreign_known = apply_filters('updraftplus_accept_archivename', array());

		$service = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;
		if (!is_array($service)) $service = array($service);

		// Now, need to turn any updraft_restore_<entity> fields (that came from a potential WP_Filesystem form) back into parts of the _POST array (which we want to use)
		if (empty($_POST['updraft_restore']) || (!is_array($_POST['updraft_restore']))) $_POST['updraft_restore'] = array();

		$backup_set = $backup_history[$timestamp];
		$entities_to_restore = array();
		foreach ($_POST['updraft_restore'] as $entity) {
			if (empty($backup_set['meta_foreign'])) {
				$entities_to_restore[$entity] = $entity;
			} else {
				if ('db' == $entity && !empty($foreign_known[$backup_set['meta_foreign']]) && !empty($foreign_known[$backup_set['meta_foreign']]['separatedb'])) {
					$entities_to_restore[$entity] = 'db';
				} else {
					$entities_to_restore[$entity] = 'wpcore';
				}
			}
		}

		foreach ($_POST as $key => $value) {
			if (0 === strpos($key, 'updraft_restore_')) {
				$nkey = substr($key, 16);
				if (!isset($entities_to_restore[$nkey])) {
					$_POST['updraft_restore'][] = $nkey;
					if (empty($backup_set['meta_foreign'])) {
						$entities_to_restore[$nkey] = $nkey;
					} else {
						if ('db' == $entity && !empty($foreign_known[$backup_set['meta_foreign']]['separatedb'])) {
							$entities_to_restore[$nkey] = 'db';
						} else {
							$entities_to_restore[$nkey] = 'wpcore';
						}
					}
				}
			}
		}

		if (0 == count($_POST['updraft_restore'])) {
			echo '<p>'.__('ABORT: Could not find the information on which entities to restore.', 'updraftplus').'</p>';
			echo '<p>'.__('If making a request for support, please include this information:','updraftplus').' '.count($_POST).' : '.htmlspecialchars(serialize($_POST)).'</p>';
			return new WP_Error('missing_info', 'Backup information not found');
		}

		$updraftplus->log("Restore job started. Entities to restore: ".implode(', ', array_flip($entities_to_restore)));

		$this->entities_to_restore = $entities_to_restore;

		set_error_handler(array($updraftplus, 'php_error'), E_ALL & ~E_STRICT);

		/*
		$_POST['updraft_restore'] is typically something like: array( 0=>'db', 1=>'plugins', 2=>'themes'), etc.
		i.e. array ( 'db', 'plugins', themes')
		*/

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		uksort($backup_set, array($this, 'sort_restoration_entities'));

		// We use a single object for each entity, because we want to store information about the backup set
		require_once(UPDRAFTPLUS_DIR.'/restorer.php');

		global $updraftplus_restorer;
		$updraftplus_restorer = new Updraft_Restorer(new Updraft_Restorer_Skin, $backup_set);

		$second_loop = array();

		echo "<h2>".__('Final checks', 'updraftplus').'</h2>';

		if (empty($backup_set['meta_foreign'])) {
			$entities_to_download = $entities_to_restore;
		} else {
			if (!empty($foreign_known[$backup_set['meta_foreign']]['separatedb'])) {
				$entities_to_download = array();
				if (in_array('db', $entities_to_restore)) {
					$entities_to_download['db'] = 1;
				}
				if (count($entities_to_restore) > 1 || !in_array('db', $entities_to_restore)) {
					$entities_to_download['wpcore'] = 1;
				}
			} else {
				$entities_to_download = array('wpcore' => 1);
			}
		}

		// First loop: make sure that files are present + readable; and populate array for second loop
		foreach ($backup_set as $type => $files) {
			// All restorable entities must be given explicitly, as we can store other arbitrary data in the history array
			if (!isset($backupable_entities[$type]) && 'db' != $type) continue;
			if (isset($backupable_entities[$type]['restorable']) && $backupable_entities[$type]['restorable'] == false) continue;

			if (!isset($entities_to_download[$type])) continue;
			if ('wpcore' == $type && is_multisite() && 0 === $updraftplus_restorer->ud_backup_is_multisite) {
				echo "<p>$type: <strong>";
				echo __('Skipping restoration of WordPress core when importing a single site into a multisite installation. If you had anything necessary in your WordPress directory then you will need to re-add it manually from the zip file.', 'updraftplus');
				#TODO
				#$updraftplus->log_e('Skipping restoration of WordPress core when importing a single site into a multisite installation. If you had anything necessary in your WordPress directory then you will need to re-add it manually from the zip file.');
				echo "</strong></p>";
				continue;
			}

			if (is_string($files)) $files=array($files);

			foreach ($files as $ind => $file) {
				$fullpath = $updraft_dir.$file;
				echo sprintf(__("Looking for %s archive: file name: %s", 'updraftplus'), $type, htmlspecialchars($file))."<br>";

				add_action('http_request_args', array($updraftplus, 'modify_http_options'));
				foreach ($service as $serv) {
					if(!is_readable($fullpath)) {
						$sd = (empty($updraftplus->backup_methods[$serv])) ? $serv : $updraftplus->backup_methods[$serv];
						echo __("File is not locally present - needs retrieving from remote storage",'updraftplus')." ($sd)";
						$this->download_file($file, $serv);
						echo ": ";
						if (!is_readable($fullpath)) {
							echo __("Error", 'updraftplus');
						} else {
							echo __("OK", 'updraftplus');
						}
						echo '<br>';
					}
				}
				remove_action('http_request_args', array($updraftplus, 'modify_http_options'));

				$index = ($ind == 0) ? '' : $ind;
				// If a file size is stored in the backup data, then verify correctness of the local file
				if (isset($backup_history[$timestamp][$type.$index.'-size'])) {
					$fs = $backup_history[$timestamp][$type.$index.'-size'];
					echo __("Archive is expected to be size:",'updraftplus')." ".round($fs/1024, 1)." Kb: ";
					$as = @filesize($fullpath);
					if ($as == $fs) {
						echo __('OK','updraftplus').'<br>';
					} else {
						echo "<strong>".__('Error:','updraftplus')."</strong> ".__('file is size:', 'updraftplus')." ".round($as/1024)." ($fs, $as)<br>";
					}
				} else {
					echo __("The backup records do not contain information about the proper size of this file.",'updraftplus')."<br>";
				}
				if (!is_readable($fullpath)) {
					echo __('Could not find one of the files for restoration', 'updraftplus')." ($file)<br>";
					$updraftplus->log("$file: ".__('Could not find one of the files for restoration', 'updraftplus'), 'error');
					echo '</div>';
					restore_error_handler();
					return false;
				}
			}

			if (empty($updraftplus_restorer->ud_foreign)) {
				$types = array($type);
			} else {
				if ('db' != $type || empty($foreign_known[$updraftplus_restorer->ud_foreign]['separatedb'])) {
					$types = array('wpcore');
				} else {
					$types = array('db');
				}
			}

			foreach ($types as $check_type) {
				$info = (isset($backupable_entities[$check_type])) ? $backupable_entities[$check_type] : array();
				$val = $updraftplus_restorer->pre_restore_backup($files, $check_type, $info);
				if (is_wp_error($val)) {
					$updraftplus->log_wp_error($val);
					foreach ($val->get_error_messages() as $msg) {
						echo '<strong>'.__('Error:',  'updraftplus').'</strong> '.htmlspecialchars($msg).'<br>';
					}
					foreach ($val->get_error_codes() as $code) {
						if ('already_exists' == $code) $this->print_delete_old_dirs_form(false);
					}
					echo '</div>'; //close the updraft_restore_progress div even if we error
					restore_error_handler();
					return $val;
				} elseif (false === $val) {
					echo '</div>'; //close the updraft_restore_progress div even if we error
					restore_error_handler();
					return false;
				}
			}

			foreach ($entities_to_restore as $entity => $via) {
				if ($via == $type) $second_loop[$entity] = $files;
			}
		
		}

		$updraftplus_restorer->delete = (UpdraftPlus_Options::get_updraft_option('updraft_delete_local')) ? true : false;
		if ('none' === $service || 'email' === $service || empty($service) || (is_array($service) && 1 == count($service) && (in_array('none', $service) || in_array('', $service) || in_array('email', $service))) || !empty($updraftplus_restorer->ud_foreign)) {
			if ($updraftplus_restorer->delete) $updraftplus->log_e('Will not delete any archives after unpacking them, because there was no cloud storage for this backup');
			$updraftplus_restorer->delete = false;
		}

		if (!empty($updraftplus_restorer->ud_foreign)) $updraftplus->log("Foreign backup; created by: ".$updraftplus_restorer->ud_foreign);

		// Second loop: now actually do the restoration
		uksort($second_loop, array($this, 'sort_restoration_entities'));
		foreach ($second_loop as $type => $files) {
			# Types: uploads, themes, plugins, others, db
			$info = (isset($backupable_entities[$type])) ? $backupable_entities[$type] : array();

			echo ('db' == $type) ? "<h2>".__('Database','updraftplus')."</h2>" : "<h2>".$info['description']."</h2>";
			$updraftplus->log("Entity: ".$type);

			if (is_string($files)) $files = array($files);
			foreach ($files as $fkey => $file) {
				$last_one = (1 == count($second_loop) && 1 == count($files));

				$val = $updraftplus_restorer->restore_backup($file, $type, $info, $last_one);

				if(is_wp_error($val)) {
					$updraftplus->log_e($val);
					foreach ($val->get_error_messages() as $msg) {
						echo '<strong>'.__('Error message',  'updraftplus').':</strong> '.htmlspecialchars($msg).'<br>';
					}
					$codes = $val->get_error_codes();
					if (is_array($codes)) {
						foreach ($codes as $code) {
							$data = $val->get_error_data($code);
							if (!empty($data)) {
								$pdata = (is_string($data)) ? $data : serialize($data);
								echo '<strong>'.__('Error data:', 'updraftplus').'</strong> '.htmlspecialchars($pdata).'<br>';
								if (false !== strpos($pdata, 'PCLZIP_ERR_BAD_FORMAT (-10)')) {
									echo '<a href="http://updraftplus.com/faqs/error-message-pclzip_err_bad_format-10-invalid-archive-structure-mean/"><strong>'.__('Please consult this FAQ for help on what to do about it.', 'updraftplus').'</strong></a><br>';
								}
							}
						}
					}
					echo '</div>'; //close the updraft_restore_progress div even if we error
					restore_error_handler();
					return $val;
				} elseif (false === $val) {
					echo '</div>'; //close the updraft_restore_progress div even if we error
					restore_error_handler();
					return false;
				}
				unset($files[$fkey]);
			}
			unset($second_loop[$type]);
		}

		foreach (array('template', 'stylesheet', 'template_root', 'stylesheet_root') as $opt) {
			add_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}
		if (!function_exists('validate_current_theme')) require_once(ABSPATH.WPINC.'/themes');

			# Have seen a case where the current theme in the DB began with a capital, but not on disk - and this breaks migrating from Windows to a case-sensitive system
			$template = get_option('template');
			if (!empty($template) && $template != WP_DEFAULT_THEME && $template != strtolower($template)) {

				$theme_root = get_theme_root($template);
				$theme_root2 = get_theme_root(strtolower($template));

				if (!file_exists("$theme_root/$template/style.css") && file_exists("$theme_root/".strtolower($template)."/style.css")) {
					$updraftplus->log_e("Theme directory (%s) not found, but lower-case version exists; updating database option accordingly", $template);
					update_option('template', strtolower($template));
				}

			}

		if (!validate_current_theme()) {
			global $updraftplus;
			echo '<strong>';
			$updraftplus->log_e("The current theme was not found; to prevent this stopping the site from loading, your theme has been reverted to the default theme");
			echo '</strong>';
		}
		#foreach (array('template', 'stylesheet', 'template_root', 'stylesheet_root') as $opt) {
		#	remove_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		#}

		echo '</div>'; //close the updraft_restore_progress div

		restore_error_handler();
		return true;
	}

	public function option_filter_template($val) { global $updraftplus; return $updraftplus->option_filter_get('template'); }

	public function option_filter_stylesheet($val) { global $updraftplus; return $updraftplus->option_filter_get('stylesheet'); }

	public function option_filter_template_root($val) { global $updraftplus; return $updraftplus->option_filter_get('template_root'); }

	public function option_filter_stylesheet_root($val) { global $updraftplus; return $updraftplus->option_filter_get('stylesheet_root'); }

	function sort_restoration_entities($a, $b) {
		if ($a == $b) return 0;
		# Put the database first
		# Put wpcore after plugins/uploads/themes (needed for restores of foreign all-in-one formats)
		if ('db' == $a || 'wpcore' == $b) return -1;
		if ('db' == $b || 'wpcore' == $a) return 1;
		# After wpcore, next last is others
		if ('others' == $b) return -1;
		if ('others' == $a) return 1;
		return strcmp($a, $b);
	}

	public function return_array($input) {
		if (!is_array($input)) $input = array();
		return $input;
	}

	# TODO: Remove legacy storage setting keys from here
	private function get_settings_keys() {
		return array('updraft_autobackup_default', 'updraft_dropbox', 'updraft_googledrive', 'updraftplus_tmp_googledrive_access_token', 'updraftplus_dismissedautobackup', 'updraftplus_dismissedexpiry', 'updraft_interval', 'updraft_interval_increments', 'updraft_interval_database', 'updraft_retain', 'updraft_retain_db', 'updraft_encryptionphrase', 'updraft_service', 'updraft_dropbox_appkey', 'updraft_dropbox_secret', 'updraft_googledrive_clientid', 'updraft_googledrive_secret', 'updraft_googledrive_remotepath', 'updraft_ftp_login', 'updraft_ftp_pass', 'updraft_ftp_remote_path', 'updraft_server_address', 'updraft_dir', 'updraft_email', 'updraft_delete_local', 'updraft_debug_mode', 'updraft_include_plugins', 'updraft_include_themes', 'updraft_include_uploads', 'updraft_include_others', 'updraft_include_wpcore', 'updraft_include_wpcore_exclude', 'updraft_include_more', 'updraft_include_blogs', 'updraft_include_mu-plugins', 'updraft_include_others_exclude', 'updraft_include_uploads_exclude',
		'updraft_lastmessage', 'updraft_googledrive_token', 'updraft_dropboxtk_request_token', 'updraft_dropboxtk_access_token', 'updraft_dropbox_folder', 'updraft_adminlocking',
		'updraft_last_backup', 'updraft_starttime_files', 'updraft_starttime_db', 'updraft_startday_db', 'updraft_startday_files', 'updraft_sftp_settings', 'updraft_s3', 'updraft_s3generic', 'updraft_dreamhost', 'updraft_s3generic_login', 'updraft_s3generic_pass', 'updraft_s3generic_remote_path', 'updraft_s3generic_endpoint', 'updraft_webdav_settings', 'updraft_disable_ping', 'updraft_openstack', 'updraft_bitcasa', 'updraft_copycom', 'updraft_cloudfiles', 'updraft_cloudfiles_user', 'updraft_cloudfiles_apikey', 'updraft_cloudfiles_path', 'updraft_cloudfiles_authurl', 'updraft_ssl_useservercerts', 'updraft_ssl_disableverify', 'updraft_s3_login', 'updraft_s3_pass', 'updraft_s3_remote_path', 'updraft_dreamobjects_login', 'updraft_dreamobjects_pass', 'updraft_dreamobjects_remote_path', 'updraft_report_warningsonly', 'updraft_report_wholebackup', 'updraft_log_syslog', 'updraft_extradatabases');
	}

}
