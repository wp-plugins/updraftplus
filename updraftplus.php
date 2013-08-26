<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: take backups locally, or backup to Amazon S3, Dropbox, Google Drive, Rackspace, (S)FTP, WebDAV & email, on automatic schedules.
Author: UpdraftPlus.Com, DavidAnderson
Version: 1.7.3
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Text Domain: updraftplus
Author URI: http://updraftplus.com
*/

/*
TODO - some of these are out of date/done, needs pruning
// Remember minimum resume intervals in between runs
// Backup notes
// Raise a warning for probably-too-large email attachments
// mysqldump, if available, for faster database dumps. Need then to test compatibility with max_packet_size detection in restoration
// Check flow of activation on multisite
// Find a faster encryption method
// Provide option to make autobackup default to off
// On restore, raise a warning for ginormous zips
// Detect double-compressed files when they are uploaded (need a way to detect gz compression in general)
// Log migrations/restores, and have an option for auto-emailing the log
// Log a warning if the resumption is loonnggg after it was expected to be (usually on unvisited dev sites)
# Email backup method should be able to force split limit down to something manageable - or at least, should make the option display. (Put it in email class. Tweak the storage dropdown to not hide stuff also in expert class if expert is shown).
// Remember historical resumption intervals. But remember that the site may migrate, so we need to check their accuracy from time to time. Use transient?
// What happens if you restore with a database that then changes the setting for updraft_dir ? Should be safe, as the setting is cached during a run: double-check.
// Look at how the updraft dir is chosen... abspath+wp-content/updraft is not the best universal default
// Import/slurp backups from other sites. See: http://www.skyverge.com/blog/extending-the-wordpress-xml-rpc-api/
// More sophisticated options for retaining/deleting (e.g. 4/day for X days, then 7/week for Z weeks, then 1/month for Y months)
// Unpack zips via AJAX? Do bit-by-bit to allow enormous opens a better chance? (have a huge one in Dropbox)
// Put in a maintenance-mode detector
// Detect CloudFlare output in attempts to connect - detecting cloudflare.com should be sufficient
// Show 'Migrate' instead of 'Restore' on the button if relevant
// Bring multisite shop page up to date
// Re-do pricing + support packages
// Option to create S3 bucket with RRS
// More files: back up multiple directories, not just one
// Give a help page to go with the message: A zip error occurred - check your log for more details (reduce support requests)
// Exclude .git and .svn by default from wpcore
// Restore-console not showing proper time-zone?
// Add option to add, not just replace entities on restore/migrate
// Warnings of unreadable files upon PclZip fall-back may need to be more visible - test
// Add warning to backup run at beginning if -old dirs exist
// Auto-alert if disk usage passes user-defined threshold / or an automatically computed one. Auto-alert if more backups are known than should be (usually a sign of incompleteness). Actually should just delete unknown backups over a certain age.
// Generic S3 provider: add page to site. S3-compatible storage providers: http://www.dragondisk.com/s3-storage-providers.html
// Importer - import backup sets from another WP site directly via HTTP
// Option to create new user for self post-restore
// Auto-disable certain cacheing/minifying plugins post-restore
// Enhance Google Drive support to not require registration, and to allow offline auth
// Add note post-DB backup: you will need to log in using details from newly-imported DB
// Make search+replace two-pass to deal with moving between exotic non-default moved-directory setups
// Get link - http://www.rackspace.com/knowledge_center/article/how-to-use-updraftplus-to-back-up-cloud-sites-to-cloud-files
// 'Delete from your webserver' should trigger a rescan if the backup was local-only
// Notify user only if backup fails
// Log file SHA1 after finishing creation
// Add more info to email - e.g. names + sizes + checksums of uploads + locations
// Encrypt archives - http://www.frostjedi.com/phpbb3/viewtopic.php?f=46&t=168508&p=391881&e=391881
// Option for additive restores - i.e. add content (themes, plugins,...) instead of replacing
// Testing framework - automated testing of all file upload / download / deletion methods
// Though Google Drive code users WP's native HTTP functions, it seems to not work if WP natively uses something other than curl
// Ginormous tables - need to make sure we "touch" the being-written-out-file (and double-check that we check for that) every 15 seconds - https://friendpaste.com/697eKEcWib01o6zT1foFIn
// With ginormous tables, log how many times they've been attempted: after 3rd attempt, log a warning and move on. But first, batch ginormous tables (resumable)
// Import single site into a multisite: http://codex.wordpress.org/Migrating_Multiple_Blogs_into_WordPress_3.0_Multisite, http://wordpress.org/support/topic/single-sites-to-multisite?replies=5, http://wpmu.org/import-export-wordpress-sites-multisite/
// Dropbox authentication broken by other plugins' debug output - find a way to alert user.
// Add note in FAQs about 'maintenance mode' plugins
// Selective restores - some resources
// Automatically de-activate cacheing/minifying plugins upon restore (and inform user) to prevent unexpected clashes
// After restoring the themes, should check to see if the currently-active one still exists or not
// When you migrate/restore, if there is a .htaccess, warn/give option about it.
// 'Show log' should be done in a nice pop-out, with a button to download the raw
// delete_old_dirs() needs to use WP_Filesystem in a more user-friendly way when errors occur
// Bulk download of entire set at once (not have to click 7 times).
// Restoration should also clear all common cache locations (or just not back them up)
// Deal with gigantic database tables - e.g. those over a million rows on cheap hosting.
// When restoring core, need an option to retain database settings / exclude wp-config.php
// If migrating, warn about consequences of over-writing wp-config.php
// Produce a command-line version of the restorer (so that people with shell access are immune from server-enforced timeouts)
// Restorations should be logged also
// Migrator - list+download from remote, kick-off backup remotely
// April 20, 2015: This is the date when the Google Documents API is likely to stop working (https://developers.google.com/google-apps/documents-list/terms)
// Search for other TODO-s in the code
// Opt-in non-personal stats + link to aggregated results
// Stand-alone installer - take a look at this: http://wordpress.org/extend/plugins/duplicator/screenshots/
// More DB add-on (other non-WP tables; even other databases)
// Unlimited customers should be auto-emailed each time they add a site (security)
// Update all-features page at updraftplus.com (not updated after 1.5.5)
// Add in downloading in the 'Restore' modal, and remove the advice to do so manually.
// Save database encryption key inside backup history on per-db basis, so that if it changes we can still decrypt
// Switch to Google Drive SDK. Google folders. https://developers.google.com/drive/folder
// Convert S3.php to use WP's native HTTP functions
// AJAX-ify restoration
// Ability to re-scan existing cloud storage
// Dropbox uses one mcrypt function - port to phpseclib for more portability
// Store meta-data on which version of UD the backup was made with (will help if we ever introduce quirks that need ironing)
// Test restoration when uploads dir is /assets/ (e.g. with Shoestrap theme)
// Send the user an email upon their first backup with tips on what to do (e.g. support/improve) (include legacy check to not bug existing users)
// GoogleDrive +Rackspace folders
//Do an automated test periodically for the success of loop-back connections
//When a manual backup is run, use a timer to update the 'Download backups and logs' section, just like 'Last finished backup run'. Beware of over-writing anything that's in there from a resumable downloader.
//Change DB encryption to not require whole gzip in memory (twice)
//Add Box.Net, SugarSync, Me.Ga support??
//Make it easier to find add-ons
//The restorer has a hard-coded wp-content - fix
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
// Create single zip, containing even WordPress itself
// Remove the recurrence of admin notices when settings are saved due to _wp_referer

Encrypt filesystem, if memory allows (and have option for abort if not)
// New sub-module to verify that the backups are there, independently of backup thread
*/

