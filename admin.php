<?php

// TODO: Indicate warnings + errors to front-end on analysis of archives
// TODO: Multi-archive sets - downloaders + deleter needs to handle these

if (!defined ('ABSPATH')) die ('No direct access allowed');

// For the purposes of improving site performance (don't load in 10s of Kilobytes of un-needed code on every page load), admin-area code is being progressively moved here.

// This gets called in admin_init, earlier than default (so our object can get used by those hooking admin_init). Or possibly in admin_menu.

global $updraftplus_admin;
if (empty($updraftplus_admin)) $updraftplus_admin = new UpdraftPlus_Admin();

class UpdraftPlus_Admin {

	function __construct() {
		$this->admin_init();
	}

	function admin_init() {

		add_action('admin_head', array($this,'admin_head'));
		add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);
		add_action('wp_ajax_updraft_download_backup', array($this, 'updraft_download_backup'));
		add_action('wp_ajax_updraft_ajax', array($this, 'updraft_ajax_handler'));
		add_action('wp_ajax_plupload_action', array($this,'plupload_action'));
		add_action('wp_ajax_plupload_action2', array($this,'plupload_action2'));

		global $updraftplus, $wp_version, $pagenow;
		add_filter('updraftplus_dirlist_others', array($updraftplus, 'backup_others_dirlist'));

		// First, the checks that are on all (admin) pages:

