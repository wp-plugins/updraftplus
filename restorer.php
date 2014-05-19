<?php
if (!defined ('ABSPATH')) die('No direct access allowed');

if(!class_exists('WP_Upgrader')) require_once(ABSPATH.'wp-admin/includes/class-wp-upgrader.php');
class Updraft_Restorer extends WP_Upgrader {

	public $ud_backup_is_multisite = -1;

	private $is_multisite;

	// This is just used so far for detecting whether we're on the second run for an entity or not.
	public $been_restored = array();
	public $delete = false;

	private $created_by_version = false;

	private $ud_backup_info;
	public $ud_foreign;

	public function __construct($skin = null, $info = null) {
		$this->ud_backup_info = $info;
		$this->ud_foreign = (empty($info['meta_foreign'])) ? false : $info['meta_foreign'];
		parent::__construct($skin);
		$this->init();
		$this->backup_strings();
		$this->is_multisite = is_multisite();
	}

	function backup_strings() {
		$this->strings['not_possible'] = __('UpdraftPlus is not able to directly restore this kind of entity. It must be restored manually.','updraftplus');
		$this->strings['no_package'] = __('Backup file not available.','updraftplus');
		$this->strings['copy_failed'] = __('Copying this entity failed.','updraftplus');
		$this->strings['unpack_package'] = __('Unpacking backup...','updraftplus');
		$this->strings['decrypt_database'] = __('Decrypting database (can take a while)...','updraftplus');
		$this->strings['decrypted_database'] = __('Database successfully decrypted.','updraftplus');
		$this->strings['moving_old'] = __('Moving old data out of the way...','updraftplus');
		$this->strings['moving_backup'] = __('Moving unpacked backup into place...','updraftplus');
		$this->strings['restore_database'] = __('Restoring the database (on a large site this can take a long time - if it times out (which can happen if your web hosting company has configured your hosting to limit resources) then you should use a different method, such as phpMyAdmin)...','updraftplus');
		$this->strings['cleaning_up'] = __('Cleaning up rubbish...','updraftplus');
		$this->strings['old_move_failed'] = __('Could not move old files out of the way.','updraftplus').' '.__('You should check the file permissions in your WordPress installation', 'updraftplus');
		$this->strings['old_delete_failed'] = __('Could not delete old directory.','updraftplus');
		$this->strings['new_move_failed'] = __('Could not move new files into place. Check your wp-content/upgrade folder.','updraftplus');
		$this->strings['move_failed'] = __('Could not move the files into place. Check your file permissions.','updraftplus');
		$this->strings['delete_failed'] = __('Failed to delete working directory after restoring.','updraftplus');
		$this->strings['multisite_error'] = __('You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus');
		$this->strings['unpack_failed'] = __('Failed to unpack the archive', 'updraftplus');
	}

	# This function is copied from class WP_Upgrader (WP 3.8 - no significant changes since 3.2 at least); we only had to fork it because it hard-codes using the basename of the zip file as its unpack directory; which can be long; and then combining that with long pathnames in the zip being unpacked can overflow a 256-character path limit (yes, they apparently still exist - amazing!)
	# Subsequently, we have also added the ability to unpack tarballs
	private function unpack_package_archive($package, $delete_package = true) {

		if (!empty($this->ud_foreign) && !empty($this->ud_foreign_working_dir)) {
			if (is_dir($this->ud_foreign_working_dir)) {
				return $this->ud_foreign_working_dir;
			} else {
				global $updraftplus;
				$updraftplus->log('Previously unpacked directory seems to have disappeared; will unpack again');
			}
		}

		global $wp_filesystem, $updraftplus;

		$this->skin->feedback($this->strings['unpack_package'].' ('.basename($package).')');

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';

		//Clean up contents of upgrade directory beforehand.
		$upgrade_files = $wp_filesystem->dirlist($upgrade_folder);
		if ( !empty($upgrade_files) ) {
			foreach ( $upgrade_files as $file )
				$wp_filesystem->delete($upgrade_folder . $file['name'], true);
		}

		//We need a working directory
		#This is the only change from the WP core version - minimise path length
		#$working_dir = $upgrade_folder . basename($package, '.zip');
		$working_dir = $upgrade_folder . substr(md5($package), 0, 8);

		// Clean up working directory
		if ( $wp_filesystem->is_dir($working_dir) )
			$wp_filesystem->delete($working_dir, true);

		// Unzip package to working directory
		if ('.zip' == substr($package, -4, 4)) {
			$result = unzip_file( $package, $working_dir );
		} elseif ('.tar' == substr($package, -4, 4) || '.tar.gz' == substr($package, -7, 7) || '.tar.bz2' == substr($package, -8, 8)) {
			if (!class_exists('UpdraftPlus_Archive_Tar')) {
				if (false === strpos(get_include_path(), UPDRAFTPLUS_DIR.'/includes/PEAR')) set_include_path(UPDRAFTPLUS_DIR.'/includes/PEAR'.PATH_SEPARATOR.get_include_path());

				require_once(UPDRAFTPLUS_DIR.'/includes/PEAR/Archive/Tar.php');
				$p_compress = null;
				if ('.tar.gz' == substr($package, -7, 7)) {
					$p_compress = 'gz';
				} elseif ('.tar.bz2' == substr($package, -8, 8)) {
					$p_compress = 'bz2';
				}

				# It's not pretty. But it works.
				if (is_a($wp_filesystem, 'WP_Filesystem_Direct')) {
					$extract_dir = $working_dir;
				} else {
					$updraft_dir = $updraftplus->backups_dir_location();
					if (!$updraftplus->really_is_writable($updraft_dir)) {
						$updraftplus->log_e("Backup directory (%s) is not writable, or does not exist.", $updraft_dir);
						$result = new WP_Error('unpack_failed', $this->strings['unpack_failed'], $tar->extract);
					} else {
						$extract_dir = $updraft_dir.'/'.basename($working_dir).'-old';
						if (file_exists($extract_dir)) $updraftplus->remove_local_directory($extract_dir);
						$updraftplus->log("Using a temporary folder to extract before moving over WPFS: $extract_dir");
					}
				}
				# Slightly hackish - rather than re-write Archive_Tar to use wp_filesystem, we instead unpack into the location that we already require to be directly writable for other reasons, and then move from there.
			
				if (empty($result)) {
					
					$this->ud_extract_count = 0;
					$this->ud_working_dir = trailingslashit($working_dir);
					$this->ud_extract_dir = untrailingslashit($extract_dir);
					$this->ud_made_dirs = array();
					add_filter('updraftplus_tar_wrote', array($this, 'tar_wrote'), 10, 2);
					$tar = new UpdraftPlus_Archive_Tar($package, $p_compress);
					$result = $tar->extract($extract_dir, false);
					if (!is_a($wp_filesystem, 'WP_Filesystem_Direct')) $updraftplus->remove_local_directory($extract_dir);
					if (true != $result) {
						$result = new WP_Error('unpack_failed', $this->strings['unpack_failed'], $result);
					} else {
						if (!is_a($wp_filesystem, 'WP_Filesystem_Direct')) {
							$updraftplus->log('Moved unpacked tarball contents');
						}
					}
					remove_filter('updraftplus_tar_wrote', array($this, 'tar_wrote'), 10, 2);
				}

			}
		}

		// Once extracted, delete the package if required.
		if ( $delete_package )
			unlink($package);

		if ( is_wp_error($result) ) {
			$wp_filesystem->delete($working_dir, true);
			if ( 'incompatible_archive' == $result->get_error_code() ) {
				return new WP_Error( 'incompatible_archive', $this->strings['incompatible_archive'], $result->get_error_data() );
			}
			return $result;
		}

		if (!empty($this->ud_foreign)) $this->ud_foreign_working_dir = $working_dir;

		return $working_dir;
	}

