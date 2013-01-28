=== UpdraftPlus Backup ===
Contributors: David Anderson
Tags: backup, restore, database, cloud, amazon, s3, dropbox, google drive, ftp, cloud, back up, multisite
Requires at least: 3.2
Tested up to: 3.5.1
Stable tag: 1.3.18
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later

== Upgrade Notice ==
More settings tidying

== Description ==

UpdraftPlus simplifies backups (and restoration). Backup into the cloud (Amazon S3, Dropbox, Google Drive, FTP, and email) and restore with a single click. Backups of files and database can have separate schedules.

* Supports backups to Amazon S3, Dropbox, Google Drive, FTP (including SSL), email
* One-click restore
* Backup automatically on a repeating schedule
* Files and databases can have separate schedules
* Failed uploads are automatically resumed/retried
* Select which files to backup (plugins, themes, content, other)
* Database backups can be encrypted for security
* Debug mode that gives full logging of the backup
* Thousands of users: widely tested and reliable
* Premium/multi-site version available (see below)

= Best New WordPress Plugin =

That's according to WordPress big cheese, Vladimir Prelovac. Check out his weekly chart to see where UpdraftPlus is right now: http://www.prelovac.com/vladimir/wordpress-plugins-rising-stars

= Professional / Enterprise support agreements available =

UpdraftPlus is written by professional WordPress developers. If your site needs guaranteed support, then we are available. Get in touch - https://www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/ - to arrange the support contract that your site needs.

= UpdraftPlus Premium =

