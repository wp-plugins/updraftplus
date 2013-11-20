<?php

class UpdraftPlus_BackupModule_googledrive {

	public $gdocs;

	public static function action_auth() {
		if ( isset( $_GET['state'] ) ) {
			if ('success' == $_GET['state']) {
				add_action('all_admin_notices', array('UpdraftPlus_BackupModule_googledrive', 'show_authed_admin_success') );
			}
			elseif ('token' == $_GET['state']) self::gdrive_auth_token();
			elseif ('revoke' == $_GET['state']) self::gdrive_auth_revoke();
		} elseif (isset($_GET['updraftplus_googleauth'])) {
			self::gdrive_auth_request();
		}
	}

	// Get a Google account access token using the refresh token
	function access_token($token, $client_id, $client_secret) {

		global $updraftplus;
		$updraftplus->log("Google Drive: requesting access token: client_id=$client_id");

		$query_body = array(
			'refresh_token' => $token,
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'grant_type' => 'refresh_token'
		);

		$result = wp_remote_post('https://accounts.google.com/o/oauth2/token', array('timeout' => '15', 'method' => 'POST', 'body' => $query_body) );

		if (is_wp_error($result)) {
			$updraftplus->log("Google Drive error when requesting access token");
			foreach ($result->get_error_messages() as $msg) $updraftplus->log("Error message: $msg");
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
			'redirect_uri' => UpdraftPlus_Options::admin_page_url().'?action=updraftmethod-googledrive-auth',
			'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/drive.file https://docs.google.com/feeds/ https://docs.googleusercontent.com/ https://spreadsheets.google.com/feeds/',
			'state' => 'token',
			'access_type' => 'offline',
			'approval_prompt' => 'force'
		);
		if(headers_sent()) {
			global $updraftplus;
			$updraftplus->log(sprintf(__('The %s authentication could not go ahead, because something else on your site is breaking it. Try disabling your other plugins and switching to a default theme. (Specifically, you are looking for the component that sends output (most likely PHP warnings/errors) before the page begins. Turning off any debugging settings may also help).', ''), 'Google Drive'), 'error');
		} else {
			header('Location: https://accounts.google.com/o/oauth2/auth?'.http_build_query($params));
		}
	}

