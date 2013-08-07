<?php
class Updraft_Restorer extends WP_Upgrader {

	var $ud_backup_is_multisite = -1;

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
		$this->strings['moving_backup'] = __('Moving unpacked backup in place...','updraftplus');
		$this->strings['restore_database'] = __('Restoring the database (on a large site this can take a long time - if it times out (which can happen if your web hosting company has configured your hosting to limit resources) then you should use a different method, such as phpMyAdmin)...','updraftplus');
		$this->strings['cleaning_up'] = __('Cleaning up rubbish...','updraftplus');
		$this->strings['old_delete_failed'] = __('Could not move old directory out of the way. Perhaps you already have -old directories that need deleting first?','updraftplus');
		$this->strings['old_move_failed'] = __('Could not delete old directory.','updraftplus');
		$this->strings['new_move_failed'] = __('Could not move new directory into place. Check your wp-content/upgrade folder.','updraftplus');
		$this->strings['delete_failed'] = __('Failed to delete working directory after restoring.','updraftplus');
		$this->strings['multisite_error'] = __('You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus');
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
		# $working_dir_filesystem = WP_CONTENT_DIR.'/upgrade/'. basename($package, '.crypt');

		// Clean up working directory
		if ( $wp_filesystem->is_dir($working_dir) )
			$wp_filesystem->delete($working_dir, true);

		if (!$wp_filesystem->mkdir($working_dir, 0775)) return new WP_Error('mkdir_failed', __('Failed to create a temporary directory','updraftplus').' ('.$working_dir.')');