	public function tar_wrote($result, $file) {
		if (0 !== strpos($file, $this->ud_extract_dir)) return false;
		global $wp_filesystem, $updraftplus;
		if (!is_a($wp_filesystem, 'WP_Filesystem_Direct')) {
			$modint = 100;
			$leaf = substr($file, strlen($this->ud_extract_dir));
			$dirname = dirname($leaf);
			$need_dirs = explode('/', $dirname);
			if (empty($this->ud_made_dirs[$dirname])) {
				$cdir = '';
				foreach ($need_dirs as $ndir) {
					$cdir .= ($cdir) ? '/'.$ndir : $ndir;
					if (empty($this->ud_made_dirs[$cdir])) {
						if ( !$wp_filesystem->mkdir( $this->ud_working_dir.$cdir, FS_CHMOD_DIR) && ! $wp_filesystem->is_dir($this->ud_working_dir.$cdir) ) {
							$updraftplus->log("Failed to create WPFS directory: ".$this->ud_working_dir.$cdir);
							return false;
						} else {
							$this->ud_made_dirs[$cdir] = true;
						}
					}
				}
			}
			$put = $wp_filesystem->put_contents($this->ud_working_dir.$leaf, file_get_contents($file));
			if (is_wp_error($put)) $updraftplus->log_wp_error($put);
			@unlink($file);
		} else {
			$modint = 500;
			$put = true;
		}
		if ($put) {
			$this->ud_extract_count++;
			if ($this->ud_extract_count % $modint == 0) {
				$updraftplus->log_e("%s files have been extracted", $this->ud_extract_count);
			}
		}
		return ($put == true);
	}

	// This returns a wp_filesystem location (and we musn't change that, as we must retain compatibility with the class parent)
	function unpack_package($package, $delete_package = true) {

		global $wp_filesystem, $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location();

		// If not database, then it is a zip - unpack in the usual way
		#if (!preg_match('/db\.gz(\.crypt)?$/i', $package)) return parent::unpack_package($updraft_dir.'/'.$package, $delete_package);
		if (!preg_match('/db\.gz(\.crypt)?$/i', $package) && !preg_match('/\.sql(\.gz)?$/i', $package)) return $this->unpack_package_archive($updraft_dir.'/'.$package, $delete_package);

		$backup_dir = $wp_filesystem->find_folder($updraft_dir);

		// Unpack a database. The general shape of the following is copied from class-wp-upgrader.php

		@set_time_limit(1800);

		$this->skin->feedback('unpack_package');

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
		@$wp_filesystem->mkdir($upgrade_folder, octdec($this->calculate_additive_chmod_oct(FS_CHMOD_DIR, 0775)));

		//Clean up contents of upgrade directory beforehand.
		$upgrade_files = $wp_filesystem->dirlist($upgrade_folder);
		if ( !empty($upgrade_files) ) {
			foreach ( $upgrade_files as $file )
				$wp_filesystem->delete($upgrade_folder.$file['name'], true);
		}

		//We need a working directory
		$working_dir = $upgrade_folder . basename($package, '.crypt');
		# $working_dir_localpath = WP_CONTENT_DIR.'/upgrade/'. basename($package, '.crypt');

		// Clean up working directory
		if ($wp_filesystem->is_dir($working_dir)) $wp_filesystem->delete($working_dir, true);

		if (!$wp_filesystem->mkdir($working_dir, octdec($this->calculate_additive_chmod_oct(FS_CHMOD_DIR, 0775)))) return new WP_Error('mkdir_failed', __('Failed to create a temporary directory','updraftplus').' ('.$working_dir.')');

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

			if (preg_match('/\.sql$/i', $package)) { 
				if (!$wp_filesystem->copy($backup_dir.$package, $working_dir.'/backup.db')) {
					if ( $wp_filesystem->errors->get_error_code() ) { 
						foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
					}
					return new WP_Error('copy_failed', $this->strings['copy_failed']);
				}
			} elseif (!$wp_filesystem->copy($backup_dir.$package, $working_dir.'/backup.db.gz')) {
				if ( $wp_filesystem->errors->get_error_code() ) { 
					foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
				}
				return new WP_Error('copy_failed', $this->strings['copy_failed']);
			}

		}

		// Once extracted, delete the package if required (non-recursive, is a file)
		if ($delete_package) $wp_filesystem->delete($backup_dir.$package, false, true);

		$updraftplus->log("Database successfully unpacked");

