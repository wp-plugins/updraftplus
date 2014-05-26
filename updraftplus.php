<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: take backups locally, or backup to Amazon S3, Dropbox, Google Drive, Rackspace, (S)FTP, WebDAV & email, on automatic schedules.
Author: UpdraftPlus.Com, DavidAnderson
Version: 1.9.13
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Text Domain: updraftplus
Domain Path: /languages
Author URI: http://updraftplus.com
*/

/*
TODO - some of these are out of date/done, needs pruning
// On free version, add note to restore page/to "delete-old-dirs" section
// Make SFTP chunked (there is a new stream wrapper)
// Store/show current Dropbox account
// On plugins restore, don't let UD over-write itself - because this usually means a down-grade. Since upgrades are db-compatible, there's no reason to downgrade.
// Renewal links should redirect to login and redirect to relevant page after
// Alert user if they enter http(s):(etc) as their Dropbox path - seen one user do it
// Schedule a task to report on failure
// Copy.Com, Box
// Switch 'Backup Now' to call the WP action via AJAX instead of via Cron - then test on hosts who deny all cron (e.g. Heart)
// Get something to parse the 'Backups in progress' data, and if the 'next resumption' is far negative, and if also cron jobs appear to be not running, then call the action directly.
// If ionice is available, then use it to limit I/O usage
// Check the timestamps used in filenames - they should be UTC
// Get user to confirm if they check both the search/replace and wp-config boxes
// Tweak the display so that users seeing resumption messages don't think it's stuck
// A search/replace console without needing to restore
// On restore, check for some 'standard' PHP modules (prevents support requests related to them) -e.g. GD, Curl
// Recognise known huge non-core tables on restore, and postpone them to the end (AJAX method?)
// Add a cart notice if people have DBSF=quantity1
// Pre-restore actually unpack the zips if they are not insanely big (to prevent the restore crashing at this stage if there's a problem)
// Include in email report the list of "more" directories: http://updraftplus.com/forums/support-forum-group1/paid-support-forum-forum2/wordpress-multi-sites-thread121/
// Integrate jstree for a nice files-chooser; use https://wordpress.org/plugins/dropbox-photo-sideloader/ to see how it's done
// Verify that attempting to bring back a MS backup on a non-MS install warns the user
// Pre-schedule resumptions that we know will be scheduled later
// Change add-ons screen, to be less confusing for people who haven't yet updated but have connected
// Change migrate window: 1) Retain link to article 2) Have selector to choose which backup set to migrate - or a fresh one 3) Have option for FTP/SFTP/SCP despatch 4) Have big "Go" button. Have some indication of what happens next. Test the login first. Have the remote site auto-scan its directory + pick up new sets. Have a way of querying the remote site for its UD-dir. Have a way of saving the settings as a 'profile'. Or just save the last set of settings (since mostly will be just one place to send to). Implement an HTTP/JSON method for sending files too.
// Post restore, do an AJAX get for the site; if this results in a 500, then auto-turn-on WP_DEBUG
// Place in maintenance mode during restore - ?
// Test Azure: https://blogs.technet.com/b/blainbar/archive/2013/08/07/article-create-a-wordpress-site-using-windows-azure-read-on.aspx?Redirected=true
// Seen during autobackup on 1.8.2: Warning: Invalid argument supplied for foreach() in /home/infinite/public_html/new/wp-content/plugins/updraftplus/updraftplus.php on line 1652
// Add some kind of automated scan for post content (e.g. images) that has the same URL base, but is not part of WP. There's an example of such a site in tmp-rich.
// Free/premium comparison page
// Complete the tweak to bring the delete-old-dirs within a dialog (just needed to deal wtih case of needing credentials more elegantly).
// Add note to support page requesting that non-English be translated
// More locking: lock the resumptions too (will need to manage keys to make sure junk data is not left behind)
// See: ftp-logins.log - would help if we retry FTP logins after 10 second delay (not on testing), to lessen chances of 'too many users - try again later' being terminal. Also, can we log the login error?
// Deal with missing plugins/themes/uploads directory when installing
// Bring down interval if we are already in upload time (since zip delays are no longer possible). See: options-general-11-23.txt
// Add FAQ - can I get it to save automatically to my computer?
// Pruner assumes storage is same as current - ?
// Include blog feed in basic email report
// Detect, and show prominent error in admin area, if the slug is not updraftplus/updraftplus.php (one Mac user in the wild managed to upload as updraftplus-2).
// Pre-schedule future resumptions that we know will be scheduled; helps deal with WP's dodgy scheduler skipping some. (Then need to un-schedule if job finishes).
// Dates in the progress box are apparently untranslated
// Add-on descriptions are not internationalised
// Nicer in-dashboard log: show log + option to download; also (if 'reporting' add-on available) show the HTML report from that
// Take a look at logfile-to-examine.txt (stored), and the pattern of detection of zipfile contents
// http://www.phpclasses.org/package/8269-PHP-Send-MySQL-database-backup-files-to-Ubuntu-One.html
// Put the -old directories in updraft_dir instead of present location. Prevents file perms issues, and also will be automatically excluded from backups.
// Test restores via cloud service for small $??? (Relevant: http://browshot.com/features) (per-day? per-install?)
// Warn/prevent if trying to migrate between sub-domain/sub-folder based multisites
// Don't perform pruning when doing auto-backup?
// Post-migrate, notify the user if on Apache but without mod_rewrite (has been seen in the wild)
// Pre-check the search/replace box if migration detected
// Can some tables be omitted from the search/replace on a migrate? i.e. Special knowledge?
// Put a 'what do I get if I upgrade?' link into the mix
// Add to admin bar (and make it something that can be turned off)
// If migrated database from somewhere else, then add note about revising UD settings
// Strategy for what to do if the updraft_dir contains untracked backups. Automatically rescan?
// MySQL manual: See Section 8.2.2.1, Speed of INSERT Statements.
// Exempt UD itself from a plugins restore? (will options be out-of-sync? exempt options too?)
// Post restore/migrate, check updraft_dir, and reset if non-existent
// Auto-empty caches post-restore/post-migration (prevent support requests from people with state/wrong cacheing data)
// Show 'Migrate' instead of 'Restore' on the button if relevant
// Test with: http://wordpress.org/plugins/wp-db-driver/
// Backup notes
// Automatically re-count folder usage after doing a delete
// Switch zip engines earlier if no progress - see log.cfd793337563_hostingfails.txt
// The delete-em at the end needs to be made resumable. And to only run on last run-through (i.e. no errors, or no resumption)
// Incremental - can leverage some of the multi-zip work???
// Put in a help link to explain what WordPress core (including any additions to your WordPress root directory) does (was asked for support)
// More databases
// Multiple files in more-files
// On multisite, the settings should be in the network panel. Connection settings need migrating into site options.
// On restore, raise a warning for ginormous zips
// Detect double-compressed files when they are uploaded (need a way to detect gz compression in general)
// Log migrations/restores, and have an option for auto-emailing the log
# Email backup method should be able to force split limit down to something manageable - or at least, should make the option display. (Put it in email class. Tweak the storage dropdown to not hide stuff also in expert class if expert is shown).
// What happens if you restore with a database that then changes the setting for updraft_dir ? Should be safe, as the setting is cached during a run: double-check.
// Multi-site manager at updraftplus.com
// Import/slurp backups from other sites. See: http://www.skyverge.com/blog/extending-the-wordpress-xml-rpc-api/
// More sophisticated options for retaining/deleting (e.g. 4/day for X days, then 7/week for Z weeks, then 1/month for Y months)
// Unpack zips via AJAX? Do bit-by-bit to allow enormous opens a better chance? (have a huge one in Dropbox)
// Put in a maintenance-mode detector
// Add update warning if they've got an add-on but not connected account
// Detect CloudFlare output in attempts to connect - detecting cloudflare.com should be sufficient
// Bring multisite shop page up to date
// Re-do pricing + support packages
// More files: back up multiple directories, not just one
// Give a help page to go with the message: A zip error occurred - check your log for more details (reduce support requests)
// Exclude .git and .svn by default from wpcore
// Add option to add, not just replace entities on restore/migrate
// Add warning to backup run at beginning if -old dirs exist
// Auto-alert if disk usage passes user-defined threshold / or an automatically computed one. Auto-alert if more backups are known than should be (usually a sign of incompleteness). Actually should just delete unknown backups over a certain age.
// Generic S3 provider: add page to site. S3-compatible storage providers: http://www.dragondisk.com/s3-storage-providers.html
// Importer - import backup sets from another WP site directly via HTTP
// Option to create new user for self post-restore
// Auto-disable certain cacheing/minifying plugins post-restore
// Add note post-DB backup: you will need to log in using details from newly-imported DB
// Make search+replace two-pass to deal with moving between exotic non-default moved-directory setups
// Get link - http://www.rackspace.com/knowledge_center/article/how-to-use-updraftplus-to-back-up-cloud-sites-to-cloud-files
// 'Delete from your webserver' should trigger a rescan if the backup was local-only
// Option for additive restores - i.e. add content (themes, plugins,...) instead of replacing
// Testing framework - automated testing of all file upload / download / deletion methods
// Ginormous tables - need to make sure we "touch" the being-written-out-file (and double-check that we check for that) every 15 seconds - https://friendpaste.com/697eKEcWib01o6zT1foFIn
// With ginormous tables, log how many times they've been attempted: after 3rd attempt, log a warning and move on. But first, batch ginormous tables (resumable)
// Import single site into a multisite: http://codex.wordpress.org/Migrating_Multiple_Blogs_into_WordPress_3.0_Multisite, http://wordpress.org/support/topic/single-sites-to-multisite?replies=5, http://wpmu.org/import-export-wordpress-sites-multisite/
// Selective restores - some resources
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
// Search for other TODO-s in the code
// Opt-in non-personal stats + link to aggregated results
// Stand-alone installer - take a look at this: http://wordpress.org/extend/plugins/duplicator/screenshots/
// More DB add-on (other non-WP tables; even other databases)
// Unlimited customers should be auto-emailed each time they add a site (security)
// Update all-features page at updraftplus.com (not updated after 1.5.5)
// Save database encryption key inside backup history on per-db basis, so that if it changes we can still decrypt
// AJAX-ify restoration
// Warn Premium users before de-activating not to update whilst inactive
// Ability to re-scan existing cloud storage
// Dropbox uses one mcrypt function - port to phpseclib for more portability
// Store meta-data on which version of UD the backup was made with (will help if we ever introduce quirks that need ironing)
// Send the user an email upon their first backup with tips on what to do (e.g. support/improve) (include legacy check to not bug existing users)
// Rackspace folders
//Do an automated test periodically for the success of loop-back connections
//When a manual backup is run, use a timer to update the 'Download backups and logs' section, just like 'Last finished backup run'. Beware of over-writing anything that's in there from a resumable downloader.
//Change DB encryption to not require whole gzip in memory (twice) http://www.frostjedi.com/phpbb3/viewtopic.php?f=46&t=168508&p=391881&e=391881
//Add YouSendIt/Hightail, Copy.Com, Box.Net, SugarSync, Me.Ga support??
//Make it easier to find add-ons
// On restore, move in data, not the whole directory (gives more flexibility on file permissions)
// Move the inclusion, cloud and retention data into the backup job (i.e. don't read current config, make it an attribute of each job). In fact, everything should be. So audit all code for where get_option is called inside a backup run: it shouldn't happen.
// Should we resume if the only errors were upon deletion (i.e. the backup itself was fine?) Presently we do, but it displays errors for the user to confuse them. Perhaps better to make pruning a separate scheuled task??
// Create a "Want Support?" button/console, that leads them through what is needed, and performs some basic tests...
// Add-on to check integrity of backups
// Add-on to manage all your backups from a single dashboard
// Provide backup/restoration for UpdraftPlus's settings, to allow 'bootstrap' on a fresh WP install - some kind of single-use code which a remote UpdraftPlus can use to authenticate
// Multiple schedules
// Allow connecting to remote storage, scanning + populating backup history from it
// Multisite add-on should allow restoring of each blog individually
// Remove the recurrence of admin notices when settings are saved due to _wp_referer
// New sub-module to verify that the backups are there, independently of backup thread
*/

