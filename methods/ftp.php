<?php

class UpdraftPlus_BackupModule_ftp {

	function backup($backup_array) {

		global $updraftplus;

		if( !class_exists('ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

		//handle SSL and errors at some point TODO
		$ftp = new ftp_wrapper(get_option('updraft_server_address'), get_option('updraft_ftp_login'), get_option('updraft_ftp_pass'));
		$ftp->passive = true;
		$ftp->connect();
		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit(get_option('updraft_ftp_remote_path'));
		foreach($backup_array as $file) {
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			if ($ftp->put($fullpath,$ftp_remote_path.$file,FTP_BINARY)) {
				$updraftplus->log("ERROR: $file_name: Successfully uploaded via FTP");
				$updraftplus->uploaded_file($file);
			} else {
				$updraftplus->error("$file_name: Failed to upload to FTP" );
				$updraftplus->log("ERROR: $file_name: Failed to upload to FTP" );
			}
		}

		$updraftplus->prune_retained_backups("ftp", $this, array('ftp_object' => $ftp, 'ftp_remote_path' => $ftp_remote_path));
	}

	function delete($file, $ftparr) {
		$ftp = $ftparr['ftp_object'];
		$ftp_remote_path = $ftparr['ftp_remote_path'];
		@$ftp->delete($ftp_remote_path.$file);
	}

	function download($file) {
		if( !class_exists('ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

		//handle SSL and errors at some point TODO
		$ftp = new ftp_wrapper(get_option('updraft_server_address'),get_option('updraft_ftp_login'),get_option('updraft_ftp_pass'));
		$ftp->passive = true;
		$ftp->connect();
		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit(get_option('updraft_ftp_remote_path'));
		$fullpath = trailingslashit(get_option('updraft_dir')).$file;
		$ftp->get($fullpath,$ftp_remote_path.$file,FTP_BINARY);
	}

	function config_print() {
		?>
		<tr class="updraftplusmethod ftp">
			<th>FTP Server:</th>
			<td><input type="text" style="width:260px" name="updraft_server_address" value="<?php echo htmlspecialchars(get_option('updraft_server_address')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th>FTP Login:</th>
			<td><input type="text" autocomplete="off" name="updraft_ftp_login" value="<?php echo htmlspecialchars(get_option('updraft_ftp_login')) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th>FTP Password:</th>
			<td><input type="text" autocomplete="off" style="width:260px" name="updraft_ftp_pass" value="<?php echo htmlspecialchars(get_option('updraft_ftp_pass')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th>Remote Path:</th>
			<td><input type="text" style="width:260px" name="updraft_ftp_remote_path" value="<?php echo htmlspecialchars(get_option('updraft_ftp_remote_path')); ?>" /></td>
		</tr>
		<?php
	}

	function file_delete() {
		$this->log("$backup_datestamp: Delete remote ftp: $remote_path/$dofile");
		@$remote_object->delete($remote_path.$dofile);
	}

}

?>