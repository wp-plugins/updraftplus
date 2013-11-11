<?php
if (!defined ('ABSPATH')) die('No direct access allowed');

if(!class_exists('WP_Upgrader')) require_once(ABSPATH.'wp-admin/includes/class-wp-upgrader.php');
class Updraft_Restorer extends WP_Upgrader {

	public $ud_backup_is_multisite = -1;

	// This is just used so far for detecting whether we're on the second run for an entity or not.
	public $been_restored = array();

	public $delete = false;

	function __construct() {
		parent::__construct();
		$this->init();
		$this->backup_strings();
	}

	function backup_strings() {
		$this->strings['not_possible'] = __('UpdraftPlus is not able to directly restore this kind of entity. It must be restored manually.','updraftplus');
		$this->strings['no_package'] = __('Backup file not available.','updraftplus');
		$this->strings['copy_failed'] = __('Copying this entity failed.','updraftplus');
		$this->strings['unpack_package'] = __('Unpacking backup...','updraftplus');
		$this->strings['decrypt_database'] = __('Decrypting database (can take a while)...','updraftplus');
		$this->strings['decrypted_database'] = __('Database successfully decrypted.','updraftplus');
		$this->strings['moving_old'] = __('Moving old directory out of the way...','updraftplus');
		$this->strings['moving_backup'] = __('Moving unpacked backup into place...','updraftplus');
		$this->strings['restore_database'] = __('Restoring the database (on a large site this can take a long time - if it times out (which can happen if your web hosting company has configured your hosting to limit resources) then you should use a different method, such as phpMyAdmin)...','updraftplus');
		$this->strings['cleaning_up'] = __('Cleaning up rubbish...','updraftplus');
		$this->strings['old_move_failed'] = __('Could not move old directory out of the way.','updraftplus').' '.__('You should check the file permissions in your WordPress installation', 'updraftplus');
		$this->strings['old_delete_failed'] = __('Could not delete old directory.','updraftplus');
		$this->strings['new_move_failed'] = __('Could not move new directory into place. Check your wp-content/upgrade folder.','updraftplus');
		$this->strings['delete_failed'] = __('Failed to delete working directory after restoring.','updraftplus');
		$this->strings['multisite_error'] = __('You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus');
	}

	function get_max_packet_size() {
		global $wpdb;
		$mp = (int)$wpdb->get_var("SELECT @@session.max_allowed_packet");
		# Default to 1Mb
		return (is_numeric($mp) && $mp > 0) ? $mp : 1048576;
	}

	// This returns a wp_filesystem location (and we musn't change that, as we must retain compatibility with the class parent)
	function unpack_package($package, $delete_package = true) {

		global $wp_filesystem, $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location();

		// If not database, then it is a zip - unpack in the usual way
		if (!preg_match('/db\.gz(\.crypt)?$/i', $package)) return parent::unpack_package($updraft_dir.'/'.$package, $delete_package);

		$backup_dir = $wp_filesystem->find_folder($updraft_dir);

		// Unpack a database. The general shape of the following is copied from class-wp-upgrader.php

		@set_time_limit(1800);

		$this->skin->feedback('unpack_package');

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
		@$wp_filesystem->mkdir($upgrade_folder, 0775);

		//Clean up contents of upgrade directory beforehand.
		$upgrade_files = $wp_filesystem->dirlist($upgrade_folder);
		if ( !empty($upgrade_files) ) {
			foreach ( $upgrade_files as $file )
				$wp_filesystem->delete($upgrade_folder . $file['name'], true);
		}

		//We need a working directory
		$working_dir = $upgrade_folder . basename($package, '.crypt');
		# $working_dir_localpath = WP_CONTENT_DIR.'/upgrade/'. basename($package, '.crypt');

		// Clean up working directory
		if ( $wp_filesystem->is_dir($working_dir) )
			$wp_filesystem->delete($working_dir, true);

		if (!$wp_filesystem->mkdir($working_dir, 0775)) return new WP_Error('mkdir_failed', __('Failed to create a temporary directory','updraftplus').' ('.$working_dir.')');

		// Unpack package to working directory
		if ($updraftplus->is_db_encrypted($package)) {
			$this->skin->feedback('decrypt_database');
			$encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
			if (!$encryption) return new WP_Error('no_encryption_key', __('Decryption failed. The database file is encrypted, but you have no encryption key entered.', 'updraftplus'));

			$plaintext = $updraftplus->decrypt(false, $encryption, $wp_filesystem->get_contents($backup_dir.$package));

			if ($plaintext) {
				$this->skin->feedback('decrypted_database');
				if (!$wp_filesystem->put_contents($working_dir.'/backup.db.gz', $plaintext)) {
					return new WP_Error('write_failed', __('Failed to write out the decrypted database to the filesystem','updraftplus'));
				}
			} else {
				return new WP_Error('decryption_failed', __('Decryption failed. The most likely cause is that you used the wrong key.','updraftplus'));
			}
		} else {

			if (!$wp_filesystem->copy($backup_dir.$package, $working_dir.'/backup.db.gz')) {
				if ( $wp_filesystem->errors->get_error_code() ) { 
					foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
				}
				return new WP_Error('copy_failed', $this->strings['copy_failed']);
			}

		}

		// Once extracted, delete the package if required (non-recursive, is a file)
		if ( $delete_package )
			$wp_filesystem->delete($backup_dir.$package, false, true);

		return $working_dir;

	}

