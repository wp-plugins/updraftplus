<?php

// https://www.dropbox.com/developers/apply?cont=/developers/apps

class UpdraftPlus_BackupModule_dropbox {

	function backup($backup_array) {

		global $updraftplus;
		$updraftplus->log("DropBox: begin cloud upload");
		if (!class_exists("DropBox_API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

		if (!get_option('updraft_dropbox_appkey') || get_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->log('You do not appear to be authenticated with DropBox');
			$updraftplus->error('You do not appear to be authenticated with DropBox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.print_r($e, true));
			$updraftplus->error('DropBox error: '.print_r($e, true));
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();
		$dropbox_folder = trailingslashit(get_option('updraft_dropbox_folder'));

		foreach($backup_array as $file) {
			$updraftplus->log("DropBox: Attempt to upload: $file");

			$file_success = 1;

			$filesize = (filesize($updraft_dir.'/'.$file) / 1024);
			$microtime = microtime(true);

			try {
				$put = $dropbox->putFile($updraft_dir.'/'.$file, $dropbox_folder.$file);
			} catch (Exception $e) {
				$updraftplus->log('DropBox error: '.print_r($e, true));
				$updraftplus->error('DropBox error: '.print_r($e, true));
				$file_success = 0;
			}
			if ($file_success) {
				$updraftplus->uploaded_file($file);
				$microtime_elapsed = microtime(true)-$microtime;
				$speedps = $filesize/$microtime_elapsed;
				$speed = sprintf("%.2d",$filesize)." Kb in ".sprintf("%.2d",$microtime_elapsed)."s (".sprintf("%.2d", $speedps)." Kb/s)";
				$updraftplus->log("DropBox: File upload success (".$dropbox_folder.$file."): $speed");
			}

		}

		$updraftplus->prune_retained_backups('dropbox', $this, null);

	}

	function delete($file) {

		global $updraftplus;
		$updraftplus->log("DropBox: request deletion: $file");
		if (!class_exists("DropBox_API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

		if (!get_option('updraft_dropbox_appkey') || get_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->log('You do not appear to be authenticated with DropBox');
			$updraftplus->error('You do not appear to be authenticated with DropBox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.print_r($e, true));
			$updraftplus->error('DropBox error: '.print_r($e, true));
			return false;
		}

		$dropbox_folder = trailingslashit(get_option('updraft_dropbox_folder'));

		$file_success = 1;
		try {
			// Apparently $dropbox_folder is not needed
			$dropbox->delete($file);
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.print_r($e, true));
			$updraftplus->error('DropBox error: '.print_r($e, true));
			$file_success = 0;
		}
		if ($file_success) $updraftplus->log('DropBox: delete succeeded');

	}

	function download($file) {

		global $updraftplus;

		if (!class_exists("DropBox_API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

		if (!get_option('updraft_dropbox_appkey') || get_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->error('You do not appear to be authenticated with DropBox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		} catch (Exception $e) {
			$updraftplus->error('DropBox error: '.print_r($e, true));
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();
		$microtime = microtime(true);
		$file_success = 1;

		try {
			$put = $dropbox->getFile($file, $updraft_dir.'/'.$file);
		} catch (Exception $e) {
			$updraftplus->error('DropBox error: '.print_r($e, true));
			$file_success = 0;
		}

	}

	function config_print() {

		?>
			<tr class="updraftplusmethod dropbox">
				<th></th>
				<td>
				<p>Once you have an active DropBox account, <a href="https://www.dropbox.com/developers/apps">get your app key and secret from here</a>. <strong>Set up App Folder access.</strong></p>
				<p>Note that UpdraftPlus's DropBox support does not yet support chunked uploading of massive files. This means that UpdraftPlus will need enough resources each time WordPress calls it in order to upload at least one complete file from your backup set. You can help to support chunked uploading <a href="http://david.dw-perspective.org.uk/donate">by making a donation</a>.</p>
				</td>
			</tr>

			<tr class="updraftplusmethod dropbox">
				<th>Your DropBox App Key:</th>
				<td><input type="text" autocomplete="off" style="width:332px" id="updraft_dropbox_appkey" name="updraft_dropbox_appkey" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_appkey')) ?>" /></td>
			</tr>
			<tr class="updraftplusmethod dropbox">
				<th>Your DropBox App Secret:</th>
				<td><input type="text" style="width:332px" id="updraft_dropbox_secret" name="updraft_dropbox_secret" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_secret')); ?>" /></td>
			</tr>
			<tr class="updraftplusmethod dropbox">
				<th>DropBox Folder:</th>
				<td><input type="text" style="width:332px" id="updraft_dropbox_folder" name="updraft_dropbox_folder" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_folder')); ?>" /></td>
			</tr>

			<tr class="updraftplusmethod dropbox">
			<th></th>
			<td>
			<?php
			// Check requirements
			if (!function_exists('mcrypt_encrypt')) {
				?><p><strong>Warning:</strong> Your web server's PHP installation does not included a required module (MCrypt). Please contact your web hosting provider's support. UpdraftPlus's DropBox module <strong>requires</strong> MCrypt. Please do not file any support requests; there is no alternative.</p><?php
			}
			if (!function_exists("curl_init")) {
				?><p><strong>Warning:</strong> Your web server's PHP installation does not included a required module (Curl). Please contact your web hosting provider's support. UpdraftPlus's DropBox module <strong>requires</strong> Curl. Your only options to get this working are 1) Install/enable curl or 2) Hire us or someone else to code additional support options into UpdraftPlus. 3) Wait, possibly forever, for someone else to do this.</p><?php
			} else {
				$curl_version = curl_version();
				$curl_ssl_supported= ($curl_version['features'] & CURL_VERSION_SSL);
				if (!$curl_ssl_supported) {
				?><p><strong>Warning:</strong> Your web server's PHP/Curl installation does not support https access. We cannot access DropBox without this support. Please contact your web hosting provider's support. UpdraftPlus's DropBox module <strong>requires</strong> Curl+https. Your only options to get this working are 1) Install/enable curl with https or 2) Hire us or someone else to code additional support options into UpdraftPlus. 3) Wait, possibly forever, for someone else to do this.</p><?php
				}
			}
			?>
			</td>
			</tr>

			<tr class="updraftplusmethod dropbox">
				<th>Authenticate with DropBox:</th>
				<td><p><?php if (get_option('updraft_dropboxtk_request_token','xyz') != 'xyz') echo "<strong>(You appear to be already authenticated).</strong>"; ?> <a href="?page=updraftplus&action=updraftmethod-dropbox-auth&updraftplus_dropboxauth=doit"><strong>After</strong> you have saved your settings (by clicking &quot;Save Changes&quot; below), then come back here once and click this link to complete authentication with DropBox.</a>
				</p>
				</td>
			</tr>

<!--		<tr class="updraftplusmethod dropbox">
		<th></th>
		<td><p><button id="updraft-dropbox-test" type="button" class="button-primary" style="font-size:18px !important">Test DropBox Settings</button></p></td>
		</tr>-->
		<?php
	}
/*
	function config_print_javascript_onready() {
		?>
		jQuery('#updraft-dropbox-test').click(function(){
			var data = {
				action: 'updraft_credentials_test',
				method: 'dropbox',
				nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>',
				appkey: jQuery('#updraft_dropbox_appkey').val(),
				secret: jQuery('#updraft_dropbox_secret').val(),
				folder: jQuery('#updraft_dropbox_folder').val()
			};
			jQuery.post(ajaxurl, data, function(response) {
					alert('Settings test result: ' + response);
			});
		});
		<?php
	}*/

	function action_auth() {
		if ( isset( $_GET['oauth_token'] ) ) {
			self::auth_token();
		} elseif (isset($_GET['updraftplus_dropboxauth'])) {
			self::auth_request();
		}
	}

	function show_authed_admin_warning() {
		global $updraftplus;

		$dropbox = self::bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		$accountInfo = $dropbox->accountInfo();

		$message = "<strong>Success</strong>: you have authenticated your DropBox account";

		if ($accountInfo['code'] != "200") {
			$message .= " (though part of the returned information was not as expected - your mileage may vary)". $accountInfo['code'];
		} else {
			$body = $accountInfo['body'];
			$message .= ". Your DropBox account name: ".htmlspecialchars($body->display_name);
		}
		$updraftplus->show_admin_warning($message);

	}

	function auth_token() {
		$previous_token = get_option("updraft_dropboxtk_request_token","xyz");
		self::bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		$new_token = get_option("updraft_dropboxtk_request_token","xyz");
		if ($new_token && $new_token != "xyz") {
			add_action('admin_notices', array('UpdraftPlus_BackupModule_dropbox', 'show_authed_admin_warning') );
		}
	}

	// Acquire single-use authorization code
	function auth_request() {

		self::bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));

// 		$params = array(
// 			'response_type' => 'code',
// 			'client_id' => get_option('updraft_dropbo'),
// 			'redirect_uri' => admin_url('options-general.php?page=updraftplus&action=updraftmethod-googledrive-auth'),
// 			'scope' => 'https://www.googleapis.com/auth/drive.file https://docs.google.com/feeds/ https://docs.googleusercontent.com/ https://spreadsheets.google.com/feeds/',
// 			'state' => 'token',
// 			'access_type' => 'offline',
// 			'approval_prompt' => 'auto'
// 		);
// 		header('Location: https://accounts.google.com/o/oauth2/auth?'.http_build_query($params));
	}

	// This basically reproduces the relevant bits of bootstrap.php from the SDK
	function bootstrap($key, $secret) {

		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/Exception.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/ConsumerAbstract.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/StorageInterface.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/Encrypter.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/WordPress.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/Curl.php');

		// Set the callback URL
		$callback = admin_url('options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth');

		// Instantiate the Encrypter and storage objects
		$encrypter = new Dropbox_Encrypter('ThisOneDoesNotMatterBeyondLength');

		// Instantiate the storage
		$storage = new Dropbox_WordPress($encrypter, "updraft_dropboxtk_");

		$OAuth = new Dropbox_Curl($key, $secret, $storage, $callback);
		return new Dropbox_API($OAuth);
	}

	function credentials_test() {

		$key = $_POST['appkey'];
		$secret = $_POST['secret'];
		$folder = $_POST['folder'];

		if (empty($folder)) {
			echo "Failure: No folder details were given.";
			return;
		}
		if (empty($key)) {
			echo "Failure: No API key was given.";
			return;
		}
		if (empty($secret)) {
			echo "Failure: No API secret was given.";
			return;
		}

		if (!class_exists("DropBox_API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

		echo "Not yet implemented. $key $secret $folder";

		$dropbox = self::bootstrap($key, $secret);

		$account_info = $dropbox->accountInfo();

		print_r($account_info);

	}

}