		return $working_dir;

	}

	// For moving files out of a directory into their new location
	// The purposes of the $type parameter are 1) to detect 'others' and apply a historical bugfix 2) to detect wpcore, and apply the setting for what to do with wp-config.php 3) to work out whether to delete the directory itself
	// Must use only wp_filesystem
	// $dest_dir must already have a trailing slash
	// $preserve_existing: this setting only applies at the top level: 0 = overwrite with no backup; 1 = make backup of existing; 2 = do nothing if there is existing, 3 = do nothing to the top level directory, but do copy-in contents. Thus, on a multi-archive set where you want a backup, you'd do this: first call with $preserve_existing === 1, then on subsequent zips call with 3
	public function move_backup_in($working_dir, $dest_dir, $preserve_existing = 1, $do_not_overwrite = array('plugins', 'themes', 'uploads', 'upgrade'), $type = 'not-others', $send_actions = false, $force_local = false) {

		global $wp_filesystem, $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();

		#  && !is_a($wp_filesystem, 'WP_Filesystem_Direct')
		if (true == $force_local) {
			$wpfs = new UpdraftPlus_WP_Filesystem_Direct(true);
		} else {
			$wpfs = $wp_filesystem;
		}

		# Get the content to be moved in. Include hidden files = true. Recursion is only required if we're likely to copy-in
		$recursive = (3 == $preserve_existing) ? true : false;
		$upgrade_files = $wpfs->dirlist($working_dir, true, $recursive);

		if (empty($upgrade_files)) return true;

		if (!$wpfs->is_dir($dest_dir)) {
			return new WP_Error('no_such_dir', __('The directory does not exist', 'updraftplus')." ($dest_dir)");
// 			$updraftplus->log_e("The directory does not exist, so will be created (%s).", $dest_dir);
// 			# Attempts to create the directory fail, as due to a core bug, $dest_dir will be the wrong value if it did not already exist (at least for themes - the value of it depends on an is_dir() check wrongly used to detect a relative path)
// 			if (!$wpfs->mkdir($dest_dir)) {
// 				return new WP_Error('create_failed', __('Failed to create directory', 'updraftplus')." ($dest_dir)");
// 			}
		}

		$wpcore_config_moved = false;

		foreach ( $upgrade_files as $file => $filestruc ) {

			if (empty($file)) continue;

			if ($dest_dir.$file == $updraft_dir) {
				$updraftplus->log('Skipping attempt to replace updraft_dir whilst processing '.$type);
				continue;
			}

			// Correctly restore files in 'others' in no directory that were wrongly backed up in versions 1.4.0 - 1.4.48
			if (('others' == $type || 'wpcore' == $type) && preg_match('/^([\-_A-Za-z0-9]+\.php)$/', $file, $matches) && $wpfs->exists($working_dir . "/$file/$file")) {
				if ('others' == $type) {
					echo "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions 1.4.0 - 1.4.48, and sometimes up to 1.6.55 on some Windows servers); will rename to simply $file<br>";
				} else {
					echo "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions before 1.6.55 in certain situations on Windows servers); will rename to simply $file<br>";
				}
				$updraftplus->log("$file/$file: rename to $file");
				$file = $matches[1];
				$tmp_file = rand(0,999999999).'.php';
				// Rename directory
				$wpfs->move($working_dir . "/$file", $working_dir . "/".$tmp_file, true);
				$wpfs->move($working_dir . "/$tmp_file/$file", $working_dir ."/".$file, true);
				$wpfs->rmdir($working_dir . "/$tmp_file", false);
			}

			if ('wp-config.php' == $file && 'wpcore' == $type) {
				if (empty($_POST['updraft_restorer_wpcore_includewpconfig'])) {
					$updraftplus->log_e('wp-config.php from backup: will restore as wp-config-backup.php', 'updraftplus');
					$wpfs->move($working_dir . "/$file", $working_dir . "/wp-config-backup.php", true);
					$file = "wp-config-backup.php";
					$wpcore_config_moved = true;
				} else {
					$updraftplus->log_e("wp-config.php from backup: restoring (as per user's request)", 'updraftplus');
				}
			} elseif ('wpcore' == $type && 'wp-config-backup.php' == $file && $wpcore_config_moved) {
				# The file is already gone; nothing to do
				continue;
			}

			# Sanity check (should not be possible as these were excluded at backup time)
			if (in_array($file, $do_not_overwrite)) continue;

			if (('object-cache.php' == $file || 'advanced-cache.php' == $file) && 'others' == $type) {
				if (false == apply_filters('updraftplus_restorecachefiles', true, $file)) {
					$nfile = preg_replace('/\.php$/', '-backup.php', $file);
					$wpfs->move($working_dir . "/$file", $working_dir . "/".$nfile, true);
					$file=$nfile;
				}
			} elseif (('object-cache-backup.php' == $file || 'advanced-cache-backup.php' == $file) && 'others' == $type) {
				$wpfs->delete($working_dir."/".$file);
				continue;
			} 

			# First, move the existing one, if necessary (may not be present)
			if ($wpfs->exists($dest_dir.$file)) {
				if ($preserve_existing == 1) {
					# Move existing to -old
					if ( !$wpfs->move($dest_dir.$file, $dest_dir.$file.'-old', true) ) {
						return new WP_Error('old_move_failed', $this->strings['old_move_failed']." ($dest_dir.$file)");
					}
				} elseif ($preserve_existing == 0) {
					# Over-write, no backup
					if (!$wpfs->delete($dest_dir.$file, true)) {
						return new WP_Error('old_delete_failed', $this->strings['old_delete_failed']." ($file)");
					}
				}
			}

			# Secondly, move in the new one
			if (2 == $preserve_existing && $wpfs->exists($dest_dir.$file)) {
				# Something exists - no move. Remove it from the temporary directory - so that it will be clean later
				@$wpfs->delete($working_dir.'/'.$file, true);
			} elseif (3 != $preserve_existing || !$wpfs->exists($dest_dir.$file)) {
				$is_dir = $wpfs->is_dir($working_dir."/".$file);
				# This method is broken due to https://core.trac.wordpress.org/ticket/26598
				#if (empty($chmod)) $chmod = $wpfs->getnumchmodfromh($wpfs->gethchmod($dest_dir));
				if (empty($chmod)) $chmod = octdec(sprintf("%04d", $this->get_current_chmod($dest_dir, $wpfs)));
				if ($wpfs->move($working_dir."/".$file, $dest_dir.$file, true) ) {
					if ($send_actions) do_action('updraftplus_restored_'.$type.'_one', $file);
					# Make sure permissions are at least as great as those of the parent
					if ($is_dir && !empty($chmod)) $this->chmod_if_needed($dest_dir.$file, $chmod, false, $wpfs);
				} else {
					return new WP_Error('move_failed', $this->strings['move_failed'], $working_dir."/".$file." -> ".$dest_dir.$file);
				}
			} elseif (3 == $preserve_existing && !empty($filestruc['files'])) {
				# The directory ($dest_dir) already exists, and we've been requested to copy-in. We need to perform the recursive copy-in
				# $filestruc['files'] is then a new structure like $upgrade_files
				# First pass: create directory structure
				# Get chmod value for the parent directory, and re-use it (instead of passing false)

				# This method is broken due to https://core.trac.wordpress.org/ticket/26598
				#if (empty($chmod)) $chmod = $wpfs->getnumchmodfromh($wpfs->gethchmod($dest_dir));
				if (empty($chmod)) $chmod = octdec(sprintf("%04d", $this->get_current_chmod($dest_dir, $wpfs)));
				# Copy in the files. This also needs to make sure the directories exist, in case the zip file lacks entries
				$delete_root = ('others' == $type || 'wpcore' == $type) ? false : true;

				$copy_in = $this->copy_files_in($working_dir.'/'.$file, $dest_dir.$file, $filestruc['files'], $chmod, $delete_root);
				if (!empty($chmod)) $this->chmod_if_needed($dest_dir.$file, $chmod, false, $wpfs);

				if (is_wp_error($copy_in)) return $copy_in;
				if (!$copy_in) return new WP_Error('move_failed', $this->strings['move_failed'], "(2) ".$working_dir.'/'.$file." -> ".$dest_dir.$file);

				$wpfs->rmdir($working_dir.'/'.$file);
			} else {
				$wpfs->rmdir($working_dir.'/'.$file);
			}
		}

		return true;

	}

	# $dest_dir must already exist
	function copy_files_in($source_dir, $dest_dir, $files, $chmod = false, $deletesource = false) {
		global $wp_filesystem, $updraftplus;
		foreach ($files as $rname => $rfile) {
			if ('d' != $rfile['type']) {
				# Delete it if it already exists (or perhaps WP does it for us)
					if (!$wp_filesystem->move($source_dir.'/'.$rname, $dest_dir.'/'.$rname, true)) {
					$updraftplus->log_e('Failed to move file (check your file permissions and disk quota): %s', $source_dir.'/'.$rname." -&gt; ".$dest_dir.'/'.$rname);
					return false;
				}
			} else {
				# Directory
				if ($wp_filesystem->is_file($dest_dir.'/'.$rname)) @$wp_filesystem->delete($dest_dir.'/'.$rname, false, 'f');
				# No such directory yet: just move it
				if (!$wp_filesystem->is_dir($dest_dir.'/'.$rname)) {
					if (!$wp_filesystem->move($source_dir.'/'.$rname, $dest_dir.'/'.$rname, false)) {
						$updraftplus->log_e('Failed to move directory (check your file permissions and disk quota): %s', $source_dir.'/'.$rname." -&gt; ".$dest_dir.'/'.$rname);
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
	public function pre_restore_backup($backup_files, $type, $info) {

		if (is_string($backup_files)) $backup_files=array($backup_files);

		if ('more' == $type) {
			$this->skin->feedback('not_possible');
			return;
		}

		// Ensure access to the indicated directory - and to WP_CONTENT_DIR (in which we use upgrade/)
		$need_these = array(WP_CONTENT_DIR);
		if (!empty($info['path'])) $need_these[] = $info['path'];

		$res = $this->fs_connect($need_these);
		if (false === $res || is_wp_error($res)) return $res;

		# Check upgrade directory is writable (instead of having non-obvious messages when we try to write)
		# In theory, this is redundant (since we already checked for access to WP_CONTENT_DIR); but in practice, this extra check has been needed

		global $wp_filesystem, $updraftplus, $updraftplus_admin, $updraftplus_addons_migrator;

		if (empty($this->pre_restore_updatedir_writable)) {
			$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
			@$wp_filesystem->mkdir($upgrade_folder, octdec($this->calculate_additive_chmod_oct(FS_CHMOD_DIR, 0775)));
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

// 		$this->maintenance_mode(true);
// 
// 		$updraftplus->log_e('Testing file permissions...');

		$ret_val = true;

		$updraft_dir = $updraftplus->backups_dir_location();

		if (('plugins' == $type || 'uploads' == $type || 'themes' == $type) && (!is_multisite() || $this->ud_backup_is_multisite !== 0 || ('uploads' != $type || empty($updraftplus_addons_migrator->new_blogid )))) {
// 			if ($wp_filesystem->exists($wp_filesystem_dir.'-old')) {
			if (file_exists($updraft_dir.'/'.basename($wp_filesystem_dir)."-old")) {
				$ret_val = new WP_Error('already_exists', sprintf(__('Existing unremoved folders from a previous restore exist (please use the "Delete Old Directories" button to delete them before trying again): %s', 'updraftplus'), $wp_filesystem_dir.'-old'));

			} else {
				// No longer used - since we now do not move the directories themselves
// 				# File permissions test; see if we can move the directory back and forth
// 				if (!$wp_filesystem->move($wp_filesystem_dir, $wp_filesystem_dir."-old", false)) {
// 					$ret_val = new WP_Error('old_move_failed', $this->strings['old_move_failed']);
// 				} else {
// 					$wp_filesystem->move($wp_filesystem_dir."-old", $wp_filesystem_dir, false);
// 				}
			}
		}

// 		$this->maintenance_mode(false);

		if (!empty($this->ud_foreign)) {
			$known_foreigners = apply_filters('updraftplus_accept_archivename', array());
			if (!is_array($known_foreigners) || empty($known_foreigners[$this->ud_foreign])) {
				return new WP_Error('uk_foreign', __('This version of UpdraftPlus does not know how to handle this type of foreign backup', 'updraftplus').' ('.$this->ud_foreign.')');
			}
		}

		return $ret_val;
	}

	function get_wp_filesystem_dir($path) {
		global $wp_filesystem;
		// Get the wp_filesystem location for the folder on the local install
		switch ($path) {
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
	public function restore_backup($backup_file, $type, $info, $last_one = false) {

		if ('more' == $type) {
			$this->skin->feedback('not_possible');
			return;
		}

		global $wp_filesystem, $updraftplus_addons_migrator, $updraftplus;

		$updraftplus->log("restore_backup(backup_file=$backup_file, type=$type, info=".serialize($info).", last_one=$last_one)");

		$updraft_dir = $updraftplus->backups_dir_location();

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

				$this->skin->feedback('moving_backup');

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

				$this->skin->feedback('moving_old');

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

					$this->skin->feedback('moving_backup');

					$move_from = $this->get_first_directory($working_dir, array(basename($info['path']), $type));

					if ( !$wp_filesystem->move($move_from, $fsud, true) ) {
						return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
					}

					@$wp_filesystem->delete($move_from);

				} else {
					return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
				}

			}
		} elseif ('db' == $type) {

			// $import_table_prefix is received as a reference
			$rdb = $this->restore_backup_db($working_dir, $working_dir_localpath, $import_table_prefix);
			if (false === $rdb || is_wp_error($rdb)) return $rdb;

		} elseif ('others' == $type) {

			$dirname = basename($info['path']);

			# For foreign 'Simple Backup', we need to keep going down until we find wp-content 
			if (empty($this->ud_foreign)) {
				$move_from = $working_dir;
			} else {
				$move_from = $this->search_for_folder('wp-content', $working_dir);
				if (!is_string($move_from)) return new WP_Error('not_found', __('The WordPress content folder (wp-content) was not found in this zip file.', 'updraftplus'));
			}

			// In this special case, the backup contents are not in a folder, so it is not simply a case of moving the folder around, but rather looping over all that we find

			# On subsequent archives of a multi-archive set, don't move anything; but do on the first
			$preserve_existing = (isset($this->been_restored['others'])) ? 3 : 1;

			$this->move_backup_in($move_from, trailingslashit($wp_filesystem_dir), $preserve_existing, array('plugins', 'themes', 'uploads', 'upgrade'), 'others');

			$this->been_restored['others'] = true;

		} else {

			// Default action: used for plugins, themes and uploads (and wpcore, via a filter)

			// Multi-archive sets: we record what we've already begun on, and on subsequent runs, copy in instead of replacing
			$movedin = apply_filters('updraftplus_restore_movein_'.$type, $working_dir, $this->abspath, $wp_filesystem_dir);
			// A filter, to allow add-ons to perform the install of non-standard entities, or to indicate that it's not possible
			if (false === $movedin) {
				$this->skin->feedback('not_possible');
			} elseif (is_wp_error($movedin)) {
				return $movedin;
			} elseif (true !== $movedin) {

				# On the first time, create the -old directory in updraft_dir
				# (Old style: On the first time, move the existing data to -old)
				if (!isset($this->been_restored[$type])) {

					# First, try filesystem-level move
					$old_dir = $updraft_dir.'/'.$type.'-old';
					if (is_dir($old_dir)) {
						$updraftplus->log_e('%s: This directory already exists, and will be replaced', $old_dir);
						$updraftplus->remove_local_directory($old_dir);
					}

					$move_old_destination = 0;

					if (@mkdir($old_dir)) {
						$updraftplus->log("Moving old data: filesystem method / updraft_dir is potentially possible");
						$move_old_destination = 1;
					}

					# Try wp_filesystem instead
					if ($wp_filesystem->exists($wp_filesystem_dir."-old")) {
						// Is better to warn and delete the backup than abort mid-restore and leave inconsistent site
						$updraftplus->log_e('%s: This directory already exists, and will be replaced', $wp_filesystem_dir."-old");
						# In theory, supply true as the 3rd parameter of true achieves this; in practice, not always so (leads to support requests)
						$wp_filesystem->delete($wp_filesystem_dir."-old", true);
						if ($wp_filesystem->exists($wp_filesystem_dir."-old")) {
							$updraftplus->log("Failed to remove existing directory (".$wp_filesystem_dir."-old");
							$failed_to_remove = true;
							#return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
						}
					}

					if (empty($failed_to_remove) && @$wp_filesystem->mkdir($wp_filesystem_dir."-old")) {
						$updraftplus->log("Moving old data: can potentially use wp_filesystem method / -old");
						$move_old_destination += 2;
					}

					if (0 == $move_old_destination) {
						$updraftplus->log_e("File permissions do not allow the old data to be moved and retained; instead, it will be deleted.");
					}

					$this->skin->feedback('moving_old');

					# First, try direct filesystem method into updraft_dir
					if (1 == $move_old_destination % 2) {
						# The final 'true' forces direct filesystem access
						$move_old = @$this->move_backup_in($get_dir, $updraft_dir.'/'.$type.'-old/' , 3, array(), $type, false, true);
						if (is_wp_error($move_old)) $updraftplus->log_wp_error($move_old);
					}

					# Try wp_filesystem method into -old if that failed
					if (2 >= $move_old_destination && (0 == $move_old_destination % 2 || (!empty($move_old) && is_wp_error($move_old)))) {
						$move_old = @$this->move_backup_in($wp_filesystem_dir, $wp_filesystem_dir."-old/" , 3, array(), $type);
						#if (is_wp_error($move_old)) return $move_old;
						if (is_wp_error($move_old)) $updraftplus->log_wp_error($move_old);
// 						if ( !$wp_filesystem->move($wp_filesystem_dir, $wp_filesystem_dir."-old", false) ) {
// 							return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
// 						}
					}

					# Finally, when all else fails, nuke it
					if (0 == $move_old_destination || (!empty($move_old) && is_wp_error($move_old))) {
						$updraftplus->log("$type: $wp_filesystem_dir: deleting contents (as attempts to copy failed)");
						$del_files = $wp_filesystem->dirlist($wp_filesystem_dir, true, false);
						if (empty($del_files)) $del_files = array();
						foreach ( $del_files as $file => $filestruc ) {
							if (empty($file)) continue;
							$wp_filesystem->delete($wp_filesystem_dir.'/'.$file, true);
						}
					}

				}

				# For foreign 'Simple Backup', we need to keep going down until we find wp-content 
				if (empty($this->ud_foreign)) {
					$working_dir_use = $working_dir;
				} else {
					$working_dir_use = $this->search_for_folder('wp-content', $working_dir);
					if (!is_string($working_dir_use)) return new WP_Error('not_found', __('The WordPress content folder (wp-content) was not found in this zip file.', 'updraftplus'));
				}

				// The backup may not actually have /$type, since that is info from the present site
				$move_from = $this->get_first_directory($working_dir_use, array(basename($info['path']), $type));
				if (false === $move_from) return new WP_Error('new_move_failed', $this->strings['new_move_failed']);

				$this->skin->feedback('moving_backup');

				// Old-style
// 				if (!isset($this->been_restored[$type])) {
// 					if (!$wp_filesystem->move($move_from, $wp_filesystem_dir, true) ) {
// 						return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
// 					}
// 				} else {
					$move_in = $this->move_backup_in($move_from, trailingslashit($wp_filesystem_dir), 3, array(), $type);
					if (is_wp_error($move_in)) return $move_in;
					if (!$move_in) return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
					$wp_filesystem->rmdir($move_from);
// 				}

			}

			$this->been_restored[$type] = true;

		}

		$attempt_delete = true;
		if (!empty($this->ud_foreign) && !$last_one) $attempt_delete = false;

		// Non-recursive, so the directory needs to be empty
		if ($attempt_delete) $this->skin->feedback('cleaning_up');

		if ($attempt_delete && !$wp_filesystem->delete($working_dir, !empty($this->ud_foreign))) {

			# TODO: Can remove this after 1-Jan-2015; or at least, make it so that it requires the version number to be present.
			$fixed_it_now = false;
			# Deal with a corner-case in version 1.8.5
			if ('uploads' == $type && (empty($this->created_by_version) || (version_compare($this->created_by_version, '1.8.5', '>=') && version_compare($this->created_by_version, '1.8.8', '<')))) {
				$updraftplus->log("Clean-up failed with uploads: will attempt 1.8.5-1.8.7 fix (".$this->created_by_version.")");
				$move_in = @$this->move_backup_in(dirname($move_from), trailingslashit($wp_filesystem_dir), 3, array(), $type);
				$updraftplus->log("Result: ".serialize($move_in));
				if ($wp_filesystem->delete($working_dir)) $fixed_it_now = true;
			}

			if (!$fixed_it_now) {
				$updraftplus->log_e('Error: %s', $this->strings['delete_failed'].' ('.$working_dir.')');
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
					$updraftplus->log_e('Unable to enumerate files in that directory.');
				}
			}
		}

		# Permissions changes (at the top level - i.e. this does not reply if using recursion) are now *additive* - i.e. there's no danger of permissions being removed from what's on-disk
		switch($type) {
			case 'wpcore':
				$this->chmod_if_needed($wp_filesystem_dir, FS_CHMOD_DIR, false, $wp_filesystem);
				// In case we restored a .htaccess which is incorrect for the local setup
				$this->flush_rewrite_rules();
			break;
			case 'uploads':
				$this->chmod_if_needed($wp_filesystem_dir, FS_CHMOD_DIR, false, $wp_filesystem);
			break;
			case 'db':
				do_action('updraftplus_restored_db', array('expected_oldsiteurl' => $this->old_siteurl, 'expected_oldhome' => $this->old_home, 'expected_oldcontent' => $this->old_content), $import_table_prefix);
				$this->flush_rewrite_rules();
			break;
			default:
				$this->chmod_if_needed($wp_filesystem_dir, FS_CHMOD_DIR, false, $wp_filesystem);
		}
		# db was already done
		if ('db' != $type) do_action('updraftplus_restored_'.$type);

		return true;

	}

	private function search_for_folder($folder, $startat) {
		# Exists in this folder?
		if (is_dir($startat.'/'.$folder)) return trailingslashit($startat).$folder;
		# Does not
		if($handle = opendir($startat)) {
			while (($file = readdir($handle)) !== false) {
				if ($file != '.' && $file != '..' && is_dir($startat).'/'.$file) {
					$ss = $this->search_for_folder($folder, trailingslashit($startat).$file);
					if (is_string($ss)) return $ss;
				}
			}
			closedir($handle);
		}
		return false;
	}

	# Returns an octal string (but not an octal number)
	function get_current_chmod($file, $wpfs = false) {
		if (false == $wpfs) {
			global $wp_filesystem;
			$wpfs = $wp_filesystem;
		}
		# getchmod() is broken at least as recently as WP3.8 - see: https://core.trac.wordpress.org/ticket/26598
		return (is_a($wpfs, 'WP_Filesystem_Direct')) ? substr(sprintf("%06d", decoct(@fileperms($file))),3) : $wpfs->getchmod($file);
	}

	# Returns a string in octal format
	# $new_chmod should be an octal, i.e. what you'd pass to chmod()
	function calculate_additive_chmod_oct($old_chmod, $new_chmod) {
		# chmod() expects octal form, which means a preceding zero - see http://php.net/chmod
		$old_chmod = sprintf("%04d", $old_chmod);
		$new_chmod = sprintf("%04d", decoct($new_chmod));

		for ($i=1; $i<=3; $i++) {
			$oldbit = substr($old_chmod, $i, 1);
			$newbit = substr($new_chmod, $i, 1);
			for ($j=0; $j<=2; $j++) {
				if (($oldbit & (1<<$j)) && !($newbit & (1<<$j))) {
					$newbit = (string)($newbit | 1<<$j);
					$new_chmod = sprintf("%04d", substr($new_chmod, 0, $i).$newbit.substr($new_chmod, $i+1));
				}
			}
		}

		return $new_chmod;
	}

	# "If needed" means, "If the permissions are not already more permissive than this". i.e. This will not tighten permissions from what the user had before (we trust them)
	# $chmod should be an octal - i.e. the same as you'd pass to chmod()
	function chmod_if_needed($dir, $chmod, $recursive = false, $wpfs = false, $suppress = true) {

		# Do nothing on Windows
		if (strtoupper(substr(php_uname('s'), 0, 3)) === 'WIN') return true;

		if (false == $wpfs) {
			global $wp_filesystem;
			$wpfs = $wp_filesystem;
		}

		$old_chmod = $this->get_current_chmod($dir, $wpfs);

		# Sanity fcheck
		if (strlen($old_chmod) < 3) return;

		$new_chmod = $this->calculate_additive_chmod_oct($old_chmod, $chmod);

		# Don't fix what isn't broken
		if (!$recursive && $new_chmod == $old_chmod) return true;

		$new_chmod = octdec($new_chmod);

		if ($suppress) {
			return @$wpfs->chmod($dir, $new_chmod, $recursive);
		} else {
			return $wpfs->chmod($dir, $new_chmod, $recursive);
		}
	}

	// $dirnames: an array of preferred names
	private function get_first_directory($working_dir, $dirnames) {
		global $wp_filesystem, $updraftplus;
		$fdirnames = array_flip($dirnames);
		$dirlist = $wp_filesystem->dirlist($working_dir, true, false);
		if (is_array($dirlist)) {
			$move_from = false;
			foreach ($dirlist as $name => $struc) {
				if (isset($struc['type']) && 'd' != $struc['type']) continue;
				if (false === $move_from) {
					if (isset($fdirnames[$name])) {
						$move_from = $working_dir . "/".$name;
					} elseif (preg_match('/^([^\.].*)$/', $name, $fmatch)) {
						$first_entry = $working_dir."/".$fmatch[1];
					}
				}
			}
			if ($move_from === false && isset($first_entry)) {
				$updraftplus->log_e('Using directory from backup: %s', basename($first_entry));
				$move_from = $first_entry;
			}
		} else {
			# That shouldn't happen. Fall back to default
			$move_from = $working_dir."/".$dirnames[0];
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

		global $updraftplus;
		echo $updraftplus->log_e('New table prefix: %s', $import_table_prefix);

		return $import_table_prefix;

	}

	public function option_filter_permalink_structure($val) {
		global $updraftplus;
		return $updraftplus->option_filter_get('permalink_structure');
	}

	public function option_filter_page_on_front($val) {
		global $updraftplus;
		return $updraftplus->option_filter_get('page_on_front');
	}

	public function option_filter_rewrite_rules($val) {
		global $updraftplus;
		return $updraftplus->option_filter_get('rewrite_rules');
	}

	// The pass-by-reference on $import_table_prefix is due to historical refactoring
	private function restore_backup_db($working_dir, $working_dir_localpath, &$import_table_prefix) {

		do_action('updraftplus_restore_db_pre');

		# This is now a legacy option (at least on the front end), so we should not see it much
		$this->prior_upload_path = get_option('upload_path');

		// There is a file backup.db(.gz) inside the working directory

		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if (@ini_get('safe_mode') && 'off' != strtolower(@ini_get('safe_mode'))) {
			echo "<p>".__('Warning: PHP safe_mode is active on your server. Timeouts are much more likely. If these happen, then you will need to manually restore the file via phpMyAdmin or another method.', 'updraftplus')."</p><br/>";
		}

		$db_basename = 'backup.db.gz';
		if (!empty($this->ud_foreign)) {

			$plugins = apply_filters('updraftplus_accept_archivename', array());

			if (empty($plugins[$this->ud_foreign])) return new WP_Error('unknown', sprintf(__('Backup created by unknown source (%s) - cannot be restored.', 'updraftplus'), $this->ud_foreign));

			if (empty($plugins[$this->ud_foreign]['separatedb'])) {
				$db_basename = apply_filters('updraftplus_foreign_separatedbname', false, $this->ud_foreign, $this->ud_backup_info, $working_dir_localpath);
			} elseif (file_exists($working_dir_localpath.'/backup.db')) {
				$db_basename = 'backup.db';
			}
		}

		// wp_filesystem has no gzopen method, so we switch to using the local filesystem (which is harmless, since we are performing read-only operations)
		if (false === $db_basename || !is_readable($working_dir_localpath.'/'.$db_basename)) return new WP_Error('dbopen_failed',__('Failed to find database file','updraftplus')." ($working_dir/".$db_basename.")");

		global $wpdb, $updraftplus;
		
		$this->skin->feedback('restore_database');

		$is_plain = (substr($db_basename, -3, 3) == '.db');

		// Read-only access: don't need to go through WP_Filesystem
		if ($is_plain) {
			$dbhandle = fopen($working_dir_localpath.'/'.$db_basename, 'r');
		} else {
			$dbhandle = gzopen($working_dir_localpath.'/'.$db_basename, 'r');
		}
		if (!$dbhandle) return new WP_Error('dbopen_failed',__('Failed to open database file','updraftplus'));

		$this->line = 0;

		// Line up a wpdb-like object to use
		// mysql_query will throw E_DEPRECATED from PHP 5.5, so we expect WordPress to have switched to something else by then
// 			$use_wpdb = (version_compare(phpversion(), '5.5', '>=') || !function_exists('mysql_query') || !$wpdb->is_mysql || !$wpdb->ready) ? true : false;
		// Seems not - PHP 5.5 is immanent for release
		$this->use_wpdb = ((!function_exists('mysql_query') && !function_exists('mysqli_query')) || !$wpdb->is_mysql || !$wpdb->ready) ? true : false;

		if (false == $this->use_wpdb) {
			// We have our own extension which drops lots of the overhead on the query
			$wpdb_obj = new UpdraftPlus_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
			// Was that successful?
			if (!$wpdb_obj->is_mysql || !$wpdb_obj->ready) {
				$this->use_wpdb = true;
			} else {
				$this->mysql_dbh = $wpdb_obj->updraftplus_getdbh();
				$this->use_mysqli = $wpdb_obj->updraftplus_use_mysqli();
			}
		}

		if (true == $this->use_wpdb) {
			$updraftplus->log_e('Database access: Direct MySQL access is not available, so we are falling back to wpdb (this will be considerably slower)');
		} else {
			$updraftplus->log("Using direct MySQL access; value of use_mysqli is: ".($this->use_mysqli ? '1' : '0'));
			if ($this->use_mysqli) {
				@mysqli_query($this->mysql_dbh, 'SET SESSION query_cache_type = OFF;');
			} else {
				@mysql_query('SET SESSION query_cache_type = OFF;', $this->mysql_dbh );
			}
		}

		// Find the supported engines - in case the dump had something else (case seen: saved from MariaDB with engine Aria; imported into plain MySQL without)
		$supported_engines = $wpdb->get_results("SHOW ENGINES", OBJECT_K);

		$this->errors = 0;
		$this->statements_run = 0;
		$this->insert_statements_run = 0;
		$this->tables_created = 0;

		$sql_line = "";
		$sql_type = -1;

		$this->start_time = microtime(true);

		$old_wpversion = '';
		$this->old_siteurl = '';
		$this->old_home = '';
		$this->old_content = '';
		$old_table_prefix = '';
		$old_siteinfo = array();
		$gathering_siteinfo = true;

		$this->create_forbidden = false;
		$this->drop_forbidden = false;

		$this->last_error = '';
		$random_table_name = 'updraft_tmp_'.rand(0,9999999).md5(microtime(true));

		# The only purpose in funnelling queries directly here is to be able to get the error number
		if ($this->use_wpdb) {
			$req = $wpdb->query("CREATE TABLE $random_table_name");
			if (!$req) $this->last_error = $wpdb->last_error;
			$this->last_error_no = false;
		} else {
			if ($this->use_mysqli) {
				$req = mysqli_query($this->mysql_dbh, "CREATE TABLE $random_table_name");
			} else {
				$req = mysql_unbuffered_query("CREATE TABLE $random_table_name", $this->mysql_dbh);
			}
			if (!$req) {
				$this->last_error = ($this->use_mysqli) ? mysqli_error($this->mysql_dbh) : mysql_error($this->mysql_dbh);
				$this->last_error_no = ($this->use_mysqli) ? mysqli_errno($this->mysql_dbh) : mysql_errno($this->mysql_dbh);
			}
		}

		if (!$req && ($this->use_wpdb || 1142 === $this->last_error_no)) {
			$this->create_forbidden = true;
			# If we can't create, then there's no point dropping
			$this->drop_forbidden = true;
			echo '<strong>'.__('Warning:', 'updraftplus').'</strong> ';
			$updraftplus->log_e('Your database user does not have permission to create tables. We will attempt to restore by simply emptying the tables; this should work as long as a) you are restoring from a WordPress version with the same database structure, and b) Your imported database does not contain any tables which are not already present on the importing site.', ' ('.$this->last_error.')');
		} else {
			if ($this->use_wpdb) {
				$req = $wpdb->query("DROP TABLE $random_table_name");
				if (!$req) $this->last_error = $wpdb->last_error;
				$this->last_error_no = false;
			} else {
				if ($this->use_mysqli) {
					$req = mysqli_query($this->mysql_dbh, "DROP TABLE $random_table_name");
				} else {
					$req = mysql_unbuffered_query("DROP TABLE $random_table_name", $this->mysql_dbh);
				}
				if (!$req) {
					$this->last_error = ($this->use_mysqli) ? mysqli_error($this->mysql_dbh) : mysql_error($this->mysql_dbh);
					$this->last_error_no = ($this->use_mysqli) ? mysqli_errno($this->mysql_dbh) : mysql_errno($this->mysql_dbh);
				}
			}
			if (!$req && ($this->use_wpdb || $this->last_error_no === 1142)) {
				$this->drop_forbidden = true;
				echo '<strong>'.__('Warning:','updraftplus').'</strong> ';
				$updraftplus->log_e('Your database user does not have permission to drop tables. We will attempt to restore by simply emptying the tables; this should work as long as you are restoring from a WordPress version with the same database structure (%s)', ' ('.$this->last_error.')');
			}
		}

		$restoring_table = '';

		$this->max_allowed_packet = $updraftplus->get_max_packet_size();

		while (($is_plain && !feof($dbhandle)) || (!$is_plain && !gzeof($dbhandle))) {
			// Up to 1Mb
			$buffer = ($is_plain) ? rtrim(fgets($dbhandle, 1048576)) : rtrim(gzgets($dbhandle, 1048576));
			// Discard comments
			if (empty($buffer) || substr($buffer, 0, 1) == '#' || preg_match('/^--(\s|$)/', substr($buffer, 0, 3))) {
				if ('' == $this->old_siteurl && preg_match('/^\# Backup of: (http(.*))$/', $buffer, $matches)) {
					$this->old_siteurl = untrailingslashit($matches[1]);
					$updraftplus->log_e('<strong>Backup of:</strong> %s', htmlspecialchars($this->old_siteurl));
					do_action('updraftplus_restore_db_record_old_siteurl', $this->old_siteurl);
				} elseif (false === $this->created_by_version && preg_match('/^\# Created by UpdraftPlus version ([\d\.]+)/', $buffer, $matches)) {
					$this->created_by_version = trim($matches[1]);
					echo '<strong>'.__('Backup created by:', 'updraftplus').'</strong> '.htmlspecialchars($this->created_by_version).'<br>';
					$updraftplus->log('Backup created by: '.$this->created_by_version);
				} elseif ('' == $this->old_home && preg_match('/^\# Home URL: (http(.*))$/', $buffer, $matches)) {
					$this->old_home = untrailingslashit($matches[1]);
					if ($this->old_siteurl && $this->old_home != $this->old_siteurl) {
						echo '<strong>'.__('Site home:', 'updraftplus').'</strong> '.htmlspecialchars($this->old_home).'<br>';
						$updraftplus->log('Site home: '.$this->old_home);
					}
					do_action('updraftplus_restore_db_record_old_home', $this->old_home);
				} elseif ('' == $this->old_content && preg_match('/^\# Content URL: (http(.*))$/', $buffer, $matches)) {
					$this->old_content = untrailingslashit($matches[1]);
					echo '<strong>'.__('Content URL:', 'updraftplus').'</strong> '.htmlspecialchars($this->old_content).'<br>';
					$updraftplus->log('Content URL: '.$this->old_content);
					do_action('updraftplus_restore_db_record_old_content', $this->old_content);
				} elseif ('' == $old_table_prefix && (preg_match('/^\# Table prefix: (\S+)$/', $buffer, $matches) || preg_match('/^-- Table Prefix: (\S+)$/i', $buffer, $matches))) {
					# We also support backwpup style:
					# -- Table Prefix: wp_
					$old_table_prefix = $matches[1];
					echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
					$updraftplus->log("Old table prefix: ".$old_table_prefix);
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
						$updraftplus->log("Site information: ".$key."=".$val);
						$old_siteinfo[$key]=$val;
						if ('multisite' == $key) {
							if ($val) { $this->ud_backup_is_multisite=1; } else { $this->ud_backup_is_multisite = 0;}
						}
					}
				}
				continue;
			}
			
			// Detect INSERT commands early, so that we can split them if necessary
			if ($sql_line && preg_match('/^\s*(insert into \`?([^\`]*)\`?\s+(values|\())/i', $sql_line, $matches)) {
				$sql_type = 3;
				$insert_prefix = $matches[1];
			}

			# Deal with case where adding this line will take us over the MySQL max_allowed_packet limit - must split, if we can (if it looks like consecutive rows)
			# ALlow a 100-byte margin for error (including searching/replacing table prefix)
			if (3 == $sql_type && $sql_line && strlen($sql_line.$buffer) > ($this->max_allowed_packet - 100) && preg_match('/,\s*$/', $sql_line) && preg_match('/^\s*\(/', $buffer)) {
				// Remove the final comma; replace with semi-colon
				$sql_line = substr(rtrim($sql_line), 0, strlen($sql_line)-1).';';
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) $sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				# Run the SQL command; then set up for the next one.
				$this->line++;
				echo __("Split line to avoid exceeding maximum packet size", 'updraftplus')." (".strlen($sql_line)." + ".strlen($buffer)." : ".$this->max_allowed_packet.")<br>";
				$updraftplus->log("Split line to avoid exceeding maximum packet size (".strlen($sql_line)." + ".strlen($buffer)." : ".$this->max_allowed_packet.")");
				$do_exec = $this->sql_exec($sql_line, $sql_type, $import_table_prefix);
				if (is_wp_error($do_exec)) return $do_exec;
				# Reset, then carry on
				$sql_line = $insert_prefix." ";
			}

			$sql_line .= $buffer;
			# Do we have a complete line yet? We used to just test the final character for ';' here (up to 1.8.12), but that was too unsophisticated
			if (
				(3 == $sql_type && !preg_match('/\)\s*;$/', substr($sql_line, -3, 3)))
				|| (3 != $sql_type && ';' != substr($sql_line, -1, 1))
			) continue;

			$this->line++;

			# We now have a complete line - process it

			if (3 == $sql_type && $sql_line && strlen($sql_line) > $this->max_allowed_packet) {
				$this->log_oversized_packet($sql_line);
				# Reset
				$sql_line = '';
				$sql_type = -1;
				# If this is the very first SQL line of the options table, we need to bail; it's essential
				if (0 == $this->insert_statements_run && $restoring_table && $restoring_table == $import_table_prefix.'options') {
					return new WP_Error('initial_db_error', sprintf(__('An error occurred on the first %s command - aborting run','updraftplus'), 'INSERT (options)'));
				}
				continue;
			}

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
					$updraftplus->log("Old table prefix: $old_table_prefix");
				}

				$this->new_table_name = ($old_table_prefix) ? $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $this->table_name) : $this->table_name;

				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) {
					$sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				}
			} elseif (preg_match('/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line, $matches)) {

				$sql_type = 2;
				$this->insert_statements_run = 0;
				$this->table_name = $matches[1];

				// MySQL 4.1 outputs TYPE=, but accepts ENGINE=; 5.1 onwards accept *only* ENGINE=
				$sql_line = $updraftplus->str_lreplace('TYPE=', 'ENGINE=', $sql_line);

				if (!isset($printed_new_table_prefix)) {
					$import_table_prefix = $this->pre_sql_actions($import_table_prefix);
					if (false===$import_table_prefix || is_wp_error($import_table_prefix)) return $import_table_prefix;
					$printed_new_table_prefix = true;
				}

				$this->new_table_name = ($old_table_prefix) ? $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $this->table_name) : $this->table_name;

				// This CREATE TABLE command may be the de-facto mark for the end of processing a previous table (which is so if this is not the first table in the SQL dump)
				if ($restoring_table) {

					// After restoring the options table, we can set old_siteurl if on legacy (i.e. not already set)
					if ($restoring_table == $import_table_prefix.'options') {
						if ('' == $this->old_siteurl || '' == $this->old_home || '' == $this->old_content) {
							global $updraftplus_addons_migrator;
							if (isset($updraftplus_addons_migrator->new_blogid)) switch_to_blog($updraftplus_addons_migrator->new_blogid);

							if ('' == $this->old_siteurl) {
								$this->old_siteurl = untrailingslashit($wpdb->get_row("SELECT option_value FROM $wpdb->options WHERE option_name='siteurl'")->option_value);
								do_action('updraftplus_restore_db_record_old_siteurl', $this->old_siteurl);
							}
							if ('' == $this->old_home) {
								$this->old_home = untrailingslashit($wpdb->get_row("SELECT option_value FROM $wpdb->options WHERE option_name='home'")->option_value);
								do_action('updraftplus_restore_db_record_old_home', $this->old_home);
							}
							if ('' == $this->old_content) {
								$this->old_content = $this->old_siteurl.'/wp-content';
								do_action('updraftplus_restore_db_record_old_content', $this->old_content);
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
				$logline = "Restoring table ($engine): ".$this->table_name;
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) {
					$new_table_name = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $this->table_name);
					echo ' - '.__('will restore as:', 'updraftplus').' '.htmlspecialchars($new_table_name);
					$logline .= " - will restore as: ".$new_table_name;
					$sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				} else {
					$new_table_name = $this->table_name;
				}
				$updraftplus->log($logline);
				$restoring_table = $new_table_name;
				echo '<br>';
				if ($engine_change_message) echo $engine_change_message;

			} elseif (preg_match('/^\s*(insert into \`?([^\`]*)\`?\s+(values|\())/i', $sql_line, $matches)) {
				$sql_type = 3;
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) $sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
			} elseif (preg_match('/^\s*(\/\*\!40000 )?(alter|lock) tables? \`?([^\`\(]*)\`?\s+(write|disable|enable)/i', $sql_line, $matches)) {
				# Only binary mysqldump produces this pattern (LOCK TABLES `table` WRITE, ALTER TABLE `table` (DISABLE|ENABLE) KEYS)
				$sql_type = 4;
				if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) $sql_line = $updraftplus->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
			} elseif (preg_match('/^(un)?lock tables/i', $sql_line)) {
				# BackWPup produces these
				$sql_type = 5;
			}
// 			if (5 !== $sql_type) {
				$do_exec = $this->sql_exec($sql_line, $sql_type);
				if (is_wp_error($do_exec)) return $do_exec;
// 			}

			# Reset
			$sql_line = '';
			$sql_type = -1;

		}
		
		if ($restoring_table) $this->restored_table($restoring_table, $import_table_prefix, $old_table_prefix);

		$time_taken = microtime(true) - $this->start_time;
		$updraftplus->log_e('Finished: lines processed: %d in %.2f seconds', $this->line, $time_taken);
		if ($is_plain) {
			fclose($dbhandle);
		} else {
			gzclose($dbhandle);
		}

		global $wp_filesystem;

		$wp_filesystem->delete($working_dir.'/'.$db_basename, false, 'f');
		return true;

	}

	private function log_oversized_packet($sql_line) {
		global $updraftplus;
		$logit = substr($sql_line, 0, 100);
		$updraftplus->log(sprintf("An SQL line that is larger than the maximum packet size and cannot be split was found: %s", '('.strlen($sql_line).', '.$logit.' ...)'));
		echo '<strong>'.__('Warning:', 'updraftplus').'</strong> '.sprintf(__("An SQL line that is larger than the maximum packet size and cannot be split was found; this line will not be processed, but will be dropped: %s", 'updraftplus'), '('.strlen($sql_line).', '.$this->max_allowed_packet.', '.$logit.' ...)')."<br>";
	}

	# UPDATE is sql_type=5 (not used in the function, but used in Migrator and so noted here for reference)
	# $import_table_prefix is only use in one place in this function, and otherwise need/should not be supplied
	public function sql_exec($sql_line, $sql_type, $import_table_prefix = '') {

		global $wpdb, $updraftplus;
		$ignore_errors = false;
		if (2 == $sql_type && $this->create_forbidden) {
			$updraftplus->log_e('Cannot create new tables, so skipping this command (%s)', htmlspecialchars($sql_line));
			$req = true;
		} else {
			if (1 == $sql_type && $this->drop_forbidden) {
				$sql_line = "DELETE FROM ".$updraftplus->backquote($this->new_table_name);
				$updraftplus->log_e('Cannot drop tables, so deleting instead (%s)', $sql_line);
				$ignore_errors = true;
			}

			if (3 == $sql_type && $sql_line && strlen($sql_line) > $this->max_allowed_packet) {
				$this->log_oversized_packet($sql_line);
				# If this is the very first SQL line of the options table, we need to bail; it's essential
				$this->errors++;
				if (0 == $this->insert_statements_run && $this->new_table_name && $this->new_table_name == $import_table_prefix.'options') {
					return new WP_Error('initial_db_error', sprintf(__('An error occurred on the first %s command - aborting run','updraftplus'), 'INSERT (options)'));
				}
				return false;
			}

			if ($this->use_wpdb) {
				$req = $wpdb->query($sql_line);
				if (!$req) $this->last_error = $wpdb->last_error;
			} else {
				if ($this->use_mysqli) {
					$req = mysqli_query($this->mysql_dbh, $sql_line);
					if (!$req) $this->last_error = mysqli_error($this->mysql_dbh);
				} else {
					$req = mysql_unbuffered_query($sql_line, $this->mysql_dbh);
					if (!$req) $this->last_error = mysql_error($this->mysql_dbh);
				}
			}
			if (3 == $sql_type) $this->insert_statements_run++;
			$this->statements_run++;
		}

		if (!$req) {
			if (!$ignore_errors) $this->errors++;
			$print_err = (strlen($sql_line) > 100) ? substr($sql_line, 0, 100).' ...' : $sql_line;
			echo sprintf(_x('An error (%s) occurred:', 'The user is being told the number of times an error has happened, e.g. An error (27) occurred', 'updraftplus'), $this->errors)." - ".htmlspecialchars($this->last_error)." - ".__('the database query being run was:','updraftplus').' '.htmlspecialchars($print_err).'<br>';
			$updraftplus->log("An error (".$this->errors.") occurred: ".$this->last_error." - SQL query was: ".substr($sql_line, 0, 65536));
			// First command is expected to be DROP TABLE
			if (1 == $this->errors && 2 == $sql_type && 0 == $this->tables_created) {
				return new WP_Error('initial_db_error', sprintf(__('An error occurred on the first %s command - aborting run','updraftplus'), 'CREATE TABLE'));
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
				$updraftplus->log_e('Database queries processed: %d in %.2f seconds',$this->line, $time_taken);
			}
		}
		return $req;
	}

