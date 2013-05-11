<?php

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

		// First, the checks that are on all (admin) pages:

		if (UpdraftPlus_Options::user_can_manage() && UpdraftPlus_Options::get_updraft_option('updraft_service') == "googledrive" && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid','') != '' && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token','') == '') {
			add_action('admin_notices', array($this,'show_admin_warning_googledrive') );
		}

		if (UpdraftPlus_Options::user_can_manage() && UpdraftPlus_Options::get_updraft_option('updraft_service') == "dropbox" && UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token','') == '') {
			add_action('admin_notices', array($this,'show_admin_warning_dropbox') );
		}

		if (UpdraftPlus_Options::user_can_manage() && $this->disk_space_check(1024*1024*35) === false) add_action('admin_notices', array($this, 'show_admin_warning_diskspace'));

		// Next, the actions that only come on the UpdraftPlus page
		if ($pagenow != 'options-general.php' || !isset($_REQUEST['page']) || 'updraftplus' != $_REQUEST['page']) return;

		if(UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
			@ini_set('display_errors',1);
			@error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
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
		if ($pagenow != 'options-general.php' || !isset($_REQUEST['page']) && 'updraftplus' != $_REQUEST['page']) return;

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
		#filelist .file, #filelist2 .file, #ud_downloadstatus .file {
			padding: 5px;
			background: #ececec;
			border: solid 1px #ccc;
			margin: 4px 0;
		}
		#filelist .fileprogress, #filelist2 .fileprogress, #ud_downloadstatus .dlfileprogress {
			width: 0%;
			background: #f6a828;
			height: 5px;
		}
		#ud_downloadstatus .raw {
			margin-top: 8px;
			clear:left;
		}
		#ud_downloadstatus .file {
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
			$known_size  = filesize($fullpath);
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
			$updraftplus->error("$file: ".sprintf(__("The backup archive for restoring this file could not be found. The remote storage method in use (%s) does not allow us to retrieve files. To proceed with this restoration, you need to obtain a copy of this file and place it inside UpdraftPlus's working folder", 'updraftplus'), $service)." (".$this->prune_updraft_dir_prefix($updraftplus->backups_dir_location()).")");
		}

	}

	// Called via AJAX
	function updraft_ajax_handler() {

		global $updraftplus;

		// Test the nonce (probably not needed, since we're presumably admin-authed, but there's no harm)
		$nonce = (empty($_REQUEST['nonce'])) ? "" : $_REQUEST['nonce'];
		if (! wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce') || empty($_REQUEST['subaction'])) die('Security check');

		if ('lastlog' == $_GET['subaction']) {
			echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '('.__('Nothing yet logged', 'updraftplus').')'));
		} elseif ('lastbackup' == $_GET['subaction']) {
			echo $this->last_backup_html();
		} elseif ('diskspaceused' == $_GET['subaction']) {
			echo $this->recursive_directory_size($updraftplus->backups_dir_location());
		} elseif ('historystatus' == $_GET['subaction']) {
			$rescan = (isset($_GET['rescan']) && $_GET['rescan'] == 1);
			if ($rescan) $this->rebuild_backup_history();
			echo $this->existing_backup_table();
		} elseif ('downloadstatus' == $_GET['subaction'] && isset($_GET['timestamp']) && isset($_GET['type'])) {

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

		} elseif ($_POST['subaction'] == 'credentials_test') {
			$method = (preg_match("/^[a-z0-9]+$/", $_POST['method'])) ? $_POST['method'] : "";

			// Test the credentials, return a code
			require_once(UPDRAFTPLUS_DIR."/methods/$method.php");

			$objname = "UpdraftPlus_BackupModule_${method}";
			if (method_exists($objname, "credentials_test")) call_user_func(array('UpdraftPlus_BackupModule_'.$method, 'credentials_test'));
		}

		die;

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
		// check ajax noonce

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
			if(empty($updraftplus->errors) && $backup_success == true) {
				echo '<p><strong>'.__('Restore successful!','updraftplus').'</strong></p>';
				echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus&updraft_restore_success=true">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
				return;
			} else {
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

		if(isset($_GET['action']) && $_GET['action'] == 'updraft_create_backup_dir') {
			if(!$this->create_backup_dir()) {
				echo '<p>'.__('Backup directory could not be created','updraftplus').'...</p><br/>';
			} else {
				echo '<p>'.__('Backup directory successfully created.','updraftplus').'</p><br/>';
			}
			echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
			return;
		}
		
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup') {
			// For unknown reasons, the <script> runs twice if put inside the <div>
			echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;"><strong>',__('Schedule backup','updraftplus').':</strong> ';
			if (wp_schedule_single_event(time()+5, 'updraft_backup_all') === false) {
				$updraftplus->log("A backup run failed to schedule");
				echo __("Failed.",'updraftplus')."</div>";
			} else {
				echo htmlspecialchars(__('OK. Now load any page from your site to make sure the schedule can trigger. You should then see activity in the "Last log message" field below.','updraftplus'))." <a href=\"http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/\">".__('Nothing happening? Follow this link for help.','updraftplus')."</a></div><script>setTimeout(function(){updraft_showlastbackup();}, 7000);</script>";
				$updraftplus->log("A backup run has been scheduled");
			}
		}

		// updraft_file_ids is not deleted
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_all') { $updraftplus->boot_backup(true,true); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_db') { $updraftplus->backup_db(); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_wipesettings') {
			$settings = array('updraft_interval', 'updraft_interval_database', 'updraft_retain', 'updraft_retain_db', 'updraft_encryptionphrase', 'updraft_service', 'updraft_dropbox_appkey', 'updraft_dropbox_secret', 'updraft_googledrive_clientid', 'updraft_googledrive_secret', 'updraft_googledrive_remotepath', 'updraft_ftp_login', 'updraft_ftp_pass', 'updraft_ftp_remote_path', 'updraft_server_address', 'updraft_dir', 'updraft_email', 'updraft_delete_local', 'updraft_debug_mode', 'updraft_include_plugins', 'updraft_include_themes', 'updraft_include_uploads', 'updraft_include_others', 'updraft_include_wpcore', 'updraft_include_wpcore_exclude', 'updraft_include_more', 
			'updraft_include_blogs', 'updraft_include_mu-plugins', 'updraft_include_others_exclude', 'updraft_lastmessage', 'updraft_googledrive_clientid', 'updraft_googledrive_token', 'updraft_dropboxtk_request_token', 'updraft_dropboxtk_access_token', 'updraft_dropbox_folder', 'updraft_last_backup', 'updraft_starttime_files', 'updraft_starttime_db', 'updraft_sftp_settings', 'updraft_disable_ping', 'updraft_cloudfiles_user', 'updraft_cloudfiles_apikey', 'updraft_cloudfiles_path', 'updraft_cloudfiles_authurl', 'updraft_ssl_useservercerts', 'updraft_ssl_disableverify');
			foreach ($settings as $s) {
				UpdraftPlus_Options::delete_updraft_option($s);
			}
			$this->show_admin_warning(__("Your settings have been wiped.",'updraftplus'));
		}

		?>
		<div class="wrap">
			<h1><?php echo $updraftplus->plugin_title; ?></h1>

			<?php _e('By UpdraftPlus.Com','updraftplus')?> ( <a href="http://updraftplus.com">UpdraftPlus.Com</a> | <a href="http://david.dw-perspective.org.uk"><?php _e("Lead developer's homepage",'updraftplus');?></a> | <?php if (!defined('UPDRAFTPLUS_NOADS')) { ?><a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate"><?php _e('Donate','updraftplus');?></a> | <?php } ?><a href="http://updraftplus.com/support/frequently-asked-questions/">FAQs</a> | <a href="http://profiles.wordpress.org/davidanderson/"><?php _e('Other WordPress plugins','updraftplus');?></a>). <?php _e('Version','updraftplus');?>: <?php echo $updraftplus->version; ?>
			<br>
			<?php
			if(isset($_GET['updraft_restore_success'])) {
				// If we restored the database, then that will have out-of-date information which may confuse the user - so automatically re-scan for them.
				$this->rebuild_backup_history();
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
				<div class="updated fade" style="padding:8px;"><?php _e('You have old directories from a previous backup (technical information: these are found in wp-content, and suffixed with -old). Use this button to delete them (if you have verified that the restoration worked).','updraftplus');?>
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

					$backup_disabled = (is_writable($updraft_dir)) ? '' : 'disabled="disabled"';

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
					<th><?php _e('Last finished backup run','updraftplus');?>:</th>
					<td id="updraft_last_backup"><?php echo $last_backup_html ?></td>
				</tr>
			</table>
			<div style="float:left; width:200px; padding-top: 20px;">
				<p><button type="button" <?php echo $backup_disabled ?> class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:22px !important; min-height: 32px;" onclick="jQuery('#updraft-backupnow-modal').dialog('open');"><?php _e('Backup Now','updraftplus');?></button></p>
				<div style="position:relative">
					<div style="position:absolute;top:0;left:0">
						<?php
						$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
						$backup_history = (is_array($backup_history))?$backup_history:array();
						?>
						<input type="button" class="button-primary" value="<?php _e('Restore','updraftplus');?>" style="padding-top:2px;padding-bottom:2px;font-size:22px !important; min-height: 32px;" onclick="jQuery('.download-backups').slideDown(); updraft_historytimertoggle(1); jQuery('html,body').animate({scrollTop: jQuery('#updraft_lastlogcontainer').offset().top},'slow');">
					</div>
				</div>
			</div>
			<br style="clear:both" />
			<table class="form-table">
				<tr>
					<th><?php _e('Last log message','updraftplus');?>:</th>
					<td>
						<span id="updraft_lastlogcontainer"><?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', __('(Nothing yet logged)','updraftplus'))); ?></span><br>
						<a href="?page=updraftplus&action=downloadlatestmodlog&wpnonce=<?php echo wp_create_nonce('updraftplus_download') ?>"><?php _e('Download most recently modified log file','updraftplus');?></a>
					</td>
				</tr>
				<tr>
					<th><?php echo htmlspecialchars(__('Backups, logs & restoring','updraftplus')); ?>:</th>
					<td><a id="updraft_showbackups" href="#" title="<?php _e('Press to see available backups','updraftplus');?>" onclick="jQuery('.download-backups').toggle(); updraft_historytimertoggle(0);"><?php echo sprintf(__('%d set(s) available', 'updraftplus'), count($backup_history)); ?></a></td>
				</tr>
				<tr>
					<td></td><td class="download-backups" style="display:none; border: 1px dotted;">
						<p style="max-width: 740px;"><ul style="list-style: disc inside;">
						<li><strong><?php _e('Downloading','updraftplus');?>:</strong> <?php _e("Pressing a button for Database/Plugins/Themes/Uploads/Others will make UpdraftPlus try to bring the backup file back from the remote storage (if any - e.g. Amazon S3, Dropbox, Google Drive, FTP) to your webserver. Then you will be allowed to download it to your computer. If the fetch from the remote storage stops progressing (wait 30 seconds to make sure), then press again to resume. Remember that you can also visit the cloud storage vendor's website directly.",'updraftplus');?></li>
						<li><strong><?php _e('Restoring','updraftplus');?>:</strong> <?php _e("Press the button for the backup you wish to restore. If your site is large and you are using remote storage, then you should first click on each entity in order to retrieve it back to the webserver. This will prevent time-outs from occuring during the restore process itself.",'updraftplus');?> <?php _e('More tasks:','updraftplus');?> <a href="#" onclick="jQuery('#updraft-plupload-modal').slideToggle(); return false;"><?php _e('upload backup files','updraftplus');?></a> | <a href="#" onclick="updraft_updatehistory(1); return false;" title="<?php _e('Press here to look inside your UpdraftPlus directory (in your web hosting space) for any new backup sets that you have uploaded. The location of this directory is set in the expert settings, below.','updraftplus'); ?>"><?php _e('rescan folder for new backup sets','updraftplus');?></a></li>
						<li><strong><?php _e('Opera web browser','updraftplus');?>:</strong> <?php _e('If you are using this, then turn Turbo/Road mode off.','updraftplus');?></li>
						<?php if (UpdraftPlus_Options::get_updraft_option('updraft_service') == 'googledrive') {
							?><li><strong><?php _e('Google Drive','updraftplus');?>:</strong> <?php _e('Google changed their permissions setup recently (April 2013). To download or restore from Google Drive, you <strong>must</strong> first re-authenticate (using the link in the Google Drive configuration section).','updraftplus');?></li>
						<?php } ?>
						<li title="<?php _e('This is a count of the contents of your Updraft directory','updraftplus');?>"><strong><?php _e('Web-server disk space in use by UpdraftPlus','updraftplus');?>:</strong> <span id="updraft_diskspaceused"><em>(calculating...)</em></span> <a href="#" onclick="updraftplus_diskspace(); return false;"><?php _e('refresh','updraftplus');?></a></li></ul>

						<div id="updraft-plupload-modal" title="<?php _e('UpdraftPlus - Upload backup files','updraftplus'); ?>" style="width: 75%; margin: 16px; display:none; margin-left: 100px;">
						<p><em><?php _e("Upload files into UpdraftPlus. Use this to import backups made on a different WordPress installation." ,'updraftplus');?></em></p>
							<div id="plupload-upload-ui" style="width: 70%;">
								<div id="drag-drop-area">
									<div class="drag-drop-inside">
									<p class="drag-drop-info"><?php _e('Drop backup zips here'); ?></p>
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
								jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'diskspaceused', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
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
							function updraft_downloader(base, nonce, what) {
								// Create somewhere for the status to be found
								var stid = base+nonce+'_'+what;
								if (!jQuery('#'+stid).length) {
									jQuery('#ud_downloadstatus').append('<div style="clear:left; border: 1px solid; padding: 8px; margin-top: 4px; max-width:840px;" id="'+stid+'"><button onclick="jQuery(\'#'+stid+'\').fadeOut().remove();" type="button" style="float:right; margin-bottom: 8px;">X</button><strong>Download '+what+' ('+nonce+')</strong>:<div class="raw">Begun looking for this entity</div><div class="file" id="'+stid+'_st"><div class="dlfileprogress" style="width: 0;"></div></div>');
									// <b><span class="dlname">??</span></b> (<span class="dlsofar">?? KB</span>/<span class="dlsize">??</span> KB)
									setTimeout(function(){updraft_downloader_status(base, nonce, what)}, 300);
								}
								// Now send the actual request to kick it all off
								jQuery.post(ajaxurl, jQuery('#uddownloadform_'+what+'_'+nonce).serialize());
								// We don't want the form to submit as that replaces the document
								return false;
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
													if (resp.p < 100 || base != 'uddlstatus_') {
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

<div id="updraft-restore-modal" title="UpdraftPlus - <?php _e('Restore backup','updraftplus');?>">
<p><strong><?php _e('Restore backup from','updraftplus');?>:</strong> <span id="updraft_restore_date"></span></p>
<p><?php _e("Restoring will replace this site's themes, plugins, uploads, database and/or other content directories (according to what is contained in the backup set, and your selection).",'updraftplus');?> <?php _e('Choose the components to restore','updraftplus');?>:</p>
<form id="updraft_restore_form" method="post">
	<fieldset>
		<input type="hidden" name="action" value="updraft_restore">
		<input type="hidden" name="backup_timestamp" value="0" id="updraft_restore_timestamp">
		<?php

		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if(@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") {
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

<div id="updraft-backupnow-modal" title="UpdraftPlus - <?php _e('Perform a backup now','updraftplus'); ?>">
	<p><?php _e("This will schedule a one-time backup. To proceed, press 'Backup Now', then wait 10 seconds, then visit any page on your site. WordPress should then start the backup running in the background.",'updraftplus');?></p>

	<form id="updraft-backupnow-form" method="post">
		<input type="hidden" name="action" value="updraft_backup" />
	</form>

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
			<h2><?php _e('Configure Backup Contents And Schedule','updraftplus');?></h2>
			<?php UpdraftPlus_Options::options_form_begin(); ?>
				<?php $this->settings_formcontents($last_backup_html); ?>
			</form>
			<div style="padding-top: 40px; display:none;" class="expertmode">
				<hr>
				<h3><?php _e('Debug Information And Expert Options','updraftplus');?></h3>
				<p>
				<?php
				$peak_memory_usage = memory_get_peak_usage(true)/1024/1024;
				$memory_usage = memory_get_usage(true)/1024/1024;
				echo __('Peak memory usage','updraftplus').': '.$peak_memory_usage.' MB<br/>';
				echo __('Current memory usage','updraftplus').': '.$memory_usage.' MB<br/>';
				echo __('PHP memory limit','updraftplus').': '.ini_get('memory_limit').' <br/>';
				?>
				</p>
				<p style="max-width: 600px;"><?php _e('The buttons below will immediately execute a backup run, independently of WordPress\'s scheduler. If these work whilst your scheduled backups and the "Backup Now" button do absolutely nothing (i.e. not even produce a log file), then it means that your scheduler is broken. You should then disable all your other plugins, and try the "Backup Now" button. If that fails, then contact your web hosting company and ask them if they have disabled wp-cron. If it succeeds, then re-activate your other plugins one-by-one, and find the one that is the problem and report a bug to them.','updraftplus');?></p>

				<form method="post">
					<input type="hidden" name="action" value="updraft_backup_debug_all" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="<?php _e('Debug Full Backup','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will cause an immediate backup. The page will stall loading until it finishes (ie, unscheduled).','updraftplus'));?>'))" /></p>
				</form>
				<form method="post">
					<input type="hidden" name="action" value="updraft_backup_debug_db" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="<?php _e('Debug Database Backup','updraftplus');?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will cause an immediate DB backup. The page will stall loading until it finishes (ie, unscheduled). The backup may well run out of time; really this button is only helpful for checking that the backup is able to get through the initial stages, or for small WordPress sites..','updraftplus'));?>'))" /></p>
				</form>
				<h3><?php _e('Wipe Settings','updraftplus');?></h3>
				<p style="max-width: 600px;"><?php _e('This button will delete all UpdraftPlus settings (but not any of your existing backups from your cloud storage). You will then need to enter all your settings again. You can also do this before deactivating/deinstalling UpdraftPlus if you wish.','updraftplus');?></p>
				<form method="post">
					<input type="hidden" name="action" value="updraft_wipesettings" />
					<p><input type="submit" class="button-primary" value="<?php _e('Wipe All Settings','updraftplus'); ?>" onclick="return(confirm('<?php echo htmlspecialchars(__('This will delete all your UpdraftPlus settings - are you sure you want to do this?'));?>'))" /></p>
				</form>
			</div>

			<script type="text/javascript">
			/* <![CDATA[ */
				jQuery(document).ready(function() {
					jQuery('#updraft-service').change(function() {
						jQuery('.updraftplusmethod').hide();
						var active_class = jQuery(this).val();
						jQuery('.'+active_class).show();
					});
				})
				jQuery(window).load(function() {
					//this is for hiding the restore progress at the top after it is done
					setTimeout('jQuery("#updraft-restore-progress").toggle(1000)',3000)
					jQuery('#updraft-restore-progress-toggle').click(function() {
						jQuery('#updraft-restore-progress').toggle(500)
					})
				})
			/* ]]> */
			</script>
			<?php
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
		
		$content_dir = $wp_filesystem->wp_content_dir();
		$list = $wp_filesystem->dirlist($content_dir);

		$return_code = true;

		foreach ($list as $item) {
			if (substr($item['name'], -4, 4) == "-old") {
				//recursively delete
				print "<strong>".__('Delete','updraftplus').": </strong>".htmlspecialchars($item['name']).": ";
				if(!$wp_filesystem->delete($content_dir.$item['name'], true)) {
					$return_code = false;
					print "<strong>Failed</strong><br>";
				} else {
					print "<strong>OK</strong><br>";
				}
			}
		}

		return $return_code;
	}

	function create_backup_dir() {
		global $wp_filesystem, $updraftplus;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_create_backup_dir"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
			exit; 
		}

		$updraft_dir = $updraftplus->backups_dir_location();

		$default_backup_dir = $wp_filesystem->find_folder($updraft_dir);
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;

		if (!$wp_filesystem->mkdir($updraft_dir, 0775)) return false;

		return true;
	}

	function execution_time_check($time) {
		$setting = ini_get('max_execution_time');
		return ( $setting==0 || $setting >= $time) ? true : false;
	}

	//scans the content dir to see if any -old dirs are present
	function scan_old_dirs() {
		$dirArr = scandir(WP_CONTENT_DIR);
		foreach($dirArr as $dir) {
			if(strpos($dir,'-old') !== false) return true;
		}
		return false;
	}

	function last_backup_html() {

		global $updraftplus;

		$updraft_last_backup = UpdraftPlus_Options::get_updraft_option('updraft_last_backup');

		$updraft_dir = $updraftplus->backups_dir_location();

		if($updraft_last_backup) {

			if ($updraft_last_backup['success']) {
				// Convert to GMT, then to blog time
				$last_backup_text = get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraft_last_backup['backup_time']), 'D, F j, Y H:i');
			} else {
				$last_backup_text = implode("<br>",$updraft_last_backup['errors']);
			}

			if (!empty($updraft_last_backup['backup_nonce'])) {
				$potential_log_file = $updraft_dir."/log.".$updraft_last_backup['backup_nonce'].".txt";
				if (is_readable($potential_log_file)) $last_backup_text .= "<br><a href=\"?page=updraftplus&action=downloadlog&updraftplus_backup_nonce=".$updraft_last_backup['backup_nonce']."\">".__('Download log file','updraftplus')."</a>";
			}

			$last_backup_color = ($updraft_last_backup['success']) ? 'green' : 'red';

		} else {
			$last_backup_text = __('No backup has been completed.','updraftplus');
			$last_backup_color = 'blue';
		}

		return "<span style=\"color:${last_backup_color}\">${last_backup_text}</span>";

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
						?><input id="updraft_include_others" type="checkbox" name="updraft_include_others" value="1" <?php echo $included; ?> /> <label for="updraft_include_<?php echo $key ?>"><?php echo __('Any other directories found inside wp-content', 'updraftplus');?></label><br><?php

						$display = ($included) ? '' : 'style="display:none;"';

						echo "<div id=\"updraft_include_others_exclude\" $display>";

							echo '<label for="updraft_include_others_exclude">'.__('Exclude these:', 'updraftplus').'</label>';

							echo '<input title="'.__('If entering multiple files/directories, then separate them with commas', 'updraftplus').'" type="text" id="updraft_include_others_exclude" name="updraft_include_others_exclude" size="54" value="'.htmlspecialchars($include_others_exclude).'" />';

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
						echo "<input id=\"updraft_include_$key\" type=\"checkbox\" name=\"updraft_include_$key\" value=\"1\" $included /><label for=\"updraft_include_$key\"> ".$info['description']."</label><br>";
						do_action("updraftplus_config_option_include_$key");
					}
				}
			?>
				<p><?php echo apply_filters('updraftplus_admin_directories_description', __('The above directories are everything, except for WordPress core itself which you can download afresh from WordPress.org.', 'updraftplus').' <a href="http://updraftplus.com/shop/">'.htmlspecialchars(__('Or, get the "More Files" add-on from our shop.', 'updraftplus'))); ?></a> <a href="http://wordshell.net"></p><p>(<?php echo __('Use WordShell for automatic backup, version control and patching', 'updraftplus');?></a>).</p></td>
				</td>
			</tr>
			<tr>
				<th><?php _e('Email','updraftplus'); ?>:</th>
				<td><input type="text" style="width:260px" name="updraft_email" value="<?php echo UpdraftPlus_Options::get_updraft_option('updraft_email'); ?>" /> <br><?php _e('Enter an address here to have a report sent (and the whole backup, if you choose) to it.','updraftplus'); ?></td>
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
								<p class="drag-drop-info"><?php _e('Drop encrypted database files (db.crypt.gz files) here to upload them for decryption'); ?></p>
								<p><?php _ex('or', 'Uploader: Drop .crypt.db.gz files here to upload them for decryption - or - Select Files'); ?></p>
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
				var lastlog_lastmessage = "";
				var lastlog_sdata = {
					action: 'updraft_ajax',
					subaction: 'lastlog',
					nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>'
				};
				function updraft_showlastlog(){
					jQuery.get(ajaxurl, lastlog_sdata, function(response) {
						nexttimer = 1500;
						if (lastlog_lastmessage == response) { nexttimer = 4500; }
						setTimeout(function(){updraft_showlastlog()}, nexttimer);
						jQuery('#updraft_lastlogcontainer').html(response);
						lastlog_lastmessage = response;
					});
				}
				var lastbackup_sdata = {
					action: 'updraft_ajax',
					subaction: 'lastbackup',
					nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>'
				};
				var lastbackup_laststatus = '<?php echo $last_backup_html?>'
				function updraft_showlastbackup(){
					jQuery.get(ajaxurl, lastbackup_sdata, function(response) {
						if (lastbackup_laststatus == response) {
							setTimeout(function(){updraft_showlastbackup()}, 7000);
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
						updraft_historytimer = setInterval(function(){updraft_updatehistory(0)}, 30000);
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
						jQuery('#updraft_existing_backups').html('<p style="text-align:center;"><em>Rescanning (looking for backups that you have uploaded manually into the internal backup store)...</em></p>');
					}
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'historystatus', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>', rescan: rescan }, function(response) {
						jQuery('#updraft_existing_backups').html(response);
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

					jQuery( "#updraft-restore-modal" ).dialog({
						autoOpen: false, height: 505, width: 590, modal: true,
						buttons: {
							'<?php _e('Restore','updraftplus');?>': function() {
								var anyselected = 0;
								jQuery('input[name="updraft_restore[]"]').each(function(x,y){
									if (jQuery(y).is(':checked')) {
										anyselected = 1;
										//alert(jQuery(y).val());
									}
								});
								if (anyselected == 1) {
									jQuery('#updraft_restore_form').submit();
								} else {
									alert('You did not select any components to restore. Please select at least one, and then try again.');
								}
							},
							'<?php _e('Cancel','updraftplus');?>': function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery( "#updraft-backupnow-modal" ).dialog({
						autoOpen: false, height: 265, width: 375, modal: true,
						buttons: {
							'<?php _e('Backup Now','updraftplus');?>': function() {
								jQuery('#updraft-backupnow-form').submit();
							},
							'<?php _e('Cancel','updraftplus');?>': function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery('#enableexpertmode').click(function() {
						jQuery('.expertmode').fadeIn();
						return false;
					});
					<?php if (!@is_writable($updraft_dir)) echo "jQuery('.backupdirrow').show();\n"; ?>
					setTimeout(function(){updraft_showlastlog();}, 1200);
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
				<td><a id="enableexpertmode" href="#"><?php _e('Show expert settings','updraftplus');?></a> - <?php _e("click this to show some further options; don't bother with this unless you have a problem or are curious.",'updraftplus');?> <?php do_action('updraftplus_expertsettingsdescription'); ?></td>
			</tr>
			<?php
			$delete_local = UpdraftPlus_Options::get_updraft_option('updraft_delete_local', 1);
			?>

			<tr class="deletelocal expertmode" style="display:none;">
				<th><?php _e('Delete local backup','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_delete_local" name="updraft_delete_local" value="1" <?php if ($delete_local) echo 'checked="checked"'; ?>> <br><label for="updraft_delete_local"><?php _e('Uncheck this to prevent deletion of any superfluous backup files from your server after the backup run finishes (i.e. any files despatched remotely will also remain locally, and any files being kept locally will not be subject to the retention limits).','updraftplus');?></label></td>
			</tr>


			<tr class="expertmode backupdirrow" style="display:none;">
				<th><?php _e('Backup directory','updraftplus');?>:</th>
				<td><input type="text" name="updraft_dir" id="updraft_dir" style="width:525px" value="<?php echo htmlspecialchars($this->prune_updraft_dir_prefix($updraft_dir)); ?>" /></td>
			</tr>
			<tr class="expertmode backupdirrow" style="display:none;">
				<td></td><td><?php

					// Suppress warnings, since if the user is dumping warnings to screen, then invalid JavaScript results and the screen breaks.
					if(@is_writable($updraft_dir)) {
						$dir_info = '<span style="color:green">'.__('Backup directory specified is writable, which is good.','updraftplus').'</span>';
					} else {
						$dir_info = '<span style="color:red">'.__('Backup directory specified is <b>not</b> writable, or does not exist.','updraftplus').' <span style="font-size:110%;font-weight:bold"><a href="options-general.php?page=updraftplus&action=updraft_create_backup_dir">'.__('Click here to attempt to create the directory and set the permissions','updraftplus').'</a></span>, '.__('or, to reset this option','updraftplus').' <a href="#" onclick="jQuery(\'#updraft_dir\').val(\''.WP_CONTENT_DIR.'/updraft\'); return false;">'.__('click here','updraftplus').'</a>. '.__('If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.','updraftplus').'</span>';
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

	function curl_check($service, $has_fallback = false) {
		// Check requirements
		if (!function_exists("curl_init")) {
			?><p><strong><?php _e('Warning','updraftplus'); ?>:</strong> <?php echo sprintf(__('Your web server\'s PHP installation does not included a required module (%s). Please contact your web hosting provider\'s support.', 'updraftplus'), 'Curl'); ?> <?php echo sprintf(__("UpdraftPlus's %s module <strong>requires</strong> Curl. Your only options to get this working are 1) Install/enable curl or 2) Hire us or someone else to code additional support options into UpdraftPlus. 3) Wait, possibly forever, for someone else to do this.",'updraftplus'),$service);?></p><?php
		} else {
			$curl_version = curl_version();
			$curl_ssl_supported= ($curl_version['features'] & CURL_VERSION_SSL);
			if (!$curl_ssl_supported) {
				if ($has_fallback) {
					?><p><strong><?php _e('Warning','updraftplus'); ?>:</strong> <?php echo sprintf(__("Your web server's PHP/Curl installation does not support https access. Communications with %s will be unencrypted. ask your web host to install Curl/SSL in order to gain the ability for encryption (via an add-on).",'updraftplus'),$service);?></p><?php
				} else {
					?><p><strong><?php _e('Warning','updraftplus'); ?>:</strong> <?php echo sprintf(__("Your web server's PHP/Curl installation does not support https access. We cannot access %s without this support. Please contact your web hosting provider's support. %s <strong>requires</strong> Curl+https. Please do not file any support requests; there is no alternative.",'updraftplus'),$service);?></p><?php
				}
			} else {
				?><p><em><?php echo sprintf(__("Good news: Your site's communications with %s can be encrypted. If you see any errors to do with encryption, then look in the 'Expert Settings' for more help.", 'updraftplus'),$service);?></em></p><?php
			}
		}
	}

	function recursive_directory_size($directory) {
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
						$handlesize = $this->recursive_directory_size($path);
						if($handlesize >= 0) { $size += $handlesize; } else { return -1; }
					}
				}
			}
			closedir($handle);
		}
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

	function existing_backup_table($backup_history = false) {

		global $updraftplus;

		// Fetch it if it was not passed
		if ($backup_history === false) $backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history=array();

		$updraft_dir = $updraftplus->backups_dir_location();

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		echo '<table>';

		krsort($backup_history);

		foreach($backup_history as $key=>$value) {
			$pretty_date = date('Y-m-d G:i',$key);
			$entities = '';
			?>
		<tr>
			<td><b><?php echo $pretty_date?></b></td>
			<td>
		<?php if (isset($value['db'])) {
					$entities .= '/db/';
					$sdescrip = preg_replace('/ \(.*\)$/', '', __('Database','updraftplus'));
		?>
				<form id="uddownloadform_db_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader('uddlstatus_', <?php echo $key;?>, 'db')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="db" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input type="submit" value="<?php _e('Database','updraftplus');?>" />
				</form>
		<?php } else { echo "(No&nbsp;database)"; } ?>
			</td>

		<?php
			foreach ($backupable_entities as $type => $info) {
				echo '<td>';
				$sdescrip = preg_replace('/ \(.*\)$/', '', $info['description']);
				if (strlen($sdescrip) > 20 && isset($info['shortdescription'])) $sdescrip = $info['shortdescription'];
				if (isset($value[$type])) {
					$entities .= '/'.$type.'/';
				?>
				<form id="uddownloadform_<?php echo $type.'_'.$key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader('uddlstatus_', '<?php echo $key."', '".$type;?>')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="<?php echo $type; ?>" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input  type="submit" title="<?php echo __('Press here to download','updraftplus').' '.strtolower($info['description']); ?>" value="<?php echo $sdescrip;?>" />
				</form>
		<?php } else { printf(_x('(No %s)','Message shown when no such object is available','updraftplus'), preg_replace('/\s\(.{12,}\)/', '', strtolower($sdescrip))); } ?>
			</td>
		<?php }; ?>

			<td>
		<?php if (isset($value['nonce']) && preg_match("/^[0-9a-f]{12}$/",$value['nonce']) && is_readable($updraft_dir.'/log.'.$value['nonce'].'.txt')) { ?>
				<form action="options-general.php" method="get">
					<input type="hidden" name="action" value="downloadlog" />
					<input type="hidden" name="page" value="updraftplus" />
					<input type="hidden" name="updraftplus_backup_nonce" value="<?php echo $value['nonce']; ?>" />
					<input type="submit" value="<?php _e('Backup Log','updraftplus');?>" />
				</form>
		<?php } else { echo "(No&nbsp;backup&nbsp;log)"; } ?>
			</td>
			<td>
				<form method="post" action="">
					<input type="hidden" name="backup_timestamp" value="<?php echo $key;?>">
					<input type="hidden" name="action" value="updraft_restore" />
					<?php if ($entities) { ?><button title="<?php _e('After pressing this button, you will be given the option to choose which components you wish to restore','updraftplus');?>" type="button" class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:16px !important; min-height:26px;" onclick="updraft_restore_setoptions('<?php echo $entities;?>'); jQuery('#updraft_restore_timestamp').val('<?php echo $key;?>'); jQuery('#updraft_restore_date').html('<?php echo $pretty_date;?>'); jQuery('#updraft-restore-modal').dialog('open');"><?php _e('Restore','updraftplus');?></button><?php } ?>
				</form>
			</td>
		</tr>
		<script>
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
		<?php }
		echo '</table>';
	}

	// This function examines inside the updraft directory to see if any new archives have been uploaded. If so, it adds them to the backup set. (No removal of items from the backup set is done)
	function rebuild_backup_history() {

		global $updraftplus;

		$known_files = array();
		$known_nonces = array();
		$changes = false;

		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history = array();

		// Accumulate a list of known files
		foreach ($backup_history as $btime => $bdata) {
			foreach ($bdata as $key => $value) {
				// Record which set this file is found in
				if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(zip|gz|gz\.crypt)$/i', $value, $matches)) {
					$nonce = $matches[2];
					$known_files[$value] = $nonce;
					$known_nonces[$nonce] = $btime;
				}
			}
		}
		
		$updraft_dir = $updraftplus->backups_dir_location();

		if (!is_dir($updraft_dir)) return;

		if (!$handle = opendir($updraft_dir)) return;
	
		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)\.(zip|gz|gz\.crypt)$/i', $entry, $matches)) {
					$btime = strtotime($matches[1]);
					if ($btime > 100) {
						if (!isset($known_files[$entry])) {
							$changes = true;
							$nonce = $matches[2];
							$type = $matches[3];
							// The time from the filename does not include seconds. Need to identify the seconds to get the right time
							if (isset($known_nonces[$nonce])) $btime = $known_nonces[$nonce];
							if (!isset($backup_history[$btime])) $backup_history[$btime] = array();
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

	function restore_backup($timestamp) {

		@set_time_limit(900);

		global $wp_filesystem, $updraftplus;
		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>'.__('This backup does not exist in the backup history - restoration aborted. Timestamp:','updraftplus')." $timestamp</p><br/>";
			return false;
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
			return false;
		}

		/*
		$_POST['updraft_restore'] is typically something like: array( 0=>'db', 1=>'plugins', 2=>'themes'), etc.
		i.e. array ( 'db', 'plugins', themes')
		*/

		$backupable_entities = $updraftplus->get_backupable_file_entities(true, true);

		foreach($backup_history[$timestamp] as $type => $file) {
			// All restorable entities must be given explicitly, as we can store other arbitrary data in the history array
			 
			if (!isset($backupable_entities[$type]) && 'db' != $type) continue;

			if (isset($backupable_entities[$type]['restorable']) && $backupable_entities[$type]['restorable'] == false) continue;

			if ($type == 'db') {
				echo "<h2>".__('Database','updraftplus')."</h2>";
			} else {
				echo "<h2>".$backupable_entities[$type]['description']."</h2>";
			}

			if (!isset($entities_to_restore[$type])) {
				echo "<p>$type: ".__('This component was not selected for restoration - skipping.', 'updraftplus')."</p>";
				continue;
			}

			$fullpath = $updraft_dir.$file;

			echo "Looking for $type archive: file name: ".htmlspecialchars($file)."<br>";
			if(!is_readable($fullpath)) {
				echo __("File is not locally present - needs retrieving from remote storage (for large files, it is better to do this in advance from the download console)",'updraftplus')."<br>";
				$this->download_file($file, $service);
			}
			// If a file size is stored in the backup data, then verify correctness of the local file
			if (isset($backup_history[$timestamp][$type.'-size'])) {
				$fs = $backup_history[$timestamp][$type.'-size'];
				echo __("Archive is expected to be size:",'updraftplus')." ".round($fs/1024)." Kb: ";
				$as = @filesize($fullpath);
				if ($as == $fs) {
					echo "OK<br>";
				} else {
					echo "<strong>".__('ERROR','updraftplus').":</strong> is size: ".round($as/1024)." ($fs, $as)<br>";
				}
			} else {
				echo __("The backup records do not contain information about the proper size of this file.",'updraftplus')."<br>";
			}
			# Types: uploads, themes, plugins, others, db
			if(is_readable($fullpath)) {

				if(!class_exists('WP_Upgrader')) require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
				require_once(UPDRAFTPLUS_DIR.'/includes/updraft-restorer.php');
				$restorer = new Updraft_Restorer();
				
				$info = (isset($backupable_entities[$type])) ? $backupable_entities[$type] : array();
				
				$val = $restorer->restore_backup($file, $type, $service, $info);

				if(is_wp_error($val)) {
					foreach ($val->get_error_messages() as $msg) {
						echo '<strong>'.__('Error message',  'updraftplus').':</strong> '.htmlspecialchars($msg).'<br>';
					}
					echo '</div>'; //close the updraft_restore_progress div even if we error
					return false;
				}
			} else {
				$updraftplus->error("$file: ".__('Could not find one of the files for restoration', 'updraftplus'));
				echo __('Could not find one of the files for restoration', 'updraftplus').": ($file)";
			}
		}
		echo '</div>'; //close the updraft_restore_progress div
		return true;
	}



}

?>