	// For moving files out of a directory into their new location
	// The purposes of the $type parameter are 1) to detect 'others' and apply a historical bugfix 2) to detect wpcore, and apply the setting for what to do with wp-config.php 3) to work out whether to delete the directory itself
	// Must use only wp_filesystem
	// $dest_dir must already have a trailing slash
	// $preserve_existing: this setting only applies at the top level: 0 = overwrite with no backup; 1 = make backup of existing; 2 = do nothing if there is existing, 3 = do nothing to the top level directory, but do copy-in contents. Thus, on a multi-archive set where you want a backup, you'd do this: first call with $preserve_existing === 1, then on subsequent zips call with 3
	function move_backup_in($working_dir, $dest_dir, $preserve_existing = 1, $do_not_overwrite = array('plugins', 'themes', 'uploads', 'upgrade'), $type = 'not-others', $send_actions = false) {

		global $wp_filesystem;

		# Get the content to be moved in. Include hidden files = true. Recursion is only required if we're likely to copy-in
		$recursive = (3 == $preserve_existing) ? true : false;
		$upgrade_files = $wp_filesystem->dirlist($working_dir, true, $recursive);

		if (empty($upgrade_files)) return true;

		foreach ( $upgrade_files as $file => $filestruc ) {

			// Correctly restore files in 'others' in no directory that were wrongly backed up in versions 1.4.0 - 1.4.48
			if (('others' == $type || 'wpcore' == $type ) && preg_match('/^([\-_A-Za-z0-9]+\.php)$/', $file, $matches) && $wp_filesystem->exists($working_dir . "/$file/$file")) {
				if ('others' == $type) {
					echo "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions 1.4.0 - 1.4.48, and sometimes up to 1.6.55 on some Windows servers); will rename to simply $file<br>";
				} else {
					echo "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions before 1.6.55 in certain situations on Windows servers); will rename to simply $file<br>";
				}
				$file = $matches[1];
				$tmp_file = rand(0,999999999).'.php';
				// Rename directory
				$wp_filesystem->move($working_dir . "/$file", $working_dir . "/".$tmp_file, true);
				$wp_filesystem->move($working_dir . "/$tmp_file/$file", $working_dir ."/".$file, true);
				$wp_filesystem->rmdir($working_dir . "/$tmp_file", false);
			}

			if ('wpcore' == $type && 'wp-config.php' == $file) {
				if (empty($_POST['updraft_restorer_wpcore_includewpconfig'])) {
					_e('wp-config.php from backup: will restore as wp-config-backup.php', 'updraftplus');
					$wp_filesystem->move($working_dir . "/$file", $working_dir . "/wp-config-backup.php", true);
					$file = "wp-config-backup.php";
				} else {
					_e('wp-config.php from backup: restoring (as per user\'s request)', 'updraftplus');
				}
				echo '<br>';
			}

			# Sanity check (should not be possible as these were excluded at backup time)
			if (in_array($file, $do_not_overwrite)) continue;

			# First, move the existing one, if necessary (may not be present)
			if ($wp_filesystem->exists($dest_dir.$file)) {
				if ($preserve_existing == 1) {
					# Move existing to -old
					if ( !$wp_filesystem->move($dest_dir.$file, $dest_dir.$file.'-old', true) ) {
						return new WP_Error('old_move_failed', $this->strings['old_move_failed']." ($dest_dir.$file)");
					}
				} elseif ($preserve_existing == 0) {
					# Over-write, no backup
					if (!$wp_filesystem->delete($dest_dir.$file, true)) {
						return new WP_Error('old_delete_failed', $this->strings['old_delete_failed']." ($file)");
					}
				}
			}

			# Secondly, move in the new one
			if (2 == $preserve_existing && $wp_filesystem->exists($dest_dir.$file)) {
				# Something exists - no move. Remove it from the temporary directory - so that it will be clean later
				@$wp_filesystem->delete($working_dir.'/'.$file, true);
			} elseif (3 != $preserve_existing || !$wp_filesystem->exists($dest_dir.$file)) {
				if ($wp_filesystem->move($working_dir."/".$file, $dest_dir.$file, true) ) {
					if ($send_actions) do_action('updraftplus_restored_'.$type.'_one', $file);
				} else {
					return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
				}
			} elseif (3 == $preserve_existing && !empty($filestruc['files'])) {
				# The directory ($dest_dir) already exists, and we've been requested to copy-in. We need to perform the recursive copy-in
				# $filestruc['files'] is then a new structure like $upgrade_files
				# First pass: create directory structure
				# Get chmod value for the parent directory, and re-use it (instead of passing false)

				$chmod = $wp_filesystem->getnumchmodfromh($wp_filesystem->gethchmod($dest_dir));
				# Copy in the files. This also needs to make sure the directories exist, in case the zip file lacks entries
				$delete_root = ('others' == $type || 'wpcore' == $type) ? false : true;
				$copy_in = $this->copy_files_in($working_dir.'/'.$file, $dest_dir.$file, $filestruc['files'], $chmod, $delete_root);

				if (is_wp_error($copy_in)) return $copy_in;
				if (!$copy_in) return new WP_Error('new_move_failed', $this->strings['new_move_failed']);

				$wp_filesystem->rmdir($working_dir.'/'.$file);
			} else {
				$wp_filesystem->rmdir($working_dir.'/'.$file);
			}
		}

		return true;

	}

	# $dest_dir must already exist
	function copy_files_in($source_dir, $dest_dir, $files, $chmod = false, $deletesource = false) {
		global $wp_filesystem;
		foreach ($files as $rname => $rfile) {
			if ('d' != $rfile['type']) {
				# Delete it if it already exists (or perhaps WP does it for us)
					if (!$wp_filesystem->move($source_dir.'/'.$rname, $dest_dir.'/'.$rname, true)) {
					echo sprintf(__('Failed to move file (check your file permissions and disk quota): %s', 'updraftplus'), $source_dir.'/'.$rname." -&gt; ".$dest_dir.'/'.$rname);
					return false;
				}
			} else {
				# Directory
				if ($wp_filesystem->is_file($dest_dir.'/'.$rname)) @$wp_filesystem->delete($dest_dir.'/'.$rname, false, 'f');
				# No such directory yet: just move it
				if (!$wp_filesystem->is_dir($dest_dir.'/'.$rname)) {
					if (!$wp_filesystem->move($source_dir.'/'.$rname, $dest_dir.'/'.$rname, false)) {
						echo sprintf(__('Failed to move directory (check your file permissions and disk quota): %s', 'updraftplus'), $source_dir.'/'.$rname." -&gt; ".$dest_dir.'/'.$rname);
						return false;
					}
				} elseif (!empty($rfile['files'])) {
					# There is a directory - and we want to to copy in
					$docopy = $this->copy_files_in($source_dir.'/'.$rname, $dest_dir.'/'.$rname, $rfile['files'], $chmod, false);
					if (is_wp_error($docopy)) return $docopy;
					if (false === $docopy) {
						return false;
					}
				} else {
					# There is a directory: but nothing to copy in to it
					@$wp_filesystem->rmdir($source_dir.'/'.$rname);
				}
			}
		}
		# We are meant to leave the working directory empty. Hence, need to rmdir() once a directory is empty. But not the root of it all in case of others/wpcore.
		if ($deletesource || strpos($source_dir, '/') !== false) {
			$wp_filesystem->rmdir($source_dir, false);
		}

		return true;

	}