		if (UpdraftPlus_Options::user_can_manage() && UpdraftPlus_Options::get_updraft_option('updraft_service') == "googledrive" && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid','') != '' && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token','') == '') {
			add_action('admin_notices', array($this,'show_admin_warning_googledrive') );
		}

		if (UpdraftPlus_Options::user_can_manage() && UpdraftPlus_Options::get_updraft_option('updraft_service') == "dropbox" && UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token','') == '') {
			add_action('admin_notices', array($this,'show_admin_warning_dropbox') );
		}

		if (UpdraftPlus_Options::user_can_manage() && $this->disk_space_check(1024*1024*35) === false) add_action('admin_notices', array($this, 'show_admin_warning_diskspace'));

		// Next, the actions that only come on settings pages
		// if ($pagenow != 'options-general.php') return;

		// Next, the actions that only come on the UpdraftPlus page
		if ($pagenow != 'options-general.php' || !isset($_REQUEST['page']) || 'updraftplus' != $_REQUEST['page']) return;

		if(UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
			@ini_set('display_errors',1);
			@error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
			add_action('admin_notices', array($this, 'show_admin_debug_warning'));
		}

		// W3 Total Cache's object cache eats transients during cron jobs. Reported to them many times by multiple people.
		if (defined('W3TC') && W3TC == true) {
			if (function_exists('w3_instance')) {
				$modules = w3_instance('W3_ModuleStatus');
				if ($modules->is_enabled('objectcache')) {
					add_action('admin_notices', array($this, 'show_admin_warning_w3_total_cache'));
				}
			}
		}

		// LiteSpeed has a generic problem with terminating cron jobs
		if (isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false) {
			if (!is_file(ABSPATH.'.htaccess') || !preg_match('/noabort/i', file_get_contents(ABSPATH.'.htaccess'))) {
				add_action('admin_notices', array($this, 'show_admin_warning_litespeed'));
			}
		}

		if (version_compare($wp_version, '3.2', '<')) add_action('admin_notices', array($this, 'show_admin_warning_wordpressversion'));

			wp_enqueue_script('jquery');
			wp_enqueue_script('jquery-ui-dialog');
			wp_enqueue_script('plupload-all');
			wp_register_script('updraftplus-plupload', UPDRAFTPLUS_URL.'/includes/ud-plupload.js', array('jquery'));
			wp_enqueue_script('updraftplus-plupload');

	}

	function admin_head() {

		global $pagenow;
		if ($pagenow != 'options-general.php' || !isset($_REQUEST['page']) || 'updraftplus' != $_REQUEST['page']) return;

 		$chunk_size = min(wp_max_upload_size()-1024, 1024*1024*2);

		$plupload_init = array(
			'runtimes' => 'html5,silverlight,flash,html4',
			'browse_button' => 'plupload-browse-button',
			'container' => 'plupload-upload-ui',
			'drop_element' => 'drag-drop-area',
			'file_data_name' => 'async-upload',
			'multiple_queues' => true,
			'max_file_size' => '100Gb',
			'chunk_size' => $chunk_size.'b',
			'url' => admin_url('admin-ajax.php'),
			'flash_swf_url' => includes_url('js/plupload/plupload.flash.swf'),
			'silverlight_xap_url' => includes_url('js/plupload/plupload.silverlight.xap'),
			'filters' => array(array('title' => __('Allowed Files'), 'extensions' => 'zip,gz,crypt')),
			'multipart' => true,
			'multi_selection' => true,
			'urlstream_upload' => true,
			// additional post data to send to our ajax hook
			'multipart_params' => array(
				'_ajax_nonce' => wp_create_nonce('updraft-uploader'),
				'action' => 'plupload_action'
			)
		);

		?><script type="text/javascript">var updraft_plupload_config=<?php echo json_encode($plupload_init); ?>;</script>
		<?php
			$plupload_init['browse_button'] = 'plupload-browse-button2';
			$plupload_init['container'] = 'plupload-upload-ui2';
			$plupload_init['drop_element'] = 'drag-drop-area2';
			$plupload_init['multipart_params']['action'] = 'plupload_action2';
			$plupload_init['filters'] = array(array('title' => __('Allowed Files'), 'extensions' => 'crypt'));
		?><script type="text/javascript">var updraft_plupload_config2=<?php echo json_encode($plupload_init); ?>;
		var updraft_downloader_nonce = '<?php wp_create_nonce("updraftplus_download"); ?>'
		</script>
		<style type="text/css">
		.updraftplus-remove a {
			color: red;
		}
		.updraftplus-remove:hover {
			background-color: red;
		}
		.updraftplus-remove a:hover {
			color: #fff;
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

	function googledrive_remove_folderurlprefix($input) {
		return str_replace('https://drive.google.com/#folders/', '', $input);
	}

	function disk_space_check($space) {
		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();
		$disk_free_space = @disk_free_space($updraft_dir);
		if ($disk_free_space == false) return -1;
		return ($disk_free_space > $space) ? true : false;
	}

	# Adds the settings link under the plugin on the plugin screen.
	function plugin_action_links($links, $file) {
		if ($file == 'updraftplus/updraftplus.php'){
			$settings_link = '<a href="'.site_url().'/wp-admin/options-general.php?page=updraftplus">'.__("Settings", "updraftplus").'</a>';
			array_unshift($links, $settings_link);
// 			$settings_link = '<a href="http://david.dw-perspective.org.uk/donate">'.__("Donate","UpdraftPlus").'</a>';
// 			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://updraftplus.com">'.__("Add-Ons / Pro Support","updraftplus").'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}


	function show_admin_warning($message, $class = "updated") {
		echo '<div id="updraftmessage" class="'.$class.' fade">'."<p>$message</p></div>";
	}

	function show_admin_warning_diskspace() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('You have less than %s of free disk space on the disk which UpdraftPlus is configured to use to create backups. UpdraftPlus could well run out of space. Contact your the operator of your server (e.g. your web hosting company) to resolve this issue.','updraftplus'),'35 Mb'));
	}

	function show_admin_warning_wordpressversion() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('UpdraftPlus does not officially support versions of WordPress before %s. It may work for you, but if it does not, then please be aware that no support is available until you upgrade WordPress.'),'3.2'),'updraftplus');
	}

	function show_admin_warning_litespeed() {
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('Your website is hosted using the %s web server.','updraftplus'),'LiteSpeed').' <a href="http://updraftplus.com/faqs/i-am-having-trouble-backing-up-and-my-web-hosting-company-uses-the-litespeed-webserver/">'.__('Please consult this FAQ if you have problems backing up.', 'updraftplus').'</a>');
	}

	function show_admin_debug_warning() {
		$this->show_admin_warning('<strong>'.__('Notice','updraftplus').':</strong> '.__('UpdraftPlus\'s debug mode is on. You may see debugging notices on this page not just from UpdraftPlus, but from any other plugin installed. Please try to make sure that the notice you are seeing is from UpdraftPlus before you raise a support request.', 'updraftplus').'</a>');
	}

	function show_admin_warning_w3_total_cache() {
		$url = (is_multisite()) ? network_admin_url('admin.php?page=w3tc_general') : admin_url('admin.php?page=w3tc_general');
		$this->show_admin_warning('<strong>'.__('Warning','updraftplus').':</strong> '.__('W3 Total Cache\'s object cache is active. This is known to have a bug that messes with all scheduled tasks (including backup jobs).','updraftplus').' <a href="'.$url.'#object_cache">'.__('Go here to turn it off.','updraftplus').'</a> '.sprintf(__('<a href="%s">Go here</a> for more information.', 'updraftplus'),'http://updraftplus.com/faqs/whats-the-deal-with-w3-total-caches-object-cache/'));
	}

	function show_admin_warning_dropbox() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth&updraftplus_dropboxauth=doit">'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Dropbox','Dropbox').'</a>');
	}

	function show_admin_warning_googledrive() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth&updraftplus_googleauth=doit">'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Google Drive','Google Drive').'</a>');
	}

	// This options filter removes ABSPATH off the front of updraft_dir, if it is given absolutely and contained within it
	function prune_updraft_dir_prefix($updraft_dir) {
		if ('/' == substr($updraft_dir, 0, 1) || "\\" == substr($updraft_dir, 0, 1) || preg_match('/^[a-zA-Z]:/', $updraft_dir)) {
			if (strpos($updraft_dir, ABSPATH) === 0) {
				$updraft_dir = substr($updraft_dir, strlen(ABSPATH));
			}
		}
		return $updraft_dir;
	}

	function updraft_download_backup() {

		@set_time_limit(900);

		global $updraftplus;

		if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'updraftplus_download')) die;

		if (!isset($_REQUEST['timestamp']) || !is_numeric($_REQUEST['timestamp']) ||  !isset($_REQUEST['type'])) exit;

		$backupable_entities = $updraftplus->get_backupable_file_entities(true);
		$type_match = false;
		foreach ($backupable_entities as $type => $info) {
			if ($_REQUEST['type'] == $type) $type_match = true;
		}

		if (!$type_match && $_REQUEST['type'] != 'db') exit;

		// Get the information on what is wanted
		$type = $_REQUEST['type'];
		$timestamp = $_REQUEST['timestamp'];

		// You need a nonce before you can set job data. And we certainly don't yet have one.
		$updraftplus->backup_time_nonce();

		$debug_mode = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');

		// Set the job type before logging, as there can be different logging destinations
		$updraftplus->jobdata_set('job_type', 'download');

		// Retrieve the information from our backup history
		$backup_history = $updraftplus->get_backup_history();
		// Base name
		$file = $backup_history[$timestamp][$type];

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

		$updraftplus->log("Requested to obtain file: timestamp=$timestamp, type=$type");

		$known_size = isset($backup_history[$timestamp][$type.'-size']) ? $backup_history[$timestamp][$type.'-size'] : 0;

		$service = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;
		$updraftplus->jobdata_set('service', $service);

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
		set_transient('ud_dlfile_'.$timestamp.'_'.$type, "downloading:$known_size:$fullpath", 3600);

		if ($needs_downloading) {
			// Close browser connection so that it can resume AJAX polling
			header('Content-Length: 0');
			header('Connection: close');
			header('Content-Encoding: none');
			if (session_id()) session_write_close();
			echo "\r\n\r\n";
			$this->download_file($file, $service);
			if (is_readable($fullpath)) {
				clearstatcache();
				$updraftplus->log('Remote fetch was successful (file size: '.round(filesize($fullpath)/1024,1).' Kb)');
			} else {
				$updraftplus->log('Remote fetch failed');
			}
		}

		// Now, spool the thing to the browser
		if(is_file($fullpath) && is_readable($fullpath)) {

			// That message is then picked up by the AJAX listener
			set_transient('ud_dlfile_'.$timestamp.'_'.$type, 'downloaded:'.filesize($fullpath).":$fullpath", 3600);

		} else {

			set_transient('ud_dlfile_'.$timestamp.'_'.$type, 'failed', 3600);
			set_transient('ud_dlerrors_'.$timestamp.'_'.$type, $updraftplus->errors, 3600);

			echo 'Remote fetch failed. File '.$fullpath.' did not exist or was unreadable. If you delete local backups then remote retrieval may have failed.';
		}

		@fclose($updraftplus->logfile_handle);
  		if (!$debug_mode) @unlink($updraftplus->logfile_name);

		exit;

	}

	function download_file($file, $service=false) {

		global $updraftplus;

		@set_time_limit(900);

		if (!$service) $service = UpdraftPlus_Options::get_updraft_option('updraft_service');

		$updraftplus->log("Requested file from remote service: $service: $file");

		$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
		if (file_exists($method_include)) require_once($method_include);

		$objname = "UpdraftPlus_BackupModule_${service}";
		if (method_exists($objname, "download")) {
			$remote_obj = new $objname;
			$remote_obj->download($file);
		} else {
			$updraftplus->log("Automatic backup restoration is not available with the method: $service.");
			$updraftplus->log("$file: ".sprintf(__("The backup archive for restoring this file could not be found. The remote storage method in use (%s) does not allow us to retrieve files. To proceed with this restoration, you need to obtain a copy of this file and place it inside UpdraftPlus's working folder", 'updraftplus'), $service)." (".$this->prune_updraft_dir_prefix($updraftplus->backups_dir_location()).")", 'error');
		}

	}

	// Called via AJAX
	function updraft_ajax_handler() {

		global $updraftplus;

		// Test the nonce
		$nonce = (empty($_REQUEST['nonce'])) ? "" : $_REQUEST['nonce'];
		if (! wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce') || empty($_REQUEST['subaction'])) die('Security check');

		if (isset($_GET['subaction']) && 'lastlog' == $_GET['subaction']) {
			echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '('.__('Nothing yet logged', 'updraftplus').')'));
		} elseif (isset($_GET['subaction']) && 'restore_alldownloaded' == $_GET['subaction'] && isset($_GET['timestamp'])) {
			echo '<p>'.__('The backup archive files have been processed - if all is well, then now press Restore again to proceed. Otherwise, cancel and correct any problems first.', 'updraftplus').'</p>';
			parse_str($_GET['restoreopts'], $res);

			$elements = array_flip($res['updraft_restore']);

			if (isset($res['updraft_restore']) && isset($elements['db'])) {

				// Analyse the header of the database file + display results
				echo '<p>';
				$this->analyse_db_file($_GET['timestamp'], $res);
				echo '</p>';

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

			if (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) $updraftplus->logfile_open($updraftplus->nonce);

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
							if ($service != 'none') {
								$delete_from_service[] = $backups[$timestamp]['service'];
							}
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
						$objname = "UpdraftPlus_BackupModule_${service}";
						$deleted = -1;
						if (method_exists($objname, "backup")) {
							# TODO: Re-use the object (i.e. prevent repeated connection setup/teardown)
							$remote_obj = new $objname;
							$deleted = $remote_obj->delete($files);
						}
						if ($deleted === -1) {
							//echo __('Did not know how to delete from this cloud service.', 'updraftplus');
						} elseif ($deleted !== false) {
							$remote_deleted++;
						} else {
							// Do nothing
						}
					}
				}
			}
			$message .= __('The backup set has been removed.', 'updraftplus')."\n";
			$message .= sprintf(__('Local archives deleted: %d', 'updraftplus'),$local_deleted)."\n";
			$message .= sprintf(__('Remote archives deleted: %d', 'updraftplus'),$remote_deleted)."\n";

			print json_encode(array('result' => 'success', 'message' => $message));

		} elseif ('rawbackuphistory' == $_REQUEST['subaction']) {
			echo '<h3>'.__('Known backups (raw)', 'updraftplus').'</h3><pre>';
			var_dump($updraftplus->get_backup_history());
			echo '</pre>';
			echo '<h3>Files</h3><pre>';
			$updraft_dir = $updraftplus->backups_dir_location();
			$d = dir($updraft_dir);
			while (false !== ($entry = $d->read())) {
				$fp = $updraft_dir.'/'.$entry;
				if (is_dir($fp)) {
					$size = '       d';
				} elseif (is_link($fp)) {
					$size = '       l';
				} elseif (is_file($fp)) {
					$size = sprintf("%8.1f", round(filesize($fp)/1024, 1));
				} else {
					$size = '       ?';
				}
				printf("%s %s \n", $size, $entry);
			}
			echo '</pre>';
			@$d->close();
		} elseif ('countbackups' == $_REQUEST['subaction']) {
			$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			$backup_history = (is_array($backup_history))?$backup_history:array();
			echo sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history));
		} elseif ('ping' == $_REQUEST['subaction']) {
			// The purpose of this is to detect brokenness caused by extra line feeds in plugins/themes - before it breaks other AJAX operations and leads to support requests
			echo 'pong';
		} elseif ('phpinfo' == $_REQUEST['subaction']) {
			phpinfo(INFO_ALL ^ (INFO_CREDITS | INFO_LICENSE));
		} elseif ('backupnow' == $_REQUEST['subaction']) {
			echo '<strong>',__('Schedule backup','updraftplus').':</strong> ';
			if (wp_schedule_single_event(time()+5, 'updraft_backup_all') === false) {
				$updraftplus->log("A backup run failed to schedule");
				echo __("Failed.",'updraftplus')."</div>";
			} else {
				// For unknown reasons, the <script> runs twice if put inside the <div>
				echo htmlspecialchars(__('OK. You should soon see activity in the "Last log message" field below.','updraftplus'))." <a href=\"http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/\">".__('Nothing happening? Follow this link for help.','updraftplus')."</a></div><script>setTimeout(function(){updraft_showlastbackup();}, 7000);</script>";
				$updraftplus->log("A backup run has been scheduled");
			}

		} elseif (isset($_GET['subaction']) && 'lastbackup' == $_GET['subaction']) {
			echo $this->last_backup_html();
		} elseif (isset($_GET['subaction']) && 'activejobs_list' == $_GET['subaction']) {
			$this->print_active_jobs();
		} elseif (isset($_GET['subaction']) && 'activejobs_delete' == $_GET['subaction'] && isset($_GET['jobid'])) {

			$cron = get_option('cron');
			$found_it = 0;
			foreach ($cron as $time => $job) {
				if (isset($job['updraft_backup_resume'])) {
					foreach ($job['updraft_backup_resume'] as $hook => $info) {
						if (isset($info['args'][1]) && $info['args'][1] == $_GET['jobid']) {
							$found_it = 1;
							$args = $cron[$time]['updraft_backup_resume'][$hook]['args'];
							wp_unschedule_event($time, 'updraft_backup_resume', $args);
							echo 'Y:'.__('Job deleted', 'updraftplus');
						}
					}
				}
			}

			if (!$found_it) { echo 'X:'.__('Could not find that job - perhaps it has already finished?', 'updraftplus'); }

			
		} elseif (isset($_GET['subaction']) && 'diskspaceused' == $_GET['subaction'] && isset($_GET['entity'])) {
			if ($_GET['entity'] == 'updraft') {
				echo $this->recursive_directory_size($updraftplus->backups_dir_location());
			} else {
				$backupable_entities = $updraftplus->get_backupable_file_entities(true, false);
				if (!empty($backupable_entities[$_GET['entity']])) {
					$dirs = apply_filters('updraftplus_dirlist_'.$_GET['entity'], $backupable_entities[$_GET['entity']]);
					echo $this->recursive_directory_size($dirs);
				} else {
					_e('Error','updraftplus');
				}
			}
		} elseif (isset($_GET['subaction']) && 'historystatus' == $_GET['subaction']) {
			$rescan = (isset($_GET['rescan']) && $_GET['rescan'] == 1);
			if ($rescan) $this->rebuild_backup_history();
			$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			$backup_history = (is_array($backup_history))?$backup_history:array();
			echo json_encode(array('n' => sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history)), 't' => $this->existing_backup_table($backup_history)));
		} elseif (isset($_GET['subaction']) && 'downloadstatus' == $_GET['subaction'] && isset($_GET['timestamp']) && isset($_GET['type'])) {

			$response = array();

			$response['m'] = get_transient('ud_dlmess_'.$_GET['timestamp'].'_'.$_GET['type']).'<br>';

			if ($file = get_transient('ud_dlfile_'.$_GET['timestamp'].'_'.$_GET['type'])) {
				if ('failed' == $file) {
					$response['e'] = __('Download failed','updraftplus').'<br>';
					$errs = get_transient('ud_dlerrors_'.$_GET['timestamp'].'_'.$_GET['type']);
					if (is_array($errs) && !empty($errs)) {
						$response['e'] .= '<ul style="list-style: disc inside;">';
						foreach ($errs as $err) {
							$response['e'] .= '<li>'.htmlspecialchars($err).'</li>';
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

			echo json_encode($response);

		} elseif (isset($_POST['subaction']) && $_POST['subaction'] == 'credentials_test') {
			$method = (preg_match("/^[a-z0-9]+$/", $_POST['method'])) ? $_POST['method'] : "";

			// Test the credentials, return a code
			require_once(UPDRAFTPLUS_DIR."/methods/$method.php");

			$objname = "UpdraftPlus_BackupModule_${method}";
			if (method_exists($objname, "credentials_test")) call_user_func(array('UpdraftPlus_BackupModule_'.$method, 'credentials_test'));
		}

		die;

	}

	function analyse_db_file($timestamp, $res) {
		global $updraftplus;
		$backup = $updraftplus->get_backup_history($timestamp);
		if (!isset($backup['nonce']) || !isset($backup['db'])) return;

		$updraft_dir = $updraftplus->backups_dir_location();

		$db_file = $updraft_dir.'/'.$backup['db'];

		if (!is_readable($db_file)) return;

		// Encrypted - decrypt it
		if ($updraftplus->is_db_encrypted($db_file)) {

			$encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');

			if (!$encryption) {
				echo sprintf(__('Error: %s', 'updraftplus'), __('Decryption failed. The database file is encrypted, but you have no encryption key entered.', 'updraftplus'));
				return;
			}

			require_once(UPDRAFTPLUS_DIR.'/includes/phpseclib/Crypt/Rijndael.php');
			$rijndael = new Crypt_Rijndael();

			// Get decryption key
			$rijndael->setKey($encryption);
			$ciphertext = $rijndael->decrypt(file_get_contents($db_file));
			if ($ciphertext) {
				$new_db_file = $updraft_dir.'/'.basename($db_file, '.crypt');
				if (!file_put_contents($new_db_file, $ciphertext)) {
					echo sprintf(__('Error: %s', 'updraftplus'), __('Failed to write out the decrypted database to the filesystem.','updraftplus'));
					return false;
				}
				$db_file = $new_db_file;
			} else {
				echo sprintf(__('Error: %s', 'updraftplus'), __('Decryption failed. The most likely cause is that you used the wrong key.','updraftplus'));
				return false;
			}
		}

		$dbhandle = gzopen($db_file, 'r');
		if (!$dbhandle) {
			echo sprintf(__('Error: %s', 'updraftplus'), __('Failed to open database file.','updraftplus'));
			return false;
		}

		# Analyse the file, print the results.

		$line = 0;
		$old_siteurl = '';
		$old_table_prefix = '';
		$old_siteinfo = array();
		$gathering_siteinfo = true;
		while (!gzeof($dbhandle) && $line < 100) {
			$line++;
			// Up to 1Mb
			$buffer = rtrim(gzgets($dbhandle, 1048576));
			// Comments are what we are interested in
			if (substr($buffer, 0, 1) == '#') {

				// TODO: More information - e.g. WordPress version. Warn if importing new into old.
				if ('' == $old_siteurl && preg_match('/^\# Backup of: (http(.*))$/', $buffer, $matches)) {
					$old_siteurl = $matches[1];
					echo __('Backup of:', 'updraftplus').' '.htmlspecialchars($old_siteurl).'<br>';
					// Check for should-be migration
					if ($old_siteurl != site_url()) {
						echo apply_filters('updraftplus_dbscan_urlchange', sprintf(__('Error: %s', 'updraftplus'), '<a href="http://updraftplus.com/shop/migrator/">'.__('This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus').'</a>'), $old_siteurl, $res);
					}
				} elseif ('' == $old_table_prefix && preg_match('/^\# Table prefix: (\S+)$/', $buffer, $matches)) {
					$old_table_prefix = $matches[1];
// 					echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
				} elseif ($gathering_siteinfo && preg_match('/^\# Site info: (\S+)$/', $buffer, $matches)) {
					if ('end' == $matches[1]) {
						$gathering_siteinfo = false;
						// Sanity checks
						if (isset($old_siteinfo['multisite']) && !$old_siteinfo['multisite'] && is_multisite()) {
							// Just need to check that you're crazy
							if (!defined('UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE') ||  UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE != true) {
								echo sprintf(__('Error: %s', 'updraftplus'), __('You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus'));
								return false;
							}
							// Got the needed code?
							if (!class_exists('UpdraftPlusAddOn_MultiSite') || !class_exists('UpdraftPlus_Addons_Migrator')) {
								 echo sprintf(__('Error: %s', 'updraftplus'), __('To import an ordinary WordPress site into a multisite installation requires both the multisite and migrator add-ons.', 'updraftplus'));
								return false;
							}
						}
					} elseif (preg_match('/^([^=]+)=(.*)$/', $matches[1], $kvmatches)) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						if ('multisite' == $key && $val) {
							echo '<strong>'.__('Site information:','updraftplus').'</strong>'.' is a WordPress Network<br>';
						}
						$old_siteinfo[$key]=$val;
					}
				}

			}
		}

		@gzclose($dbhandle);

	}

	function upload_dir($uploads) {
		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();
		if (is_writable($updraft_dir)) $uploads['path'] = $updraft_dir;
		return $uploads;
	}

	// We do actually want to over-write
	function unique_filename_callback($dir, $name, $ext) {
		return $name.$ext;
	}

	function sanitize_file_name($filename) {
		// WordPress 3.4.2 on multisite (at least) adds in an unwanted underscore
		return preg_replace('/-db\.gz_\.crypt$/', '-db.gz.crypt', $filename);
	}

	function plupload_action() {
		// check ajax nonce

		global $updraftplus;
		@set_time_limit(900);

		check_ajax_referer('updraft-uploader');

		$updraft_dir = $updraftplus->backups_dir_location();
		if (!is_writable($updraft_dir)) exit;

		add_filter('upload_dir', array($this, 'upload_dir'));
		add_filter('sanitize_file_name', array($this, 'sanitize_file_name'));
		// handle file upload

		$farray = array( 'test_form' => true, 'action' => 'plupload_action' );

		$farray['test_type'] = false;
		$farray['ext'] = 'x-gzip';
		$farray['type'] = 'application/octet-stream';

		if (isset($_POST['chunks'])) {

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
			$final_file = $_POST['name'];
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
			if (!preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(zip|gz|gz\.crypt)$/i', $file)) {

				@unlink($status['file']);
				echo 'ERROR:'.__('Bad filename format - this does not look like a file created by UpdraftPlus','updraftplus');
				exit;
			}
		}

		// send the uploaded file url in response
		echo 'OK:'.$status['url'];
		exit;
	}

	function plupload_action2() {

		@set_time_limit(900);
		global $updraftplus;

		// check ajax nonce
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
			$final_file = $_POST['name'];
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
			if (!preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(gz\.crypt)$/i', $file)) {

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


	function settings_output() {

		global $updraftplus;

		wp_enqueue_style('jquery-ui', UPDRAFTPLUS_URL.'/includes/jquery-ui-1.8.22.custom.css'); 

		/*
		we use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credentials 
		for the WP_Filesystem. to do this WP outputs a form, but we don't pass our parameters via that. So the values are 
		passed back in as GET parameters. REQUEST covers both GET and POST so this logic works.
		*/
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_restore' && isset($_REQUEST['backup_timestamp'])) {
			$backup_success = $this->restore_backup($_REQUEST['backup_timestamp']);
			if(empty($updraftplus->errors) && $backup_success === true) {
				// If we restored the database, then that will have out-of-date information which may confuse the user - so automatically re-scan for them.
				$this->rebuild_backup_history();
				echo '<p><strong>'.__('Restore successful!','updraftplus').'</strong></p>';
				echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus&updraft_restore_success=true">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
				return;
			} elseif (is_wp_error($backup_success)) {
				echo '<p>Restore failed...</p><ul style="list-style: disc inside;">';
				foreach ($updraftplus->errors as $err) {
					if (is_wp_error($err)) {
						foreach ($err->get_error_messages() as $msg) {
							echo '<li>'.htmlspecialchars($msg).'<li>';
						}
					} elseif (is_string($err)) {
						echo  "<li>".htmlspecialchars($err)."</li>";
					} else {
						print "<li>".print_r($err,true)."</li>";
					}
				}
				echo '</ul><b>Actions:</b> <a href="options-general.php?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
				return;
			} elseif (false === $backup_success) {
				# This means, "not yet - but stay on the page because we may be able to do it later, e.g. if the user types in the requested information"
				return;
			}
			//uncomment the below once i figure out how i want the flow of a restoration to work.
			//echo '<b>'__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus">Return to UpdraftPlus Configuration</a>';
		}
		$deleted_old_dirs = false;
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_delete_old_dirs') {
			
			echo '<h1>UpdraftPlus - '.__('Remove old directories','updraftplus').'</h1>';

			$nonce = (empty($_REQUEST['_wpnonce'])) ? "" : $_REQUEST['_wpnonce'];
			if (!wp_verify_nonce($nonce, 'updraft_delete_old_dirs')) die('Security check');

			if($this->delete_old_dirs()) {
				echo '<p>'.__('Old directories successfully removed.','updraftplus').'</p><br/>';
				$deleted_old_dirs = true;
			} else {
				echo '<p>',__('Old directory removal failed for some reason. You may want to do this manually.','updraftplus').'</p><br/>';
			}
			echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
			return;
		}
		
		if(isset($_GET['error'])) $this->show_admin_warning(htmlspecialchars($_GET['error']), 'error');
		if(isset($_GET['message'])) $this->show_admin_warning(htmlspecialchars($_GET['message']));

		if(isset($_GET['action']) && $_GET['action'] == 'updraft_create_backup_dir' && isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'create_backup_dir')) {
			$created = $this->create_backup_dir();
			if(is_wp_error($created)) {
				echo '<p>'.__('Backup directory could not be created','updraftplus').'...<br/>';
				echo '<ul style="list-style: disc inside;">';
				foreach ($created->get_error_messages() as $key => $msg) {
					echo '<li>'.htmlspecialchars($msg).'</li>';
				}
				echo '</ul></p>';
			} elseif ($created !== false) {
				echo '<p>'.__('Backup directory successfully created.','updraftplus').'</p><br/>';
			}
			echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
			return;
		}
		
		echo '<div id="updraft_backup_started" class="updated fade" style="display:none; max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;"></div>';

		// updraft_file_ids is not deleted
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_all') { $updraftplus->boot_backup(true,true); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_db') { $updraftplus->backup_db(); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_wipesettings') {
			$settings = array('updraft_interval', 'updraft_interval_database', 'updraft_retain', 'updraft_retain_db', 'updraft_encryptionphrase', 'updraft_service', 'updraft_dropbox_appkey', 'updraft_dropbox_secret', 'updraft_googledrive_clientid', 'updraft_googledrive_secret', 'updraft_googledrive_remotepath', 'updraft_ftp_login', 'updraft_ftp_pass', 'updraft_ftp_remote_path', 'updraft_server_address', 'updraft_dir', 'updraft_email', 'updraft_delete_local', 'updraft_debug_mode', 'updraft_include_plugins', 'updraft_include_themes', 'updraft_include_uploads', 'updraft_include_others', 'updraft_include_wpcore', 'updraft_include_wpcore_exclude', 'updraft_include_more', 
			'updraft_include_blogs', 'updraft_include_mu-plugins', 'updraft_include_others_exclude', 'updraft_lastmessage', 'updraft_googledrive_clientid', 'updraft_googledrive_token', 'updraft_dropboxtk_request_token', 'updraft_dropboxtk_access_token', 'updraft_dropbox_folder', 'updraft_last_backup', 'updraft_starttime_files', 'updraft_starttime_db', 'updraft_sftp_settings', 'updraft_s3generic_login', 'updraft_s3generic_pass', 'updraft_s3generic_remote_path', 'updraft_s3generic_endpoint', 'updraft_webdav_settings', 'updraft_disable_ping', 'updraft_cloudfiles_user', 'updraft_cloudfiles_apikey', 'updraft_cloudfiles_path', 'updraft_cloudfiles_authurl', 'updraft_ssl_useservercerts', 'updraft_ssl_disableverify', 'updraft_s3_login', 'updraft_s3_pass', 'updraft_s3_remote_path', 'updraft_dreamobjects_login', 'updraft_dreamobjects_pass', 'updraft_dreamobjects_remote_path');
			foreach ($settings as $s) {
				UpdraftPlus_Options::delete_updraft_option($s);
			}
			$this->show_admin_warning(__("Your settings have been wiped.",'updraftplus'));
		}

		?>
		<div class="wrap">
			<h1><?php echo $updraftplus->plugin_title; ?></h1>

			<?php _e('By UpdraftPlus.Com','updraftplus')?> ( <a href="http://updraftplus.com">UpdraftPlus.Com</a> | <a href="http://updraftplus.com/news/"><?php _e('News','updraftplus');?></a> | <?php if (!defined('UPDRAFTPLUS_NOADS')) { ?><a href="http://updraftplus.com/shop/"><?php _e("Premium",'updraftplus');?></a>  | <?php } ?><a href="http://david.dw-perspective.org.uk"><?php _e("Lead developer's homepage",'updraftplus');?></a> | <?php if (1==0 && !defined('UPDRAFTPLUS_NOADS')) { ?><a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate"><?php _e('Donate','updraftplus');?></a> | <?php } ?><a href="http://updraftplus.com/support/frequently-asked-questions/">FAQs</a> | <a href="http://profiles.wordpress.org/davidanderson/"><?php _e('Other WordPress plugins','updraftplus');?></a>). <?php _e('Version','updraftplus');?>: <?php echo $updraftplus->version; ?>
			<br>
			<?php
			if(isset($_GET['updraft_restore_success'])) {
				echo "<div class=\"updated fade\" style=\"padding:8px;\"><strong>".__('Your backup has been restored.','updraftplus').'</strong> '.__('Your old (themes, uploads, plugins, whatever) directories have been retained with "-old" appended to their name. Remove them when you are satisfied that the backup worked properly.')."</div>";
			}

			$ws_advert = $updraftplus->wordshell_random_advert(1);
			if ($ws_advert) { echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;">'.$ws_advert.'</div>'; }

			if($deleted_old_dirs) echo '<div style="color:blue" class=\"updated fade\">'.__('Old directories successfully deleted.','updraftplus').'</div>';

			if(!$updraftplus->memory_check(64)) {?>
				<div style="color:orange"><?php _e("Your PHP memory limit (set by your web hosting company) is quite low. UpdraftPlus attempted to raise it but was unsuccessful. This plugin may struggle with a memory limit of less than 64 Mb  - especially if you have very large files uploaded (though on the other hand, many sites will bhe  successful with a 32Mb limit - your experience may vary).",'updraftplus');?> <?php _e('Current limit is:','updraftplus');?> <?php echo $updraftplus->memory_check_current(); ?> Mb</div>
			<?php
			}
			if(1==0 && !$this->execution_time_check(60)) {?>
				<div style="color:orange"><?php _e("Your PHP max_execution_time is less than 60 seconds. This possibly means you're running in safe_mode. Either disable safe_mode or modify your php.ini to set max_execution_time to a higher number. If you do not, then longer will be needed to complete a backup (but that is all). Present limit is:",'updraftplus');?> <?php echo ini_get('max_execution_time').' '.__('seconds','updraftplus')?>.</div>
			<?php
			}
			if($this->scan_old_dirs()) {?>
				<div class="updated fade" style="padding:8px;"><?php _e('You have old directories from a previous backup (technical information: these are suffixed with -old). Use this button to delete them (if you have verified that the restoration worked).','updraftplus');?>
				<form method="post" action="<?php echo remove_query_arg(array('updraft_restore_success','action')) ?>">
					<?php wp_nonce_field('updraft_delete_old_dirs'); ?>
					<input type="hidden" name="action" value="updraft_delete_old_dirs" />
					<input type="submit" class="button-primary" value="<?php _e('Delete Old Directories','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('Are you sure you want to delete the old directories? This cannot be undone.','updraftplus'));?>'))" />
				</form>
				</div>
			<?php
			}
			if(!empty($updraftplus->errors)) {
				echo '<div class="error fade" style="padding:8px;">';
				foreach($updraftplus->errors as $error) {
					echo '<div style="color:red">'.$error.'</div>';
				}
				echo '</div>';
			}
			?>

			<h2 style="clear:left;"><?php _e('Existing Schedule And Backups','updraftplus');?></h2>
			<table class="form-table" style="float:left; clear: both; width:545px;">
				<noscript>
				<tr>
					<th><?php _e('JavaScript warning','updraftplus');?>:</th>
					<td style="color:red"><?php _e('This admin interface uses JavaScript heavily. You either need to activate it within your browser, or to use a JavaScript-capable browser.','updraftplus');?></td>
				</tr>
				</noscript>
					<?php
					$updraft_dir = $updraftplus->backups_dir_location();
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
							$next_scheduled_backup_database = __('Nothing currently scheduled','updraftplus');
						}
					}
					$current_time = get_date_from_gmt(gmdate('Y-m-d H:i:s'), 'D, F j, Y H:i');

					$backup_disabled = ($updraftplus->really_is_writable($updraft_dir)) ? '' : 'disabled="disabled"';

					$last_backup_html = $this->last_backup_html();

					?>

				<tr>
					<th><span title="<?php _e('All the times shown in this section are using WordPress\'s configured time zone, which you can set in Settings -> General', 'updraftplus'); ?>"><?php _e('Next scheduled backups','updraftplus');?>:</span></th>
					<td>
						<div style="width: 76px; float:left;"><?php _e('Files','updraftplus'); ?>:</div><div style="color:blue; float:left;"><?php echo $next_scheduled_backup?></div>
						<div style="width: 76px; clear: left; float:left;"><?php _e('Database','updraftplus');?>: </div><div style="color:blue; float:left;"><?php echo $next_scheduled_backup_database?></div>
						<div style="width: 76px; clear: left; float:left;"><?php _e('Time now','updraftplus');?>: </div><div style="color:blue; float:left;"><?php echo $current_time?></div>
					</td>
				</tr>
				<tr>
					<th><?php _e('Last backup job run:','updraftplus');?></th>
					<td id="updraft_last_backup"><?php echo $last_backup_html ?></td>
				</tr>
			</table>
			<div style="float:left; width:200px; margin-top: <?php echo (class_exists('UpdraftPlus_Addons_Migrator')) ? "20" : "0" ?>px;">
				<div style="margin-bottom: 10px;">
					<button type="button" <?php echo $backup_disabled ?> class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:22px !important; min-height: 32px; min-width: 170px;" onclick="jQuery('#updraft-backupnow-modal').dialog('open');"><?php _e('Backup Now','updraftplus');?></button>
				</div>
				<div style="margin-bottom: 10px;">
					<?php
						$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
						$backup_history = (is_array($backup_history))?$backup_history:array();
					?>
					<input type="button" class="button-primary" value="<?php _e('Restore','updraftplus');?>" style="padding-top:2px;padding-bottom:2px;font-size:22px !important; min-height: 32px;  min-width: 170px;" onclick="jQuery('.download-backups').slideDown(); updraft_historytimertoggle(1); jQuery('html,body').animate({scrollTop: jQuery('#updraft_lastlogcontainer').offset().top},'slow');">
				</div>
				<div>
					<button type="button" class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:22px !important; min-height: 32px;  min-width: 170px;" onclick="jQuery('#updraft-migrate-modal').dialog('open');"><?php _e('Clone/Migrate','updraftplus');?></button>
				</div>
			</div>
			<br style="clear:both" />
			<table class="form-table">
				<tr id="updraft_lastlogmessagerow">
					<th><?php _e('Last log message','updraftplus');?>:</th>
					<td>
						<span id="updraft_lastlogcontainer"><?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', __('(Nothing yet logged)','updraftplus'))); ?></span><br>
						<a href="?page=updraftplus&action=downloadlatestmodlog&wpnonce=<?php echo wp_create_nonce('updraftplus_download') ?>"><?php _e('Download most recently modified log file','updraftplus');?></a>
					</td>
				</tr>
				<tr>
					<th><?php echo htmlspecialchars(__('Backups, logs & restoring','updraftplus')); ?>:</th>
					<td><a id="updraft_showbackups" href="#" title="<?php _e('Press to see available backups','updraftplus');?>" onclick="jQuery('.download-backups').fadeToggle(); updraft_historytimertoggle(0);"><?php echo sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history)); ?></a></td>
				</tr>
				<?php
					if (defined('UPDRAFTPLUS_EXPERIMENTAL_MISC') && UPDRAFTPLUS_EXPERIMENTAL_MISC == true) {
				?>
				<tr>
					<th><?php echo __('Latest UpdraftPlus.com news:', 'updraftplus'); ?></th>
					<td>Blah blah blah. Move to right-hand col?</td>
				</tr>
				<?php } ?>
			</table>
			<table class="form-table">
				<tr>
					<td style="">&nbsp;</td><td class="download-backups" style="display:none; border: 2px dashed #aaa;">
						<h2><?php echo __('Downloading and restoring', 'updraftplus'); ?></h2>
						<p style="display:none; background-color:pink; padding:8px; margin:4px;border: 1px dotted;" id="ud-whitespace-warning">
						 <?php echo '<strong>'.__('Warning','updraftplus').':</strong> '.__('Your WordPress installation has a problem with outputting extra whitespace. This can corrupt backups that you download from here.','updraftplus').' <a href="http://updraftplus.com/problems-with-extra-white-space/">'.__('Please consult this FAQ for help on what to do about it.', 'updraftplus').'</a>';?>
						</p>
						<p style="max-width: 740px;"><ul style="list-style: disc inside;">
						<li><strong><?php _e('Downloading','updraftplus');?>:</strong> <?php _e("Pressing a button for Database/Plugins/Themes/Uploads/Others will make UpdraftPlus try to bring the backup file back from the remote storage (if any - e.g. Amazon S3, Dropbox, Google Drive, FTP) to your webserver. Then you will be allowed to download it to your computer. If the fetch from the remote storage stops progressing (wait 30 seconds to make sure), then press again to resume. Remember that you can also visit the cloud storage vendor's website directly.",'updraftplus');?></li>
						<li><strong><?php _e('Restoring','updraftplus');?>:</strong> <?php _e("Press the button for the backup you wish to restore. If your site is large and you are using remote storage, then you should first click on each entity in order to retrieve it back to the webserver. This will prevent time-outs from occuring during the restore process itself.",'updraftplus');?> <?php _e('More tasks:','updraftplus');?> <a href="#" onclick="jQuery('#updraft-plupload-modal').slideToggle(); return false;"><?php _e('upload backup files','updraftplus');?></a> | <a href="#" onclick="updraft_updatehistory(1); return false;" title="<?php _e('Press here to look inside your UpdraftPlus directory (in your web hosting space) for any new backup sets that you have uploaded. The location of this directory is set in the expert settings, below.','updraftplus'); ?>"><?php _e('rescan folder for new backup sets','updraftplus');?></a></li>
						<li><strong><?php _e('Opera web browser','updraftplus');?>:</strong> <?php _e('If you are using this, then turn Turbo/Road mode off.','updraftplus');?></li>
						<?php if (UpdraftPlus_Options::get_updraft_option('updraft_service') == 'googledrive') {
							?><li><strong><?php _e('Google Drive','updraftplus');?>:</strong> <?php _e('Google changed their permissions setup recently (April 2013). To download or restore from Google Drive, you <strong>must</strong> first re-authenticate (using the link in the Google Drive configuration section).','updraftplus');?></li>
						<?php } ?>
						<li title="<?php _e('This is a count of the contents of your Updraft directory','updraftplus');?>"><strong><?php _e('Web-server disk space in use by UpdraftPlus','updraftplus');?>:</strong> <span id="updraft_diskspaceused"><em>(calculating...)</em></span> <a href="#" onclick="updraftplus_diskspace(); return false;"><?php _e('refresh','updraftplus');?></a></li></ul>

						<div id="updraft-plupload-modal" title="<?php _e('UpdraftPlus - Upload backup files','updraftplus'); ?>" style="width: 75%; margin: 16px; display:none; margin-left: 100px;">
						<p style="max-width: 600px;"><em><?php _e("Upload files into UpdraftPlus. Use this to import backups made on a different WordPress installation." ,'updraftplus');?> <?php echo htmlspecialchars(__('Or, you can place them manually into your UpdraftPlus directory (usually wp-content/updraft), e.g. via FTP, and then use the "rescan" link above.', 'updraftplus'));?></em></p>
							<div id="plupload-upload-ui" style="width: 70%;">
								<div id="drag-drop-area">
									<div class="drag-drop-inside">
									<p class="drag-drop-info"><?php _e('Drop backup zips here', 'updraftplus'); ?></p>
									<p><?php _ex('or', 'Uploader: Drop zip files here - or - Select Files'); ?></p>
									<p class="drag-drop-buttons"><input id="plupload-browse-button" type="button" value="<?php esc_attr_e('Select Files'); ?>" class="button" /></p>
									</div>
								</div>
								<div id="filelist">
								</div>
							</div>

						</div>

						<div id="ud_downloadstatus"></div>
						<script>
							function updraftplus_diskspace() {
								jQuery('#updraft_diskspaceused').html('<em><?php _e('calculating...','updraftplus');?></em>');
								jQuery.get(ajaxurl, { action: 'updraft_ajax', entity: 'updraft', subaction: 'diskspaceused', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
									jQuery('#updraft_diskspaceused').html(response);
								});
							}
							var lastlog_lastmessage = "";
							function updraftplus_deletefromserver(timestamp, type) {
								var pdata = {
									action: 'updraft_download_backup',
									stage: 'delete',
									timestamp: timestamp,
									type: type,
									_wpnonce: '<?php echo wp_create_nonce("updraftplus_download"); ?>'
								};
								jQuery.post(ajaxurl, pdata, function(response) {
									if (response == 'deleted') {
										
									} else {
										alert('We requested to delete the file, but could not understand the server\'s response '+response);
									}
								});
							}
							function updraftplus_downloadstage2(timestamp, type) {
								location.href=ajaxurl+'?_wpnonce=<?php echo wp_create_nonce("updraftplus_download"); ?>&timestamp='+timestamp+'&type='+type+'&stage=2&action=updraft_download_backup';
							}
							function updraft_downloader(base, nonce, what, whicharea) {
								// Create somewhere for the status to be found
								var stid = base+nonce+'_'+what;
								if (!jQuery('#'+stid).length) {
									jQuery(whicharea).append('<div style="clear:left; border: 1px solid; padding: 8px; margin-top: 4px; max-width:840px;" id="'+stid+'"><button onclick="jQuery(\'#'+stid+'\').fadeOut().remove();" type="button" style="float:right; margin-bottom: 8px;">X</button><strong>Download '+what+' ('+nonce+')</strong>:<div class="raw"><?php _e('Begun looking for this entity','updraftplus');?></div><div class="file" id="'+stid+'_st"><div class="dlfileprogress" style="width: 0;"></div></div>');
									// <b><span class="dlname">??</span></b> (<span class="dlsofar">?? KB</span>/<span class="dlsize">??</span> KB)
									setTimeout(function(){updraft_downloader_status(base, nonce, what);}, 300);
								}
								// Now send the actual request to kick it all off
								jQuery.post(ajaxurl, jQuery('#uddownloadform_'+what+'_'+nonce).serialize());
								// We don't want the form to submit as that replaces the document
								return false;
							}
							function updraft_restorer_checkstage2(doalert) {
								// How many left?
								var stilldownloading = jQuery('#ud_downloadstatus2 .file').length;
								if (stilldownloading > 0) {
									if (doalert) { alert('<?php _e('Some files are still downloading or being processed - please wait.', 'updraftplus'); ?>'); }
									return;
								}
								// Allow pressing 'Restore' to proceed
								jQuery('#updraft-restore-modal-stage2a').html('<?php _e('Processing files - please wait...', 'updraftplus'); ?>');
								jQuery.get(ajaxurl, {
									action: 'updraft_ajax',
									subaction: 'restore_alldownloaded', 
									nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>',
									timestamp: jQuery('#updraft_restore_timestamp').val(),
									restoreopts: jQuery('#updraft_restore_form').serialize()
								}, function(data) {
									updraft_restore_stage = 3;
									jQuery('#updraft-restore-modal-stage2a').html(data);
								});
							}
							var dlstatus_sdata = {
								action: 'updraft_ajax',
								subaction: 'downloadstatus',
								nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>'
							};
							dlstatus_lastlog = '';
							function updraft_downloader_status(base, nonce, what) {
								// Get the DOM id of the status div (add _st for the id of the file itself)
								var stid = base+nonce+'_'+what;
								if (jQuery('#'+stid).length) {
// 									console.log(stid+": "+jQuery('#'+stid).length);
									dlstatus_sdata.timestamp = nonce;
									dlstatus_sdata.type = what;
									jQuery.get(ajaxurl, dlstatus_sdata, function(response) {
										nexttimer = 1250;
										if (dlstatus_lastlog == response) { nexttimer = 3000; }
										try {
											var resp = jQuery.parseJSON(response);
											var cancel_repeat = 0;
											if (resp.e != null) {
												jQuery('#'+stid+' .raw').html('<strong><?php _e('Error:','updraftplus'); ?></strong> '+resp.e);
												console.log(resp);
											} else if (resp.p != null) {
												jQuery('#'+stid+'_st .dlfileprogress').width(resp.p+'%');
												//jQuery('#'+stid+'_st .dlsofar').html(Math.round(resp.s/1024));
												//jQuery('#'+stid+'_st .dlsize').html(Math.round(resp.t/1024));
												if (resp.m != null) {
													if (resp.p >=100 && base == 'udrestoredlstatus_') {
														jQuery('#'+stid+' .raw').html(resp.m);
														jQuery('#'+stid).fadeOut('slow', function() { jQuery(this).remove(); updraft_restorer_checkstage2(0);});
													} else if (resp.p < 100 || base != 'uddlstatus_') {
														jQuery('#'+stid+' .raw').html(resp.m);
													} else {
														jQuery('#'+stid+' .raw').html('<?php _e('File ready.','updraftplus'); ?> <?php _e('You should:','updraftplus'); ?> <button type="button" onclick="updraftplus_downloadstage2(\''+nonce+'\', \''+what+'\')\">Download to your computer</button> and then, if you wish, <button id="uddownloaddelete_'+nonce+'_'+what+'" type="button" onclick="updraftplus_deletefromserver(\''+nonce+'\', \''+what+'\')\">Delete from your web server</button>');
													}
												}
												dlstatus_lastlog = response;
											} else if (resp.m != null) {
													jQuery('#'+stid+' .raw').html(resp.m);
											} else {
												alert('<?php _e('Download error: the server sent us a response (JSON) which we did not understand', 'updraftplus'); ?> ('+response+')');
												cancel_repeat = 1;
											}
											if (cancel_repeat == 0) { setTimeout(function(){updraft_downloader_status(base, nonce, what)}, nexttimer); }
										} catch(err) {
											alert('<?php _e('Download error: the server sent us a response which we did not understand.', 'updraftplus'); ?> <?php _e("Error:",'updraftplus');?> '+err);
										}
									});
								}
							}
						</script>
						<div id="updraft_existing_backups" style="margin-bottom:12px;">
							<?php
								print $this->existing_backup_table($backup_history);
							?>
						</div>
					</td>
				</tr>
			</table>

