=== UpdraftPlus ===
Contributors: David Anderson
Tags: backup, restore, database, cloud, amazon, google drive, gdrive, s3, ftp, cloud, updraft
Requires at least: 3.2
Tested up to: 3.3.2
Stable tag: 0.7.7
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv2 or later

== Description ==

UpdraftPlus simplifies backups (and restoration) for your blog. Backup into the cloud (S3, FTP, and email) and restore with a single click. Backups of files and database can be upon separate schedules.

== Upgrade Notice ==
Added separate schedules and Google Drive support (0.8.0)

== Installation ==

Standard WordPress plugin installation:

1. Upload updraftplus/ into wp-content/plugins/ (or use the built-in installers)
2. Activate the plugin via the 'Plugins' menu.
3. Go to the 'UpdraftPlus' option under settings.
4. Follow the instructions.

== Frequently Asked Questions ==

= How is this better than the original Updraft? =

You can check the changelog for changes; but the original Updraft, before I forked it, had two major problems. Firstly, it only backed up WP core tables from the database; if any of your plugins stored data in extra tables, then they were not backed up. Secondly, the database backup did not include charset information, which meant that you needed to know some SQL wizardry to actually be able to use the backup. I made UpdraftPlus out of my experience of trying to back up several sites with Updraft. Then, I added encryption for the database file for extra peace of mind, and future-proofed by getting rid of some deprecated aspects.

= I like automating WordPress, and using the command-line. Please advertise to me. =

That's very good of you, thank you. You are looking for WordShell, <a href="http://wordshell.net">http://wordshell.net</a>.

= I encrypted my database - how do I decrypt it? =

If you have the encryption key entered in your settings and you are restoring from the settings interface, then it will automatically decrypt. Otherwise, use the file example-decrypt.php found in the plugin directory.

= I lost my encryption key - what can I do? =

Nothing, probably. That's the point of an encryption key - people who don't have it can't get the data. Hire an encryption expert to build a super computer to try to break the encryption by brute force, at a tremendous price.

= I found a bug. What do I do? =

Contact me! This is a complex plugin and the only way I can ensure it's robust is to get bug reports and fix the problems that crop up. Please turn on debugging mode and send me the log if you can find it. Include as much information as you can when reporting (PHP version, your blog's site, the error you saw and how you got to the page that caused it, etcetera). If you can send a patch, that's even better.

== Changelog ==

= 0.8.18 - 06/06/2012 =
* Now supports Google Drive (thanks to Sorin Iclanzan, code re-used his Google Drive-only 'backup' plugin)
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