	// Pre-flight check: chance to complain and abort before anything at all is done
	function pre_restore_backup($backup_files, $type, $info) {

		if (is_string($backup_files)) $backup_files=array($backup_files);

		if ($type == 'more') {
			show_message($this->strings['not_possible']);
			return;
		}

		// Ensure access to the indicated directory - and to WP_CONTENT_DIR (in which we use upgrade/)
		$need_these = array(WP_CONTENT_DIR);
		if (!empty($info['path'])) $need_these[] = $info['path'];

		$res = $this->fs_connect($need_these);
		if (false === $res || is_wp_error($res)) return $res;

		# Check upgrade directory is writable (instead of having non-obvious messages when we try to write)
		# In theory, this is redundant (since we already checked for access to WP_CONTENT_DIR); but in practice, this extra check has been needed
		global $wp_filesystem;
		if (empty($this->pre_restore_updatedir_writable)) {
			$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
			@$wp_filesystem->mkdir($upgrade_folder, 0775);
			if (!$wp_filesystem->is_dir($upgrade_folder)) {
				return new WP_Error('no_dir', sprintf(__('UpdraftPlus needed to create a %s in your content directory, but failed - please check your file permissions and enable the access (%s)', 'updraftplus'), __('folder', 'updraftplus'), $upgrade_folder));
			}
			$rand_file = 'testfile_'.rand(0,9999999).md5(microtime(true)).'.txt';
			if ($wp_filesystem->put_contents($upgrade_folder.$rand_file, 'testing...')) {
				@$wp_filesystem->delete($upgrade_folder.$rand_file);
				$this->pre_restore_updatedir_writable = true;
			} else {
				return new WP_Error('no_file', sprintf(__('UpdraftPlus needed to create a %s in your content directory, but failed - please check your file permissions and enable the access (%s)', 'updraftplus'), __('file', 'updraftplus'), $upgrade_folder.$rand_file));
			}
		}

		# Code below here assumes that we're dealing with file-based entities
		if ('db' == $type) return true;

		$wp_filesystem_dir = $this->get_wp_filesystem_dir($info['path']);
		if ($wp_filesystem_dir === false) return false;

		global $updraftplus_addons_migrator;

		$this->maintenance_mode(true);

		echo __('Testing file permissions...', 'updraftplus')."<br>";

		$ret_val = true;

		if (('plugins' == $type || 'uploads' == $type || 'themes' == $type) && (!is_multisite() || $this->ud_backup_is_multisite !== 0 || ('uploads' != $type || empty($updraftplus_addons_migrator->new_blogid )))) {
			if ($wp_filesystem->exists($wp_filesystem_dir.'-old')) {
				$ret_val = new WP_Error('already_exists', sprintf(__('An existing unremoved backup from a previous restore exists: %s', 'updraftplus'), $wp_filesystem_dir.'-old'));
			} else {
				# File permissions test; see if we can move the directory back and forth
				if (!$wp_filesystem->move($wp_filesystem_dir, $wp_filesystem_dir."-old", false)) {
					$ret_val = new WP_Error('old_move_failed', $this->strings['old_move_failed']);
				} else {
					$wp_filesystem->move($wp_filesystem_dir."-old", $wp_filesystem_dir, false);
				}
			}
		}

		$this->maintenance_mode(false);

		return $ret_val;
	}

	function get_wp_filesystem_dir($path) {
		global $wp_filesystem;
		// Get the wp_filesystem location for the folder on the local install
		switch ( $path ) {
			case ABSPATH:
			case '';
				$wp_filesystem_dir = $wp_filesystem->abspath();
				break;
			case WP_CONTENT_DIR:
				$wp_filesystem_dir = $wp_filesystem->wp_content_dir();
				break;
			case WP_PLUGIN_DIR:
				$wp_filesystem_dir = $wp_filesystem->wp_plugins_dir();
				break;
			case WP_CONTENT_DIR . '/themes':
				$wp_filesystem_dir = $wp_filesystem->wp_themes_dir();
				break;
			default:
				$wp_filesystem_dir = $wp_filesystem->find_folder($path);
				break;
		}
		if ( ! $wp_filesystem_dir ) return false;
		return untrailingslashit($wp_filesystem_dir);
	}

