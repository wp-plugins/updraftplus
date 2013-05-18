<?php
class Updraft_Restorer extends WP_Upgrader {

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
		if (preg_match('/\.crypt$/i', $package)) {
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
	function move_backup_in($working_dir, $dest_dir, $make_backup = true, $do_not_overwrite = array('plugins', 'themes', 'uploads', 'upgrade'), $type = 'not-others') {

		global $wp_filesystem;

		$upgrade_files = $wp_filesystem->dirlist($working_dir);

		if ( !empty($upgrade_files) ) {
			foreach ( $upgrade_files as $filestruc ) {
				$file = $filestruc['name'];

				// Correctly restore files in 'others' in no directory that were wrongly backed up in versions 1.4.0 - 1.4.48
				if ('others' == $type && preg_match('/^([\-_A-Za-z0-9]+\.php)$/', $file, $matches) && $wp_filesystem->exists($working_dir . "/$file/$file")) {
					echo "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions 1.4.0 - 1.4.48); will rename to simply $file<br>";
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
						if ($make_backup) {
							if ( !$wp_filesystem->move($dest_dir.$file, $dest_dir.$file.'-old', true) ) {
								return new WP_Error('old_move_failed', $this->strings['old_move_failed']." (wp-content/$file)");
							}
						} else {
							if (!$wp_filesystem->delete($dest_dir.$file, true)) {
								return new WP_Error('old_delete_failed', $this->strings['old_delete_failed']." ($file)");
							}
// 							if ( !$wp_filesystem->move($dest_dir.$file, $working_dir.'/'.$file.'-old', true) ) {
// 								return new WP_Error('old_move_failed', $this->strings['old_move_failed']." (wp-content/$file)");
// 							}
						}
					}
					# Now, move in the new one
					if ( !$wp_filesystem->move($working_dir . "/".$file, $dest_dir.$file, true) ) {
						return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
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

	// $backup_file is just the basename
	function restore_backup($backup_file, $type, $service, $info) {

		if ($type == 'more') {
			show_message($this->strings['not_possible']);
			return;
		}

		global $wp_filesystem;
		$this->init();
		$this->backup_strings();

		$res = $this->fs_connect(array(ABSPATH, WP_CONTENT_DIR) );
		if(!$res) exit;

		$wp_dir = trailingslashit($wp_filesystem->abspath());

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

		@set_time_limit(1800);

		if ($type == 'others' ) {

			$dirname = basename($info['path']);

			// In this special case, the backup contents are not in a folder, so it is not simply a case of moving the folder around, but rather looping over all that we find

			$this->move_backup_in($working_dir, $wp_dir."wp-content/", true, array('plugins', 'themes', 'uploads', 'upgrade'), 'others');

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
				$wpdb_obj = new UpdraftPlus_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
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
				@mysql_query( 'SET SESSION query_cache_type = OFF;', $mysql_dbh );
			}

			$errors = 0;

			$sql_line = "";

			$start_time = microtime(true);

			// TODO: Print a warning if restoring to a different WP version
			$old_wpversion = '';
			$old_siteurl = '';
			$old_table_prefix = '';
			global $table_prefix;

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
					}
					continue;
				}
				
				$sql_line .= $buffer;
				
				# Do we have a complete line yet?
				if (';' != substr($sql_line, -1, 1)) continue;

				$line++;

				# The timed overhead of this is negligible
				if (preg_match('/^\s*drop table if exists \`?([^\`]*)\`?\s*;/i', $sql_line, $matches)) {
					$table_name = $matches[1];
					// Legacy, less reliable - in case it was not caught before
					if ($old_table_prefix == '' && preg_match('/^([a-z0-9]+)_.*$/i', $table_name, $tmatches)) {
						$old_table_prefix = $tmatches[1].'_';
						echo '<strong>'.__('Old table prefix:', 'updraftplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
					}
					if ('' != $old_table_prefix && $table_prefix != $old_table_prefix) {
						$sql_line = $this->str_replace_once($old_table_prefix, $table_prefix, $sql_line);
					}
				} elseif (preg_match('/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line, $matches)) {

					if ($restoring_table) {

						$this->restored_table($restoring_table, $table_prefix, $old_table_prefix);
						
						// After restoring the options table, we can set old_siteurl if on legacy (i.e. not already set)
						if ($restoring_table == $table_prefix.'options') {

							if ('' == $old_siteurl) {
								$old_siteurl = $wpdb->get_row("SELECT option_value FROM $wpdb->options WHERE option_name='siteurl'")->option_value;
							do_action('updraftplus_restore_db_record_old_siteurl', $old_siteurl);
							}

						}

					}

					$table_name = $matches[1];
					echo '<strong>'.__('Restoring table','updraftplus').":</strong> ".htmlspecialchars($table_name);
					if ('' != $old_table_prefix && $table_prefix != $old_table_prefix) {
						$new_table_name = $this->str_replace_once($old_table_prefix, $table_prefix, $table_name);
						echo ' - '.__('will restore as:', 'updraftplus').' '.htmlspecialchars($new_table_name);
						$sql_line = $this->str_replace_once($old_table_prefix, $table_prefix, $sql_line);
					} else {
						$new_table_name = $table_name;
					}
					$restoring_table = $new_table_name;
					echo '<br>';
				} elseif ('' != $old_table_prefix && preg_match('/^\s*insert into \`?([^\`]*)\`?\s+values/i', $sql_line, $matches)) {
					if ($table_prefix != $old_table_prefix) $sql_line = $this->str_replace_once($old_table_prefix, $table_prefix, $sql_line);
				}

				if ($use_wpdb) {
					$req = $wpdb->query($sql_line);
					if (!$req) $last_error = $wpdb->last_error;
				} else {
					$req = mysql_unbuffered_query( $sql_line, $mysql_dbh );
					if (!$req) $last_error = mysql_error($mysql_dbh);
				}
				
				if (!$req) {
					echo sprintf(_x('An error (%s) occured:', 'The user is being told the number of times an error has happened, e.g. An error (27) occurred', 'updraftplus'), $errors)." - ".htmlspecialchars($last_error)." - ".__('the database query being run was:','updraftplus').' '.htmlspecialchars($sql_line).'<br>';
					$errors++;
					if ($errors>49) {
						return new WP_Error('too_many_db_errors', __('Too many database errors have occurred - aborting restoration (you will need to restore manually)','updraftplus'));
					}
				}

				if ($line%50 == 0) {
					if ($line%250 == 0 || $line<250) {
						$time_taken = microtime(true) - $start_time;
						echo sprintf(__('Database lines processed: %d in %.2f seconds','updraftplus'),$line, $time_taken)."<br>";
					}
				}

				# Reset
				$sql_line = '';

			}
			
			if ($restoring_table) $this->restored_table($restoring_table, $table_prefix, $old_table_prefix);

			$time_taken = microtime(true) - $start_time;
			echo sprintf(__('Finished: lines processed: %d in %.2f seconds','updraftplus'),$line, $time_taken)."<br>";
			gzclose($dbhandle);
			$wp_filesystem->delete($working_dir.'/backup.db.gz', false, true);

		} else {

			$dirname = basename($info['path']);

			show_message($this->strings['moving_old']);

			$movedin = apply_filters('updraftplus_restore_movein_'.$type, $working_dir, $wp_dir);
			// A filter, to allow add-ons to perform the install of non-standard entities, or to indicate that it's not possible
			if ($movedin === false) {
				show_message($this->strings['not_possible']);
			} elseif ($movedin !== true) {
				if ( !$wp_filesystem->move($wp_dir . "wp-content/$dirname", $wp_dir . "wp-content/$dirname-old", true) ) {
					return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
				}

				show_message($this->strings['moving_backup']);
				if ( !$wp_filesystem->move($working_dir . "/$dirname", $wp_dir . "wp-content/$dirname", true) ) {
					return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
				}
			}
		}

		// Non-recursive, so the directory needs to be empty
		show_message($this->strings['cleaning_up']);
		if (!$wp_filesystem->delete($working_dir) ) {
			return new WP_Error('delete_failed', $this->strings['delete_failed'].' ('.$working_dir.')');
		}

		switch($type) {
			case 'wpcore':
				@$wp_filesystem->chmod($wp_dir, FS_CHMOD_DIR);
				// In case we restored a .htaccess which is incorrect for the local setup
				$this->flush_rewrite_rules();
			break;
			case 'uploads':
				@$wp_filesystem->chmod($wp_dir . "wp-content/$dirname", 0775, true);
			break;
			case 'db':
				do_action('updraftplus_restored_db', array('expected_oldsiteurl' => $old_siteurl));
				$this->flush_rewrite_rules();
			break;
			default:
				@$wp_filesystem->chmod($wp_dir . "wp-content/$dirname", FS_CHMOD_DIR);
		}
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

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			add_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

		global $wp_rewrite;
		$wp_rewrite->init();
		flush_rewrite_rules(true);

		foreach (array('permalink_structure', 'rewrite_rules', 'page_on_front') as $opt) {
			add_filter('pre_option_'.$opt, array($this, 'option_filter_'.$opt));
		}

// 		remove_filter('all', array($this, 'option_filter'));

	}

	function restored_table($table, $table_prefix, $old_table_prefix) {

		global $wpdb;

		// WordPress has an option name predicated upon the table prefix. Yuk.
		if ($table == $table_prefix.'options' && $table_prefix != $old_table_prefix) {
			echo sprintf(__('Table prefix has changed: changing %s table field(s) accordingly:', 'updraftplus'),'option').' ';
			if (false === $wpdb->query("UPDATE $wpdb->options SET option_name='${table_prefix}user_roles' WHERE option_name='${old_table_prefix}user_roles' LIMIT 1")) {
				echo __('Error','updraftplus');
			} else {
				echo __('OK', 'updraftplus');
			}
			echo '<br>';
		} elseif ($table == $table_prefix.'usermeta') {

			echo sprintf(__('Table prefix has changed: changing %s table field(s) accordingly:', 'updraftplus'),'usermeta').' ';

			global $table_prefix;

			$meta_keys = $wpdb->get_results("SELECT umeta_id, meta_key 
				FROM ${table_prefix}usermeta 
				WHERE meta_key 
				LIKE '".str_replace('_', '\_', $old_table_prefix)."%'");
			
			$old_prefix_length = strlen($old_table_prefix);

			$errors_occurred = false;
			foreach ($meta_keys as $meta_key ) {
				
				//Create new meta key
				$new_meta_key = $table_prefix . substr($meta_key->meta_key, $old_prefix_length);
				
				$query = "UPDATE " . $table_prefix . "usermeta 
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
		if ($table == $table_prefix.'options') {
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