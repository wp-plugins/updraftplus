<?php

// Files can easily get way too big for this method

function updraftplus_email_backup($backup_array) {

	global $updraftplus;

	foreach ($backup_array as $type => $file) {
		$fullpath = trailingslashit(get_option('updraft_dir')).$file;
		wp_mail(get_option('updraft_email'), "WordPress Backup ".date('Y-m-d H:i',$updraftplus->backup_time), "Backup is of the $type.  Be wary; email backups may fail because of file size limitations on mail servers.", null, array($fullpath));
		$updraftplus->uploaded_file($file);
	}

	$updraftplus->prune_retained_backups("local");
}

?>