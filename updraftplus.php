<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: your site can be backed up locally or to Amazon S3, Dropbox, Google Drive, (S)FTP, WebDAV & email, on automatic schedules.
Author: UpdraftPlus.Com, DavidAnderson
Version: 1.5.9
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Text Domain: updraftplus
Author URI: http://updraftplus.com
*/

/*
TODO - some of these are out of date/done, needs pruning
// Add an appeal for translators to email me
// Separate out all restoration code and admin UI into separate file/classes (optimisation)?
// Search for other TODO-s in the code
// Make mcrypt warning on dropbox more prominent - one customer missed it
// Store meta-data on which version of UD the backup was made with (will help if we ever introduce quirks that need ironing)
// Test restoration when uploads dir is /assets/ (e.g. with Shoestrap theme)
// Send the user an email upon their first backup with tips on what to do (e.g. support/improve) (include legacy check to not bug existing users)
//Allow use of /usr/bin/zip - since this can escape from PHP's memory limit. Can still batch as we do so, in order to monitor/measure progress
//Do an automated test periodically for the success of loop-back connections
//When a manual backup is run, use a timer to update the 'Download backups and logs' section, just like 'Last finished backup run'. Beware of over-writing anything that's in there from a resumable downloader.
//Change DB encryption to not require whole gzip in memory (twice)
//Add Rackspace, Box.Net, SugarSync and Microsoft Skydrive support??
//Make it easier to find add-ons
//The restorer has a hard-coded wp-content - fix
//?? On 'backup now', open up a Lightbox, count down 5 seconds, then start examining the log file (if it can be found)
//Should make clear in dashboard what is a non-fatal error (i.e. can be retried) - leads to unnecessary bug reports
// Move the inclusion, cloud and retention data into the backup job (i.e. don't read current config, make it an attribute of each job). In fact, everything should be. So audit all code for where get_option is called inside a backup run: it shouldn't happen.
// Should we resume if the only errors were upon deletion (i.e. the backup itself was fine?) Presently we do, but it displays errors for the user to confuse them. Perhaps better to make pruning a separate scheuled task??
// Warn the user if their zip-file creation is slooowww...
// Create a "Want Support?" button/console, that leads them through what is needed, and performs some basic tests...
// Resuming partial (S)FTP uploads
// Translations
// Add-on to manage all your backups from a single dashboard
// Make disk space check more intelligent (currently hard-coded at 35Mb)
// Provide backup/restoration for UpdraftPlus's settings, to allow 'bootstrap' on a fresh WP install - some kind of single-use code which a remote UpdraftPlus can use to authenticate
// Multiple jobs
// Multisite - a separate 'blogs' zip
// Allow connecting to remote storage, scanning + populating backup history from it
// GoogleDrive in-dashboard download resumption loads the whole archive into memory - should instead either chunk or directly stream fo the file handle
// Multisite add-on should allow restoring of each blog individually
// When looking for files to delete, is the current encryption setting used? Should not be.
// Create single zip, containing even WordPress itself
// When a new backup starts, AJAX-update the 'Last backup' display in the admin page.
// Remove the recurrence of admin notices when settings are saved due to _wp_referer

Encrypt filesystem, if memory allows (and have option for abort if not); split up into multiple zips when needed
// Does not delete old custom directories upon a restore?
// New sub-module to verify that the backups are there, independently of backup thread
*/

