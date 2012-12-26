<?php

function updraftplus_ftp_backup($backup_array) {

	global $updraftplus;

	if( !class_exists('ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

	//handle SSL and errors at some point TODO
	$ftp = new ftp_wrapper(get_option('updraft_server_address'),get_option('updraft_ftp_login'),get_option('updraft_ftp_pass'));
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

	$updraftplus->prune_retained_backups("ftp",$ftp,$ftp_remote_path);
}

function updraftplus_download_ftp_backup($file) {
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

?>