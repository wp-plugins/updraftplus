<?php

// https://www.dropbox.com/developers/apply?cont=/developers/apps

class UpdraftPlus_BackupModule_dropbox {

	private $current_file_hash;
	private $current_file_size;

	function chunked_callback($offset, $uploadid) {
		global $updraftplus;

		// Update upload ID
		set_transient('updraf_dbid_'.$this->current_file_hash, $uploadid, 3600*3);
		set_transient('updraf_dbof_'.$this->current_file_hash, $offset, 3600*3);

		if ($this->current_file_size > 0) {
			$percent = round(100*($offset/$this->current_file_size),1);
			$updraftplus->log("DropBox: Chunked Upload: ${percent}% ($uploadid, $offset)");
		} else {
			$updraftplus->log("DropBox: Chunked Upload: $offset bytes uploaded");
		}

	}

	function backup($backup_array) {

		global $updraftplus;
		$updraftplus->log("DropBox: begin cloud upload");

		if (!get_option('updraft_dropbox_appkey') || get_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->log('You do not appear to be authenticated with DropBox');
			$updraftplus->error('You do not appear to be authenticated with DropBox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
			$dropbox->setChunkSize(524288); // 512Kb
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			$updraftplus->error('DropBox error: '.$e->getMessage().' (see log file for more)');
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();
		$dropbox_folder = trailingslashit(get_option('updraft_dropbox_folder'));

		foreach($backup_array as $file) {
			$updraftplus->log("DropBox: Attempt to upload: $file");

			$file_success = 1;

			$hash = md5($file);
			$this->current_file_hash=$hash;

			$filesize = filesize($updraft_dir.'/'.$file);
			$this->current_file_size = $filesize;
			// Into Kb
			$filesize = $filesize/1024;
			$microtime = microtime(true);

			if ($upload_id = get_transient('updraf_dbid_'.$hash)) {
				# Resume
				$offset =  get_transient('updraf_dbof_'.$hash);
				$updraftplus->log("This is a resumption: $offset bytes had already been uploaded");
			} else {
				$offset = 0;
				$upload_id = null;
			}

			// I did erroneously have $dropbox_folder as the third parameter in chunkedUpload... this causes a sub-directory to be created
			// Old-style, single file put: $put = $dropbox->putFile($updraft_dir.'/'.$file, $dropbox_folder.$file);

			$ourself = $this;

			try {
				$dropbox->chunkedUpload($updraft_dir.'/'.$file, $file, '', true, $offset, $upload_id, array($ourself, 'chunked_callback'));
			} catch (Exception $e) {
				$updraftplus->log("Exception: ".$e->getMessage());
				if (preg_match("/Submitted input out of alignment: got \[(\d+)\] expected \[(\d+)\]/i", $e->getMessage(), $matches)) {
					// Try the indicated offset
					$we_tried = $matches[1];
					$dropbox_wanted = $matches[2];
					$updraftplus->log("DropBox alignment error: tried=$we_tried, wanted=$dropbox_wanted; will attempt recovery");
					try {
						$dropbox->chunkedUpload($updraft_dir.'/'.$file, $file, '', true, $dropbox_wanted, $upload_id, array($ourself, 'chunked_callback'));
					} catch (Exception $e) {
						$updraftplus->log('DropBox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
						$updraftplus->error("DropBox error: failed to upload file $file (see full log for more)");
						$file_success = 0;
					}
				} else {
					$updraftplus->log('DropBox error: '.$e->getMessage());
					$updraftplus->error("DropBox error: failed to upload file $file (see full log for more)");
					$file_success = 0;
				}
			}
			if ($file_success) {
				$updraftplus->uploaded_file($file);
				$microtime_elapsed = microtime(true)-$microtime;
				$speedps = $filesize/$microtime_elapsed;
				$speed = sprintf("%.2d",$filesize)." Kb in ".sprintf("%.2d",$microtime_elapsed)."s (".sprintf("%.2d", $speedps)." Kb/s)";
				$updraftplus->log("DropBox: File upload success (".$dropbox_folder.$file."): $speed");
				delete_transient('updraft_duido_'.$hash);
				delete_transient('updraft_duidi_'.$hash);
			}

		}

		$updraftplus->prune_retained_backups('dropbox', $this, null);

	}

	function delete($file) {

		global $updraftplus;
		$updraftplus->log("DropBox: request deletion: $file");

		if (!get_option('updraft_dropbox_appkey') || get_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->log('You do not appear to be authenticated with DropBox');
			//$updraftplus->error('You do not appear to be authenticated with DropBox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			//$updraftplus->error('DropBox error: failed to access DropBox (see log file for more)');
			return false;
		}

		$dropbox_folder = trailingslashit(get_option('updraft_dropbox_folder'));

		$file_success = 1;
		try {
			// Apparently $dropbox_folder is not needed
			$dropbox->delete($file);
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			// TODO
			// Add this back October 2013 when removing the block below
			//$updraftplus->error("DropBox error: failed to delete file ($file): see log file for more info");
			$file_success = 0;
		}
		if ($file_success) {
			$updraftplus->log('DropBox: delete succeeded');
		} else {
			$file_success = 1;
			// We created the file in the wrong place for a while. This code is needed until October 2013, when it can be removed.
			try {
				$dropbox->delete($dropbox_folder.'/'.$file);
			} catch (Exception $e) {
				$updraftplus->log('DropBox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
				$file_success = 0;
			}
			if ($file_success) $updraftplus->log('DropBox: delete succeeded (alternative path)');
		}

	}

	function download($file) {

		global $updraftplus;

		if (!get_option('updraft_dropbox_appkey') || get_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->error('You do not appear to be authenticated with DropBox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap(get_option('updraft_dropbox_appkey'), get_option('updraft_dropbox_secret'));
		} catch (Exception $e) {
			$updraftplus->log('DropBox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();
		$microtime = microtime(true);

		$try_the_other_one = false;
		try {
			$get = $dropbox->getFile($file, $updraft_dir.'/'.$file);
		} catch (Exception $e) {
			// TODO: Remove this October 2013 (we stored in the wrong place for a while...)
			$try_the_other_one = true;
			$possible_error = $e->getMessage();
		}

		// TODO: Remove this October 2013 (we stored in the wrong place for a while...)
		if ($try_the_other_one) {
			$dropbox_folder = trailingslashit(get_option('updraft_dropbox_folder'));
			$updraftplus->error('DropBox error: '.$e);
			try {
				$get = $dropbox->getFile($file, $updraft_dir.'/'.$file);
			}  catch (Exception $e) {
				$updraftplus->error($possible_error);
				$updraftplus->error($e->getMessage());
			}
		}

	}

	function config_print() {

		?>
			<tr class="updraftplusmethod dropbox">
				<td></td>
				<td><em>DropBox is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your blog is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.</em></td>
			</tr>

			<tr class="updraftplusmethod dropbox">
				<th></th>
				<td>
				<p>Once you have an active DropBox account, you will need to set up an 'app' - <a href="https://www.dropbox.com/developers/apps">get your app key and secret from here</a>. <strong>Set up App Folder access, and select the &quot;Core API&quot;.</strong> (You can give the app whatever name and description you like).</p>
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
				<td><input type="text" style="width:332px" id="updraft_dropbox_folder" name="updraft_dropbox_folder" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_folder')); ?>" /> <em>N.B. This is inside your &quot;apps&quot; folder</em></td>
			</tr>

			<tr class="updraftplusmethod dropbox">
			<th></th>
			<td>
			<?php
			// Check requirements.
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

	}

	// This basically reproduces the relevant bits of bootstrap.php from the SDK
	function bootstrap($key, $secret) {

		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/Exception.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/ConsumerAbstract.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/StorageInterface.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/Encrypter.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/WordPress.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/Curl.php');
//		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/WordPress.php');

		// Set the callback URL
		$callback = admin_url('options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth');

		// Instantiate the Encrypter and storage objects
		$encrypter = new Dropbox_Encrypter('ThisOneDoesNotMatterBeyondLength');

		// Instantiate the storage
		$storage = new Dropbox_WordPress($encrypter, "updraft_dropboxtk_");

//		WordPress consumer does not yet work
//		$OAuth = new Dropbox_ConsumerWordPress($key, $secret, $storage, $callback);
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

		echo "Not yet implemented. $key $secret $folder";

		$dropbox = self::bootstrap($key, $secret);

		$account_info = $dropbox->accountInfo();

		print_r($account_info);

	}

}