/*
Portions copyright 2011-13 David Anderson
Portions copyright 2010 Paul Kehrer
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
define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,backup*');

# The following can go in your wp-config.php
if (!defined('UPDRAFTPLUS_ZIP_EXECUTABLE')) define('UPDRAFTPLUS_ZIP_EXECUTABLE', "/usr/bin/zip,/bin/zip,/usr/local/bin/zip,/usr/sfw/bin/zip,/usr/xdg4/bin/zip,/opt/bin/zip");
# If any individual file size is greater than this, then a warning is given
if (!defined('UPDRAFTPLUS_WARN_FILE_SIZE')) define('UPDRAFTPLUS_WARN_FILE_SIZE', 1024*1024*250);
# On a test on a Pentium laptop, 100,000 rows needed ~ 1 minute to write out - so 150,000 is around the CPanel default of 90 seconds execution time.
if (!defined('UPDRAFTPLUS_WARN_DB_ROWS')) define('UPDRAFTPLUS_WARN_DB_ROWS', 150000);

# The smallest value (in megabytes) that the "split zip files at" setting is allowed to be set to
if (!defined('UPDRAFTPLUS_SPLIT_MIN')) define('UPDRAFTPLUS_SPLIT_MIN', 100);

// This is used in various places, based on our assumption of the maximum time any job should take. May need lengthening in future if we get reports which show enormous sets hitting the limit.
// Also one section requires at least 1% progress each run, so on a 5-minute schedule, that equals just under 9 hours - then an extra allowance takes it just over. (However these days, we reduce the scheduling time if possible, so we get more attempts).
define('UPDRAFT_TRANSTIME', 3600*12);

// Load add-ons and various files that may or may not be present, depending on where the plugin was distributed
if (is_file(UPDRAFTPLUS_DIR.'/premium.php')) require_once(UPDRAFTPLUS_DIR.'/premium.php');
if (is_file(UPDRAFTPLUS_DIR.'/autoload.php')) require_once(UPDRAFTPLUS_DIR.'/autoload.php');
if (is_file(UPDRAFTPLUS_DIR.'/udaddons/updraftplus-addons.php')) include_once(UPDRAFTPLUS_DIR.'/udaddons/updraftplus-addons.php');

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
	# Add sanity checks - found someone who'd set WP_MAX_MEMORY_LIMIT to 256K !
	if (!$updraftplus->memory_check($updraftplus->memory_check_current(WP_MAX_MEMORY_LIMIT))) {
		$new = absint($updraftplus->memory_check_current(WP_MAX_MEMORY_LIMIT));
		if ($new>32 && $new<100000) {
			@ini_set('memory_limit', $new.'M'); //up the memory limit to the maximum WordPress is allowing for large backup files
		}
	}
}

if (!class_exists('UpdraftPlus_Options')) require_once(UPDRAFTPLUS_DIR.'/options.php');

class UpdraftPlus {

	public $version;

	public $plugin_title = 'UpdraftPlus Backup/Restore';

	// Choices will be shown in the admin menu in the order used here
	public $backup_methods = array (
		"s3" => "Amazon S3",
		"dropbox" => "Dropbox",
		'cloudfiles' => 'Rackspace Cloud Files',
		"googledrive" => "Google Drive",
		"ftp" => "FTP",
		'sftp' => 'SFTP',
		'webdav' => 'WebDAV',
		's3generic' => 'S3-Compatible (Generic)',
		'dreamobjects' => 'DreamObjects',
		"email" => "Email"
	);

	public $errors = array();
	public $nonce;
	public $logfile_name = "";
	public $logfile_handle = false;
	public $backup_time;
	public $backup_time_ms;

	public $opened_log_time;
	private $backup_dir;

	private $jobdata;

	public $something_useful_happened = false;

	// Used to schedule resumption attempts beyond the tenth, if needed
	public $current_resumption;
	private $newresumption_scheduled = false;

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
		# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules. Raised priority because some plugins wrongly over-write all prior schedule changes (including BackupBuddy!)
		add_filter('cron_schedules', array($this,'modify_cron_schedules'), 30);
		add_action('plugins_loaded', array($this, 'load_translations'));

		register_deactivation_hook(__FILE__, array($this, 'deactivation'));

	}

	public function ensure_phpseclib($class = false, $class_path = false) {
		if ($class && class_exists($class)) return;
		if (false === strpos(get_include_path(), UPDRAFTPLUS_DIR.'/includes/phpseclib')) set_include_path(get_include_path().PATH_SEPARATOR.UPDRAFTPLUS_DIR.'/includes/phpseclib');
		if ($class_path) require_once(UPDRAFTPLUS_DIR.'/includes/phpseclib/'.$class_path.'.php');
	}

	public function admin_init() {
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

	public function add_curl_capath($handle) {
		if (!UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')) curl_setopt($handle, CURLOPT_CAINFO, UPDRAFTPLUS_DIR.'/includes/cacert.pem' );
	}

	// Handle actions passed on to method plugins; e.g. Google OAuth 2.0 - ?page=updraftplus&action=updraftmethod-googledrive-auth
	// Also handle action=downloadlog
	public function handle_url_actions() {

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
				$spool_file = $updraft_dir.'/'.basename($_GET['updraftplus_file']);
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

	// Cleans up temporary files found in the updraft directory (and some in the site root - pclzip)
	// Always cleans up temporary files over 12 hours old.
	// With parameters, also cleans up those.
	function clean_temporary_files($match = '', $older_than = 43200) {
		$updraft_dir = $this->backups_dir_location();
		$now_time=time();
		if ($handle = opendir($updraft_dir)) {
			while (false !== ($entry = readdir($handle))) {
				// This match is for files created internally by zipArchive::addFile
				$ziparchive_match = preg_match("/$match([0-9]+)?\.zip\.tmp\.([A-Za-z0-9]){6}?$/i", $entry);
				// zi followed by 6 characters is the pattern used by /usr/bin/zip on Linux systems. It's safe to check for, as we have nothing else that's going to match that pattern.
				$binzip_match = preg_match("/^zi([A-Za-z0-9]){6}$/", $entry);
				if ((preg_match("/$match\.tmp(\.gz)?$/i", $entry) || $ziparchive_match || $binzip_match) && is_file($updraft_dir.'/'.$entry)) {
					// We delete if a parameter was specified (and either it is a ZipArchive match or an order to delete of whatever age), or if over 12 hours old
					if (($match && ($ziparchive_match || $binzip_match || 0 == $older_than) && $now_time-filemtime($updraft_dir.'/'.$entry) >= $older_than) || $now_time-filemtime($updraft_dir.'/'.$entry)>43200) {
						$this->log("Deleting old temporary file: $entry");
						@unlink($updraft_dir.'/'.$entry);
					}
				}
			}
			@closedir($handle);
		}
		# Depending on the PHP setup, the current working directory could be ABSPATH or wp-admin - scan both
		foreach (array(ABSPATH, ABSPATH.'wp-admin/') as $path) {
			if ($handle = opendir($path)) {
				while (false !== ($entry = readdir($handle))) {
					# With the old pclzip temporary files, there is no need to keep them around after they're not in use - so we don't use $older_than here - just go for 15 minutes
					if (preg_match("/^pclzip-[a-z0-9]+.tmp$/", $entry) && $now_time-filemtime($path.$entry) >= 900) {
						$this->log("Deleting old PclZip temporary file: $entry");
						@unlink($path.$entry);
					}
				}
				@closedir($handle);
			}
		}
	}

	public function backup_time_nonce() {
		$this->backup_time_ms = microtime(true);
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
		@include(ABSPATH.'wp-includes/version.php');

		// Will need updating when WP stops being just plain MySQL
		$mysql_version = (function_exists('mysql_get_server_info')) ? mysql_get_server_info() : '?';

		$safe_mode = $this->detect_safe_mode();

		$memory_usage = round(@memory_get_usage(false)/1048576, 1);
		$logline = "UpdraftPlus: ".$this->version." WP: ".$wp_version." PHP: ".phpversion()." (".php_uname().") MySQL: $mysql_version Server: ".$_SERVER["SERVER_SOFTWARE"]." safe_mode: $safe_mode max_execution_time: ".@ini_get("max_execution_time")." memory_limit: ".ini_get('memory_limit')." (used: ${memory_usage}M) ZipArchive::addFile : ";

		// method_exists causes some faulty PHP installations to segfault, leading to support requests
		if (version_compare(phpversion(), '5.2.0', '>=') && extension_loaded('zip')) {
			$logline .= 'Y';
		} else {
			$logline .= (method_exists('ZipArchive', 'addFile')) ? "Y" : "N";
		}
		$this->log($logline);
		$disk_free_space = @disk_free_space($updraft_dir);
		if ($disk_free_space === false) {
			$this->log("Free space on disk containing Updraft's temporary directory: Unknown");
		} else {
			$this->log("Free space on disk containing Updraft's temporary directory: ".round($disk_free_space/1048576,1)." Mb");
			if ($disk_free_space < 50*1048576) $this->log(sprintf(__('Your free disk space is very low - only %s Mb remain', 'updraftplus'), round($disk_free_space/1048576, 1)), 'warning');
		}
	}

	/* Logs the given line, adding (relative) time stamp and newline
	Note these subtleties of log handling:
	- Messages at level 'error' are not logged to file - it is assumed that a separate call to log() at another level will take place. This is because at level 'error', messages are translated; whereas the log file is for developers who may not know the translated language. Messages at level 'error' are for the user.
	- Messages at level 'error' do not persist through the job (they are only saved with save_backup_history(), and never restored from there - so only the final save_backup_history() errors persist); we presume that either a) they will be cleared on the next attempt, or b) they will occur again on the final attempt (at which point they will go to the user). But...
	- ... messages at level 'warning' persist. These are conditions that are unlikely to be cleared, not-fatal, but the user should be informed about. The $uniq_id field (which should not be numeric) can then be used for warnings that should only be logged once
	*/
	public function log($line, $level = 'notice', $uniq_id = false) {

		if ('error' == $level || 'warning' == $level) {
			if ('error' == $level && $this->error_count() == 0) $this->log("An error condition has occurred for the first time during this job");
			$this->errors[] = array('level' => $level, 'message' => $line);
			# Errors are logged separately
			if ('error' == $level) return;
			# It's a warning
			$warnings = $this->jobdata_get('warnings');
			if (!is_array($warnings)) $warnings=array();
			if ($uniq_id) {
				$warnings[$uniq_id] = $line;
			} else {
				$warnings[] = $line;
			}
			$this->jobdata_set('warnings', $warnings);
		}

		if ($this->logfile_handle) {
			# Record log file times relative to the backup start, if possible
			$rtime = (!empty($this->backup_time_ms)) ? microtime(true)-$this->backup_time_ms : microtime(true)-$this->opened_log_time;
			fwrite($this->logfile_handle, sprintf("%08.03f", round($rtime, 3))." (".$this->current_resumption.") $line\n");
		}
		if ('download' == $this->jobdata_get('job_type')) {
			// Download messages are keyed on the job (since they could be running several), and transient
			// The values of the POST array were checked before
			$findex = (!empty($_POST['findex'])) ? $_POST['findex'] : 0;
			set_transient('ud_dlmess_'.$_POST['timestamp'].'_'.$_POST['type'].'_'.$findex, $line." (".date('M d H:i:s').")", 3600);
		} else {
			if ($level != 'debug') UpdraftPlus_Options::update_updraft_option('updraft_lastmessage', $line." (".date_i18n('M d H:i:s').")");
		}
		if (defined('UPDRAFTPLUS_CONSOLELOG')) print $line."\n";
		if (defined('UPDRAFTPLUS_BROWSERLOG')) print htmlentities($line)."\n";
	}

	// This function is used by cloud methods to provide standardised logging, but more importantly to help us detect that meaningful activity took place during a resumption run, so that we can schedule further resumptions if it is worthwhile
	public function record_uploaded_chunk($percent, $extra, $file_path = false) {

		// Touch the original file, which helps prevent overlapping runs
		if ($file_path) touch($file_path);

		// Log it
		$service = $this->jobdata_get('service');
		$log = ucfirst($service)." chunked upload: $percent % uploaded";
		if ($extra) $log .= " ($extra)";
		$this->log($log);
		// If we are on an 'overtime' resumption run, and we are still meaningfully uploading, then schedule a new resumption
		// Our definition of meaningful is that we must maintain an overall average of at least 0.7% per run, after allowing 9 runs for everything else to get going
		// i.e. Max 100/.7 + 9 = 150 runs = 760 minutes = 12 hrs 40, if spaced at 5 minute intervals. However, our algorithm now decreases the intervals if it can, so this should not really come into play
		// If they get 2 minutes on each run, and the file is 1Gb, then that equals 10.2Mb/120s = minimum 59Kb/s upload speed required

		// What this means in effect is that at least one of the files touched during the run must reach this percentage (so lapping round from 100 is OK)
		if ($percent > 0.7 * ( $this->current_resumption - 9)) {
			$this->something_useful_happened();
		}
	}

	function detect_safe_mode() {
		return (@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") ? 1 : 0;
	}

	# We require -@ and -u -r to work - which is the usual Linux binzip
	function find_working_bin_zip($logit = true) {
		if ($this->detect_safe_mode()) return false;
		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if (!function_exists('popen') || !function_exists('proc_open')) return false;
		$updraft_dir = $this->backups_dir_location();
		foreach (explode(',', UPDRAFTPLUS_ZIP_EXECUTABLE) as $potzip) {
			if ($logit) $this->log("Testing: $potzip");
			if (@is_executable($potzip)) {
				# Test it, see if it is compatible with Info-ZIP
				# If you have another kind of zip, then feel free to tell me about it
				@mkdir($updraft_dir.'/binziptest/subdir1/subdir2', 0777, true);
				file_put_contents($updraft_dir.'/binziptest/subdir1/subdir2/test.html', '<html></body><a href="http://updraftplus.com">UpdraftPlus is a great backup and restoration plugin for WordPress.</body></html>');
				@unlink($updraft_dir.'/binziptest/test.zip');
				if (is_file($updraft_dir.'/binziptest/subdir1/subdir2/test.html')) {

					$exec = "cd ".escapeshellarg($updraft_dir)."; $potzip -v -u -r binziptest/test.zip binziptest/subdir1";

					$all_ok=true;
					$handle = popen($exec, "r");
					if ($handle) {
						while (!feof($handle)) {
							$w = fgets($handle);
							if ($w && $logit) $this->log("Output: ".trim($w));
						}
						$ret = pclose($handle);
						if ($ret !=0) {
							if ($logit) $this->log("Binary zip: error (code: $ret)");
							$all_ok = false;
						}
					} else {
						if ($logit) $this->log("Error: popen failed");
						$all_ok = false;
					}

					# Now test -@
					if (true == $all_ok) {
						file_put_contents($updraft_dir.'/binziptest/subdir1/subdir2/test2.html', '<html></body><a href="http://updraftplus.com">UpdraftPlus is a really great backup and restoration plugin for WordPress.</body></html>');
						
						$exec = $potzip." -v -@ binziptest/test.zip";

						$all_ok=true;

						$descriptorspec = array(
							0 => array('pipe', 'r'),
							1 => array('pipe', 'w'),
							2 => array('pipe', 'w')
						);
						$handle = proc_open($exec, $descriptorspec, $pipes, $updraft_dir);
						if (is_resource($handle)) {
							if (!fwrite($pipes[0], "binziptest/subdir1/subdir2/test2.html\n")) {
								@fclose($pipes[0]);
								@fclose($pipes[1]);
								@fclose($pipes[2]);
								$all_ok = false;
							} else {
								fclose($pipes[0]);
								while (!feof($pipes[1])) {
									$w = fgets($pipes[1]);
									if ($w && $logit) $this->log("Output: ".trim($w));
								}
								fclose($pipes[1]);
								
								while (!feof($pipes[2])) {
									$last_error = fgets($pipes[2]);
									if (!empty($last_error) && $logit) $this->log("Error output: ".trim($w));
								}
								fclose($pipes[2]);

								$ret = proc_close($handle);
								if ($ret !=0) {
									if ($logit) $this->log("Binary zip: error (code: $ret)");
									$all_ok = false;
								}

							}

						} else {
							if ($logit) $this->log("Error: proc_open failed");
							$all_ok = false;
						}

					}

					// Do we now actually have a working zip? Need to test the created object using PclZip
					// If it passes, then remove dirs and then return $potzip;
					$found_first = false;
					$found_second = false;
					if ($all_ok && file_exists($updraft_dir.'/binziptest/test.zip')) {
						if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
						$zip = new PclZip($updraft_dir.'/binziptest/test.zip');
						$foundit = 0;
						if (($list = $zip->listContent()) != 0) {
							foreach ($list as $obj) {
								if ($obj['filename'] && !empty($obj['stored_filename']) && 'binziptest/subdir1/subdir2/test.html' == $obj['stored_filename'] && $obj['size']==127) $found_first=true;
								if ($obj['filename'] && !empty($obj['stored_filename']) && 'binziptest/subdir1/subdir2/test2.html' == $obj['stored_filename'] && $obj['size']==134) $found_second=true;
							}
						}
					}
					$this->remove_binzip_test_files($updraft_dir);
					if ($found_first && $found_second) {
						if ($logit) $this->log("Working binary zip found: $potzip");
						return $potzip;
					}

				}
				$this->remove_binzip_test_files($updraft_dir);
			}
		}
		return false;
	}

	function remove_binzip_test_files($updraft_dir) {
		@unlink($updraft_dir.'/binziptest/subdir1/subdir2/test.html');
		@unlink($updraft_dir.'/binziptest/subdir1/subdir2/test2.html');
		@rmdir($updraft_dir.'/binziptest/subdir1/subdir2');
		@rmdir($updraft_dir.'/binziptest/subdir1');
		@unlink($updraft_dir.'/binziptest/test.zip');
		@rmdir($updraft_dir.'/binziptest');
	}

	// This function is purely for timing - we just want to know the maximum run-time; not whether we have achieved anything during it
	function record_still_alive() {
		// Update the record of maximum detected runtime on each run
		$time_passed = $this->jobdata_get('run_times');
		if (!is_array($time_passed)) $time_passed = array();

		$time_this_run = microtime(true)-$this->opened_log_time;
		$time_passed[$this->current_resumption] = $time_this_run;
		$this->jobdata_set('run_times', $time_passed);

		$resume_interval = $this->jobdata_get('resume_interval');
		if ($time_this_run + 30 > $resume_interval) {
			$new_interval = ceil($time_this_run + 30);
			set_transient('updraft_initial_resume_interval', (int)$new_interval, 8*86400);
			$this->log("The time we have been running (".round($time_this_run,1).") is approaching the resumption interval ($resume_interval) - increasing resumption interval to $new_interval");
			$this->jobdata_set('resume_interval', $new_interval);
		}

	}

	function something_useful_happened() {

		$this->record_still_alive();

		if (!$this->something_useful_happened) {
			$useful_checkin = $this->jobdata_get('useful_checkin');
			if (empty($useful_checkin) || $this->current_resumption > $useful_checkin) $this->jobdata_set('useful_checkin', $this->current_resumption);
		}

		$this->something_useful_happened = true;

		if ($this->current_resumption >= 9 && $this->newresumption_scheduled == false) {
			$this->log("This is resumption ".$this->current_resumption.", but meaningful activity is still taking place; so a new one will be scheduled");
			// We just use max here to make sure we get a number at all
			$resume_interval = max($this->jobdata_get('resume_interval'), 75);
			// Don't consult the minimum here
			// if (!is_numeric($resume_interval) || $resume_interval<300) { $resume_interval = 300; }
			$schedule_for = time()+$resume_interval;
			$this->newresumption_scheduled = $schedule_for;
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($this->current_resumption + 1, $this->nonce));
		} else {
			$this->reschedule_if_needed();
		}
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

		$this->current_resumption = $resumption_no;

		// 15 minutes
		@set_time_limit(900);

		@ignore_user_abort(true);
		// This is scheduled for 5 minutes after a backup job starts

		$runs_started = array();

		// Restore state
		$resumption_extralog = '';
		if ($resumption_no > 0) {
			$this->nonce = $bnonce;
			$this->backup_time = $this->jobdata_get('backup_time');
			$this->backup_time_ms = $this->jobdata_get('backup_time_ms');
			$this->logfile_open($bnonce);

			$runs_started = $this->jobdata_get('runs_started');
			if (!is_array($runs_started)) $runs_started=array();
			$time_passed = $this->jobdata_get('run_times');
			if (!is_array($time_passed)) $time_passed = array();
			$time_now = microtime(true);
			foreach ($time_passed as $run => $passed) {
				if (isset($runs_started[$run]) && $runs_started[$run] + $time_passed[$run] + 30 > $time_now) {
					$this->terminate_due_to_activity('check-in', round($time_now,1), round($runs_started[$run] + $time_passed[$run],1));
				}
			}

			$prev_resumption = $resumption_no - 1;
			if (isset($time_passed[$prev_resumption])) $resumption_extralog = ", previous check-in=".round($time_passed[$prev_resumption], 1)."s";

		}

		$runs_started[$resumption_no] = microtime(true);
		$this->jobdata_set('runs_started', $runs_started);

		// Import existing warnings. The purpose of this is so that when save_backup_history() is called, it has a complete set - because job data expires quickly, whilst the warnings of the last backup run need to persist
		$warnings = $this->jobdata_get('warnings');
		if (is_array($warnings)) {
			foreach ($warnings as $warning) {
				$this->errors[] = array('level' => 'warning', 'message' => $warning);
			}
		}

		$btime = $this->backup_time;
		$job_type = $this->jobdata_get('job_type');

		$updraft_dir = $this->backups_dir_location();

		$time_ago = time()-$btime;

		$this->log("Backup run: resumption=$resumption_no, nonce=$bnonce, begun at=$btime (${time_ago}s ago), job type=$job_type".$resumption_extralog);

		// This works round a bizarre bug seen in one WP install, where delete_transient and wp_clear_scheduled_hook both took no effect, and upon 'resumption' the entire backup would repeat.
		if ($resumption_no >= 1 && 'finished' == $this->jobdata_get('jobstatus')) {
			$this->log("Terminate: This backup job is already finished.");
			die;
		}

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval<300) $resume_interval = 300;

		// We just do this once, as we don't want to be in permanent conflict with the overlap detector
		if ($resumption_no == 8) {
			// $time_passed is set earlier
			list($max_time, $timings_string, $run_times_known) = $this->max_time_passed($time_passed, 7);
			$this->log("Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time)");
			// Remember that 30 seconds is used as the 'perhaps something is still running' detection threshold
			if ($run_times_known >= 6 && ($max_time + 38 < $resume_interval)) {
				$resume_interval = round($max_time + 38);
				$this->log("Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds");
				$this->jobdata_set('resume_interval', $resume_interval);
			}
		}

		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no+1;
		if ($next_resumption < 10) {
			if ($this->jobdata_get('one_shot') === true) {
				$this->log('We are in "one shot" mode - no resumptions will be scheduled');
			} else {
				$schedule_resumption = true;
			}
		} else {
			// We're in over-time - we only reschedule if something useful happened last time (used to be that we waited for it to happen this time - but that meant that transient errors, e.g. Google 400s on uploads, scuppered it all - we'd do better to have another chance
			$useful_checkin = $this->jobdata_get('useful_checkin');
			$last_resumption = $resumption_no-1;

			if (empty($useful_checkin) || $useful_checkin < $last_resumption) {
				$this->log(sprintf('The current run is resumption number %d, and there was nothing useful done on the last run (last useful run: %s) - will not schedule a further attempt until we see something useful happening this time', $resumption_no, $useful_checkin));
			} else {
				$schedule_resumption = true;
			}
		}
		if (isset($schedule_resumption)) {
			$schedule_for = time()+$resume_interval;
			$this->log("Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for) in case this run gets aborted");
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $bnonce));
			$this->newresumption_scheduled = $schedule_for;
		}

		// Sanity check
		if (empty($this->backup_time)) {
			$this->log('Abort this run: the backup_time parameter appears to be empty (this is usually caused by resuming an already-complete backup, or by your site having a faulty object cache active (e.g. W3 Total Cache\'s object cache))');
			return false;
		}

		global $updraftplus_backup;
		// Bring in all the zip routines
		if (!is_a($updraftplus_backup, 'UpdraftPlus_Backup')) require_once(UPDRAFTPLUS_DIR.'/backup.php');

		// This should be always called; if there were no files in this run, it returns us an empty array
		$backup_array = $updraftplus_backup->resumable_backup_of_files($resumption_no);

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

			$db_backup = $updraftplus_backup->backup_db($backup_database);
			if(is_array($our_files) && is_string($db_backup)) {
				$our_files['db'] = $db_backup;
			}

			if ($backup_database != 'encrypted') $this->jobdata_set('backup_database', 'finished');
		} elseif ('no' == $backup_database) {
			$this->log('No database backup - not part of this run');
		} else {
			$this->log("Unrecognised data when trying to ascertain if the database was backed up ($backup_database)");
		}

		// Save this to our history so we can track backups for the retain feature
		$this->log("Saving backup history");
		// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
		$this->save_backup_history($our_files);

		// Potentially encrypt the database if it is not already
		if (isset($our_files['db']) && !preg_match("/\.crypt$/", $our_files['db'])) {
			$our_files['db'] = $updraftplus_backup->encrypt_file($our_files['db']);
			// No need to save backup history now, as it will happen in a few lines time
			if (preg_match("/\.crypt$/", $our_files['db'])) $this->jobdata_set('backup_database', 'encrypted');
		}

		if (isset($our_files['db']) && file_exists($updraft_dir.'/'.$our_files['db'])) {
			$our_files['db-size'] = filesize($updraft_dir.'/'.$our_files['db']);
			$this->save_backup_history($our_files);
		}

		$backupable_entities = $this->get_backupable_file_entities(true);

		# Queue files for upload
		foreach ($our_files as $key => $files) {
			// Only continue if the stored info was about a dump
			if (!isset($backupable_entities[$key]) && $key != 'db') continue;
			if (is_string($files)) $files = array($files);
			foreach ($files as $findex => $file) {
				if ($this->is_uploaded($file)) {
					$this->log("$file: $key: This file has already been successfully uploaded");
				} elseif (is_file($updraft_dir.'/'.$file)) {
					$this->log("$file: $key: This file has not yet been successfully uploaded: will queue");
					$undone_files[$key.$findex] = $file;
				} else {
					$this->log("$file: $key: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem ($updraft_dir/$file)");
					$this->uploaded_file($file);
				}
			}
		}

		if (count($undone_files) == 0) {
			$this->log("Resume backup ($bnonce, $resumption_no): finish run");
			$this->log("There were no more files that needed uploading; backup job is complete");
			// No email, as the user probably already got one if something else completed the run
			$this->backup_finish($next_resumption, true, false, $resumption_no);
			return;
		} else {
			$this->log("Requesting upload of the files that have not yet been successfully uploaded (".count($undone_files).")");
			$updraftplus_backup->cloud_backup($undone_files);
		}

		$this->log("Resume backup ($bnonce, $resumption_no): finish run");
		if (is_array($our_files)) $this->save_last_backup($our_files);
		$this->backup_finish($next_resumption, true, true, $resumption_no);

	}

	function max_time_passed($time_passed, $upto) {
		$max_time = 0;
		$timings_string = "";
		$run_times_known=0;
		for ($i=0; $i<=$upto; $i++) {
			$timings_string .= "$i:";
			if (isset($time_passed[$i])) {
				$timings_string .=  round($time_passed[$i], 1).' ';
				$run_times_known++;
				if ($time_passed[$i] > $max_time) $max_time = round($time_passed[$i]);
			} else {
				$timings_string .=  '? ';
			}
		}
		return array($max_time, $timings_string, $run_times_known);
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
	private function jobdata_set_multi() {
		if (!is_array($this->jobdata)) $this->jobdata = array();

		$args = func_num_args();

		for ($i=1; $i<=$args/2; $i++) {
			$key = func_get_arg($i*2-2);
			$value = func_get_arg($i*2-1);
			$this->jobdata[$key] = $value;
		}
		if (!empty($this->nonce)) set_transient("updraft_jobdata_".$this->nonce, $this->jobdata, UPDRAFT_TRANSTIME);
	}

	public function jobdata_set($key, $value) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_transient("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) $this->jobdata = array();
		}
		$this->jobdata[$key] = $value;
		set_transient("updraft_jobdata_".$this->nonce, $this->jobdata, UPDRAFT_TRANSTIME);
	}

	public function jobdata_delete($key) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_transient("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) $this->jobdata = array();
		}
		unset($this->jobdata[$key]);
		set_transient("updraft_jobdata_".$this->nonce, $this->jobdata, UPDRAFT_TRANSTIME);
	}

	public function jobdata_get($key, $default = null) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_transient("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) return $default;
		}
		return (isset($this->jobdata[$key])) ? $this->jobdata[$key] : $default;
	}

	// This procedure initiates a backup run
	public function boot_backup($backup_files, $backup_database, $restrict_files_to_override = false, $one_shot = false, $service = false) {

		@ignore_user_abort(true);
		// 15 minutes
		@set_time_limit(900);

		//generate backup information
		$this->backup_time_nonce();
		$this->logfile_open($this->nonce);

		if (!is_file($this->logfile_name)) {
			$this->log('Failed to open log file ('.$this->logfile_name.') - you need to check your UpdraftPlus settings (your chosen directory for creating files in is not writable, or you ran out of disk space). Backup aborted.');
			$this->log(__('Could not create files in the backup directory. Backup aborted - check your UpdraftPlus settings.','updraftplus'), 'error');
			return false;
		}

		// Some house-cleaning
		$this->clean_temporary_files();
		do_action('updraftplus_boot_backup');

		// Log some information that may be helpful
		$this->log("Tasks: Backup files: $backup_files (schedule: ".UpdraftPlus_Options::get_updraft_option('updraft_interval', 'unset').") Backup DB: $backup_database (schedule: ".UpdraftPlus_Options::get_updraft_option('updraft_interval_database', 'unset').")");

		if (false === $one_shot) {
			# If the files and database schedules are the same, and if this the file one, then we rope in database too.
			# On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.
			if ('manual' != UpdraftPlus_Options::get_updraft_option('updraft_interval') && (UpdraftPlus_Options::get_updraft_option('updraft_interval') == UpdraftPlus_Options::get_updraft_option('updraft_interval_database') || UpdraftPlus_Options::get_updraft_option('updraft_interval_database', 'xyz') == 'xyz' )) {
				$backup_database = ($backup_files == true) ? true : false;
			}
			$this->log("Processed schedules. Tasks now: Backup files: $backup_files Backup DB: $backup_database");
		}

		if (!is_string($service)) $service = UpdraftPlus_Options::get_updraft_option('updraft_service');

		# If nothing to be done, then just finish
		if (!$backup_files && !$backup_database) {
			$this->backup_finish(1, false, false, 0);
			return;
		}

		// Allow the resume interval to be more than 300 if last time we know we went beyond that - but never more than 600
		$resume_interval = (int)min(max(300, get_transient('updraft_initial_resume_interval')), 600);
		# We delete it because we only want to know about behaviour found during the very last backup run (so, if you move servers then old data is not retained)
		delete_transient('updraft_initial_resume_interval');

		$job_file_entities = array();
		if ($backup_files) {
			$possible_backups = $this->get_backupable_file_entities(true);
			foreach ($possible_backups as $youwhat => $whichdir) {
				if ((false === $restrict_files_to_override && UpdraftPlus_Options::get_updraft_option("updraft_include_$youwhat", apply_filters("updraftplus_defaultoption_include_$youwhat", true))) || (is_array($restrict_files_to_override) && in_array($youwhat, $restrict_files_to_override))) {
					// The 0 indicates the zip file index
					$job_file_entities[$youwhat] = array(
						'index' => 0,
						'status' => 'notstarted',
						'indexstatus' => array(0 => 'notstarted')
					);
				}
			}
		}

		$initial_jobdata = array(
			'resume_interval', $resume_interval,
			'job_type', 'backup',
			'backup_time', $this->backup_time,
			'backup_time_ms', $this->backup_time_ms,
			'service', $service,
			'split_every', max(absint(UpdraftPlus_Options::get_updraft_option('updraft_split_every', 800)), UPDRAFTPLUS_SPLIT_MIN),
			'maxzipbatch', 26214400, #25Mb
			'job_file_entities', $job_file_entities,
			'one_shot', $one_shot
		);

		// Save what *should* be done, to make it resumable from this point on
		array_push($initial_jobdata, 'backup_database', (($backup_database) ? 'begun' : 'no'));
		if ($backup_files) array_push($initial_jobdata, 'backup_files', 'begun');

		// Use of jobdata_set_multi saves around 200ms
		call_user_func_array(array($this, 'jobdata_set_multi'), $initial_jobdata);

		// Everything is set up; now go
		$this->backup_resume(0, $this->nonce);

	}

	function backup_finish($cancel_event, $do_cleanup, $allow_email, $resumption_no) {

		// The valid use of $do_cleanup is to indicate if in fact anything exists to clean up (if no job really started, then there may be nothing)

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if ($this->error_count() == 0) {
			if ($do_cleanup) {
				$this->log("There were no errors in the uploads, so the 'resume' event ($cancel_event) is being unscheduled");
				# This apparently-worthless setting of metadata before deleting it is for the benefit of a WP install seen where wp_clear_scheduled_hook() and delete_transient() apparently did nothing (probably a faulty cache)
				$this->jobdata_set('jobstatus', 'finished');
				delete_transient("updraft_jobdata_".$this->nonce);
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event, $this->nonce));
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
		if ($this->error_count() == 0) {
			$send_an_email = true;
			if ($this->error_count('warning') == 0) {
				$final_message = __("The backup apparently succeeded and is now complete",'updraftplus');
			} else {
				$final_message = __("The backup apparently succeeded (with warnings) and is now complete",'updraftplus');
			}
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

		global $updraftplus_backup;
		if ($send_an_email) $updraftplus_backup->send_results_email($final_message);

		$this->log($final_message);

		@fclose($this->logfile_handle);

		// Don't delete the log file now; delete it upon rotation
 		//if (!UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) @unlink($this->logfile_name);

	}

	public function error_count($level = 'error') {
		$count = 0;
		foreach ($this->errors as $err) {
			if (('error' == $level && (is_string($err) || is_wp_error($err))) || (is_array($err) && $level == $err['level']) ) { $count++; }
		}
		return $count;
	}

	public function list_errors() {
		echo '<ul style="list-style: disc inside;">';
		foreach ($this->errors as $err) {
			if (is_wp_error($err)) {
				foreach ($err->get_error_messages() as $msg) {
					echo '<li>'.htmlspecialchars($msg).'<li>';
				}
			} elseif (is_array($err) && 'error' == $err['level']) {
				echo  "<li>".htmlspecialchars($err['message'])."</li>";
			} elseif (is_string($err)) {
				echo  "<li>".htmlspecialchars($err)."</li>";
			} else {
				print "<li>".print_r($err,true)."</li>";
			}
		}
		echo '</ul>';
	}

	function save_last_backup($backup_array) {
		$success = ($this->error_count() == 0) ? 1 : 0;

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

	function is_uploaded($file) {
		$hash = md5($file);
		return ($this->jobdata_get("uploaded_$hash") === "yes") ? true : false;
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
		// 45 is chosen because it is 15 seconds more than what is used to detect recent activity on files (file mod times). (If we use exactly the same, then it's more possible to slightly miss each other)
		if ($time_away >1 && $time_away <= 45) {
			$this->log('The scheduled resumption is within 45 seconds - will reschedule');
			// Push 45 seconds into the future
 			// $this->reschedule(60);
			// Increase interval generally by 45 seconds, on the assumption that our prior estimates were innaccurate (i.e. not just 45 seconds *this* time)
			$this->increase_resume_and_reschedule(45);
		}
	}

	function reschedule($how_far_ahead) {
		// Reschedule - remove presently scheduled event
		$next_resumption = $this->current_resumption + 1;
		wp_clear_scheduled_hook('updraft_backup_resume', array($next_resumption, $this->nonce));
		// Add new event
		if ($how_far_ahead < 300) $how_far_ahead=300;
		$schedule_for = time() + $how_far_ahead;
		$this->log("Rescheduling resumption $next_resumption: moving to $how_far_ahead seconds from now ($schedule_for)");
		wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $this->nonce));
		$this->newresumption_scheduled = $schedule_for;
	}

	function increase_resume_and_reschedule($howmuch = 120, $force_schedule = false) {

		$resume_interval = $this->jobdata_get('resume_interval');
		if (!is_numeric($resume_interval) || $resume_interval < 300) $resume_interval = 300;

		if (empty($this->newresumption_scheduled) && $force_schedule) {
			$this->log("A new resumption will be scheduled to prevent the job ending");
		}

		$new_resume = $resume_interval + $howmuch;
		# It may be that we're increasing for the second (or more) time during a run, and that we already know that the new value will be insufficient, and can be increased
		if ($this->opened_log_time > 100 && microtime(true)-$this->opened_log_time > $new_resume) {
			$new_resume = ceil(microtime(true)-$this->opened_log_time)+45;
			$howmuch = $new_resume-$resume_interval;
		}

		if (!empty($this->newresumption_scheduled) || $force_schedule) $this->reschedule($new_resume);
		$this->jobdata_set('resume_interval', $new_resume);

		$this->log("To decrease the likelihood of overlaps, increasing resumption interval to: $resume_interval + $howmuch = $new_resume");
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

	public function really_is_writable($dir) {
		// Suppress warnings, since if the user is dumping warnings to screen, then invalid JavaScript results and the screen breaks.
		if (!@is_writable($dir)) return false;
		// Found a case - GoDaddy server, Windows, PHP 5.2.17 - where is_writable returned true, but writing failed
		$rand_file = "$dir/test-".md5(rand().time()).".txt";
		while (file_exists($rand_file)) {
			$rand_file = "$dir/test-".md5(rand().time()).".txt";
		}
		$ret = @file_put_contents($rand_file, 'testing...');
		@unlink($rand_file);
		return ($ret > 0);
	}

	function backup_others_dirlist() {
		# Create an array of directories to be skipped
		$others_skip = preg_split("/,/",UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude', UPDRAFT_DEFAULT_OTHERS_EXCLUDE));
		# Make the values into the keys
		$others_skip = array_flip($others_skip);

		$possible_backups_dirs = array_flip($this->get_backupable_file_entities(false));

		$other_dirlist = $this->compile_folder_list_for_backup(WP_CONTENT_DIR, $possible_backups_dirs, $others_skip);

		return $other_dirlist;

	}

	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	public function backquote($a_name) {
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

	// avoid_these_dirs and skip_these_dirs ultimately do the same thing; but avoid_these_dirs takes full paths whereas skip_these_dirs takes basenames; and they are logged differently (dirs in avoid are potentially dangerous to include; skip is just a user-level preference). They are allowed to overlap.
	function compile_folder_list_for_backup($backup_from_inside_dir, $avoid_these_dirs, $skip_these_dirs) {

		// Entries in $skip_these_dirs are allowed to end in *, which means "and anything else as a suffix". It's not a full shell glob, but it covers what is needed to-date.

		$dirlist = array(); 

		$this->log('Looking for candidates to back up in: '.$backup_from_inside_dir);

		$updraft_dir = $this->backups_dir_location();
		if ($handle = opendir($backup_from_inside_dir)) {
		
			while (false !== ($entry = readdir($handle))) {
				// $candidate: full path; $entry = one-level
				$candidate = $backup_from_inside_dir.'/'.$entry;
				if ($entry != "." && $entry != "..") {
					if (isset($avoid_these_dirs[$candidate])) {
						$this->log("finding files: $entry: skipping: this is the ".$avoid_these_dirs[$candidate]." directory");
					} elseif ($candidate == $updraft_dir) {
						$this->log("finding files: $entry: skipping: this is the updraft directory");
					} elseif (isset($skip_these_dirs[$entry])) {
						$this->log("finding files: $entry: skipping: excluded by options");
					} else {
						$add_to_list = true;
						// Now deal with entries in $skip_these_dirs ending in *
						foreach ($skip_these_dirs as $skip => $sind) {
							if ('*' == substr($skip, -1, 1)) {
								if (substr($entry, 0, strlen($skip)-1) == substr($skip, 0, strlen($skip)-1)) {
									$this->log("finding files: $entry: skipping: excluded by options (glob)");
									$add_to_list = false;
								}
							}
						}
						if ($add_to_list) {
							$this->log("finding files: $entry: adding to list");
							array_push($dirlist, $candidate);
						}
					}
				}
			}
			@closedir($handle);
		} else {
			$this->log('ERROR: Could not read the directory: '.$backup_from_inside_dir);
			$this->log(__('Could not read the directory', 'updraftplus').': '.$backup_from_inside_dir, 'error');
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
			$this->log(__('Could not save backup history because we have no backup array. Backup probably failed.','updraftplus'), 'error');
		}
	}
	
	public function is_db_encrypted($file) {
		return preg_match('/\.crypt$/i', $file);
	}

	public function get_backup_history($timestamp = false) {
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
		if (!$timestamp) return $backup_history;
		return (isset($backup_history[$timestamp])) ? $backup_history[$timestamp] : array();
	}

	function terminate_due_to_activity($file, $time_now, $time_mod) {
		# We check-in, to avoid 'no check in last time!' detectors firing
		$this->record_still_alive();
		$file_size = file_exists($file) ? round(filesize($file)/1024,1). 'Kb' : 'n/a';
		$this->log("Terminate: ".basename($file)." exists with activity within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".(floor($time_now-$time_mod)).", size=$file_size). This likely means that another UpdraftPlus run is at work; so we will exit.");
		$this->increase_resume_and_reschedule(120, true);
		die;
	}

	# Replace last occurence
	public function str_lreplace($search, $replace, $subject) {
		$pos = strrpos($subject, $search);
		if($pos !== false) $subject = substr_replace($subject, $replace, $pos, strlen($search));
		return $subject;
	}

	public function str_replace_once($needle, $replace, $haystack) {
		$pos = strpos($haystack,$needle);
		return ($pos !== false) ? substr_replace($haystack,$replace,$pos,strlen($needle)) : $haystack;
	}

	/*
	this function is both the backup scheduler and ostensibly a filter callback for saving the option.
	it is called in the register_setting for the updraft_interval, which means when the admin settings 
	are saved it is called.  it returns the actual result from wp_filter_nohtml_kses (a sanitization filter) 
	so the option can be properly saved.
	*/
	public function schedule_backup($interval) {
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
				$first_time = apply_filters('updraftplus_schedule_firsttime_files', time()+30);
				wp_schedule_event($first_time, $interval, 'updraft_backup');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	public function deactivation () {
// 		wp_clear_scheduled_hook('updraftplus_weekly_ping');
	}

	// Acts as a WordPress options filter
	public function googledrive_clientid_checkchange($client_id) {
		if (UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token') != '' && UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid') != $client_id) {
			require_once(UPDRAFTPLUS_DIR.'/methods/googledrive.php');
			add_action('http_api_curl', array($this, 'add_curl_capath'));
			UpdraftPlus_BackupModule_googledrive::gdrive_auth_revoke(true);
			remove_action('http_api_curl', array($this, 'add_curl_capath'));

		}
		return $client_id;
	}

	public function schedule_backup_database($interval) {
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
				$first_time = apply_filters('updraftplus_schedule_firsttime_db', time()+30);
				wp_schedule_event($first_time, $interval, 'updraft_backup_database');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	//wp-cron only has hourly, daily and twicedaily, so we need to add some of our own
	public function modify_cron_schedules($schedules) {
		$schedules['weekly'] = array( 'interval' => 604800, 'display' => 'Once Weekly' );
		$schedules['fortnightly'] = array( 'interval' => 1209600, 'display' => 'Once Each Fortnight' );
		$schedules['monthly'] = array( 'interval' => 2592000, 'display' => 'Once Monthly' );
		$schedules['every4hours'] = array( 'interval' => 14400, 'display' => 'Every 4 hours' );
		$schedules['every8hours'] = array( 'interval' => 28800, 'display' => 'Every 8 hours' );
		return $schedules;
	}

	// Returns without any trailing slash
	public function backups_dir_location() {

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

	public function spool_file($type, $fullpath, $encryption = "") {

		@set_time_limit(900);

		if (file_exists($fullpath)) {

			$file = basename($fullpath);

			$len = filesize($fullpath);

			$filearr = explode('.',$file);

			$file_ext = array_pop($filearr);
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
			header("Content-Length: $len;");

			if ($file_ext == 'crypt') {
				if ($encryption == "") $encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
				if ($encryption == "") {
					header('Content-type: text/plain');
					_e("Decryption failed. The database file is encrypted, but you have no encryption key entered.",'updraftplus');
					$this->log('Decryption of database failed: the database file is encrypted, but you have no encryption key entered.', 'error');
				} else {
					$this->ensure_phpseclib('Crypt_Rijndael', 'Crypt/Rijndael');
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
					// When we sent application/x-gzip, we found a case where the server compressed it a second time
					header('Content-type: application/octet-stream');
				}
				header("Content-Disposition: attachment; filename=\"$file\";");
				# Prevent the file being read into memory
				@ob_end_flush();
				readfile($fullpath);
			}
// 			$this->delete_local($file);
		} else {
			echo __('File not found', 'updraftplus');
		}
	}

	public function retain_range($input) {
		$input = (int)$input;
		return  ($input > 0 && $input < 3650) ? $input : 1;
	}

	function memory_check_current($memory_limit = false) {
		# Returns in megabytes
		if ($memory_limit == false) $memory_limit = ini_get('memory_limit');
		$memory_limit = rtrim($memory_limit);
		$memory_unit = $memory_limit[strlen($memory_limit)-1];
		$memory_limit = substr($memory_limit,0,strlen($memory_limit)-1);
		switch($memory_unit) {
			case 'K':
				$memory_limit = floor($memory_limit/1024);
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

	function memory_check($memory, $check_using = false) {
		$memory_limit = $this->memory_check_current($check_using);
		return ($memory_limit >= $memory)?true:false;
	}

	private function url_start($urls,$url) {
		return ($urls) ? '<a href="http://'.$url.'">' : "";
	}

	private function url_end($urls,$url) {
		return ($urls) ? '</a>' : " (http://$url)";
	}

	public function wordshell_random_advert($urls) {
		if (defined('UPDRAFTPLUS_NOADS3')) return "";
		$rad = rand(0,8);
		switch ($rad) {
		case 0:
			return $this->url_start($urls,'updraftplus.com')."Want more features or paid, guaranteed support? Check out UpdraftPlus.Com".$this->url_end($urls,'updraftplus.com');
			break;
		case 1:
			if (defined('WPLANG') && strlen(WPLANG)>0 && !is_file(UPDRAFTPLUS_DIR.'/languages/updraftplus-'.WPLANG.
'.mo')) return __('Can you translate? Want to improve UpdraftPlus for speakers of your language?','updraftplus').$this->url_start($urls,'updraftplus.com/translate/')."Please go here for instructions - it is easy.".$this->url_end($urls,'updraftplus.com/translate/');

			return __('Like UpdraftPlus and can spare one minute?','updraftplus').$this->url_start($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform').' '.__('Please help UpdraftPlus by giving a positive review at wordpress.org','updraftplus').$this->url_end($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform');
			break;
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
			if (!defined('UPDRAFTPLUS_NOADS3')) {
				return $this->url_start($urls,'updraftplus.com').__("Need even more features and support? Check out UpdraftPlus Premium",'updraftplus').$this->url_end($urls,'updraftplus.com');
			} else {
				return "Thanks for being an UpdraftPlus premium user. Keep visiting ".$this->url_start($urls,'updraftplus.com')."updraftplus.com".$this->url_end($urls,'updraftplus.com')." to see what's going on.";
			}
			break;
		case 6:
// 			return "Need custom WordPress services from experts (including bespoke development)?".$this->url_start($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/')." Get them from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/');
			return __("Subscribe to the UpdraftPlus blog to get up-to-date news and offers",'updraftplus')." - ".$this->url_start($urls,'updraftplus.com/news/').__("Blog link",'updraftplus').$this->url_end($urls,'updraftplus.com/news/').' - '.$this->url_start($urls,'feeds.feedburner.com/UpdraftPlus').__("RSS link",'updraftplus').$this->url_end($urls,'feeds.feedburner.com/UpdraftPlus');
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