<div id="updraft-delete-modal" title="<?php _e('Delete backup set', 'updraftplus');?>">
<form id="updraft_delete_form" method="post">
	<p style="margin-top:3px; padding-top:0">
		<?php _e('Are you sure that you wish to delete this backup set?', 'updraftplus'); ?>
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

<div id="updraft-hidethis">
<p><strong><?php _e('Warning:', 'updraftplus'); ?> <?php _e("If you can still read these words after the page finishes loading, then there is a JavaScript or jQuery problem in your web browser.", 'updraftplus'); ?> <a href="http://updraftplus.com/do-you-have-a-javascript-or-jquery-error/"><?php _e('Go here for more information.', 'updraftplus'); ?></a></strong></p>
</p>
</div>
<script>
jQuery(document).ready(function() {
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'ping', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(data, response) {
		if ('success' == response && data != 'pong' && data.indexOf('pong')>=0) {
			jQuery('#ud-whitespace-warning').show();
		}
	});
	jQuery('#updraft-hidethis').remove();
});
</script>

<div id="updraft-restore-modal" title="UpdraftPlus - <?php _e('Restore backup','updraftplus');?>">
<p><strong><?php _e('Restore backup from','updraftplus');?>:</strong> <span class="updraft_restore_date"></span></p>

<div id="updraft-restore-modal-stage2">

	<p><strong><?php _e('Downloading / preparing backup files...', 'updraftplus');?></strong></p>
	<div id="ud_downloadstatus2"></div>

	<div id="updraft-restore-modal-stage2a"></div>