		// Unpack package to working directory
		if ($updraftplus->is_db_encrypted($package)) {
			$this->skin->feedback('decrypt_database');
			$encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
			if (!$encryption) return new WP_Error('no_encryption_key', __('Decryption failed. The database file is encrypted, but you have no encryption key entered.', 'updraftplus'));

			// Encrypted - decrypt it
			require_once(UPDRAFTPLUS_DIR.'/includes/phpseclib/Crypt/Rijndael.php');
			$rijndael = new Crypt_Rijndael();

			// Get decryption key
			$rijndael->setKey($encryption);
			$ciphertext = $rijndael->decrypt($wp_filesystem->get_contents($backup_dir.$package));
			if ($ciphertext) {
				$this->skin->feedback('decrypted_database');
				if (!$wp_filesystem->put_contents($working_dir.'/backup.db.gz', $ciphertext)) {
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
	// The only purpose of the $type parameter is 1) to detect 'others' and apply a historical bugfix 2) to detect wpcore, and apply the setting for what to do with wp-config.php
	// Must use only wp_filesystem
	// $dest_dir must already have a trailing slash
	// $preserve_existing: 0 = overwrite with no backup; 1 = make backup of existing; 2 = do nothing if there is existing
	function move_backup_in($working_dir, $dest_dir, $preserve_existing = 1, $do_not_overwrite = array('plugins', 'themes', 'uploads', 'upgrade'), $type = 'not-others', $send_actions = false) {

		global $wp_filesystem;

		$upgrade_files = $wp_filesystem->dirlist($working_dir);

		if ( !empty($upgrade_files) ) {
			foreach ( $upgrade_files as $filestruc ) {
				$file = $filestruc['name'];

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
				if (!in_array($file, $do_not_overwrite)) {
					# First, move the existing one, if necessary (may not be present)
					if ($wp_filesystem->exists($dest_dir.$file)) {
						if ($preserve_existing == 1) {
							if ( !$wp_filesystem->move($dest_dir.$file, $dest_dir.$file.'-old', true) ) {
								return new WP_Error('old_move_failed', $this->strings['old_move_failed']." (wp-content/$file)");
							}
						} elseif ($preserve_existing == 0) {
							if (!$wp_filesystem->delete($dest_dir.$file, true)) {
								return new WP_Error('old_delete_failed', $this->strings['old_delete_failed']." ($file)");
							}
// 							if ( !$wp_filesystem->move($dest_dir.$file, $working_dir.'/'.$file.'-old', true) ) {
// 								return new WP_Error('old_move_failed', $this->strings['old_move_failed']." (wp-content/$file)");
// 							}
						}
					}
					# Now, move in the new one
					if (2 == $preserve_existing && $wp_filesystem->exists($dest_dir.$file)) {
						# Remove it - so that we are clean later
						@$wp_filesystem->delete($working_dir.'/'.$file, true);
					} else {
						if ($wp_filesystem->move($working_dir . "/".$file, $dest_dir.$file, true) ) {
							if ($send_actions) do_action('updraftplus_restored_'.$type.'_one', $file);
						} else {
							return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
						}
					}
				}
			}
		}

		return true;

	}

	function str_replace_once($needle, $replace, $haystack) {
		$pos = strpos($haystack,$needle);
		return ($pos !== false) ? substr_replace($haystack,$replace,$pos,strlen($needle)) : $haystack;
	}

	// Pre-flight check: chance to complain and abort before anything at all is done
	function pre_restore_backup($backup_files, $type, $info) {
		if (is_string($backup_files)) $backup_files=array($backup_files);

		if ($type == 'more') {
			show_message($this->strings['not_possible']);
			return;
		}

		// Ensure access to the indicated directory - and to WP_CONTENT_DIR (in which we use upgrade/)
		$res = $this->fs_connect(array($info['path'], WP_CONTENT_DIR) );
		if (false === $res || is_wp_error($res)) return $res;

		$wp_filesystem_dir = $this->get_wp_filesystem_dir($info['path']);
		if ($wp_filesystem_dir === false) return false;


		global $updraftplus_addons_migrator, $wp_filesystem;
		if ('plugins' == $type || 'uploads' == $type || 'themes' == $type && (!is_multisite() || $this->ud_backup_is_multisite !== 0 || ('uploads' != $type || !isset($updraftplus_addons_migrator['new_blogid'] )))) {
			if ($wp_filesystem->exists($wp_filesystem_dir.'-old')) {
				return new WP_Error('already_exists', sprintf(__('An existing unremoved backup from a previous restore exists: %s', 'updraftplus'), $wp_filesystem_dir.'-old'));
			}
		}

		return true;
	}

	function get_wp_filesystem_dir($path) {
		global $wp_filesystem;
		// Get the wp_filesystem location for the folder on the local install
		switch ( $path ) {
			case ABSPATH:
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

	// TODO: This does not yet cope with multiple files (i.e. array) - it needs to then add on subsequent archives, not replace (only first should replace)
	// $backup_file is just the basename
	function restore_backup($backup_files, $type, $service, $info) {

		if (is_string($backup_files)) $backup_files=array($backup_files);

		// TODO
		$backup_file = $backup_files[0];

		if ($type == 'more') {
			show_message($this->strings['not_possible']);
			return;
		}

		global $wp_filesystem, $updraftplus_addons_migrator, $updraftplus;

		$wp_filesystem_dir = $this->get_wp_filesystem_dir($info['path']);
		if ($wp_filesystem_dir === false) return false;

		$wp_dir = trailingslashit($wp_filesystem->abspath());
		$wp_content_dir = trailingslashit($wp_filesystem->wp_content_dir());

		@set_time_limit(1800);

		$delete = (UpdraftPlus_Options::get_updraft_option('updraft_delete_local')) ? true : false;
		if ('none' == $service) {
			if ($delete) _e('Will not delete the archive after unpacking it, because there was no cloud storage for this backup','updraftplus').'<br>';
			$delete = false;
		}

		// This returns the wp_filesystem path
		$working_dir = $this->unpack_package($backup_file, $delete);

		if (is_wp_error($working_dir)) return $working_dir;
		$working_dir_filesystem = WP_CONTENT_DIR.'/upgrade/'.basename($working_dir);

		global $table_prefix;
		// We copy the variable because we may be importing with a different prefix (e.g. on multisite imports of individual blog data)
		$import_table_prefix = $table_prefix;

		@set_time_limit(1800);

		if ($type == 'others') {

			$dirname = basename($info['path']);

			// In this special case, the backup contents are not in a folder, so it is not simply a case of moving the folder around, but rather looping over all that we find

			$this->move_backup_in($working_dir, trailingslashit($wp_filesystem_dir), true, array('plugins', 'themes', 'uploads', 'upgrade'), 'others');

		} elseif (is_multisite() && $this->ud_backup_is_multisite === 0 && ( ( 'plugins' == $type || 'themes' == $type )  || ( 'uploads' == $type && isset($updraftplus_addons_migrator['new_blogid'])) ) && $wp_filesystem->is_dir($working_dir.'/'.$type)) {
			# Migrating a single site into a multisite
			if ('plugins' == $type || 'themes' == $type) {
				// Only move in entities that are not already there (2)
				$this->move_backup_in($working_dir.'/'.$type, trailingslashit($wp_filesystem_dir), 2, array(), $type, true);
				@$wp_filesystem->delete($working_dir.'/'.$type);
			} else {
				// Uploads

				show_message($this->strings['moving_old']);

				switch_to_blog($updraftplus_addons_migrator['new_blogid']);

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

					if ( !$wp_filesystem->move($working_dir . "/".$type, $fsud, true) ) {
						return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
					}
/*
					$this->move_backup_in($working_dir.'/'.$type, $wp_content_dir.$type.'/', 1, array(), $type);
					@$wp_filesystem->delete($working_dir.'/'.$type);*/
				} else {
					return new WP_Error('move_failed', $this->strings['new_move_failed']);
				}

			}
		} elseif ('db' == $type) {

			do_action('updraftplus_restore_db_pre');

			// There is a file backup.db.gz inside the working directory

			# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
			if (@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") {
				echo "<p>".__('Warning: PHP safe_mode is active on your server. Timeouts are much more likely. If these happen, then you will need to manually restore the file via phpMyAdmin or another method.', 'updraftplus')."</p><br/>";
				return false;
			}

			// wp_filesystem has no gzopen method, so we switch to using the local filesystem (which is harmless, since we are performing read-only operations)
			if (!is_readable($working_dir_filesystem.'/backup.db.gz')) return new WP_Error('gzopen_failed',__('Failed to find database file','updraftplus')." ($working_dir/backup.db.gz)");

			$this->skin->feedback('restore_database');

			// Read-only access: don't need to go through WP_Filesystem
			$dbhandle = gzopen($working_dir_filesystem.'/backup.db.gz', 'r');
			if (!$dbhandle) return new WP_Error('gzopen_failed',__('Failed to open database file','updraftplus'));

			$line = 0;

			global $wpdb;

			// Line up a wpdb-like object to use
			// mysql_query will throw E_DEPRECATED from PHP 5.5, so we expect WordPress to have switched to something else by then
// 			$use_wpdb = (version_compare(phpversion(), '5.5', '>=') || !function_exists('mysql_query') || !$wpdb->is_mysql || !$wpdb->ready) ? true : false;
			// Seems not - PHP 5.5 is immanent for release
			$use_wpdb = (!function_exists('mysql_query') || !$wpdb->is_mysql || !$wpdb->ready) ? true : false;

			if (false == $use_wpdb) {
				// We have our own extension which drops lots of the overhead on the query
				$wpdb_obj = new UpdraftPlus_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
				// Was that successful?
				if (!$wpdb_obj->is_mysql || !$wpdb_obj->ready) {
					$use_wpdb = true;
				} else {
					$mysql_dbh = $wpdb_obj->updraftplus_getdbh();
				}
			}

			if (true == $use_wpdb) {
				_e('Database access: Direct MySQL access is not available, so we are falling back to wpdb (this will be considerably slower)','updraftplus');
			} else {
				@mysql_query('SET SESSION query_cache_type = OFF;', $mysql_dbh );
			}

			// Find the supported engines - in case the dump had something else (case seen: saved from MariaDB with engine Aria; imported into plain MySQL without)
			$supported_engines = $wpdb->get_results("SHOW ENGINES", OBJECT_K);

			$errors = 0;
			$statements_run = 0;
			$tables_created = 0;

			$sql_line = "";
			$sql_type = -1;

			$start_time = microtime(true);

			// TODO: Print a warning if restoring to a different WP version
			$old_wpversion = '';
			$old_siteurl = '';
			$old_table_prefix = '';
			$old_siteinfo = array();
			$gathering_siteinfo = true;

			$create_forbidden = false;
			$drop_forbidden = false;
			$last_error = '';
			$random_table_name = 'updraft_tmp_'.rand(0,9999999).md5(microtime(true));
			if ($use_wpdb) {
				$req = $wpdb->query("CREATE TABLE $random_table_name");
				if (!$req) $last_error = $wpdb->last_error;
				$last_error_no = false;
			} else {
				$req = mysql_unbuffered_query("CREATE TABLE $random_table_name", $mysql_dbh );
				if (!$req) {
					$last_error = mysql_error($mysql_dbh);
					$last_error_no = mysql_errno($mysql_dbh);
				}
			}

			if (!$req && ($use_wpdb || $last_error_no === 1142)) {
				$create_forbidden = true;
				# If we can't create, then there's no point dropping
				$drop_forbidden = true;
				echo '<strong>'.__('Warning:','updraftplus').'</strong> '.__('Your database user does not have permission to create tables. We will attempt to restore by simply emptying the tables; this should work as long as a) you are restoring from a WordPress version with the same database structure, and b) Your imported database does not contain any tables which are not already present on the importing site.', 'updraftplus').' ('.$last_error.')'."<br>";
			} else {
				if ($use_wpdb) {
					$req = $wpdb->query("DROP TABLE $random_table_name");
					if (!$req) $last_error = $wpdb->last_error;
					$last_error_no = false;
				} else {
					$req = mysql_unbuffered_query("DROP TABLE $random_table_name", $mysql_dbh );
					if (!$req) {
						$last_error = mysql_error($mysql_dbh);
						$last_error_no = mysql_errno($mysql_dbh);
					}
				}
				if (!$req && ($use_wpdb || $last_error_no === 1142)) {
					$drop_forbidden = true;
					echo '<strong>'.__('Warning:','updraftplus').'</strong> '.__('Your database user does not have permission to drop tables. We will attempt to restore by simply emptying the tables; this should work as long as you are restoring from a WordPress version with the same database structure','updraftplus').' ('.$last_error.')'."<br>";
				}
			}

			$restoring_table = '';
			
			while (!gzeof($dbhandle)) {
				// Up to 1Mb
				$buffer = rtrim(gzgets($dbhandle, 1048576));
				// Discard comments
				if (empty($buffer) || substr($buffer, 0, 1) == '#') {
					if ('' == $old_siteurl && preg_match('/^\# Backup of: (http(.*))$/', $buffer, $matches)) {
						$old_siteurl = $matches[1];
						echo '<strong>'.__('Backup of:', 'updraftplus').'</strong> '.htmlspecialchars($old_siteurl).'<br>';
						do_action('updraftplus_restore_db_record_old_siteurl', $old_siteurl);
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
				
				$sql_line .= $buffer;
				
				# Do we have a complete line yet?
				if (';' != substr($sql_line, -1, 1)) continue;

				$line++;

				# The timed overhead of this is negligible
				if (preg_match('/^\s*drop table if exists \`?([^\`]*)\`?\s*;/i', $sql_line, $matches)) {

					$sql_type = 1;

					if (!isset($printed_new_table_prefix)) {
						$import_table_prefix = $this->pre_sql_actions($import_table_prefix);
						if (false===$import_table_prefix || is_wp_error($import_table_prefix)) return $import_table_prefix;
						$printed_new_table_prefix = true;
					}

					$table_name = $matches[1];
					// Legacy, less reliable - in case it was not caught before
					if ($old_table_prefix == '' && preg_match('/^([a-z0-9]+)_.*$/i', $table_name, $tmatches)) {
						$old_table_prefix = $tmatches[1].'_';
						echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
					}
					if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) {
						$sql_line = $this->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
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

						$this->restored_table($restoring_table, $import_table_prefix, $old_table_prefix);
						
						// After restoring the options table, we can set old_siteurl if on legacy (i.e. not already set)
						if ($restoring_table == $import_table_prefix.'options') {

							if ('' == $old_siteurl) {
								global $updraftplus_addons_migrator;
								if (isset($updraftplus_addons_migrator['new_blogid'])) switch_to_blog($updraftplus_addons_migrator['new_blogid']);

								$old_siteurl = $wpdb->get_row("SELECT option_value FROM $wpdb->options WHERE option_name='siteurl'")->option_value;
								do_action('updraftplus_restore_db_record_old_siteurl', $old_siteurl);
								
								if (isset($updraftplus_addons_migrator['new_blogid'])) restore_current_blog();
							}

						}

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

					$table_name = $matches[1];
					echo '<strong>'.sprintf(__('Restoring table (%s)','updraftplus'), $engine).":</strong> ".htmlspecialchars($table_name);
					if ('' != $old_table_prefix && $import_table_prefix != $old_table_prefix) {
						$new_table_name = $this->str_replace_once($old_table_prefix, $import_table_prefix, $table_name);
						echo ' - '.__('will restore as:', 'updraftplus').' '.htmlspecialchars($new_table_name);
						$sql_line = $this->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
					} else {
						$new_table_name = $table_name;
					}
					$restoring_table = $new_table_name;
					echo '<br>';
					if ($engine_change_message) echo $engine_change_message;

				} elseif ('' != $old_table_prefix && preg_match('/^\s*insert into \`?([^\`]*)\`?\s+values/i', $sql_line, $matches)) {
					$sql_type = 3;
					if ($import_table_prefix != $old_table_prefix) $sql_line = $this->str_replace_once($old_table_prefix, $import_table_prefix, $sql_line);
				}

				if ($sql_type == 2 && $create_forbidden) {
					echo sprintf(__('Cannot create new tables, so skipping this command (%s)', 'updraftplus'),htmlspecialchars("CREATE TABLE $table_name"))."<br>";
					$req = true;
				} else {
					if ($sql_type == 1 && $drop_forbidden) {
						$sql_line = "DELETE FROM ".$updraftplus->backquote($table_name);
						echo sprintf(__('Cannot drop tables, so deleting instead (%s)', 'updraftplus'), $sql_line)."<br>";
					}
					if ($use_wpdb) {
						$req = $wpdb->query($sql_line);
						if (!$req) $last_error = $wpdb->last_error;
					} else {
						$req = mysql_unbuffered_query( $sql_line, $mysql_dbh );
						if (!$req) $last_error = mysql_error($mysql_dbh);
					}
					$statements_run++;
				}

				if (!$req) {
					$errors++;
					echo sprintf(_x('An error (%s) occured:', 'The user is being told the number of times an error has happened, e.g. An error (27) occurred', 'updraftplus'), $errors)." - ".htmlspecialchars($last_error)." - ".__('the database query being run was:','updraftplus').' '.htmlspecialchars($sql_line).'<br>';
					// First command is expected to be DROP TABLE
					if (1 == $errors && 2 == $sql_type && 0 == $tables_created) {
						return new WP_Error('initial_db_error', __('An error occured on the first CREATE TABLE command - aborting run','updraftplus'));
					}
					if ($errors>49) {
						return new WP_Error('too_many_db_errors', __('Too many database errors have occurred - aborting restoration (you will need to restore manually)','updraftplus'));
					}
				} elseif ($sql_type == 2) {
					$tables_created++;
				}
				if ($line%50 == 0) {
					if ($line%250 == 0 || $line<250) {
						$time_taken = microtime(true) - $start_time;
						echo sprintf(__('Database lines processed: %d in %.2f seconds','updraftplus'),$line, $time_taken)."<br>";
					}
				}

				# Reset
				$sql_line = '';
				$sql_type = -1;

			}
			
			if ($restoring_table) $this->restored_table($restoring_table, $import_table_prefix, $old_table_prefix);

			$time_taken = microtime(true) - $start_time;
			echo sprintf(__('Finished: lines processed: %d in %.2f seconds','updraftplus'),$line, $time_taken)."<br>";
			gzclose($dbhandle);
			$wp_filesystem->delete($working_dir.'/backup.db.gz', false, true);

		} else {

			// Default action: used for plugins, themes and uploads (and wpcore, via a filter)

			$dirname = basename($info['path']);

			show_message($this->strings['moving_old']);

			$movedin = apply_filters('updraftplus_restore_movein_'.$type, $working_dir, $wp_dir, $wp_filesystem_dir);
			// A filter, to allow add-ons to perform the install of non-standard entities, or to indicate that it's not possible
			if (false === $movedin) {
				show_message($this->strings['not_possible']);
			} elseif ($movedin !== true) {
				if ($wp_filesystem->exists($wp_filesystem_dir."-old")) {
					// Is better to warn and delete the backup than abort mid-restore and leave inconsistent site
					echo $wp_filesystem_dir."-old: ".__('This directory already exists, and will be replaced', 'updraftplus').'<br>';
					# In theory, supply true as the 3rd parameter of true achieves this; in practice, not always so (leads to support requests)
					$wp_filesystem->delete($wp_filesystem_dir."-old", true);
				}
				if ( !$wp_filesystem->move($wp_filesystem_dir, $wp_filesystem_dir."-old", false) ) {
					return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
				}

				// The backup may not actually have /$dirname, since that is info from the present site
				$dirlist = $wp_filesystem->dirlist($working_dir, true, true);
				if (is_array($dirlist)) {
					$move_from = false;
					foreach ($dirlist as $name => $struc) {
						if (false === $move_from) {
							if ($name == $dirname) {
								$move_from = $working_dir . "/$dirname";
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
					$move_from = $working_dir . "/$dirname";
				}

				show_message($this->strings['moving_backup']);
				if ((isset($move_from) && false === $move_from) || !$wp_filesystem->move($move_from, $wp_filesystem_dir, true) ) {
					return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
				}
			}
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
				do_action('updraftplus_restored_db', array('expected_oldsiteurl' => $old_siteurl), $import_table_prefix);
				$this->flush_rewrite_rules();
			break;
			default:
				@$wp_filesystem->chmod($wp_filesystem_dir, FS_CHMOD_DIR);
		}

		return true;

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

// 	function option_filter($which) {
// 		if (strpos($which, 'pre_option') !== false) { echo "OPT_FILT: $which<br>\n"; }
// 		return false;
// 	}

	function flush_rewrite_rules() {

		// We have to deal with the fact that the procedures used call get_option, which could be looking at the wrong table prefix, or have the wrong thing cached

// 		add_filter('all', array($this, 'option_filter'));

		global $updraftplus_addons_migrator;
		if (isset($updraftplus_addons_migrator['new_blogid'])) switch_to_blog($updraftplus_addons_migrator['new_blogid']);

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			add_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		global $wp_rewrite;
		$wp_rewrite->init();
		flush_rewrite_rules(true);

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			remove_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		if (isset($updraftplus_addons_migrator['new_blogid'])) restore_current_blog();

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

				if (false === $wpdb->query($query)) {
					$errors_occurred = true;
				}
			
			}
			if ($errors_occurred) {
				echo __('Error', 'updraftplus');
			} else {
				echo __('OK', 'updraftplus');
			}
			echo "<br>";

		}

		do_action('updraftplus_restored_db_table', $table);

		// Re-generate permalinks. Do this last - i.e. make sure everything else is fixed up first.
		if ($table == $import_table_prefix.'options') {
			$this->flush_rewrite_rules();
		}

	}

}

// Get a protected property
class UpdraftPlus_WPDB extends wpdb {

	function updraftplus_getdbh() {
		return $this->dbh;
	}

}
?>