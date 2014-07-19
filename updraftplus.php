<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: take backups locally, or backup to Amazon S3, Dropbox, Google Drive, Rackspace, (S)FTP, WebDAV & email, on automatic schedules.
Author: UpdraftPlus.Com, DavidAnderson
Version: 1.9.18
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Text Domain: updraftplus
Domain Path: /languages
Author URI: http://updraftplus.com
*/

/*
TODO - some of these are out of date/done, needs pruning
// If a current backup has a "next resumption" that is heavily negative, then provide a link for kick-starting it (i.e. to run the next resumption action via AJAX)
// If importing full WPMU backup into non-WPMU, check that they are clearly warned this is usually wrong
// Deploy FUE addon
// Check what happens on renewals-of-renewals - are they detected?
// More complex pruning options - search archive for retain/billion/complex for ideas
// Feature to despatch any not-yet-despatched backups to remote storage
// Make 'more files' restorable - require them to select a directory first
// Labels for backups
// Bring down interval if we are already in upload time (since zip delays are no longer possible). See: options-general-11-23.txt
// On free version, add note to restore page/to "delete-old-dirs" section
// Make SFTP chunked (there is a new stream wrapper)
// On plugins restore, don't let UD over-write itself - because this usually means a down-grade. Since upgrades are db-compatible, there's no reason to downgrade.
// Schedule a task to report on failure
// Copy.Com
// If ionice is available, then use it to limit I/O usage
// Get user to confirm if they check both the search/replace and wp-config boxes
// Display "Migrate" instead of "Restore" for non-native backups
// Tweak the display so that users seeing resumption messages don't think it's stuck
// On restore, check for some 'standard' PHP modules (prevents support requests related to them) -e.g. GD, Curl
// Recognise known huge non-core tables on restore, and postpone them to the end (AJAX method?)
// Add a cart notice if people have DBSF=quantity1
// Include in email report the list of "more" directories: http://updraftplus.com/forums/support-forum-group1/paid-support-forum-forum2/wordpress-multi-sites-thread121/
// Integrate jstree for a nice files-chooser; use https://wordpress.org/plugins/dropbox-photo-sideloader/ to see how it's done
// Verify that attempting to bring back a MS backup on a non-MS install warns the user
// Pre-schedule resumptions that we know will be scheduled later
// Change add-ons screen, to be less confusing for people who haven't yet updated but have connected
// Change migrate window: 1) Retain link to article 2) Have selector to choose which backup set to migrate - or a fresh one 3) Have option for FTP/SFTP/SCP despatch 4) Have big "Go" button. Have some indication of what happens next. Test the login first. Have the remote site auto-scan its directory + pick up new sets. Have a way of querying the remote site for its UD-dir. Have a way of saving the settings as a 'profile'. Or just save the last set of settings (since mostly will be just one place to send to). Implement an HTTP/JSON method for sending files too.
// Post restore, do an AJAX get for the site; if this results in a 500, then auto-turn-on WP_DEBUG
// Place in maintenance mode during restore - ?
// Test Azure: https://blogs.technet.com/b/blainbar/archive/2013/08/07/article-create-a-wordpress-site-using-windows-azure-read-on.aspx?Redirected=true
// Add some kind of automated scan for post content (e.g. images) that has the same URL base, but is not part of WP. There's an example of such a site in tmp-rich.
// Free/premium comparison page
// Complete the tweak to bring the delete-old-dirs within a dialog (just needed to deal wtih case of needing credentials more elegantly).
// More locking: lock the resumptions too (will need to manage keys to make sure junk data is not left behind)
// See: ftp-logins.log - would help if we retry FTP logins after 10 second delay (not on testing), to lessen chances of 'too many users - try again later' being terminal. Also, can we log the login error?
// Deal with missing plugins/themes/uploads directory when installing
// Add FAQ - can I get it to save automatically to my computer?
// Pruner assumes storage is same as current - ?
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
// Put a 'what do I get if I upgrade?' link into the mix
// If migrated database from somewhere else, then add note about revising UD settings
// Strategy for what to do if the updraft_dir contains untracked backups. Automatically rescan?
// MySQL manual: See Section 8.2.2.1, Speed of INSERT Statements.
// Exempt UD itself from a plugins restore? (will options be out-of-sync? exempt options too?)
// Post restore/migrate, check updraft_dir, and reset if non-existent
// Auto-empty caches post-restore/post-migration (prevent support requests from people with state/wrong cacheing data)
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
// On migrations/restores, have an option for auto-emailing the log
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

if (!defined('ABSPATH')) die('No direct access allowed');

define('UPDRAFTPLUS_DIR', dirname(__FILE__));
define('UPDRAFTPLUS_URL', plugins_url('', __FILE__));
define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,backup*,*backups');
define('UPDRAFT_DEFAULT_UPLOADS_EXCLUDE','backup*,*backups,backwpup*,wp-clone');

# The following can go in your wp-config.php
# Tables whose data can be safed without significant loss, if (and only if) the attempt to back them up fails (e.g. bwps_log, from WordPress Better Security, is log data; but individual entries can be huge and cause out-of-memory fatal errors on low-resource environments). Comma-separate the table names (without the WordPress table prefix).
if (!defined('UPDRAFTPLUS_DATA_OPTIONAL_TABLES')) define('UPDRAFTPLUS_DATA_OPTIONAL_TABLES', 'bwps_log,statpress,slim_stats,redirection_logs,Counterize,Counterize_Referers,Counterize_UserAgents,wbz404_logs,wbz404_redirects,tts_trafficstats,tts_referrer_stats');
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

// Load add-ons and files that may or may not be present, depending on where the plugin was distributed
if (is_file(UPDRAFTPLUS_DIR.'/autoload.php')) require_once(UPDRAFTPLUS_DIR.'/autoload.php');
if (is_file(UPDRAFTPLUS_DIR.'/udaddons/updraftplus-addons.php')) include_once(UPDRAFTPLUS_DIR.'/udaddons/updraftplus-addons.php');

# wp-cron only has hourly, daily and twicedaily, so we need to add some of our own
function updraftplus_modify_cron_schedules($schedules) {
	$schedules['weekly'] = array('interval' => 604800, 'display' => 'Once Weekly');
	$schedules['fortnightly'] = array('interval' => 1209600, 'display' => 'Once Each Fortnight');
	$schedules['monthly'] = array('interval' => 2592000, 'display' => 'Once Monthly');
	$schedules['every4hours'] = array('interval' => 14400, 'display' => sprintf(__('Every %s hours', 'updraftplus'), 4));
	$schedules['every8hours'] = array('interval' => 28800, 'display' => sprintf(__('Every %s hours', 'updraftplus'), 8));
	return $schedules;
}
# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules. Raised priority because some plugins wrongly over-write all prior schedule changes (including BackupBuddy!)
add_filter('cron_schedules', 'updraftplus_modify_cron_schedules', 30);

// The checks here before loading are for performance only - unless one of those conditions is met, then none of the hooks will ever be used
if (!is_admin() && (!defined('DOING_CRON') || !DOING_CRON) && (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) && empty($_SERVER['SHELL']) && empty($_SERVER['USER'])) return;

$updraftplus_have_addons = 0;
if (is_dir(UPDRAFTPLUS_DIR.'/addons') && $dir_handle = opendir(UPDRAFTPLUS_DIR.'/addons')) {
	while (false !== ($e = readdir($dir_handle))) {
		if (is_file(UPDRAFTPLUS_DIR.'/addons/'.$e) && preg_match('/\.php$/', $e)) {
			# We used to have 1024 bytes here - but this meant that if someone's site was hacked and a lot of code added at the top, and if they were running a too-low PHP version, then they might just see the symptom rather than the cause - and raise the support request with us.
			$header = file_get_contents(UPDRAFTPLUS_DIR.'/addons/'.$e, false, null, -1, 16384);
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

require_once(UPDRAFTPLUS_DIR.'/class-updraftplus.php');
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