</div>

<div id="updraft-restore-modal-stage1">
<p><?php _e("Restoring will replace this site's themes, plugins, uploads, database and/or other content directories (according to what is contained in the backup set, and your selection).",'updraftplus');?> <?php _e('Choose the components to restore','updraftplus');?>:</p>
<form id="updraft_restore_form" method="post">
	<fieldset>
		<input type="hidden" name="action" value="updraft_restore">
		<input type="hidden" name="backup_timestamp" value="0" id="updraft_restore_timestamp">
		<?php

		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if($updraftplus->detect_safe_mode()) {
			echo "<p><em>".__('Your web server has PHP\'s so-called safe_mode active.','updraftplus').' '.__('This makes time-outs much more likely. You are recommended to turn safe_mode off, or to restore only one entity at a time, <a href="http://updraftplus.com/faqs/i-want-to-restore-but-have-either-cannot-or-have-failed-to-do-so-from-the-wp-admin-console/">or to restore manually</a>.', 'updraftplus')."</em></p><br/>";
		}

			$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);
			foreach ($backupable_entities as $type => $info) {
				if (!isset($info['restorable']) || $info['restorable'] == true) {
					echo '<div><input id="updraft_restore_'.$type.'" type="checkbox" name="updraft_restore[]" value="'.$type.'"> <label for="updraft_restore_'.$type.'">'.$info['description'].'</label><br>';

					do_action("updraftplus_restore_form_$type");

					echo '</div>';
				} else {
					$sdescrip = isset($info['shortdescription']) ? $info['shortdescription'] : $info['description'];
					echo "<div style=\"margin: 8px 0;\"><em>".htmlspecialchars(sprintf(__('The following entity cannot be restored automatically: "%s".', 'updraftplus'), $sdescrip))." ".__('You will need to restore it manually.', 'updraftplus')."</em><br>".'<input id="updraft_restore_'.$type.'" type="hidden" name="updraft_restore[]" value="'.$type.'"></div>';
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

			<script>
				jQuery('#updraft_restore_db').change(function(){
					if (jQuery('#updraft_restore_db').is(':checked')) {
						jQuery('#updraft_restorer_dboptions').slideDown();
					} else {
						jQuery('#updraft_restorer_dboptions').slideUp();
					}
				});
			</script>

			</div>

		</div>
	</fieldset>
</form>
<p><em><a href="http://updraftplus.com/faqs/what-should-i-understand-before-undertaking-a-restoration/" target="_new"><?php _e('Do read this helpful article of useful things to know before restoring.','updraftplus');?></a></em></p>
</div>

</div>

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
	<p><?php _e("To proceed, press 'Backup Now'. Then, watch the 'Last Log Message' field for activity after about 10 seconds. WordPress should start the backup running in the background.",'updraftplus');?></p>

	<p><?php _e('Does nothing happen when you schedule backups?','updraftplus');?> <a href="http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/"><?php _e('Go here for help.','updraft');?></a></p>
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
			<h2 style="margin-top: 6px;"><?php _e('Configure Backup Contents And Schedule','updraftplus');?></h2>
			<?php UpdraftPlus_Options::options_form_begin(); ?>
				<?php $this->settings_formcontents($last_backup_html); ?>
			</form>
			<div style="padding-top: 40px; display:none;" class="expertmode">
				<hr>
				<h2><?php _e('Debug Information And Expert Options','updraftplus');?></h2>
				<p>
				<?php
				echo sprintf(__('Web server:','updraftplus'), 'PHP').' '.htmlspecialchars($_SERVER["SERVER_SOFTWARE"]).' ('.htmlspecialchars(php_uname()).')<br />';
				$peak_memory_usage = memory_get_peak_usage(true)/1024/1024;
				$memory_usage = memory_get_usage(true)/1024/1024;
				echo __('Peak memory usage','updraftplus').': '.$peak_memory_usage.' MB<br/>';
				echo __('Current memory usage','updraftplus').': '.$memory_usage.' MB<br/>';
				echo __('PHP memory limit','updraftplus').': '.ini_get('memory_limit').' <br/>';
				echo sprintf(__('%s version:','updraftplus'), 'PHP').' '.phpversion().' - ';
				echo '<a href="admin-ajax.php?page=updraftplus&action=updraft_ajax&subaction=phpinfo&nonce='.wp_create_nonce('updraftplus-credentialtest-nonce').'" id="updraftplus-phpinfo">'.__('show PHP information (phpinfo)', 'updraftplus').'</a><br/>';
				echo sprintf(__('%s version:','updraftplus'), 'MySQL').' '.((function_exists('mysql_get_server_info')) ? mysql_get_server_info() : '?').'<br>';

				if (version_compare(phpversion(), '5.2.0', '>=') && extension_loaded('zip')) {
					$ziparchive_exists .= __('Yes', 'updraftplus');
				} else {
					$ziparchive_exists .= (method_exists('ZipArchive', 'addFile')) ? __('Yes', 'updraftplus') : __('No', 'updraftplus');
				}

				echo __('PHP has support for ZipArchive::addFile:', 'updraftplus').' '.$ziparchive_exists.'<br>';

				$binzip = $updraftplus->find_working_bin_zip(false);

				echo __('zip executable found:', 'updraftplus').' '.((is_string($binzip)) ? __('Yes').': '.$binzip : __('No')).'<br>';

				echo '<a href="admin-ajax.php?page=updraftplus&action=updraft_ajax&subaction=backuphistoryraw&nonce='.wp_create_nonce('updraftplus-credentialtest-nonce').'" id="updraftplus-rawbackuphistory">'.__('Show raw backup and file list', 'updraftplus').'</a><br/>';


				echo '<h3>'.__('Total (uncompressed) on-disk data:','updraftplus').'</h3>';
				echo '<p style="clear: left; max-width: 600px;"><em>'.__('N.B. This count is based upon what was, or was not, excluded the last time you saved the options.', 'updraftplus').'</em></p>';

				foreach ($backupable_entities as $key => $info) {

					$sdescrip = preg_replace('/ \(.*\)$/', '', $info['description']);
					if (strlen($sdescrip) > 20 && isset($info['shortdescription'])) $sdescrip = $info['shortdescription'];

					echo '<div style="clear: left;float:left; width:150px;">'.ucfirst($sdescrip).':</strong></div><div style="float:left;"><span id="updraft_diskspaceused_'.$key.'"><em></em></span> <a href="#" onclick="updraftplus_diskspace_entity(\''.$key.'\'); return false;">'.__('count','updraftplus').'</a></div>';
				}

				?>

				</p>
				<p style="clear: left; padding-top: 20px; max-width: 600px; margin:0;"><?php _e('The buttons below will immediately execute a backup run, independently of WordPress\'s scheduler. If these work whilst your scheduled backups and the "Backup Now" button do absolutely nothing (i.e. not even produce a log file), then it means that your scheduler is broken. You should then disable all your other plugins, and try the "Backup Now" button. If that fails, then contact your web hosting company and ask them if they have disabled wp-cron. If it succeeds, then re-activate your other plugins one-by-one, and find the one that is the problem and report a bug to them.','updraftplus');?></p>

				<table border="0" style="border: none;">
				<tbody>
				<tr>
				<td>
				<form method="post">
					<input type="hidden" name="action" value="updraft_backup_debug_all" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="<?php _e('Debug Full Backup','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will cause an immediate backup. The page will stall loading until it finishes (ie, unscheduled).','updraftplus'));?>'))" /></p>
				</form>
				</td><td>
				<form method="post">
					<input type="hidden" name="action" value="updraft_backup_debug_db" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="<?php _e('Debug Database Backup','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will cause an immediate DB backup. The page will stall loading until it finishes (ie, unscheduled). The backup may well run out of time; really this button is only helpful for checking that the backup is able to get through the initial stages, or for small WordPress sites..','updraftplus'));?>'))" /></p>
				</form>
				</td>
				</tr>
				</tbody>
				</table>
				<h3><?php _e('Wipe Settings','updraftplus');?></h3>
				<p style="max-width: 600px;"><?php _e('This button will delete all UpdraftPlus settings (but not any of your existing backups from your cloud storage). You will then need to enter all your settings again. You can also do this before deactivating/deinstalling UpdraftPlus if you wish.','updraftplus');?></p>
				<form method="post">
					<input type="hidden" name="action" value="updraft_wipesettings" />
					<p><input type="submit" class="button-primary" value="<?php _e('Wipe All Settings','updraftplus'); ?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will delete all your UpdraftPlus settings - are you sure you want to do this?'));?>'))" /></p>
				</form>
				<h3><?php _e('Active jobs', 'updraftplus');?></h3>
				<div id="updraft_activejobs">
				<?php $this->print_active_jobs(); ?>
				</div>
			</div>

			<script type="text/javascript">
			/* <![CDATA[ */
				
				function updraft_activejobs_delete(jobid) {
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'activejobs_delete', jobid: jobid, nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
						if (response.substr(0,2) == 'Y:') {
							jQuery('#updraft-jobid-'+jobid).html(response.substr(2)).fadeOut('slow').remove();
						} else if (response.substr(0,2) == 'X:') {
							alert(response.substr(2));
						} else {
							alert('<?php _e('Unknown response:', 'updraftplus'); ?> '+response);
						}
					});
				}

				function updraft_activejobs_update() {
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'activejobs_list', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
						jQuery('#updraft_activejobs').html(response);
					});
				}

				function updraftplus_diskspace_entity(key) {
					jQuery('#updraft_diskspaceused_'+key).html('<em><?php _e('calculating...','updraftplus');?></em>');
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'diskspaceused', entity: key, nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
						jQuery('#updraft_diskspaceused_'+key).html(response);
					});
				}

				function updraft_iframe_modal(getwhat, title) {
					jQuery('#updraft-iframe-modal-innards').html('<iframe width="100%" height="440px" src="'+ajaxurl+'?action=updraft_ajax&subaction='+getwhat+'&nonce=<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>"></iframe>');
					jQuery('#updraft-iframe-modal').dialog('option', 'title', title).dialog('open');
				}

				jQuery(document).ready(function() {

					jQuery('#updraft-service').change(function() {
						jQuery('.updraftplusmethod').hide();
						var active_class = jQuery(this).val();
						jQuery('.'+active_class).show();
					});

				jQuery('#updraftplus-phpinfo').click(function(e) {
					e.preventDefault();
					updraft_iframe_modal('phpinfo', '<?php _e('PHP information', 'updraftplus'); ?>');
				});

				jQuery('#updraftplus-rawbackuphistory').click(function(e) {
					e.preventDefault();
					updraft_iframe_modal('rawbackuphistory', '<?php _e('Raw backup history', 'updraftplus'); ?>');
				});

				})
				jQuery(window).load(function() {
					//this is for hiding the restore progress at the top after it is done
					setTimeout('jQuery("#updraft-restore-progress").toggle(1000);',3000)
					jQuery('#updraft-restore-progress-toggle').click(function() {
						jQuery('#updraft-restore-progress').toggle(500)
					})
				})
			/* ]]> */
			</script>
			<?php
	}

	function print_active_jobs() {
		$cron = get_option('cron');
		$found_jobs = 0;
		foreach ($cron as $time => $job) {
			if (isset($job['updraft_backup_resume'])) {
				foreach ($job['updraft_backup_resume'] as $hook => $info) {
					if (isset($info['args'][1])) {
						$found_jobs++;
						$job_id = $info['args'][1];
						$jobdata = get_transient("updraft_jobdata_".$job_id);
						if (!is_array($jobdata)) $jobdata = array();
						$began_at = (isset($jobdata['backup_time'])) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $jobdata['backup_time']), 'D, F j, Y H:i') : '?';
						echo '<div style="clear:left; float:left;" id="updraft-jobid-'.$job_id.'">'.sprintf(__("%s: began at: %s; next resumption: %d (after %ss)", 'updraftplus'), $job_id, $began_at, $info['args'][0], $time-time()).' - <a href="?page=updraftplus&action=downloadlog&updraftplus_backup_nonce='.$job_id.'">'.__('show log', 'updraftplus').'</a> - <a href="javascript:updraft_activejobs_delete(\''.$job_id.'\')">'.__('delete schedule', 'updraftplus').'</a></div>';;
// 									echo str_replace("\n", "<br>", print_r($job, true));
					}
				}
			}
		}
		if (0 == $found_jobs) {
			echo '<p><em>'.__('(None)', 'updraftplus').'</em></p>';
		}
	}

	//deletes the -old directories that are created when a backup is restored.
	function delete_old_dirs() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials(wp_nonce_url("options-general.php?page=updraftplus&action=updraft_delete_old_dirs", 'updraft_delete_old_dirs')); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}
		// From WP_CONTENT_DIR - which contains 'themes'
		$ret = $this->delete_old_dirs_dir($wp_filesystem->wp_content_dir());
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

		return ($ret && $ret3) ? true : false;
	}

	function delete_old_dirs_dir($dir) {

		global $wp_filesystem;
		$list = $wp_filesystem->dirlist($dir);
		if (!is_array($list)) return false;

		$ret = true;
		foreach ($list as $item) {
			if (substr($item['name'], -4, 4) == "-old") {
				//recursively delete
				print "<strong>".__('Delete','updraftplus').": </strong>".htmlspecialchars($item['name']).": ";
				if(!$wp_filesystem->delete($dir.$item['name'], true)) {
					$ret = false;
					print "<strong>".__('Failed', 'updraftplus')."</strong><br>";
				} else {
					print "<strong>".__('OK', 'updraftplus')."</strong><br>";
				}
			}
		}
		return $ret;
	}

	// The aim is to get a directory that is writable by the webserver, because that's the only way we can create zip files
	function create_backup_dir() {

		global $wp_filesystem, $updraftplus;

		if (false === ($credentials = request_filesystem_credentials('options-general.php?page=updraftplus&action=updraft_create_backup_dir&nonce='.wp_create_nonce('create_backup_dir')))) {
			return false;
		}

		if ( ! WP_Filesystem($credentials) ) {
			// our credentials were no good, ask the user for them again
			request_filesystem_credentials('options-general.php?page=updraftplus&action=updraft_create_backup_dir&nonce='.wp_create_nonce('create_backup_dir'), '', true);
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
				return new WP_Error('writable_error', __('The folder exists, but your webserver does not have permission to write to it.', 'updraftplus').' '.__('You will need to consult with your web hosting provider to find out to set permissions for a WordPress plugin to write to the directory.', 'updraftplus'));
			}
		}

		return true;
	}

	function execution_time_check($time) {
		$setting = ini_get('max_execution_time');
		return ( $setting==0 || $setting >= $time) ? true : false;
	}

	//scans the content dir to see if any -old dirs are present
	function scan_old_dirs() {
		$dirArr = scandir(untrailingslashit(WP_CONTENT_DIR));
		foreach($dirArr as $dir) {
			if (preg_match('/-old$/', $dir)) return true;
		}
		# No need to scan ABSPATH - we don't backup there
		$plugdir = untrailingslashit(WP_PLUGIN_DIR);
		if (is_dir($plugdir.'-old')) return true;
		return false;
	}

	function last_backup_html() {

		global $updraftplus;

		$updraft_last_backup = UpdraftPlus_Options::get_updraft_option('updraft_last_backup');

		if($updraft_last_backup) {

			// Convert to GMT, then to blog time
			$last_backup_text = "<span style=\"color:".(($updraft_last_backup['success']) ? 'green' : 'black').";\">".get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraft_last_backup['backup_time']), 'D, F j, Y H:i').'</span><br>';

			if (is_array($updraft_last_backup['errors'])) {
				foreach ($updraft_last_backup['errors'] as $err) {
					$level = (is_array($err)) ? $err['level'] : 'error';
					$message = (is_array($err)) ? $err['message'] : $err;
			
					$last_backup_text .= ('warning' == $level) ? "<span style=\"color:orange;\">" : "<span style=\"color:red;\">";

					if ('warning' == $level) $message = sprintf(__("Warning: %s", 'updraftplus'), $message);

					$last_backup_text .= htmlspecialchars($message);
					
					$last_backup_text .= '</span><br>';
				}
			}

			if (!empty($updraft_last_backup['backup_nonce'])) {
				$updraft_dir = $updraftplus->backups_dir_location();

				$potential_log_file = $updraft_dir."/log.".$updraft_last_backup['backup_nonce'].".txt";

				if (is_readable($potential_log_file)) $last_backup_text .= "<a href=\"?page=updraftplus&action=downloadlog&updraftplus_backup_nonce=".$updraft_last_backup['backup_nonce']."\">".__('Download log file','updraftplus')."</a>";
			}

		} else {
			$last_backup_text =  "<span style=\"color:blue;\">".__('No backup has been completed.','updraftplus')."</span>";
		}

		return $last_backup_text;

	}

	function settings_formcontents($last_backup_html) {

		global $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location();

		?>
			<table class="form-table" style="width:900px;">
			<tr>
				<th><?php _e('File backup intervals','updraftplus'); ?>:</th>
				<td><select id="updraft_interval" name="updraft_interval" onchange="updraft_check_same_times();">
					<?php
					$intervals = array ("manual" => _x("Manual",'i.e. Non-automatic','updraftplus'), 'every4hours' => __("Every 4 hours",'updraftplus'), 'every8hours' => __("Every 8 hours",'updraftplus'), 'twicedaily' => __("Every 12 hours",'updraftplus'), 'daily' => __("Daily",'updraftplus'), 'weekly' => __("Weekly",'updraftplus'), 'fortnightly' => __("Fortnightly",'updraftplus'), 'monthly' => __("Monthly",'updraftplus'));
					foreach ($intervals as $cronsched => $descrip) {
						echo "<option value=\"$cronsched\" ";
						if ($cronsched == UpdraftPlus_Options::get_updraft_option('updraft_interval','manual')) echo 'selected="selected"';
						echo ">$descrip</option>\n";
					}
					?>
					</select> <?php echo apply_filters('updraftplus_schedule_showfileconfig', '<input type="hidden" name="updraftplus_starttime_files" value="">'); ?>
					<?php
					echo __('and retain this many backups', 'updraftplus').': ';
					$updraft_retain = UpdraftPlus_Options::get_updraft_option('updraft_retain', 1);
					$updraft_retain = ((int)$updraft_retain > 0) ? (int)$updraft_retain : 1;
					?> <input type="text" name="updraft_retain" value="<?php echo $updraft_retain ?>" style="width:40px;" />
					</td>
			</tr>
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
					</select> <span id="updraft_db_timings"><?php echo apply_filters('updraftplus_schedule_showdbconfig', '<input type="hidden" name="updraftplus_starttime_db" value="">'); ?></span>
					<?php
					echo __('and retain this many backups', 'updraftplus').': ';
					$updraft_retain_db = UpdraftPlus_Options::get_updraft_option('updraft_retain_db', $updraft_retain);
					$updraft_retain_db = ((int)$updraft_retain_db > 0) ? (int)$updraft_retain_db : 1;
					?> <input type="text" name="updraft_retain_db" value="<?php echo $updraft_retain_db ?>" style="width:40px" />
			</td>
			</tr>
			<tr class="backup-interval-description">
				<td></td><td><p><?php echo htmlspecialchars(__('If you would like to automatically schedule backups, choose schedules from the dropdowns above. Backups will occur at the intervals specified. If the two schedules are the same, then the two backups will take place together. If you choose "manual" then you must click the "Backup Now" button whenever you wish a backup to occur.', 'updraftplus')); ?></p>
				<?php echo apply_filters('updraftplus_fixtime_advert', '<p><strong>'.__('To fix the time at which a backup should take place,','updraftplus').' </strong> ('.__('e.g. if your server is busy at day and you want to run overnight','updraftplus').'), <a href="http://updraftplus.com/shop/fix-time/">'.htmlspecialchars(__('use the "Fix Time" add-on','updraftplus')).'</a></p>'); ?>
				</td>
			</tr>
			<tr>
				<th><?php _e('Include in files backup','updraftplus');?>:</th>
				<td>

			<?php
				$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);
				$include_others_exclude = UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE);
				# The true (default value if non-existent) here has the effect of forcing a default of on.
				foreach ($backupable_entities as $key => $info) {
					$included = (UpdraftPlus_Options::get_updraft_option("updraft_include_$key", apply_filters("updraftplus_defaultoption_include_".$key, true))) ? 'checked="checked"' : "";
					if ('others' == $key) {
						?><input id="updraft_include_others" type="checkbox" name="updraft_include_others" value="1" <?php echo $included; ?> /> <label title="<?php echo sprintf(__('Your wp-content directory server path: %s', 'updraftplus'), WP_CONTENT_DIR); ?>" for="updraft_include_<?php echo $key ?>"><?php echo __('Any other directories found inside wp-content', 'updraftplus');?></label><br><?php

						$display = ($included) ? '' : 'style="display:none;"';

						echo "<div id=\"updraft_include_others_exclude\" $display>";

							echo '<label for="updraft_include_others_exclude">'.__('Exclude these:', 'updraftplus').'</label>';

							echo '<input title="'.__('If entering multiple files/directories, then separate them with commas. You can use a * at the end of any entry as a wildcard.', 'updraftplus').'" type="text" id="updraft_include_others_exclude" name="updraft_include_others_exclude" size="54" value="'.htmlspecialchars($include_others_exclude).'" />';

							echo '<br>';

						echo '</div>';

						echo <<<ENDHERE
						<script>
							jQuery(document).ready(function() {
								jQuery('#updraft_include_others').click(function() {
									if (jQuery('#updraft_include_others').is(':checked')) {
										jQuery('#updraft_include_others_exclude').slideDown();
									} else {
										jQuery('#updraft_include_others_exclude').slideUp();
									}
								});
							});
						</script>
ENDHERE;

					} else {
						echo "<input id=\"updraft_include_$key\" type=\"checkbox\" name=\"updraft_include_$key\" value=\"1\" $included /><label for=\"updraft_include_$key\"".((isset($info['htmltitle'])) ? ' title="'.htmlspecialchars($info['htmltitle']).'"' : '')."> ".$info['description']."</label><br>";
						do_action("updraftplus_config_option_include_$key");
					}
				}
			?>
				<p><?php echo apply_filters('updraftplus_admin_directories_description', __('The above directories are everything, except for WordPress core itself which you can download afresh from WordPress.org.', 'updraftplus').' <a href="http://updraftplus.com/shop/">'.htmlspecialchars(__('Or, get the "More Files" add-on from our shop.', 'updraftplus'))); ?></a> <a href="http://wordshell.net"></p><p>(<?php echo __('Use WordShell for automatic backup, version control and patching', 'updraftplus');?></a>).</p></td>
				</td>
			</tr>
			<tr>
				<th><?php _e('Email','updraftplus'); ?>:</th>
				<td><input type="text" title="<?php _e('To send to more than one address, separate each address with a comma.', 'updraftplus'); ?>" style="width:260px" name="updraft_email" value="<?php echo UpdraftPlus_Options::get_updraft_option('updraft_email'); ?>" /> <br><?php _e('Enter an address here to have a report sent (and the whole backup, if you choose) to it.','updraftplus'); ?></td>
			</tr>

			<tr>
				<th><?php _e('Database encryption phrase','updraftplus');?>:</th>
				<?php
				$updraft_encryptionphrase = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
				?>
				<td><input type="text" name="updraft_encryptionphrase" id="updraft_encryptionphrase" value="<?php echo $updraft_encryptionphrase ?>" style="width:132px" /></td>
			</tr>
			<tr class="backup-crypt-description">
				<td></td><td><p><?php _e('If you enter text here, it is used to encrypt backups (Rijndael). <strong>Do make a separate record of it and do not lose it, or all your backups <em>will</em> be useless.</strong> Presently, only the database file is encrypted. This is also the key used to decrypt backups from this admin interface (so if you change it, then automatic decryption will not work until you change it back).','updraftplus');?> <a href="#" onclick="jQuery('#updraftplus_db_decrypt').val(jQuery('#updraft_encryptionphrase').val()); jQuery('#updraft-manualdecrypt-modal').slideToggle(); return false;"><?php _e('You can also decrypt a database manually here.','updraftplus');?></a></p>

				<div id="updraft-manualdecrypt-modal" style="width: 85%; margin: 16px; display:none; margin-left: 100px;">
					<p><h3><?php _e("Manually decrypt a database backup file" ,'updraftplus');?></h3></p>
					<div id="plupload-upload-ui2" style="width: 80%;">
						<div id="drag-drop-area2">
							<div class="drag-drop-inside">
								<p class="drag-drop-info"><?php _e('Drop encrypted database files (db.gz.crypt files) here to upload them for decryption'); ?></p>
								<p><?php _ex('or', 'Uploader: Drop db.gz.crypt files here to upload them for decryption - or - Select Files'); ?></p>
								<p class="drag-drop-buttons"><input id="plupload-browse-button2" type="button" value="<?php esc_attr_e('Select Files'); ?>" class="button" /></p>
								<p style="margin-top: 18px;"><?php _e('Use decryption key','updraftplus')?>: <input id="updraftplus_db_decrypt" type="text" size="12"></input></p>
							</div>
						</div>
						<div id="filelist2">
						</div>
					</div>

				</div>


				</td>
			</tr>
			</table>

			<h2><?php _e('Copying Your Backup To Remote Storage','updraftplus');?></h2>

			<table class="form-table" style="width:900px;">
			<tr>
				<th><?php _e('Choose your remote storage','updraftplus');?>:</th>
				<td><select name="updraft_service" id="updraft-service">
					<?php
					$debug_mode = (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) ? 'checked="checked"' : "";

					$set = 'selected="selected"';

					// Should be one of s3, dropbox, ftp, googledrive, email, or whatever else is added
					$active_service = UpdraftPlus_Options::get_updraft_option('updraft_service');

					?>
					<option value="none" <?php
						if ($active_service == "none") echo $set; ?>><?php _e('None','updraftplus'); ?></option>
					<?php
					foreach ($updraftplus->backup_methods as $method => $description) {
						echo "<option value=\"$method\"";
						if ($active_service == $method) echo ' '.$set;
						echo '>'.$description;
						echo "</option>\n";
					}
					?>
					</select></td>
			</tr>
			<?php
				foreach ($updraftplus->backup_methods as $method => $description) {
					require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
					$call_method = "UpdraftPlus_BackupModule_$method";
					call_user_func(array($call_method, 'config_print'));
				}
			?>
			</table>
			<script type="text/javascript">
			/* <![CDATA[ */
				var updraft_restore_stage = 1;
				var lastlog_lastmessage = "";
				var lastlog_sdata = {
					action: 'updraft_ajax',
					subaction: 'lastlog',
					nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>'
				};
				function updraft_showlastlog(repeat){
					jQuery.get(ajaxurl, lastlog_sdata, function(response) {
						nexttimer = 1500;
						if (lastlog_lastmessage == response) { nexttimer = 4500; }
						if (repeat) { setTimeout(function(){updraft_showlastlog(true);}, nexttimer);}
						jQuery('#updraft_lastlogcontainer').html(response);
						lastlog_lastmessage = response;
					});
				}
				var lastbackup_sdata = {
					action: 'updraft_ajax',
					subaction: 'lastbackup',
					nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>'
				};
				var lastbackup_laststatus = '<?php echo esc_html($last_backup_html);?>';
				function updraft_showlastbackup(){
					jQuery.get(ajaxurl, lastbackup_sdata, function(response) {
						if (lastbackup_laststatus == response) {
							setTimeout(function(){updraft_showlastbackup();}, 7000);
						} else {
							jQuery('#updraft_last_backup').html(response);
						}
						lastbackup_laststatus = response;
					});
				}
				var updraft_historytimer = 0;
				var calculated_diskspace = 0;
				function updraft_historytimertoggle(forceon) {
					if (!updraft_historytimer || forceon == 1) {
						updraft_updatehistory(0);
						updraft_historytimer = setInterval(function(){updraft_updatehistory(0);}, 30000);
						if (!calculated_diskspace) {
							updraftplus_diskspace();
							calculated_diskspace=1;
						}
					} else {
						clearTimeout(updraft_historytimer);
						updraft_historytimer = 0;
					}
				}
				function updraft_updatehistory(rescan) {
					if (rescan == 1) {
						jQuery('#updraft_existing_backups').html('<p style="text-align:center;"><em><?php _e('Rescanning (looking for backups that you have uploaded manually into the internal backup store)...', 'updraftplus'); ?></em></p>');
					}
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'historystatus', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>', rescan: rescan }, function(response) {
						try {
							resp = jQuery.parseJSON(response);
							if (resp.n != null) { jQuery('#updraft_showbackups').html(resp.n); }
							if (resp.t != null) { jQuery('#updraft_existing_backups').html(resp.t); }
						} catch(err) {
							console.log('<?php _e("Unexpected response:",'updraftplus');?> '+response);
						}
					});
				}

				function updraft_check_same_times() {
					if (jQuery('#updraft_interval').val() == jQuery('#updraft_interval_database').val() && jQuery('#updraft_interval').val() != 'manual') {
						jQuery('#updraft_db_timings').css('opacity','0.25');
					} else {
						jQuery('#updraft_db_timings').css('opacity','1');
					}
				}

				jQuery(document).ready(function() {

					updraft_check_same_times();

					jQuery( "#updraft-delete-modal" ).dialog({
						autoOpen: false, height: 230, width: 430, modal: true,
						buttons: {
							'<?php _e('Delete','updraftplus');?>': function() {
								jQuery('#updraft-delete-waitwarning').slideDown();
								timestamp = jQuery('#updraft_delete_timestamp').val();
								jQuery.post(ajaxurl, jQuery('#updraft_delete_form').serialize(), function(response) {
									jQuery('#updraft-delete-waitwarning').slideUp();
									var resp;
									try {
										resp = jQuery.parseJSON(response);
									} catch(err) {
										alert('<?php _e("Unexpected response:",'updraftplus');?> '+response);
									}
									if (resp.result != null) {
										if (resp.result == 'error') {
											alert('<?php _e('Error:','updraftplus');?> '+resp.message);
										} else if (resp.result == 'success') {
											jQuery('#updraft_showbackups').load(ajaxurl+'?action=updraft_ajax&subaction=countbackups&nonce=<?php echo wp_create_nonce('updraftplus-credentialtest-nonce');?>');
											jQuery('#updraft_existing_backups_row_'+timestamp).slideUp().remove();
											jQuery("#updraft-delete-modal").dialog('close');
											alert(resp.message);
										}
									}
								});
							},
							'<?php _e('Cancel','updraftplus');?>': function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery( "#updraft-restore-modal" ).dialog({
						autoOpen: false, height: 505, width: 590, modal: true,
						buttons: {
							'<?php _e('Restore','updraftplus');?>': function() {
								var anyselected = 0;
								var whichselected = [];
								jQuery('input[name="updraft_restore[]"]').each(function(x,y){
									if (jQuery(y).is(':checked')) {
										anyselected = 1;
										whichselected.push(jQuery(y).val());
										//alert(jQuery(y).val());
									}
								});
								if (anyselected == 1) {
									if (updraft_restore_stage == 1) {
										jQuery('#updraft-restore-modal-stage1').slideUp('slow');
										jQuery('#updraft-restore-modal-stage2').show();
										updraft_restore_stage = 2;
										// Create the downloader active widgets
										for (var i=0; i<whichselected.length; i++) {
											updraft_downloader('udrestoredlstatus_', jQuery('#updraft_restore_timestamp').val(), whichselected[i], '#ud_downloadstatus2');
										}
										// Make sure all are downloaded
									} else if (updraft_restore_stage == 2) {
										updraft_restorer_checkstage2(1);
									} else if (updraft_restore_stage == 3) {
										jQuery('#updraft_restore_form').submit();
									}
								} else {
									alert('You did not select any components to restore. Please select at least one, and then try again.');
								}
							},
							'<?php _e('Cancel','updraftplus');?>': function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery("#updraft-iframe-modal" ).dialog({
						autoOpen: false, height: 500, width: 780, modal: true
					});

					jQuery("#updraft-backupnow-modal" ).dialog({
						autoOpen: false, height: 265, width: 390, modal: true,
						buttons: {
							'<?php _e('Backup Now','updraftplus');?>': function() {
								jQuery(this).dialog("close");
								jQuery('#updraft_backup_started').html('<em><?php _e('Requesting start of backup...', 'updraftplus');?></em>').slideDown('');
								jQuery.post(ajaxurl, { action: 'updraft_ajax', subaction: 'backupnow', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
									jQuery('#updraft_backup_started').html(response);
									setTimeout(function() {jQuery.get('<?php echo site_url(); ?>');}, 5100);
									setTimeout(function() {updraft_showlastlog();}, 6000);
									setTimeout(function() {updraft_activejobs_update();}, 6000);
									setTimeout(function() {
										jQuery('#updraft_lastlogmessagerow').fadeOut('slow', function() {
											jQuery(this).fadeIn('slow');
										});
										},
										3200
									);
									setTimeout(function() {jQuery('#updraft_backup_started').fadeOut('slow');}, 60000);
									// Should be redundant (because of the polling for the last log line), but harmless (invokes page load)
								});
							},
							'<?php _e('Cancel','updraftplus');?>': function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery( "#updraft-migrate-modal" ).dialog({
						autoOpen: false, height: 265, width: 390, modal: true,
						buttons: {
							'<?php _e('Close','updraftplus');?>': function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery('#enableexpertmode').click(function() {
						jQuery('.expertmode').fadeIn();
						updraft_activejobs_update();
						setInterval(function() {updraft_activejobs_update();}, 15000);
						jQuery('#enableexpertmode').off('click'); 
						return false;
					});
					<?php
						$really_is_writable = $updraftplus->really_is_writable($updraft_dir);
						if (!$really_is_writable) echo "jQuery('.backupdirrow').show();\n";
					?>
					setTimeout(function(){updraft_showlastlog(true);}, 1200);
					jQuery('.updraftplusmethod').hide();
					<?php
						if ($active_service) echo "jQuery('.${active_service}').show();";
						foreach ($updraftplus->backup_methods as $method => $description) {
							// already done: require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
							$call_method = "UpdraftPlus_BackupModule_$method";
							if (method_exists($call_method, 'config_print_javascript_onready')) call_user_func(array($call_method, 'config_print_javascript_onready'));
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
				<th><?php _e('Debug mode','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_debug_mode" name="updraft_debug_mode" value="1" <?php echo $debug_mode; ?> /> <br><label for="updraft_debug_mode"><?php _e('Check this to receive more information and emails on the backup process - useful if something is going wrong. You <strong>must</strong> send us this log if you are filing a bug report.','updraftplus');?></label></td>
			</tr>
			<tr>
				<th><?php _e('Expert settings','updraftplus');?>:</th>
				<td><a id="enableexpertmode" href="#enableexpertmode"><?php _e('Show expert settings','updraftplus');?></a> - <?php _e("click this to show some further options; don't bother with this unless you have a problem or are curious.",'updraftplus');?> <?php do_action('updraftplus_expertsettingsdescription'); ?></td>
			</tr>
			<?php
			$delete_local = UpdraftPlus_Options::get_updraft_option('updraft_delete_local', 1);
			?>

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
						$dir_info .= ' <span style="font-size:110%;font-weight:bold"><a href="options-general.php?page=updraftplus&action=updraft_create_backup_dir&nonce='.wp_create_nonce('create_backup_dir').'">'.__('Click here to attempt to create the directory and set the permissions','updraftplus').'</a></span>, '.__('or, to reset this option','updraftplus').' <a href="#" onclick="jQuery(\'#updraft_dir\').val(\''.WP_CONTENT_DIR.'/updraft\'); return false;">'.__('click here','updraftplus').'</a>. '.__('If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.','updraftplus').'</span>';
					}

					echo $dir_info.' '.__("This is where UpdraftPlus will write the zip files it creates initially.  This directory must be writable by your web server. Typically you'll want to have it inside your wp-content folder (this is the default).  <b>Do not</b> place it inside your uploads dir, as that will cause recursion issues (backups of backups of backups of...).",'updraftplus');?></td>
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
				<td><input type="checkbox" id="updraft_ssl_nossl" name="updraft_ssl_nossl" value="1" <?php if (UpdraftPlus_Options::get_updraft_option('updraft_ssl_nossl')) echo 'checked="checked"'; ?>> <br><label for="updraft_ssl_nossl"><?php _e('Choosing this option lowers your security by stopping UpdraftPlus from using SSL for authentication and encrypted transport at all, where possible. Note that some cloud storage providers do not allow this (e.g. Dropbox), so with those providers this setting will have no effect.','updraftplus');?> <a href="http://updraftplus.com/faqs/i-get-ssl-certificate-errors-when-backing-up-andor-restoring/">See this FAQ also.</a></label></td>
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

	function show_double_warning($text, $extraclass = '') {

		?><div class="error updraftplusmethod <?php echo $extraclass; ?>"><p><?php echo $text; ?></p></div>

		<p><?php echo $text; ?></p>

		<?php

	}

	function curl_check($service, $has_fallback = false, $extraclass = '') {
		// Check requirements
		if (!function_exists("curl_init")) {
		
			$this->show_double_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('Your web server\'s PHP installation does not included a <strong>required</strong> (for %s) module (%s). Please contact your web hosting provider\'s support and ask for them to enable it.', 'updraftplus'), $service, 'Curl').' '.sprintf(__("Your options are 1) Install/enable %s or 2) Change web hosting companies - %s is a standard PHP component, and required by all cloud backup plugins that we know of.",'updraftplus'), 'Curl', 'Curl'), $extraclass);

		} else {
			$curl_version = curl_version();
			$curl_ssl_supported= ($curl_version['features'] & CURL_VERSION_SSL);
			if (!$curl_ssl_supported) {
				if ($has_fallback) {
					?><p><strong><?php _e('Warning','updraftplus'); ?>:</strong> <?php echo sprintf(__("Your web server's PHP/Curl installation does not support https access. Communications with %s will be unencrypted. ask your web host to install Curl/SSL in order to gain the ability for encryption (via an add-on).",'updraftplus'),$service);?></p><?php
				} else {
					$this->show_double_warning('<p><strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__("Your web server's PHP/Curl installation does not support https access. We cannot access %s without this support. Please contact your web hosting provider's support. %s <strong>requires</strong> Curl+https. Please do not file any support requests; there is no alternative.",'updraftplus'),$service).'</p>', $extraclass);
				}
			} else {
				?><p><em><?php echo sprintf(__("Good news: Your site's communications with %s can be encrypted. If you see any errors to do with encryption, then look in the 'Expert Settings' for more help.", 'updraftplus'),$service);?></em></p><?php
			}
		}
	}

	function recursive_directory_size($directories) {

		if (is_string($directories)) $directories = array($directories);

		$size = 0;

		foreach ($directories as $dir) $size += $this->recursive_directory_size_raw($dir);

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

	function recursive_directory_size_raw($directory) {

		$size = 0;
		if(substr($directory,-1) == '/') $directory = substr($directory,0,-1);

		if(!file_exists($directory) || !is_dir($directory) || !is_readable($directory)) return -1;

		if($handle = opendir($directory)) {
			while(($file = readdir($handle)) !== false) {
				$path = $directory.'/'.$file;
				if($file != '.' && $file != '..') {
					if(is_file($path)) {
						$size += filesize($path);
					} elseif(is_dir($path)) {
						$handlesize = $this->recursive_directory_size_raw($path);
						if($handlesize >= 0) { $size += $handlesize; }# else { return -1; }
					}
				}
			}
			closedir($handle);
		}

		return $size;

	}

	function existing_backup_table($backup_history = false) {

		global $updraftplus;
		$ret = '';

		// Fetch it if it was not passed
		if ($backup_history === false) $backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history=array();

		$updraft_dir = $updraftplus->backups_dir_location();

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		$ret .= '<table>';

		krsort($backup_history);

		foreach($backup_history as $key=>$value) {
			$pretty_date = date('Y-m-d G:i',$key);
			$entities = '';
			$sval = ((isset($value['service']) && $value['service'] != 'email' && $value['service'] != 'none')) ? '1' : '0';
			$title = __('Delete this backup set', 'updraftplus');
			$non=$value['nonce'];
			$ret .= <<<ENDHERE
		<tr id="updraft_existing_backups_row_$key">
			<td><div class="updraftplus-remove" style="width: 19px; height: 19px; padding-top:0px; font-size: 18px; text-align:center;font-weight:bold; border-radius: 7px;"><a style="text-decoration:none;" href="javascript:updraft_delete('$key', '$non', $sval);" title="$title">×</a></div></td><td><b>$pretty_date</b></td>
			<td>
ENDHERE;
		if (isset($value['db'])) {
			$entities .= '/db/';
			$sdescrip = preg_replace('/ \(.*\)$/', '', __('Database','updraftplus'));
			$nf = wp_nonce_field('updraftplus_download', '_wpnonce', true, false);
			$dbt = __('Database','updraftplus');
			$ret .= <<<ENDHERE
				<form id="uddownloadform_db_$key" action="admin-ajax.php" onsubmit="return updraft_downloader('uddlstatus_', $key, 'db', '#ud_downloadstatus')" method="post">
					$nf
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="db" />
					<input type="hidden" name="timestamp" value="$key" />
					<input type="submit" value="$dbt" />
				</form>
ENDHERE;
		} else {
			$ret .= sprintf(_x('(No %s)','Message shown when no such object is available','updraftplus'), __('database', 'updraftplus'));
		}
		$ret .="</td>";

		foreach ($backupable_entities as $type => $info) {
			$ret .= '<td>';
			$sdescrip = preg_replace('/ \(.*\)$/', '', $info['description']);
			if (strlen($sdescrip) > 20 && isset($info['shortdescription'])) $sdescrip = $info['shortdescription'];
			if (isset($value[$type])) {
				$entities .= '/'.$type.'/';
				$ide = __('Press here to download','updraftplus').' '.strtolower($info['description']);
				$nf = wp_nonce_field('updraftplus_download',  '_wpnonce', true, false);
				$ret .= <<<ENDHERE
				<form id="uddownloadform_${type}_${key}" action="admin-ajax.php" onsubmit="return updraft_downloader('uddlstatus_', '$key', '$type', '#ud_downloadstatus')" method="post">
					$nf
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="$type" />
					<input type="hidden" name="timestamp" value="$key" />
					<input type="submit" title="$ide" value="$sdescrip" />
				</form>
ENDHERE;
		} else {
			$ret .= sprintf(_x('(No %s)','Message shown when no such object is available','updraftplus'), preg_replace('/\s\(.{12,}\)/', '', strtolower($sdescrip))); }
			$ret .= '</td>';
		};

		$ret .= '<td>';
		if (isset($value['nonce']) && preg_match("/^[0-9a-f]{12}$/",$value['nonce']) && is_readable($updraft_dir.'/log.'.$value['nonce'].'.txt')) {
			$nval = $value['nonce'];
			$lt = __('Backup Log','updraftplus');
			$ret .= <<<ENDHERE
				<form action="options-general.php" method="get">
					<input type="hidden" name="action" value="downloadlog" />
					<input type="hidden" name="page" value="updraftplus" />
					<input type="hidden" name="updraftplus_backup_nonce" value="$nval" />
					<input type="submit" value="$lt" />
				</form>
ENDHERE;
			} else {
				$ret .= "(No&nbsp;backup&nbsp;log)";
			}
			$ret .= <<<ENDHERE
			</td>
			<td>
				<form method="post" action="">
					<input type="hidden" name="backup_timestamp" value="$key">
					<input type="hidden" name="action" value="updraft_restore" />
ENDHERE;
			if ($entities) {
				$ret .= '<button title="'.__('After pressing this button, you will be given the option to choose which components you wish to restore','updraftplus').'" type="button" class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:16px !important; min-height:26px;" onclick="'."updraft_restore_setoptions('$entities'); jQuery('#updraft_restore_timestamp').val('$key'); jQuery('.updraft_restore_date').html('$pretty_date'); updraft_restore_stage = 1; jQuery('#updraft-restore-modal').dialog('open'); jQuery('#updraft-restore-modal-stage1').show();jQuery('#updraft-restore-modal-stage2').hide(); jQuery('#updraft-restore-modal-stage2a').html('');\">".__('Restore','updraftplus').'</button>';
			}
			$ret .= <<<ENDHERE
				</form>
			</td>
		</tr>
		<script>
		function updraft_delete(key, nonce, showremote) {
			jQuery('#updraft_delete_timestamp').val(key);
			jQuery('#updraft_delete_nonce').val(nonce);
			if (showremote) {
				jQuery('#updraft-delete-remote-section, #updraft_delete_remote').removeAttr('disabled').show();
			} else {
				jQuery('#updraft-delete-remote-section, #updraft_delete_remote').hide().attr('disabled','disabled');
			}
			jQuery('#updraft-delete-modal').dialog('open');
		}
		function updraft_restore_setoptions(entities) {
			var howmany = 0;
			jQuery('input[name="updraft_restore[]"]').each(function(x,y){
				var entity = jQuery(y).val();
				if (entities.indexOf('/'+entity+'/') != -1) {
					jQuery(y).removeAttr('disabled').parent().show();
					howmany++;
					if (entity == 'db') { howmany += 4.5;}
				} else {
					jQuery(y).attr('disabled','disabled').parent().hide();
				}
			});
			var height = 276+howmany*20;
			jQuery('#updraft-restore-modal').dialog("option", "height", height);
		}
		</script>
ENDHERE;
		}
		$ret .= '</table>';
		return $ret;
	}

	// This function examines inside the updraft directory to see if any new archives have been uploaded. If so, it adds them to the backup set. (No removal of items from the backup set is done)
	function rebuild_backup_history() {

		global $updraftplus;

		$known_files = array();
		$known_nonces = array();
		$changes = false;

		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history = array();

		$updraft_dir = $updraftplus->backups_dir_location();
		if (!is_dir($updraft_dir)) return;

		// Accumulate a list of known files
		foreach ($backup_history as $btime => $bdata) {
			$found_file = false;
			foreach ($bdata as $key => $value) {
				// Record which set this file is found in
				if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(zip|gz|gz\.crypt)$/i', $value, $matches)) {
					$nonce = $matches[2];
// 					if (empty($bdata['service']) && !is_file($updraft_dir.'/'.$value)) {
					if (isset($bdata['service']) && $bdata['service'] == 'none' && !is_file($updraft_dir.'/'.$value)) {
						# File no longer present
					} else {
						$found_file = true;
						$known_files[$value] = $nonce;
						$known_nonces[$nonce] = $btime;
					}
				}
			}
			if (!$found_file) {
				unset($backup_history[$btime]);
				$changes = true;
			}
		}

		if (!$handle = opendir($updraft_dir)) return;
	
		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)(\d+)?\.(zip|gz|gz\.crypt)$/i', $entry, $matches)) {
					$btime = strtotime($matches[1]);
					if ($btime > 100) {
						if (!isset($known_files[$entry])) {
							$changes = true;
							$nonce = $matches[2];
							$type = $matches[3];
							$index = (empty($matches[4])) ? '1' : $matches[4];
							// TODO: Store using the index (first need to tweak reading side to cope with it)
							// The time from the filename does not include seconds. Need to identify the seconds to get the right time
							if (isset($known_nonces[$nonce])) $btime = $known_nonces[$nonce];
							// No cloud backup known of this file
							if (!isset($backup_history[$btime])) $backup_history[$btime] = array('service' => 'none' );
							$backup_history[$btime][$type] = $entry;
							$backup_history[$btime][$type.'-size'] = filesize($updraft_dir.'/'.$entry);
							$backup_history[$btime]['nonce'] = $nonce;
						}
					}
				}
			}
		}

		if ($changes) UpdraftPlus_Options::update_updraft_option('updraft_backup_history', $backup_history);

	}

	// Return values: false = 'not yet' (not necessarily terminal); WP_Error = terminal failure; true = success
	function restore_backup($timestamp) {

		@set_time_limit(900);

		global $wp_filesystem, $updraftplus;
		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>'.__('This backup does not exist in the backup history - restoration aborted. Timestamp:','updraftplus')." $timestamp</p><br/>";
			return new WP_Error('does_not_exist', 'Backup does not exist in the backup history');
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
			if (strpos($key, 'updraft_restorer_') === 0 ) {
				$extra_fields[] = $key;
			}
		}

		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_restore&backup_timestamp=$timestamp", '', false, false, $extra_fields);
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}

		//if we make it this far then WP_Filesystem has been instantiated and is functional (tested with ftpext, what about suPHP and other situations where direct may work?)
		echo '<h1>'.__('UpdraftPlus Restoration: Progress', 'updraftplus').'</h1><div id="updraft-restore-progress">';

		$updraft_dir = $updraftplus->backups_dir_location().'/';

		$service = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;

		// Now, need to turn any updraft_restore_<entity> fields (that came from a potential WP_Filesystem form) back into parts of the _POST array (which we want to use)
		if (empty($_POST['updraft_restore']) || (!is_array($_POST['updraft_restore']))) $_POST['updraft_restore'] = array();

		$entities_to_restore = array_flip($_POST['updraft_restore']);

		foreach ($_POST as $key => $value) {
			if (strpos($key, 'updraft_restore_') === 0 ) {
				$nkey = substr($key, 16);
				if (!isset($entities_to_restore[$nkey])) {
					$_POST['updraft_restore'][] = $nkey;
					$entities_to_restore[$nkey] = 1;
				}
			}
		}

		if (count($_POST['updraft_restore']) == 0) {
			echo '<p>'.__('ABORT: Could not find the information on which entities to restore.', 'updraftplus').'</p>';
			echo '<p>'.__('If making a request for support, please include this information:','updraftplus').' '.count($_POST).' : '.htmlspecialchars(serialize($_POST)).'</p>';
			return new WP_Error('missing_info', 'Backup information not found');
		}

		/*
		$_POST['updraft_restore'] is typically something like: array( 0=>'db', 1=>'plugins', 2=>'themes'), etc.
		i.e. array ( 'db', 'plugins', themes')
		*/

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		$backup_set = $backup_history[$timestamp];
		uksort($backup_set, array($this, 'sort_restoration_entities'));

		// We use a single object for each entity, because we want to store information about the backup set
		if(!class_exists('WP_Upgrader')) require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
		require_once(UPDRAFTPLUS_DIR.'/restorer.php');
		$restorer = new Updraft_Restorer();

		$second_loop = array();

		echo "<h2>".__('Final checks', 'updraftplus').'</h2>';

		// First loop: make sure that files are present + readable; and populate array for second loop
		foreach($backup_set as $type => $files) {
			// All restorable entities must be given explicitly, as we can store other arbitrary data in the history array
			if (!isset($backupable_entities[$type]) && 'db' != $type) continue;
			if (isset($backupable_entities[$type]['restorable']) && $backupable_entities[$type]['restorable'] == false) continue;

			if (!isset($entities_to_restore[$type])) continue;

			if ($type == 'wpcore' && is_multisite() && 0 === $restorer->ud_backup_is_multisite) {
				echo "<p>$type: <strong>".__('Skipping restoration of WordPress core when importing a single site into a multisite installation. If you had anything necessary in your WordPress directory then you will need to re-add it manually from the zip file.', 'updraftplus')."</strong></p>";
				continue;
			}

			if (is_string($files)) $files=array($files);

			foreach ($files as $ind => $file) {
				$fullpath = $updraft_dir.$file;
				echo sprintf(__("Looking for %s archive: file name: %s", 'updraftplus'), $type, htmlspecialchars($file))."<br>";
				if(!is_readable($fullpath)) {
					echo __("File is not locally present - needs retrieving from remote storage",'updraftplus')."<br>";
					$this->download_file($file, $service);
				}
				$index = ($ind == 0) ? '' : $ind;
				// If a file size is stored in the backup data, then verify correctness of the local file
				if (isset($backup_history[$timestamp][$type.$index.'-size'])) {
					$fs = $backup_history[$timestamp][$type.$index.'-size'];
					echo __("Archive is expected to be size:",'updraftplus')." ".round($fs/1024)." Kb: ";
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
					return false;
				}
			}

			$info = (isset($backupable_entities[$type])) ? $backupable_entities[$type] : array();
			$val = $restorer->pre_restore_backup($files, $type, $info);
			if (is_wp_error($val)) {
				foreach ($val->get_error_messages() as $msg) {
					echo '<strong>'.__('Error message',  'updraftplus').':</strong> '.htmlspecialchars($msg).'<br>';
				}
				echo '</div>'; //close the updraft_restore_progress div even if we error
				return $val;
			} elseif (false === $val) {
				echo '</div>'; //close the updraft_restore_progress div even if we error
				return false;
			}

			$second_loop[$type] = $files;
		}

		// Second loop: now actually do the restoration
		uksort($second_loop, array($this, 'sort_restoration_entities'));
		foreach ($second_loop as $type => $files) {
			# Types: uploads, themes, plugins, others, db
			$info = (isset($backupable_entities[$type])) ? $backupable_entities[$type] : array();

			echo ('db' == $type) ? "<h2>".__('Database','updraftplus')."</h2>" : "<h2>".$info['description']."</h2>";

			$val = $restorer->restore_backup($files, $type, $service, $info);

			if(is_wp_error($val)) {
				foreach ($val->get_error_messages() as $msg) {
					echo '<strong>'.__('Error message',  'updraftplus').':</strong> '.htmlspecialchars($msg).'<br>';
				}
				echo '</div>'; //close the updraft_restore_progress div even if we error
				return $val;
			} elseif (false === $val) {
				echo '</div>'; //close the updraft_restore_progress div even if we error
				return false;
			}
		}


		echo '</div>'; //close the updraft_restore_progress div
		return true;
	}

	function sort_restoration_entities($a, $b) {
		if ($a == $b) return 0;
		# Put the database first
		if ($a == 'db') return -1;
		if ($b == 'db') return 1;
		return strcmp($a, $b);
	}

}

?>