	// $backup_file is just the basename, and must be a string; we expect the caller to deal with looping over an array (multi-archive sets). We do, however, record whether we have already unpacked an entity of the same type - so that we know to add (not replace).
	function restore_backup($backup_file, $type, $info) {

		if ($type == 'more') {
			show_message($this->strings['not_possible']);
			return;
		}

		global $wp_filesystem, $updraftplus_addons_migrator, $updraftplus;

		$get_dir = (empty($info['path'])) ? '' : $info['path'];
		$wp_filesystem_dir = $this->get_wp_filesystem_dir($get_dir);
		if ($wp_filesystem_dir === false) return false;

		if (empty($this->abspath)) $this->abspath = trailingslashit($wp_filesystem->abspath());

		@set_time_limit(1800);

		// This returns the wp_filesystem path
		$working_dir = $this->unpack_package($backup_file, $this->delete);
		if (is_wp_error($working_dir)) return $working_dir;

		$working_dir_localpath = WP_CONTENT_DIR.'/upgrade/'.basename($working_dir);
		@set_time_limit(1800);

		// We copy the variable because we may be importing with a different prefix (e.g. on multisite imports of individual blog data)
		$import_table_prefix = $updraftplus->get_table_prefix(false);

		if (is_multisite() && $this->ud_backup_is_multisite === 0 && ( ( 'plugins' == $type || 'themes' == $type )  || ( 'uploads' == $type && !empty($updraftplus_addons_migrator->new_blogid)) )) {

			# Migrating a single site into a multisite
			if ('plugins' == $type || 'themes' == $type) {

				$move_from = $this->get_first_directory($working_dir, array(basename($info['path']), $type));

				show_message($this->strings['moving_backup']);
				// Only move in entities that are not already there (2)
				$new_move_failed = (false === $move_from) ? true : false;
				if (false === $new_move_failed) {
					$move_in = $this->move_backup_in($move_from, trailingslashit($wp_filesystem_dir), 2, array(), $type, true);
					if (is_wp_error($move_in)) return $move_in;
					if (!$move_in) $new_move_failed = true;
				}
				if ($new_move_failed) return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
				@$wp_filesystem->delete($move_from);

			} else {
				// Uploads

				show_message($this->strings['moving_old']);

				switch_to_blog($updraftplus_addons_migrator->new_blogid);

				$ud = wp_upload_dir();
				$wpud = $ud['basedir'];
				$fsud = trailingslashit($wp_filesystem->find_folder($wpud));
				restore_current_blog();

				// TODO: What is below will move the entire uploads directory if blog id is 1. Detect this situation. (Can that happen? We created a new blog, so should not be possible).

				// TODO: the upload dir is not necessarily reachable through wp_filesystem - try ordinary method instead
				if (is_string($fsud)) {
					// This is not expected to exist, since we created a new blog

					if ( $wp_filesystem->exists($fsud) && !$wp_filesystem->move($fsud, untrailingslashit($fsud)."-old", true) ) {
						return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
					}

					show_message($this->strings['moving_backup']);

					$move_from = $this->get_first_directory($working_dir, array(basename($info['path']), $type));

					if ( !$wp_filesystem->move($move_from, $fsud, true) ) {
						return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
					}

					@$wp_filesystem->delete($move_from);

				} else {
					return new WP_Error('move_failed', $this->strings['new_move_failed']);
				}

			}
		} elseif ('db' == $type) {

			// $import_table_prefix is received as a reference
			$rdb = $this->restore_backup_db($working_dir, $working_dir_localpath, $import_table_prefix);
			if (false === $rdb || is_wp_error($rdb)) return $rdb;

		} elseif ('others' == $type) {

			$dirname = basename($info['path']);

			// In this special case, the backup contents are not in a folder, so it is not simply a case of moving the folder around, but rather looping over all that we find

			# On subsequent archives of a multi-archive set, don't move anything; but do on the first
			$preserve_existing = (isset($this->been_restored['others'])) ? 3 : 1;

			$this->move_backup_in($working_dir, trailingslashit($wp_filesystem_dir), $preserve_existing, array('plugins', 'themes', 'uploads', 'upgrade'), 'others');

			$this->been_restored['others'] = true;

		} else {

			// Default action: used for plugins, themes and uploads (and wpcore, via a filter)

			// Multi-archive sets: we record what we've already begun on, and on subsequent runs, copy in instead of replacing
			$movedin = apply_filters('updraftplus_restore_movein_'.$type, $working_dir, $this->abspath, $wp_filesystem_dir);
			// A filter, to allow add-ons to perform the install of non-standard entities, or to indicate that it's not possible
			if (false === $movedin) {
				show_message($this->strings['not_possible']);
			} elseif (true !== $movedin) {

				# On the first time, move the existing data to -old
				if (!isset($this->been_restored[$type])) {
					if ($wp_filesystem->exists($wp_filesystem_dir."-old")) {
						// Is better to warn and delete the backup than abort mid-restore and leave inconsistent site
						echo $wp_filesystem_dir."-old: ".__('This directory already exists, and will be replaced', 'updraftplus').'<br>';
						# In theory, supply true as the 3rd parameter of true achieves this; in practice, not always so (leads to support requests)
						$wp_filesystem->delete($wp_filesystem_dir."-old", true);
					}

					show_message($this->strings['moving_old']);
					if ( !$wp_filesystem->move($wp_filesystem_dir, $wp_filesystem_dir."-old", false) ) {
						return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
					}
				}

				// The backup may not actually have /$type, since that is info from the present site
				$move_from = $this->get_first_directory($working_dir, array(basename($info['path']), $type));
				if (false === $move_from) return new WP_Error('new_move_failed', $this->strings['new_move_failed']);

				show_message($this->strings['moving_backup']);

				if (!isset($this->been_restored[$type])) {
					if (!$wp_filesystem->move($move_from, $wp_filesystem_dir, true) ) {
						return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
					}
				} else {
					$move_in = $this->move_backup_in($move_from,  trailingslashit($wp_filesystem_dir), 3, array(), $type);
					if (is_wp_error($move_in)) return $move_in;
					if (!$move_in) return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
					$wp_filesystem->rmdir($move_from);
				}

			}

			$this->been_restored[$type] = true;

		}

		// Non-recursive, so the directory needs to be empty
		show_message($this->strings['cleaning_up']);
		if (!$wp_filesystem->delete($working_dir) ) {
			echo sprintf(__('Error: %s', 'updraftplus'), $this->strings['delete_failed'].' ('.$working_dir.')')."<br>";
			# List contents
			// No need to make this a restoration-aborting error condition - it's not
			#return new WP_Error('delete_failed', $this->strings['delete_failed'].' ('.$working_dir.')');
			$dirlist = $wp_filesystem->dirlist($working_dir, true, true);
			if (is_array($dirlist)) {
				echo __('Files found:', 'updraftplus').'<br><ul style="list-style: disc inside;">';
				foreach ($dirlist as $name => $struc) {
					echo "<li>".htmlspecialchars($name)."</li>";
				}
				echo '</ul>';
			} else {
				echo __('Unable to enumerate files in that directory.', 'updraftplus').'<br>';
			}
		}

		switch($type) {
			case 'wpcore':
				@$wp_filesystem->chmod($wp_filesystem_dir, FS_CHMOD_DIR);
				// In case we restored a .htaccess which is incorrect for the local setup
				$this->flush_rewrite_rules();
			break;
			case 'uploads':
				@$wp_filesystem->chmod($wp_filesystem_dir, 0775, true);
			break;
			case 'db':
				do_action('updraftplus_restored_db', array('expected_oldsiteurl' => $old_siteurl, 'expected_oldhome' => $old_home, 'expected_oldcontent' => $old_content), $import_table_prefix);
				$this->flush_rewrite_rules();
			break;
			default:
				@$wp_filesystem->chmod($wp_filesystem_dir, FS_CHMOD_DIR);
		}

		return true;

	}