	// Revoke a Google account refresh token
	// Returns the parameter fed in, so can be used as a WordPress options filter
	public static function gdrive_auth_revoke() {
		$ignore = wp_remote_get('https://accounts.google.com/o/oauth2/revoke?token='.UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token'));
		UpdraftPlus_Options::update_updraft_option('updraft_googledrive_token','');
	}

	// Get a Google account refresh token using the code received from gdrive_auth_request
	public static function gdrive_auth_token() {
		if( isset( $_GET['code'] ) ) {
			$post_vars = array(
				'code' => $_GET['code'],
				'client_id' => UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'),
				'client_secret' => UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret'),
				'redirect_uri' => UpdraftPlus_Options::admin_page_url().'?action=updraftmethod-googledrive-auth',
				'grant_type' => 'authorization_code'
			);

			$result = wp_remote_post('https://accounts.google.com/o/oauth2/token', array('timeout' => 30, 'method' => 'POST', 'body' => $post_vars) );

			if (is_wp_error($result)) {
				$add_to_url = "Bad response when contacting Google: ";
				foreach ( $result->get_error_messages() as $message ) {
					global $updraftplus;
					$updraftplus->log("Google Drive authentication error: ".$message);
					$add_to_url .= $message.". ";
				}
				header('Location: '.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&error='.urlencode($add_to_url));
			} else {
				$json_values = json_decode( $result['body'], true );
				if ( isset( $json_values['refresh_token'] ) ) {

					 // Save token
					UpdraftPlus_Options::update_updraft_option('updraft_googledrive_token', $json_values['refresh_token']);

					if ( isset($json_values['access_token'])) {

						UpdraftPlus_Options::update_updraft_option('updraftplus_tmp_googledrive_access_token', $json_values['access_token']);

						// We do this to clear the GET parameters, otherwise WordPress sticks them in the _wp_referer in the form and brings them back, leading to confusion + errors
						header('Location: '.UpdraftPlus_Options::admin_page_url().'?action=updraftmethod-googledrive-auth&page=updraftplus&state=success');

					}

				} else {

					$msg = __( 'No refresh token was received from Google. This often means that you entered your client secret wrongly, or that you have not yet re-authenticated (below) since correcting it. Re-check it, then follow the link to authenticate again. Finally, if that does not work, then use expert mode to wipe all your settings, create a new Google client ID/secret, and start again.', 'updraftplus' );

					if (isset($json_values['error'])) $msg .= ' '.sprintf(__('Error: %s', 'updraftplus'), $json_values['error']);

					header('Location: '.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&error='.urlencode($msg));
				}
			}
		}
		else {
			header('Location: '.UpdraftPlus_Options::admin_page_url().'?page=updraftplus&error='.urlencode(__( 'Authorization failed', 'updraftplus')));
		}
	}

	public static function show_authed_admin_success() {

		global $updraftplus_admin;

		$updraftplus_tmp_access_token = UpdraftPlus_Options::get_updraft_option('updraftplus_tmp_googledrive_access_token');
		if (empty($updraftplus_tmp_access_token)) return;

		$message = '';
		try {
			if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');
			$x = new UpdraftPlus_BackupModule_googledrive;
			if ( !is_wp_error( $e = $x->need_gdocs($updraftplus_tmp_access_token) ) ) {
				$quota_total = max($x->gdocs->get_quota_total(), 1);
				$quota_used = $x->gdocs->get_quota_used();
				if (is_numeric($quota_total) && is_numeric($quota_used)) {
					$available_quota = $quota_total - $quota_used;
					$used_perc = round($quota_used*100/$quota_total, 1);
					$message .= sprintf(__('Your %s quota usage: %s %% used, %s available','updraftplus'), 'Google Drive', $used_perc, round($available_quota/1048576, 1).' Mb');
				}
			}
		} catch (Exception $e) {
		}

		$updraftplus_admin->show_admin_warning(__('Success','updraftplus').': '.sprintf(__('you have authenticated your %s account.','updraftplus'),__('Google Drive','updraftplus')).' '.$message);

		UpdraftPlus_Options::delete_updraft_option('updraftplus_tmp_googledrive_access_token');

	}

	// This function just does the formalities, and off-loads the main work to upload_file
	function backup($backup_array) {

		global $updraftplus, $updraftplus_backup;

		if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');

		// Do we have an access token?
		if ( !$access_token = $this->access_token( UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret') )) {
			$updraftplus->log('ERROR: Have not yet obtained an access token from Google (has the user authorised?)');
			$updraftplus->log(__('Have not yet obtained an access token from Google - you need to authorise or re-authorise your connection to Google Drive.','updraftplus'), 'error');
			return new WP_Error( "no_access_token", __("Have not yet obtained an access token from Google (has the user authorised?)",'updraftplus'));
		}

		$updraft_dir = trailingslashit($updraftplus->backups_dir_location());

		// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
		if ( is_wp_error( $e = $this->need_gdocs($access_token) ) ) return false;
		$gdocs_object = $this->gdocs;

		foreach ($backup_array as $file) {

			$available_quota = -1;

			try {
				$quota_total = $gdocs_object->get_quota_total();
				$quota_used = $gdocs_object->get_quota_used();
				$available_quota = $quota_total - $quota_used;
				$message = "Google Drive quota usage: used=".round($quota_used/1048576,1)." Mb, total=".round($quota_total/1048576,1)." Mb, available=".round($available_quota/1048576,1)." Mb";
				$updraftplus->log($message);
			} catch (Exception $e) {
				$updraftplus->log("Google Drive quota usage: failed to obtain this information: ".$e->getMessage());
			}

			$file_path = $updraft_dir.$file;
			$file_name = basename($file_path);
			$updraftplus->log("$file_name: Attempting to upload to Google Drive");

			$filesize = filesize($file_path);
			$already_failed = false;
			if ($available_quota != -1) {
				if ($filesize > $available_quota) {
					$already_failed = true;
					$updraftplus->log("File upload expected to fail: file ($file_name) size is $filesize b, whereas available quota is only $available_quota b");
					$updraftplus->log(sprintf(__("Account full: your %s account has only %d bytes left, but the file to be uploaded is %d bytes",'updraftplus'),__('Google Drive', 'updraftplus'), $available_quota, $filesize), 'error');
				}
			}
			if (!$already_failed && $filesize > 10737418240) {
				# 10Gb
				$updraftplus->log("File upload expected to fail: file ($file_name) size is $filesize b (".round($filesize/1073741824, 4)." Gb), whereas Google Drive's limit is 10Gb (1073741824 bytes)");
				$updraftplus->log(sprintf(__("Upload expected to fail: the %s limit for any single file is %s, whereas this file is %s Gb (%d bytes)",'updraftplus'),__('Google Drive', 'updraftplus'), '10Gb (1073741824)', round($filesize/1073741824, 4), $filesize), 'warning');
			} 

			$timer_start = microtime(true);
			if ( $id = $this->upload_file( $file_path, $file_name, UpdraftPlus_Options::get_updraft_option('updraft_googledrive_remotepath')) ) {
				$updraftplus->log('OK: Archive ' . $file_name . ' uploaded to Google Drive in ' . ( round(microtime( true ) - $timer_start,2) ) . ' seconds (id: '.$id.')' );
				$updraftplus->uploaded_file($file, $id);
			} else {
				$updraftplus->log("ERROR: $file_name: Failed to upload to Google Drive" );
				$updraftplus->log("$file_name: ".sprintf(__('Failed to upload to %s','updraftplus'),__('Google Drive','updraftplus')), 'error');
			}
		}

		return null;
	}

	function delete($files) {
		global $updraftplus;
		if (is_string($files)) $files=array($files);

		if ( !$this->is_gdocs($this->gdocs) ) {

			// Do we have an access token?
			if ( !$access_token = $this->access_token( UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid'), UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret') )) {
				$updraftplus->log('ERROR: Have not yet obtained an access token from Google (has the user authorised?)');
				$updraftplus->log(__('Have not yet obtained an access token from Google - you need to authorise or re-authorise your connection to Google Drive.','updraftplus'), 'error');
				return false;
			}

			// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
			if ( is_wp_error( $e = $this->need_gdocs($access_token) ) ) return false;

		}

		$ids = UpdraftPlus_Options::get_updraft_option('updraft_file_ids', array());

		$ret = true;

		foreach ($files as $file) {

			if (!isset($ids[$file])) {
				$updraftplus->log("$file: Could not delete: could not find a record of the Google Drive file ID for this file");
				$ret = false;
				continue;
			}

			$del = $this->gdocs->delete_resource($ids[$file]);
			if (is_wp_error($del)) {
				foreach ($del->get_error_messages() as $msg) $updraftplus->log("$file: Deletion failed: $msg");
				$ret = false;
				continue;
			} else {
				$updraftplus->log("$file: Deletion successful");
				unset($ids[$file]);
				UpdraftPlus_Options::update_updraft_option('updraft_file_ids', $ids);
			}

		}

		return $ret;


	}

	// Returns:
	// true = already uploaded
	// false = failure
	// otherwise, the file ID
	function upload_file( $file, $title, $parent = '') {

		global $updraftplus;

		$gdocs_object = $this->gdocs;

		$hash = md5($file);
		$transkey = 'upd_'.$hash.'_gloc';
		// This is unset upon completion, so if it is set then we are resuming
		$possible_location = $updraftplus->jobdata_get($transkey);

		if ( empty( $possible_location ) ) {
			$updraftplus->log(basename($file).": Attempting to upload file to Google Drive.");
			$location = $gdocs_object->prepare_upload( $file, $title, $parent );
		} else {
			$updraftplus->log(basename($file).": Attempting to resume upload.");
			$location = $gdocs_object->resume_upload( $file, $possible_location );
		}

		if ( is_wp_error( $location ) ) {
			$updraftplus->log("GoogleDrive upload: an error occurred");
			foreach ($location->get_error_messages() as $msg) {
				$updraftplus->log("Error details: ".$msg);
				$updraftplus->log(sprintf(__('Error: %s','updraftplus'), $msg), 'error');
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
				$updraftplus->record_uploaded_chunk($d, $log_string, $file);

				$counter++; if ($counter >= 20) $counter=0;

				$res = $gdocs_object->upload_chunk();
				if (is_string($res)) $updraftplus->jobdata_set($transkey, $res);
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
				$updraftplus->log( "An error occurred during Google Drive upload (2)" );
				$updraftplus->log(sprintf(__("An error occurred during %s upload (see log for more details)",'updraftplus'), 'Google Drive'), 'error');
				if (is_wp_error( $res )) {
					foreach ($res->get_error_messages() as $msg) $updraftplus->log($msg);
				}
				return false;
			}

			$updraftplus->log("The file was successfully uploaded to Google Drive in ".number_format_i18n( $gdocs_object->time_taken(), 3)." seconds at an upload speed of ".size_format( $gdocs_object->get_upload_speed() )."/s.");

			$updraftplus->jobdata_delete($transkey);
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
			$updraftplus->log(__('Have not yet obtained an access token from Google (has the user authorised?)', 'updraftplus'), 'error');
			return false;
		}

		// Make sure $this->gdocs is a UpdraftPlus_GDocs object, or give an error
		if ( is_wp_error( $e = $this->need_gdocs($access_token) ) ) return false;
		$gdocs_object = $this->gdocs;

		$ids = UpdraftPlus_Options::get_updraft_option('updraft_file_ids', array());
		if (!isset($ids[$file])) {
			$updraftplus->log(sprintf(__("Google Drive error: %d: could not download: could not find a record of the Google Drive file ID for this file",'updraftplus'),$file), 'error');
			return;
		} else {
			$content_link = $gdocs_object->get_content_link( $ids[$file], $file );
			if (is_wp_error($content_link)) {
				$updraftplus->log(sprintf(__("Could not find %s in order to download it", 'updraftplus'),$file)." (id: ".$ids[$file].")", 'error');
				foreach ($content_link->get_error_messages() as $msg) $updraftplus->log($msg, 'error');
				return false;
			}
			// Actually download the thing

			$download_to = $updraftplus->backups_dir_location().'/'.$file;
			$gdocs_object->download_data($content_link, $download_to, true);

			if (filesize($download_to) > 0) {
				return true;
			} else {
				$updraftplus->log('Google Drive error: zero-size file was downloaded');
				$updraftplus->log(sprintf(__('%s error: zero-size file was downloaded','updraftplus'), __("Google Drive ",'updraftplus'),__("Google Drive ",'updraftplus')), 'error');
				return false;
			}

		}

		return;

	}

	// This function modified from wordpress.org/extend/plugins/backup, by Sorin Iclanzan, under the GPLv3 or later at your choice
	function need_gdocs($access_token) {

		global $updraftplus;

		if ( ! $this->is_gdocs($this->gdocs) ) {
			if ( UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token') == "" || UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid') == "" || UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret') == "" ) {
				$updraftplus->log("GoogleDrive: this account is not authorised");
				return new WP_Error( "not_authorized", __("Account is not authorized.",'updraftplus') );
			}

			if ( is_wp_error($access_token) ) return $access_token;

			if( !class_exists('UpdraftPlus_GDocs')) require_once(UPDRAFTPLUS_DIR.'/includes/class-gdocs.php');
			$this->gdocs = new UpdraftPlus_GDocs($access_token);
			// We need to be able to upload at least one chunk within the timeout (at least, we have seen an error report where the failure to do this seemed to be the cause)
			// If we assume a user has at least 16kb/s (we saw one user with as low as 22kb/s), and that their provider may allow them only 15s, then we have the following settings
			$this->gdocs->set_option( 'chunk_size', 0.2 ); # 0.2Mb; change from default of 512Kb
			$this->gdocs->set_option( 'request_timeout', 15 ); # Change from default of 5s
			$this->gdocs->set_option( 'max_resume_attempts', 36 ); # Doesn't look like GDocs class actually uses this anyway
			if (UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify')) {
				$this->gdocs->set_option('ssl_verify', false);
			} else {
				$this->gdocs->set_option('ssl_verify', true);
			}
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
				<td><?php _e('Google Drive','updraftplus');?>:</td>
				<td>
				<img src="https://developers.google.com/drive/images/drive_logo.png" alt="<?php _e('Google Drive','updraftplus');?>">
				<p><em><?php printf(__('%s is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your site is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.','updraftplus'),'Google Drive');?></em></p>
				</td>
			</tr>

			<tr class="updraftplusmethod googledrive">
			<th></th>
			<td>
			<?php
				global $updraftplus_admin;
				if (!class_exists('SimpleXMLElement')) {
					$updraftplus_admin->show_double_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('Your web server\'s PHP installation does not included a required module (%s). Please contact your web hosting provider\'s support.', 'updraftplus'), 'SimpleXMLElement').' '.sprintf(__("UpdraftPlus's %s module <strong>requires</strong> %s. Please do not file any support requests; there is no alternative.",'updraftplus'),__('Google Drive', 'updraftplus'), 'SimpleXMLElement'), 'googledrive');
				}
			?>

			</td>
			</tr>

			<tr class="updraftplusmethod googledrive">
			<th>Google Drive:</th>
			<td>
			<p><a href="http://updraftplus.com/support/configuring-google-drive-api-access-in-updraftplus/"><strong><?php _e('For longer help, including screenshots, follow this link. The description below is sufficient for more expert users.','updraftplus');?></strong></a></p>
			<p><a href="https://code.google.com/apis/console/"><?php _e('Follow this link to your Google API Console, and there create a Client ID in the API Access section.','updraftplus');?></a> <?php _e("Select 'Web Application' as the application type.",'updraftplus');?></p><p><?php echo htmlspecialchars(__('You must add the following as the authorised redirect URI (under "More Options") when asked','updraftplus'));?>: <kbd><?php echo UpdraftPlus_Options::admin_page_url().'?action=updraftmethod-googledrive-auth'; ?></kbd> <?php _e('N.B. If you install UpdraftPlus on several WordPress sites, then you cannot re-use your client ID; you must create a new one from your Google API console for each site.','updraftplus');?>

			<?php
				if (!class_exists('SimpleXMLElement')) { echo "<b>",__('Warning','updraftplus').':</b> '.__("You do not have the SimpleXMLElement installed. Google Drive backups will <b>not</b> work until you do.",'updraftplus'); }
			?>
			</p>
			</td>
			</tr>

			<tr class="updraftplusmethod googledrive">
				<th><?php echo __('Google Drive','updraftplus').' '.__('Client ID','updraftplus'); ?>:</th>
				<td><input type="text" autocomplete="off" style="width:412px" name="updraft_googledrive_clientid" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_googledrive_clientid')) ?>" /><br><em><?php _e('If Google later shows you the message "invalid_client", then you did not enter a valid client ID here.','updraftplus');?></em></td>
			</tr>
			<tr class="updraftplusmethod googledrive">
				<th><?php echo __('Google Drive','updraftplus').' '.__('Client Secret','updraftplus'); ?>:</th>
				<td><input type="<?php echo apply_filters('updraftplus_admin_secret_field_type', 'text'); ?>" style="width:412px" name="updraft_googledrive_secret" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_googledrive_secret')); ?>" /></td>
			</tr>
			<tr class="updraftplusmethod googledrive">
				<th><?php echo __('Google Drive','updraftplus').' '.__('Folder ID','updraftplus'); ?>:</th>
				<td><input type="text" style="width:412px" name="updraft_googledrive_remotepath" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_googledrive_remotepath')); ?>" /> <em><?php _e("<strong>This is NOT a folder name</strong>. To get a folder's ID navigate to that folder in Google Drive in your web browser and copy the ID from your browser's address bar. It is the part that comes after <kbd>#folders/</kbd>. Leave empty to use your root folder.",'updraftplus');?></em></td>
			</tr>
			<tr class="updraftplusmethod googledrive">
				<th><?php _e('Authenticate with Google');?>:</th>
				<td><p><?php if ('' != UpdraftPlus_Options::get_updraft_option('updraft_googledrive_token')) echo __("<strong>(You appear to be already authenticated,</strong> though you can authenticate again to refresh your access if you've had a problem).",'updraftplus'); ?> <a href="<?php echo UpdraftPlus_Options::admin_page_url();?>?action=updraftmethod-googledrive-auth&page=updraftplus&updraftplus_googleauth=doit"><?php print __('<strong>After</strong> you have saved your settings (by clicking \'Save Changes\' below), then come back here once and click this link to complete authentication with Google.','updraftplus');?></a>
				</p>
				</td>
			</tr>
		<?php
	}

}

?>
