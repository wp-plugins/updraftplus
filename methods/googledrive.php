<?php

class UpdraftPlus_BackupModule_googledrive {

	var $gdocs;
	var $gdocs_access_token;

	public static function action_auth() {
		if ( isset( $_GET['state'] ) ) {
			if ( $_GET['state'] == 'token' )
				self::gdrive_auth_token();
			elseif ( $_GET['state'] == 'revoke' )
				self::gdrive_auth_revoke();
		} elseif (isset($_GET['updraftplus_googleauth'])) {
			self::gdrive_auth_request();
		}
	}

	// Get a Google account access token using the refresh token
	function access_token( $token, $client_id, $client_secret ) {

		global $updraftplus;
		$updraftplus->log("Google Drive: requesting access token: client_id=$client_id");

		$query_body = array( 'refresh_token' => $token, 'client_id' => $client_id, 'client_secret' => $client_secret, 'grant_type' => 'refresh_token' );
		$result = wp_remote_post('https://accounts.google.com/o/oauth2/token', array('timeout' => '15', 'method' => 'POST', 'body' => $query_body) );

		if (is_wp_error($result)) {
			$updraftplus->log("Google Drive error when requesting access token");
			foreach ($result->get_error_messages() as $msg) { $updraftplus->log("Error message: $msg"); }
			return false;
		} else {
			$json_values = json_decode( $result['body'], true );
			if ( isset( $json_values['access_token'] ) ) {
				$updraftplus->log("Google Drive: successfully obtained access token");
				return $json_values['access_token'];
			} else {
				$updraftplus->log("Google Drive error when requesting access token: response does not contain access_token");
				return false;
			}
		}
	}

