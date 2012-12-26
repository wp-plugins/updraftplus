<?php

// Not cleanly separated - much of the Google Drive code is still embedded in the main updraftplus.php

// This function just does the formalities, and off-loads the main work to googledrive_upload_file
function updraftplus_googledrive_backup($backup_array) {

	global $updraftplus;

	if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');

	// Do we have an access token?
	if ( !$access_token = $updraftplus->access_token( get_option('updraft_googledrive_token'), get_option('updraft_googledrive_clientid'), get_option('updraft_googledrive_secret') )) {
		$updraftplus->log('ERROR: Have not yet obtained an access token from Google (has the user authorised?)');
		return new WP_Error( "no_access_token", "Have not yet obtained an access token from Google (has the user authorised?");
	}

	$updraftplus->gdocs_access_token = $access_token;

	foreach ($backup_array as $file) {
		$file_path = trailingslashit(get_option('updraft_dir')).$file;
		$file_name = basename($file_path);
		$updraftplus->log("$file_name: Attempting to upload to Google Drive");
		$timer_start = microtime(true);
		if ( $id = updraftplus_googledrive_upload_file( $file_path, $file_name, get_option('updraft_googledrive_remotepath')) ) {
			$updraftplus->log('OK: Archive ' . $file_name . ' uploaded to Google Drive in ' . ( round(microtime( true ) - $timer_start,2) ) . ' seconds (id: '.$id.')' );
			$updraftplus->uploaded_file($file, $id);
		} else {
			$updraftplus->error("$file_name: Failed to upload to Google Drive" );
			$updraftplus->log("ERROR: $file_name: Failed to upload to Google Drive" );
		}
	}
	$updraftplus->prune_retained_backups("googledrive",$access_token,get_option('updraft_googledrive_remotepath'));
}

// Returns:
// true = already uploaded
// false = failure
// otherwise, the file ID
function updraftplus_googledrive_upload_file( $file, $title, $parent = '') {

	global $updraftplus;

	// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
	if ( is_wp_error( $e = updraftplus_need_gdocs() ) ) return false;
	$gdocs_object = $updraftplus->gdocs;

	$hash = md5($file);
	$transkey = 'upd_'.$hash.'_gloc';
	// This is unset upon completion, so if it is set then we are resuming
	$possible_location = get_transient($transkey);

	if ( empty( $possible_location ) ) {
		$updraftplus->log("$file: Attempting to upload file to Google Drive.");
		$location = $gdocs_object->prepare_upload( $file, $title, $parent );
	} else {
		$updraftplus->log("$file: Attempting to resume upload.");
		$location = $gdocs_object->resume_upload( $file, $possible_location );
	}

	if ( is_wp_error( $location ) ) {
		$updraftplus->log("GoogleDrive upload: an error occurred");
		foreach ($location->get_error_messages() as $msg) {
			$updraftplus->error($msg);
			$updraftplus->log("Error details: ".$msg);
		}
		return false;
	}

	if (!is_string($location) && true == $location) {
		$updraftplus->log("$file: this file is already uploaded");
		return true;
	}

	if ( is_string( $location ) ) {
		$res = $location;
		$updraftplus->log("Uploading file with title ".$title);
		$d = 0;
		do {
			$updraftplus->log("Google Drive upload: chunk d: $d, loc: $res");
			$res = $gdocs_object->upload_chunk();
			if (is_string($res)) set_transient($transkey, $res, 3600*3);
			$p = $gdocs_object->get_upload_percentage();
			if ( $p - $d >= 1 ) {
				$b = intval( $p - $d );
// 					echo '<span style="width:' . $b . '%"></span>';
				$d += $b;
			}
// 				$this->options['backup_list'][$id]['speed'] = $this->gdocs->get_upload_speed();
		} while ( is_string( $res ) );
// 			echo '</div>';

		if ( is_wp_error( $res ) || $res !== true) {
			$updraftplus->log( "An error occurred during GoogleDrive upload (2)" );
			$updraftplus->error( "An error occurred during GoogleDrive upload (2)" );
			if (is_wp_error( $res )) {
				foreach ($res->get_error_messages() as $msg) $updraftplus->log($msg);
			}
			return false;
		}

		$updraftplus->log("The file was successfully uploaded to Google Drive in ".number_format_i18n( $gdocs_object->time_taken(), 3)." seconds at an upload speed of ".size_format( $gdocs_object->get_upload_speed() )."/s.");

		delete_transient($transkey);
// 			unset( $this->options['backup_list'][$id]['location'], $this->options['backup_list'][$id]['attempt'] );
	}

	return $gdocs_object->get_file_id();

// 		$this->update_quota();
//	Google's "user info" service
// 		if ( empty( $this->options['user_info'] ) ) $this->set_user_info();

}