// 	function option_filter($which) {
// 		if (strpos($which, 'pre_option') !== false) { echo "OPT_FILT: $which<br>\n"; }
// 		return false;
// 	}

	function flush_rewrite_rules() {

		// We have to deal with the fact that the procedures used call get_option, which could be looking at the wrong table prefix, or have the wrong thing cached

		global $updraftplus_addons_migrator;
		if (!empty($updraftplus_addons_migrator->new_blogid)) switch_to_blog($updraftplus_addons_migrator->new_blogid);

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			add_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		global $wp_rewrite;
		$wp_rewrite->init();
		// Don't do this: it will cause rules created by plugins that weren't active at the start of the restore run to be lost
		# flush_rewrite_rules(true);

		if ( function_exists( 'save_mod_rewrite_rules' ) )
			save_mod_rewrite_rules();
		if ( function_exists( 'iis7_save_url_rewrite_rules' ) )
			iis7_save_url_rewrite_rules();

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			remove_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		if (!empty($updraftplus_addons_migrator->new_blogid)) restore_current_blog();

	}

	private function restored_table($table, $import_table_prefix, $old_table_prefix) {

		global $wpdb, $updraftplus;

		// WordPress has an option name predicated upon the table prefix. Yuk.
// 		if ($table == $import_table_prefix.'options') {
		if (preg_match('/^([\d+]_)?options$/', substr($table, strlen($import_table_prefix)), $matches)) {
			if (($this->is_multisite && !empty($matches[1])) || !$this->is_multisite && $table == $import_table_prefix.'options') {

				if ($import_table_prefix != $old_table_prefix) {
					$updraftplus->log("Table prefix has changed: changing options table field(s) accordingly (".$matches[1]."options)");
					echo sprintf(__('Table prefix has changed: changing %s table field(s) accordingly:', 'updraftplus'),'option').' ';
					if (false === $wpdb->query("UPDATE ${import_table_prefix}".$matches[1]."options SET option_name='${import_table_prefix}".$matches[1]."user_roles' WHERE option_name='${old_table_prefix}".$matches[1]."user_roles' LIMIT 1")) {
						echo __('Error','updraftplus');
						$updraftplus->log("Error when changing options table fields");
					} else {
						$updraftplus->log("Options table fields changed OK");
						echo __('OK', 'updraftplus');
					}
					echo '<br>';

					// Now deal with the situation where the imported database sets a new over-ride upload_path that is absolute - which may not be wanted
					$new_upload_path = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM ${import_table_prefix}".$matches[1]."options WHERE option_name = %s LIMIT 1", 'upload_path'));
					$new_upload_path = (is_object($new_upload_path)) ? $new_upload_path->option_value : '';
					// The danger situation is absolute and points somewhere that is now perhaps not accessible at all
					if (!empty($new_upload_path) && $new_upload_path != $this->prior_upload_path && strpos($new_upload_path, '/') === 0) {
						if (!file_exists($new_upload_path)) {
							$updraftplus->log_e("Uploads path (%s) does not exist - resetting (%s)", $new_upload_path, $this->prior_upload_path);
							if (false === $wpdb->query("UPDATE ${import_table_prefix}".$matches[1]."options SET option_value='".esc_sql($this->prior_upload_path)."' WHERE option_name='upload_path' LIMIT 1")) {
								echo __('Error','updraftplus');
								$updraftplus->log("Failed");
							}
							#update_option('upload_path', $this->prior_upload_path);
						}
					}
				}

				# TODO: Do on all WPMU tables
				if ($table == $import_table_prefix.'options') {
					# Bad plugin that hard-codes path references - https://wordpress.org/plugins/custom-content-type-manager/
					$cctm_data = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'cctm_data'));
					if (!empty($cctm_data->option_value)) {
						$cctm_data = maybe_unserialize($cctm_data->option_value);
						if (is_array($cctm_data) && !empty($cctm_data['cache']) && is_array($cctm_data['cache'])) {
							$cctm_data['cache'] = array();
							$updraftplus->log_e("Custom content type manager plugin data detected: clearing option cache");
							update_option('cctm_data', $cctm_data);
						}
					}
					# Another - http://www.elegantthemes.com/gallery/elegant-builder/
					$elegant_data = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'et_images_temp_folder'));
					if (!empty($elegant_data->option_value)) {
						$dbase = basename($elegant_data->option_value);
						$wp_upload_dir = wp_upload_dir();
						$edir = $wp_upload_dir['basedir'];
						if (!is_dir($edir.'/'.$dbase)) @mkdir($edir.'/'.$dbase);
						$updraftplus->log_e("Elegant themes theme builder plugin data detected: resetting temporary folder");
						update_option('et_images_temp_folder', $edir.'/'.$dbase);
					}
				}
			}