/*
Portions copyright 2011-14 David Anderson
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
define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,backup*,*backups');
define('UPDRAFT_DEFAULT_UPLOADS_EXCLUDE','backup*,*backups,backwpup*,wp-clone');

# The following can go in your wp-config.php
# Tables whose data can be safed without significant loss, if (and only if) the attempt to back them up fails (e.g. bwps_log, from WordPress Better Security, is log data; but individual entries can be huge and cause out-of-memory fatal errors on low-resource environments). Comma-separate the table names (without the WordPress table prefix).
if (!defined('UPDRAFTPLUS_DATA_OPTIONAL_TABLES')) define('UPDRAFTPLUS_DATA_OPTIONAL_TABLES', 'bwps_log,statpress,slim_stats,redirection_logs,Counterize,Counterize_Referers,Counterize_UserAgents');
if (!defined('UPDRAFTPLUS_ZIP_EXECUTABLE')) define('UPDRAFTPLUS_ZIP_EXECUTABLE', "/usr/bin/zip,/bin/zip,/usr/local/bin/zip,/usr/sfw/bin/zip,/usr/xdg4/bin/zip,/opt/bin/zip");
if (!defined('UPDRAFTPLUS_MYSQLDUMP_EXECUTABLE')) define('UPDRAFTPLUS_MYSQLDUMP_EXECUTABLE', "/usr/bin/mysqldump,/bin/mysqldump,/usr/local/bin/mysqldump,/usr/sfw/bin/mysqldump,/usr/xdg4/bin/mysqldump,/opt/bin/mysqldump");
# If any individual file size is greater than this, then a warning is given
if (!defined('UPDRAFTPLUS_WARN_FILE_SIZE')) define('UPDRAFTPLUS_WARN_FILE_SIZE', 1024*1024*250);
# On a test on a Pentium laptop, 100,000 rows needed ~ 1 minute to write out - so 150,000 is around the CPanel default of 90 seconds execution time.
if (!defined('UPDRAFTPLUS_WARN_DB_ROWS')) define('UPDRAFTPLUS_WARN_DB_ROWS', 150000);

# The smallest value (in megabytes) that the "split zip files at" setting is allowed to be set to
if (!defined('UPDRAFTPLUS_SPLIT_MIN')) define('UPDRAFTPLUS_SPLIT_MIN', 25);

# The maximum number of files to batch at one time when writing to the backup archive. You'd only be likely to want to raise (not lower) this.
if (!defined('UPDRAFTPLUS_MAXBATCHFILES')) define('UPDRAFTPLUS_MAXBATCHFILES', 500);

// Load add-ons and various files that may or may not be present, depending on where the plugin was distributed
if (is_file(UPDRAFTPLUS_DIR.'/premium.php')) require_once(UPDRAFTPLUS_DIR.'/premium.php');
if (is_file(UPDRAFTPLUS_DIR.'/autoload.php')) require_once(UPDRAFTPLUS_DIR.'/autoload.php');
if (is_file(UPDRAFTPLUS_DIR.'/udaddons/updraftplus-addons.php')) include_once(UPDRAFTPLUS_DIR.'/udaddons/updraftplus-addons.php');

$updraftplus_have_addons = 0;
if (is_dir(UPDRAFTPLUS_DIR.'/addons') && $dir_handle = opendir(UPDRAFTPLUS_DIR.'/addons')) {
	while (false !== ($e = readdir($dir_handle))) {
		if (is_file(UPDRAFTPLUS_DIR.'/addons/'.$e) && preg_match('/\.php$/', $e)) {
			$header = file_get_contents(UPDRAFTPLUS_DIR.'/addons/'.$e, false, null, -1, 1024);
			$phprequires = (preg_match("/RequiresPHP: (\d[\d\.]+)/", $header, $matches)) ? $matches[1] : false;
			$phpinclude = (preg_match("/IncludePHP: (\S+)/", $header, $matches)) ? $matches[1] : false;
			if (false === $phprequires || version_compare(PHP_VERSION, $phprequires, '>=')) {
				$updraftplus_have_addons++;
				if ($phpinclude) require_once(UPDRAFTPLUS_DIR.'/'.$phpinclude);
				include_once(UPDRAFTPLUS_DIR.'/addons/'.$e);
			}
		}
	}
	@closedir($dir_handle);
}

$updraftplus = new UpdraftPlus();
$updraftplus->have_addons = $updraftplus_have_addons;

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
	public $backup_methods = array(
		's3' => 'Amazon S3',
		'dropbox' => 'Dropbox',
		'cloudfiles' => 'Rackspace Cloud Files',
		'googledrive' => 'Google Drive',
		'ftp' => 'FTP',
		'sftp' => 'SFTP / SCP',
		'webdav' => 'WebDAV',
		'bitcasa' => 'Bitcasa',
		's3generic' => 'S3-Compatible (Generic)',
		'openstack' => 'OpenStack (Swift)',
		'dreamobjects' => 'DreamObjects',
		'email' => 'Email'
	);

	public $errors = array();
	public $nonce;
	public $logfile_name = "";
	public $logfile_handle = false;
	public $backup_time;
	public $job_time_ms;

	public $opened_log_time;
	private $backup_dir;

	private $jobdata;

	public $something_useful_happened = false;
	public $have_addons = false;

	// Used to schedule resumption attempts beyond the tenth, if needed
	public $current_resumption;
	public $newresumption_scheduled = false;

	public function __construct() {

		// Initialisation actions - takes place on plugin load

		if ($fp = fopen(__FILE__, 'r')) {
			$file_data = fread( $fp, 1024 );
			if (preg_match("/Version: ([\d\.]+)(\r|\n)/", $file_data, $matches)) {
				$this->version = $matches[1];
			}
			fclose($fp);
		}

		# Create admin page
		add_action('init', array($this, 'handle_url_actions'));
		// Run earlier than default - hence earlier than other components
		// admin_menu runs earlier, and we need it because options.php wants to use $updraftplus_admin before admin_init happens
		add_action(apply_filters('updraft_admin_menu_hook', 'admin_menu'), array($this, 'admin_menu'), 9);
		# Not a mistake: admin-ajax.php calls only admin_init and not admin_menu
		add_action('admin_init', array($this, 'admin_menu'), 9);
		add_action('updraft_backup', array($this, 'backup_files'));
		add_action('updraft_backup_database', array($this, 'backup_database'));
		add_action('updraft_backupnow_backup', array($this, 'backupnow_files'));
		add_action('updraft_backupnow_backup_database', array($this, 'backupnow_database'));
		add_action('updraft_backupnow_backup_all', array($this, 'backup_all'));
		# backup_all as an action is legacy (Oct 2013) - there may be some people who wrote cron scripts to use it
		add_action('updraft_backup_all', array($this, 'backup_all'));
		# this is our runs-after-backup event, whose purpose is to see if it succeeded or failed, and resume/mom-up etc.
		add_action('updraft_backup_resume', array($this, 'backup_resume'), 10, 3);
		# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules. Raised priority because some plugins wrongly over-write all prior schedule changes (including BackupBuddy!)
		add_filter('cron_schedules', array($this, 'modify_cron_schedules'), 30);
		add_action('plugins_loaded', array($this, 'load_translations'));

		# Prevent iThemes Security from telling people that they have no backups (and advertising them another product on that basis!)
		add_filter('itsec_has_external_backup', array($this, 'return_true'), 999);
		add_filter('itsec_external_backup_link', array($this, 'itsec_external_backup_link'), 999);
		add_filter('itsec_scheduled_external_backup', array($this, 'itsec_scheduled_external_backup'), 999);

		register_deactivation_hook(__FILE__, array($this, 'deactivation'));

	}

	public function itsec_scheduled_external_backup($x) { return (!wp_next_scheduled('updraft_backup')) ? false : true; }
	public function itsec_external_backup_link($x) { return UpdraftPlus_Options::admin_page_url().'?page=updraftplus'; }
	public function return_true($x) { return true; }

	public function ensure_phpseclib($class = false, $class_path = false) {
		if ($class && class_exists($class)) return;
		if (false === strpos(get_include_path(), UPDRAFTPLUS_DIR.'/includes/phpseclib')) set_include_path(get_include_path().PATH_SEPARATOR.UPDRAFTPLUS_DIR.'/includes/phpseclib');
		if ($class_path) require_once(UPDRAFTPLUS_DIR.'/includes/phpseclib/'.$class_path.'.php');
	}

	// Returns the number of bytes free, if it can be detected; otherwise, false
	// Presently, we only detect CPanel. If you know of others, then feel free to contribute!
	public function get_hosting_disk_quota_free() {
		if (!@is_dir('/usr/local/cpanel') || $this->detect_safe_mode() || !function_exists('popen') || (!@is_executable('/usr/local/bin/perl') && !@is_executable('/usr/local/cpanel/3rdparty/bin/perl'))) return false;

		$perl = (@is_executable('/usr/local/cpanel/3rdparty/bin/perl')) ? '/usr/local/cpanel/3rdparty/bin/perl' : '/usr/local/bin/perl';

		$exec = "UPDRAFTPLUSKEY=updraftplus $perl ".UPDRAFTPLUS_DIR."/includes/get-cpanel-quota-usage.pl";

		$handle = @popen($exec, 'r');
		if (!is_resource($handle)) return false;

		$found = false;
		$lines = 0;
		while (false === $found && !feof($handle) && $lines<100) {
			$lines++;
			$w = fgets($handle);
			# Used, limit, remain
			if (preg_match('/RESULT: (\d+) (\d+) (\d+) /', $w, $matches)) { $found = true; }
		}
		$ret = pclose($handle);
		if (false === $found ||$ret != 0) return false;

		if ((int)$matches[2]<100 || ($matches[1] + $matches[3] != $matches[2])) return false;

		return $matches;
	}

	// This function may get called multiple times, so write accordingly
	public function admin_menu() {
		// We are in the admin area: now load all that code
		global $updraftplus_admin;
		if (empty($updraftplus_admin)) require_once(UPDRAFTPLUS_DIR.'/admin.php');

		if (isset($_GET['wpnonce']) && isset($_GET['page']) && isset($_GET['action']) && $_GET['page'] == 'updraftplus' && $_GET['action'] == 'downloadlatestmodlog' && wp_verify_nonce($_GET['wpnonce'], 'updraftplus_download')) {

			$updraft_dir = $this->backups_dir_location();

			$log_file = '';
			$mod_time = 0;

			if ($handle = @opendir($updraft_dir)) {
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
					add_action('all_admin_notices', array($this,'show_admin_warning_unreadablelog') );
				}
			} else {
				add_action('all_admin_notices', array($this,'show_admin_warning_nolog') );
			}
		}

	}

	public function add_curl_capath($handle) {
		if (!UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')) curl_setopt($handle, CURLOPT_CAINFO, UPDRAFTPLUS_DIR.'/includes/cacert.pem' );
	}

	// Handle actions passed on to method plugins; e.g. Google OAuth 2.0 - ?action=updraftmethod-googledrive-auth&page=updraftplus
	// Nov 2013: Google's new cloud console, for reasons as yet unknown, only allows you to enter a redirect_uri with a single URL parameter... thus, we put page second, and re-add it if necessary. Apr 2014: Bitcasa already do this, so perhaps it is part of the OAuth2 standard or best practice somewhere.
	// Also handle action=downloadlog
	public function handle_url_actions() {

		// First, basic security check: must be an admin page, with ability to manage options, with the right parameters
		// Also, only on GET because WordPress on the options page repeats parameters sometimes when POST-ing via the _wp_referer field
		if (isset($_SERVER['REQUEST_METHOD']) && 'GET' == $_SERVER['REQUEST_METHOD'] && isset($_GET['action'])) {
			if (preg_match("/^updraftmethod-([a-z]+)-([a-z]+)$/", $_GET['action'], $matches) && file_exists(UPDRAFTPLUS_DIR.'/methods/'.$matches[1].'.php') && UpdraftPlus_Options::user_can_manage()) {
				$_GET['page'] = 'updraftplus';
				$_REQUEST['page'] = 'updraftplus';
				$method = $matches[1];
				require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
				$call_class = "UpdraftPlus_BackupModule_".$method;
				$call_method = "action_".$matches[2];
				$backup_obj = new $call_class;
				add_action('http_api_curl', array($this, 'add_curl_capath'));
				try {
					if (method_exists($backup_obj, $call_method)) {
						call_user_func(array($backup_obj, $call_method));
					} elseif (method_exists($backup_obj, 'action_handler')) {
						call_user_func(array($backup_obj, 'action_handler'), $matches[2]);
					}
				} catch (Exception $e) {
					$this->log(sprintf(__("%s error: %s", 'updraftplus'), $method, $e->getMessage().' ('.$e->getCode().')', 'error'));
				}
				remove_action('http_api_curl', array($this, 'add_curl_capath'));
			} elseif (isset( $_GET['page'] ) && $_GET['page'] == 'updraftplus' && $_GET['action'] == 'downloadlog' && isset($_GET['updraftplus_backup_nonce']) && preg_match("/^[0-9a-f]{12}$/",$_GET['updraftplus_backup_nonce']) && UpdraftPlus_Options::user_can_manage()) {
				// No WordPress nonce is needed here or for the next, since the backup is already nonce-based
				$updraft_dir = $this->backups_dir_location();
				$log_file = $updraft_dir.'/log.'.$_GET['updraftplus_backup_nonce'].'.txt';
				if (is_readable($log_file)) {
					header('Content-type: text/plain');
					readfile($log_file);
					exit;
				} else {
					add_action('all_admin_notices', array($this,'show_admin_warning_unreadablelog') );
				}
			} elseif (isset( $_GET['page'] ) && $_GET['page'] == 'updraftplus' && $_GET['action'] == 'downloadfile' && isset($_GET['updraftplus_file']) && preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-db([0-9]+)?+\.(gz\.crypt)$/i', $_GET['updraftplus_file']) && UpdraftPlus_Options::user_can_manage()) {
				$updraft_dir = $this->backups_dir_location();
				$spool_file = $updraft_dir.'/'.basename($_GET['updraftplus_file']);
				if (is_readable($spool_file)) {
					$dkey = (isset($_GET['decrypt_key'])) ? $_GET['decrypt_key'] : "";
					$this->spool_file('db', $spool_file, $dkey);
					exit;
				} else {
					add_action('all_admin_notices', array($this,'show_admin_warning_unreadablefile') );
				}
			}
		}
	}

	public function get_table_prefix($allow_override = false) {
		global $wpdb;
		if (is_multisite() && !defined('MULTISITE')) {
			# In this case (which should only be possible on installs upgraded from pre WP 3.0 WPMU), $wpdb->get_blog_prefix() cannot be made to return the right thing. $wpdb->base_prefix is not explicitly marked as public, so we prefer to use get_blog_prefix if we can, for future compatibility.
			$prefix = $wpdb->base_prefix;
		} else {
			$prefix = $wpdb->get_blog_prefix(0);
		}
		return ($allow_override) ? apply_filters('updraftplus_get_table_prefix', $prefix) : $prefix;
	}

	public function show_admin_warning_unreadablelog() {
		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('The log file could not be read.','updraftplus'));
	}

	public function show_admin_warning_nolog() {
		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('No log files were found.','updraftplus'));
	}

	public function show_admin_warning_unreadablefile() {
		global $updraftplus_admin;
		$updraftplus_admin->show_admin_warning('<strong>'.__('UpdraftPlus notice:','updraftplus').'</strong> '.__('The given file could not be read.','updraftplus'));
	}

	public function load_translations() {
		// Tell WordPress where to find the translations
		load_plugin_textdomain('updraftplus', false, basename(dirname(__FILE__)).'/languages/');
		# The Google Analyticator plugin does something horrible: loads an old version of the Google SDK on init, always - which breaks us
		if ((defined('DOING_CRON') && DOING_CRON) || (isset($_GET['page']) && $_GET['page'] == 'updraftplus')) {
			remove_action('init', 'ganalyticator_stats_init');
			# Appointments+ does the same; but providers a cleaner way to disable it
			define('APP_GCAL_DISABLE', true);
		}
	}

	// Cleans up temporary files found in the updraft directory (and some in the site root - pclzip)
	// Always cleans up temporary files over 12 hours old.
	// With parameters, also cleans up those.
	// Also cleans out old job data older than 12 hours old (immutable value)
	public function clean_temporary_files($match = '', $older_than = 43200) {
		# Clean out old job data
		if ($older_than >10000) {
			global $wpdb;
			$all_jobs = $wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE 'updraft_jobdata_%'", ARRAY_A);
			foreach ($all_jobs as $job) {
				$val = maybe_unserialize($job['option_value']);
				# TODO: Can simplify this after a while (now all jobs use job_time_ms) - 1 Jan 2014
				# TODO: This will need changing when incremental backups are introduced
				if (!empty($val['backup_time_ms']) && time() > $val['backup_time_ms'] + 86400) {
					delete_option($job['option_name']);
				} elseif (!empty($val['job_time_ms']) && time() > $val['job_time_ms'] + 86400) {
					delete_option($job['option_name']);
				} elseif (empty($val['backup_time_ms']) && empty($val['job_time_ms']) && !empty($val['job_type']) && $val['job_type'] != 'backup') {
					delete_option($job['option_name']);
				}
			}
			
		}
		$updraft_dir = $this->backups_dir_location();
		$now_time=time();
		if ($handle = opendir($updraft_dir)) {
			while (false !== ($entry = readdir($handle))) {
				// This match is for files created internally by zipArchive::addFile
				$ziparchive_match = preg_match("/$match([0-9]+)?\.zip\.tmp\.([A-Za-z0-9]){6}?$/i", $entry);
				// zi followed by 6 characters is the pattern used by /usr/bin/zip on Linux systems. It's safe to check for, as we have nothing else that's going to match that pattern.
				$binzip_match = preg_match("/^zi([A-Za-z0-9]){6}$/", $entry);
				# Temporary files from the database dump process - not needed, as is caught by the catch-all
				# $table_match = preg_match("/${match}-table-(.*)\.table(\.tmp)?\.gz$/i", $entry);
				# The gz goes in with the txt, because we *don't* want to reap the raw .txt files
				if ((preg_match("/$match\.(tmp|table|txt\.gz)(\.gz)?$/i", $entry) || $ziparchive_match || $binzip_match) && is_file($updraft_dir.'/'.$entry)) {
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

	public function backup_time_nonce($nonce = false) {
		$this->job_time_ms = microtime(true);
		$this->backup_time = time();
		if (false === $nonce) $nonce = substr(md5(time().rand()), 20);
		$this->nonce = $nonce;
	}

	public function logfile_open($nonce) {

		//set log file name and open log file
		$updraft_dir = $this->backups_dir_location();
		$this->logfile_name =  $updraft_dir."/log.$nonce.txt";

		if (file_exists($this->logfile_name)) {
			$seek_to = max((filesize($this->logfile_name) - 340), 1);
			$handle = fopen($this->logfile_name, 'r');
			if (is_resource($handle)) {
				# Returns 0 on success
				if (0 === @fseek($handle, $seek_to)) {
					$bytes_back = filesize($this->logfile_name) - $seek_to;
					# Return to the end of the file
					$read_recent = fread($handle, $bytes_back);
					# Move to end of file - ought to be redundant
					if (false !== strpos($read_recent, 'The backup apparently succeeded') && false !== strpos($read_recent, 'and is now complete')) {
						$this->backup_is_already_complete = true;
					}
				}
				fclose($handle);
			}
		}

		$this->logfile_handle = fopen($this->logfile_name, 'a');

		$this->opened_log_time = microtime(true);
		$this->log('Opened log file at time: '.date('r').' on '.site_url());
		global $wp_version;
		@include(ABSPATH.'wp-includes/version.php');

		// Will need updating when WP stops being just plain MySQL
		$mysql_version = (function_exists('mysql_get_server_info')) ? @mysql_get_server_info() : '?';

		$safe_mode = $this->detect_safe_mode();

		$memory_limit = ini_get('memory_limit');
		$memory_usage = round(@memory_get_usage(false)/1048576, 1);
		$memory_usage2 = round(@memory_get_usage(true)/1048576, 1);

		# Attempt to raise limit to avoid false positives
		@set_time_limit(900);
		$max_execution_time = (int)@ini_get("max_execution_time");

		$logline = "UpdraftPlus WordPress backup plugin (http://updraftplus.com): ".$this->version." WP: ".$wp_version." PHP: ".phpversion()." (".@php_uname().") MySQL: $mysql_version Server: ".$_SERVER["SERVER_SOFTWARE"]." safe_mode: $safe_mode max_execution_time: $max_execution_time memory_limit: $memory_limit (used: ${memory_usage}M | ${memory_usage2}M) multisite: ".((is_multisite()) ? 'Y' : 'N')." mcrypt: ".((function_exists('mcrypt_encrypt')) ? 'Y' : 'N')." ZipArchive::addFile: ";

		// method_exists causes some faulty PHP installations to segfault, leading to support requests
		if (version_compare(phpversion(), '5.2.0', '>=') && extension_loaded('zip')) {
			$logline .= 'Y';
		} else {
			$logline .= (class_exists('ZipArchive') && method_exists('ZipArchive', 'addFile')) ? "Y" : "N";
		}

		$w3oc = 'N';
		if (0 === $this->current_resumption) {
			$memlim = $this->memory_check_current();
			if ($memlim<65) {
				$this->log(sprintf(__('The amount of memory (RAM) allowed for PHP is very low (%s Mb) - you should increase it to avoid failures due to insufficient memory (consult your web hosting company for more help)', 'updraftplus'), round($memlim, 1)), 'warning', 'lowram');
			}
			if ($max_execution_time>0 && $max_execution_time<20) {
				$this->log(sprintf(__('The amount of time allowed for WordPress plugins to run is very low (%s seconds) - you should increase it to avoid backup failures due to time-outs (consult your web hosting company for more help - it is the max_execution_time PHP setting; the recommended value is %s seconds or more)', 'updraftplus'), $max_execution_time, 90), 'warning', 'lowmaxexecutiontime');
			}
			if (defined('W3TC') && W3TC == true && function_exists('w3_instance')) {
				$modules = w3_instance('W3_ModuleStatus');
				if ($modules->is_enabled('objectcache')) {
					$w3oc = 'Y';
				}
			}
			$logline .= " W3TC/ObjectCache: $w3oc";
		}

		$this->log($logline);

		$hosting_bytes_free = $this->get_hosting_disk_quota_free();
		if (is_array($hosting_bytes_free)) {
			$perc = round(100*$hosting_bytes_free[1]/(max($hosting_bytes_free[2], 1)), 1);
			$quota_free = ' / '.sprintf('Free disk space in account: %s (%s used)', round($hosting_bytes_free[3]/1048576, 1)." Mb", "$perc %");
			if ($hosting_bytes_free[3] < 1048576*50) {
				$quota_free_mb = round($hosting_bytes_free[3]/1048576, 1);
				$this->log(sprintf(__('Your free space in your hosting account is very low - only %s Mb remain', 'updraftplus'), $quota_free_mb), 'warning', 'lowaccountspace'.$quota_free_mb);
			}
		} else {
			$quota_free = '';
		}

		$disk_free_space = @disk_free_space($updraft_dir);
		if ($disk_free_space === false) {
			$this->log("Free space on disk containing Updraft's temporary directory: Unknown".$quota_free);
		} else {
			$this->log("Free space on disk containing Updraft's temporary directory: ".round($disk_free_space/1048576,1)." Mb".$quota_free);
			$disk_free_mb = round($disk_free_space/1048576, 1);
			if ($disk_free_space < 50*1048576) $this->log(sprintf(__('Your free disk space is very low - only %s Mb remain', 'updraftplus'), round($disk_free_space/1048576, 1)), 'warning', 'lowdiskspace'.$disk_free_mb);
		}

	}

	/* Logs the given line, adding (relative) time stamp and newline
	Note these subtleties of log handling:
	- Messages at level 'error' are not logged to file - it is assumed that a separate call to log() at another level will take place. This is because at level 'error', messages are translated; whereas the log file is for developers who may not know the translated language. Messages at level 'error' are for the user.
	- Messages at level 'error' do not persist through the job (they are only saved with save_backup_history(), and never restored from there - so only the final save_backup_history() errors persist); we presume that either a) they will be cleared on the next attempt, or b) they will occur again on the final attempt (at which point they will go to the user). But...
	- ... messages at level 'warning' persist. These are conditions that are unlikely to be cleared, not-fatal, but the user should be informed about. The $uniq_id field (which should not be numeric) can then be used for warnings that should only be logged once
	$skip_dblog = true is suitable when there's a risk of excessive logging, and the information is not important for the user to see in the browser on the settings page
	*/

	public function log($line, $level = 'notice', $uniq_id = false, $skip_dblog = false) {

		if ('error' == $level || 'warning' == $level) {
			if ('error' == $level && 0 == $this->error_count()) $this->log('An error condition has occurred for the first time during this job');
			if ($uniq_id) {
				$this->errors[$uniq_id] = array('level' => $level, 'message' => $line);
			} else {
				$this->errors[] = array('level' => $level, 'message' => $line);
			}
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

		do_action('updraftplus_logline', $line, $this->nonce, $level, $uniq_id);

		if ($this->logfile_handle) {
			# Record log file times relative to the backup start, if possible
			$rtime = (!empty($this->job_time_ms)) ? microtime(true)-$this->job_time_ms : microtime(true)-$this->opened_log_time;
			fwrite($this->logfile_handle, sprintf("%08.03f", round($rtime, 3))." (".$this->current_resumption.") ".(('notice' != $level) ? '['.ucfirst($level).'] ' : '').$line."\n");
		}

		switch ($this->jobdata_get('job_type')) {
			case 'download':
				// Download messages are keyed on the job (since they could be running several), and type
				// The values of the POST array were checked before
				$findex = (!empty($_POST['findex'])) ? $_POST['findex'] : 0;

				$this->jobdata_set('dlmessage_'.$_POST['timestamp'].'_'.$_POST['type'].'_'.$findex, $line);

				break;
			case 'restore':
				#if ('debug' != $level) echo $line."\n";
				break;
			default:
				if (!$skip_dblog && 'debug' != $level) UpdraftPlus_Options::update_updraft_option('updraft_lastmessage', $line." (".date_i18n('M d H:i:s').")", false);
				break;
		}

		if (defined('UPDRAFTPLUS_CONSOLELOG')) print $line."\n";
		if (defined('UPDRAFTPLUS_BROWSERLOG')) print htmlentities($line)."<br>\n";
	}

	public function log_removewarning($uniq_id) {
		$warnings = $this->jobdata_get('warnings');
		if (!is_array($warnings)) $warnings=array();
		unset($warnings[$uniq_id]);
		$this->jobdata_set('warnings', $warnings);
		unset($this->errors[$uniq_id]);
	}

	# For efficiency, you can also feed false or a string into this function
	public function log_wp_error($err, $echo = false, $logerror = false) {
		if (false === $err) return false;
		if (is_string($err)) {
			$this->log("Error message: $err");
			if ($echo) echo sprintf(__('Error: %s', 'updraftplus'), htmlspecialchars($err))."<br>";
			if ($logerror) $this->log($err, 'error');
			return false;
		}
		foreach ($err->get_error_messages() as $msg) {
			$this->log("Error message: $msg");
			if ($echo) echo sprintf(__('Error: %s', 'updraftplus'), htmlspecialchars($msg))."<br>";
			if ($logerror) $this->log($msg, 'error');
		}
		$codes = $err->get_error_codes();
		if (is_array($codes)) {
			foreach ($codes as $code) {
				$data = $err->get_error_data($code);
				if (!empty($data)) {
					$ll = (is_string($data)) ? $data : serialize($data);
					$this->log("Error data (".$code."): ".$ll);
				}
			}
		}
		# Returns false so that callers can return with false more efficiently if they wish
		return false;
	}

	public function get_max_packet_size() {
		global $wpdb, $updraftplus;
		$mp = (int)$wpdb->get_var("SELECT @@session.max_allowed_packet");
		# Default to 1Mb
		$mp = (is_numeric($mp) && $mp > 0) ? $mp : 1048576;
		# 32Mb
		if ($mp < 33554432) {
			$save = $wpdb->show_errors(false);
			$req = $wpdb->query("SET GLOBAL max_allowed_packet=33554432");
			$wpdb->show_errors($save);
			if (!$req) $updraftplus->log("Tried to raise max_allowed_packet from ".round($mp/1048576,1)." Mb to 32 Mb, but failed (".$wpdb->last_error.", ".serialize($req).")");
			$mp = (int)$wpdb->get_var("SELECT @@session.max_allowed_packet");
			# Default to 1Mb
			$mp = (is_numeric($mp) && $mp > 0) ? $mp : 1048576;
		}
		$updraftplus->log("Max packet size: ".round($mp/1048576, 1)." Mb");
		return $mp;
	}

	# Q. Why is this abstracted into a separate function? A. To allow poedit and other parsers to pick up the need to translate strings passed to it (and not pick up all of those passed to log()).
	# 1st argument = the line to be logged (obligatory)
	# Further arguments = parameters for sprintf()
	public function log_e() {
		$args = func_get_args();
		# Get first argument
		$pre_line = array_shift($args);
		# Log it whilst still in English
		if (is_wp_error($pre_line)) {
			$this->log_wp_error($pre_line);
		} else {
			# Now run (v)sprintf on it, using any remaining arguments. vsprintf = sprintf but takes an array instead of individual arguments
			$this->log(vsprintf($pre_line, $args));
			echo vsprintf(__($pre_line, 'updraftplus'), $args).'<br>';
		}
	}

	// This function is used by cloud methods to provide standardised logging, but more importantly to help us detect that meaningful activity took place during a resumption run, so that we can schedule further resumptions if it is worthwhile
	public function record_uploaded_chunk($percent, $extra = '', $file_path = false) {

		// Touch the original file, which helps prevent overlapping runs
		if ($file_path) touch($file_path);

		// What this means in effect is that at least one of the files touched during the run must reach this percentage (so lapping round from 100 is OK)
		if ($percent > 0.7 * ($this->current_resumption - max($this->jobdata_get('uploaded_lastreset'), 9))) $this->something_useful_happened();

		// Log it
		global $updraftplus_backup;
		$log = (!empty($updraftplus_backup->current_service)) ? ucfirst($updraftplus_backup->current_service)." chunked upload: $percent % uploaded" : '';
		if ($log) $this->log($log.(($extra) ? " ($extra)" : ''));
		// If we are on an 'overtime' resumption run, and we are still meaningfully uploading, then schedule a new resumption
		// Our definition of meaningful is that we must maintain an overall average of at least 0.7% per run, after allowing 9 runs for everything else to get going
		// i.e. Max 100/.7 + 9 = 150 runs = 760 minutes = 12 hrs 40, if spaced at 5 minute intervals. However, our algorithm now decreases the intervals if it can, so this should not really come into play
		// If they get 2 minutes on each run, and the file is 1Gb, then that equals 10.2Mb/120s = minimum 59Kb/s upload speed required

		$upload_status = $this->jobdata_get('uploading_substatus');
		if (is_array($upload_status)) {
			$upload_status['p'] = $percent/100;
			$this->jobdata_set('uploading_substatus', $upload_status);
		}

	}

	function chunked_upload($caller, $file, $cloudpath, $logname, $chunk_size, $uploaded_size) {

		$fullpath = $this->backups_dir_location().'/'.$file;
		$orig_file_size = filesize($fullpath);
		if ($uploaded_size >= $orig_file_size) return true;

		$fp = @fopen($fullpath, 'rb');
		if (!$fp) {
			$this->log("$logname: failed to open file: $fullpath");
			$this->log("$file: ".sprintf(__('%s Error: Failed to open local file','updraftplus'), $logname), 'error');
			return false;
		}

		$chunks = floor($orig_file_size / $chunk_size);
		// There will be a remnant unless the file size was exactly on a 5Mb boundary
		if ($orig_file_size % $chunk_size > 0 ) $chunks++;

		$this->log("$logname upload: $file (chunks: $chunks) -> $cloudpath ($uploaded_size)");

		if ($chunks < 2) {
			return 1;
		} else {
			$errors_so_far = 0;
			for ($i = 1 ; $i <= $chunks; $i++) {

				$upload_start = ($i-1)*$chunk_size;
				// The file size -1 equals the byte offset of the final byte
				$upload_end = min($i*$chunk_size-1, $orig_file_size-1);
				// Don't forget the +1; otherwise the last byte is omitted
				$upload_size = $upload_end - $upload_start + 1;

				fseek($fp, $upload_start);

				$uploaded = $caller->chunked_upload($file, $fp, $i, $upload_size, $upload_start, $upload_end);

				if ($uploaded) {
					$perc = round(100*((($i-1) * $chunk_size) + $upload_size)/max($orig_file_size, 1), 1);
					# $perc = round(100*$i/$chunks,1); # Takes no notice of last chunk likely being smaller
					$this->record_uploaded_chunk($perc, $i, $fullpath);
				} else {
					$errors_so_far++;
					if ($errors_so_far>=3) return false;
				}
			}
			if ($errors_so_far) return false;

			// All chunks are uploaded - now combine the chunks
			$ret = true;
			if (method_exists($caller, 'chunked_upload_finish')) {
				$ret = $caller->chunked_upload_finish($file);
				if (!$ret) {
					$this->log("$logname - failed to re-assemble chunks (".$e->getMessage().')');
					$this->log(sprintf(__('%s error - failed to re-assemble chunks', 'updraftplus'), $logname).' ('.$e->getMessage().')', 'error');
				}
			}
			if ($ret) {
				$this->log("$logname upload: success");
				$this->uploaded_file($file);
			}

			return $ret;

		}
	}

	public function chunked_download($file, $method, $remote_size, $manually_break_up = false, $passback = null) {

		try {

			$fullpath = $this->backups_dir_location().'/'.$file;
			$start_offset = (file_exists($fullpath)) ? filesize($fullpath): 0;

			if ($start_offset >= $remote_size) {
				$this->log("File is already completely downloaded ($start_offset/$remote_size)");
				return true;
			}

			// Some more remains to download - so let's do it
			if (!$fh = fopen($fullpath, 'a')) {
				$this->log("Error opening local file: $fullpath");
				$this->log($file.": ".__("Error",'updraftplus').": ".__('Error opening local file: Failed to download','updraftplus'), 'error');
				return false;
			}

			$last_byte = ($manually_break_up) ? min($remote_size, $start_offset + 1048576) : $remote_size;

			while ($start_offset < $remote_size) {
				$headers = array();
				// If resuming, then move to the end of the file
				$this->log("$file: local file is status: $start_offset/$remote_size bytes; requesting next ".($last_byte-$start_offset)." bytes");
				if ($start_offset >0 || $last_byte<$remote_size) {
					fseek($fh, $start_offset);
					$headers['Range'] = "bytes=$start_offset-$last_byte";
				}

				$ret = $method->chunked_download($file, $headers, $passback);
				if (false === $ret) return false;

				if (!fwrite($fh, $ret)) throw new Exception('Write failure');

				clearstatcache();
				$start_offset = ftell($fh);
				$last_byte = ($manually_break_up) ? min($remote_size, $start_offset + 1048576) : $remote_size;

			}

		} catch(Exception $e) {
			$this->log('Error ('.get_class($e).') - failed to download the file ('.$e->getCode().', '.$e->getMessage().')');
			$this->log("$file: ".__('Error - failed to download the file','updraftplus').' ('.$e->getCode().', '.$e->getMessage().')' ,'error');
			return false;
		}

		fclose($fh);

		return true;
	}

	public function decrypt($fullpath, $key, $ciphertext = false) {
		$this->ensure_phpseclib('Crypt_Rijndael', 'Crypt/Rijndael');
		$rijndael = new Crypt_Rijndael();
		$rijndael->setKey($key);
		return (false == $ciphertext) ? $rijndael->decrypt(file_get_contents($fullpath)) : $rijndael->decrypt($ciphertext);
	}

	function detect_safe_mode() {
		return (@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") ? 1 : 0;
	}

	public function find_working_sqldump($logit = true, $cacheit = true) {

		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if ($this->detect_safe_mode() || !function_exists('popen') || !function_exists('escapeshellarg')) {
			if ($cacheit) $this->jobdata_set('binsqldump', false);
			return false;
		}
		$existing = $this->jobdata_get('binsqldump', null);
		# Theoretically, we could have moved machines, due to a migration
		if (null !== $existing && (!is_string($existing) || @is_executable($existing))) return $existing;

		$updraft_dir = $this->backups_dir_location();
		global $wpdb;
		$table_name = $wpdb->get_blog_prefix().'options';
		$tmp_file = md5(time().rand()).".sqltest.tmp";
		$pfile = md5(time().rand()).'.tmp';
		file_put_contents($updraft_dir.'/'.$pfile, "[mysqldump]\npassword=".DB_PASSWORD."\n");

		$result = false;
		foreach (explode(',', UPDRAFTPLUS_MYSQLDUMP_EXECUTABLE) as $potsql) {
			if (!@is_executable($potsql)) continue;
			if ($logit) $this->log("Testing: $potsql");

			$exec = "cd ".escapeshellarg($updraft_dir)."; $potsql  --defaults-file=$pfile --max_allowed_packet=1M --quote-names --add-drop-table --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --where=option_name=\\'siteurl\\' --user=".escapeshellarg(DB_USER)." --host=".escapeshellarg(DB_HOST)." ".DB_NAME." ".escapeshellarg($table_name)." >$tmp_file";

			$handle = popen($exec, "r");
			if ($handle) {
				while (!feof($handle)) {
					$w = fgets($handle);
					if ($w && $logit) $this->log("Output: ".trim($w));
				}
				$ret = pclose($handle);
				if ($ret !=0) {
					if ($logit) $this->log("Binary mysqldump: error (code: $ret)");
				} else {
					$dumped = file_get_contents($updraft_dir.'/'.$tmp_file, false, null, 0, 4096);
					if (stripos($dumped, 'insert into') !== false) {
						if ($logit) $this->log("Working binary mysqldump found: $potsql");
						$result = $potsql;
						break;
					}
				}
			} else {
				if ($logit) $this->log("Error: popen failed");
			}
		}

		@unlink($updraft_dir.'/'.$pfile);
		@unlink($updraft_dir.'/'.$tmp_file);

		if ($cacheit) $this->jobdata_set('binsqldump', $result);

		return $result;
	}

	# We require -@ and -u -r to work - which is the usual Linux binzip
	function find_working_bin_zip($logit = true, $cacheit = true) {
		if ($this->detect_safe_mode()) return false;
		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if (!function_exists('popen') || !function_exists('proc_open') || !function_exists('escapeshellarg')) {
			if ($cacheit) $this->jobdata_set('binzip', false);
			return false;
		}

		$existing = $this->jobdata_get('binzip', null);
		# Theoretically, we could have moved machines, due to a migration
		if (null !== $existing && (!is_string($existing) || @is_executable($existing))) return $existing;

		$updraft_dir = $this->backups_dir_location();
		foreach (explode(',', UPDRAFTPLUS_ZIP_EXECUTABLE) as $potzip) {
			if (!@is_executable($potzip)) continue;
			if ($logit) $this->log("Testing: $potzip");

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
								if (!empty($last_error) && $logit) $this->log("Stderr output: ".trim($w));
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
					if ($cacheit) $this->jobdata_set('binzip', $potzip);
					return $potzip;
				}

			}
			$this->remove_binzip_test_files($updraft_dir);
		}
		if ($cacheit) $this->jobdata_set('binzip', false);
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
	public function record_still_alive() {
		// Update the record of maximum detected runtime on each run
		$time_passed = $this->jobdata_get('run_times');
		if (!is_array($time_passed)) $time_passed = array();

		$time_this_run = microtime(true)-$this->opened_log_time;
		$time_passed[$this->current_resumption] = $time_this_run;
		$this->jobdata_set('run_times', $time_passed);

		$resume_interval = $this->jobdata_get('resume_interval');
		if ($time_this_run + 30 > $resume_interval) {
			$new_interval = ceil($time_this_run + 30);
			set_site_transient('updraft_initial_resume_interval', (int)$new_interval, 8*86400);
			$this->log("The time we have been running (".round($time_this_run,1).") is approaching the resumption interval ($resume_interval) - increasing resumption interval to $new_interval");
			$this->jobdata_set('resume_interval', $new_interval);
		}

	}

	public function something_useful_happened() {

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

	public function option_filter_get($which) {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $which));
		// Has to be get_row instead of get_var because of funkiness with 0, false, null values
		return (is_object($row)) ? $row->option_value : false;
	}

	// This important function returns a list of file entities that can potentially be backed up (subject to users settings), and optionally further meta-data about them
	public function get_backupable_file_entities($include_others = true, $full_info = false) {

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

	# This is just a long-winded way of forcing WP to get the value afresh from the db, instead of using the auto-loaded/cached value (which can be out of date, especially since backups are, by their nature, long-running)
	public function filter_updraft_backup_history($v) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'updraft_backup_history' ) );
		if (is_object($row )) return maybe_unserialize($row->option_value);
		return false;
	}

	public function php_error_to_logline($errno, $errstr, $errfile, $errline) {
		switch ($errno) {
			case 1:		$e_type = 'E_ERROR'; break;
			case 2:		$e_type = 'E_WARNING'; break;
			case 4:		$e_type = 'E_PARSE'; break;
			case 8:		$e_type = 'E_NOTICE'; break;
			case 16:		$e_type = 'E_CORE_ERROR'; break;
			case 32:		$e_type = 'E_CORE_WARNING'; break;
			case 64:		$e_type = 'E_COMPILE_ERROR'; break;
			case 128:		$e_type = 'E_COMPILE_WARNING'; break;
			case 256:		$e_type = 'E_USER_ERROR'; break;
			case 512:		$e_type = 'E_USER_WARNING'; break;
			case 1024:	$e_type = 'E_USER_NOTICE'; break;
			case 2048:	$e_type = 'E_STRICT'; break;
			case 4096:	$e_type = 'E_RECOVERABLE_ERROR'; break;
			case 8192:	$e_type = 'E_DEPRECATED'; break;
			case 16384:	$e_type = 'E_USER_DEPRECATED'; break;
			case 30719:	$e_type = 'E_ALL'; break;
			default:		$e_type = "E_UNKNOWN ($errno)"; break;
		}

		if (!is_string($errstr)) $errstr = serialize($errstr);

		if (0 === strpos($errfile, ABSPATH)) $errfile = substr($errfile, strlen(ABSPATH));

		return "PHP event: code $e_type: $errstr (line $errline, $errfile)";

	}

	public function php_error($errno, $errstr, $errfile, $errline) {
		if (0 == error_reporting()) return true;
		$logline = $this->php_error_to_logline($errno, $errstr, $errfile, $errline);
		$this->log($logline);
		# Pass it up the chain
		return false;
	}

	public function backup_resume($resumption_no, $bnonce) {

		set_error_handler(array($this, 'php_error'), E_ALL & ~E_STRICT);

		$this->current_resumption = $resumption_no;

		// 15 minutes
		@set_time_limit(900);
		@ignore_user_abort(true);

		$runs_started = array();
		$time_now = microtime(true);

		add_filter('pre_option_updraft_backup_history', array($this, 'filter_updraft_backup_history'));

		// Restore state
		$resumption_extralog = '';
		$prev_resumption = $resumption_no - 1;
		$last_successful_resumption = -1;

		if ($resumption_no > 0) {
			$this->nonce = $bnonce;
			$this->backup_time = $this->jobdata_get('backup_time');
			# TODO: Remove legacy use of backup_time_ms after 1 Jan 2014
			$bts = $this->jobdata_get('backup_time_ms');
			if (!empty($bts)) {
				$this->job_time_ms = $this->jobdata_get('backup_time_ms');
			} else {
				$this->job_time_ms = $this->jobdata_get('job_time_ms');
			}
			# Get the warnings before opening the log file, as opening the log file may generate new ones (which then leads to $this->errors having duplicate entries when they are copied over below)
			$warnings = $this->jobdata_get('warnings');
			$this->logfile_open($bnonce);
			// Import existing warnings. The purpose of this is so that when save_backup_history() is called, it has a complete set - because job data expires quickly, whilst the warnings of the last backup run need to persist
			if (is_array($warnings)) {
				foreach ($warnings as $warning) {
					$this->errors[] = array('level' => 'warning', 'message' => $warning);
				}
			}

			$runs_started = $this->jobdata_get('runs_started');
			if (!is_array($runs_started)) $runs_started=array();
			$time_passed = $this->jobdata_get('run_times');
			if (!is_array($time_passed)) $time_passed = array();
			foreach ($time_passed as $run => $passed) {
				if (isset($runs_started[$run]) && $runs_started[$run] + $time_passed[$run] + 30 > $time_now) {
					$this->terminate_due_to_activity('check-in', round($time_now,1), round($runs_started[$run] + $time_passed[$run],1));
				}
			}

			for ($i = 0; $i<=$prev_resumption; $i++) {
				if (isset($time_passed[$i])) $last_successful_resumption = $i;
			}

			if (isset($time_passed[$prev_resumption])) {
				$resumption_extralog = ", previous check-in=".round($time_passed[$prev_resumption], 1)."s";
			} else {
				$this->no_checkin_last_time = true;
			}
			

			# This is just a simple test to catch restorations of old backup sets where the backup includes a resumption of the backup job
			if ($time_now - $this->backup_time > 172800) {
				$this->log('This backup began over 2 days ago: aborting');
				die;
			}

		}
		$this->last_successful_resumption = $last_successful_resumption;

		$runs_started[$resumption_no] = $time_now;
		if (!empty($this->backup_time)) $this->jobdata_set('runs_started', $runs_started);

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = max(intval($this->jobdata_get('resume_interval')), 100);

		$btime = $this->backup_time;
		$job_type = $this->jobdata_get('job_type');

		$updraft_dir = $this->backups_dir_location();

		$time_ago = time()-$btime;

		$this->log("Backup run: resumption=$resumption_no, nonce=$bnonce, begun at=$btime (${time_ago}s ago), job type=$job_type".$resumption_extralog);

		// This works round a bizarre bug seen in one WP install, where delete_transient and wp_clear_scheduled_hook both took no effect, and upon 'resumption' the entire backup would repeat.
		// Argh. In fact, this has limited effect, as apparently (at least on another install seen), the saving of the updated transient via jobdata_set() also took no effect. Still, it does not hurt.
		if (($resumption_no >= 1 && 'finished' == $this->jobdata_get('jobstatus')) || (!empty($this->backup_is_already_complete))) {
			$this->log('Terminate: This backup job is already finished.');
			die;
		}

		if ($resumption_no > 0 && isset($runs_started[$prev_resumption])) {
			$our_expected_start = $runs_started[$prev_resumption] + $resume_interval;
			# If the previous run increased the resumption time, then it is timed from the end of the previous run, not the start
			if (isset($time_passed[$prev_resumption]) && $time_passed[$prev_resumption]>0) $our_expected_start += $time_passed[$prev_resumption];
			# More than 12 minutes late?
			if ($time_now > $our_expected_start + 720) {
				$this->log('Long time past since expected resumption time: approx expected='.round($our_expected_start,1).", now=".round($time_now, 1).", diff=".round($time_now-$our_expected_start,1));
				$this->log(__('Your website is visited infrequently and UpdraftPlus is not getting the resources it hoped for; please read this page:', 'updraftplus').' http://updraftplus.com/faqs/why-am-i-getting-warnings-about-my-site-not-having-enough-visitors/', 'warning', 'infrequentvisits');
			}
		}

		// We just do this once, as we don't want to be in permanent conflict with the overlap detector
		if ($resumption_no >= 8 && $resumption_no < 15 && $resume_interval >= 300) {

			// $time_passed is set earlier
			list($max_time, $timings_string, $run_times_known) = $this->max_time_passed($time_passed, $resumption_no - 1);

			# Do this on resumption 8, or the first time that we have 6 data points
			if ((8 == $resumption_no && $run_times_known >= 6) || (6 == $run_times_known && !empty($time_passed[$prev_resumption]))) {
				$this->log("Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time)");
				// Remember that 30 seconds is used as the 'perhaps something is still running' detection threshold, and that 45 seconds is used as the 'the next resumption is approaching - reschedule!' interval
				if ($max_time + 52 < $resume_interval) {
					$resume_interval = round($max_time + 52);
					$this->log("Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds");
					$this->jobdata_set('resume_interval', $resume_interval);
				}
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
			// We're in over-time - we only reschedule if something useful happened last time (used to be that we waited for it to happen this time - but that meant that temporary errors, e.g. Google 400s on uploads, scuppered it all - we'd do better to have another chance
			$useful_checkin = $this->jobdata_get('useful_checkin');
			$last_resumption = $resumption_no-1;

			if (empty($useful_checkin) || $useful_checkin < $last_resumption) {
				$this->log(sprintf('The current run is resumption number %d, and there was nothing useful done on the last run (last useful run: %s) - will not schedule a further attempt until we see something useful happening this time', $resumption_no, $useful_checkin));
			} else {
				$schedule_resumption = true;
			}
		}

		// Sanity check
		if (empty($this->backup_time)) {
			$this->log('The backup_time parameter appears to be empty (usually caused by resuming an already-complete backup).');
			return false;
		}

		if (isset($schedule_resumption)) {
			$schedule_for = time()+$resume_interval;
			$this->log("Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for) in case this run gets aborted");
			wp_schedule_single_event($schedule_for, 'updraft_backup_resume', array($next_resumption, $bnonce));
			$this->newresumption_scheduled = $schedule_for;
		}

		$backup_files = $this->jobdata_get('backup_files');

		global $updraftplus_backup;
		// Bring in all the backup routines
		if (!is_a($updraftplus_backup, 'UpdraftPlus_Backup')) {
			require_once(UPDRAFTPLUS_DIR.'/backup.php');
			$updraftplus_backup = new UpdraftPlus_Backup($backup_files);
		}

		$undone_files = array();

		if ('no' == $backup_files) {
			$this->log("This backup run is not intended for files - skipping");
			$our_files = array();
		} else {

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

		}

		$backup_databases = $this->jobdata_get('backup_database');

		if (!is_array($backup_databases)) $backup_databases = array('wp' => $backup_databases);

		foreach ($backup_databases as $whichdb => $backup_database) {

			if (is_array($backup_database)) {
				$dbinfo = $backup_database['dbinfo'];
				$backup_database = $backup_database['status'];
			} else {
				$dbinfo = array();
			}

			$tindex = ('wp' == $whichdb) ? 'db' : 'db'.$whichdb;

			if ('begun' == $backup_database || 'finished' == $backup_database || 'encrypted' == $backup_database) {

				if ('wp' == $whichdb) {
					$db_descrip = 'WordPress DB';
				} else {
					if (!empty($dbinfo) && is_array($dbinfo) && !empty($dbinfo['host'])) {
						$db_descrip = "External DB $whichdb - ".$dbinfo['user'].'@'.$dbinfo['host'].'/'.$dbinfo['name'];
					} else {
						$db_descrip = "External DB $whichdb - details appear to be missing";
					}
				}

				if ('begun' == $backup_database) {
					if ($resumption_no > 0) {
						$this->log("Resuming creation of database dump ($db_descrip)");
					} else {
						$this->log("Beginning creation of database dump ($db_descrip)");
					}
				} elseif ('encrypted' == $backup_database) {
					$this->log("Database dump ($db_descrip): Creation and encryption were completed already");
				} else {
					$this->log("Database dump ($db_descrip): Creation was completed already");
				}

				if ('wp' != $whichdb && (empty($dbinfo) || !is_array($dbinfo) || empty($dbinfo['host']))) {
					unset($backup_databases[$whichdb]);
					$this->jobdata_set('backup_database', $backup_databases);
					continue;
				}

				$db_backup = $updraftplus_backup->backup_db($backup_database, $whichdb, $dbinfo);

				if(is_array($our_files) && is_string($db_backup)) $our_files[$tindex] = $db_backup;

				if ('encrypted' != $backup_database) {
					$backup_databases[$whichdb] = array('status' => 'finished', 'dbinfo' => $dbinfo);
					$this->jobdata_set('backup_database', $backup_databases);
				}
			} elseif ('no' == $backup_database) {
				$this->log("No database backup ($whichdb) - not part of this run");
			} else {
				$this->log("Unrecognised data when trying to ascertain if the database ($whichdb) was backed up (".serialize($backup_database).")");
			}

			// Save this to our history so we can track backups for the retain feature
			$this->log("Saving backup history");
			// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
			$this->save_backup_history($our_files);

			// Potentially encrypt the database if it is not already
			if (isset($our_files[$tindex]) && !preg_match("/\.crypt$/", $our_files[$tindex])) {
				$our_files[$tindex] = $updraftplus_backup->encrypt_file($our_files[$tindex]);
				// No need to save backup history now, as it will happen in a few lines time
				if (preg_match("/\.crypt$/", $our_files[$tindex])) {
					$backup_databases[$whichdb] = array('status' => 'encrypted', 'dbinfo' => $dbinfo);
					$this->jobdata_set('backup_database', $backup_databases);
				}
			}

			if (isset($our_files[$tindex]) && file_exists($updraft_dir.'/'.$our_files[$tindex])) {
				$our_files[$tindex.'-size'] = filesize($updraft_dir.'/'.$our_files[$tindex]);
				$this->save_backup_history($our_files);
			}

		}

		$backupable_entities = $this->get_backupable_file_entities(true);

		$checksums = array('sha1' => array());

		# Queue files for upload
		foreach ($our_files as $key => $files) {
			// Only continue if the stored info was about a dump
			if (!isset($backupable_entities[$key]) && ('db' != substr($key, 0, 2) || '-size' == substr($key, -5, 5))) continue;
			if (is_string($files)) $files = array($files);
			foreach ($files as $findex => $file) {
				$sha = $this->jobdata_get('sha1-'.$key.$findex);
				if ($sha) $checksums['sha1'][$key.$findex] = $sha;
				$sha = $this->jobdata_get('sha1-'.$key.$findex.'.crypt');
				if ($sha) $checksums['sha1'][$key.$findex.".crypt"] = $sha;
				if ($this->is_uploaded($file)) {
					$this->log("$file: $key: This file has already been successfully uploaded");
				} elseif (is_file($updraft_dir.'/'.$file)) {
					$this->log("$file: $key: This file has not yet been successfully uploaded: will queue");
					$undone_files[$key.$findex] = $file;
				} else {
					$this->log("$file: $key: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem ($updraft_dir/$file)");
					$this->uploaded_file($file, true);
				}
			}
		}
		$our_files['checksums'] = $checksums;

		# Save again (now that we have checksums)
		$this->save_backup_history($our_files);
		do_action('updraft_final_backup_history', $our_files);

		// We finished; so, low memory was not a problem
		$this->log_removewarning('lowram');

		if (count($undone_files) == 0) {
			$this->log("Resume backup ($bnonce, $resumption_no): finish run");
			$this->log("There were no more files that needed uploading; backup job is complete");
			// No email, as the user probably already got one if something else completed the run
			$this->backup_finish($next_resumption, true, false, $resumption_no);
			restore_error_handler();
			return;
		} else {
			$this->log("Requesting upload of the files that have not yet been successfully uploaded (".count($undone_files).")");
			$updraftplus_backup->cloud_backup($undone_files);
		}

		$this->log("Resume backup ($bnonce, $resumption_no): finish run");
		if (is_array($our_files)) $this->save_last_backup($our_files);
		$this->backup_finish($next_resumption, true, true, $resumption_no);

		restore_error_handler();

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

	function backup_all($skip_cloud) {
		$this->boot_backup(1, 1, false, false, ($skip_cloud) ? 'none' : false);
	}
	
	function backup_files() {
		# Note that the "false" for database gets over-ridden automatically if they turn out to have the same schedules
		$this->boot_backup(true, false);
	}
	
	function backup_database() {
		# Note that nothing will happen if the file backup had the same schedule
		$this->boot_backup(false, true);
	}

	function backupnow_files($skip_cloud) {
		$this->boot_backup(1, 0, false, false, ($skip_cloud) ? 'none' : false);
	}
	
	function backupnow_database($skip_cloud) {
		$this->boot_backup(0, 1, false, false, ($skip_cloud) ? 'none' : false);
	}

	public function jobdata_getarray($non) {
		return get_site_option("updraft_jobdata_".$non, array());
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
		if (!empty($this->nonce)) update_site_option("updraft_jobdata_".$this->nonce, $this->jobdata);
	}

	public function jobdata_set($key, $value) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_site_option("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) $this->jobdata = array();
		}
		$this->jobdata[$key] = $value;
		update_site_option("updraft_jobdata_".$this->nonce, $this->jobdata);
	}

	public function jobdata_delete($key) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_site_option("updraft_jobdata_".$this->nonce);
			if (!is_array($this->jobdata)) $this->jobdata = array();
		}
		unset($this->jobdata[$key]);
		update_site_option("updraft_jobdata_".$this->nonce, $this->jobdata);
	}

	public function get_job_option($opt) {
		// These are meant to be read-only
		if (empty($this->jobdata['option_cache']) || !is_array($this->jobdata['option_cache'])) {
			if (!is_array($this->jobdata)) $this->jobdata = get_site_option("updraft_jobdata_".$this->nonce, array());
			$this->jobdata['option_cache'] = array();
		}
		return (isset($this->jobdata['option_cache'][$opt])) ? $this->jobdata['option_cache'][$opt] : UpdraftPlus_Options::get_updraft_option($opt);
	}

	public function jobdata_get($key, $default = null) {
		if (!is_array($this->jobdata)) {
			$this->jobdata = get_site_option("updraft_jobdata_".$this->nonce, array());
			if (!is_array($this->jobdata)) return $default;
		}
		return (isset($this->jobdata[$key])) ? $this->jobdata[$key] : $default;
	}

	// This procedure initiates a backup run
	// $backup_files/$backup_database: true/false = yes/no (over-write allowed); 1/0 = yes/no (force)
	public function boot_backup($backup_files, $backup_database, $restrict_files_to_override = false, $one_shot = false, $service = false) {

		@ignore_user_abort(true);
		// 15 minutes
		@set_time_limit(900);

		//generate backup information
		$this->backup_time_nonce();
		// The current_resumption is consulted within logfile_open()
		$this->current_resumption = 0;
		$this->logfile_open($this->nonce);

		if (!is_file($this->logfile_name)) {
			$this->log('Failed to open log file ('.$this->logfile_name.') - you need to check your UpdraftPlus settings (your chosen directory for creating files in is not writable, or you ran out of disk space). Backup aborted.');
			$this->log(__('Could not create files in the backup directory. Backup aborted - check your UpdraftPlus settings.','updraftplus'), 'error');
			return false;
		}

		// Some house-cleaning
		$this->clean_temporary_files();
		// Log some information that may be helpful
		$this->log("Tasks: Backup files: $backup_files (schedule: ".UpdraftPlus_Options::get_updraft_option('updraft_interval', 'unset').") Backup DB: $backup_database (schedule: ".UpdraftPlus_Options::get_updraft_option('updraft_interval_database', 'unset').")");

		if (false === $one_shot && is_bool($backup_database)) {
			# If the files and database schedules are the same, and if this the file one, then we rope in database too.
			# On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.
			if ('manual' != UpdraftPlus_Options::get_updraft_option('updraft_interval') && (UpdraftPlus_Options::get_updraft_option('updraft_interval') == UpdraftPlus_Options::get_updraft_option('updraft_interval_database') || UpdraftPlus_Options::get_updraft_option('updraft_interval_database', 'xyz') == 'xyz' )) {
				$backup_database = ($backup_files == true) ? true : false;
			}
			$this->log("Processed schedules. Tasks now: Backup files: $backup_files Backup DB: $backup_database");
		}

		$semaphore = (($backup_files) ? 'f' : '') . (($backup_database) ? 'd' : '');

		// Make sure the options for semaphores exist
		global $wpdb;
		$results = $wpdb->get_results("
			SELECT option_id
				FROM $wpdb->options
				WHERE option_name IN ('updraftplus_locked_$semaphore', 'updraftplus_unlocked_$semaphore')
		");
		// Use of update_option() is correct here - since it is what is used in class-semaphore.php
		if (!count($results)) {
			update_option('updraftplus_unlocked_'.$semaphore, '1');
			update_option('updraftplus_last_lock_time_'.$semaphore, current_time('mysql', 1));
			update_option('updraftplus_semaphore_'.$semaphore, '0');
		}

		if (false == apply_filters('updraftplus_boot_backup', true, $backup_files, $backup_database, $one_shot)) {
			$updraftplus->log("Backup aborted (via filter)");
			return false;
		}

		if (!is_string($service) && !is_array($service)) $service = UpdraftPlus_Options::get_updraft_option('updraft_service');
		$service = $this->just_one($service);
		if (is_string($service)) $service = array($service);

		$option_cache = array();
		foreach ($service as $serv) {
			if ('' == $serv || 'none' == $serv) continue;
			include_once(UPDRAFTPLUS_DIR.'/methods/'.$serv.'.php');
			$cclass = 'UpdraftPlus_BackupModule_'.$serv;
			$obj = new $cclass;
			if (method_exists($cclass, 'get_credentials')) {
				$opts = $obj->get_credentials();
				if (is_array($opts)) {
					foreach ($opts as $opt) $option_cache[$opt] = UpdraftPlus_Options::get_updraft_option($opt);
				}
			}
		}
		$option_cache = apply_filters('updraftplus_job_option_cache', $option_cache);

		# If nothing to be done, then just finish
		if (!$backup_files && !$backup_database) {
			$this->backup_finish(1, false, false, 0);
			return;
		}

		require_once(UPDRAFTPLUS_DIR.'/includes/class-semaphore.php');
		$this->semaphore = UpdraftPlus_Semaphore::factory();
		$this->semaphore->lock_name = $semaphore;
		$this->log('Requesting semaphore lock ('.$semaphore.')');
		if (!$this->semaphore->lock()) {
			$this->log('Failed to gain semaphore lock ('.$semaphore.') - another backup of this type is apparently already active - aborting (if this is wrong - i.e. if the other backup crashed without removing the lock, then another can be started after 3 minutes)');
			return;
		}

		// Allow the resume interval to be more than 300 if last time we know we went beyond that - but never more than 600
		$resume_interval = (int)min(max(300, get_site_transient('updraft_initial_resume_interval')), 600);
		# We delete it because we only want to know about behaviour found during the very last backup run (so, if you move servers then old data is not retained)
		delete_site_transient('updraft_initial_resume_interval');

		$job_file_entities = array();
		if ($backup_files) {
			$possible_backups = $this->get_backupable_file_entities(true);
			foreach ($possible_backups as $youwhat => $whichdir) {
				if ((false === $restrict_files_to_override && UpdraftPlus_Options::get_updraft_option("updraft_include_$youwhat", apply_filters("updraftplus_defaultoption_include_$youwhat", true))) || (is_array($restrict_files_to_override) && in_array($youwhat, $restrict_files_to_override))) {
					// The 0 indicates the zip file index
					$job_file_entities[$youwhat] = array(
						'index' => 0
					);
				}
			}
		}

		$initial_jobdata = array(
			'resume_interval', $resume_interval,
			'job_type', 'backup',
			'jobstatus', 'begun',
			'backup_time', $this->backup_time,
			'job_time_ms', $this->job_time_ms,
			'service', $service,
			'split_every', max(intval(UpdraftPlus_Options::get_updraft_option('updraft_split_every', 800)), UPDRAFTPLUS_SPLIT_MIN),
			'maxzipbatch', 26214400, #25Mb
			'job_file_entities', $job_file_entities,
			'option_cache', $option_cache,
			'uploaded_lastreset', 9,
			'one_shot', $one_shot
		);

		if ($one_shot) update_site_option('updraft_oneshotnonce', $this->nonce);

		// Save what *should* be done, to make it resumable from this point on
		if ($backup_database) {
			$dbs = apply_filters('updraft_backup_databases', array('wp' => 'begun'));
			if (is_array($dbs)) {
				foreach ($dbs as $key => $db) {
					if ('wp' != $key && (!is_array($db) || empty($db['dbinfo']) || !is_array($db['dbinfo']) || empty($db['dbinfo']['host']))) unset($dbs[$key]);
				}
			}
		} else {
			$dbs = "no";
		}

		array_push($initial_jobdata, 'backup_database', $dbs);
		array_push($initial_jobdata, 'backup_files', (($backup_files) ? 'begun' : 'no'));

		// Use of jobdata_set_multi saves around 200ms
		call_user_func_array(array($this, 'jobdata_set_multi'), $initial_jobdata);

		// Everything is set up; now go
		$this->backup_resume(0, $this->nonce);

		if ($one_shot) delete_site_option('updraft_oneshotnonce');

	}

	function backup_finish($cancel_event, $do_cleanup, $allow_email, $resumption_no) {

		if (!empty($this->semaphore)) $this->semaphore->unlock();

		$delete_jobdata = false;

		// The valid use of $do_cleanup is to indicate if in fact anything exists to clean up (if no job really started, then there may be nothing)

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if ($this->error_count() == 0) {
			if ($do_cleanup) {
				$this->log("There were no errors in the uploads, so the 'resume' event ($cancel_event) is being unscheduled");
				# This apparently-worthless setting of metadata before deleting it is for the benefit of a WP install seen where wp_clear_scheduled_hook() and delete_transient() apparently did nothing (probably a faulty cache)
				$this->jobdata_set('jobstatus', 'finished');
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event, $this->nonce));
				# This should be unnecessary - even if it does resume, all should be detected as finished; but I saw one very strange case where it restarted, and repeated everything; so, this will help
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event+1, $this->nonce));
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event+2, $this->nonce));
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event+3, $this->nonce));
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event+4, $this->nonce));
				$delete_jobdata = true;
			}
		} else {
			$this->log("There were errors in the uploads, so the 'resume' event is remaining scheduled");
			$this->jobdata_set('jobstatus', 'resumingforerrors');
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
		if (0 == $this->error_count()) {
			$send_an_email = true;
			if ($this->error_count('warning') == 0) {
				$final_message = __('The backup apparently succeeded and is now complete','updraftplus');
				# Ensure it is logged in English. Not hugely important; but helps with a tiny number of really broken setups in which the options cacheing is broken
				if ('The backup apparently succeeded and is now complete' != $final_message) {
					$this->log('The backup apparently succeeded and is now complete');
				}
			} else {
				$final_message = __('The backup apparently succeeded (with warnings) and is now complete','updraftplus');
				if ('The backup apparently succeeded (with warnings) and is now complete' != $final_message) {
					$this->log('The backup apparently succeeded (with warnings) and is now complete');
				}
			}
		} elseif (false == $this->newresumption_scheduled) {
			$send_an_email = true;
			$final_message = __('The backup attempt has finished, apparently unsuccessfully', 'updraftplus');
		} else {
			// There are errors, but a resumption will be attempted
			$final_message = __('The backup has not finished; a resumption is scheduled', 'updraftplus');
		}

		// Now over-ride the decision to send an email, if needed
		if (UpdraftPlus_Options::get_updraft_option('updraft_debug_mode')) {
			$send_an_email = true;
			$this->log("An email has been scheduled for this job, because we are in debug mode");
		}

		$email = UpdraftPlus_Options::get_updraft_option('updraft_email');

		// If there's no email address, or the set was empty, that is the final over-ride: don't send
		if (!$allow_email) {
			$send_an_email = false;
			$this->log("No email will be sent - this backup set was empty.");
		} elseif (empty($email)) {
			$send_an_email = false;
			$this->log("No email will/can be sent - the user has not configured an email address.");
		}

		global $updraftplus_backup;
		if ($send_an_email) $updraftplus_backup->send_results_email($final_message);

		# Make sure this is the final message logged (so it remains on the dashboard)
		$this->log($final_message);

		@fclose($this->logfile_handle);

		// This is left until last for the benefit of the front-end UI, which then gets maximum chance to display the 'finished' status
		if ($delete_jobdata) delete_site_option('updraft_jobdata_'.$this->nonce);

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
		UpdraftPlus_Options::update_updraft_option('updraft_last_backup', $last_backup, false);
	}

	// This should be called whenever a file is successfully uploaded
	public function uploaded_file($file, $force = false) {
	
		global $updraftplus_backup, $wpdb;

		$db_connected = -1;

		# WP 3.9 onwards - https://core.trac.wordpress.org/browser/trunk/src/wp-includes/wp-db.php?rev=27925 - check_connection() allows us to get the database connection back if it had dropped
		if (method_exists($wpdb, 'check_connection')) {
			if (!$wpdb->check_connection(false)) {
				$updraftplus->reschedule(60);
				$updraftplus->log("It seems the database went away; scheduling a resumption and terminating for now");
				$db_connected = false;
			} else {
				$db_connected = true;
			}
		}

		$service = (empty($updraftplus_backup->current_service)) ? '' : $updraftplus_backup->current_service;
		$shash = $service.'-'.md5($file);
		$this->jobdata_set("uploaded_".$shash, 'yes');
	
		if ($force || !empty($updraftplus_backup->last_service)) {
			$hash = md5($file);
			$this->log("Recording as successfully uploaded: $file ($hash)");
			$this->jobdata_set('uploaded_lastreset', $this->current_resumption);
			$this->jobdata_set("uploaded_".$hash, 'yes');
		} else {
			$this->log("Recording as successfully uploaded: $file (".$updraftplus_backup->current_service.", more services to follow)");
		}

		$upload_status = $this->jobdata_get('uploading_substatus');
		if (is_array($upload_status) && isset($upload_status['i'])) {
			$upload_status['i']++;
			$upload_status['p']=0;
			$this->jobdata_set('uploading_substatus', $upload_status);
		}

		# Really, we could do this immediately when we realise the DB has gone away. This is just for the probably-impossible case that a DB write really can still succeed. But, we must abort before calling delete_local(), as the removal of the local file can cause it to be recreated if the DB is out of sync with the fact that it really is already uploaded
		if (false === $db_connected) {
			$updraftplus->record_still_alive();
			die;
		}

		# This parameter no longer provided or used, from UD 1.9.1 onwards
// 		if ($id) {
// 			$ids = UpdraftPlus_Options::get_updraft_option('updraft_file_ids', array() );
// 			$ids[$file] = $id;
// 			UpdraftPlus_Options::update_updraft_option('updraft_file_ids', $ids, false);
// 			$this->log("Stored file<->id correlation in database ($file <-> $id)");
// 		}

		// Delete local files immediately if the option is set
		// Where we are only backing up locally, only the "prune" function should do deleting
		if (!empty($updraftplus_backup->last_service) && ($this->jobdata_get('service') !== '' && ((is_array($this->jobdata_get('service')) && count($this->jobdata_get('service')) >0) || (is_string($this->jobdata_get('service')) && $this->jobdata_get('service') !== 'none')))) {
			$this->delete_local($file);
		}
	}

	function is_uploaded($file, $service = '') {
		$hash = $service.(('' == $service) ? '' : '-').md5($file);
		return ($this->jobdata_get("uploaded_$hash") === "yes") ? true : false;
	}

	function delete_local($file) {
		if(UpdraftPlus_Options::get_updraft_option('updraft_delete_local')) {
			$log = "Deleting local file: $file: ";
		//need error checking so we don't delete what isn't successfully uploaded?
			$fullpath = $this->backups_dir_location().'/'.$file;
			$deleted = unlink($fullpath);
			$this->log($log.(($deleted) ? 'OK' : 'failed'));
			return $deleted;
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

		$resume_interval = max(intval($this->jobdata_get('resume_interval')), 300);

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
	public function check_recent_modification($file) {
		if (file_exists($file)) {
			$time_mod = (int)@filemtime($file);
			$time_now = time();
			if ($time_mod>100 && ($time_now-$time_mod)<30) {
				$this->terminate_due_to_activity($file, $time_now, $time_mod);
			}
		}
	}

	public function get_exclude($whichone) {
		if ('uploads' == $whichone) {
			$exclude = explode(',', UpdraftPlus_Options::get_updraft_option('updraft_include_uploads_exclude', UPDRAFT_DEFAULT_UPLOADS_EXCLUDE));
		} elseif ('others' == $whichone) {
			$exclude = explode(',', UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude', UPDRAFT_DEFAULT_OTHERS_EXCLUDE));
		} else {
			$exclude = apply_filters('updraftplus_include_'.$whichone.'_exclude', array());
		}
		return (empty($exclude) || !is_array($exclude)) ? array() : $exclude;
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

	public function backup_uploads_dirlist($logit = false) {
		# Create an array of directories to be skipped
		# Make the values into the keys
		$exclude = UpdraftPlus_Options::get_updraft_option('updraft_include_uploads_exclude', UPDRAFT_DEFAULT_UPLOADS_EXCLUDE);
		if ($logit) $this->log("Exclusion option setting (uploads): ".$exclude);
		$skip = array_flip(preg_split("/,/", $exclude));
		$wp_upload_dir = wp_upload_dir();
		$uploads_dir = $wp_upload_dir['basedir'];
		return $this->compile_folder_list_for_backup($uploads_dir, array(), $skip);
	}

	public function backup_others_dirlist($logit = false) {
		# Create an array of directories to be skipped
		# Make the values into the keys
		$exclude = UpdraftPlus_Options::get_updraft_option('updraft_include_others_exclude', UPDRAFT_DEFAULT_OTHERS_EXCLUDE);
		if ($logit) $this->log("Exclusion option setting (others): ".$exclude);
		$skip = array_flip(preg_split("/,/", $exclude));
		$file_entities = $this->get_backupable_file_entities(false);

		# Keys = directory names to avoid; values = the label for that directory (used only in log files)
		#$avoid_these_dirs = array_flip($file_entities);
		$avoid_these_dirs = array();
		foreach ($file_entities as $type => $dirs) {
			if (is_string($dirs)) {
				$avoid_these_dirs[$dirs] = $type;
			} elseif (is_array($dirs)) {
				foreach ($dirs as $dir) {
					$avoid_these_dirs[$dir] = $type;
				}
			}
		}
		return $this->compile_folder_list_for_backup(WP_CONTENT_DIR, $avoid_these_dirs, $skip);
	}

	// Add backquotes to tables and db-names in SQL queries. Taken from phpMyAdmin.
	public function backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`'.$val.'`';
				return $result;
			} else {
				return '`'.$a_name.'`';
			}
		} else {
			return $a_name;
		}
	}

	public function strip_dirslash($string) {
		return preg_replace('#/+(,|$)#', '$1', $string);
	}

	public function remove_empties($list) {
		if (!is_array($list)) return $list;
		foreach ($list as $ind => $entry) {
			if (empty($entry)) unset($list[$ind]);
		}
		return $list;
	}

	// avoid_these_dirs and skip_these_dirs ultimately do the same thing; but avoid_these_dirs takes full paths whereas skip_these_dirs takes basenames; and they are logged differently (dirs in avoid are potentially dangerous to include; skip is just a user-level preference). They are allowed to overlap.
	public function compile_folder_list_for_backup($backup_from_inside_dir, $avoid_these_dirs, $skip_these_dirs) {

		// Entries in $skip_these_dirs are allowed to end in *, which means "and anything else as a suffix". It's not a full shell glob, but it covers what is needed to-date.

		$dirlist = array();
		$added = 0;

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
						// Now deal with entries in $skip_these_dirs ending in * or starting with *
						foreach ($skip_these_dirs as $skip => $sind) {
							if ('*' == substr($skip, -1, 1) && strlen($skip) > 1) {
								if (substr($entry, 0, strlen($skip)-1) == substr($skip, 0, strlen($skip)-1)) {
									$this->log("finding files: $entry: skipping: excluded by options (glob)");
									$add_to_list = false;
								}
							} elseif ('*' == substr($skip, 0, 1) && strlen($skip) > 1) {
								if (strlen($entry) >= strlen($skip)-1 && substr($entry, (strlen($skip)-1)*-1) == substr($skip, 1)) {
									$this->log("finding files: $entry: skipping: excluded by options (glob)");
									$add_to_list = false;
								}
							}
						}
						if ($add_to_list) {
							array_push($dirlist, $candidate);
							$added++;
							$skip_dblog = ($added > 50 && 0 != $added % 100);
							$this->log("finding files: $entry: adding to list ($added)", 'notice', false, $skip_dblog);
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
			UpdraftPlus_Options::update_updraft_option('updraft_backup_history', $backup_history, false);
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

	public function terminate_due_to_activity($file, $time_now, $time_mod) {
		# We check-in, to avoid 'no check in last time!' detectors firing
		$this->record_still_alive();
		$file_size = file_exists($file) ? round(filesize($file)/1024,1). 'Kb' : 'n/a';
		$this->log("Terminate: ".basename($file)." exists with activity within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".(floor($time_now-$time_mod)).", size=$file_size). This likely means that another UpdraftPlus run is at work; so we will exit.");
		$this->increase_resume_and_reschedule(120, true);
		if (!defined('UPDRAFTPLUS_ALLOW_RECENT_ACTIVITY') || true != UPDRAFTPLUS_ALLOW_RECENT_ACTIVITY) die;
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
	This function is both the backup scheduler and ostensibly a filter callback for saving the option.
	it is called in the register_setting for the updraft_interval, which means when the admin settings 
	are saved it is called.
	*/
	public function schedule_backup($interval) {

		// Clear schedule so that we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup');
		
		if ('manual' == $interval) return 'manual';

		$valid_schedules = wp_get_schedules();
		if (empty($valid_schedules[$interval])) $interval = 'daily';

		$first_time = apply_filters('updraftplus_schedule_firsttime_files', time()+30);
		wp_schedule_event($first_time, $interval, 'updraft_backup');

		return $interval;
	}

	public function schedule_backup_database($interval) {

		// Clear schedule so that we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup_database');

		if ('manual' == $interval) return 'manual';

		$valid_schedules = wp_get_schedules();
		if (empty($valid_schedules[$interval])) $interval = 'daily';

		$first_time = apply_filters('updraftplus_schedule_firsttime_db', time()+30);
		wp_schedule_event($first_time, $interval, 'updraft_backup_database');

		return $interval;

	}

	public function deactivation () {
// 		wp_clear_scheduled_hook('updraftplus_weekly_ping');
	}

	// Acts as a WordPress options filter
	public function googledrive_checkchange($google) {
		$opts = UpdraftPlus_Options::get_updraft_option('updraft_googledrive');
		if (!is_array($google)) return $opts;
		$old_client_id = (empty($opts['clientid'])) ? '' : $opts['clientid'];
		if (!empty($opts['token']) && $old_client_id != $google['clientid']) {
			require_once(UPDRAFTPLUS_DIR.'/methods/googledrive.php');
			add_action('http_api_curl', array($this, 'add_curl_capath'));
			UpdraftPlus_BackupModule_googledrive::gdrive_auth_revoke(false);
			remove_action('http_api_curl', array($this, 'add_curl_capath'));
			$google['token'] = '';
			unset($opts['ownername']);
		}
		foreach ($google as $key => $value) { $opts[$key] = $value; }
		if (isset($opts['folder'])) {
			$opts['folder'] = apply_filters('updraftplus_options_googledrive_foldername', 'UpdraftPlus', $opts['folder']);
			unset($opts['parentid']);
		}
		return $opts;
	}

	public function ftp_sanitise($ftp) {
		if (is_array($ftp) && !empty($ftp['host']) && preg_match('#ftp(es|s)?://(.*)#i', $ftp['host'], $matches)) {
			$ftp['host'] = untrailingslashit($matches[2]);
		}
		return $ftp;
	}

	// Acts as a WordPress options filter
	public function bitcasa_checkchange($bitcasa) {
		$opts = UpdraftPlus_Options::get_updraft_option('updraft_bitcasa');
		if (!is_array($opts)) $opts = array();
		if (!is_array($bitcasa)) return $opts;
		$old_client_id = (empty($opts['clientid'])) ? '' : $opts['clientid'];
		if (!empty($opts['token']) && $old_client_id != $bitcasa['clientid']) {
			unset($opts['token']);
			unset($opts['ownername']);
		}
		foreach ($bitcasa as $key => $value) { $opts[$key] = $value; }
		return $opts;
	}

	//wp-cron only has hourly, daily and twicedaily, so we need to add some of our own
	public function modify_cron_schedules($schedules) {
		$schedules['weekly'] = array('interval' => 604800, 'display' => 'Once Weekly');
		$schedules['fortnightly'] = array('interval' => 1209600, 'display' => 'Once Each Fortnight');
		$schedules['monthly'] = array('interval' => 2592000, 'display' => 'Once Monthly');
		$schedules['every4hours'] = array('interval' => 14400, 'display' => 'Every 4 hours');
		$schedules['every8hours'] = array('interval' => 28800, 'display' => 'Every 8 hours');
		return $schedules;
	}

	public function remove_local_directory($dir, $contents_only = false) {
		// PHP 5.3+ only
		//foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
		//	$path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
		//}
		//return rmdir($dir);
		$d = dir($dir);
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry) {
				if (is_dir($dir.'/'.$entry)) {
					$this->remove_local_directory($dir.'/'.$entry, false);
				} else {
					@unlink($dir.'/'.$entry);
				}
			}
		}
		$d->close();
		return ($contents_only) ? true : rmdir($dir);
	}

	// Returns without any trailing slash
	public function backups_dir_location() {

		if (!empty($this->backup_dir)) return $this->backup_dir;

		$updraft_dir = untrailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir'));
		# When newly installing, if someone had (e.g.) wp-content/updraft in their database from a previous, deleted pre-1.7.18 install but had removed the updraft directory before re-installing, without this fix they'd end up with wp-content/wp-content/updraft.
		if (preg_match('/^wp-content\/(.*)$/', $updraft_dir, $matches) && ABSPATH.'wp-content' === WP_CONTENT_DIR) {
			UpdraftPlus_Options::update_updraft_option('updraft_dir', $matches[1]);
			$updraft_dir = WP_CONTENT_DIR.'/'.$matches[1];
		}
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		$updraft_dir = ($updraft_dir) ? $updraft_dir : $default_backup_dir;

		// Do a test for a relative path
		if ('/' != substr($updraft_dir, 0, 1) && "\\" != substr($updraft_dir, 0, 1) && !preg_match('/^[a-zA-Z]:/', $updraft_dir)) {
			# Legacy - file paths stored related to ABSPATH
			if (is_dir(ABSPATH.$updraft_dir) && is_file(ABSPATH.$updraft_dir.'/index.html') && is_file(ABSPATH.$updraft_dir.'/.htaccess') && !is_file(ABSPATH.$updraft_dir.'/index.php') && false !== strpos(file_get_contents(ABSPATH.$updraft_dir.'/.htaccess', false, null, 0, 20), 'deny from all')) {
				$updraft_dir = ABSPATH.$updraft_dir;
			} else {
				# File paths stored relative to WP_CONTENT_DIR
				$updraft_dir = trailingslashit(WP_CONTENT_DIR).$updraft_dir;
			}
		}

		// Check for the existence of the dir and prevent enumeration
		// index.php is for a sanity check - make sure that we're not somewhere unexpected
		if((!is_dir($updraft_dir) || !is_file($updraft_dir.'/index.html') || !is_file($updraft_dir.'/.htaccess')) && !is_file($updraft_dir.'/index.php')) {
			@mkdir($updraft_dir, 0775, true);
			@file_put_contents($updraft_dir.'/index.html',"<html><body><a href=\"http://updraftplus.com\">WordPress backups by UpdraftPlus</a></body></html>");
			if (!is_file($updraft_dir.'/.htaccess')) @file_put_contents($updraft_dir.'/.htaccess','deny from all');
		}

		$this->backup_dir = $updraft_dir;

		return $updraft_dir;
	}

	private function spool_crypted_file($fullpath, $encryption) {
		if ('' == $encryption) $encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
		if ('' == $encryption) {
			header('Content-type: text/plain');
			_e("Decryption failed. The database file is encrypted, but you have no encryption key entered.", 'updraftplus');
			$this->log('Decryption of database failed: the database file is encrypted, but you have no encryption key entered.', 'error');
		} else {
			$ciphertext = $this->decrypt($fullpath, $encryption);
			if ($ciphertext) {
				header('Content-type: application/x-gzip');
				header("Content-Disposition: attachment; filename=\"".substr(basename($fullpath), 0, -6)."\";");
				header("Content-Length: ".strlen($ciphertext));
				print $ciphertext;
			} else {
				header('Content-type: text/plain');
				echo __("Decryption failed. The most likely cause is that you used the wrong key.",'updraftplus')." ".__('The decryption key used:','updraftplus').' '.$encryption;
				
			}
		}
		return true;
	}

	public function spool_file($type, $fullpath, $encryption = "") {
		@set_time_limit(900);

		if (file_exists($fullpath)) {

			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

			$spooled = false;
			if ('.crypt' == substr($fullpath, -6, 6)) $spooled = $this->spool_crypted_file($fullpath, $encryption);

			if (!$spooled) {

				header("Content-Length: ".filesize($fullpath));

				if ('.zip' == substr($fullpath, -4, 4)) {
					header('Content-type: application/zip');
				} elseif ('.tar' == substr($fullpath, -4, 4)) {
					header('Content-type: application/x-tar');
				} elseif ('.tar.gz' == substr($fullpath, -7, 7)) {
					header('Content-type: application/x-tgz');
				} elseif ('.tar.bz2' == substr($fullpath, -8, 8)) {
					header('Content-type: application/x-bzip-compressed-tar');
				} else {
					// When we sent application/x-gzip, we found a case where the server compressed it a second time
					header('Content-type: application/octet-stream');
				}
				header("Content-Disposition: attachment; filename=\"".basename($fullpath)."\";");
				# Prevent the file being read into memory
				@ob_end_flush();
				readfile($fullpath);
			}
		} else {
			echo __('File not found', 'updraftplus');
		}
	}

	public function retain_range($input) {
		$input = (int)$input;
		return  ($input > 0 && $input < 3650) ? $input : 1;
	}

	public function replace_http_with_webdav($input) {
		if (!empty($input['url']) && 'http' == substr($input['url'], 0, 4)) $input['url'] = 'webdav'.substr($input['url'], 4);
		return $input;
	}

	public function just_one_email($input, $required = false) {
		$x = $this->just_one($input, 'saveemails', (empty($input) && false === $required) ? '' : get_bloginfo('admin_email'));
		if (is_array($x)) {
			foreach ($x as $ind => $val) {
				if (empty($val)) unset($x[$ind]);
			}
			if (empty($x)) $x = '';
		}
		return $x;
	}

	public function just_one($input, $filter = 'savestorage', $rinput = false) {
		$oinput = $input;
		if (false === $rinput) $rinput = (is_array($input)) ? array_pop($input) : $input;
		if (is_string($rinput) && false !== strpos($rinput, ',')) $rinput = substr($rinput, 0, strpos($rinput, ','));
		return apply_filters('updraftplus_'.$filter, $rinput, $oinput);
	}

	function memory_check_current($memory_limit = false) {
		# Returns in megabytes
		if ($memory_limit == false) $memory_limit = ini_get('memory_limit');
		$memory_limit = rtrim($memory_limit);
		$memory_unit = $memory_limit[strlen($memory_limit)-1];
		if ((int)$memory_unit == 0 && $memory_unit !== '0') {
			$memory_limit = substr($memory_limit,0,strlen($memory_limit)-1);
		} else {
			$memory_unit = '';
		}
		switch($memory_unit) {
			case '':
				$memory_limit = floor($memory_limit/1048576);
			break;
			case 'K':
			case 'k':
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
		if (defined('UPDRAFTPLUS_NOADS_A')) return "";
		$rad = rand(0, 8);
		switch ($rad) {
		case 0:
			return $this->url_start($urls,'updraftplus.com').__("Want more features or paid, guaranteed support? Check out UpdraftPlus.Com", 'updraftplus').$this->url_end($urls,'updraftplus.com');
			break;
		case 1:
			if (defined('WPLANG') && strlen(WPLANG)>0 && !is_file(UPDRAFTPLUS_DIR.'/languages/updraftplus-'.WPLANG.
'.mo')) return __('Can you translate? Want to improve UpdraftPlus for speakers of your language?','updraftplus').' '.$this->url_start($urls,'updraftplus.com/translate/')."Please go here for instructions - it is easy.".$this->url_end($urls,'updraftplus.com/translate/');

			return __('Like UpdraftPlus and can spare one minute?','updraftplus').$this->url_start($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform').' '.__('Please help UpdraftPlus by giving a positive review at wordpress.org','updraftplus').$this->url_end($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform');
			break;
		case 2:
			return $this->url_start($urls,'wordshell.net').__("Check out WordShell", 'updraftplus').$this->url_end($urls,'www.wordshell.net')." - ".__('manage WordPress from the command line - huge time-saver', 'updraftplus');
			break;
		case 3:
			return __('Like UpdraftPlus and can spare one minute?','updraftplus').$this->url_start($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform').' '.__('Please help UpdraftPlus by giving a positive review at wordpress.org','updraftplus').$this->url_end($urls,'wordpress.org/support/view/plugin-reviews/updraftplus#postform');
			break;
		case 4:
			return $this->url_start($urls,'www.simbahosting.co.uk').__("Need high-quality WordPress hosting from WordPress specialists? (Including automatic backups and 1-click installer). Get it from the creators of UpdraftPlus.", 'updraftplus').$this->url_end($urls,'www.simbahosting.co.uk');
			break;
		case 5:
			if (!defined('UPDRAFTPLUS_NOADS_A')) {
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
