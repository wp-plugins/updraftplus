<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://updraftplus.com
Description: Backup and restore: take backups locally, or backup to Amazon S3, Dropbox, Google Drive, Rackspace, (S)FTP, WebDAV & email, on automatic schedules.
Author: UpdraftPlus.Com, DavidAnderson
Version: 1.9.30
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Text Domain: updraftplus
Domain Path: /languages
Author URI: http://updraftplus.com
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
if (!defined('UPDRAFTPLUS_DATA_OPTIONAL_TABLES')) define('UPDRAFTPLUS_DATA_OPTIONAL_TABLES', 'bwps_log,statpress,slim_stats,redirection_logs,Counterize,Counterize_Referers,Counterize_UserAgents,wbz404_logs,wbz404_redirects,tts_trafficstats,tts_referrer_stats,wponlinebackup_generations');
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
