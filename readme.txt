=== UpdraftPlus Backup ===
Contributors: David Anderson
Tags: backup, restore, database, cloud, amazon, s3, dropbox, google drive, ftp, cloud, back up, multisite
Requires at least: 3.2
Tested up to: 3.5.1
Stable tag: 1.4.29
Author URI: http://updraftplus.com
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later

== Upgrade Notice ==
Various tweaks and bug-fixes; plus, asynchronous downloading

== Description ==

<a href="http://updraftplus.com">UpdraftPlus</a> simplifies backups (and restoration). Backup into the cloud (Amazon S3, Dropbox, Google Drive, FTP, SFTP and email) and restore with a single click. Backups of files and database can have separate schedules.

* Supports backups to Amazon S3, Dropbox, Google Drive, FTP (including SSL), email, SFTP
* One-click restore
* Backup automatically on a repeating schedule
* Files and databases can have separate schedules
* Failed uploads are automatically resumed/retried
* Select which files to backup (plugins, themes, content, other)
* Download backup archives direct from your WordPress dashboard
* Database backups can be encrypted for security
* Debug mode that gives full logging of the backup
* Thousands of users: widely tested and reliable
* Premium/multi-site version and support available - <a href="http://updraftplus.com">http://updraftplus.com</a>

= Best New WordPress Plugin =

That's according to WordPress big cheese, Vladimir Prelovac. Check out his weekly chart to see where UpdraftPlus is right now: http://www.prelovac.com/vladimir/wordpress-plugins-rising-stars

= UpdraftPlus Addons And Premium =

UpdraftPlus is not crippled in any way - it is fully functional, with no annoying omissions. What we do have is various extra features, and guaranteed support, available <a href="http://updraftplus.com/">from our website, updraftplus.com</a>.

