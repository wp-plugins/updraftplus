<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: take backups locally, or backup to Amazon S3, Dropbox, Google Drive, Rackspace, (S)FTP, WebDAV & email, on automatic schedules.
Author: UpdraftPlus.Com, DavidAnderson
Version: 1.6.2
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Text Domain: updraftplus
Author URI: http://updraftplus.com
*/

/*
TODO - some of these are out of date/done, needs pruning
// When you migrate/restore, if there is a .htaccess, warn/give option about it.
// Add an appeal for translators to email me.
// Embed changelog in UpdraftPlus.Com - see http://wordpress.org/extend/plugins/wp-readme-parser/
// Fix generation of excessive transients
// Deal with gigantic database tables - e.g. those over a million rows on cheap hosting. Also indicate beforehand how many rows there are.
// Some code assumes that the updraft_dir is inside WP_CONTENT_DIR. We should be using WP_Filesystem::find_folder to remove this assumption
// When restoring core, need an option to retain database settings / exclude wp-config.php
// Produce a command-line version of the restorer (so that people with shell access are immune from server-enforced timeouts)
// More sophisticated pruning options - e.g. "but only keep 1 backup every <x> <days> after <y> <weeks>"
// Migrator - search+replace the database, list+download from remote, kick-off backup remotely
// April 20, 2015: This is the date when the Google Documents API is likely to stop working (https://developers.google.com/google-apps/documents-list/terms)
// Fix-time add-on should also fix the day/date, when relevant
// Search for other TODO-s in the code
// Stand-alone installer - take a look at this: http://wordpress.org/extend/plugins/duplicator/screenshots/
// Migrator should search+replace table by table if possible. Pick the "Backup of: " field out the db dump to get site_url() in advance. Perform an action each table. Remember which ones have been done. Then at the end, pick up those not done.
// More DB add-on (other non-WP tables; even other databases)
// Unlimited customers should be auto-emailed each time they add a site (security)
// Update all-features page at updraftplus.com (not updated after 1.5.5)
// Count available time before doing a database restore
// Add in downloading in the 'Restore' modal, and remove the advice to do so manually.
// Save database encryption key inside backup history on per-db basis, so that if it changes we can still decrypt
// Switch to Google Drive SDK. Google folders. https://developers.google.com/drive/folder
// GlotPress
// Affiliate links? (I have no need...)
// Convert S3.php to use WP's native HTTP functions
// Ability to re-scan existing cloud storage
// Dropbox uses one mcrypt function - port to phpseclib for more portability
// Store meta-data on which version of UD the backup was made with (will help if we ever introduce quirks that need ironing)
// Test restoration when uploads dir is /assets/ (e.g. with Shoestrap theme)
// Send the user an email upon their first backup with tips on what to do (e.g. support/improve) (include legacy check to not bug existing users)
//Allow use of /usr/bin/zip - since this can escape from PHP's memory limit. Can still batch as we do so, in order to monitor/measure progress
// GoogleDrive +Rackspace folders
//Do an automated test periodically for the success of loop-back connections
//When a manual backup is run, use a timer to update the 'Download backups and logs' section, just like 'Last finished backup run'. Beware of over-writing anything that's in there from a resumable downloader.
//Change DB encryption to not require whole gzip in memory (twice)
//Add DreamObjects, Box.Net, SugarSync, Me.Ga support??
//Make it easier to find add-ons
//The restorer has a hard-coded wp-content - fix
//?? On 'backup now', open up a modal, count down 5 seconds, open page via modal, then start examining the log file (if it can be found)
//Should make clear in dashboard what is a non-fatal error (i.e. can be retried) - leads to unnecessary bug reports
// Move the inclusion, cloud and retention data into the backup job (i.e. don't read current config, make it an attribute of each job). In fact, everything should be. So audit all code for where get_option is called inside a backup run: it shouldn't happen.
// Should we resume if the only errors were upon deletion (i.e. the backup itself was fine?) Presently we do, but it displays errors for the user to confuse them. Perhaps better to make pruning a separate scheuled task??
// Warn the user if their zip-file creation is slooowww...
// Create a "Want Support?" button/console, that leads them through what is needed, and performs some basic tests...
// Chunking + resuming on SFTP
// Add-on to check integrity of backups
// Add-on to manage all your backups from a single dashboard
// Make disk space check more intelligent (currently hard-coded at 35Mb)
// Provide backup/restoration for UpdraftPlus's settings, to allow 'bootstrap' on a fresh WP install - some kind of single-use code which a remote UpdraftPlus can use to authenticate
// Multiple jobs
// Allow connecting to remote storage, scanning + populating backup history from it
// GoogleDrive in-dashboard download resumption loads the whole archive into memory - should instead either chunk or directly stream fo the file handle
// Multisite add-on should allow restoring of each blog individually
// When looking for files to delete, is the current encryption setting used? Should not be.
// Create single zip, containing even WordPress itself
// Remove the recurrence of admin notices when settings are saved due to _wp_referer

Encrypt filesystem, if memory allows (and have option for abort if not); split up into multiple zips when needed
// New sub-module to verify that the backups are there, independently of backup thread
*/