// 		} elseif (preg_match('/^([\d+]_)?usermeta/', substr($table, strlen($import_table_prefix)), $matches)) {
		} elseif ($table == $import_table_prefix.'usermeta') {

			# This table is not a per-site table, but per-install

			$updraftplus->log("Table prefix has changed: changing usermeta table field(s) accordingly");
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
				$updraftplus->log("Error when changing usermeta table fields");
				echo __('Error', 'updraftplus');
			} else {
				$updraftplus->log("Usermeta table fields changed OK");
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
	public function updraftplus_getdbh() {
		return $this->dbh;
	}
	public function updraftplus_use_mysqli() {
		return !empty($this->use_mysqli);
	}
}

// The purpose of this is that, in a certain case, we want to forbid the "move" operation from doing a copy/delete if a direct move fails... because we have our own method for retrying (and don't want to risk copying a tonne of data if we can avoid it)
if (!class_exists('WP_Filesystem_Direct')) require_once(ABSPATH.'wp-admin/includes/class-wp-filesystem-direct.php');
class UpdraftPlus_WP_Filesystem_Direct extends WP_Filesystem_Direct {

	function move($source, $destination, $overwrite = false) {
		if ( ! $overwrite && $this->exists($destination) )
			return false;

		// try using rename first. if that fails (for example, source is read only) try copy
		if ( @rename($source, $destination) )
			return true;

		return false;
	}

}

if (!class_exists('WP_Upgrader_Skin')) require_once(ABSPATH.'wp-admin/includes/class-wp-upgrader.php');
class Updraft_Restorer_Skin extends WP_Upgrader_Skin {

	function header() {}
	function footer() {}
	function bulk_header() {}
	function bulk_footer() {}

	function error($error) {
		if (!$error) return;
		global $updraftplus;
		if (is_wp_error($error)) {
			$updraftplus->log_wp_error($error, true);
		} elseif (is_string($error)) {
			echo '<strong>';
			$updraftplus->log_e($error);
			echo '</strong>';
		}
	}

	function feedback($string) {

		if ( isset( $this->upgrader->strings[$string] ) )
			$string = $this->upgrader->strings[$string];

		if ( strpos($string, '%') !== false ) {
			$args = func_get_args();
			$args = array_splice($args, 1);
			if ( $args ) {
				$args = array_map( 'strip_tags', $args );
				$args = array_map( 'esc_html', $args );
				$string = vsprintf($string, $args);
			}
		}
		if ( empty($string) ) return;

		global $updraftplus;
		$updraftplus->log_e($string);
	}
}