If you need WordPress multisite compatibility (you'll know if you do), <a href="http://updraftplus.com/shop/">then you need UpdraftPlus Premium</a>.

= Professional / Enterprise support agreements available =

UpdraftPlus is written by professional WordPress developers. If your site needs guaranteed support, then we are available. Just  <a href="http://updraftplus.com/shop/">go to our shop.</a>

= Other support =

We hang out in the support forum for this plugin - http://wordpress.org/support/plugin/updraftplus - however, to save our time so that we can spend it on development, please read the plugin's Frequently Asked Questions - <a href="http://updraftplus.com/support/frequently-asked-questions/">http://updraftplus.com/support/frequently-asked-questions/</a> - before going there, and ensure that you have updated to the latest released version of UpdraftPlus.

== Installation ==

<a href="http://updraftplus.com/download/">Please go here for full instructions</a>.

== Frequently Asked Questions ==

<a href="http://updraftplus.com/support/frequently-asked-questions/"><strong>Please go here for the full FAQs.</strong></a> Below are just a handful which apply to the free wordpress.org version, or which bear repeating.

= Can UpdraftPlus do <something>? =

Check out <a href="http://updraftplus.com/updraftplus-full-feature-list/">our full list of features</a>, and our <a href="http://updraftplus.com/shop/">add-ons shop</a>.

= I found a bug. What do I do? =

Firstly, please make sure you read this FAQ through to the end - it may already have the answer you need. If it does, then please consider a donation (http://david.dw-perspective.org.uk/donate); it takes time to develop this plugin and FAQ.

If it does not, then contact me! This is a complex plugin and the only way I can ensure it's robust is to get bug reports and fix the problems that crop up. Please make sure you are using the latest version of the plugin, and that you include the version in your bug report - if you are not using the latest, then the first thing you will be asked to do is upgrade.

Please turn on debugging mode (in the UpdraftPlus options page) and then try again, and after that send me the log if you can find it (there are links to download logs on the UpdraftPlus settings page; or you may be emailed it; failing that, it is in the directory wp-content/updraft, so FTP in and look for it there). If you cannot find the log, then I may not be able to help so much, but you can try - include as much information as you can when reporting (PHP version, your blog's site, the error you saw and how you got to the page that caused it, etcetera).

If you know where to find your PHP error logs (often a file called error_log, possibly in your wp-admin directory (check via FTP)), then that's even better (don't send me multi-megabytes; just send the few lines that appear when you run a backup, if any).

If you are a programmer and can send a patch, then that's even better.

Finally, if you post in the WordPress support forum, then make sure you include the word UpdraftPlus in your post; otherwise I will not be automatically notified that you posted.

= Anything essential to know? =

After you have set up UpdraftPlus, you must check that your backups are taking place successfully. WordPress is a complex piece of software that runs in many situations. Don't wait until you need your backups before you find out that they never worked in the first place. Remember, there's no warranty and no guarantees - this is free software.

= My enormous website is hosted by a dirt-cheap provider who starve my account of resources, and UpdraftPlus runs out of time! Help! Please make UpdraftPlus deal with this situation so that I can save two dollars! =

UpdraftPlus supports resuming backup runs right from the beginning, so that it does not need to do everything in a single go; but this has limits. If your website is huge and your web hosting company gives your tiny resources on an over-loaded server, then your solution is to purchase better web hosting, or to hire me professionally. Otherwise, this is not considered a bug. UpdraftPlus is known to successfully back up websites that run into the gigabytes on web servers that are not resource-starved.

= How is this better than the original Updraft? =

You can check the changelog for changes; but the original Updraft, before I forked it, had three major problems. Firstly, it only backed up WP core tables from the database; if any of your plugins stored data in extra tables, then they were not backed up. Secondly, it only backed up your plugins/themes/uploads and not any further directories inside wp-content that other plugins might have created. Thirdly, the database backup did not include charset information, which meant that you needed to know some SQL wizardry to actually be able to use the backup. I made UpdraftPlus out of my experience of trying to back up several sites with Updraft. Then, I added encryption for the database file for extra peace of mind, and future-proofed by getting rid of some deprecated aspects. Since then, many new features have been added, e.g. resuming of failed uploads, and Dropbox support.

= Any known bugs ? =

Not a bug, but one issue to be aware of is that backups of very large sites (lots of uploaded media) are quite complex matters, given the limits of running inside WordPress on a huge variety of different web hosting setups. With large sites, you need to use Amazon S3, which UpdraftPlus supports (since 0.9.20) or Google Drive (since 0.9.21) or Dropbox (since 1.2.19), because these support chunked, resumable uploads. Other backup methods have code (since 0.9.0) to retry failed uploads of an archive, but the upload cannot be chunked, so if an archive is enormous (i.e. cannot be completely uploaded in the time that PHP is allowed for running on your web host) it cannot work.

= My site was hacked, and I have no backups! I thought UpdraftPlus was working! Can I kill you? =

No, there's no warranty or guarantee, etc. It's completely up to you to verify that UpdraftPlus is working correctly. If it doesn't then that's unfortunate, but this is a free plugin.

= I am not running the most recent version of UpdraftPlus. Should I upgrade? =

Yes; especially before you submit any support requests.

= Have you written any other free plugins? =

Thanks for asking - yes, I have. Check out my profile page - http://profiles.wordpress.org/DavidAnderson/ . I am also available for hire for bespoke work.

== Changelog ==

= 1.4.29 - 02/23/2013 =
* Now remembers what cloud service you used for historical backups, if you later switch
* Now performs user downloads from the settings page asynchronously, meaning that enormous backups can be fetched this way
* Fixed bug which forced GoogleDrive users to re-authenticate unnecessarily
* Fixed apparent race condition that broke some backups
* Include disk free space warning
* More intelligent scheduling of resumptions, leading to faster completion on hosts with low max_execution_time values
* Polls and updates in-page backup history status (no refresh required)
* Hooks for SFTP + encrypted FTP add-on

= 1.4.14 - 02/19/2013 =
* Display final status message in email
* Clean-up any old temporary files detected

= 1.4.13 - 02/18/2013 =
* Some extra hooks for "fix time" add-on (http://updraftplus.com/shop/fix-time/)
* Some internal simplification
* Small spelling + text fixes

= 1.4.11 - 02/13/2013 =
* Various branding tweaks - <a href="http://updraftplus.com">launch of updraftplus.com</a>
* Important fix for people with non-encrypted database backups

= 1.4.9 - 02/12/2013 =
* Do more when testing Amazon S3 connectivity (catches users with bucket but not file access)
* Tweak algorithm for detecting useful activity to further help gigantic sites

= 1.4.7 - 02/09/2013 =
* Tweak for some Amazon EU West 1 bucket users

= 1.4.6 - 02/07/2013 =
* Amazon S3 now works for users with non-US buckets
* Further tweak to overlap detection

= 1.4.2 - 02/06/2013 = 
* More Amazon S3 logging which should help people with wrong details
* More race/overlap detection, and more flexible rescheduling

= 1.4.0 - 02/04/2013 =
* Zip file creation is now resumable; and thus the entire backup operation is; there is now no "too early to resume" point. So even the most enormous site backups should now be able to proceed.
* Prefer PHP's native zip functions if available - 25% speed-up on zip creation

= 1.3.22 - 01/31/2013 =
* More help for really large uploads; dynamically alter the maximum number of resumption attempts if something useful is still happening

= 1.3.20 - 01/30/2013 =
* Add extra error checking in S3 method (can prevent logging loop)

= 1.3.19 - 01/29/2013 =
* Since 1.3.3, the 'Last Backup' indicator in the control panel had not been updating

= 1.3.18 - 01/28/2013 =
* Made 'expert mode' easier to operate, and tidier options for non-expert users.
* Some (not total) compliance with PHP's strict coding standards mode
* More detail provided when failing to authorise with Google

= 1.3.15 - 01/26/2013 =
* Various changes to Google Drive authentication to help those who don't enter the correct details first time, or who later need to change accounts.

= 1.3.12 - 01/25/2013 =
* 1.3.0 to 1.3.8 had a fatal flaw for people with large backups.
* 1.3.0 to 1.3.9 gave erroneous information in the email reports on what the backup contained.
* Fixed DropBox authentication for some users who were having problems

= 1.3.8 - 01/24/2013 =
* Fixed faulty assumptions in 'resume' code, now leading to more reliable resuming
* Removed some duplicate code; first attempt and resumptions now uses same code
* Added further parameters that should be removed on a wipe operation
* More logging of detected double runs

= 1.3.2 - 01/23/2013 =
* Internal reorganisation, enabling UpdraftPlus Premium

= 1.2.46 - 01/22/2013 =
* Easier Dropbox setup (we are now an official production app)
* New button to delete all existing settings
* Admin console now displays rolling status updates
* Feature: choose how many files and databases to retain separately
* Fixed bug with checking access token on Google Drive restore
* Fixed bug producing copious warnings in PHP log
* Fixed bug in automated restoration processes
* Possibly fixed settings saving bug in RTL installations
* Fix erroneous display of max_execution_time warning
* Better logging when running a DB debug session
* Better detection/handling of overlapping/concurrent runs

= 1.2.31 - 01/15/2013 =
* Fixed bug with Dropbox deletions
* Fixed cases where Dropbox failed to resume chunked uploading
* Can now create uncreated zip files on a resumption attempt
* FTP method now supports SSL (automatically detected)
* New "Test FTP settings" button
* Less noise when debugging is turned off
* Fix bug (in 1.2.30) that prevented some database uploads completing

= 1.2.20 - 01/12/2013 =
* Dropbox no longer limited to 150Mb uploads
* Dropbox can upload in chunks and resume uploading chunks
* Improved Dropbox help text

= 1.2.18 - 01/11/2013 =
* Revert Dropbox to CURL-only - was not working properly with WordPress's built-in methods
* Add note that only up to 150Mb is possible for a Dropbox upload, until we change our API usage
* Fix unnecessary repetition of database dump upon resumption of a failed backup

= 1.2.14 - 01/08/2013 =
* Dropbox support (no chunked uploading yet, but otherwise complete)
* Make the creation of the database dump also resumable, for people with really slow servers
* Database table backups are now timed
* FTP logging slightly improved
* Dropbox support uses WordPress's built-in HTTP functions

= 1.1.16 - 01/07/2013 =
* Requested feature: more frequent scheduling options requested
* Fixed bug which mangled default suggestion for backup working directory on Windows
* Provide a 'Test S3 Settings' button for Amazon S3 users

= 1.1.11 - 01/04/2013 =
* Bug fix: some backup runs were erroneously being identified as superfluous and cancelled

= 1.1.9 - 12/31/2012 =
* Big code re-factoring; cloud access methods now modularised, paving way for easier adding of new methods. Note that Google Drive users may need to re-authenticate - please check that your backups are working.
* Fix bug whereby some resumptions of failed backups were erroneously cancelled
* Database encryption made part of what is resumable

= 1.0.16 - 12/24/2012 =
* Improve race detection and clean up already-created files when detected

= 1.0.15 - 12/22/2012 =
* Fixed bug that set 1Tb (instead of 1Mb) chunk sizes for Google Drive uploads
* Added link to some screenshots to help with Google Drive setup
* Allowed use of existing Amazon S3 buckets with restrictive policies (previously, we tested for the bucket's existence by running a create operation on it, which may not be permitted)
* Use WordPress's native HTTP functions for greater reliability when performing Google Drive authorisation
* Deal with WP-Cron racey double events (abort superceeded backups)
* Allow user to download logs from admin interface

= 1.0.5 - 12/13/2012 =
* Tweaked default Google Drive options

= 1.0.4 - 12/10/2012 =
* Implemented resumption/chunked uploading on Google Drive - much bigger sites can now be backed up
* Fixed bug whereby setting for deleting local backups was lost
* Now marked as 1.0, since we are feature-complete with targeted features for this release
* Made description fuller

= 0.9.20 - 12/06/2012 =
* Updated to latest S3.php library with chunked uploading patch
* Implemented chunked uploading on Amazon S3 - much bigger sites can now be backed up with S3

= 0.9.10 - 11/22/2012 =
* Completed basic Google Drive support (thanks to Sorin Iclanzan, code taken from "Backup" plugin under GPLv3+); now supporting uploading, purging and restoring - i.e. full UpdraftPlus functionality
* Licence change to GPLv3+ (from GPLv2+) to allow incorporating Sorin's code
* Tidied/organised the settings screen further

= 0.9.2 - 11/21/2012 =
* Failed uploads can now be re-tried, giving really big blogs a better opportunity to eventually succeed uploading

= 0.8.51 - 11/19/2012 =
* Moved screenshot into assets, reducing plugin download size

= 0.8.50 - 10/13/2012 =
* Important new feature: back up other directories found in the WP content (wp-content) directory (not just plugins/themes/uploads, as in original Updraft)

= 0.8.37 - 10/12/2012 =
* Don't whinge about Google Drive authentication if that method is not current

= 0.8.36 - 10/03/2012 =
* Support using sub-directories in Amazon S3
* Some more debug logging for Amazon S3

= 0.8.33 - 09/19/2012 =
* Work around some web hosts with invalid safe_mode configurations

= 0.8.32 - 09/17/2012 =
* Fix a subtle bug that caused database tables from outside of this WordPress install to be backed up

= 0.8.31 - 09/08/2012 =
* Fixed error deleting old S3 backups. If your expired S3 backups were not deleted, they should be in future - but you will need to delete manually those that expired before you installed this update.
* Fixed minor bug closing log file
* Marked as working with WordPress 3.4.2

= 0.8.29 - 06/29/2012 =
* Marking as tested up to WordPress 3.4.1

= 0.8.28 - 06/06/2012 =
* Now experimentally supports Google Drive (thanks to Sorin Iclanzan, code re-used from his Google Drive-only 'backup' plugin)
* New feature: backup files and database on separate schedules
* Tidied and improved retain behaviour

= 0.7.7 - 05/29/2012 =
* Implementation of a logging mechanism to allow easier debugging and development

= 0.7.4 - 05/21/2012 =
* Removed CloudFront method; I have no way of testing this
* Backup all tables found in the database that have this site's table prefix
* If encryption fails, then abort (don't revert to not encrypting)
* Added ability to decrypt encrypted database backups
* Added ability to opt out of backing up each file group
* Now adds database character set, the lack of which before made database backups unusable without modifications
* Version number bump to make clear that this is an improvement on the original Updraft, and is now tried and tested

= 0.1.3 - 01/16/2012 =
* Force backup of all tables found in database (vanilla Updraft only backed up WP core tables)
* Tweak notification email to include site name

= 0.1 - 08/10/2011 =

* A fork of Updraft 0.6.1 by Paul Kehrer with the following improvements
* Replaced deprecated function calls (in WordPress 3.2.1)
* Removed all warnings from basic admin page with WP_DEBUG on
* Implemented encrypted backup (but not yet automatic restoration) on database
* Some de-uglification of admin interface


== Screenshots ==

1. Configuration page

We recognise and thank the following for code and/or libraries used and/or modified under the terms of their licences:
* UpdraftPlus is based on the original Updraft by Paul Kehrer (Twitter: http://twitter.com/reaperhulk, Blog: http://langui.sh)
* Sorin Iclanzan, http://profiles.wordpress.org/hel.io/
* Ben Tadiar, https://github.com/BenTheDesigner/Dropbox
* Beau Brownlee, http://www.solutionbot.com/2009/01/02/php-ftp-class/
* Donovan Schonknecht, http://undesigned.org.za/2007/10/22/amazon-s3-php-class

== License ==

    Portions copyright 2011-3 David Anderson
    Portions copyright 2010 Paul Kehrer

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