/*  Portions copyright 2010 Paul Kehrer
Portions copyright 2011-13 David Anderson
Other portions copyright as indicated authors in the relevant files

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

define('UPDRAFTPLUS_DIR', dirname(__FILE__));
define('UPDRAFTPLUS_URL', plugins_url('', __FILE__));
define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,index.php,backup,backups');
// This is used in various places, based on our assumption of the maximum time any job should take. May need lengthening in future if we get reports which show enormous sets hitting the limit.
// Also one section requires at least 1% progress each run, so on a 5-minute schedule, that equals just under 9 hours - then an extra allowance takes it just over
define('UPDRAFT_TRANSTIME', 3600*9+5);

// Load add-ons
if (is_file(UPDRAFTPLUS_DIR.'/premium.php')) require_once(UPDRAFTPLUS_DIR.'/premium.php');
if (is_file(UPDRAFTPLUS_DIR.'/autoload.php')) require_once(UPDRAFTPLUS_DIR.'/autoload.php');

if (is_dir(UPDRAFTPLUS_DIR.'/addons') && $dir_handle = opendir(UPDRAFTPLUS_DIR.'/addons')) {
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
	@ini_set('memory_limit', WP_MAX_MEMORY_LIMIT); //up the memory limit to the maximum WordPress is allowing for large backup files
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
		'cloudfiles' => 'Rackspace Cloud Files',
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
		add_action('init', array($this, 'handle_url_actions'));
		// Run earlier than default - hence earlier than other components
		add_action('admin_init', array($this,'admin_init'), 9);
		// admin_menu runs earlier, and we need it because options.php wants to use $updraftplus_admin before admin_init happens
		add_action('admin_menu', array($this,'admin_init'), 9);
		add_action('updraft_backup', array($this,'backup_files'));
		add_action('updraft_backup_database', array($this,'backup_database'));
		# backup_all is used by the manual "Backup Now" button	
		add_action('updraft_backup_all', array($this,'backup_all'));
		# this is our runs-after-backup event, whose purpose is to see if it succeeded or failed, and resume/mom-up etc.
		add_action('updraft_backup_resume', array($this,'backup_resume'), 10, 3);
		# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
		add_filter('cron_schedules', array($this,'modify_cron_schedules'));
		add_action('plugins_loaded', array($this, 'load_translations'));

		register_deactivation_hook(__FILE__, array($this, 'deactivation'));

	}

	function admin_init() {
		// We are in the admin area: now load all that code
		global $updraftplus_admin;
		if (empty($updraftplus_admin)) require_once(UPDRAFTPLUS_DIR.'/admin.php');

		if (isset($_GET['wpnonce']) && isset($_GET['page']) && isset($_GET['action']) && $_GET['page'] == 'updraftplus' && $_GET['action'] == 'downloadlatestmodlog' && wp_verify_nonce($_GET['wpnonce'], 'updraftplus_download')) {

			$updraft_dir = $this->backups_dir_location();

			$log_file = '';
			$mod_time = 0;

			if ($handle = opendir($updraft_dir)) {
				while (false !== ($entry = readdir($handle))) {
					// The latter match is for files created internally by zipArchive::addFile
					if (preg_match('/^log\.[a-z0-9]+\.txt$/i', $entry)) {
						$mtime = filemtime($updraft_dir.'/'.$entry);
						if ($mtime > $mod_time) {
							$mod_time = $mtime;
							$log_file = $updraft_dir.'/'.$entry;
						}
					}
				}
				@closedir($handle);
			}

			if ($mod_time >0) {
				if (is_readable($log_file)) {
					header('Content-type: text/plain');
					readfile($log_file);
					exit;
				} else {
					add_action('admin_notices', array($this,'show_admin_warning_unreadablelog') );
				}
			} else {
					add_action('admin_notices', array($this,'show_admin_warning_nolog') );
			}
		}

	}

	function add_curl_capath($handle) {
		if (!UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')) curl_setopt($handle, CURLOPT_CAINFO, UPDRAFTPLUS_DIR.'/includes/cacert.pem' );
	}

	// Handle actions passed on to method plugins; e.g. Google OAuth 2.0 - ?page=updraftplus&action=updraftmethod-googledrive-auth
	// Also handle action=downloadlog
	function handle_url_actions() {

		// First, basic security check: must be an admin page, with ability to manage options, with the right parameters
		// Also, only on GET because WordPress on the options page repeats parameters sometimes when POST-ing via the _wp_referer field
		if (isset($_SERVER['REQUEST_METHOD']) && 'GET' == $_SERVER['REQUEST_METHOD'] && UpdraftPlus_Options::user_can_manage() && isset( $_GET['page'] ) && $_GET['page'] == 'updraftplus' && isset($_GET['action']) ) {
			if (preg_match("/^updraftmethod-([a-z]+)-([a-z]+)$/", $_GET['action'], $matches) && file_exists(UPDRAFTPLUS_DIR.'/methods/'.$matches[1].'.php')) {
				$method = $matches[1];
				require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
				$call_class = "UpdraftPlus_BackupModule_".$method;
				$call_method = "action_".$matches[2];

				add_action('http_api_curl', array($this, 'add_curl_capath'));
				if (method_exists($call_class, $call_method)) call_user_func(array($call_class,$call_method));
				remove_action('http_api_curl', array($this, 'add_curl_capath'));

			} elseif ($_GET['action'] == 'downloadlog' && isset($_GET['updraftplus_backup_nonce']) && preg_match("/^[0-9a-f]{12}$/",$_GET['updraftplus_backup_nonce'])) {
				// No WordPress nonce is needed here or for the next, since the backup is already nonce-based
				$updraft_dir = $this->backups_dir_location();
				$log_file = $updraft_dir.'/log.'.$_GET['updraftplus_backup_nonce'].'.txt';
				if (is_readable($log_file)) {
					header('Content-type: text/plain');
					readfile($log_file);
					exit;
				} else {
					add_action('admin_notices', array($this,'show_admin_warning_unreadablelog') );
				}
			} elseif ($_GET['action'] == 'downloadfile' && isset($_GET['updraftplus_file']) && preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(gz\.crypt)$/i', $_GET['updraftplus_file'])) {
				$updraft_dir = $this->backups_dir_location();
				$spool_file = $updraft_dir.'/'.$_GET['updraftplus_file'];
				if (is_readable($spool_file)) {
					$dkey = (isset($_GET['decrypt_key'])) ? $_GET['decrypt_key'] : "";
					$this->spool_file('db', $spool_file, $dkey);
					exit;
				} else {
					add_action('admin_notices', array($this,'show_admin_warning_unreadablefile') );
				}
			}
		}
	}

	function show_admin_warning_unreadablelog() {
		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('The log file could not be read.','updraftplus'));
	}

	function show_admin_warning_nolog() {
		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('No log files were found.','updraftplus'));
	}

	function show_admin_warning_unreadablefile() {
		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('The given file could not be read.','updraftplus'));
	}

	function load_translations() {
		// Tell WordPress where to find the translations
		load_plugin_textdomain('updraftplus', false, basename(dirname(__FILE__)).'/languages');
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
		@include(ABSPATH.'wp-includes/version.php');
		global $wp_version;
		$logline = "UpdraftPlus: ".$this->version." WP: ".$wp_version." PHP: ".phpversion()." (".php_uname().") Server: ".$_SERVER["SERVER_SOFTWARE"]." max_execution_time: ".@ini_get("max_execution_time")." memory_limit: ".ini_get('memory_limit')." ZipArchive::addFile : ";

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

		if ($percent > ( $this->current_resumption - 9)) {
			$this->something_useful_happened();
		}
	}

	function something_useful_happened() {

		// First, update the record of maximum detected runtime on each run
		$time_passed = $this->jobdata_get('run_times');
		if (!is_array($time_passed)) $time_passed = array();
		$time_passed[$this->current_resumption] = microtime(true)-$this->opened_log_time;
		$this->jobdata_set('run_times', $time_passed);

		if ($this->current_resumption >= 9 && $this->newresumption_scheduled == false) {
			$this->log("This is resumption ".$this->current_resumption.", but meaningful activity is still taking place; so a new one will be scheduled");
			// We just use max here to make sure we get a number at all
			$resume_interval = max($this->jobdata_get('resume_interval'), 75);
			// Don't consult the minimum here
			// if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) { $resume_interval = $this->minimum_resume_interval(); }
			$schedule_for = time()+$resume_interval;
			$this->newresumption_scheduled = $schedule_for;
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($this->current_resumption + 1, $this->nonce));
		} else {
			$this->reschedule_if_needed();
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

		// We then add 'others' on to the end
		if ($include_others) {
			if ($full_info) {
				$arr['others'] = array('path' => WP_CONTENT_DIR, 'description' => __('Others','updraftplus'));
			} else {
				$arr['others'] = WP_CONTENT_DIR;
			}
		}

		// Entries that should be added after 'others'
		$arr = apply_filters('updraft_backupable_file_entities_final', $arr, $full_info);

		return $arr;

	}

	function backup_resume($resumption_no, $bnonce) {

		// 15 minutes
		@set_time_limit(900);

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
		$this->log("Backup run: resumption=$resumption_no, nonce=$bnonce, begun at=$btime (${time_ago}s ago), job type=$job_type".$resumption_extralog);

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval<$this->minimum_resume_interval()) $resume_interval = $this->minimum_resume_interval();

		// We just do this once, as we don't want to be in permanent conflict with the overlap detector
		if ($resumption_no == 8) {
			$max_time = 0;
			// $time_passed is set earlier
			$timings_string = "";
			$run_times_known=0;
			for ($i=0; $i<=7; $i++) {
				$timings_string .= "$i:";
				if (isset($time_passed[$i])) {
					$timings_string .=  round($time_passed[$i], 1).' ';
					$run_times_known++;
					if ($time_passed[$i] > $max_time) $max_time = round($time_passed[$i]);
				} else {
					$timings_string .=  '? ';
				}
			}
			$this->log("Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time)");
			if ($run_times_known >= 6 && ($max_time + 35 < $resume_interval)) {
				$resume_interval = round($max_time + 35);
				$this->log("Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds");
				$this->jobdata_set('resume_interval', $resume_interval);
			}
		}

		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no+1;
		if ($next_resumption < 10) {
			$schedule_for = time()+$resume_interval;
			$this->log("Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for) in case this run gets aborted");
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $bnonce));
			$this->newresumption_scheduled = $schedule_for;
		} else {
			$this->log(sprintf('The current run is resumption number %d - will not schedule a further attempt until we see something useful happening', $resumption_no));
		}

		// Sanity check
		if (empty($this->backup_time)) {
			$this->log('Abort this run: the backup_time parameter appears to be empty (this is usually caused by resuming an already-complete backup, or by your site having a faulty object cache active (e.g. W3 Total Cache\'s object cache))');
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
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_transient("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) $this->jobdata = array();
		}
		$this->jobdata[$key] = $value;
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
		// 15 minutes
		@set_time_limit(900);

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
		do_action('updraftplus_boot_backup');

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
			$final_message = __("The backup apparently succeeded and is now complete",'updraftplus');
		} elseif ($this->newresumption_scheduled == false) {
			$send_an_email = true;
			$final_message = __("The backup attempt has finished, apparently unsuccessfully",'updraftplus');
		} else {
			// There are errors, but a resumption will be attempted
			$final_message = __("The backup has not finished; a resumption is scheduled within 5 minutes",'updraftplus');
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
		add_action('http_api_curl', array($this, 'add_curl_capath'));
		if (file_exists($method_include)) require_once($method_include);
		remove_action('http_api_curl', array($this, 'add_curl_capath'));

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

	function increase_resume_and_reschedule($howmuch = 120, $force_schedule = false) {

		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval < $this->minimum_resume_interval()) $resume_interval = $this->minimum_resume_interval();

		if (empty($this->newresumption_scheduled) && $force_schedule) {
			$this->log("A new resumption will be scheduled to prevent the job ending");
		}

		if (!empty($this->newresumption_scheduled) || $force_schedule) $this->reschedule($resume_interval+$howmuch);
		$this->jobdata_set('resume_interval', $resume_interval+$howmuch);

		$this->log("To decrease the likelihood of overlaps, increasing resumption interval to: $resume_interval + $howmuch = ".($resume_interval+$howmuch));
	}

	// For detecting another run, and aborting if one was found
	function check_recent_modification($file) {
		if (file_exists($file)) {
			$time_mod = (int)@filemtime($file);
			$time_now = time();
			if ($time_mod>100 && ($time_now-$time_mod)<30) {
				$this->terminate_due_to_activity($file, $time_now, $time_mod);
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

		// Bring in all the zip routines
		require_once(UPDRAFTPLUS_DIR.'/backup.php');
		global $updraftplus_backup;

		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',substr(get_bloginfo(), 0, 96));
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if(!$blog_name) $blog_name = 'non_alpha_name';

		$backup_file_basename = 'backup_'.get_date_from_gmt(gmdate('Y-m-d H:i:s', $this->backup_time), 'Y-m-d-Hi').'_'.$blog_name.'_'.$this->nonce;

		$backup_array = array();

		$possible_backups = $this->get_backupable_file_entities(false);

		# Plugins, themes, uploads
		foreach ($possible_backups as $youwhat => $whichdir) {

			// TODO: Should store what is wanted in the job description
			if (UpdraftPlus_Options::get_updraft_option("updraft_include_$youwhat", apply_filters("updraftplus_defaultoption_include_$youwhat", true))) {

				$zip_file = $updraft_dir.'/'.$backup_file_basename.'-'.$youwhat.'.zip';

				$this->check_recent_modification($zip_file);

				if ($transient_status == 'finished') {
					$backup_array[$youwhat] = $backup_file_basename.'-'.$youwhat.'.zip';
					if (file_exists($zip_file)) $backup_array[$youwhat.'-size'] = filesize($zip_file);
				} else {
					# Apply a filter to allow add-ons to provide their own method for creating a zip of the entity
					$created = apply_filters('updraftplus_backup_makezip_'.$youwhat, $whichdir, $backup_file_basename);
					# If the filter did not lead to something being created, then use the default method
					if ($created == $whichdir) {
						$created = $updraftplus_backup->create_zip($whichdir, $youwhat, $updraft_dir, $backup_file_basename);
					}
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

				# Create an array of directories to be skipped
				$others_skip = preg_split("/,/",UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude', UPDRAFT_DEFAULT_OTHERS_EXCLUDE));
				# Make the values into the keys
				$others_skip = array_flip($others_skip);

				$possible_backups_dirs = array_flip($possible_backups);

				$other_dirlist = $this->compile_folder_list_for_backup(WP_CONTENT_DIR, $possible_backups_dirs, $others_skip);

				if (count($other_dirlist)>0) {
					$created = $updraftplus_backup->create_zip($other_dirlist, 'others', $updraft_dir, $backup_file_basename);
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

	// avoid_these_dirs and skip_these_dirs ultimately do the same thing; but avoid_these_dirs takes full paths whereas skip_these_dirs takes basenames; and they are logged differently (avoid is potentially dangerous; skip is just a preference). They are allowed to overlap.
	function compile_folder_list_for_backup($backup_from_inside_dir, $avoid_these_dirs, $skip_these_dirs) {

		$dirlist = array(); 

		$this->log('Looking for candidates to back up in: '.$backup_from_inside_dir);

		$updraft_dir = $this->backups_dir_location();
		if ($handle = opendir($backup_from_inside_dir)) {
		
			while (false !== ($entry = readdir($handle))) {
				$candidate = $backup_from_inside_dir.'/'.$entry;
				if ($entry != "." && $entry != "..") {
					if (isset($avoid_these_dirs[$candidate])) {
						$this->log("finding files: $entry: skipping: this is the ".$avoid_these_dirs[$candidate]." directory");
					} elseif ($candidate == $updraft_dir) {
						$this->log("finding files: $entry: skipping: this is the updraft directory");
					} elseif (isset($skip_these_dirs[$entry])) {
						$this->log("finding files: $entry: skipping: excluded by options");
					} else {
						$this->log("finding files: $entry: adding to list");
						array_push($dirlist, $candidate);
					}
				}
			}
			@closedir($handle);
		} else {
			$this->log('ERROR: Could not read the directory: '.$backup_from_inside_dir);
			$this->error(__('Could not read the directory', 'updraftplus').': '.$backup_from_inside_dir);
		}

		return $dirlist;

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

		@include(ABSPATH.'wp-includes/version.php');
		global $wp_version;

		$this->stow("# WordPress MySQL database backup\n");
		$this->stow("# Created by UpdraftPlus version ".$this->version." (http://updraftplus.com)\n");
		$this->stow("# WordPress Version: $wp_version, running on PHP ".phpversion()." (".$_SERVER["SERVER_SOFTWARE"].")\n");
		$this->stow("# Backup of: ".site_url()."\n");

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

	// The purpose of this function is to make sure that the options table is put in the database first, then the usermeta table
	function backup_db_sorttables($a, $b) {
		global $table_prefix;
		if ($a == $b) return 0;
		if ($a == $table_prefix.'options') return -1;
		if ($b ==  $table_prefix.'options') return 1;
		if ($a == $table_prefix.'users') return -1;
		if ($b ==  $table_prefix.'users') return 1;
		if ($a == $table_prefix.'usermeta') return -1;
		if ($b ==  $table_prefix.'usermeta') return 1;
		return strcmp($a, $b);
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

		// Put the options table first
		usort($all_tables, array($this, 'backup_db_sorttables'));

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
			$this->terminate_due_to_activity($backup_final_file_name, $time_now, $time_mod);
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

	function terminate_due_to_activity($file, $time_now, $time_mod) {
		$file_size = filesize($file);
		$this->log("Terminate: the final database file ($file) exists, and was modified within the last 20 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=$file_size). This likely means that another UpdraftPlus run is at work; so we will exit.");
		$this->increase_resume_and_reschedule(120, true);
		die;
	}

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
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE `$table`", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if (false === $table_structure) {
				$err_msg = sprintf('Error getting table structure of %s', $table);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n#\n# " . sprintf('Data contents of table %s',$this->backquote($table)) . "\n");

			$table_status = $wpdb->get_row("SHOW TABLE STATUS WHERE Name='$table'");
			if (isset($table_status->Rows)) {
				$rows = $table_status->Rows;
				$this->log("Table $table: Total expected rows (approximate): ".$rows);
				$this->stow("# Approximate rows expected in table: $rows\n");
				if ($rows > 500000) {
					$this->log("Table $table: $rows is very many rows - we hope your web hosting company gives you enough resources to dump out that table in the backup");
				}
			}

			$this->stow("#\n\n");

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
				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES ';
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					$thisentry = "";
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
						if ($thisentry) $thisentry .= ",\n ";
						$thisentry .= '('.implode(', ', $values).')';
						// Flush every 512Kb
						if (strlen($thisentry) > 524288) {
							$this->stow(" \n".$entries.$thisentry.';');
							$thisentry = "";
						}
						
					}
					if ($thisentry) $this->stow(" \n".$entries.$thisentry.';');
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
		if (count($this->errors) == 0) $this->log("An error condition has occurred for the first time during this job");
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

	function deactivation () {
		wp_clear_scheduled_hook('updraftplus_weekly_ping');
	}

	// Acts as a WordPress options filter
	function googledrive_clientid_checkchange($client_id) {
		if (UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token') != '' && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid') != $client_id) {
			require_once(UPDRAFTPLUS_DIR.'/methods/googledrive.php');
			add_action('http_api_curl', array($this, 'add_curl_capath'));
			UpdraftPlus_BackupModule_googledrive::gdrive_auth_revoke(true);
			remove_action('http_api_curl', array($this, 'add_curl_capath'));

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

	function spool_file($type, $fullpath, $encryption = "") {

		@set_time_limit(900);

		if (file_exists($fullpath)) {

			$file = basename($fullpath);

			$len = filesize($fullpath);

			$filearr = explode('.',$file);
	// 			//we've only got zip and gz...for now
			$file_ext = array_pop($filearr);
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
			header("Content-Length: $len;");

			if ($file_ext == 'crypt') {
				if ($encryption == "") $encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
				if ($encryption == "") {
					header('Content-type: text/plain');
					_e("Decryption failed. The database file is encrypted, but you have no encryption key entered.",'updraftplus');
					$this->error('Decryption of database failed: the database file is encrypted, but you have no encryption key entered.');
				} else {
					require_once(UPDRAFTPLUS_DIR.'/includes/phpseclib/Crypt/Rijndael.php');
					$rijndael = new Crypt_Rijndael();
					$rijndael->setKey($encryption);
					$ciphertext = $rijndael->decrypt(file_get_contents($fullpath));
					if ($ciphertext) {
						header('Content-type: application/x-gzip');
						header("Content-Disposition: attachment; filename=\"".substr($file,0,-6)."\";");
						print $ciphertext;
					} else {
						header('Content-type: text/plain');
						echo __("Decryption failed. The most likely cause is that you used the wrong key.",'updraftplus')." ".__('The decryption key used:','updraftplus').' '.$encryption;
						
					}
				}
			} else {
				if ($file_ext == 'zip') {
					header('Content-type: application/zip');
				} else {
					header('Content-type: application/x-gzip');
				}
				header("Content-Disposition: attachment; filename=\"$file\";");
				readfile($fullpath);
			}
// 			$this->delete_local($file);
		} else {
			echo __('File not found', 'updraftplus');
		}
	}

	function retain_range($input) {
		$input = (int)$input;
		return  ($input > 0 && $input < 3650) ? $input : 1;
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

	function memory_check($memory) {
		$memory_limit = $this->memory_check_current();
		return ($memory_limit >= $memory)?true:false;
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

			return __('Find UpdraftPlus useful?','updraftplus').' '.$this->url_start($urls,'david.dw-perspective.org.uk/donate').__("Please make a donation", 'updraftplus').$this->url_end($urls,'david.dw-perspective.org.uk/donate');
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

}


?>
