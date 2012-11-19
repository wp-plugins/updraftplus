=== UpdraftPlus ===
Contributors: David Anderson
Tags: backup, restore, database, cloud, amazon, s3, Amazon S3, google drive, google, gdrive, ftp, cloud, updraft, back up
Requires at least: 3.2
Tested up to: 3.4.2
Stable tag: 0.8.51
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv2 or later

== Upgrade Notice ==
Screenshots now moved into assets directory. Also, try the trunk for 0.9 with new code for re-trying of failed uploads.

== Description ==

UpdraftPlus simplifies backups and restoration. Schedule backups into the cloud (S3, Google Drive, FTP, and email) and restore with a single click.

== Installation ==

Standard WordPress plugin installation:

1. Upload updraftplus/ into wp-content/plugins/ (or use the built-in installers)
2. Activate the plugin via the 'Plugins' menu.
3. Go to the 'UpdraftPlus' option under settings.
4. Follow the instructions.

== Frequently Asked Questions ==

= How is this better than the original Updraft? =

You can check the changelog for changes; but the original Updraft, before I forked it, had three major problems. Firstly, it only backed up WP core tables from the database; if any of your plugins stored data in extra tables, then they were not backed up. Secondly, it only backed up your plugins/themes/uploads and not any further directories inside wp-content that other plugins might have created. Thirdly, the database backup did not include charset information, which meant that you needed to know some SQL wizardry to actually be able to use the backup. I made UpdraftPlus out of my experience of trying to back up several sites with Updraft. Then, I added encryption for the database file for extra peace of mind, and future-proofed by getting rid of some deprecated aspects.

= I like automating WordPress, and using the command-line. Please tell me more. =

That's very good of you, thank you. You are looking for WordShell, <a href="http://wordshell.net">http://wordshell.net</a>.

= Some of my files have uploaded into my cloud storage, but not others. =

From version 0.9.0, UpdraftPlus features a resumption feature - if you wait 5 minutes and visit a page on your site, then it should re-try not-yet-uploaded files. If that fails, then turn on debugging and paste the debug log (log in via FTP, and look in wp-content/updraft) into the support forum.

= I want to restore, but have failed to do so from the WP Admin console =

That's no problem. If you have your backed files, then you simply need to unzip them into the right places. UpdraftPlus does not back up the WordPress core - you can just get a fresh copy of that from www.wordpress.org. After installing that, then unzip the zip files for your uploads, themes and plugins back into the wp-content directory. Then re-install the database (e.g. by running it through PHPMyAdmin). Please don't ask me how to carry out these steps - they are basic operations which you can hire any of hundreds of thousands of people to show you how to do.

= Anything essential to know? =

After you have set up UpdraftPlus, you must check that your backups are taking place successfully. WordPress is a complex piece of software that runs in many situations. Don't wait until you need your backups before you find out that they never worked in the first place. Remember, there's no warranty.

= What exactly does UpdraftPlus back up ? =

Unless you disable any of these, it will back up your database (all tables which have been prefixed with the prefix for this WordPress installation, both core tables and extra ones added by plugins), your plugins folder, your themes folder, your uploads folder and any extra folders that other plugins have created in the WordPress content directory.

= What does UpdraftPlus not back up ? =

It does not back up WordPress core (since you can always get another copy of this from wordpress.org), and does not back up any extra files which you have added outside of the WordPress content directory (files which, by their nature, are unknown to WordPress). By default the WordPress content directory is "wp-content" in your WordPress root. It will not back up database tables which do not have the WordPress prefix (i.e. database tables from other applications but sharing a database with WordPress).

= Any known bugs ? =
The major one is that backups of very large sites (lots of uploaded media) can fail due to timing out. If your site is very large, then be doubly-sure to test when setting up that your backups are not empty. Since 0.9.0 there is a feature to re-try failed uploads on a separate scheduled run, which means UpdraftPlus should succeed for more sites than before (since we now only need enough time on each run to upload a single file, not all of them).

= I encrypted my database - how do I decrypt it? =

If you have the encryption key entered in your settings and you are restoring from the settings interface, then it will automatically decrypt. Otherwise, use the file example-decrypt.php found in the plugin directory.

= I lost my encryption key - what can I do? =

Nothing, probably. That's the point of an encryption key - people who don't have it can't get the data. Hire an encryption expert to build a super computer to try to break the encryption by brute force, at a price.

= I found a bug. What do I do? =

Contact me! This is a complex plugin and the only way I can ensure it's robust is to get bug reports and fix the problems that crop up. Please turn on debugging mode and send me the log if you can find it. Include as much information as you can when reporting (PHP version, your blog's site, the error you saw and how you got to the page that caused it, etcetera). If you can send a patch, that's even better.

== Changelog ==

= 0.9.1 - 11/19/2012 =
* Failed uploads can now be resumed, giving really big blogs a better opportunity to eventually succeed uploading

= 0.8.51 - 11/19/2012 =
* Moved screenshot into assets, reducing plugin download size

= 0.8.50 - 10/13/2012 =
* Important new feature: back up other directories found in the WP content directory (not just plugins/themes/uploads, as in original Updraft)

= 0.8.37 - 10/12/2012 =
* Don't whinge about Google Drive authentication if that method is not current

= 0.8.36 - 03/10/2012 =
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


== License ==

    Portions copyright 2011-2 David Anderson
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