	// Acquire single-use authorization code from Google OAuth 2.0
	public static function gdrive_auth_request() {
		// First, revoke any existing token, since Google doesn't appear to like issuing new ones
		if (UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token') != "") self::gdrive_auth_revoke();
		// We use 'force' here for the approval_prompt, not 'auto', as that deals better with messy situations where the user authenticated, then changed settings
		$params = array(
			'response_type' => 'code',
			'client_id' => UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'),
			'redirect_uri' => admin_url('options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth'),
			'scope' => 'https://www.googleapis.com/auth/drive.file https://docs.google.com/feeds/ https://docs.googleusercontent.com/ https://spreadsheets.google.com/feeds/',
			'state' => 'token',
			'access_type' => 'offline',
			'approval_prompt' => 'force'
		);
		header('Location: https://accounts.google.com/o/oauth2/auth?'.http_build_query($params));
	}

	// Revoke a Google account refresh token
	// Returns the parameter fed in, so can be used as a WordPress options filter
	public static function gdrive_auth_revoke() {
		$ignore = wp_remote_get('https://accounts.google.com/o/oauth2/revoke?token='.UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token'));
		UpdraftPlus_Options::update_updraft_option('updraft_googledrive_token','');
		//header('Location: '.admin_url( 'options-general.php?page=updraftplus&message=Authorisation revoked'));
	}

	// Get a Google account refresh token using the code received from gdrive_auth_request
	public static function gdrive_auth_token() {
		if( isset( $_GET['code'] ) ) {
			$post_vars = array(
				'code' => $_GET['code'],
				'client_id' => UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'),
				'client_secret' => UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret'),
				'redirect_uri' => admin_url('options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth'),
				'grant_type' => 'authorization_code'
			);

			$result = wp_remote_post('https://accounts.google.com/o/oauth2/token', array('timeout' => 30, 'method' => 'POST', 'body' => $post_vars) );

			if (is_wp_error($result)) {
				$add_to_url = "Bad response when contacting Google: ";
				foreach ( $result->get_error_messages() as $message ) {
					global $updraftplus;
					$updraftplus->log("Google Drive authentication error: ".$message);
					$add_to_url .= "$message. ";
				}
				header('Location: '.admin_url('options-general.php?page=updraftplus&error='.urlencode($add_to_url)) );
			} else {
				$json_values = json_decode( $result['body'], true );
				if ( isset( $json_values['refresh_token'] ) ) {
					UpdraftPlus_Options::update_updraft_option('updraft_googledrive_token', $json_values['refresh_token']); // Save token
					header('Location: '.admin_url('options-general.php?page=updraftplus&message=' . __( 'Google Drive authorisation was successful.', 'updraftplus' ) ) );
				}
				else {
					header('Location: '.admin_url('options-general.php?page=updraftplus&error=' . __( 'No refresh token was received from Google. This often means that you entered your client secret wrongly, or that you have not yet re-authenticated (below) since correcting it. Re-check it, then follow the link to authenticate again. Finally, if that does not work, then use expert mode to wipe all your settings, create a new Google client ID/secret, and start again.', 'updraftplus' ) ) );
				}
			}
		}
		else {
			header('Location: '.admin_url('options-general.php?page=updraftplus&error=' . __( 'Authorization failed!', 'updraftplus' ) ) );
		}
	}

	// This function just does the formalities, and off-loads the main work to upload_file
	function backup($backup_array) {

		global $updraftplus;

		if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');

		// Do we have an access token?
		if ( !$access_token = $this->access_token( UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret') )) {
			$updraftplus->log('ERROR: Have not yet obtained an access token from Google (has the user authorised?)');
			$updraftplus->error('Have not yet obtained an access token from Google - you need to authorise or re-authorise your connection to Google Drive.');
			return new WP_Error( "no_access_token", "Have not yet obtained an access token from Google (has the user authorised?");
		}

		$this->gdocs_access_token = $access_token;

		foreach ($backup_array as $file) {
			$file_path = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir')).$file;
			$file_name = basename($file_path);
			$updraftplus->log("$file_name: Attempting to upload to Google Drive");
			$timer_start = microtime(true);
			if ( $id = $this->upload_file( $file_path, $file_name, UpdraftPlus_Options::get_updraft_option('updraft_googledrive_remotepath')) ) {
				$updraftplus->log('OK: Archive ' . $file_name . ' uploaded to Google Drive in ' . ( round(microtime( true ) - $timer_start,2) ) . ' seconds (id: '.$id.')' );
				$updraftplus->uploaded_file($file, $id);
			} else {
				$updraftplus->log("ERROR: $file_name: Failed to upload to Google Drive" );
				$updraftplus->error("$file_name: Failed to upload to Google Drive" );
			}
		}
		$updraftplus->prune_retained_backups("googledrive", $this, null);
	}

	function delete($file) {
		global $updraftplus;
		$ids = UpdraftPlus_Options::get_updraft_option('updraft_file_ids', array());
		if (!isset($ids[$file])) {
			$updraftplus->log("Could not delete: could not find a record of the Google Drive file ID for this file");
			return;
		} else {
			$del == $this->gdocs->delete_resource($ids[$file]);
			if (is_wp_error($del)) {
				foreach ($del->get_error_messages() as $msg) $updraftplus->log("Deletion failed: $msg");
			} else {
				$updraftplus->log("Deletion successful");
				unset($ids[$file]);
				UpdraftPlus_Options::update_updraft_option('updraft_file_ids', $ids);
			}
		}
		return;
	}

	// Returns:
	// true = already uploaded
	// false = failure
	// otherwise, the file ID
	function upload_file( $file, $title, $parent = '') {

		global $updraftplus;

		// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
		if ( is_wp_error( $e = $this->need_gdocs() ) ) return false;
		$gdocs_object = $this->gdocs;

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
				$updraftplus->log("Error details: ".$msg);
				$updraftplus->error($msg);
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
			// This counter is only used for when deciding what to log
			$counter = 0;
			do {
				$log_string = ($counter == 0) ? "URL: $res" : "";
				$updraftplus->record_uploaded_chunk($d, $log_string);

				$counter++; if ($counter >= 20) $counter=0;

				$res = $gdocs_object->upload_chunk();
				if (is_string($res)) set_transient($transkey, $res, UPDRAFT_TRANSTIME);
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
				$updraftplus->error( "An error occurred during GoogleDrive upload (see log for more details" );
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

	function download($file) {

		global $updraftplus;

		if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');

		// Do we have an access token?
		if ( !$access_token = $this->access_token( UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret') )) {
			$updraftplus->error('Have not yet obtained an access token from Google (has the user authorised?)');
			return false;
		}

		$this->gdocs_access_token = $access_token;

		// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
		if ( is_wp_error( $e = $this->need_gdocs() ) ) return false;
		$gdocs_object = $this->gdocs;

		$ids = UpdraftPlus_Options::get_updraft_option('updraft_file_ids', array());
		if (!isset($ids[$file])) {
			$updraftplus->error("Google Drive error: $file: could not download: could not find a record of the Google Drive file ID for this file");
			return;
		} else {
			$content_link = $gdocs_object->get_content_link( $ids[$file], $file );
			if (is_wp_error($content_link)) {
				$updraftplus->error("Could not find $file in order to download it (id: ".$ids[$file].")");
				foreach ($content_link->get_error_messages() as $msg) $updraftplus->error($msg);
				return false;
			}
			// Actually download the thing

			$download_to = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir')).$file;
			$gdocs_object->download_data($content_link, $download_to, true);

			if (filesize($download_to) > 0) {
				return true;
			} else {
				$updraftplus->error("Google Drive error: zero-size file was downloaded");
				return false;
			}

		}

		return;

	}

	// This function modified from wordpress.org/extend/plugins/backup, by Sorin Iclanzan, under the GPLv3 or later at your choice
	function need_gdocs() {

		global $updraftplus;

		if ( ! $this->is_gdocs($this->gdocs) ) {
			if ( UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token') == "" || UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid') == "" || UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret') == "" ) {
				$updraftplus->log("GoogleDrive: this account is not authorised");
				return new WP_Error( "not_authorized", "Account is not authorized." );
			}

			if ( is_wp_error( $this->gdocs_access_token ) ) return $access_token;

			$this->gdocs = new UpdraftPlus_GDocs( $this->gdocs_access_token );
			// We need to be able to upload at least one chunk within the timeout (at least, we have seen an error report where the failure to do this seemed to be the cause)
			// If we assume a user has at least 16kb/s (we saw one user with as low as 22kb/s), and that their provider may allow them only 15s, then we have the following settings
			$this->gdocs->set_option( 'chunk_size', 0.2 ); # 0.2Mb; change from default of 512Kb
			$this->gdocs->set_option( 'request_timeout', 15 ); # Change from default of 5s
			$this->gdocs->set_option( 'max_resume_attempts', 36 ); # Doesn't look like GDocs class actually uses this anyway
		}
		return true;
	}

	// This function taken from wordpress.org/extend/plugins/backup, by Sorin Iclanzan, under the GPLv3 or later at your choice
	function is_gdocs( $thing ) {
		if ( is_object( $thing ) && is_a( $thing, 'UpdraftPlus_GDocs' ) ) return true;
		return false;
	}

	public static function config_print() {
		?>
			<tr class="updraftplusmethod googledrive">
				<td>Google Drive:</td>
				<td>
				<img src="https://developers.google.com/drive/images/drive_logo.png" alt="Google Drive">
				<p><em>Google Drive is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your blog is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.</em></p>
				</td>
			</tr>
			<tr class="updraftplusmethod googledrive">
			<th>Google Drive:</th>
			<td>
			<p><a href="http://updraftplus.com/support/configuring-google-drive-api-access-in-updraftplus/"><strong>For longer help, including screenshots, follow this link. The description below is sufficient for more expert users.</strong></a></p>
			<p><a href="https://code.google.com/apis/console/">Follow this link to your Google API Console</a>, and there create a Client ID in the API Access section. Select 'Web Application' as the application type.</p><p>You must add <kbd><?php echo admin_url('options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth'); ?></kbd> as the authorised redirect URI (under &quot;More Options&quot;) when asked. N.B. If you install UpdraftPlus on several WordPress sites, then you cannot re-use your client ID; you must create a new one from your Google API console for each blog.

			<?php
				if (!class_exists('SimpleXMLElement')) { echo " <b>WARNING:</b> You do not have the SimpleXMLElement installed. Google Drive backups will <b>not</b> work until you do."; }
			?>
			</p>
			</td>
			</tr>

			<tr class="updraftplusmethod googledrive">
				<th>Google Drive Client ID:</th>
				<td><input type="text" autocomplete="off" style="width:352px" name="updraft_googledrive_clientid" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid')) ?>" /><br><em>If Google later shows you the message &quot;invalid_client&quot;, then you did not enter a valid client ID here.</em></td>
			</tr>
			<tr class="updraftplusmethod googledrive">
				<th>Google Drive Client Secret:</th>
				<td><input type="text" style="width:352px" name="updraft_googledrive_secret" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret')); ?>" /></td>
			</tr>
			<tr class="updraftplusmethod googledrive">
				<th>Google Drive Folder ID:</th>
				<td><input type="text" style="width:352px" name="updraft_googledrive_remotepath" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_googledrive_remotepath')); ?>" /> <em><strong>This is NOT a folder name</strong>. To get a folder's ID navigate to that folder in Google Drive in your web browser and copy the ID from your browser's address bar. It is the part that comes after <kbd>#folders/.</kbd> Leave empty to use your root folder)</em></td>
			</tr>
			<tr class="updraftplusmethod googledrive">
				<th>Authenticate with Google:</th>
				<td><p><?php if (UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token') != "") echo "<strong>(You appear to be already authenticated,</strong> though you can authenticate again to refresh your access if you've had a problem).</strong>"; ?> <a href="options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth&updraftplus_googleauth=doit"><strong>After</strong> you have saved your settings (by clicking &quot;Save Changes&quot; below), then come back here once and click this link to complete authentication with Google.</a>
				</p>
				</td>
			</tr>
		<?php
	}

}

?>
