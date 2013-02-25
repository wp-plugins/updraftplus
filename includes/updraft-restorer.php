<?php
class Updraft_Restorer extends WP_Upgrader {

	function backup_strings() {
		$this->strings['no_package'] = __('Backup file not available.');
		$this->strings['unpack_package'] = __('Unpacking backup...');
		$this->strings['moving_old'] = __('Moving old directory out of the way...');
		$this->strings['moving_backup'] = __('Moving unpackaged backup in place...');
		$this->strings['cleaning_up'] = __('Cleaning up detritus...');
		$this->strings['old_move_failed'] = __('Could not move old dir out of the way.');
		$this->strings['new_move_failed'] = __('Could not move new dir into place. Check your wp-content/upgrade folder.');
		$this->strings['delete_failed'] = __('Failed to delete working directory after restoring.');
	}

	function restore_backup($backup_file, $type) {

		// Various keys can get stored in the data - but only some represent actual data entities
		if ($type != 'plugins' && $type != 'themes' && $type != 'others' && $type != 'uploads') continue;

		global $wp_filesystem;
		$this->init();
		$this->backup_strings();

		$res = $this->fs_connect(array(ABSPATH, WP_CONTENT_DIR) );
		if(!$res) exit;

		$wp_dir = trailingslashit($wp_filesystem->abspath());

		$download = $this->download_package( $backup_file );
		if ( is_wp_error($download) )
			return $download;
		
		$delete = (UpdraftPlus_Options::get_updraft_option('updraft_delete_local')) ? true : false;

		$working_dir = $this->unpack_package($download , $delete);
		if (is_wp_error($working_dir)) return $working_dir;
		
		if ($type == 'others' ) {

			// In this special case, the backup contents are not in a folder, so it is not simply a case of moving the folder around, but rather looping over all that we find

			$upgrade_files = $wp_filesystem->dirlist($working_dir);
			if ( !empty($upgrade_files) ) {
				foreach ( $upgrade_files as $filestruc ) {
					$file = $filestruc['name'];
					# Sanity check (should not be possible as these were excluded at backup time)
					if ($file != "plugins" && $file != "themes" && $file != "uploads" && $file != "upgrade") {
						# First, move the existing one, if necessary (may not be present)
						if ($wp_filesystem->exists($wp_dir . "wp-content/$file")) {
							if ( !$wp_filesystem->move($wp_dir . "wp-content/$file", $wp_dir . "wp-content/$file-old", true) ) {
								return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
							}
						}
						# Now, move in the new one
						if ( !$wp_filesystem->move($working_dir . "/$file", $wp_dir . "wp-content/$file", true) ) {
							return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
						}
					}
				}
			}

		} else {
		
			show_message($this->strings['moving_old']);
			if ( !$wp_filesystem->move($wp_dir . "wp-content/$type", $wp_dir . "wp-content/$type-old", true) ) {
				return new WP_Error('old_move_failed', $this->strings['old_move_failed']);
			}

			show_message($this->strings['moving_backup']);
			if ( !$wp_filesystem->move($working_dir . "/$type", $wp_dir . "wp-content/$type", true) ) {
				return new WP_Error('new_move_failed', $this->strings['new_move_failed']);
			}
			
		}

		show_message($this->strings['cleaning_up']);
		if ( !$wp_filesystem->delete($working_dir) ) {
			return new WP_Error('delete_failed', $this->strings['delete_failed']);
		}

		
		switch($type) {
			case 'uploads':
				@$wp_filesystem->chmod($wp_dir . "wp-content/$type", 0777, true);
			break;
			default:
				@$wp_filesystem->chmod($wp_dir . "wp-content/$type", FS_CHMOD_DIR);
		}
	}

}
?>