/*  Portions copyright 2010 Paul Kehrer
Portions copyright 2011-13 David Anderson
Other portions copyright as indicated authors in the relevant files
Particular thanks to Sorin Iclanzan, author of the "Backup" plugin, from which much Google Drive code was taken under the GPLv3+

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// 15 minutes
@set_time_limit(900);

define('UPDRAFTPLUS_DIR', dirname(__FILE__));
define('UPDRAFTPLUS_URL', plugins_url('', __FILE__));
define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,index.php,backup,backups');
// This is used in various places, based on our assumption of the maximum time any job should take. May need lengthening in future if we get reports which show enormous sets hitting the limit.
// Also one section requires at least 1% progress each run, so on a 5-minute schedule, that equals just under 9 hours - then an extra allowance takes it just over
define('UPDRAFT_TRANSTIME', 3600*9+5);

// Load add-ons
if (is_file(UPDRAFTPLUS_DIR.'/premium.php')) require_once(UPDRAFTPLUS_DIR.'/premium.php');

if ($dir_handle = opendir(UPDRAFTPLUS_DIR.'/addons')) {
	while ($e = readdir($dir_handle)) {
		if (is_file(UPDRAFTPLUS_DIR.'/addons/'.$e) && preg_match('/\.php$/', $e)) {
			include_once(UPDRAFTPLUS_DIR.'/addons/'.$e);
		}
	}
	@closedir($dir_handle);
}

if (!isset($updraftplus)) $updraftplus = new UpdraftPlus();

if (!$updraftplus->memory_check(192)) {
// Experience appears to show that the memory limit is only likely to be hit (unless it is very low) by single files that are larger than available memory (when compressed)
	@ini_set('memory_limit', '192M'); //up the memory limit for large backup files
}

if (!class_exists('UpdraftPlus_Options')) require_once(UPDRAFTPLUS_DIR.'/options.php');

set_include_path(get_include_path().PATH_SEPARATOR.UPDRAFTPLUS_DIR.'/includes/phpseclib');

class UpdraftPlus {

	var $version;

	var $plugin_title = 'UpdraftPlus Backup/Restore';

	// Choices will be shown in the admin menu in the order used here
	var $backup_methods = array (
		"s3" => "Amazon S3",
		"dropbox" => "Dropbox",
		"googledrive" => "Google Drive",
		"ftp" => "FTP",
		'sftp' => 'SFTP',
		'webdav' => 'WebDAV',
		"email" => "Email"
	);

	var $dbhandle;
	var $dbhandle_isgz;
	var $errors = array();
	var $nonce;
	var $logfile_name = "";
	var $logfile_handle = false;
	var $backup_time;

	var $opened_log_time;
	var $backup_dir;

	var $jobdata;

	// Used to schedule resumption attempts beyond the tenth, if needed
	var $current_resumption;
	var $newresumption_scheduled = false;

	var $zipfiles_added;
	var $zipfiles_existingfiles;
	var $zipfiles_dirbatched;
	var $zipfiles_batched;
	var $zipfiles_lastwritetime;

	var $zip_preferpcl = false;

	function __construct() {

		// Initialisation actions - takes place on plugin load

		if ($fp = fopen( __FILE__, 'r')) {
			$file_data = fread( $fp, 1024 );
			if (preg_match("/Version: ([\d\.]+)(\r|\n)/", $file_data, $matches)) {
				$this->version = $matches[1];
			}
			fclose($fp);
		}

		# Create admin page
		add_action('admin_init', array($this, 'admin_init'));
		add_action('updraft_backup', array($this,'backup_files'));
		add_action('updraft_backup_database', array($this,'backup_database'));
		# backup_all is used by the manual "Backup Now" button
		add_action('updraft_backup_all', array($this,'backup_all'));
		# this is our runs-after-backup event, whose purpose is to see if it succeeded or failed, and resume/mom-up etc.
		add_action('updraft_backup_resume', array($this,'backup_resume'), 10, 3);
		add_action('wp_ajax_updraft_download_backup', array($this, 'updraft_download_backup'));
		add_action('wp_ajax_updraft_ajax', array($this, 'updraft_ajax_handler'));
		# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
		add_filter('cron_schedules', array($this,'modify_cron_schedules'));
		add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);
		add_action('init', array($this, 'handle_url_actions'));
		add_action('plugins_loaded', array($this, 'load_translations'));

		if (defined('UPDRAFTPLUS_PREFERPCLZIP') && UPDRAFTPLUS_PREFERPCLZIP == true) { $this->zip_preferpcl = true; }

	}

	function load_translations() {
		// Tell WordPress where to find the translations
		load_plugin_textdomain('updraftplus', false, basename(dirname(__FILE__)).'/languages');
	}

	// Handle actions passed on to method plugins; e.g. Google OAuth 2.0 - ?page=updraftplus&action=updraftmethod-googledrive-auth
	// Also handle action=downloadlog
	function handle_url_actions() {
		// First, basic security check: must be an admin page, with ability to manage options, with the right parameters
		if ( UpdraftPlus_Options::user_can_manage() && isset( $_GET['page'] ) && $_GET['page'] == 'updraftplus' && isset($_GET['action']) ) {
			if (preg_match("/^updraftmethod-([a-z]+)-([a-z]+)$/", $_GET['action'], $matches) && file_exists(UPDRAFTPLUS_DIR.'/methods/'.$matches[1].'.php')) {
				$method = $matches[1];
				require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
				$call_class = "UpdraftPlus_BackupModule_".$method;
				$call_method = "action_".$matches[2];
				if (method_exists($call_class, $call_method)) call_user_func(array($call_class,$call_method));
			} elseif ($_GET['action'] == 'downloadlog' && isset($_GET['updraftplus_backup_nonce']) && preg_match("/^[0-9a-f]{12}$/",$_GET['updraftplus_backup_nonce'])) {
				$updraft_dir = $this->backups_dir_location();
				$log_file = $updraft_dir.'/log.'.$_GET['updraftplus_backup_nonce'].'.txt';
				if (is_readable($log_file)) {
					header('Content-type: text/plain');
					readfile($log_file);
					exit;
				} else {
					add_action('admin_notices', array($this,'show_admin_warning_unreadablelog') );
				}
			}
		}
	}

	// Cleans up temporary files found in the updraft directory
	function clean_temporary_files() {
		$updraft_dir = $this->backups_dir_location();
		if ($handle = opendir($updraft_dir)) {
			$now_time=time();
			while (false !== ($entry = readdir($handle))) {
				// The latter match is for files created internally by zipArchive::addFile
				if ((preg_match('/\.tmp(\.gz)?$/i', $entry) || preg_match('/\.zip\.tmp\.([A-Za-z0-9]){6}?$/i', $entry)) && is_file($updraft_dir.'/'.$entry) && $now_time-filemtime($updraft_dir.'/'.$entry)>86400) {
					$this->log("Deleting old temporary file: $entry");
					@unlink($updraft_dir.'/'.$entry);
				}
			}
			@closedir($handle);
		}
	}

	# Adds the settings link under the plugin on the plugin screen.
	function plugin_action_links($links, $file) {
		if ($file == plugin_basename(__FILE__)){
			$settings_link = '<a href="'.site_url().'/wp-admin/options-general.php?page=updraftplus">'.__("Settings", "updraftplus").'</a>';
			array_unshift($links, $settings_link);
// 			$settings_link = '<a href="http://david.dw-perspective.org.uk/donate">'.__("Donate","UpdraftPlus").'</a>';
// 			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://updraftplus.com">'.__("Add-Ons / Pro Support","updraftplus").'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	function backup_time_nonce() {
		$this->backup_time = time();
		$nonce = substr(md5(time().rand()), 20);
		$this->nonce = $nonce;
	}

	function logfile_open($nonce) {

		//set log file name and open log file
		$updraft_dir = $this->backups_dir_location();
		$this->logfile_name =  $updraft_dir. "/log.$nonce.txt";

		// Use append mode in case it already exists
		$this->logfile_handle = fopen($this->logfile_name, 'a');
		$this->opened_log_time = microtime(true);
		$this->log('Opened log file at time: '.date('r'));
		global $wp_version;
		$logline = "UpdraftPlus: ".$this->version." WP: ".$wp_version." PHP: ".phpversion()." (".php_uname().") max_execution_time: ".@ini_get("max_execution_time")." memory_limit: ".ini_get('memory_limit')." ZipArchive::addFile : ";

		// method_exists causes some faulty PHP installations to segfault, leading to support requests
		if (version_compare(phpversion(), '5.2.0', '>=') && extension_loaded('zip')) {
			$logline .= 'Y';
		} else {
			$logline .= (method_exists('ZipArchive', 'addFile')) ? "Y" : "N";
		}
		$this->log($logline);
		$disk_free_space = @disk_free_space($updraft_dir);
		$this->log("Free space on disk containing Updraft's temporary directory: ".round($disk_free_space/1048576,1)." Mb");
	}

	# Logs the given line, adding (relative) time stamp and newline
	function log($line) {
		if ($this->logfile_handle) fwrite($this->logfile_handle, sprintf("%08.03f", round(microtime(true)-$this->opened_log_time, 3))." (".$this->current_resumption.") $line\n");
		if ('download' == $this->jobdata_get('job_type')) {
			// Download messages are keyed on the job (since they could be running several), and transient
			// The values of the POST array were checked before
			set_transient('ud_dlmess_'.$_POST['timestamp'].'_'.$_POST['type'], $line." (".date('M d H:i:s').")", 3600);
		} else {
			UpdraftPlus_Options::update_updraft_option('updraft_lastmessage', $line." (".date('M d H:i:s').")");
		}
		if (defined('UPDRAFTPLUS_CONSOLELOG')) print $line."\n";
	}

	// This function is used by cloud methods to provide standardised logging, but more importantly to help us detect that meaningful activity took place during a resumption run, so that we can schedule further resumptions if it is worthwhile
	function record_uploaded_chunk($percent, $extra, $file_path = false) {

		// Touch the original file, which helps prevent overlapping runs
		if ($file_path) touch($file_path);

		// Log it
		$service = $this->jobdata_get('service');
		$log = ucfirst($service)." chunked upload: $percent % uploaded";
		if ($extra) $log .= " ($extra)";
		$this->log($log);
		// If we are on an 'overtime' resumption run, and we are still meainingfully uploading, then schedule a new resumption
		// Our definition of meaningful is that we must maintain an overall average of at least 1% per run, after allowing 9 runs for everything else to get going
		// i.e. Max 109 runs = 545 minutes = 9 hrs 05
		// If they get 2 minutes on each run, and the file is 1Gb, then that equals 10.2Mb/120s = minimum 87Kb/s upload speed required

		if ($this->current_resumption >= 9 && $this->newresumption_scheduled == false && $percent > ( $this->current_resumption - 9)) {
			$this->something_useful_happened();
		}
	}

	function minimum_resume_interval() {
		// Bringing this down brings in more risk of undetectable overlaps than is worth it
		return 300;
// 		$inter = (int)ini_get('max_execution_time');
// 		if (!$inter || $inter>300) $inter = 300;
// 		if ($inter<35) $inter=35;
// 		return $inter;
	}

	// This important function returns a list of file entities that can potentially be backed up (subject to users settings), and optionally further meta-data about them
	function get_backupable_file_entities($include_others = true, $full_info = false) {

		$wp_upload_dir = wp_upload_dir();

		if ($full_info) {
			$arr = array(
				'plugins' => array('path' => WP_PLUGIN_DIR, 'description' => __('Plugins','updraftplus')),
				'themes' => array('path' => WP_CONTENT_DIR.'/themes', 'description' => __('Themes','updraftplus')),
				'uploads' => array('path' => $wp_upload_dir['basedir'], 'description' => __('Uploads','updraftplus'))
			);
		} else {
			$arr = array(
				'plugins' => WP_PLUGIN_DIR,
				'themes' => WP_CONTENT_DIR.'/themes',
				'uploads' => $wp_upload_dir['basedir']
			);
		}

		$arr = apply_filters('updraft_backupable_file_entities', $arr, $full_info);

		if ($include_others) {
			if ($full_info) {
				$arr['others'] = array('path' => WP_CONTENT_DIR, 'description' => __('Others','updraftplus'));
			} else {
				$arr['others'] = WP_CONTENT_DIR;
			}
		}

		return $arr;

	}

	function backup_resume($resumption_no, $bnonce) {

		@ignore_user_abort(true);
		// This is scheduled for 5 minutes after a backup job starts

		// Restore state
		$resumption_extralog = '';
		if ($resumption_no > 0) {
			$this->nonce = $bnonce;
			$this->backup_time = $this->jobdata_get('backup_time');
			$this->logfile_open($bnonce);

			$time_passed = $this->jobdata_get('run_times');
			if (!is_array($time_passed)) $time_passed = array();

			$prev_resumption = $resumption_no - 1;
			if (isset($time_passed[$prev_resumption])) $resumption_extralog = ", previous check-in=".round($time_passed[$prev_resumption], 1)."s";
		}


		$btime = $this->backup_time;

		$job_type = $this->jobdata_get('job_type');

		$updraft_dir = $this->backups_dir_location();

		$time_ago = time()-$btime;

		$this->current_resumption = $resumption_no;
		$this->log("Backup run: resumption=$resumption_no, nonce=$bnonce, begun at=$btime (${time_ago}s ago), job type: $job_type".$resumption_extralog);

		if ($resumption_no == 8) {
			$timings_string = "";
			$run_times_known=0;
			for ($i=0; $i<=7; $i++) {
				$timings_string .= "$i:";
				if (isset($time_passed[$i])) {
					$timings_string .=  round($time_passed[$i], 1).' ';
					$run_times_known++;
				} else {
					$timings_string .=  '? ';
				}
			}
			$this->log("Time passed on previous resumptions: $passed");
			// TODO: If there's sufficient data and an upper limit clearly lower than our present resume_interval, then decrease the resume_interval
		}

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) $resume_interval = $this->minimum_resume_interval();

		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no+1;
		if ($next_resumption < 10) {
			$schedule_for = time()+$resume_interval;
			$this->log("Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for) in case this run gets aborted");
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $bnonce));
			$this->newresumption_scheduled = $schedule_for;
		} else {
			$this->log(sprintf('The current run is attempt number %d - will not schedule a further attempt until we see something useful happening', 10));
		}

		// Sanity check
		if (empty($this->backup_time)) {
			$this->log('Abort this run: the backup_time parameter appears to be empty');
			return false;
		}

		// This should be always called; if there were no files in this run, it returns us an empty array
		$backup_array = $this->resumable_backup_of_files($resumption_no);

		// This save, if there was something, is then immediately picked up again
		if (is_array($backup_array)) {
			$this->log('Saving backup status to database (elements: '.count($backup_array).")");
			$this->save_backup_history($backup_array);
		}

		// Switch of variable name is purely vestigial
		$our_files = $backup_array;
		if (!is_array($our_files)) $our_files = array();

		$undone_files = array();

		$backup_database = $this->jobdata_get('backup_database');

		// The transient is read and written below (instead of using the existing variable) so that we can copy-and-paste this part as needed.
		if ($backup_database == "begun" || $backup_database == 'finished' || $backup_database == 'encrypted') {
			if ($backup_database == "begun") {
				if ($resumption_no > 0) {
					$this->log("Resuming creation of database dump");
				} else {
					$this->log("Beginning creation of database dump");
				}
			} elseif ($backup_database == 'encrypted') {
				$this->log("Database dump: Creation and encryption were completed already");
			} else {
				$this->log("Database dump: Creation was completed already");
			}

			$db_backup = $this->backup_db($backup_database);

			if(is_array($our_files) && is_string($db_backup)) {
				$our_files['db'] = $db_backup;
			}

			if ($backup_database != 'encrypted') $this->jobdata_set("backup_database", 'finished');
		} else {
			$this->log("Unrecognised data when trying to ascertain if the database was backed up ($backup_database)");
		}

		// Save this to our history so we can track backups for the retain feature
		$this->log("Saving backup history");
		// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
		$this->save_backup_history($our_files);

		// Potentially encrypt the database if it is not already
		if (isset($our_files['db']) && !preg_match("/\.crypt$/", $our_files['db'])) {
			$our_files['db'] = $this->encrypt_file($our_files['db']);
			// No need to save backup history now, as it will happen in a few lines time
			if (preg_match("/\.crypt$/", $our_files['db'])) $this->jobdata_set("backup_database", 'encrypted');
		}

		if (isset($our_files['db']) && file_exists($updraft_dir.'/'.$our_files['db'])) {
			$our_files['db-size'] = filesize($updraft_dir.'/'.$our_files['db']);
			$this->save_backup_history($our_files);
		}

		$backupable_entities = $this->get_backupable_file_entities(true);

		foreach ($our_files as $key => $file) {

			// Only continue if the stored info was about a dump
			if (!isset($backupable_entities[$key]) && $key != 'db') continue;

			$hash = md5($file);
			$fullpath = $this->backups_dir_location().'/'.$file;
			if ($this->jobdata_get("uploaded_$hash") === "yes") {
				$this->log("$file: $key: This file has already been successfully uploaded");
			} elseif (is_file($fullpath)) {
				$this->log("$file: $key: This file has not yet been successfully uploaded: will queue");
				$undone_files[$key] = $file;
			} else {
				$this->log("$file: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem");
				$this->uploaded_file($file);
			}
		}

		if (count($undone_files) == 0) {
			$this->log("There were no more files that needed uploading; backup job is complete");
			// No email, as the user probably already got one if something else completed the run
			$this->backup_finish($next_resumption, true, false, $resumption_no);
			return;
		}

		$this->log("Requesting backup of the files that were not successfully uploaded");
		$this->cloud_backup($undone_files);

		$this->log("Resume backup ($bnonce, $resumption_no): finish run");
		if (is_array($our_files)) $this->save_last_backup($our_files);
		$this->backup_finish($next_resumption, true, true, $resumption_no);

	}

	function backup_all() {
		$this->boot_backup(true,true);
	}
	
	function backup_files() {
		# Note that the "false" for database gets over-ridden automatically if they turn out to have the same schedules
		$this->boot_backup(true,false);
	}
	
	function backup_database() {
		# Note that nothing will happen if the file backup had the same schedule
		$this->boot_backup(false,true);
	}

	// This works with any amount of settings, but we provide also a jobdata_set for efficiency as normally there's only one setting
	function jobdata_set_multi() {
		if (!is_array($this->jobdata)) $this->jobdata = array();

		$args = func_num_args();

		for ($i=1; $i<=$args/2; $i++) {
			$key = func_get_arg($i*2-2);
			$value = func_get_arg($i*2-1);
			$this->jobdata[$key] = $value;
		}
		if (!empty($this->nonce)) set_transient("updraft_jobdata_".$this->nonce, $this->jobdata, UPDRAFT_TRANSTIME);
	}

	function jobdata_set($key, $value) {
			if (is_array($this->jobdata)) {
				$this->jobdata[$key] = $value;
			} else {
				$this->jobdata = get_transient("updraft_jobdata_".$this->nonce);
				if (!is_array($this->jobdata)) $this->jobdata = array($key => $value);
			}
			set_transient("updraft_jobdata_".$this->nonce, $this->jobdata, 14400);
	}


	function jobdata_get($key) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_transient("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) return false;
		}
		return (isset($this->jobdata[$key])) ? $this->jobdata[$key] : false;
	}

	// This uses a transient; its only purpose is to indicate *total* completion; there is no actual danger, just wasted time, in resuming when it was not needed. So the transient just helps save resources.
	function resumable_backup_of_files($resumption_no) {
		//backup directories and return a numerically indexed array of file paths to the backup files
		$transient_status = $this->jobdata_get('backup_files');
		if ($transient_status == 'finished') {
			$this->log("Creation of backups of directories: already finished");
		} elseif ($transient_status == "begun") {
			if ($resumption_no>0) {
				$this->log("Creation of backups of directories: had begun; will resume");
			} else {
				$this->log("Creation of backups of directories: beginning");
			}
		} else {
			# This is not necessarily a backup run which is meant to contain files at all
			$this->log("This backup run is not intended for files - skipping");
			return array();
		}
		// We want this array, even if already finished
		$backup_array = $this->backup_dirs($transient_status);
		// This can get over-written later
		$this->jobdata_set('backup_files', 'finished');
		return $backup_array;
	}

	// This procedure initiates a backup run
	function boot_backup($backup_files, $backup_database) {

		@ignore_user_abort(true);

		//generate backup information
		$this->backup_time_nonce();
		$this->logfile_open($this->nonce);

		if (!is_file($this->logfile_name)) {
			$this->log('Failed to open log file ('.$this->logfile_name.') - you need to check your UpdraftPlus settings (your chosen directory for creating files in is not writable, or you ran out of disk space). Backup aborted.');
			$this->error(__('Could not create files in the backup directory. Backup aborted - check your UpdraftPlus settings.','updraftplus'));
			return false;
		}

		// Some house-cleaning
		$this->clean_temporary_files();

		// Log some information that may be helpful
		$this->log("Tasks: Backup files: $backup_files (schedule: ".UpdraftPlus_Options::get_updraft_option('updraft_interval', 'unset').") Backup DB: $backup_database (schedule: ".UpdraftPlus_Options::get_updraft_option('updraft_interval_database', 'unset').")");

		# If the files and database schedules are the same, and if this the file one, then we rope in database too.
		# On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.
		if (UpdraftPlus_Options::get_updraft_option('updraft_interval') == UpdraftPlus_Options::get_updraft_option('updraft_interval_database') || UpdraftPlus_Options::get_updraft_option('updraft_interval_database', 'xyz') == 'xyz' ) {
			$backup_database = ($backup_files == true) ? true : false;
		}

		$this->log("Processed schedules. Tasks now: Backup files: $backup_files Backup DB: $backup_database");

		# If nothing to be done, then just finish
		if (!$backup_files && !$backup_database) {
			$this->backup_finish(1, false, false, 0);
			return;
		}

		$resume_interval = $this->minimum_resume_interval();
// 		$max_execution_time = ini_get('max_execution_time');
// 		if ($max_execution_time >0 && $max_execution_time<300 && $resume_interval< $max_execution_time + 30) $resume_interval = $max_execution_time + 30;

		$initial_jobdata = array(
			'resume_interval', $resume_interval,
			'job_type', 'backup',
			'backup_time', $this->backup_time,
			'service', UpdraftPlus_Options::get_updraft_option('updraft_service')
		);

		// Save what *should* be done, to make it resumable from this point on
		if ($backup_database) array_push($initial_jobdata, 'backup_database', 'begun');
		if ($backup_files) array_push($initial_jobdata, 'backup_files', 'begun');

		// Use of jobdata_set_multi saves around 200ms
		call_user_func_array(array($this, 'jobdata_set_multi'), $initial_jobdata);

		// Everything is now set up; now go
		$this->backup_resume(0, $this->nonce);

	}

	// Encrypts the file if the option is set; returns the basename of the file (according to whether it was encrypted or nto)
	function encrypt_file($file) {
		$encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
		if (strlen($encryption) > 0) {
			$this->log("$file: applying encryption");
			$encryption_error = 0;
			$microstart = microtime(true);
			require_once(UPDRAFTPLUS_DIR.'/includes/phpseclib/Crypt/Rijndael.php');
			$rijndael = new Crypt_Rijndael();
			$rijndael->setKey($encryption);
			$updraft_dir = $this->backups_dir_location();
			$file_size = @filesize($updraft_dir.'/'.$file)/1024;
			if (false === file_put_contents($updraft_dir.'/'.$file.'.crypt' , $rijndael->encrypt(file_get_contents($updraft_dir.'/'.$file)))) {$encryption_error = 1;}
			if (0 == $encryption_error) {
				$time_taken = max(0.000001, microtime(true)-$microstart);
				$this->log("$file: encryption successful: ".round($file_size,1)."Kb in ".round($time_taken,1)."s (".round($file_size/$time_taken, 1)."Kb/s)");
				# Delete unencrypted file
				@unlink($updraft_dir.'/'.$file);
				return basename($file.'.crypt');
			} else {
				$this->log("Encryption error occurred when encrypting database. Encryption aborted.");
				$this->error(__("Encryption error occurred when encrypting database. Encryption aborted.",'updraftplus'));
				return basename($file);
			}
		} else {
			return basename($file);
		}
	}

	function backup_finish($cancel_event, $clear_nonce_transient, $allow_email, $resumption_no) {

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if (empty($this->errors)) {
			if ($clear_nonce_transient) {
				$this->log("There were no errors in the uploads, so the 'resume' event is being unscheduled");
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event, $this->nonce));
				// TODO: Delete the job transient (is presently useful for debugging, and only lasts 4 hours)
			}
		} else {
			$this->log("There were errors in the uploads, so the 'resume' event is remaining scheduled");
		}

		// Send the results email if appropriate, which means:
		// - The caller allowed it (which is not the case in an 'empty' run)
		// - And: An email address was set (which must be so in email mode)
		// And one of:
		// - Debug mode
		// - There were no errors (which means we completed and so this is the final run - time for the final report)
		// - It was the tenth resumption; everything failed

		$send_an_email = false;

		// Make sure that the final status is shown
		if (empty($this->errors)) {
			$send_an_email = true;
			$final_message = "The backup apparently succeeded and is now complete";
		} elseif ($this->newresumption_scheduled == false) {
			$send_an_email = true;
			$final_message = "The backup attempt has finished, apparently unsuccessfully";
		} else {
			// There are errors, but a resumption will be attempted
			$final_message = "The backup has not finished; a resumption is scheduled within 5 minutes";
		}

		// Now over-ride the decision to send an email, if needed
		if (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
			$send_an_email = true;
			$this->log("An email has been scheduled for this job, because we are in debug mode");
		}
		// If there's no email address, or the set was empty, that is the final over-ride: don't send
		if (!$allow_email) {
			$send_an_email = false;
			$this->log("No email will be sent - this backup set was empty.");
		} elseif (UpdraftPlus_Options::get_updraft_option('updraft_email') == '') {
			$send_an_email = false;
			$this->log("No email will/can be sent - the user has not configured an email address.");
		}

		if ($send_an_email) $this->send_results_email($final_message);

		$this->log($final_message);

		@fclose($this->logfile_handle);

		// Don't delete the log file now; delete it upon rotation
 		//if (!UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) @unlink($this->logfile_name);

	}

	function send_results_email($final_message) {

		$debug_mode = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');

		$sendmail_to = UpdraftPlus_Options::get_updraft_option('updraft_email');

		$backup_files = $this->jobdata_get('backup_files');
		$backup_db = $this->jobdata_get("backup_database");

		if ($backup_files == 'finished' && ( $backup_db == 'finished' || $backup_db == 'encrypted' ) ) {
			$backup_contains = "Files and database";
		} elseif ($backup_files == 'finished') {
			$backup_contains = ($backup_db == "begun") ? "Files (database backup has not completed)" : "Files only (database was not part of this particular schedule)";
		} elseif ($backup_db == 'finished' || $backup_db == 'encrypted') {
			$backup_contains = ($backup_files == "begun") ? "Database (files backup has not completed)" : "Database only (files were not part of this particular schedule)";
		} else {
			$backup_contains = "Unknown/unexpected error - please raise a support request";
		}

		$this->log("Sending email ('$backup_contains') report to: ".substr($sendmail_to, 0, 5)."...");

		$append_log = ($debug_mode && $this->logfile_name != "") ? "\r\nLog contents:\r\n".file_get_contents($this->logfile_name) : "" ;

		wp_mail($sendmail_to,__('Backed up', 'updraftplus').': '.get_bloginfo('name').' (UpdraftPlus '.$this->version.') '.date('Y-m-d H:i',time()),'Site: '.site_url()."\r\nUpdraftPlus: ".__('WordPress backup is complete','updraftplus').".\r\n".__('Backup contains','updraftplus').': '.$backup_contains."\r\n".__('Latest status', 'updraftplus').": $final_message\r\n\r\n".$this->wordshell_random_advert(0)."\r\n".$append_log);

	}

	function save_last_backup($backup_array) {
		$success = (empty($this->errors)) ? 1 : 0;

		$last_backup = array('backup_time'=>$this->backup_time, 'backup_array'=>$backup_array, 'success'=>$success, 'errors'=>$this->errors, 'backup_nonce' => $this->nonce);

		UpdraftPlus_Options::update_updraft_option('updraft_last_backup', $last_backup);
	}

	// This should be called whenever a file is successfully uploaded
	function uploaded_file($file, $id = false) {
		$hash = md5($file);
		$this->log("Recording as successfully uploaded: $file ($hash)");
		$this->jobdata_set("uploaded_$hash", "yes");
		if ($id) {
			$ids = UpdraftPlus_Options::get_updraft_option('updraft_file_ids', array() );
			$ids[$file] = $id;
			UpdraftPlus_Options::update_updraft_option('updraft_file_ids',$ids);
			$this->log("Stored file<->id correlation in database ($file <-> $id)");
		}
		// Delete local files immediately if the option is set
		// Where we are only backing up locally, only the "prune" function should do deleting
		if ($this->jobdata_get('service') != '' && $this->jobdata_get('service') != 'none') $this->delete_local($file);
	}

	// Dispatch to the relevant function
	function cloud_backup($backup_array) {

		$service = $this->jobdata_get('service');
		$this->log("Cloud backup selection: ".$service);
		@set_time_limit(900);

		$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
		if (file_exists($method_include)) require_once($method_include);

		if ($service == "none") {
			$this->log("No remote despatch: user chose no remote backup service");
		} else {
			$this->log("Beginning dispatch of backup to remote");
		}

		$objname = "UpdraftPlus_BackupModule_${service}";
		if (method_exists($objname, "backup")) {
			// New style - external, allowing more plugability
			$remote_obj = new $objname;
			$remote_obj->backup($backup_array);
		} elseif ($service == "none") {
			$this->prune_retained_backups("none", null, null);
		}
	}

	function prune_file($service, $dofile, $method_object = null, $object_passback = null ) {
		$this->log("Delete this file: $dofile, service=$service");
		$fullpath = $this->backups_dir_location().'/'.$dofile;
		// delete it if it's locally available
		if (file_exists($fullpath)) {
			$this->log("Deleting local copy ($fullpath)");
			@unlink($fullpath);
		}

		// Despatch to the particular method's deletion routine
		if (!is_null($method_object)) $method_object->delete($dofile, $object_passback);
	}

	// Carries out retain behaviour. Pass in a valid S3 or FTP object and path if relevant.
	function prune_retained_backups($service, $backup_method_object = null, $backup_passback = null) {

		// If they turned off deletion on local backups, then there is nothing to do
		if (UpdraftPlus_Options::get_updraft_option('updraft_delete_local') == 0 && $service == 'none') {
			$this->log("Prune old backups from local store: nothing to do, since the user disabled local deletion and we are using local backups");
			return;
		}

		$this->log("Retain: beginning examination of existing backup sets");

		// Number of backups to retain - files
		$updraft_retain = UpdraftPlus_Options::get_updraft_option('updraft_retain', 1);
		$updraft_retain = (is_numeric($updraft_retain)) ? $updraft_retain : 1;
		$this->log("Retain files: user setting: number to retain = $updraft_retain");

		// Number of backups to retain - db
		$updraft_retain_db = UpdraftPlus_Options::get_updraft_option('updraft_retain_db', $updraft_retain);
		$updraft_retain_db = (is_numeric($updraft_retain_db)) ? $updraft_retain_db : 1;
		$this->log("Retain db: user setting: number to retain = $updraft_retain_db");

		// Returns an array, most recent first, of backup sets
		$backup_history = $this->get_backup_history();
		$db_backups_found = 0;
		$file_backups_found = 0;
		$this->log("Number of backup sets in history: ".count($backup_history));

		$backupable_entities = $this->get_backupable_file_entities(true);

		foreach ($backup_history as $backup_datestamp => $backup_to_examine) {
			// $backup_to_examine is an array of file names, keyed on db/plugins/themes/uploads
			// The new backup_history array is saved afterwards, so remember to unset the ones that are to be deleted
			$this->log("Examining backup set with datestamp: $backup_datestamp");

			if (isset($backup_to_examine['db'])) {
				$db_backups_found++;
				$this->log("$backup_datestamp: this set includes a database (".$backup_to_examine['db']."); db count is now $db_backups_found");
				if ($db_backups_found > $updraft_retain_db) {
					$this->log("$backup_datestamp: over retain limit ($updraft_retain_db); will delete this database");
					$dofile = $backup_to_examine['db'];
					if (!empty($dofile)) $this->prune_file($service, $dofile, $backup_method_object, $backup_passback);
					unset($backup_to_examine['db']);
				}
			}

			$contains_files = false;
			foreach ($backupable_entities as $entity => $info) {
				if (isset($backup_to_examine[$entity])) {
					$contains_files = true;
					break;
				}
			}

			if ($contains_files) {
				$file_backups_found++;
				$this->log("$backup_datestamp: this set includes files; fileset count is now $file_backups_found");
				if ($file_backups_found > $updraft_retain) {
					$this->log("$backup_datestamp: over retain limit ($updraft_retain); will delete this file set");
					
					foreach ($backupable_entities as $entity => $info) {
						if (!empty($backup_to_examine[$entity])) {
							$this->prune_file($service, $backup_to_examine[$entity], $backup_method_object, $backup_passback);
						}
						unset($backup_to_examine[$entity]);
					}

				}
			}

			// Get new result, post-deletion
			$contains_files = false;
			foreach ($backupable_entities as $entity => $info) {
				if (isset($backup_to_examine[$entity])) {
					$contains_files = true;
					break;
				}
			}

			// Delete backup set completely if empty, o/w just remove DB
			// We search on the four keys which represent data, allowing other keys to be used to track other things
			if (!$contains_files && !isset($backup_to_examine['db']) ) {
				$this->log("$backup_datestamp: this backup set is now empty; will remove from history");
				unset($backup_history[$backup_datestamp]);
				if (isset($backup_to_examine['nonce'])) {
					$fullpath = $this->backups_dir_location().'/log.'.$backup_to_examine['nonce'].'.txt';
					if (is_file($fullpath)) {
						$this->log("$backup_datestamp: deleting log file (log.".$backup_to_examine['nonce'].".txt)");
						@unlink($fullpath);
					} else {
						$this->log("$backup_datestamp: corresponding log file not found - must have already been deleted");
					}
				} else {
					$this->log("$backup_datestamp: no nonce record found in the backup set, so cannot delete any remaining log file");
				}
			} else {
				$this->log("$backup_datestamp: this backup set remains non-empty; will retain in history");
				$backup_history[$backup_datestamp] = $backup_to_examine;
			}
		}
		$this->log("Retain: saving new backup history (sets now: ".count($backup_history).") and finishing retain operation");
		UpdraftPlus_Options::update_updraft_option('updraft_backup_history',$backup_history);
	}

	function delete_local($file) {
		if(UpdraftPlus_Options::get_updraft_option('updraft_delete_local')) {
			$this->log("Deleting local file: $file");
		//need error checking so we don't delete what isn't successfully uploaded?
			$fullpath = $this->backups_dir_location().'/'.$file;
			return unlink($fullpath);
		}
		return true;
	}

	// This function is not needed for backup success, according to the design, but it helps with efficient scheduling
	function reschedule_if_needed() {
		// If nothing is scheduled, then return
		if (empty($this->newresumption_scheduled)) return;
		$time_now = time();
		$time_away = $this->newresumption_scheduled - $time_now;
		// 30 is chosen because it is also used to detect recent activity on files (file mod times)
		if ($time_away >1 && $time_away <= 30) {
			$this->log('The scheduled resumption is within 30 seconds - will reschedule');
			// Push 30 seconds into the future
 			// $this->reschedule(60);
			// Increase interval generally by 30 seconds, on the assumption that our prior estimates were innaccurate (i.e. not just 30 seconds *this* time)
			$this->increase_resume_and_reschedule(30);
		}
	}

	function reschedule($how_far_ahead) {
		// Reschedule - remove presently scheduled event
		$next_resumption = $this->current_resumption + 1;
		wp_clear_scheduled_hook('updraft_backup_resume', array($next_resumption, $this->nonce));
		// Add new event
		if ($how_far_ahead < $this->minimum_resume_interval()) $how_far_ahead=$this->minimum_resume_interval();
		$schedule_for = time() + $how_far_ahead;
		$this->log("Rescheduling resumption $next_resumption: moving to $how_far_ahead seconds from now ($schedule_for)");
		wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $this->nonce));
		$this->newresumption_scheduled = $schedule_for;
	}

	function increase_resume_and_reschedule($howmuch = 120) {
		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval < $this->minimum_resume_interval()) { $resume_interval = $this->minimum_resume_interval(); }
		if (!empty($this->newresumption_scheduled)) $this->reschedule($resume_interval+$howmuch);
		$this->jobdata_set('resume_interval', $resume_interval+$howmuch);
		$this->log("To decrease the likelihood of overlaps, increasing resumption interval to: $resume_interval + $howmuch = ".($resume_interval+$howmuch));
	}

	function create_zip($create_from_dir, $whichone, $create_in_dir, $backup_file_basename) {
		// Note: $create_from_dir can be an array or a string
		@set_time_limit(900);

		if ($whichone != "others") $this->log("Beginning creation of dump of $whichone");

		$full_path = $create_in_dir.'/'.$backup_file_basename.'-'.$whichone.'.zip';
		$time_now = time();

		if (file_exists($full_path)) {
			$this->log("$backup_file_basename-$whichone.zip: this file has already been created");
			$time_mod = (int)@filemtime($full_path);
			if ($time_mod>100 && ($time_now-$time_mod)<30) {
				$this->log("Terminate: the zip $full_path already exists, and was modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=".filesize($full_path)."). This likely means that another UpdraftPlus run is still at work; so we will exit.");
				$this->increase_resume_and_reschedule(120);
				die;
			}
			return basename($full_path);
		}

		// Temporary file, to be able to detect actual completion (upon which, it is renamed)

		// Firstly, make sure that the temporary file is not already being written to - which can happen if a resumption takes place whilst an old run is still active
		$zip_name = $full_path.'.tmp';
		$time_mod = (int)@filemtime($zip_name);
		if (file_exists($zip_name) && $time_mod>100 && ($time_now-$time_mod)<30) {
			$file_size = filesize($zip_name);
			$this->log("Terminate: the temporary file $zip_name already exists, and was modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=$file_size). This likely means that another UpdraftPlus run is still at work; so we will exit.");
			$this->increase_resume_and_reschedule(120);
			die;
		} elseif (file_exists($zip_name)) {
			$this->log("File exists ($zip_name), but was apparently not modified within the last 30 seconds, so we assume that any previous run has now terminated (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).")");
		}

		$microtime_start = microtime(true);
		# The paths in the zip should then begin with '$whichone', having removed WP_CONTENT_DIR from the front
		$zipcode = $this->make_zipfile($create_from_dir, $zip_name);
		if ($zipcode !== true) {
			$this->log("ERROR: Zip failure: Could not create $whichone zip: code=$zipcode");
			$this->error(sprintf(__("Could not create %s zip. Consult the log file for more information.",'updraftplus'),$whichone));
			return false;
		} else {
			rename($full_path.'.tmp', $full_path);
			$timetaken = max(microtime(true)-$microtime_start, 0.000001);
			$kbsize = filesize($full_path)/1024;
			$rate = round($kbsize/$timetaken, 1);
			$this->log("Created $whichone zip - file size is ".round($kbsize,1)." Kb in ".round($timetaken,1)." s ($rate Kb/s)");
		}

		return basename($full_path);
	}

	// For detecting another run, and aborting if one was found
	function check_recent_modification($file) {
		if (file_exists($file)) {
			$time_mod = (int)@filemtime($file);
			$time_now = time();
			if ($time_mod>100 && ($time_now-$time_mod)<30) {
				$this->log("Terminate: the file $file already exists, and was modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=".filesize($file)."). This likely means that another UpdraftPlus run is still at work; so we will exit.");
				$this->increase_resume_and_reschedule(120);
				die;
			}
		}
	}

	// This function is resumable
	function backup_dirs($transient_status) {

		if(!$this->backup_time) $this->backup_time_nonce();

		$updraft_dir = $this->backups_dir_location();
		if(!is_writable($updraft_dir)) {
			$this->log("Backup directory ($updraft_dir) is not writable, or does not exist");
			$this->error(sprintf(__("Backup directory (%s) is not writable, or does not exist.", 'updraftplus'), $updraft_dir));
			return array();
		}

		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',substr(get_bloginfo(), 0, 96));
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if(!$blog_name) $blog_name = 'non_alpha_name';

		$backup_file_basename = 'backup_'.get_date_from_gmt(gmdate('Y-m-d H:i:s', $this->backup_time), 'Y-m-d-Hi').'_'.$blog_name.'_'.$this->nonce;

		$backup_array = array();

		$possible_backups = $this->get_backupable_file_entities(false);

		# Plugins, themes, uploads
		foreach ($possible_backups as $youwhat => $whichdir) {

			if (UpdraftPlus_Options::get_updraft_option("updraft_include_$youwhat", true)) {

				$zip_file = $updraft_dir.'/'.$backup_file_basename.'-'.$youwhat.'.zip';

				$this->check_recent_modification($zip_file);

				if ($transient_status == 'finished') {
					$backup_array[$youwhat] = $backup_file_basename.'-'.$youwhat.'.zip';
					if (file_exists($zip_file)) $backup_array[$youwhat.'-size'] = filesize($zip_file);
				} else {
					$created = $this->create_zip($whichdir, $youwhat, $updraft_dir, $backup_file_basename);
					if ($created) {
						$backup_array[$youwhat] = $created;
						$backup_array[$youwhat.'-size'] = filesize($updraft_dir.'/'.$created);
					}
				}
			} else {
				$this->log("No backup of $youwhat: excluded by user's options");
			}
		}

		# Others - needs special/separate handling, since its purpose is to mop up everything else
		if (UpdraftPlus_Options::get_updraft_option('updraft_include_others', true)) {

				$zip_file = $updraft_dir.'/'.$backup_file_basename.'-others.zip';
				$this->check_recent_modification($zip_file);

			if ($transient_status == 'finished') {
				$backup_array['others'] = $backup_file_basename.'-others.zip';
				if (file_exists($zip_file)) $backup_array['others-size'] = filesize($zip_file);
			} else {
				$this->log("Beginning backup of other directories found in the content directory");

				// http://www.phpconcept.net/pclzip/user-guide/53
				/* First parameter to create is:
					An array of filenames or dirnames,
					or
					A string containing the filename or a dirname,
					or
					A string containing a list of filename or dirname separated by a comma.
				*/

				# Initialise
				$other_dirlist = array(); 

				$others_skip = preg_split("/,/",UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude', UPDRAFT_DEFAULT_OTHERS_EXCLUDE));
				# Make the values into the keys
				$others_skip = array_flip($others_skip);

				$this->log('Looking for candidates to back up in: '.WP_CONTENT_DIR);
				if ($handle = opendir(WP_CONTENT_DIR)) {
				
					$possible_backups_dirs = array_flip($possible_backups);
				
					while (false !== ($entry = readdir($handle))) {
						$candidate = WP_CONTENT_DIR.'/'.$entry;
						if ($entry != "." && $entry != "..") {
							if (isset($possible_backups_dirs[$candidate])) {
								$this->log("others: $entry: skipping: this is the ".$possible_backups_dirs[$candidate]." directory");
							} elseif ($candidate == $updraft_dir) {
								$this->log("others: $entry: skipping: this is the updraft directory");
							} elseif (isset($others_skip[$entry])) {
								$this->log("others: $entry: skipping: excluded by options");
							} else {
								$this->log("others: $entry: adding to list");
								array_push($other_dirlist, $candidate);
							}
						}
					}
					@closedir($handle);
				} else {
					$this->log('ERROR: Could not read the content directory: '.WP_CONTENT_DIR);
					$this->error(__('Could not read the content directory', 'updraftplus').': '.WP_CONTENT_DIR);
				}

				if (count($other_dirlist)>0) {
					$created = $this->create_zip($other_dirlist, 'others', $updraft_dir, $backup_file_basename);
					if ($created) {
						$backup_array['others'] = $created;
						$backup_array['others-size'] = filesize($updraft_dir.'/'.$created);
					}
				} else {
					$this->log("No backup of other directories: there was nothing found to back up");
				}
			# If we are not already finished
			}
		} else {
			$this->log("No backup of other directories: excluded by user's options");
		}
		return $backup_array;
	}

	function save_backup_history($backup_array) {
		if(is_array($backup_array)) {
			$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
			$backup_history = (is_array($backup_history)) ? $backup_history : array();
			$backup_array['nonce'] = $this->nonce;
			$backup_array['service'] = $this->jobdata_get('service');
			$backup_history[$this->backup_time] = $backup_array;
			UpdraftPlus_Options::update_updraft_option('updraft_backup_history', $backup_history);
		} else {
			$this->log('Could not save backup history because we have no backup array. Backup probably failed.');
			$this->error(__('Could not save backup history because we have no backup array. Backup probably failed.','updraftplus'));
		}
	}
	
	function get_backup_history() {
		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		// In fact, it looks like the line below actually *introduces* a race condition
		//by doing a raw DB query to get the most up-to-date data from this option we slightly narrow the window for the multiple-cron race condition
// 		global $wpdb;
// 		$backup_history = @unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value from $wpdb->options WHERE option_name='updraft_backup_history'")));
		if(is_array($backup_history)) {
			krsort($backup_history); //reverse sort so earliest backup is last on the array. Then we can array_pop.
		} else {
			$backup_history = array();
		}
		return $backup_history;
	}

	// Open a file, store its filehandle
	function backup_db_open($file, $allow_gz = true) {
		if (function_exists('gzopen') && $allow_gz == true) {
			$this->dbhandle = @gzopen($file, 'w');
			$this->dbhandle_isgz = true;
		} else {
			$this->dbhandle = @fopen($file, 'w');
			$this->dbhandle_isgz = false;
		}
		if(!$this->dbhandle) {
			$this->log("ERROR: $file: Could not open the backup file for writing");
			$this->error("$file: ".__("Could not open the backup file for writing",'updraftplus'));
		}
	}

	function backup_db_header() {

		//Begin new backup of MySql
		$this->stow("# " . 'WordPress MySQL database backup' . "\n");
		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$this->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
			$this->stow("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
			$this->stow("/*!40101 SET NAMES " . DB_CHARSET . " */;\n");
		}
		$this->stow("/*!40101 SET foreign_key_checks = 0 */;\n");
	}

	/* This function is resumable, using the following method:
	- Each table is written out to ($final_filename).table.tmp
	- When the writing finishes, it is renamed to ($final_filename).table
	- When all tables are finished, they are concatenated into the final file
	*/
	function backup_db($already_done = "begun") {

		// Get the file prefix
		$updraft_dir = $this->backups_dir_location();

		if(!$this->backup_time) $this->backup_time_nonce();
		if (!$this->opened_log_time) $this->logfile_open($this->nonce);

		// Get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', str_replace(' ','_', substr(get_bloginfo(), 0, 96)));
		if (!$blog_name) $blog_name = 'non_alpha_name';

		$file_base = 'backup_'.get_date_from_gmt(gmdate('Y-m-d H:i:s', $this->backup_time), 'Y-m-d-Hi').'_'.$blog_name.'_'.$this->nonce;
		$backup_file_base = $updraft_dir.'/'.$file_base;

		if ('finished' == $already_done) return basename($backup_file_base.'-db.gz');
		if ('encrypted' == $already_done) return basename($backup_file_base.'-db.gz.crypt');

		$total_tables = 0;

		global $table_prefix, $wpdb;

		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);

		if (!is_writable($updraft_dir)) {
			$this->log("The backup directory ($updraft_dir) is not writable.");
			$this->error("$updraft_dir: ".__('The backup directory is not writable.','updraftplus'));
			return false;
		}

		$stitch_files = array();

		foreach ($all_tables as $table) {
			$total_tables++;
			// Increase script execution time-limit to 15 min for every table.
			if ( !@ini_get('safe_mode') || strtolower(@ini_get('safe_mode')) == "off") @set_time_limit(15*60);
			// The table file may already exist if we have produced it on a previous run
			$table_file_prefix = $file_base.'-db-table-'.$table.'.table';
			if (file_exists($updraft_dir.'/'.$table_file_prefix.'.gz')) {
				$this->log("Table $table: corresponding file already exists; moving on");
			} else {
				// Open file, store the handle
				$this->backup_db_open($updraft_dir.'/'.$table_file_prefix.'.tmp.gz', true);
				# === is needed, otherwise 'false' matches (i.e. prefix does not match)
				if ( strpos($table, $table_prefix) === 0 ) {
					// Create the SQL statements
					$this->stow("# --------------------------------------------------------\n");
					$this->stow("# " . sprintf(__('Table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
					$this->stow("# --------------------------------------------------------\n");
					$this->backup_table($table);
				} else {
					$this->stow("# --------------------------------------------------------\n");
					$this->stow("# " . sprintf(__('Skipping non-WP table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
					$this->stow("# --------------------------------------------------------\n");				
				}
				// Close file
				$this->close($this->dbhandle);
				$this->log("Table $table: finishing file (${table_file_prefix}.gz)");
				rename($updraft_dir.'/'.$table_file_prefix.'.tmp.gz', $updraft_dir.'/'.$table_file_prefix.'.gz');
			}
			$stitch_files[] = $table_file_prefix;
		}

		// Race detection - with zip files now being resumable, these can more easily occur, with two running side-by-side
		$backup_final_file_name = $backup_file_base.'-db.gz';
		$time_now = time();
		$time_mod = (int)@filemtime($backup_final_file_name);
		if (file_exists($backup_final_file_name) && $time_mod>100 && ($time_now-$time_mod)<20) {
			$file_size = filesize($backup_final_file_name);
			$this->log("Terminate: the final database file ($backup_final_file_name) exists, and was modified within the last 20 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=$file_size). This likely means that another UpdraftPlus run is at work; so we will exit.");
			$this->increase_resume_and_reschedule(120);
			die;
		} elseif (file_exists($backup_final_file_name)) {
			$this->log("The final database file ($backup_final_file_name) exists, but was apparently not modified within the last 20 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod)."). Thus we assume that another UpdraftPlus terminated; thus we will continue.");
		}

		// Finally, stitch the files together
		$this->backup_db_open($backup_final_file_name, true);
		$this->backup_db_header();

		// We delay the unlinking because if two runs go concurrently and fail to detect each other (should not happen, but there's no harm in assuming the detection failed) then that leads to files missing from the db dump
		$unlink_files = array();

		foreach ($stitch_files as $table_file) {
			$this->log("{$table_file}.gz: adding to final database dump");
			if (!$handle = gzopen($updraft_dir.'/'.$table_file.'.gz', "r")) {
				$this->log("Error: Failed to open database file for reading: ${table_file}.gz");
				$this->error(" Failed to open database file for reading: ${table_file}.gz");
			} else {
				while ($line = gzgets($handle, 2048)) { $this->stow($line); }
				gzclose($handle);
				$unlink_files[] = $updraft_dir.'/'.$table_file.'.gz';
			}
		}

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
			$this->stow("/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
			$this->stow("/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
		}

		$this->log($file_base.'-db.gz: finished writing out complete database file ('.round(filesize($backup_final_file_name)/1024,1).' Kb)');
		$this->close($this->dbhandle);

		foreach ($unlink_files as $unlink_file) @unlink($unlink_file);

		if (count($this->errors)) {
			return false;
		} else {
			# We no longer encrypt here - because the operation can take long, we made it resumable and moved it to the upload loop
			$this->log("Total database tables backed up: $total_tables");
			return basename($backup_file_base.'-db.gz');
		}

	} //wp_db_backup

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;

		$microtime = microtime(true);

		$total_rows = 0;

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			//$this->error(__('Error getting table details','wp-db-backup') . ": $table");
			return false;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Table structure of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if (false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n#\n# " . sprintf(__('Data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n#\n");
		}
		
		// In UpdraftPlus, segment is always 'none'
		if(($segment == 'none') || ($segment >= 0)) {
			$defs = array();
			$integer_fields = array();
			// $table_structure was from "DESCRIBE $table"
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) || (0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) || (0 === strpos(strtolower($struct->Type), 'int')) || (0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$integer_fields[strtolower($struct->Field)] = "1";
				}
			}
			
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = 100;
			} else {
				$row_start = $segment * 100;
				$row_inc = 100;
			}

			do {
				if ( !@ini_get('safe_mode') || strtolower(@ini_get('safe_mode')) == "off") @set_time_limit(15*60);
				$table_data = $wpdb->get_results("SELECT * FROM $table LIMIT {$row_start}, {$row_inc}", ARRAY_A);
				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$total_rows++;
						$values = array();
						foreach ($row as $key => $value) {
							if (isset($integer_fields[strtolower($key)])) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ');');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
 		$this->log("Table $table: Total rows added: $total_rows in ".sprintf("%.02f",max(microtime(true)-$microtime,0.00001))." seconds");

	} // end backup_table()

	function stow($query_line) {
		if ($this->dbhandle_isgz) {
			if(! @gzwrite($this->dbhandle, $query_line)) {
				//$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
			}
		} else {
			if(false === @fwrite($this->dbhandle, $query_line)) {
				//$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
			}
		}
	}

	function close($handle) {
		if ($this->dbhandle_isgz) {
			gzclose($handle);
		} else {
			fclose($handle);
		}
	}

	function error($error) {
		if (count($this->errors) == 0) $this->log("An error condition has occurred for the first time on this run");
		$this->errors[] = $error;
		return true;
	}

	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	function backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	}

	/*END OF WP-DB-BACKUP BLOCK */

	function hourminute($pot) {
		if (preg_match("/^[0-2][0-9]:[0-5][0-9]$/", $pot)) return $pot;
		if ('' == $pot) return date('H:i', time()+300);
		return '00:00';
	}

	/*
	this function is both the backup scheduler and ostensibly a filter callback for saving the option.
	it is called in the register_setting for the updraft_interval, which means when the admin settings 
	are saved it is called.  it returns the actual result from wp_filter_nohtml_kses (a sanitization filter) 
	so the option can be properly saved.
	*/
	function schedule_backup($interval) {
		//clear schedule and add new so we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup');
		switch($interval) {
			case 'every4hours':
			case 'every8hours':
			case 'twicedaily':
			case 'daily':
			case 'weekly':
			case 'fortnightly':
			case 'monthly':
				$first_time = apply_filters('updraftplus_schedule_start_files', time()+30);
				wp_schedule_event($first_time, $interval, 'updraft_backup');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	// Acts as a WordPress options filter
	function googledrive_clientid_checkchange($client_id) {
		if (UpdraftPlus_Options::get_updraft_option('fdrive_token') != '' && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid') != $client_id) {
			require_once(UPDRAFTPLUS_DIR.'/methods/googledrive.php');
			UpdraftPlus_BackupModule_googledrive::gdrive_auth_revoke(true);
		}
		return $client_id;
	}

	function schedule_backup_database($interval) {
		//clear schedule and add new so we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup_database');
		switch($interval) {
			case 'every4hours':
			case 'every8hours':
			case 'twicedaily':
			case 'daily':
			case 'weekly':
			case 'fortnightly':
			case 'monthly':
				$first_time = apply_filters('updraftplus_schedule_start_db', time()+30);
				wp_schedule_event($first_time, $interval, 'updraft_backup_database');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	//wp-cron only has hourly, daily and twicedaily, so we need to add some of our own
	function modify_cron_schedules($schedules) {
		$schedules['weekly'] = array( 'interval' => 604800, 'display' => 'Once Weekly' );
		$schedules['fortnightly'] = array( 'interval' => 1209600, 'display' => 'Once Each Fortnight' );
		$schedules['monthly'] = array( 'interval' => 2592000, 'display' => 'Once Monthly' );
		$schedules['every4hours'] = array( 'interval' => 14400, 'display' => 'Every 4 hours' );
		$schedules['every8hours'] = array( 'interval' => 28800, 'display' => 'Every 8 hours' );
		return $schedules;
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

	function backups_dir_location() {

		if (!empty($this->backup_dir)) return $this->backup_dir;

		$updraft_dir = untrailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir'));
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;

		// Do a test for a relative path
		if ('/' != substr($updraft_dir, 0, 1) && "\\" != substr($updraft_dir, 0, 1) && !preg_match('/^[a-zA-Z]:/', $updraft_dir)) {
			$updraft_dir = ABSPATH.$updraft_dir;
		}

		//if the option isn't set, default it to /backups inside the upload dir
		//check for the existence of the dir and an enumeration preventer.
		// index.php is for a sanity check - make sure that we're not somewhere unexpected
		if((!is_dir($updraft_dir) || !is_file($updraft_dir.'/index.html') || !is_file($updraft_dir.'/.htaccess')) && !is_file($updraft_dir.'/index.php')) {
			@mkdir($updraft_dir, 0775, true);
			@file_put_contents($updraft_dir.'/index.html',"<html><body><a href=\"http://updraftplus.com\">WordPress backups by UpdraftPlus</a></body></html>");
			if (!is_file($updraft_dir.'/.htaccess')) @file_put_contents($updraft_dir.'/.htaccess','deny from all');
		}

		$this->backup_dir = $updraft_dir;

		return $updraft_dir;
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
						$handlesize = recursive_directory_size($path);
						if($handlesize >= 0) { $size += $handlesize; } else { return -1; }
					}
				}
			}
			closedir($handle);
		}
		if ($size > 1073741824) {
			return round($size / 1048576, 1).' Gb';
		} elseif ($size > 1048576) {
			return round($size / 1048576, 1).' Mb';
		} elseif ($size > 1024) {
			return round($size / 1024, 1).' Kb';
		} else {
			return round($size, 1).' b';
		}
	}

	// This function examines inside the updraft directory to see if any new archives have been uploaded. If so, it adds them to the backup set. (No removal of items from the backup set is done)
	function rebuild_backup_history() {

		$known_files = array();
		$known_nonces = array();
		$changes = false;

		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history = array();

		// Accumulate a list of known files
		foreach ($backup_history as $btime => $bdata) {
			foreach ($bdata as $key => $value) {
				// Record which set this file is found in
				if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(zip|gz)$/i', $value, $matches)) {
					$nonce = $matches[2];
					$known_files[$value] = $nonce;
					$known_nonces[$nonce] = $btime;
				}
			}
		}
		
		$updraft_dir = $this->backups_dir_location();

		if (!is_dir($updraft_dir)) return;

		if (!$handle = opendir($updraft_dir)) return;
	
		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)\.(zip|gz)$/i', $entry, $matches)) {
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

	// Called via AJAX
	function updraft_ajax_handler() {
		// Test the nonce (probably not needed, since we're presumably admin-authed, but there's no harm)
		$nonce = (empty($_REQUEST['nonce'])) ? "" : $_REQUEST['nonce'];
		if (! wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce') || empty($_REQUEST['subaction'])) die('Security check');

		if ('lastlog' == $_GET['subaction']) {
			echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '('.__('Nothing yet logged', 'updraftplus').')'));
		} elseif ('lastbackup' == $_GET['subaction']) {
			echo $this->last_backup_html();
		} elseif ('diskspaceused' == $_GET['subaction']) {
			echo $this->recursive_directory_size($this->backups_dir_location());
		} elseif ('historystatus' == $_GET['subaction']) {
			$rescan = (isset($_GET['rescan']) && $_GET['rescan'] == 1);
			if ($rescan) $this->rebuild_backup_history();
			echo $this->existing_backup_table();
		} elseif ('downloadstatus' == $_GET['subaction'] && isset($_GET['timestamp']) && isset($_GET['type'])) {

			echo get_transient('ud_dlmess_'.$_GET['timestamp'].'_'.$_GET['type']).'<br>';

			if ($file = get_transient('ud_dlfile_'.$_GET['timestamp'].'_'.$_GET['type'])) {
				if ('failed' == $file) {
					echo "Download failed";
				} elseif (preg_match('/^downloaded:(.*)$/', $file, $matches) && file_exists($matches[1])) {
					$size = round(filesize($matches[1])/1024, 1);
					echo "File ready: $size Kb: You should: <button type=\"button\" onclick=\"updraftplus_downloadstage2('".$_GET['timestamp']."', '".$_GET['type']."')\">Download to your computer</button> and then, if you wish, <button id=\"uddownloaddelete_".$_GET['timestamp']."_".$_GET['type']."\" type=\"button\" onclick=\"updraftplus_deletefromserver('".$_GET['timestamp']."', '".$_GET['type']."')\">Delete from your web server</button>";
				} elseif (preg_match('/^downloading:(.*)$/', $file, $matches) && file_exists($matches[1])) {
					$size = round(filesize($matches[1])/1024, 1);
					echo __('File downloading', 'updraftplus').": ".basename($matches[1]).": $size Kb";
				} else {
					echo __("No local copy present.", 'updraftplus');
				}
			}

		} elseif ($_POST['subaction'] == 'credentials_test') {
			$method = (preg_match("/^[a-z0-9]+$/", $_POST['method'])) ? $_POST['method'] : "";

			// Test the credentials, return a code
			require_once(UPDRAFTPLUS_DIR."/methods/$method.php");

			$objname = "UpdraftPlus_BackupModule_${method}";
			if (method_exists($objname, "credentials_test")) call_user_func(array('UpdraftPlus_BackupModule_'.$method, 'credentials_test'));
		}

		die;

	}

	function updraft_download_backup() {

		if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'updraftplus_download')) die;

		if (!isset($_REQUEST['timestamp']) || !is_numeric($_REQUEST['timestamp']) ||  !isset($_REQUEST['type'])) exit;

		$backupable_entities = $this->get_backupable_file_entities(true);
		$type_match = false;
		foreach ($backupable_entities as $type => $info) {
			if ($_REQUEST['type'] == $type) $type_match = true;
		}

		if (!$type_match && $_REQUEST['type'] != 'db') exit;

		// Get the information on what is wanted
		$type = $_REQUEST['type'];
		$timestamp = $_REQUEST['timestamp'];

		// You need a nonce before you can set job data. And we certainly don't yet have one.
		$this->backup_time_nonce();

		$debug_mode = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');

		// Set the job type before logging, as there can be different logging destinations
		$this->jobdata_set('job_type', 'download');

		// Retrieve the information from our backup history
		$backup_history = $this->get_backup_history();
		// Base name
		$file = $backup_history[$timestamp][$type];

		// Where it should end up being downloaded to
		$fullpath = $this->backups_dir_location().'/'.$file;

		if (isset($_GET['stage']) && '2' == $_GET['stage']) {
			$this->spool_file($timestamp, $type, $fullpath);
			die;
		}

		if (isset($_POST['stage']) && 'delete' == $_POST['stage']) {
			@unlink($fullpath);
			echo 'deleted';
			$this->log('The file has been deleted');
			die;
		}

		// TODO: FIXME: Failed downloads may leave log files forever (though they are small)
		// Note that log() assumes that the data is in _POST, not _GET
		if ($debug_mode) $this->logfile_open($this->nonce);

		$this->log("Requested to obtain file: timestamp=$timestamp, type=$type");

		// The AJAX responder that updates on progress wants to see this
		set_transient('ud_dlfile_'.$timestamp.'_'.$type, 'downloading:'.$fullpath, 3600);

		$service = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;
		$this->jobdata_set('service', $service);

		// Fetch it from the cloud, if we have not already got it

		$needs_downloading = false;
		$known_size = isset($backup_history[$timestamp][$type.'-size']) ? $backup_history[$timestamp][$type.'-size'] : false;

		if(!file_exists($fullpath)) {
			//if the file doesn't exist and they're using one of the cloud options, fetch it down from the cloud.
			$needs_downloading = true;
			$this->log('File does not yet exist locally - needs downloading');
		} elseif ($known_size>0 && filesize($fullpath) < $known_size) {
			$this->log('The file was found locally but did not match the size in the backup history - will resume downloading');
			$needs_downloading = true;
		} elseif ($known_size>0) {
			$this->log('The file was found locally and matched the recorded size from the backup history ('.round($known_size/1024,1).' Kb)');
		} else {
			$this->log('No file size was found recorded in the backup history. We will assume the local one is complete.');
		}

		if ($needs_downloading) {
			// Close browser connection so that it can resume AJAX polling
			header('Connection: close');
			header('Content-Length: 0');
			header('Content-Encoding: none');
			session_write_close();
			echo "\r\n\r\n";
			$this->download_file($file, $service);
			if (is_readable($fullpath)) {
				$this->log('Remote fetch was successful (file size: '.round(filesize($fullpath)/1024,1).' Kb)');
			} else {
				$this->log('Remote fetch failed');
			}
		}

		// Now, spool the thing to the browser
		if(is_file($fullpath) && is_readable($fullpath)) {

			// That message is then picked up by the AJAX listener
			set_transient('ud_dlfile_'.$timestamp.'_'.$type, 'downloaded:'.$fullpath, 3600);

		} else {

			set_transient('ud_dlfile_'.$timestamp.'_'.$type, 'failed', 3600);

			echo 'Remote fetch failed. File '.$fullpath.' did not exist or was unreadable. If you delete local backups then remote retrieval may have failed.';
		}

		@fclose($this->logfile_handle);
  		if (!$debug_mode) @unlink($this->logfile_name);

		exit;

	}

	function spool_file($timestamp, $type, $fullpath) {

		if (file_exists($fullpath)) {

			$file = basename($fullpath);

			$len = filesize($fullpath);

			$filearr = explode('.',$file);
	// 			//we've only got zip and gz...for now
			$file_ext = array_pop($filearr);
			if($file_ext == 'zip') {
				header('Content-type: application/zip');
			} else {
				// This catches both when what was popped was 'crypt' (*-db.gz.crypt) and when it was 'gz' (unencrypted)
				header('Content-type: application/x-gzip');
			}
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
			header("Content-Length: $len;");
			if ($file_ext == 'crypt') {
				header("Content-Disposition: attachment; filename=\"".substr($file,0,-6)."\";");
			} else {
				header("Content-Disposition: attachment; filename=\"$file\";");
			}
			ob_end_flush();
			if ($file_ext == 'crypt') {
				$encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
				if ($encryption == "") {
					$this->error('Decryption of database failed: the database file is encrypted, but you have no encryption key entered.');
				} else {
					require_once(dirname(__FILE__).'/includes/phpseclib/Crypt/Rijndael.php');
					$rijndael = new Crypt_Rijndael();
					$rijndael->setKey($encryption);
					$in_handle = fopen($fullpath,'r');
					$ciphertext = "";
					while (!feof ($in_handle)) {
						$ciphertext .= fread($in_handle, 16384);
					}
					fclose ($in_handle);
					print $rijndael->decrypt($ciphertext);
				}
			} else {
				readfile($fullpath);
			}
// 			$this->delete_local($file);
		} else {
			echo __('File not found', 'updraftplus');
		}
	}

	function download_file($file, $service=false) {

		if (!$service) $service = UpdraftPlus_Options::get_updraft_option('updraft_service');

		$this->log("Requested file from remote service: service=$service, file=$file");

		$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
		if (file_exists($method_include)) require_once($method_include);

		$objname = "UpdraftPlus_BackupModule_${service}";
		if (method_exists($objname, "download")) {
			$remote_obj = new $objname;
			$remote_obj->download($file);
		} else {
			$this->log("Automatic backup restoration is not available with the method: $service.");
			$this->error("$file: ".sprintf(__("The backup archive for restoring this file could not be found. The remote storage method in use (%s) does not allow us to retrieve files. To proceed with this restoration, you need to obtain a copy of this file and place it inside UpdraftPlus's working folder", 'updraftplus'), $service)." (".$this->prune_updraft_dir_prefix($this->backups_dir_location()).")");
		}

	}
		
	function restore_backup($timestamp) {

		global $wp_filesystem;
		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>'.__('This backup does not exist in the backup history - restoration aborted. Timestamp:','updraftplus')." $timestamp</p><br/>";
			return false;
		}

		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_restore&backup_timestamp=$timestamp"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}

		//if we make it this far then WP_Filesystem has been instantiated and is functional (tested with ftpext, what about suPHP and other situations where direct may work?)
		echo '<h1>'.__('UpdraftPlus Restoration: Progress', 'updraftplus').'</h1><div id="updraft-restore-progress">';

		$updraft_dir = $this->backups_dir_location().'/';

		$service = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;

		if (!isset($_POST['updraft_restore']) || !is_array($_POST['updraft_restore'])) {
			echo '<p>'.__('ABORT: Could not find the information on which entities to restore.', 'updraftplus').'</p>';
			return false;
		}

		$entities_to_restore = array_flip($_POST['updraft_restore']);

		$backupable_entities = $this->get_backupable_file_entities(true, true);

		foreach($backup_history[$timestamp] as $type => $file) {
			// All restorable entities must be given explicitly, as we can store other arbitrary data in the history array
			 
			if (!isset($backupable_entities[$type]) && 'db' != $type) continue;

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
			if(!is_readable($fullpath) && $type != 'db') {
				echo __("File is not locally present - needs retrieving from remote storage (for large files, it is better to do this in advance from the download console)",'updraftplus')."<br>";
				$this->download_file($file, $service);
			}
			// If a file size is stored in the backup data, then verify correctness of the local file
			if (isset($backup_history[$timestamp][$type.'-size'])) {
				$fs = $backup_history[$timestamp][$type.'-size'];
				echo __("Archive is expected to be size:",'updraftplus')." ".round($fs/1024)." Kb :";
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
			if(is_readable($fullpath) && $type != 'db') {

				if(!class_exists('WP_Upgrader')) require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
				require_once(UPDRAFTPLUS_DIR.'/includes/updraft-restorer.php');
				$restorer = new Updraft_Restorer();
				$val = $restorer->restore_backup($fullpath, $type, $service, $backupable_entities[$type]);

				if(is_wp_error($val)) {
					foreach ($val->get_error_messages() as $msg) {
						echo '<strong>'.__('Error message',  'updraftplus').':</strong> '.htmlspecialchars($msg).'<br>';
					}
					echo '</div>'; //close the updraft_restore_progress div even if we error
					return false;
				}
			} elseif ($type != 'db') {
				$this->error("$file: ".__('Could not find one of the files for restoration', 'updraftplus'));
				echo __('Could not find one of the files for restoration', 'updraftplus').": ($file)";
			} else {
				echo __("Databases are not yet restored through this mechanism - use your web host's control panel, phpMyAdmin or a similar tool",'updraftplus')."<br>";
			}
		}
		echo '</div>'; //close the updraft_restore_progress div
		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if(@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") {
			echo "<p>".__('Database could not be restored because PHP safe_mode is active on your server.  You will need to manually restore the file via phpMyAdmin or another method.', 'updraftplus')."</p><br/>";
			return false;
		}
		return true;
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
		
		$list = $wp_filesystem->dirlist(WP_CONTENT_DIR);

		$return_code = true;

		foreach ($list as $item) {
			if (substr($item['name'], -4, 4) == "-old") {
				//recursively delete
				print "<strong>".__('Delete','updraftplus').": </strong>".htmlspecialchars($item['name']).": ";
				if(!$wp_filesystem->delete(WP_CONTENT_DIR.'/'.$item['name'], true)) {
					$return_code = false;
					print "<strong>Failed</strong><br>";
				} else {
					print "<strong>OK</strong><br>";
				}
			}
		}

		return $return_code;
	}
	
	//scans the content dir to see if any -old dirs are present
	function scan_old_dirs() {
		$dirArr = scandir(WP_CONTENT_DIR);
		foreach($dirArr as $dir) {
			if(strpos($dir,'-old') !== false) return true;
		}
		return false;
	}
	
	
	function retain_range($input) {
		$input = (int)$input;
		if($input > 0 && $input < 3650) {
			return $input;
		} else {
			return 1;
		}
	}
	
	function create_backup_dir() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_create_backup_dir"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
			exit; 
		}

		$updraft_dir = $this->backups_dir_location();
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;

		//chmod the backup dir to 0777. ideally we'd rather chgrp it but i'm not sure if it's possible to detect the group apache is running under (or what if it's not apache...)
		if(!$wp_filesystem->mkdir($updraft_dir, 0777)) return false;

		return true;
	}

	function memory_check_current() {
		# Returns in megabytes
		$memory_limit = ini_get('memory_limit');
		$memory_unit = $memory_limit[strlen($memory_limit)-1];
		$memory_limit = substr($memory_limit,0,strlen($memory_limit)-1);
		switch($memory_unit) {
			case 'K':
				$memory_limit = $memory_limit/1024;
			break;
			case 'G':
				$memory_limit = $memory_limit*1024;
			break;
			case 'M':
				//assumed size, no change needed
			break;
		}
		return $memory_limit;
	}

	function disk_space_check($space) {
		$updraft_dir = $this->backups_dir_location();
		$disk_free_space = @disk_free_space($updraft_dir);
		if ($disk_free_space == false) return -1;
		return ($disk_free_space > $space) ? true : false;
	}

	function memory_check($memory) {
		$memory_limit = $this->memory_check_current();
		return ($memory_limit >= $memory)?true:false;
	}

	function execution_time_check($time) {
		$setting = ini_get('max_execution_time');
		return ( $setting==0 || $setting >= $time) ? true : false;
	}

	function admin_init() {
		if(UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
			@ini_set('display_errors',1);
			@error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
			@ini_set('track_errors',1);
		}
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-dialog');

		if (UpdraftPlus_Options::user_can_manage() && UpdraftPlus_Options::get_updraft_option('updraft_service') == "googledrive" && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid','') != '' && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token','') == '') {
			add_action('admin_notices', array($this,'show_admin_warning_googledrive') );
		}

		if (UpdraftPlus_Options::user_can_manage() && UpdraftPlus_Options::get_updraft_option('updraft_service') == "dropbox" && UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token','') == '') {
			add_action('admin_notices', array($this,'show_admin_warning_dropbox') );
		}

		if (UpdraftPlus_Options::user_can_manage() && $this->disk_space_check(1024*1024*35) === false) add_action('admin_notices', array($this, 'show_admin_warning_diskspace'));

		global $wp_version, $pagenow;
		if ($pagenow == 'options-general.php' && version_compare($wp_version, '3.2', '<')) add_action('admin_notices', array($this, 'show_admin_warning_wordpressversion'));

	}

	function url_start($urls,$url) {
		return ($urls) ? '<a href="http://'.$url.'">' : "";
	}

	function url_end($urls,$url) {
		return ($urls) ? '</a>' : " (http://$url)";
	}

	function wordshell_random_advert($urls) {
		if (defined('UPDRAFTPLUS_NOADS')) return "";
		$rad = rand(0,8);
		switch ($rad) {
		case 0:
			return $this->url_start($urls,'updraftplus.com')."Want more features or paid, guaranteed support? Check out UpdraftPlus.Com".$this->url_end($urls,'updraftplus.com');
			break;
		case 1:
			if (defined('WPLANG') && strlen(WPLANG)>0 && !is_file(UPDRAFTPLUS_DIR.'/languages/updraftplus-'.WPLANG.
'.mo')) return __('Can you translate? Want to improve UpdraftPlus for speakers of your language?','updraftplus').$this->url_start($urls,'updraftplus.com/translate/')."Please go here for instructions - it is easy.".$this->url_end($urls,'updraftplus.com/translate/');

			return __('Find UpdraftPlus useful?','updraftplus').$this->url_start($urls,'david.dw-perspective.org.uk/donate').__("Please make a donation", 'updraftplus').$this->url_end($urls,'david.dw-perspective.org.uk/donate');
		case 2:
			return $this->url_start($urls,'wordshell.net')."Check out WordShell".$this->url_end($urls,'www.wordshell.net')." - manage WordPress from the command line - huge time-saver";
			break;
		case 3:
			return __('Like UpdraftPlus and can spare one minute?','updraftplus').$this->url_start($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform').' '.__('Please help UpdraftPlus by giving a positive review at wordpress.org','updraftplus').$this->url_end($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform');
			break;
		case 4:
			return $this->url_start($urls,'www.simbahosting.co.uk')."Need high-quality WordPress hosting from WordPress specialists? (Including automatic backups and 1-click installer). Get it from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk');
			break;
		case 5:
			if (!defined('UPDRAFTPLUS_NOADS')) {
				return $this->url_start($urls,'updraftplus.com').__("Need even more features and support? Check out UpdraftPlus Premium",'updraftplus').$this->url_end($urls,'updraftplus.com');
			} else {
				return "Thanks for being an UpdraftPlus premium user. Keep visiting ".$this->url_start($urls,'updraftplus.com')."updraftplus.com".$this->url_end($urls,'updraftplus.com')." to see what's going on.";
			}
			break;
		case 6:
			return "Need custom WordPress services from experts (including bespoke development)?".$this->url_start($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/')." Get them from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/');
			break;
		case 7:
			return $this->url_start($urls,'updraftplus.com').__("Check out UpdraftPlus.Com for help, add-ons and support",'updraftplus').$this->url_end($urls,'updraftplus.com');
			break;
		case 8:
			return __("Want to say thank-you for UpdraftPlus?",'updraftplus').$this->url_start($urls,'updraftplus.com/shop/')." ".__("Please buy our very cheap 'no adverts' add-on.",'updraftplus').$this->url_end($urls,'updraftplus.com/shop/');
			break;
		}
	}

	function settings_formcontents($last_backup_html) {
		$updraft_dir = $this->backups_dir_location();

		?>
			<table class="form-table" style="width:900px;">
			<tr>
				<th><?php _e('File backup intervals','updraftplus'); ?>:</th>
				<td><select name="updraft_interval">
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
				<td><select name="updraft_interval_database">
					<?php
					foreach ($intervals as $cronsched => $descrip) {
						echo "<option value=\"$cronsched\" ";
						if ($cronsched == UpdraftPlus_Options::get_updraft_option('updraft_interval_database', UpdraftPlus_Options::get_updraft_option('updraft_interval'))) echo 'selected="selected"';
						echo ">$descrip</option>\n";
					}
					?>
					</select> <?php echo apply_filters('updraftplus_schedule_showdbconfig', '<input type="hidden" name="updraftplus_starttime_db" value="">'); ?>
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
				<th>Include in files backup:</th>
				<td>

			<?php
				$backupable_entities = $this->get_backupable_file_entities(true, true);
				$include_others_exclude = UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE);
				# The true (default value if non-existent) here has the effect of forcing a default of on.
				foreach ($backupable_entities as $key => $info) {
					$included = (UpdraftPlus_Options::get_updraft_option("updraft_include_$key",true)) ? 'checked="checked"' : "";
					if ('others' == $key) {
						?><input id="updraft_include_others" type="checkbox" name="updraft_include_others" value="1" <?php echo $included; ?> /> <label for="updraft_include_<?php echo $key ?>"><?php echo __('Any other directories found inside wp-content but exclude these directories:', 'updraftplus');?></label> <input type="text" name="updraft_include_others_exclude" size="44" value="<?php echo htmlspecialchars($include_others_exclude); ?>"/><br><?php
					} else {
						echo "<input id=\"updraft_include_$key\" type=\"checkbox\" name=\"updraft_include_$key\" value=\"1\" $included /><label for=\"updraft_include_$key\">".$info['description']."</label><br>";
					}
				}
			?>
				<p><?php echo __('Include all of these, unless you are backing them up outside of UpdraftPlus. The above directories are usually everything (except for WordPress core itself which you can download afresh from WordPress.org). But if you have made customised modifications outside of these directories, you need to back them up another way.', 'updraftplus') ?> (<a href="http://wordshell.net"><?php echo __('Use WordShell for automatic backup, version control and patching', 'updraftplus');?></a>).</p></td>
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
				<td><input type="text" name="updraft_encryptionphrase" value="<?php echo $updraft_encryptionphrase ?>" style="width:132px" /></td>
			</tr>
			<tr class="backup-crypt-description">
				<td></td><td><?php _e('If you enter text here, it is used to encrypt backups (Rijndael). <strong>Do make a separate record of it and do not lose it, or all your backups <em>will</em> be useless.</strong> Presently, only the database file is encrypted. This is also the key used to decrypt backups from this admin interface (so if you change it, then automatic decryption will not work until you change it back). You can also use the file example-decrypt.php from inside the UpdraftPlus plugin directory to decrypt manually.','updraftplus');?></td>
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
						if ($active_service == "none") echo $set; ?>>None</option>
					<?php
					foreach ($this->backup_methods as $method => $description) {
						echo "<option value=\"$method\"";
						if ($active_service == $method) echo ' '.$set;
						echo '>'.$description;
						echo "</option>\n";
					}
					?>
					</select></td>
			</tr>
			<?php
				foreach ($this->backup_methods as $method => $description) {
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

				jQuery(document).ready(function() {
					jQuery( "#updraft-restore-modal" ).dialog({
						autoOpen: false, height: 385, width: 480, modal: true,
						buttons: {
							Restore: function() {
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
							<?php _e('Cancel','updraftplus');?>: function() { jQuery(this).dialog("close"); }
						}
					});

					jQuery( "#updraft-backupnow-modal" ).dialog({
						autoOpen: false, height: 265, width: 375, modal: true,
						buttons: {
							'<?php _e('Backup Now','updraftplus');?>': function() {
								jQuery('#updraft-backupnow-form').submit();
							},
							<?php _e('Cancel','updraftplus');?>: function() { jQuery(this).dialog("close"); }
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
						foreach ($this->backup_methods as $method => $description) {
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
				<td><a id="enableexpertmode" href="#"><?php _e('Show expert settings','updraftplus');?></a> - <?php _e("click this to show some further options; don't bother with this unless you have a problem or are curious.",'updraftplus');?></td>
			</tr>
			<?php
			$delete_local = UpdraftPlus_Options::get_updraft_option('updraft_delete_local', 1);
			?>

			<tr class="deletelocal expertmode" style="display:none;">
				<th><?php _e('Delete local backup','updraftplus');?>:</th>
				<td><input type="checkbox" id="updraft_delete_local" name="updraft_delete_local" value="1" <?php if ($delete_local) echo 'checked="checked"'; ?>> <br><label for="updraft_delete_local"><?php _e('Uncheck this to prevent deletion of any superfluous backup files from your server after the backup run finishes (i.e. any files despatched remotely will also remain locally, and any files being kept locally will not be subject to the retention limits).','updraftplus');?></label></td>
			</tr>

			<tr class="expertmode backupdirrow" style="display:none;">
				<th>Backup directory:</th>
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
			<tr>
			<td></td>
			<td>
				<?php
					$ws_ad = $this->wordshell_random_advert(1);
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

	function last_backup_html() {

		$updraft_last_backup = UpdraftPlus_Options::get_updraft_option('updraft_last_backup');

		$updraft_dir = $this->backups_dir_location();

		if($updraft_last_backup) {

			if ($updraft_last_backup['success']) {
				// Convert to GMT, then to blog time
				$last_backup_text = get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraft_last_backup['backup_time']), 'D, F j, Y H:i T');
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

	function settings_output() {

		wp_enqueue_style('jquery-ui', UPDRAFTPLUS_URL.'/includes/jquery-ui-1.8.22.custom.css'); 

		/*
		we use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credentials 
		for the WP_Filesystem. to do this WP outputs a form that we can't insert variables into (apparently). So the values are 
		passed back in as GET parameters. REQUEST covers both GET and POST so this weird logic works.
		*/
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_restore' && isset($_REQUEST['backup_timestamp'])) {
			$backup_success = $this->restore_backup($_REQUEST['backup_timestamp']);
			if(empty($this->errors) && $backup_success == true) {
				echo '<p><strong>'.__('Restore successful!','updraftplus').'</strong></p>';
				echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus&updraft_restore_success=true">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
				return;
			} else {
				echo '<p>Restore failed...</p><ul style="list-style: disc inside;">';
				foreach ($this->errors as $err) {
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
		
		if(isset($_GET['error'])) {
			$this->show_admin_warning(htmlspecialchars($_GET['error']), 'error');
		}
		if(isset($_GET['message'])) {
			$this->show_admin_warning(htmlspecialchars($_GET['message']));
		}

		if(isset($_GET['action']) && $_GET['action'] == 'updraft_create_backup_dir') {
			if(!$this->create_backup_dir()) {
				echo '<p>'.__('Backup directory could not be created','updraftplus').'...</p><br/>';
			}
			echo '<p>'.__('Backup directory successfully created.','updraftplus').'</p><br/>';
			echo '<b>'.__('Actions','updraftplus').':</b> <a href="options-general.php?page=updraftplus">'.__('Return to UpdraftPlus Configuration','updraftplus').'</a>';
			return;
		}
		
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup') {
			// For unknown reasons, the <script> runs twice if put inside the <div>
			echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;"><strong>',__('Schedule backup','updraftplus').':</strong> ';
			if (wp_schedule_single_event(time()+5, 'updraft_backup_all') === false) {
				$this->log("A backup run failed to schedule");
				echo __("Failed.",'updraftplus')."</div>";
			} else {
				echo htmlspecialchars(__('OK. Now load any page from your site to make sure the schedule can trigger. You should then see activity in the "Last log message" field below.','updraftplus'))." <a href=\"http://updraftplus.com/faqs/my-scheduled-backups-and-pressing-backup-now-does-nothing-however-pressing-debug-backup-does-produce-a-backup/\">".__('Nothing happening? Follow this link for help.','updraftplus')."</a></div><script>setTimeout(function(){updraft_showlastbackup();}, 7000);</script>";
				$this->log("A backup run has been scheduled");
			}
		}

		// updraft_file_ids is not deleted
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_all') { $this->boot_backup(true,true); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_db') { $this->backup_db(); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_wipesettings') {
			$settings = array('updraft_interval', 'updraft_interval_database', 'updraft_retain', 'updraft_retain_db', 'updraft_encryptionphrase', 'updraft_service', 'updraft_dropbox_appkey', 'updraft_dropbox_secret', 'updraft_googledrive_clientid', 'updraft_googledrive_secret', 'updraft_googledrive_remotepath', 'updraft_ftp_login', 'updraft_ftp_pass', 'updraft_ftp_remote_path', 'updraft_server_address', 'updraft_dir', 'updraft_email', 'updraft_delete_local', 'updraft_debug_mode', 'updraft_include_plugins', 'updraft_include_themes', 'updraft_include_uploads', 'updraft_include_others', 'updraft_include_blogs', 'updraft_include_mu-plugins', 'updraft_include_others_exclude', 'updraft_lastmessage', 'updraft_googledrive_clientid', 'updraft_googledrive_token', 'updraft_dropboxtk_request_token', 'updraft_dropboxtk_access_token', 'updraft_dropbox_folder', 'updraft_last_backup', 'updraft_starttime_files', 'updraft_starttime_db', 'updraft_sftp_settings');
			foreach ($settings as $s) {
				UpdraftPlus_Options::delete_updraft_option($s);
			}
			$this->show_admin_warning(__("Your settings have been wiped.",'updraftplus'));
		}

		?>
		<div class="wrap">
			<h1><?php echo $this->plugin_title; ?></h1>

			<?php _e('By UpdraftPlus.Com','updraftplus')?> ( <a href="http://updraftplus.com">UpdraftPlus.Com</a> | <a href="http://david.dw-perspective.org.uk"><?php _e("Lead developer's homepage",'updraftplus');?></a> | <?php if (!defined('UPDRAFTPLUS_NOADS')) { ?><a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate"><?php _e('Donate','updraftplus');?></a> | <?php } ?><a href="http://updraftplus.com/support/frequently-asked-questions/">FAQs</a> | <a href="http://profiles.wordpress.org/davidanderson/"><?php _e('Other WordPress plugins','updraftplus');?></a>). <?php _e('Version','updraftplus');?>: <?php echo $this->version; ?>
			<br>
			<?php
			if(isset($_GET['updraft_restore_success'])) {
				echo "<div class=\"updated fade\" style=\"padding:8px;\"><strong>".__('Your backup has been restored.','updraftplus').'</strong> '.__('Your old (themes, uploads, plugins, whatever) directories have been retained with "-old" appended to their name. Remove them when you are satisfied that the backup worked properly.').' '.__('At this time UpdraftPlus does not automatically restore your database. You will need to use an external tool like phpMyAdmin to perform that task.','updraftplus')."</div>";
			}

			$ws_advert = $this->wordshell_random_advert(1);
			if ($ws_advert) { echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;">'.$ws_advert.'</div>'; }

			if($deleted_old_dirs) echo '<div style="color:blue" class=\"updated fade\">'.__('Old directories successfully deleted.','updraftplus').'</div>';

			if(!$this->memory_check(96)) {?>
				<div style="color:orange"><?php _e("Your PHP memory limit is quite low. UpdraftPlus attempted to raise it but was unsuccessful. This plugin may not work properly with a memory limit of less than 96 Mb (though on the other hand, it has been used successfully with a 32Mb limit - your mileage may vary, but don't blame us!).",'updraftplus');?> <?php _e('Current limit is:','updraftplus');?> <?php echo $this->memory_check_current(); ?> Mb</div>
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
			if(!empty($this->errors)) {
				foreach($this->errors as $error) {
					// ignoring severity
					echo '<div style="color:red">'.$error['error'].'</div>';
				}
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
					$updraft_dir = $this->backups_dir_location();
					// UNIX timestamp
					$next_scheduled_backup = wp_next_scheduled('updraft_backup');
					if ($next_scheduled_backup) {
						// Convert to GMT
						$next_scheduled_backup_gmt = gmdate('Y-m-d H:i:s', $next_scheduled_backup);
						// Convert to blog time zone
						$next_scheduled_backup = get_date_from_gmt($next_scheduled_backup_gmt, 'D, F j, Y H:i T');
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
							$next_scheduled_backup_database = get_date_from_gmt($next_scheduled_backup_database_gmt, 'D, F j, Y H:i T');
						} else {
							$next_scheduled_backup_database = __('Nothing currently scheduled','updraftplus');
						}
					}
					$current_time = get_date_from_gmt(gmdate('Y-m-d H:i:s'), 'D, F j, Y H:i T');

					$backup_disabled = (is_writable($updraft_dir)) ? '' : 'disabled="disabled"';

					$last_backup_html = $this->last_backup_html();

					?>

				<tr>
					<th><?php _e('Next scheduled backups','updraftplus');?>:</th>
					<td>
						<div style="width: 76px; float:left;">Files:</div><div style="color:blue; float:left;"><?php echo $next_scheduled_backup?></div>
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
						$backup_history_sets = (count($backup_history) == 1) ? 'set' : 'sets';
						$restore_disabled = (count($backup_history) == 0) ? 'disabled="disabled"' : "";
						?>
						<input type="button" class="button-primary" <?php echo $restore_disabled ?> value="<?php _e('Restore','updraftplus');?>" style="padding-top:2px;padding-bottom:2px;font-size:22px !important; min-height: 32px;" onclick="jQuery('.download-backups').slideDown(); updraft_historytimertoggle(1); jQuery('html,body').animate({scrollTop: jQuery('#updraft_lastlogcontainer').offset().top},'slow');">
					</div>
				</div>
			</div>
			<br style="clear:both" />
			<table class="form-table">
				<tr>
					<th>Last log message:</th>
					<td id="updraft_lastlogcontainer"><?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '(Nothing yet logged)')); ?></td>
				</tr>
				<tr>
					<th>Backups, logs &amp; restoring:</th>
					<td><a id="updraft_showbackups" href="#" title="<?php _e('Press to see available backups','updraftplus');?>" onclick="jQuery('.download-backups').toggle(); updraft_historytimertoggle(0);"><?php echo count($backup_history).' '.$backup_history_sets; ?> available</a></td>
				</tr>
				<tr>
					<td></td><td class="download-backups" style="display:none; border: 1px dotted;">
						<p style="max-width: 740px;"><ul style="list-style: disc inside;">
						<li><strong><?php _e('Downloading','updraftplus');?>:</strong> <?php _e("Pressing a button for Database/Plugins/Themes/Uploads/Others will make UpdraftPlus try to bring the backup file back from the remote storage (if any - e.g. Amazon S3, Dropbox, Google Drive, FTP) to your webserver. Then you will be allowed to download it to your computer. If the fetch from the remote storage stops progressing (wait 30 seconds to make sure), then press again to resume. Remember that you can also visit the cloud storage vendor's website directly.",'updraftplus');?></li>
						<li><strong><?php _e('Restoring','updraftplus');?>:</strong> <?php _e("Press the button for the backup you wish to restore. If your site is large and you are using remote storage, then you should first click on each entity in order to retrieve it back to the webserver. This will prevent time-outs from occuring during the restore process itself.",'updraftplus');?></li>
						<li><strong><?php _e('Opera web browser','updraftplus');?>:</strong> <?php _e('If you are using this, then turn Turbo/Road mode off.','updraftplus');?></li>
						<li title="<?php _e('This is a count of the contents of your Updraft directory','updraftplus');?>"><strong><?php _e('Web-server disk space in use by UpdraftPlus','updraftplus');?>:</strong> <span id="updraft_diskspaceused"><em>(calculating...)</em></span> <a href="#" onclick="updraftplus_diskspace(); return false;"><?php _e('refresh','updraftplus');?></a> | <a href="#" onclick="updraft_updatehistory(1); return false;" title="<?php _e('Press here to look inside your UpdraftPlus directory (in your web hosting space) for any new backup sets that you have uploaded. The location of this directory is set in the expert settings, below.','updraftplus'); ?>"><?php _e('rescan folder for new backup sets','updraftplus');?></a></li></ul>
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
							function updraft_downloader(nonce, what) {
								// Create somewhere for the status to be found
								var stid = 'uddlstatus_'+nonce+'_'+what;
								if (!jQuery('#'+stid).length) {
									jQuery('#ud_downloadstatus').append('<div style="clear:left; border: 1px dashed; padding: 8px; margin-top: 4px; max-width:840px;" id="'+stid+'"><button onclick="jQuery(\'#'+stid+'\').fadeOut().remove();" type="button" style="float:right;">X</button><strong>Download '+what+' ('+nonce+')</strong>: <span id="'+stid+'_st">Begun looking for this entity</span></div>');
									setTimeout(function(){updraft_downloader_status(nonce, what)}, 200);
								}
								// Reset, in case this is a re-try
								jQuery('#'+stid+'_st').html('Begun looking for this entity');
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
							function updraft_downloader_status(nonce, what) {
								var stid = 'uddlstatus_'+nonce+'_'+what;
								if (jQuery('#'+stid).length) {
									dlstatus_sdata.timestamp = nonce;
									dlstatus_sdata.type = what;
									jQuery.get(ajaxurl, dlstatus_sdata, function(response) {
										nexttimer = 1250;
										if (dlstatus_lastlog == response) { nexttimer = 3000; }
										setTimeout(function(){updraft_downloader_status(nonce, what)}, nexttimer);
										jQuery('#'+stid+'_st').html(response);
										dlstatus_lastlog = response;
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
<p><?php _e("Restoring will replace this site's themes, plugins, uploads and/or other content directories (according to what is contained in the backup set, and your selection",'updraftplus');?>). <?php _e('Choose the components to restore','updraftplus');?>:</p>
<form id="updraft_restore_form" method="post">
	<fieldset>
		<input type="hidden" name="action" value="updraft_restore">
		<input type="hidden" name="backup_timestamp" value="0" id="updraft_restore_timestamp">
		<?php
			$backupable_entities = $this->get_backupable_file_entities(true, true);
			foreach ($backupable_entities as $type => $info) {
				echo '<div><input id="updraft_restore_'.$type.'" type="checkbox" name="updraft_restore[]" value="'.$type.'"> <label for="updraft_restore_'.$type.'">'.$info['description'].'</label><br></div>';
			}
		?>
		<p><em><?php _e("Databases cannot yet be restored from here - you must download the database file and take it to your web hosting company's control panel.",'updraftplus');?></em></p>
	</fieldset>
</form>
</div>

<div id="updraft-backupnow-modal" title="UpdraftPlus - Perform a backup now">

	<p><?php _e("This will schedule a one-time backup. To proceed, press 'Backup Now', then wait 10 seconds, then visit any page on your site. WordPress should then start the backup running in the background.",'updraftplus');?></p>

	<form id="updraft-backupnow-form" method="post" action="">
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
				echo 'Peak memory usage: '.$peak_memory_usage.' MB<br/>';
				echo 'Current memory usage: '.$memory_usage.' MB<br/>';
				echo 'PHP memory limit: '.ini_get('memory_limit').' <br/>';
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
					<p><input type="submit" class="button-primary" value="Wipe All Settings" onclick="return(confirm('<?php echo htmlspecialchars(__('This will delete all your UpdraftPlus settings - are you sure you want to do this?'));?>'))" /></p>
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

	function existing_backup_table($backup_history = false) {

		// Fetch it if it was not passed
		if ($backup_history === false) $backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if (!is_array($backup_history)) $backup_history=array();

		$updraft_dir = $this->backups_dir_location();

		$backupable_entities = $this->get_backupable_file_entities(true, true);

		echo '<table>';

		krsort($backup_history);

		foreach($backup_history as $key=>$value) {
			$pretty_date = date('Y-m-d G:i',$key);
			$entities = '';
			?>
		<tr>
			<td><b><?php echo $pretty_date?></b></td>
			<td>
		<?php if (isset($value['db'])) { ?>
				<form id="uddownloadform_db_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader(<?php echo $key;?>, 'db')" method="post">
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
				if (isset($value[$type])) {
					$entities .= '/'.$type.'/';
					$sdescrip = preg_replace('/ \(.*\)$/', '', $info['description']);
				?>
				<form id="uddownloadform_<?php echo $type.'_'.$key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader('<?php echo $key."', '".$type;?>')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="<?php echo $type; ?>" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input type="submit" title="<?php echo __('Press here to download','updraftplus').' '.strtolower($info['description']); ?>" value="<?php echo $sdescrip;?>" />
				</form>
		<?php } else { echo "(No&nbsp;".strtolower($info['description']).")"; } ?>
			</td>
		<?php }; ?>

			<td>
		<?php if (isset($value['nonce']) && preg_match("/^[0-9a-f]{12}$/",$value['nonce']) && is_readable($updraft_dir.'/log.'.$value['nonce'].'.txt')) { ?>
				<form action="options-general.php" method="get">
					<input type="hidden" name="action" value="downloadlog" />
					<input type="hidden" name="page" value="updraftplus" />
					<input type="hidden" name="updraftplus_backup_nonce" value="<?php echo $value['nonce']; ?>" />
					<input type="submit" value="Backup Log" />
				</form>
		<?php } else { echo "(No&nbsp;backup&nbsp;log)"; } ?>
			</td>
			<td>
				<form method="post" action="">
					<input type="hidden" name="backup_timestamp" value="<?php echo $key;?>">
					<input type="hidden" name="action" value="updraft_restore" />
					<?php if ($entities) { ?><button title="<?php _e('After pressing this button, you will be given the option to choose which components you wish to restore','updraftplus');?>" type="button" <?php echo $restore_disabled ?> class="button-primary" style="padding-top:2px;padding-bottom:2px;font-size:16px !important; min-height:26px;" onclick="updraft_restore_options('<?php echo $entities;?>'); jQuery('#updraft_restore_timestamp').val('<?php echo $key;?>'); jQuery('#updraft_restore_date').html('<?php echo $pretty_date;?>'); jQuery('#updraft-restore-modal').dialog('open');">Restore</button><?php } ?>
				</form>
			</td>
		</tr>
		<script>
		function updraft_restore_options(entities) {
			jQuery('input[name="updraft_restore[]"]').each(function(x,y){
				var entity = jQuery(y).val();
				if (entities.indexOf('/'+entity+'/') != -1) {
					jQuery(y).removeAttr('disabled').parent().show();
				} else {
					jQuery(y).attr('disabled','disabled').parent().hide();
				}
			});
		}
		</script>
		<?php }
		echo '</table>';
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

	function show_admin_warning_unreadablelog() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('The log file could not be read.','updraftplus'));
	}

	function show_admin_warning_dropbox() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth&updraftplus_dropboxauth=doit">.'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Dropbox','Dropbox').'</a>');
	}

	function show_admin_warning_googledrive() {
		$this->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> <a href="options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth&updraftplus_googleauth=doit">.'.sprintf(__('Click here to authenticate your %s account (you will not be able to back up to %s without it).','updraftplus'),'Google Drive','Google Drive').'</a>');
	}

	// Caution: $source is allowed to be an array, not just a filename
	function make_zipfile($source, $destination) {

		// When to prefer PCL:
		// - We were asked to
		// - No zip extension present and no relevant method present
		// The zip extension check is not redundant, because method_exists segfaults some PHP installs, leading to support requests

		// Fallback to PclZip - which my tests show is 25% slower (and we can't resume)
		if ($this->zip_preferpcl || (!extension_loaded('zip') && !method_exists('ZipArchive', 'AddFile'))) {
			if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
			$zip_object = new PclZip($destination);
			$zipcode = $zip_object->create($source, PCLZIP_OPT_REMOVE_PATH, WP_CONTENT_DIR);
			if ($zipcode == 0 ) {
				$this->log("PclZip Error: ".$zip_object->errorName());
				return $zip_object->errorCode();
			} else {
				return true;
			}
		}

		$this->existing_files = array();

		// If the file exists, then we should grab its index of files inside, and sizes
		// Then, when we come to write a file, we should check if it's already there, and only add if it is not
		if (file_exists($destination) && is_readable($destination)) {
			$zip = new ZipArchive;
			$zip->open($destination);
			$this->log(basename($destination).": Zip file already exists, with ".$zip->numFiles." files");
			for ($i=0; $i<$zip->numFiles; $i++) {
				$si = $zip->statIndex($i);
				$name = $si['name'];
				$this->existing_files[$name] = $si['size'];
			}
		} elseif (file_exists($destination)) {
			$this->log("Zip file already exists, but is not readable; will remove: $destination");
			@unlink($destination);
		}

		$this->zipfiles_added = 0;
		$this->zipfiles_dirbatched = array();
		$this->zipfiles_batched = array();
		$this->zipfiles_lastwritetime = time();

		// Magic value, used later to detect no error occurring
		$last_error = 2349864;
		if (is_array($source)) {
			foreach ($source as $element) {
				$howmany = $this->makezip_recursive_add($destination, $element, basename($element), $element);
				if ($howmany < 0) {
					$last_error = $howmany;
				}
			}
		} else {
			$howmany = $this->makezip_recursive_add($destination, $source, basename($source), $source);
			if ($howmany < 0) {
				$last_error = $howmany;
			}
		}

		// Any not yet dispatched?
		if (count($this->zipfiles_dirbatched)>0 || count($this->zipfiles_batched)>0) {
			$howmany = $this->makezip_addfiles($destination);
			if ($howmany < 0) {
				$last_error = $howmany;
			}
		}

	if ($this->zipfiles_added > 0 || $last_error == 2349864) {
		// ZipArchive::addFile sometimes fails
		if (filesize($destination) < 100) {
			// Retry with PclZip
			$this->log("Zip::addFile apparently failed - retrying with PclZip");
			$this->zip_preferpcl = true;
			return $this->make_zipfile($source, $destination);
		}
		return true;
	} else {
		return $last_error;
	}

	}

	// Q. Why don't we only open and close the zip file just once?
	// A. Because apparently PHP doesn't write out until the final close, and it will return an error if anything file has vanished in the meantime. So going directory-by-directory reduces our chances of hitting an error if the filesystem is changing underneath us (which is very possible if dealing with e.g. 1Gb of files)

	// We batch up the files, rather than do them one at a time. So we are more efficient than open,one-write,close.
	function makezip_addfiles($zipfile) {

		// Short-circuit the null case, because we want to detect later if something useful happenned
		if (count($this->zipfiles_dirbatched) == 0 && count($this->zipfiles_batched) == 0) return true;

		// 05-Mar-2013 - added a new check on the total data added; it appears that things fall over if too much data is contained in the cumulative total of files that were addFile'd without a close-open cycle; presumably data is being stored in memory. In the case in question, it was a batch of MP3 files of around 100Mb each - 25 of those equals 2.5Gb!

		$data_added_since_reopen = 0;

		$zip = new ZipArchive();
		if (file_exists($zipfile)) {
			$opencode = $zip->open($zipfile);
			$original_size = filesize($zipfile);
			clearstatcache();
		} else {
			$opencode = $zip->open($zipfile, ZIPARCHIVE::CREATE);
			$original_size = 0;
		}

		if ($opencode !== true) return array($opencode, 0);
		// Make sure all directories are created before we start creating files
		while ($dir = array_pop($this->zipfiles_dirbatched)) {
			$zip->addEmptyDir($dir);
		}
		foreach ($this->zipfiles_batched as $file => $add_as) {
			$fsize = filesize($file);
			if (!isset($this->existing_files[$add_as]) || $this->existing_files[$add_as] != $fsize) {

				@touch($zipfile);
				$zip->addFile($file, $add_as);

				$data_added_since_reopen += $fsize;
				# 25Mb - force a write-out and re-open
				if ($data_added_since_reopen > 26214400 || (time() - $this->zipfiles_lastwritetime) > 2) {

					$before_size = filesize($zipfile);
					clearstatcache();

					if ($data_added_since_reopen > 26214400) {
						$this->log("Adding batch to zip file: over 25Mb added on this batch (".round($data_added_since_reopen/1048576,1)." Mb); re-opening (prior size: ".round($before_size/1024,1).' Kb)');
					} else {
						$this->log("Adding batch to zip file: over 2 seconds have passed since the last write (".round($data_added_since_reopen/1048576,1)." Mb); re-opening (prior size: ".round($before_size/1024,1).' Kb)');
					}
					if (!$zip->close()) {
						$this->log("zip::Close returned an error");
					}
					unset($zip);
					$zip = new ZipArchive();
					$opencode = $zip->open($zipfile);
					if ($opencode !== true) return array($opencode, 0);
					$data_added_since_reopen = 0;
					$this->zipfiles_lastwritetime = time();
					// Call here, in case we've got so many big files that we don't complete the whole routine
					if (filesize($zipfile) > $before_size) $this->something_useful_happened();
					clearstatcache();
				}
			}
			$this->zipfiles_added++;
			// Don't call something_useful_happened() here - nothing necessarily happens until close() is called
			if ($this->zipfiles_added % 100 == 0) $this->log("Zip: ".basename($zipfile).": ".$this->zipfiles_added." files added (on-disk size: ".round(filesize($zipfile)/1024,1)." Kb)");
		}
		// Reset the array
		$this->zipfiles_batched = array();
		$ret =  $zip->close();
		$this->zipfiles_lastwritetime = time();
		if (filesize($zipfile) > $original_size) $this->something_useful_happened();
		clearstatcache();
		return $ret;
	}

	function something_useful_happened() {

		// First, update the record of maximum detected runtime on each run
		$time_passed = $this->jobdata_get('run_times');
		if (!is_array($time_passed)) $time_passed = array();
		$time_passed[$this->current_resumption] = microtime(true)-$this->opened_log_time;
		$this->jobdata_set('run_times', $time_passed);

		if ($this->current_resumption >= 9 && $this->newresumption_scheduled == false) {
			$resume_interval = $this->jobdata_get('resume_interval');
			if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) { $resume_interval = $this->minimum_resume_interval(); }
			$schedule_for = time()+$resume_interval;
			$this->newresumption_scheduled = $schedule_for;
			$this->log("This is resumption ".$this->current_resumption.", but meaningful activity is still taking place; so a new one will be scheduled");
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($this->current_resumption + 1, $this->nonce));
		} else {
			$this->reschedule_if_needed();
		}
	}

	// This function recursively packs the zip, dereferencing symlinks but packing into a single-parent tree for universal unpacking
	function makezip_recursive_add($zipfile, $fullpath, $use_path_when_storing, $original_fullpath) {

		// De-reference
		$fullpath = realpath($fullpath);

		// Is the place we've ended up above the original base? That leads to infinite recursion
		if (($fullpath !== $original_fullpath && strpos($original_fullpath, $fullpath) === 0) || ($original_fullpath == $fullpath && strpos($use_path_when_storing, '/') !== false) ) {
			$this->log("Infinite recursion: symlink lead us to $fullpath, which is within $original_fullpath");
			$this->error(__("Infinite recursion: consult your log for more information",'updraftplus'));
			return false;
		}

		if(is_file($fullpath)) {
			if (is_readable($fullpath)) {
				$key = ($fullpath == $original_fullpath) ? basename($fullpath) : $use_path_when_storing.'/'.basename($fullpath);
				$this->zipfiles_batched[$fullpath] = $key;
				@touch($zipfile);
			} else {
				$this->log("$fullpath: unreadable file");
				$this->error("$fullpath: unreadable file");
			}
		} elseif (is_dir($fullpath)) {
			if (!isset($this->existing_files[$use_path_when_storing])) $this->zipfiles_dirbatched[] = $use_path_when_storing;
			if (!$dir_handle = @opendir($fullpath)) {
				$this->log("Failed to open directory: $fullpath");
				$this->error("Failed to open directory: $fullpath");
				return;
			}
			while ($e = readdir($dir_handle)) {
				if ($e != '.' && $e != '..') {
					if (is_link($fullpath.'/'.$e)) {
						$deref = realpath($fullpath.'/'.$e);
						if (is_file($deref)) {
							if (is_readable($deref)) {
								$this->zipfiles_batched[$deref] = $use_path_when_storing.'/'.$e;
								@touch($zipfile);
							} else {
								$this->log("$deref: unreadable file");
								$this->error("$deref: unreadable file");
							}
						} elseif (is_dir($deref)) {
							$this->makezip_recursive_add($zipfile, $deref, $use_path_when_storing.'/'.$e, $original_fullpath);
						}
					} elseif (is_file($fullpath.'/'.$e)) {
						if (is_readable($fullpath.'/'.$e)) {
							$this->zipfiles_batched[$fullpath.'/'.$e] = $use_path_when_storing.'/'.$e;
							@touch($zipfile);
						} else {
							$this->log("$fullpath/$e: unreadable file");
							$this->error("$fullpath/$e: unreadable file");
						}
					} elseif (is_dir($fullpath.'/'.$e)) {
						// no need to addEmptyDir here, as it gets done when we recurse
						$this->makezip_recursive_add($zipfile, $fullpath.'/'.$e, $use_path_when_storing.'/'.$e, $original_fullpath);
					}
				}
			}
			closedir($dir_handle);
		}

		// We don't want to touch the zip file on every single file, so we batch them up
		// We go every 25 files, because if you wait too much longer, the contents may have changed from under you
		// And for some redundancy (redundant because of the touches going on anyway), we try to touch the file after 20 seconds, to help with the "recently modified" check on resumption (we saw a case where the file went for 155 seconds without being touched and so the other runner was not detected)
		if (count($this->zipfiles_batched) > 25 || (file_exists($zipfile) && ((time()-filemtime($zipfile)) > 20) )) {
			$ret = $this->makezip_addfiles($zipfile);
		} else {
			$ret = true;
		}

		return $ret;

	}

}


?>