If you need WordPress multisite compatibility (you'll know if you do), then you need UpdraftPlus Premium: http://updraftplus.com

= Other support =

We hang out in the support forum for this plugin - http://wordpress.org/support/plugin/updraftplus - however, to save our time so that we can spend it on development, please read the plugin's Frequently Asked Questions - http://wordpress.org/extend/plugins/updraftplus/faq/ - before going there, and ensure that you have updated to the latest released version of UpdraftPlus.

== Installation ==

Standard WordPress plugin installation:

1. Search for "UpdraftPlus" in your site's admin area plugin page
2. Press 'Install'
3. Go to the options page and go through the questions there

== Frequently Asked Questions ==

= What exactly does UpdraftPlus back up ? =

Basically, everything, unless you did something very exotic (which you would then know about) to your WordPress install. Unless you disable any of these, it will back up your database (all tables which have been prefixed with the prefix for this WordPress installation, both core tables and extra ones added by plugins), your plugins folder, your themes folder, your uploads folder and any extra folders that other plugins have created in the WordPress content directory.

= What does UpdraftPlus not back up ? =

It does not back up WordPress core (since you can always get another copy of this from wordpress.org), and does not back up any extra files which you have added outside of the WordPress content directory (files which, by their nature, are unknown to WordPress). By default the WordPress content directory is "wp-content" in your WordPress root. It will not back up database tables which do not have the WordPress prefix (i.e. database tables from other applications but sharing a database with WordPress).

= I like automating WordPress, and using the command-line. Please tell me more. =

That's very good of you, thank you. You are looking for WordShell, <a href="http://wordshell.net">http://wordshell.net</a>.

= Is it WordPress Multisite (a.k.a. WordPress network) compatible? =

If you have a WordPress Multisite install (and you'll know if you do), then you need <a href="http://updraftplus.com">UpdraftPlus Premium</a>.

= I found a bug. What do I do? =

Firstly, please make sure you read this FAQ through to the end - it may already have the answer you need. If it does, then please consider a donation (http://david.dw-perspective.org.uk/donate); it takes time to develop this plugin and FAQ.

If it does not, then contact me! This is a complex plugin and the only way I can ensure it's robust is to get bug reports and fix the problems that crop up. Please make sure you are using the latest version of the plugin, and that you include the version in your bug report - if you are not using the latest, then the first thing you will be asked to do is upgrade.

Please turn on debugging mode (in the UpdraftPlus options page) and then try again, and after that send me the log if you can find it (there are links to download logs on the UpdraftPlus settings page; or you may be emailed it; failing that, it is in the directory wp-content/updraft, so FTP in and look for it there). If you cannot find the log, then I may not be able to help so much, but you can try - include as much information as you can when reporting (PHP version, your blog's site, the error you saw and how you got to the page that caused it, etcetera).

If you know where to find your PHP error logs (often a file called error_log, possibly in your wp-admin directory (check via FTP)), then that's even better (don't send me multi-megabytes; just send the few lines that appear when you run a backup, if any).

If you are a programmer and can send a patch, then that's even better.

Finally, if you post in the WordPress support forum, then make sure you include the word UpdraftPlus in your post; otherwise I will not be automatically notified that you posted.

= My scheduled backups and pressing "Backup Now" does nothing; however pressing "Debug Backup" does produce a backup =

This almost always indicates a problem with the scheduler in your WordPress installation. Schedule a backup (by pressing "Backup Now"), wait 5 seconds, and then run the wp-cron.php script on your site (e.g. http://example.com/wp-cron.php). If absolutely nothing happens (i.e. no log file appears in your wp-content/updraft directory), then you should contact your web hosting provider. We have heard of web hosting providers who have disabled this part of WordPress. Also, it is possible for other plugins to accidentally do this. Disable any cacheing plugins (e.g. WP Super Cache, WP Total Cache), plus any others that you can temporarily live without, and try the backup again. If the backup then succeeds, then you need to report the bug to the author of the guilty plugin.

= Some of my files have uploaded into my cloud storage, but not others. =

From version 0.9.0, UpdraftPlus features a resumption feature - if you wait 5 minutes and visit a page on your site, then it should re-try not-yet-uploaded files. If that fails, then turn on debugging and paste the debug log (log in via FTP, and look in wp-content/updraft) into the support forum. Before asking for support, make sure that you: 1) Have started a backup, and then waited at least an hour (because UpdraftPlus will keep trying) 2) Not started any new backups in the mean-time (that may cancel the earlier backup) 3) Have the log of the failed backup attempt, and that log only (please don't bombard me with every log file you could find - this only slows me down).

= How do I restore my backup (from a site that is still installed/running)? =

If your site is still basically intact (in particular, the database), and if you backed up using a cloud method (e.g. Amazon S3, Google Drive, FTP), then on the UpdraftPlus settings page, there is a nice shiny 'Restore' button. Press it, and it will over-write your present files (not database) with those contained in the indicated backup set.

Again, if you backed up using a cloud method, then on UpdraftPlus's settings page, there should be a clickable link next to "Download Backups". Click on that link, and it will give you a set of further buttons, allowing you to download zip files of the various backed-up components. You can download those to your computer, and then unpack them, and copy them into your web space. (That's a very simple operation - but if you don't know how and don't have a friend to assist, then bung me a donation - http://david.dw-perspective.org.uk/donate - and I can help out).

= I want to restore, but have either cannot, or have failed to do so from the WP Admin console =

That's no problem. If you have access to your backed files (i.e. you have the emailed copies, or have obtained the backed up copies directly from Amazon S3, Dropbox, Google Drive, FTP or whatever store you were using), then you simply need to unzip them into the right places. UpdraftPlus does not back up the WordPress core - you can just get a fresh copy of that from www.wordpress.org. So, if you are starting from nothing, then first download and unzip a WordPress zip from www.wordpress.org. After doing that, then unzip the zip files for your uploads, themes, plugins and other filesback into the wp-content directory. Then re-install the database (e.g. by running it through PHPMyAdmin - see also the later question on how to decrypt if your database backup was encrypted). These are all basic operations and not difficult for anyone with simple skills; but if you need help and cannot find someone to assist, then send me a meaningful donation - http://david.dw-perspective.org.uk/donate - and I can help.

= Anything essential to know? =

After you have set up UpdraftPlus, you must check that your backups are taking place successfully. WordPress is a complex piece of software that runs in many situations. Don't wait until you need your backups before you find out that they never worked in the first place. Remember, there's no warranty and no guarantees - this is free software.

= My enormous website is hosted by a dirt-cheap provider who starve my account of resources, and UpdraftPlus runs out of time! Help! Please make UpdraftPlus deal with this situation so that I can save two dollars! =

UpdraftPlus supports resuming backup runs right from the beginning, so that it does not need to do everything in a single go; but this has limits. If your website is huge and your web hosting company gives your tiny resources on an over-loaded server, then your solution is to purchase better web hosting, or to hire me professionally. Otherwise, this is not considered a bug. UpdraftPlus is known to successfully back up websites that run into the gigabytes on web servers that are not resource-starved.

= How is this better than the original Updraft? =

You can check the changelog for changes; but the original Updraft, before I forked it, had three major problems. Firstly, it only backed up WP core tables from the database; if any of your plugins stored data in extra tables, then they were not backed up. Secondly, it only backed up your plugins/themes/uploads and not any further directories inside wp-content that other plugins might have created. Thirdly, the database backup did not include charset information, which meant that you needed to know some SQL wizardry to actually be able to use the backup. I made UpdraftPlus out of my experience of trying to back up several sites with Updraft. Then, I added encryption for the database file for extra peace of mind, and future-proofed by getting rid of some deprecated aspects. Since then, many new features have been added, e.g. resuming of failed uploads, and Dropbox support.

= Any known bugs ? =

Not a bug as such, but one issue to be aware of is that backups of very large sites (lots of uploaded media) are quite complex matters, given the limits of running inside WordPress on a huge variety of different web hosting setups. With large sites, you need to use Amazon S3, which UpdraftPlus supports (since 0.9.20) or Google Drive (since 0.9.21) or Dropbox (since 1.2.19), because these support chunked, resumable uploads. Other backup methods have code (since 0.9.0) to retry failed uploads of an archive, but the upload cannot be chunked, so if an archive is enormous (i.e. cannot be completely uploaded in the time that PHP is allowed for running on your web host) it cannot work.

= I encrypted my database - how do I decrypt it? =

If you have the encryption key entered in your settings and you are restoring from the settings interface, then it will automatically decrypt. Otherwise, use the file example-decrypt.php found in the plugin directory; that will need very (very) minor PHP knowledge to use; find your local PHP guru, or bung me a donation (http://david.dw-perspective.org.uk/donate) and I can do it for you.

= I lost my encryption key - what can I do? =

Nothing, probably. That's the point of an encryption key - people who don't have it can't get the data. Hire an encryption expert to build a super computer to try to break the encryption by brute force, at a price.

= My site was hacked, and I have no backups! I thought UpdraftPlus was working! Can I kill you? =

No, there's no warranty or guarantee, etc. It's completely up to you to verify that UpdraftPlus is working correctly. If it doesn't then that's unfortunate, but this is a free plugin.

= Does UpdraftPlus delete all its settings when it is de-installed? =

No. Doing so is "cleaner", but some users also de-install and re-install and don't want to have to re-enter their settings. If you want to remove all UpdraftPlus's settings, then there's a button down at the bottom of the settings page.

= I am not running the most recent version of UpdraftPlus. Should I upgrade? =

Yes; especially before you submit any support requests.

= Have you written any other free plugins? =

Thanks for asking - yes, I have. Check out my profile page - http://profiles.wordpress.org/DavidAnderson/ . I am also available for hire for bespoke work.

== Changelog ==

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