function updraftplus_download_googledrive_backup($file) {

	global $updraftplus;

	if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');

	// Do we have an access token?
	if ( !$access_token = $updraftplus->access_token( get_option('updraft_googledrive_token'), get_option('updraft_googledrive_clientid'), get_option('updraft_googledrive_secret') )) {
		$updraftplus->error('ERROR: Have not yet obtained an access token from Google (has the user authorised?)');
		return false;
	}

	$updraftplus->gdocs_access_token = $access_token;

	// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
	if ( is_wp_error( $e = updraftplus_need_gdocs() ) ) return false;
	$gdocs_object = $updraftplus->gdocs;

	$ids = get_option('updraft_file_ids', array());
	if (!isset($ids[$file])) {
		$this->error("Google Drive error: $file: could not download: could not find a record of the Google Drive file ID for this file");
		return;
	} else {
		$content_link = $gdocs_object->get_content_link( $ids[$file], $file );
		if (is_wp_error($content_link)) {
			$updraftplus->error("Could not find $file in order to download it (id: ".$ids[$file].")");
			foreach ($content_link->get_error_messages() as $msg) $updraftplus->error($msg);
			return false;
		}
		// Actually download the thing
		$download_to = trailingslashit(get_option('updraft_dir')).$file;
		$gdocs_object->download_data($content_link, $download_to);

		if (filesize($download_to) >0) {
			return true;
		} else {
			$updraftplus->error("Google Drive error: zero-size file was downloaded");
			return false;
		}

	}

	return;

}

// This function modified from wordpress.org/extend/plugins/backup, by Sorin Iclanzan, under the GPLv3 or later at your choice
function updraftplus_need_gdocs() {

	global $updraftplus;

	if ( ! updraftplus_is_gdocs($updraftplus->gdocs) ) {
		if ( get_option('updraft_googledrive_token') == "" || get_option('updraft_googledrive_clientid') == "" || get_option('updraft_googledrive_secret') == "" ) {
			$updraftplus->log("GoogleDrive: this account is not authorised");
			return new WP_Error( "not_authorized", "Account is not authorized." );
		}

		if ( is_wp_error( $updraftplus->gdocs_access_token ) ) return $access_token;

		$updraftplus->gdocs = new UpdraftPlus_GDocs( $updraftplus->gdocs_access_token );
		$updraftplus->gdocs->set_option( 'chunk_size', 1 ); # 1Mb; change from default of 512Kb
		$updraftplus->gdocs->set_option( 'request_timeout', 10 ); # Change from default of 10s
		$updraftplus->gdocs->set_option( 'max_resume_attempts', 36 ); # Doesn't look like GDocs class actually uses this anyway
	}
	return true;
}

// This function taken from wordpress.org/extend/plugins/backup, by Sorin Iclanzan, under the GPLv3 or later at your choice
function updraftplus_is_gdocs( $thing ) {
	if ( is_object( $thing ) && is_a( $thing, 'UpdraftPlus_GDocs' ) ) return true;
	return false;
}


?>