	// $dirnames: an array of preferred names
	function get_first_directory($working_dir, $dirnames) {
		global $wp_filesystem;
		$fdirnames = array_flip($dirnames);
		$dirlist = $wp_filesystem->dirlist($working_dir, true, true);
		if (is_array($dirlist)) {
			$move_from = false;
			foreach ($dirlist as $name => $struc) {
				if (false === $move_from) {
					if (isset($fdirnames[$name])) {
						$move_from = $working_dir . "/".$name;
					} elseif (preg_match('/^([^\.].*)$/', $name, $fmatch)) {
						$first_entry = $working_dir."/".$fmatch[1];
					}
				}
			}
			if ($move_from === false && isset($first_entry)) {
				echo sprintf(__('Using directory from backup: %s', 'updraftplus'), basename($first_entry)).'<br>';
				$move_from = $first_entry;
			}
		} else {
			# That shouldn't happen. Fall back to default
			$move_from = $working_dir . "/".$dirname[0];
		}
		return $move_from;
	}

	function pre_sql_actions($import_table_prefix) {

		$import_table_prefix = apply_filters('updraftplus_restore_set_table_prefix', $import_table_prefix, $this->ud_backup_is_multisite);

		if (!is_string($import_table_prefix)) {
			if ($import_table_prefix === false) {
				echo '<p>'.__('Please supply the requested information, and then continue.', 'updraftplus').'</p>';
				return false;
			} else {
				return new WP_Error('invalid_table_prefix', __('Error:', 'updraftplus').' '.serialize($import_table_prefix));
			}
		}

		echo '<strong>'.__('New table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($import_table_prefix).'<br>';

		return $import_table_prefix;

	}

	function option_filter_get($which) {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $which));
		// Has to be get_row instead of get_var because of funkiness with 0, false, null values
		if (is_object($row)) {
			return $row->option_value;
		}
		return false;
	}

	function option_filter_permalink_structure($val) {
		return $this->option_filter_get('permalink_structure');
	}

	function option_filter_page_on_front($val) {
		return $this->option_filter_get('page_on_front');
	}

	function option_filter_rewrite_rules($val) {
		return $this->option_filter_get('rewrite_rules');
	}

	// The pass-by-reference on $import_table_prefix is due to historical refactoring
	function restore_backup_db($working_dir, $working_dir_localpath, &$import_table_prefix) {

		do_action('updraftplus_restore_db_pre');

		# This is now a legacy option (at least on the front end), so we should not see it much
		$this->prior_upload_path = get_option('upload_path');

		// There is a file backup.db.gz inside the working directory

		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if (@ini_get('safe_mode') && 'off' != strtolower(@ini_get('safe_mode'))) {
			echo "<p>".__('Warning: PHP safe_mode is active on your server. Timeouts are much more likely. If these happen, then you will need to manually restore the file via phpMyAdmin or another method.', 'updraftplus')."</p><br/>";
			return false;
		}

		// wp_filesystem has no gzopen method, so we switch to using the local filesystem (which is harmless, since we are performing read-only operations)
		if (!is_readable($working_dir_localpath.'/backup.db.gz')) return new WP_Error('gzopen_failed',__('Failed to find database file','updraftplus')." ($working_dir/backup.db.gz)");

		$this->skin->feedback('restore_database');

		// Read-only access: don't need to go through WP_Filesystem
		$dbhandle = gzopen($working_dir_localpath.'/backup.db.gz', 'r');
		if (!$dbhandle) return new WP_Error('gzopen_failed',__('Failed to open database file','updraftplus'));

		$this->line = 0;

		global $wpdb, $updraftplus;

		// Line up a wpdb-like object to use
		// mysql_query will throw E_DEPRECATED from PHP 5.5, so we expect WordPress to have switched to something else by then
// 			$use_wpdb = (version_compare(phpversion(), '5.5', '>=') || !function_exists('mysql_query') || !$wpdb->is_mysql || !$wpdb->ready) ? true : false;
		// Seems not - PHP 5.5 is immanent for release
		$this->use_wpdb = (!function_exists('mysql_query') || !$wpdb->is_mysql || !$wpdb->ready) ? true : false;

		if (false == $this->use_wpdb) {
			// We have our own extension which drops lots of the overhead on the query
			$wpdb_obj = new UpdraftPlus_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
			// Was that successful?
			if (!$wpdb_obj->is_mysql || !$wpdb_obj->ready) {
				$this->use_wpdb = true;
			} else {
				$this->mysql_dbh = $wpdb_obj->updraftplus_getdbh();
			}
		}

		if (true == $this->use_wpdb) {
			_e('Database access: Direct MySQL access is not available, so we are falling back to wpdb (this will be considerably slower)','updraftplus');
		} else {
			@mysql_query('SET SESSION query_cache_type = OFF;', $this->mysql_dbh );
		}

		// Find the supported engines - in case the dump had something else (case seen: saved from MariaDB with engine Aria; imported into plain MySQL without)
		$supported_engines = $wpdb->get_results("SHOW ENGINES", OBJECT_K);

		$this->errors = 0;
		$this->statements_run = 0;
		$this->tables_created = 0;

		$sql_line = "";
		$sql_type = -1;

		$this->start_time = microtime(true);

		$old_wpversion = '';
		$old_siteurl = '';
		$old_home = '';
		$old_content = '';
		$old_table_prefix = '';
		$old_siteinfo = array();
		$gathering_siteinfo = true;

		$this->create_forbidden = false;
		$this->drop_forbidden = false;

		$this->last_error = '';
		$random_table_name = 'updraft_tmp_'.rand(0,9999999).md5(microtime(true));
		if ($this->use_wpdb) {
			$req = $wpdb->query("CREATE TABLE $random_table_name");
			if (!$req) $this->last_error = $wpdb->last_error;
			$this->last_error_no = false;
		} else {
			$req = mysql_unbuffered_query("CREATE TABLE $random_table_name", $this->mysql_dbh );
			if (!$req) {
				$this->last_error = mysql_error($this->mysql_dbh);
				$this->last_error_no = mysql_errno($this->mysql_dbh);
			}
		}

		if (!$req && ($this->use_wpdb || $this->last_error_no === 1142)) {
			$this->create_forbidden = true;
			# If we can't create, then there's no point dropping
			$this->drop_forbidden = true;
			echo '<strong>'.__('Warning:','updraftplus').'</strong> '.__('Your database user does not have permission to create tables. We will attempt to restore by simply emptying the tables; this should work as long as a) you are restoring from a WordPress version with the same database structure, and b) Your imported database does not contain any tables which are not already present on the importing site.', 'updraftplus').' ('.$this->last_error.')'."<br>";
		} else {
			if ($this->use_wpdb) {
				$req = $wpdb->query("DROP TABLE $random_table_name");
				if (!$req) $this->last_error = $wpdb->last_error;
				$this->last_error_no = false;
			} else {
				$req = mysql_unbuffered_query("DROP TABLE $random_table_name", $this->mysql_dbh);
				if (!$req) {
					$this->last_error = mysql_error($this->mysql_dbh);
					$this->last_error_no = mysql_errno($this->mysql_dbh);
				}
			}
			if (!$req && ($this->use_wpdb || $this->last_error_no === 1142)) {
				$this->drop_forbidden = true;
				echo '<strong>'.__('Warning:','updraftplus').'</strong> '.__('Your database user does not have permission to drop tables. We will attempt to restore by simply emptying the tables; this should work as long as you are restoring from a WordPress version with the same database structure','updraftplus').' ('.$this->last_error.')'."<br>";
			}
		}

		$restoring_table = '';
		
		$max_allowed_packet = $this->get_max_packet_size();

		while (!gzeof($dbhandle)) {
			// Up to 1Mb
			$buffer = rtrim(gzgets($dbhandle, 1048576));
			// Discard comments
			if (empty($buffer) || substr($buffer, 0, 1) == '#') {
				if ('' == $old_siteurl && preg_match('/^\# Backup of: (http(.*))$/', $buffer, $matches)) {
					$old_siteurl = $matches[1];
					echo '<strong>'.__('Backup of:', 'updraftplus').'</strong> '.htmlspecialchars($old_siteurl).'<br>';
					do_action('updraftplus_restore_db_record_old_siteurl', $old_siteurl);
				} elseif ('' == $old_home && preg_match('/^\# Home URL: (http(.*))$/', $buffer, $matches)) {
					$old_home = $matches[1];
					if ($old_siteurl && $old_home != $old_siteurl) echo '<strong>'.__('Site home:', 'updraftplus').'</strong> '.htmlspecialchars($old_home).'<br>';
					do_action('updraftplus_restore_db_record_old_home', $old_home);
				} elseif ('' == $old_content && preg_match('/^\# Content URL: (http(.*))$/', $buffer, $matches)) {
					$old_content = $matches[1];
					echo '<strong>'.__('Content URL:', 'updraftplus').'</strong> '.htmlspecialchars($old_content).'<br>';
					do_action('updraftplus_restore_db_record_old_content', $old_content);
				} elseif ('' == $old_table_prefix && preg_match('/^\# Table prefix: (\S+)$/', $buffer, $matches)) {
					$old_table_prefix = $matches[1];
					echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
				} elseif ($gathering_siteinfo && preg_match('/^\# Site info: (\S+)$/', $buffer, $matches)) {
					if ('end' == $matches[1]) {
						$gathering_siteinfo = false;
						// Sanity checks
						if (isset($old_siteinfo['multisite']) && !$old_siteinfo['multisite'] && is_multisite()) {
							// Just need to check that you're crazy
							if (!defined('UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE') ||  UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE != true) {
								return new WP_Error('multisite_error', $this->strings['multisite_error']);
							}
							// Got the needed code?
							if (!class_exists('UpdraftPlusAddOn_MultiSite') || !class_exists('UpdraftPlus_Addons_Migrator')) {
								return new WP_Error('missing_addons', __('To import an ordinary WordPress site into a multisite installation requires both the multisite and migrator add-ons.', 'updraftplus'));	
							}
						}
					} elseif (preg_match('/^([^=]+)=(.*)$/', $matches[1], $kvmatches)) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						echo '<strong>'.__('Site information:','updraftplus').'</strong>'.' '.htmlspecialchars($key).' = '.htmlspecialchars($val).'<br>';
						$old_siteinfo[$key]=$val;
						if ('multisite' == $key) {
							if ($val) { $this->ud_backup_is_multisite=1; } else { $this->ud_backup_is_multisite = 0;}
						}
					}
				}
				continue;
			}
			
			// Detect INSERT commands early, so that we can split them if necessary
			if ($sql_line && preg_match('/^\s*(insert into \`?([^\`]*)\`?\s+values)/i', $sql_line, $matches)) {
				$sql_type = 3;
				$insert_prefix = $matches[1];
			}

			# Deal with case where adding this line will take us over the MySQL max_allowed_packet limit - must split, if we can (if it looks like consecutive rows)
			if (3 == $sql_type && $sql_line && strlen($sql_line.$buffer) > $max_allowed_packet && preg_match('/,\s*$/', $sql_line) && preg_match('/^\s*\(/', $buffer)) {
				// Remove the final comma; replace with semi-colon
				$sql_line = substr(rtrim($sql_line), 0, strlen($sql_line)-1).';';
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) $sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				# Run the SQL command; then set up for the next one.
				$this->line++;
				echo "Split line to avoid exceeding maximum packet size (".strlen($sql_line)." + ".strlen($buffer)." > $max_allowed_packet)<br>";
				$do_exec = $this->sql_exec($sql_line, $sql_type);
				if (is_wp_error($do_exec)) return $do_exec;
				# Reset, then carry on
				$sql_line = $insert_prefix." ";
			}

			$sql_line .= $buffer;

			# Do we have a complete line yet?
			if (';' != substr($sql_line, -1, 1)) continue;

			$this->line++;

			# The timed overhead of this is negligible
			if (preg_match('/^\s*drop table if exists \`?([^\`]*)\`?\s*;/i', $sql_line, $matches)) {

				$sql_type = 1;

				if (!isset($printed_new_table_prefix)) {
					$import_table_prefix = $this->pre_sql_actions($import_table_prefix);
					if (false===$import_table_prefix || is_wp_error($import_table_prefix)) return $import_table_prefix;
					$printed_new_table_prefix = true;
				}

				$this->table_name = $matches[1];
				
				// Legacy, less reliable - in case it was not caught before
				if ('' == $old_table_prefix && preg_match('/^([a-z0-9]+)_.*$/i', $this->table_name, $tmatches)) {
					$old_table_prefix = $tmatches[1].'_';
					echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
				}

				$this->new_table_name = ($old_table_prefix) ? $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $this->table_name) : $this->table_name;

				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) {
					$sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				}
			} elseif (preg_match('/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line, $matches)) {

				$sql_type = 2;

				// MySQL 4.1 outputs TYPE=, but accepts ENGINE=; 5.1 onwards accept *only* ENGINE=
				$sql_line = $updraftplus->str_lreplace('TYPE=', 'ENGINE=', $sql_line);

				if (!isset($printed_new_table_prefix)) {
					$import_table_prefix = $this->pre_sql_actions($import_table_prefix);
					if (false===$import_table_prefix || is_wp_error($import_table_prefix)) return $import_table_prefix;
					$printed_new_table_prefix = true;
				}

				// This CREATE TABLE command may be the de-facto mark for the end of processing a previous table (which is so if this is not the first table in the SQL dump)
				if ($restoring_table) {

					// After restoring the options table, we can set old_siteurl if on legacy (i.e. not already set)
					if ($restoring_table == $import_table_prefix.'options') {
						if ('' == $old_siteurl || '' == $old_home || '' == $old_content) {
							global $updraftplus_addons_migrator;
							if (isset($updraftplus_addons_migrator->new_blogid)) switch_to_blog($updraftplus_addons_migrator->new_blogid);

							if ('' == $old_siteurl) {
								$old_siteurl = $wpdb->get_row("SELECT option_value FROM $wpdb->options WHERE option_name='siteurl'")->option_value;
								do_action('updraftplus_restore_db_record_old_siteurl', $old_siteurl);
							}
							if ('' == $old_home) {
								$old_home = $wpdb->get_row("SELECT option_value FROM $wpdb->options WHERE option_name='home'")->option_value;
								do_action('updraftplus_restore_db_record_old_home', $old_home);
							}
							if ('' == $old_content) {
								$old_content = $old_siteurl.'/wp-content';
								do_action('updraftplus_restore_db_record_old_content', $old_content);
							}							
							if (isset($updraftplus_addons_migrator->new_blogid)) restore_current_blog();
						}
					}

					$this->restored_table($restoring_table, $import_table_prefix, $old_table_prefix);

				}

				$engine = "(?)"; $engine_change_message = '';
				if (preg_match('/ENGINE=([^\s;]+)/', $sql_line, $eng_match)) {
					$engine = $eng_match[1];
					if (isset($supported_engines[$engine])) {
						#echo sprintf(__('Requested table engine (%s) is present.', 'updraftplus'), $engine);
						if ('myisam' == strtolower($engine)) {
							$sql_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $sql_line, 1);
						}
					} else {
						$engine_change_message = sprintf(__('Requested table engine (%s) is not present - changing to MyISAM.', 'updraftplus'), $engine)."<br>";
						$sql_line = $updraftplus->str_lreplace("ENGINE=$eng_match", "ENGINE=MyISAM", $sql_line);
						// Remove (M)aria options
						if ('maria' == strtolower($engine) || 'aria' == strtolower($engine)) {
							$sql_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $sql_line, 1);
							$sql_line = preg_replace('/TRANSACTIONAL=\d\s?/', '', $sql_line, 1);
						}
					}
				}

				$this->table_name = $matches[1];
				echo '<strong>'.sprintf(__('Restoring table (%s)','updraftplus'), $engine).":</strong> ".htmlspecialchars($this->table_name);
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) {
					$new_table_name = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $this->table_name);
					echo ' - '.__('will restore as:', 'updraftplus').' '.htmlspecialchars($new_table_name);
					$sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				} else {
					$new_table_name = $this->table_name;
				}
				$restoring_table = $new_table_name;
				echo '<br>';
				if ($engine_change_message) echo $engine_change_message;

			} elseif (preg_match('/^\s*(insert into \`?([^\`]*)\`?\s+values)/i', $sql_line, $matches)) {
				$sql_type = 3;
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) $sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
			} elseif (preg_match('/^\s*(\/\*\!40000 alter|lock) tables? \`?([^\`\(]*)\`?\s+(write|disable|enable)/i', $sql_line, $matches)) {
				# Only binary mysqldump produces this pattern (LOCK TABLES `table` WRITE, ALTER TABLE `table` (DISABLE|ENABLE) KEYS)
				$sql_type = 4;
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) $sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
			}

			$do_exec = $this->sql_exec($sql_line, $sql_type);
			if (is_wp_error($do_exec)) return $do_exec;

			# Reset
			$sql_line = '';
			$sql_type = -1;

		}
		
		if ($restoring_table) $this->restored_table($restoring_table, $import_table_prefix, $old_table_prefix);

		$time_taken = microtime(true) - $this->start_time;
		echo sprintf(__('Finished: lines processed: %d in %.2f seconds','updraftplus'),$this->line, $time_taken)."<br>";
		gzclose($dbhandle);

		global $wp_filesystem;
		$wp_filesystem->delete($working_dir.'/backup.db.gz', false, true);

		return true;

	}

	function sql_exec($sql_line, $sql_type) {

		// if (strlen($sql_line) > 100000) {
		// echo "Length: ".strlen($sql_line)." Mem: ".round(memory_get_usage(true)/1048576, 1)." / ".round(memory_get_usage()/1048576, 1)."<br>";
		// }

// 		echo "Memory usage (Mb): ".round(memory_get_usage(false)/1048576, 1)." : ".round(memory_get_usage(true)/1048576, 1)."<br>";

		global $wpdb, $updraftplus;
			$ignore_errors = false;

		if ($sql_type == 2 && $this->create_forbidden) {
			echo sprintf(__('Cannot create new tables, so skipping this command (%s)', 'updraftplus'), htmlspecialchars($sql_line))."<br>";
			$req = true;
		} else {
			if ($sql_type == 1 && $this->drop_forbidden) {
				$sql_line = "DELETE FROM ".$updraftplus->backquote($this->new_table_name);
				echo sprintf(__('Cannot drop tables, so deleting instead (%s)', 'updraftplus'), $sql_line)."<br>";
				$ignore_errors = true;
			}
// 				echo substr($sql_line, 0, 50)." (".strlen($sql_line).")<br>";
			if ($this->use_wpdb) {
				$req = $wpdb->query($sql_line);
				if (!$req) $this->last_error = $wpdb->last_error;
			} else {
				$req = mysql_unbuffered_query( $sql_line, $this->mysql_dbh );
				if (!$req) $this->last_error = mysql_error($this->mysql_dbh);
			}
			$this->statements_run++;
		}

		if (!$req) {
			if (!$ignore_errors) $this->errors++;
			echo sprintf(_x('An error (%s) occured:', 'The user is being told the number of times an error has happened, e.g. An error (27) occurred', 'updraftplus'), $this->errors)." - ".htmlspecialchars($this->last_error)." - ".__('the database query being run was:','updraftplus').' '.htmlspecialchars($sql_line).'<br>';
			// First command is expected to be DROP TABLE
			if (1 == $this->errors && 2 == $sql_type && 0 == $this->tables_created) {
				return new WP_Error('initial_db_error', __('An error occured on the first CREATE TABLE command - aborting run','updraftplus'));
			}
			if ($this->errors>49) {
				return new WP_Error('too_many_db_errors', __('Too many database errors have occurred - aborting restoration (you will need to restore manually)','updraftplus'));
			}
		} elseif ($sql_type == 2) {
			$this->tables_created++;
		}
		if (($this->line)%50 == 0) {
			if (($this->line)%250 == 0 || $this->line<250) {
				$time_taken = microtime(true) - $this->start_time;
				echo sprintf(__('Database lines processed: %d in %.2f seconds','updraftplus'),$this->line, $time_taken)."<br>";
			}
		}
	}


