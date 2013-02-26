<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: your content and database can be backed up locally or to Amazon S3, Dropbox, Google Drive, (S)FTP & email, on separate schedules.
Author: David Anderson
Version: 1.4.29
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Author URI: http://wordshell.net
*/

/*
TODO - some are out of date/done, needs pruning
//When a manual backup is run, use a timer to update the 'Download backups and logs' section, just like 'Last finished backup run'. Beware of over-writing anything that's in there from a resumable downloader.
//Change DB encryption to not require whole gzip in memory (twice)
//Add Rackspace, Box.Net, SugarSync and Microsoft Skydrive support??
//The restorer has a hard-coded wp-content - fix
//?? On 'backup now', open up a Lightbox, count down 5 seconds, then start examining the log file (if it can be found)
//Should make clear in dashboard what is a non-fatal error (i.e. can be retried) - leads to unnecessary bug reports
// Move the inclusion, cloud and retention data into the backup job (i.e. don't read current config, make it an attribute of each job). In fact, everything should be. So audit all code for where get_option is called inside a backup run: it shouldn't happen.
// Should we resume if the only errors were upon deletion (i.e. the backup itself was fine?) Presently we do, but it displays errors for the user to confuse them. Perhaps better to make pruning a separate scheuled task??
// Warn the user if their zip-file creation is slooowww...
// Create a "Want Support?" button/console, that leads them through what is needed, and performs some basic tests...
// Resuming partial (S)FTP uploads
// Translations
// Make disk space check more intelligent (currently hard-coded at 35Mb)
// Specific folders on DropBox
// Provide backup/restoration for UpdraftPlus's settings, to allow 'bootstrap' on a fresh WP install - some kind of single-use code which a remote UpdraftPlus can use to authenticate
// Multiple jobs
// Multisite - a separate 'blogs' zip
// Allow connecting to remote storage, scanning + populating backup history from it
// Change FTP to use SSL by default
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
# TODO: Better solution is to split the backup set into manageable chunks based on this limit
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

	var $zip_preferpcl = false;

	function __construct() {

		// Initialisation actions - takes place on plugin load

		if ($fp = fopen( __FILE__, 'r')) {
			$file_data = fread( $fp, 1024 );
			if (preg_match("/Version: ([\d\.]+)(\r|\n)/", $file_data, $matches)) {
				$this->version = $matches[1];
			}
			fclose( $fp );
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
				if (preg_match('/\.tmp(\.gz)?$/', $entry) && is_file($updraft_dir.'/'.$entry) && $now_time-filemtime($updraft_dir.'/'.$entry)>86400) {
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
			$settings_link = '<a href="'.site_url().'/wp-admin/options-general.php?page=updraftplus">'.__("Settings", "UpdraftPlus").'</a>';
			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://david.dw-perspective.org.uk/donate">'.__("Donate","UpdraftPlus").'</a>';
			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://updraftplus.com">'.__("Add-Ons / Pro Support","UpdraftPlus").'</a>';
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
		$this->log("Opened log file at time: ".date('r'));
		global $wp_version;
		$logline = "UpdraftPlus: ".$this->version." WordPress: ".$wp_version." PHP: ".phpversion()." (".php_uname().") PHP Max Execution Time: ".@ini_get("max_execution_time")." ZipArchive::addFile exists: ";
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
		if ($this->logfile_handle) fwrite($this->logfile_handle, sprintf("%08.03f", round(microtime(true)-$this->opened_log_time, 3))." ".$line."\n");
		if ('download' == $this->jobdata_get('job_type')) {
			// Download messages are keyed on the job (since they could be running several), and transient
			// The values of the POST array were checked before
			set_transient('ud_dlmess_'.$_POST['timestamp'].'_'.$_POST['type'], $line." (".date('M d H:i:s').")", 3600);
		} else {
			UpdraftPlus_Options::update_updraft_option("updraft_lastmessage", $line." (".date('M d H:i:s').")");
		}
	}

	// This function is used by cloud methods to provide standardised logging, but more importantly to help us detect that meaningful activity took place during a resumption run, so that we can schedule further resumptions if it is worthwhile
	function record_uploaded_chunk($percent, $extra) {
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
			$resume_interval = $this->jobdata_get('resume_interval');
			if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) { $resume_interval = $this->minimum_resume_interval(); }
			$schedule_for = time()+$resume_interval;
			$this->newresumption_scheduled = $schedule_for;
			$this->log("This is resumption ".$this->current_resumption.", but meaningful uploading is still taking place; so a new one will be scheduled");
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($this->current_resumption + 1, $this->nonce));
		}
	}

	function minimum_resume_interval() {
		$inter = ini_get('max_execution_time');
		if (!$inter || $inter>300) $inter = 300;
		return $inter;
	}

	function backup_resume($resumption_no, $bnonce) {

		@ignore_user_abort(true);
		// This is scheduled for 5 minutes after a backup job starts

		// Restore state
		if ($resumption_no > 0) {
			$this->nonce = $bnonce;
			$this->backup_time = $this->jobdata_get('backup_time');
			$this->logfile_open($bnonce);
		}

		$btime = $this->backup_time;

		$job_type = $this->jobdata_get('job_type');

		$updraft_dir = $this->backups_dir_location();

		$this->log("Backup run: resumption=$resumption_no, nonce=$bnonce, begun at=$btime, job type: $job_type");
		$this->current_resumption = $resumption_no;

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) $resume_interval = $this->minimum_resume_interval();

		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no+1;
		if ($next_resumption < 10) {
			$this->log("Scheduling a resumption ($next_resumption) after $resume_interval seconds in case this run gets aborted");
			$schedule_for = time()+$resume_interval;
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $bnonce));
			$this->newresumption_scheduled = $schedule_for;
		} else {
			$this->log("The current run is our tenth attempt - will not schedule a further attempt until we see something useful happening");
		}

		// This should be always called; if there were no files in this run, it returns us an empty array
		$backup_array = $this->resumable_backup_of_files($resumption_no);

		// This save, if there was something, is then immediately picked up again
		if (is_array($backup_array)) {
			$this->log("Saving backup status to database (elements: ".count($backup_array).")");
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

		foreach ($our_files as $key => $file) {

			// Only continue if the stored info was about a dump
			if ($key != 'plugins' && $key != 'themes' && $key != 'others' && $key != 'uploads' && $key != 'db') continue;

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
		if ($this->nonce) set_transient("updraft_jobdata_".$this->nonce, $this->jobdata, UPDRAFT_TRANSTIME);
	}

	function jobdata_set($key, $value) {
			if (is_array($this->jobdata)) {
				$this->jobdata[$key] = $value;
			} else {
				$this->jobdata = array($key => $value);
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
		$max_execution_time = ini_get('max_execution_time');
		if ($max_execution_time >0 && $max_execution_time<300 && $resume_interval< $max_execution_time + 30) $resume_interval = $max_execution_time + 30;

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

		// Everthing is now set up; now go
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
				$this->error("Encryption error occurred when encrypting database. Encryption aborted.");
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

		wp_mail($sendmail_to,'Backed up: '.get_bloginfo('name').' (UpdraftPlus '.$this->version.') '.date('Y-m-d H:i',time()),'Site: '.site_url()."\r\nUpdraftPlus WordPress backup is complete.\r\nBackup contains: ".$backup_contains."\r\nLatest status: $final_message\r\n\r\n".$this->wordshell_random_advert(0)."\r\n".$append_log);

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

			if (isset($backup_to_examine['plugins']) || isset($backup_to_examine['themes']) || isset($backup_to_examine['uploads']) || isset($backup_to_examine['others'])) {
				$file_backups_found++;
				$this->log("$backup_datestamp: this set includes files; fileset count is now $file_backups_found");
				if ($file_backups_found > $updraft_retain) {
					$this->log("$backup_datestamp: over retain limit ($updraft_retain); will delete this file set");
					$file = isset($backup_to_examine['plugins']) ? $backup_to_examine['plugins'] : "";
					$file2 = isset($backup_to_examine['themes']) ? $backup_to_examine['themes'] : "";
					$file3 = isset($backup_to_examine['uploads']) ? $backup_to_examine['uploads'] : "";
					$file4 = isset($backup_to_examine['others']) ? $backup_to_examine['others'] : "";
					foreach (array($file, $file2, $file3, $file4) as $dofile) {
						if (!empty($dofile)) $this->prune_file($service, $dofile, $backup_method_object, $backup_passback);
					}
					unset($backup_to_examine['plugins']);
					unset($backup_to_examine['themes']);
					unset($backup_to_examine['uploads']);
					unset($backup_to_examine['others']);
				}
			}

			// Delete backup set completely if empty, o/w just remove DB
			// We search on the four keys which represent data, allowing other keys to be used to track other things
			if (!isset($backup_to_examine['plugins']) && !isset($backup_to_examine['themes']) && !isset($backup_to_examine['others']) && !isset($backup_to_examine['uploads']) && !isset($backup_to_examine['db']) ) {
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

	function reschedule($how_far_ahead) {
		// Reschedule - remove presently scheduled event
		wp_clear_scheduled_hook('updraft_backup_resume', array($this->current_resumption + 1, $this->nonce));
		// Add new event
		if ($how_far_ahead < $this->minimum_resume_interval()) $how_far_ahead=$this->minimum_resume_interval();
		$schedule_for = time() + $how_far_ahead;
		wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($this->current_resumption + 1, $this->nonce));
		$this->newresumption_scheduled = $schedule_for;
	}

	function increase_resume_and_reschedule($howmuch = 120) {
		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) { $resume_interval = $this->minimum_resume_interval(); }
		if ($this->newresumption_scheduled != false) $this->reschedule($resume_interval+$howmuch);
		$this->jobdata_set('resume_interval', $resume_interval+$howmuch);
		$this->log("To decrease the likelihood of overlaps, increasing resumption interval to: ".($resume_interval+$howmuch));
	}

	function create_zip($create_from_dir, $whichone, $create_in_dir, $backup_file_basename) {
		// Note: $create_from_dir can be an array or a string
		@set_time_limit(900);

		if ($whichone != "others") $this->log("Beginning creation of dump of $whichone");

		$full_path = $create_in_dir.'/'.$backup_file_basename.'-'.$whichone.'.zip';

		if (file_exists($full_path)) {
			$this->log("$backup_file_basename-$whichone.zip: this file has already been created");
			return basename($full_path);
		}

		// Temporary file, to be able to detect actual completion (upon which, it is renamed)

		// Firstly, make sure that the temporary file is not already being written to - which can happen if a resumption takes place whilst an old run is still active
		$zip_name = $full_path.'.tmp';
		$time_now = time();
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
			$this->log("ERROR: Zip failure: /*Could not create*/ $whichone zip: code=$zipcode");
			$this->error("Could not create $whichone zip: code $zipcode. Consult the log file for more information.");
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

	// This function is resumable
	function backup_dirs($transient_status) {

		if(!$this->backup_time) $this->backup_time_nonce();

		$updraft_dir = $this->backups_dir_location();
		if(!is_writable($updraft_dir)) {
			$this->log("Backup directory ($updraft_dir) is not writable, or does not exist");
			$this->error("Backup directory ($updraft_dir) is not writable, or does not exist.");
			return array();
		}

		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',substr(get_bloginfo(), 0, 96));
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if(!$blog_name) $blog_name = 'non_alpha_name';

		$backup_file_basename = 'backup_'.date('Y-m-d-Hi', $this->backup_time).'_'.$blog_name.'_'.$this->nonce;

		$backup_array = array();

		$wp_themes_dir = WP_CONTENT_DIR.'/themes';
		$wp_upload_dir = wp_upload_dir();
		$wp_upload_dir = $wp_upload_dir['basedir'];
		$wp_plugins_dir = WP_PLUGIN_DIR;

		$possible_backups = array ('plugins' => $wp_plugins_dir, 'themes' => $wp_themes_dir, 'uploads' => $wp_upload_dir);

		# Plugins, themes, uploads
		foreach ($possible_backups as $youwhat => $whichdir) {
			if (UpdraftPlus_Options::get_updraft_option("updraft_include_$youwhat", true)) {
				if ($transient_status == 'finished') {
					$backup_array[$youwhat] = $backup_file_basename.'-'.$youwhat.'.zip';
					if (file_exists($updraft_dir.'/'.$backup_file_basename.'-'.$youwhat.'.zip')) $backup_array[$youwhat.'-size'] = filesize($updraft_dir.'/'.$backup_file_basename.'-'.$youwhat.'.zip');
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

		# Others
		if (UpdraftPlus_Options::get_updraft_option('updraft_include_others', true)) {

			if ($transient_status == 'finished') {
				$backup_array['others'] = $backup_file_basename.'-others.zip';
				if (file_exists($updraft_dir.'/'.$backup_file_basename.'-others.zip')) $backup_array['others-size'] = filesize($updraft_dir.'/'.$backup_file_basename.'-others.zip');
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
					while (false !== ($entry = readdir($handle))) {
						$candidate = WP_CONTENT_DIR.'/'.$entry;
						if ($entry == "." || $entry == "..") { ; }
						elseif ($candidate == $updraft_dir) { $this->log("others: $entry: skipping: this is the updraft directory"); }
						elseif ($candidate == $wp_themes_dir) { $this->log("others: $entry: skipping: this is the themes directory"); }
						elseif ($candidate == $wp_upload_dir) { $this->log("others: $entry: skipping: this is the uploads directory"); }
						elseif ($candidate == $wp_plugins_dir) { $this->log("others: $entry: skipping: this is the plugins directory"); }
						elseif (isset($others_skip[$entry])) { $this->log("others: $entry: skipping: excluded by options"); }
						else { $this->log("others: $entry: adding to list"); array_push($other_dirlist, $candidate); }
					}
					@closedir($handle);
				} else {
					$this->log('ERROR: Could not read the content directory: '.WP_CONTENT_DIR);
					$this->error('Could not read the content directory: '.WP_CONTENT_DIR);
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
			$this->error('Could not save backup history because we have no backup array. Backup probably failed.');
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
			$this->error("$file: Could not open the backup file for writing");
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
		$file_base = 'backup_'.date('Y-m-d-Hi',$this->backup_time).'_'.$blog_name.'_'.$this->nonce;
		$backup_file_base = $updraft_dir.'/'.$file_base;

		if ('finished' == $already_done) return basename($backup_file_base.'-db.gz');
		if ('encrypted' == $already_done) return basename($backup_file_base.'-db.gz.crypt');

		$total_tables = 0;

		global $table_prefix, $wpdb;

		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);

		if (!is_writable($updraft_dir)) {
			$this->log("The backup directory ($updraft_dir) is not writable.");
			$this->error("The backup directory ($updraft_dir) is not writable.");
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

		foreach ($unlink_files as $unlink_file) {
			@unlink($unlink_file);
		}

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

	function backups_dir_location() {
		if (!empty($this->backup_dir)) return $this->backup_dir;
		$updraft_dir = untrailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir'));
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		//if the option isn't set, default it to /backups inside the upload dir
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;
		//check for the existence of the dir and an enumeration preventer.
		if(!is_dir($updraft_dir) || !is_file($updraft_dir.'/index.html') || !is_file($updraft_dir.'/.htaccess')) {
			@mkdir($updraft_dir, 0775, true);
			@file_put_contents($updraft_dir.'/index.html','Nothing to see here.');
			@file_put_contents($updraft_dir.'/.htaccess','deny from all');
		}
		$this->backup_dir = $updraft_dir;
		return $updraft_dir;
	}
	
	// Called via AJAX
	function updraft_ajax_handler() {
		// Test the nonce (probably not needed, since we're presumably admin-authed, but there's no harm)
		$nonce = (empty($_REQUEST['nonce'])) ? "" : $_REQUEST['nonce'];
		if (! wp_verify_nonce($nonce, 'updraftplus-credentialtest-nonce') || empty($_REQUEST['subaction'])) die('Security check');

		if ('lastlog' == $_GET['subaction']) {
			echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_lastmessage', '(Nothing yet logged)'));
		} elseif ('lastbackup' == $_GET['subaction']) {
			echo $this->last_backup_html();
		} elseif ('historystatus' == $_GET['subaction']) {
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
					echo "File downloading: ".basename($matches[1]).": $size Kb";
				} else {
					echo "No local copy present.";
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

		if (!isset($_REQUEST['timestamp']) || !is_numeric($_REQUEST['timestamp']) ||  !isset($_REQUEST['type']) || ('plugins' != $_REQUEST['type'] && 'themes' != $_REQUEST['type'] && 'uploads' != $_REQUEST['type'] && 'others' != $_REQUEST['type'] && 'db' != $_REQUEST['type'])) exit;

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
		// Not that log() assumes that the data is in _POST, not _GET
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
			$this->download_file($file, $service, true);
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
			echo "File not found";
		}
	}

	function download_file($file, $service=false, $detach_from_browser) {

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
			$this->error("Automatic backup restoration is not available with the method: $service.");
		}

	}
		
	function restore_backup($timestamp) {
		global $wp_filesystem;
		$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>This backup does not exist in the backup history - restoration aborted. Timestamp: '.$timestamp.'</p><br/>';
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
		echo '<span style="font-weight:bold">Restoration Progress</span><div id="updraft-restore-progress">';

		$updraft_dir = $this->backups_dir_location().'/';

		$service = (isset($backup_history[$timestamp]['service'])) ? $backup_history[$timestamp]['service'] : false;

		foreach($backup_history[$timestamp] as $type => $file) {
			// All restorable entities must be given explicitly, as we can store other arbitrary data in the history array
			if ('themes' != $type && 'plugins' != $type && 'uploads' != $type && 'others' != $type && 'db' != $type) continue;
			$fullpath = $updraft_dir.$file;
			if(!is_readable($fullpath) && $type != 'db') {
				$this->download_file($file, $service);
			}
			# Types: uploads, themes, plugins, others, db
			if(is_readable($fullpath) && $type != 'db') {
				if(!class_exists('WP_Upgrader')) require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
				require_once(UPDRAFTPLUS_DIR.'/includes/updraft-restorer.php');
				$restorer = new Updraft_Restorer();
				$val = $restorer->restore_backup($fullpath, $type);
				if(is_wp_error($val)) {
					print_r($val);
					echo '</div>'; //close the updraft_restore_progress div even if we error
					return false;
				}
			}
		}
		echo '</div>'; //close the updraft_restore_progress div
		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if(@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") {
			echo "<p>DB could not be restored because PHP safe_mode is active on your server.  You will need to manually restore the file via phpMyAdmin or another method.</p><br/>";
			return false;
		}
		return true;
	}

	//deletes the -old directories that are created when a backup is restored.
	function delete_old_dirs() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_delete_old_dirs"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}
		
		$to_delete = array('themes-old','plugins-old','uploads-old','others-old');

		foreach($to_delete as $name) {
			//recursively delete
			if(!$wp_filesystem->delete(WP_CONTENT_DIR.'/'.$name, true)) {
				return false;
			}
		}
		return true;
	}
	
	//scans the content dir to see if any -old dirs are present
	function scan_old_dirs() {
		$dirArr = scandir(WP_CONTENT_DIR);
		foreach($dirArr as $dir) {
			if(strpos($dir,'-old') !== false) {
				return true;
			}
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
			return "Find UpdraftPlus useful? ".$this->url_start($urls,'david.dw-perspective.org.uk/donate')."Please make a donation.".$this->url_end($urls,'david.dw-perspective.org.uk/donate');
		case 2:
			return $this->url_start($urls,'wordshell.net')."Check out WordShell".$this->url_end($urls,'www.wordshell.net')." - manage WordPress from the command line - huge time-saver";
			break;
		case 3:
			return "Want some more useful plugins? ".$this->url_start($urls,'profiles.wordpress.org/DavidAnderson/')."See my WordPress profile page for others.".$this->url_end($urls,'profiles.wordpress.org/DavidAnderson/');
			break;
		case 4:
			return $this->url_start($urls,'www.simbahosting.co.uk')."Need high-quality WordPress hosting from WordPress specialists? (Including automatic backups and 1-click installer). Get it from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk');
			break;
		case 5:
			if (!defined('UPDRAFTPLUS_NOADS')) {
				return $this->url_start($urls,'updraftplus.com')."Need even more features and support? Check out UpdraftPlus Premium".$this->url_end($urls,'updraftplus.com');
			} else {
				return "Thanks for being an UpdraftPlus premium user. Keep visiting ".$this->url_start($urls,'updraftplus.com')."updraftplus.com".$this->url_end($urls,'updraftplus.com')." to see what's going on.";
			}
			break;
		case 6:
			return "Need custom WordPress services from experts (including bespoke development)?".$this->url_start($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/')." Get them from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/');
			break;
		case 7:
			return $this->url_start($urls,'www.updraftplus.com')."Check out UpdraftPlus.Com for help, add-ons and support".$this->url_end($urls,'www.updraftplus.com');
			break;
		case 8:
			return "Want to say thank-you for UpdraftPlus? ".$this->url_start($urls,'updraftplus.com/shop/')." Please buy our very cheap 'no adverts' add-on.".$this->url_end($urls,'updraftplus.com/shop/');
			break;
		}
	}

	function settings_formcontents($last_backup_html) {
		$updraft_dir = $this->backups_dir_location();

		?>
			<table class="form-table" style="width:850px;">
			<tr>
				<th>File backup intervals:</th>
				<td><select name="updraft_interval">
					<?php
					$intervals = array ("manual" => "Manual", 'every4hours' => "Every 4 hours", 'every8hours' => "Every 8 hours", 'twicedaily' => "Every 12 hours", 'daily' => "Daily", 'weekly' => "Weekly", 'fortnightly' => "Fortnightly", 'monthly' => "Monthly");
					foreach ($intervals as $cronsched => $descrip) {
						echo "<option value=\"$cronsched\" ";
						if ($cronsched == UpdraftPlus_Options::get_updraft_option('updraft_interval','manual')) echo 'selected="selected"';
						echo ">$descrip</option>\n";
					}
					?>
					</select> <?php echo apply_filters('updraftplus_schedule_showfileconfig', '<input type="hidden" name="updraftplus_starttime_files" value="">'); ?>
					and retain this many backups: <?php
					$updraft_retain = UpdraftPlus_Options::get_updraft_option('updraft_retain', 1);
					$updraft_retain = ((int)$updraft_retain > 0) ? (int)$updraft_retain : 1;
					?> <input type="text" name="updraft_retain" value="<?php echo $updraft_retain ?>" style="width:40px;" />
					</td>
			</tr>
			<tr>
				<th>Database backup intervals:</th>
				<td><select name="updraft_interval_database">
					<?php
					foreach ($intervals as $cronsched => $descrip) {
						echo "<option value=\"$cronsched\" ";
						if ($cronsched == UpdraftPlus_Options::get_updraft_option('updraft_interval_database', UpdraftPlus_Options::get_updraft_option('updraft_interval'))) echo 'selected="selected"';
						echo ">$descrip</option>\n";
					}
					?>
					</select> <?php echo apply_filters('updraftplus_schedule_showdbconfig', '<input type="hidden" name="updraftplus_starttime_db" value="">'); ?>
					and retain this many backups: <?php
					$updraft_retain_db = UpdraftPlus_Options::get_updraft_option('updraft_retain_db', $updraft_retain);
					$updraft_retain_db = ((int)$updraft_retain_db > 0) ? (int)$updraft_retain_db : 1;
					?> <input type="text" name="updraft_retain_db" value="<?php echo $updraft_retain_db ?>" style="width:40px" />
			</td>
			</tr>
			<tr class="backup-interval-description">
				<td></td><td><p>If you would like to automatically schedule backups, choose schedules from the dropdowns above. Backups will occur at the intervals specified. If the two schedules are the same, then the two backups will take place together. If you choose &quot;manual&quot; then you must click the &quot;Backup Now!&quot; button whenever you wish a backup to occur.</p>
				<?php echo apply_filters('updraftplus_fixtime_advert', '<p><strong>To fix the time at which a backup should take place, </strong> (e.g. if your server is busy at day and you want to run overnight), <a href="http://updraftplus.com/shop/fix-time/">use the &quot;Fix Time&quot; add-on</a></p>'); ?>
				</td>
			</tr>
			<?php
				# The true (default value if non-existent) here has the effect of forcing a default of on.
				$include_themes = (UpdraftPlus_Options::get_updraft_option('updraft_include_themes',true)) ? 'checked="checked"' : "";
				$include_plugins = (UpdraftPlus_Options::get_updraft_option('updraft_include_plugins',true)) ? 'checked="checked"' : "";
				$include_uploads = (UpdraftPlus_Options::get_updraft_option('updraft_include_uploads',true)) ? 'checked="checked"' : "";
				$include_others = (UpdraftPlus_Options::get_updraft_option('updraft_include_others',true)) ? 'checked="checked"' : "";
				$include_others_exclude = UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE);
			?>
			<tr>
				<th>Include in files backup:</th>
				<td>
				<input type="checkbox" name="updraft_include_plugins" value="1" <?php echo $include_plugins; ?> /> Plugins<br>
				<input type="checkbox" name="updraft_include_themes" value="1" <?php echo $include_themes; ?> /> Themes<br>
				<input type="checkbox" name="updraft_include_uploads" value="1" <?php echo $include_uploads; ?> /> Uploads<br>
				<input type="checkbox" name="updraft_include_others" value="1" <?php echo $include_others; ?> /> Any other directories found inside wp-content <?php if (is_multisite()) echo "(which on a multisite install includes users' blog contents) "; ?>- but exclude these directories: <input type="text" name="updraft_include_others_exclude" size="44" value="<?php echo htmlspecialchars($include_others_exclude); ?>"/><br>
				Include all of these, unless you are backing them up outside of UpdraftPlus. The above directories are usually everything (except for WordPress core itself which you can download afresh from WordPress.org). But if you have made customised modifications outside of these directories, you need to back them up another way. (<a href="http://wordshell.net">Use WordShell</a> for automatic backup, version control and patching).<br></td>
				</td>
			</tr>
			<tr>
				<th>Email:</th>
				<td><input type="text" style="width:260px" name="updraft_email" value="<?php echo UpdraftPlus_Options::get_updraft_option('updraft_email'); ?>" /> <br>Enter an address here to have a report sent (and the whole backup, if you choose) to it.</td>
			</tr>

			<tr>
				<th>Database encryption phrase:</th>
				<?php
				$updraft_encryptionphrase = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
				?>
				<td><input type="text" name="updraft_encryptionphrase" value="<?php echo $updraft_encryptionphrase ?>" style="width:132px" /></td>
			</tr>
			<tr class="backup-crypt-description">
				<td></td><td>If you enter text here, it is used to encrypt backups (Rijndael). <strong>Do make a separate record of it and do not lose it, or all your backups <em>will</em> be useless.</strong> Presently, only the database file is encrypted. This is also the key used to decrypt backups from this admin interface (so if you change it, then automatic decryption will not work until you change it back). You can also use the file example-decrypt.php from inside the UpdraftPlus plugin directory to decrypt manually.</td>
			</tr>
			</table>

			<h2>Copying Your Backup To Remote Storage</h2>

			<table class="form-table" style="width:850px;">
			<tr>
				<th>Choose your remote storage:</th>
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
				function updraft_historytimertoggle() {
					if (updraft_historytimer) {
						clearTimeout(updraft_historytimer);
						updraft_historytimer = 0;
					} else {
						updraft_updatehistory();
						updraft_historytimer = setInterval(function(){updraft_updatehistory()}, 30000);
					}
				}
				function updraft_updatehistory() {
					jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'historystatus', nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>' }, function(response) {
						jQuery('#updraft_existing_backups').html(response);
					});
				}

				jQuery(document).ready(function() {
					jQuery('#enableexpertmode').click(function() {
						jQuery('.expertmode').fadeIn();
						return false;
					});
					<?php if (!is_writable($updraft_dir)) echo "jQuery('.backupdirrow').show();\n"; ?>
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
			<table class="form-table" style="width:850px;">
			<tr>
				<td colspan="2"><h2>Advanced / Debugging Settings</h2></td>
			</tr>
			<tr>
				<th>Debug mode:</th>
				<td><input type="checkbox" name="updraft_debug_mode" value="1" <?php echo $debug_mode; ?> /> <br>Check this to receive more information and emails on the backup process - useful if something is going wrong. You <strong>must</strong> send me this log if you are filing a bug report.</td>
			</tr>
			<tr>
				<th>Expert settings:</th>
				<td><a id="enableexpertmode" href="#">Show expert settings</a> - click this to show some further options; don't bother with this unless you have a problem or are curious.</td>
			</tr>
			<?php
			$delete_local = UpdraftPlus_Options::get_updraft_option('updraft_delete_local', 1);
			?>

			<tr class="deletelocal expertmode" style="display:none;">
				<th>Delete local backup:</th>
				<td><input type="checkbox" name="updraft_delete_local" value="1" <?php if ($delete_local) echo 'checked="checked"'; ?>> <br>Uncheck this to prevent deletion of any superfluous backup files from your server after the backup run finishes (i.e. any files despatched remotely will also remain locally, and any files being kept locally will not be subject to the retention limits).</td>
			</tr>

			<tr class="expertmode backupdirrow" style="display:none;">
				<th>Backup directory:</th>
				<td><input type="text" name="updraft_dir" style="width:525px" value="<?php echo htmlspecialchars($updraft_dir); ?>" /></td>
			</tr>
			<tr class="expertmode backupdirrow" style="display:none;">
				<td></td><td><?php

					if(is_writable($updraft_dir)) {
						$dir_info = '<span style="color:green">Backup directory specified is writable, which is good.</span>';
					} else {
						$dir_info = '<span style="color:red">Backup directory specified is <b>not</b> writable, or does not exist. <span style="font-size:110%;font-weight:bold"><a href="options-general.php?page=updraftplus&action=updraft_create_backup_dir">Click here</a></span> to attempt to create the directory and set the permissions.  If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.</span>';
					}

					echo $dir_info ?> This is where UpdraftPlus will write the zip files it creates initially.  This directory must be writable by your web server. Typically you'll want to have it inside your wp-content folder (this is the default).  <b>Do not</b> place it inside your uploads dir, as that will cause recursion issues (backups of backups of backups of...).</td>
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
					<input type="submit" class="button-primary" value="Save Changes" />
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
				if (is_readable($potential_log_file)) $last_backup_text .= "<br><a href=\"?page=updraftplus&action=downloadlog&updraftplus_backup_nonce=".$updraft_last_backup['backup_nonce']."\">Download log file</a>";
			}

			$last_backup_color = ($updraft_last_backup['success']) ? 'green' : 'red';

		} else {
			$last_backup_text = 'No backup has been completed.';
			$last_backup_color = 'blue';
		}

		return "<span style=\"color:${last_backup_color}\">${last_backup_text}</span>";

	}

	function settings_output() {

		/*
		we use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credentials 
		for the WP_Filesystem. to do this WP outputs a form that we can't insert variables into (apparently). So the values are 
		passed back in as GET parameters. REQUEST covers both GET and POST so this weird logic works.
		*/
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_restore' && isset($_REQUEST['backup_timestamp'])) {
			$backup_success = $this->restore_backup($_REQUEST['backup_timestamp']);
			if(empty($this->errors) && $backup_success == true) {
				echo '<p>Restore successful!</p><br/>';
				echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus&updraft_restore_success=true">Return to Updraft Configuration</a>.';
				return;
			} else {
				echo '<p>Restore failed...</p><ul>';
				foreach ($this->errors as $err) {
					echo "<li>";
					if (is_string($err)) { echo htmlspecialchars($err); } else {
						print_r($err);
					}
					echo "</li>";
				}
				echo '</ul><b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
				return;
			}
			//uncomment the below once i figure out how i want the flow of a restoration to work.
			//echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
		}
		$deleted_old_dirs = false;
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_delete_old_dirs') {
			if($this->delete_old_dirs()) {
				$deleted_old_dirs = true;
			} else {
				echo '<p>Old directory removal failed for some reason. You may want to do this manually.</p><br/>';
			}
			echo '<p>Old directories successfully removed.</p><br/>';
			echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
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
				echo '<p>Backup directory could not be created...</p><br/>';
			}
			echo '<p>Backup directory successfully created.</p><br/>';
			echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
			return;
		}
		
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup') {
			// For unknown reasons, the <script> runs twice if put inside the <div>
			echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;"><strong>Schedule backup:</strong> ';
			if (wp_schedule_single_event(time()+5, 'updraft_backup_all') === false) {
				$this->log("A backup run failed to schedule");
				echo "Failed.</div>";
			} else {
				echo "OK. Now load any page from your site to make sure the schedule can trigger.</div><script>setTimeout(function(){updraft_showlastbackup();}, 7000);</script>";
				$this->log("A backup run has been scheduled");
			}
		}

		// updraft_file_ids is not deleted
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_all') { $this->boot_backup(true,true); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_db') { $this->backup_db(); }
		elseif (isset($_POST['action']) && $_POST['action'] == 'updraft_wipesettings') {
			$settings = array('updraft_interval', 'updraft_interval_database', 'updraft_retain', 'updraft_retain_db', 'updraft_encryptionphrase', 'updraft_service', 'updraft_dropbox_appkey', 'updraft_dropbox_secret', 'updraft_dropbox_folder', 'updraft_googledrive_clientid', 'updraft_googledrive_secret', 'updraft_googledrive_remotepath', 'updraft_ftp_login', 'updraft_ftp_pass', 'updraft_ftp_remote_path', 'updraft_server_address', 'updraft_dir', 'updraft_email', 'updraft_delete_local', 'updraft_debug_mode', 'updraft_include_plugins', 'updraft_include_themes', 'updraft_include_uploads', 'updraft_include_others', 'updraft_include_others_exclude', 'updraft_lastmessage', 'updraft_googledrive_clientid', 'updraft_googledrive_token', 'updraft_dropboxtk_request_token', 'updraft_dropboxtk_access_token', 'updraft_dropbox_folder', 'updraft_last_backup', 'updraft_starttime_files', 'updraft_starttime_db', 'updraft_sftp_settings');
			foreach ($settings as $s) {
				UpdraftPlus_Options::delete_updraft_option($s);
			}
			$this->show_admin_warning("Your settings have been wiped.");
		}

		?>
		<div class="wrap">
			<h1><?php echo $this->plugin_title; ?></h1>

			Maintained by <b>David Anderson</b> (<a href="http://updraftplus.com">UpdraftPlus.Com</a> | <a href="http://david.dw-perspective.org.uk">Author Homepage</a> | <?php if (!defined('UPDRAFTPLUS_NOADS')) { ?><a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate">Donate</a> | <?php } ?><a href="http://wordpress.org/extend/plugins/updraftplus/faq/">FAQs</a> | <a href="http://profiles.wordpress.org/davidanderson/">My other WordPress plugins</a>). Version: <?php echo $this->version; ?>
			<br>
			<?php
			if(isset($_GET['updraft_restore_success'])) {
				echo "<div style=\"color:blue\">Your backup has been restored.  Your old themes, uploads, and plugins directories have been retained with \"-old\" appended to their name.  Remove them when you are satisfied that the backup worked properly.  At this time Updraft does not automatically restore your DB.  You will need to use an external tool like phpMyAdmin to perform that task.</div>";
			}

			$ws_advert = $this->wordshell_random_advert(1);
			if ($ws_advert) { echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;">'.$ws_advert.'</div>'; }

			if($deleted_old_dirs) echo '<div style="color:blue">Old directories successfully deleted.</div>';

			if(!$this->memory_check(96)) {?>
				<div style="color:orange">Your PHP memory limit is quite low. UpdraftPlus attempted to raise it but was unsuccessful. This plugin may not work properly with a memory limit of less than 96 Mb (though on the other hand, it has been used successfully with a 32Mb limit - your mileage may vary, but don't blame us!). Current limit is: <?php echo $this->memory_check_current(); ?> Mb</div>
			<?php
			}
			if(!$this->execution_time_check(60)) {?>
				<div style="color:orange">Your PHP max_execution_time is less than 60 seconds. This possibly means you're running in safe_mode. Either disable safe_mode or modify your php.ini to set max_execution_time to a higher number. If you do not, then longer will be needed to complete a backup. Present limit is: <?php echo ini_get('max_execution_time'); ?> seconds.</div>
			<?php
			}

			if($this->scan_old_dirs()) {?>
				<div style="color:orange">You have old directories from a previous backup. Click to delete them after you have verified that the restoration worked.</div>
				<form method="post" action="<?php echo remove_query_arg(array('updraft_restore_success','action')) ?>">
					<input type="hidden" name="action" value="updraft_delete_old_dirs" />
					<input type="submit" class="button-primary" value="Delete Old Dirs" onclick="return(confirm('Are you sure you want to delete the old directories?  This cannot be undone.'))" />
				</form>
			<?php
			}
			if(!empty($this->errors)) {
				foreach($this->errors as $error) {
					// ignoring severity
					echo '<div style="color:red">'.$error['error'].'</div>';
				}
			}
			?>

			<h2 style="clear:left;">Existing Schedule And Backups</h2>
			<table class="form-table" style="float:left; clear: both; width:545px;">
				<noscript>
				<tr>
					<th>JavaScript warning:</th>
					<td style="color:red">This admin interface uses JavaScript heavily. You either need to activate it within your browser, or to use a JavaScript-capable browser.</td>
				</tr>
				</noscript>
				<tr>
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
						$next_scheduled_backup = 'No backups are scheduled at this time.';
					}
					
					$next_scheduled_backup_database = wp_next_scheduled('updraft_backup_database');
					if (UpdraftPlus_Options::get_updraft_option('updraft_interval_database',UpdraftPlus_Options::get_updraft_option('updraft_interval')) == UpdraftPlus_Options::get_updraft_option('updraft_interval')) {
						$next_scheduled_backup_database = "Will take place at the same time as the files backup.";
					} else {
						if ($next_scheduled_backup_database) {
							// Convert to GMT
							$next_scheduled_backup_database_gmt = gmdate('Y-m-d H:i:s', $next_scheduled_backup_database);
							// Convert to blog time zone
							$next_scheduled_backup_database = get_date_from_gmt($next_scheduled_backup_database_gmt, 'D, F j, Y H:i T');
						} else {
							$next_scheduled_backup_database = 'No backups are scheduled at this time.';
						}
					}
					$current_time = get_date_from_gmt(gmdate('Y-m-d H:i:s'), 'D, F j, Y H:i T');

					$backup_disabled = (is_writable($updraft_dir)) ? '' : 'disabled="disabled"';

					$last_backup_html = $this->last_backup_html();

					?>

					<th>Time now:</th>
					<td style="color:blue"><?php echo $current_time?></td>
				</tr>
				<tr>
					<th>Next scheduled files backup:</th>
					<td style="color:blue"><?php echo $next_scheduled_backup?></td>
				</tr>
				<tr>
					<th>Next scheduled DB backup:</th>
					<td style="color:blue"><?php echo $next_scheduled_backup_database?></td>
				</tr>
				<tr>
					<th>Last finished backup run:</th>
					<td id="updraft_last_backup"><?php echo $last_backup_html ?></td>
				</tr>
			</table>
			<div style="float:left; width:200px; padding-top: 40px;">
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup" />
					<p><input type="submit" <?php echo $backup_disabled ?> class="button-primary" value="Backup Now!" style="padding-top:2px;padding-bottom:2px;font-size:22px !important" onclick="return(confirm('This will schedule a one-time backup. To trigger the backup you should go ahead, then wait 10 seconds, then visit any page on your site. WordPress should then start the backup running in the background.'))"></p>
				</form>
				<div style="position:relative">
					<div style="position:absolute;top:0;left:0">
						<?php
						$backup_history = UpdraftPlus_Options::get_updraft_option('updraft_backup_history');
						$backup_history = (is_array($backup_history))?$backup_history:array();
						$restore_disabled = (count($backup_history) == 0) ? 'disabled="disabled"' : "";
						?>
						<input type="button" class="button-primary" <?php echo $restore_disabled ?> value="Restore" style="padding-top:2px;padding-bottom:2px;font-size:22px !important" onclick="jQuery('#backup-restore').fadeIn('slow');jQuery(this).parent().fadeOut('slow')">
					</div>
					<div style="display:none;position:absolute;top:0;left:0" id="backup-restore">
						<form method="post" action="">
							<b>Choose: </b>
							<select name="backup_timestamp" style="display:inline">
								<?php
								foreach($backup_history as $key=>$value) {
									echo "<option value='$key'>".date('Y-m-d G:i',$key)."</option>\n";
								}
								?>
							</select>

							<input type="hidden" name="action" value="updraft_restore" />
							<input type="submit" <?php echo $restore_disabled ?> class="button-primary" value="Restore Now!" style="padding-top:7px;margin-top:5px;padding-bottom:7px;font-size:24px !important" onclick="return(confirm('Restoring from backup will replace this site\'s themes, plugins, uploads and other content directories (according to what is contained in the backup set which you select). Database restoration cannot be done through this process - you must download the database and import yourself (e.g. through PHPMyAdmin). Do you wish to continue with the restoration process?'))" />
						</form>
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
					<th>Download backups and logs:</th>
					<td><a href="#" title="Click to see available backups" onclick="jQuery('.download-backups').toggle(); updraft_historytimertoggle();"><?php echo count($backup_history)?> available</a></td>
				</tr>
				<tr>
					<td></td><td class="download-backups" style="display:none">
						<p><em><strong>Note</strong> - Pressing a button will make UpdraftPlus try to bring a backup file back from the remote storage (if any - e.g. Amazon S3, Dropbox, Google Drive, FTP) to your webserver, before then allowing you to download it to your computer. If the fetch from the remote storage stops progressing (wait 30 seconds to make sure), then click again to resume from where it left off. Remember that you can always visit the cloud storage website vendor's website directly.</em></p>
						<div id="ud_downloadstatus"></div>
						<script>
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
						<div id="updraft_existing_backups">
							<?php
								print $this->existing_backup_table($backup_history);
							?>
						</div>
					</td>
				</tr>
			</table>
			<?php
			if (is_multisite() && !file_exists(UPDRAFTPLUS_DIR.'/addons/multisite.php')) {
				?>
				<h2>UpdraftPlus Multisite</h2>
				<table>
				<tr>
				<td>
				<p style="max-width:800px;">Do you need WordPress Multisite support? Please check out <a href="http://updraftplus.com">UpdraftPlus Premium</a>.</p>
				</td>
				</tr>
				</table>
			<?php } ?>
			<h2>Configure Backup Contents And Schedule</h2>
			<?php UpdraftPlus_Options::options_form_begin(); ?>
				<?php $this->settings_formcontents($last_backup_html); ?>
			</form>
			<div style="padding-top: 40px; display:none;" class="expertmode">
				<hr>
				<h3>Debug Information And Expert Options</h3>
				<p>
				<?php
				$peak_memory_usage = memory_get_peak_usage(true)/1024/1024;
				$memory_usage = memory_get_usage(true)/1024/1024;
				echo 'Peak memory usage: '.$peak_memory_usage.' MB<br/>';
				echo 'Current memory usage: '.$memory_usage.' MB<br/>';
				echo 'PHP memory limit: '.ini_get('memory_limit').' <br/>';
				?>
				</p>
				<p style="max-width: 600px;">The buttons below will immediately execute a backup run, independently of WordPress's scheduler. If these work whilst your scheduled backups and the &quot;Backup Now&quot; button do absolutely nothing (i.e. not even produce a log file), then it means that your scheduler is broken. You should then disable all your other plugins, and try the &quot; Backup Now&quot; button. If that fails, then contact your web hosting company and ask them if they have disabled wp-cron. If it succeeds, then re-activate your other plugins one-by-one, and find the one that is the problem and report a bug to them.</p>

				<form method="post">
					<input type="hidden" name="action" value="updraft_backup_debug_all" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="Debug Full Backup" onclick="return(confirm('This will cause an immediate backup.  The page will stall loading until it finishes (ie, unscheduled).'))" /></p>
				</form>
				<form method="post">
					<input type="hidden" name="action" value="updraft_backup_debug_db" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="Debug DB Backup" onclick="return(confirm('This will cause an immediate DB backup.  The page will stall loading until it finishes (ie, unscheduled). The backup may well run out of time; really this button is only helpful for checking that the backup is able to get through the initial stages, or for small WordPress sites.'))" /></p>
				</form>
				<h3>Wipe Settings</h3>
				<p style="max-width: 600px;">This button will delete all UpdraftPlus settings (but not any of your existing backups from your cloud storage). You will then need to enter all your settings again. You can also do this before deactivating/deinstalling UpdraftPlus if you wish.</p>
				<form method="post">
					<input type="hidden" name="action" value="updraft_wipesettings" />
					<p><input type="submit" class="button-primary" value="Wipe All Settings" onclick="return(confirm('This will delete all your UpdraftPlus settings - are you sure you want to do this?'))" /></p>
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

		echo '<table>';
		foreach($backup_history as $key=>$value) {
		?>
		<tr>
			<td><b><?php echo date('Y-m-d G:i',$key)?></b></td>
			<td>
		<?php if (isset($value['db'])) { ?>
				<form id="uddownloadform_db_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader(<?php echo $key;?>, 'db')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="db" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input type="submit" value="Database" />
				</form>
		<?php } else { echo "(No database)"; } ?>
			</td>
			<td>
		<?php if (isset($value['plugins'])) { ?>
				<form id="uddownloadform_plugins_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader(<?php echo $key;?>, 'plugins')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="plugins" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input  type="submit" value="Plugins" />
				</form>
		<?php } else { echo "(No plugins)"; } ?>
			</td>
			<td>
		<?php if (isset($value['themes'])) { ?>
				<form id="uddownloadform_themes_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader(<?php echo $key;?>, 'themes')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="themes" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input  type="submit" value="Themes" />
				</form>
		<?php } else { echo "(No themes)"; } ?>
			</td>
			<td>
		<?php if (isset($value['uploads'])) { ?>
				<form id="uddownloadform_uploads_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader(<?php echo $key;?>, 'uploads')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="uploads" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input  type="submit" value="Uploads" />
				</form>
		<?php } else { echo "(No uploads)"; } ?>
			</td>
			<td>
		<?php if (isset($value['others'])) { ?>
				<form id="uddownloadform_others_<?php echo $key;?>" action="admin-ajax.php" onsubmit="return updraft_downloader(<?php echo $key;?>, 'others')" method="post">
					<?php wp_nonce_field('updraftplus_download'); ?>
					<input type="hidden" name="action" value="updraft_download_backup" />
					<input type="hidden" name="type" value="others" />
					<input type="hidden" name="timestamp" value="<?php echo $key?>" />
					<input type="submit" value="Others" />
				</form>
		<?php } else { echo "(No others)"; } ?>
			</td>
			<td>
		<?php if (isset($value['nonce']) && preg_match("/^[0-9a-f]{12}$/",$value['nonce']) && is_readable($updraft_dir.'/log.'.$value['nonce'].'.txt')) { ?>
				<form action="options-general.php" method="get">
					<input type="hidden" name="action" value="downloadlog" />
					<input type="hidden" name="page" value="updraftplus" />
					<input type="hidden" name="updraftplus_backup_nonce" value="<?php echo $value['nonce']; ?>" />
					<input type="submit" value="Backup Log" />
				</form>
		<?php } else { echo "(No backup log)"; } ?>
			</td>
		</tr>
		<?php }
		echo '</table>';
	}

	function show_admin_warning($message, $class = "updated") {
		echo '<div id="updraftmessage" class="'.$class.' fade">'."<p>$message</p></div>";
	}

	function show_admin_warning_diskspace() {
		$this->show_admin_warning('<strong>Warning:</strong> You have less than 35Mb of free disk space on the disk which UpdraftPlus is configured to use to create backups. UpdraftPlus could well run out of space. Contact your the operator of your server (e.g. your web hosting company) to resolve this issue.');
	}

	function show_admin_warning_wordpressversion() {
		$this->show_admin_warning('<strong>Warning:</strong> UpdraftPlus does not officially support versions of WordPress before 3.2. It may work for you, but if it does not, then please be aware that no support is available until you upgrade WordPress.');
	}

	function show_admin_warning_unreadablelog() {
		$this->show_admin_warning('<strong>UpdraftPlus notice:</strong> The log file could not be read.');
	}

	function show_admin_warning_dropbox() {
		$this->show_admin_warning('<strong>UpdraftPlus notice:</strong> <a href="options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth&updraftplus_dropboxauth=doit">Click here to authenticate your Dropbox account (you will not be able to back up to Dropbox without it).</a>');
	}

	function show_admin_warning_googledrive() {
		$this->show_admin_warning('<strong>UpdraftPlus notice:</strong> <a href="options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth&updraftplus_googleauth=doit">Click here to authenticate your Google Drive account (you will not be able to back up to Google Drive without it).</a>');
	}

	// Caution: $source is allowed to be an array, not just a filename
	function make_zipfile($source, $destination) {

		// When to prefer PCL:
		// - We were asked to
		// - No zip extension present and no relevant method present
		// The zip extension check is not redundant, because method_exists segfaults some PHP installs, leading to support requests

		// Fallback to PclZip - which my tests show is 25% slower
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

		$last_error = -1;
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

	if ($this->zipfiles_added > 0) {
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
		$zip = new ZipArchive();
		if (file_exists($zipfile)) {
			$opencode = $zip->open($zipfile);
		} else {
			$opencode = $zip->open($zipfile, ZIPARCHIVE::CREATE);
		}
		if ($opencode !== true) return array($opencode, 0);
		// Make sure all directories are created before we start creating files
		while ($dir = array_pop($this->zipfiles_dirbatched)) {
			$zip->addEmptyDir($dir);
		}
		foreach ($this->zipfiles_batched as $file => $add_as) {
			if (!isset($this->existing_files[$add_as]) || $this->existing_files[$add_as] != filesize($file)) {
				$zip->addFile($file, $add_as);
			}
			$this->zipfiles_added++;
			if ($this->zipfiles_added % 100 == 0) $this->log("Zip: ".basename($zipfile).": ".$this->zipfiles_added." files added (size: ".round(filesize($zipfile)/1024,1)." Kb)");
		}
		// Reset the array
		$this->zipfiles_batched = array();
		return $zip->close();
	}

	// This function recursively packs the zip, dereferencing symlinks but packing into a single-parent tree for universal unpacking
	function makezip_recursive_add($zipfile, $fullpath, $use_path_when_storing, $original_fullpath) {

		// De-reference
		$fullpath = realpath($fullpath);

		// Is the place we've ended up above the original base? That leads to infinite recursion
		if (($fullpath !== $original_fullpath && strpos($original_fullpath, $fullpath) === 0) || ($original_fullpath == $fullpath && strpos($use_path_when_storing, '/') !== false) ) {
			$this->log("Infinite recursion: symlink lead us to $fullpath, which is within $original_fullpath");
			$this->error("Infinite recursion: consult your log for more information");
			return false;
		}

		if(is_file($fullpath)) {
			if (is_readable($fullpath)) {
				$key = $use_path_when_storing.'/'.basename($fullpath);
				$this->zipfiles_batched[$fullpath] = $use_path_when_storing.'/'.basename($fullpath);
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