// 	function option_filter($which) {
// 		if (strpos($which, 'pre_option') !== false) { echo "OPT_FILT: $which<br>\n"; }
// 		return false;
// 	}

	function flush_rewrite_rules() {

		// We have to deal with the fact that the procedures used call get_option, which could be looking at the wrong table prefix, or have the wrong thing cached

// 		add_filter('all', array($this, 'option_filter'));

		global $updraftplus_addons_migrator;
		if (!empty($updraftplus_addons_migrator->new_blogid)) switch_to_blog($updraftplus_addons_migrator->new_blogid);

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			add_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		global $wp_rewrite;
		$wp_rewrite->init();
		flush_rewrite_rules(true);

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			remove_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		if (!empty($updraftplus_addons_migrator->new_blogid)) restore_current_blog();

// 		remove_filter('all', array($this, 'option_filter'));

	}

	function restored_table($table, $import_table_prefix, $old_table_prefix) {

		global $wpdb;

		// WordPress has an option name predicated upon the table prefix. Yuk.
		if ($table == $import_table_prefix.'options' && $import_table_prefix != $old_table_prefix) {
			echo sprintf(__('Table prefix has changed: changing %s table field(s) accordingly:', 'updraftplus'),'option').' ';
			if (false === $wpdb->query("UPDATE $wpdb->options SET option_name='${import_table_prefix}user_roles' WHERE option_name='${old_table_prefix}user_roles' LIMIT 1")) {
				echo __('Error','updraftplus');
			} else {
				echo __('OK', 'updraftplus');
			}
			echo '<br>';

			// Now deal with the situation where the imported database sets a new over-ride upload_path that is absolute - which may not be wanted
			$new_upload_path = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'upload_path'));
			$new_upload_path = (is_object($new_upload_path)) ? $new_upload_path->option_value : '';
			// The danger situation is absolute and points somewhere that is now perhaps not accessible at all
			if (!empty($new_upload_path) && $new_upload_path != $this->prior_upload_path && strpos($new_upload_path, '/') === 0) {
				if (!file_exists($new_upload_path)) {
					echo sprintf(__("Uploads path (%s) does not exist - resetting (%s)",'updraftplus'), $new_upload_path, $this->prior_upload_path)."<br>";
					if (false === $wpdb->query("UPDATE $wpdb->options SET option_value='".esc_sql($this->prior_upload_path)."' WHERE option_name='upload_path' LIMIT 1")) {
						echo __('Error','updraftplus');
					}
					#update_option('upload_path', $this->prior_upload_path);
				}
			}

		} elseif ($table == $import_table_prefix.'usermeta' && $import_table_prefix != $old_table_prefix) {

			echo sprintf(__('Table prefix has changed: changing %s table field(s) accordingly:', 'updraftplus'),'usermeta').' ';

			$um_sql = "SELECT umeta_id, meta_key 
				FROM ${import_table_prefix}usermeta 
				WHERE meta_key 
				LIKE '".str_replace('_', '\_', $old_table_prefix)."%'";

			$meta_keys = $wpdb->get_results($um_sql);
			
			$old_prefix_length = strlen($old_table_prefix);

			$errors_occurred = false;
			foreach ($meta_keys as $meta_key ) {
				//Create new meta key
				$new_meta_key = $import_table_prefix . substr($meta_key->meta_key, $old_prefix_length);
				
				$query = "UPDATE " . $import_table_prefix . "usermeta 
					SET meta_key='".$new_meta_key."' 
					WHERE umeta_id=".$meta_key->umeta_id;

				if (false === $wpdb->query($query)) $errors_occurred = true;
			}

			if ($errors_occurred) {
				echo __('Error', 'updraftplus');
			} else {
				echo __('OK', 'updraftplus');
			}
			echo "<br>";

		}

		do_action('updraftplus_restored_db_table', $table, $import_table_prefix);

		// Re-generate permalinks. Do this last - i.e. make sure everything else is fixed up first.
		if ($table == $import_table_prefix.'options') $this->flush_rewrite_rules();

	}

}

// Get a protected property
class UpdraftPlus_WPDB extends wpdb {
	function updraftplus_getdbh() {
		return $this->dbh;
	}
}
?>
