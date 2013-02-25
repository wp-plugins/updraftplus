<?php

// https://www.dropbox.com/developers/apply?cont=/developers/apps

class UpdraftPlus_BackupModule_dropbox {

	private $current_file_hash;
	private $current_file_size;

	function chunked_callback($offset, $uploadid) {
		global $updraftplus;

		// Update upload ID
		set_transient('updraf_dbid_'.$this->current_file_hash, $uploadid, UPDRAFT_TRANSTIME);
		set_transient('updraf_dbof_'.$this->current_file_hash, $offset, UPDRAFT_TRANSTIME);

		if ($this->current_file_size > 0) {
			$percent = round(100*($offset/$this->current_file_size),1);
			$updraftplus->record_uploaded_chunk($percent, "($uploadid, $offset)");
		} else {
			$updraftplus->log("Dropbox: Chunked Upload: $offset bytes uploaded");
		}

	}

	function backup($backup_array) {

		global $updraftplus;
		$updraftplus->log("Dropbox: begin cloud upload");

		if (UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->log('You do not appear to be authenticated with Dropbox');
			$updraftplus->error('You do not appear to be authenticated with Dropbox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap();
			$dropbox->setChunkSize(524288); // 512Kb
		} catch (Exception $e) {
			$updraftplus->log('Dropbox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			$updraftplus->error('Dropbox error: '.$e->getMessage().' (see log file for more)');
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();
		$dropbox_folder = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dropbox_folder'));

		foreach($backup_array as $file) {
			$updraftplus->log("Dropbox: Attempt to upload: $file");

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
					$updraftplus->log("Dropbox alignment error: tried=$we_tried, wanted=$dropbox_wanted; will attempt recovery");
					try {
						$dropbox->chunkedUpload($updraft_dir.'/'.$file, $file, '', true, $dropbox_wanted, $upload_id, array($ourself, 'chunked_callback'));
					} catch (Exception $e) {
						$updraftplus->log('Dropbox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
						$updraftplus->error("Dropbox error: failed to upload file $file (see full log for more)");
						$file_success = 0;
					}
				} else {
					$updraftplus->log('Dropbox error: '.$e->getMessage());
					$updraftplus->error("Dropbox error: failed to upload file $file (see full log for more)");
					$file_success = 0;
				}
			}
			if ($file_success) {
				$updraftplus->uploaded_file($file);
				$microtime_elapsed = microtime(true)-$microtime;
				$speedps = $filesize/$microtime_elapsed;
				$speed = sprintf("%.2d",$filesize)." Kb in ".sprintf("%.2d",$microtime_elapsed)."s (".sprintf("%.2d", $speedps)." Kb/s)";
				$updraftplus->log("Dropbox: File upload success (".$file."): $speed");
				delete_transient('updraft_duido_'.$hash);
				delete_transient('updraft_duidi_'.$hash);
			}

		}

		$updraftplus->prune_retained_backups('dropbox', $this, null);

	}

	function defaults() {
		return array('Z3Q3ZmkwbnplNHA0Zzlx', 'bTY0bm9iNmY4eWhjODRt');
	}

	function delete($file) {

		global $updraftplus;
		$updraftplus->log("Dropbox: request deletion: $file");

		if (UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->log('You do not appear to be authenticated with Dropbox');
			//$updraftplus->error('You do not appear to be authenticated with Dropbox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap();
		} catch (Exception $e) {
			$updraftplus->log('Dropbox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			//$updraftplus->error('Dropbox error: failed to access Dropbox (see log file for more)');
			return false;
		}

		$dropbox_folder = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dropbox_folder'));

		$file_success = 1;
		try {
			// Apparently $dropbox_folder is not needed
			$dropbox->delete($file);
		} catch (Exception $e) {
			$updraftplus->log('Dropbox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			// TODO
			// Add this back October 2013 when removing the block below
			//$updraftplus->error("Dropbox error: failed to delete file ($file): see log file for more info");
			$file_success = 0;
		}
		if ($file_success) {
			$updraftplus->log('Dropbox: delete succeeded');
		} else {
			$file_success = 1;
			// We created the file in the wrong place for a while. This code is needed until October 2013, when it can be removed.
			try {
				$dropbox->delete($dropbox_folder.'/'.$file);
			} catch (Exception $e) {
				$updraftplus->log('Dropbox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
				$file_success = 0;
			}
			if ($file_success) $updraftplus->log('Dropbox: delete succeeded (alternative path)');
		}

	}

	function download($file) {

		global $updraftplus;

		if (UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token', 'xyz') == 'xyz') {
			$updraftplus->error('You do not appear to be authenticated with Dropbox');
			return false;
		}

		try {
			$dropbox = $this->bootstrap();
		} catch (Exception $e) {
			$updraftplus->log('Dropbox error: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
			return false;
		}

		$updraft_dir = $updraftplus->backups_dir_location();
		$microtime = microtime(true);

		$try_the_other_one = false;
		try {
			$get = $dropbox->getFile($file, $updraft_dir.'/'.$file, null, true);
		} catch (Exception $e) {
			// TODO: Remove this October 2013 (we stored in the wrong place for a while...)
			$try_the_other_one = true;
			$possible_error = $e->getMessage();
		}

		// TODO: Remove this October 2013 (we stored in the wrong place for a while...)
		if ($try_the_other_one) {
			$dropbox_folder = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dropbox_folder'));
			$updraftplus->error('Dropbox error: '.$e);
			try {
				$get = $dropbox->getFile($file, $updraft_dir.'/'.$file, null, true);
				if (isset($get['response']['body'])) {
					$updraftplus->log("Dropbox: downloaded ".round(strlen($get['response']['body'])/1024,1).' Kb');
				}
			}  catch (Exception $e) {
				$updraftplus->error($possible_error);
				$updraftplus->error($e->getMessage());
			}
		}

	}

	public static function config_print() {

		?>
			<tr class="updraftplusmethod dropbox">
				<td></td>
				<td>
				<img alt="Dropbox logo" src="<?php echo UPDRAFTPLUS_URL.'/images/dropbox-logo.png' ?>">
				<p><em>Dropbox is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your blog is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.</em></p>
				</td>
			</tr>

			<tr class="updraftplusmethod dropbox">
				<th>Authenticate with Dropbox:</th>
				<td><p><?php if (UpdraftPlus_Options::get_updraft_option('updraft_dropboxtk_request_token','xyz') != 'xyz') echo "<strong>(You appear to be already authenticated).</strong>"; ?> <a href="?page=updraftplus&action=updraftmethod-dropbox-auth&updraftplus_dropboxauth=doit"><strong>After</strong> you have saved your settings (by clicking &quot;Save Changes&quot; below), then come back here once and click this link to complete authentication with Dropbox.</a>
				</p>
				</td>
			</tr>

			<tr class="updraftplusmethod dropbox">
			<th></th>
			<td>
			<?php
			// Check requirements.
			if (!function_exists('mcrypt_encrypt')) {
				?><p><strong>Warning:</strong> Your web server's PHP installation does not included a required module (MCrypt). Please contact your web hosting provider's support. UpdraftPlus's Dropbox module <strong>requires</strong> MCrypt. Please do not file any support requests; there is no alternative.</p><?php
			}
			if (!function_exists("curl_init")) {
				?><p><strong>Warning:</strong> Your web server's PHP installation does not included a required module (Curl). Please contact your web hosting provider's support. UpdraftPlus's Dropbox module <strong>requires</strong> Curl. Your only options to get this working are 1) Install/enable curl or 2) Hire us or someone else to code additional support options into UpdraftPlus. 3) Wait, possibly forever, for someone else to do this.</p><?php
			} else {
				$curl_version = curl_version();
				$curl_ssl_supported= ($curl_version['features'] & CURL_VERSION_SSL);
				if (!$curl_ssl_supported) {
				?><p><strong>Warning:</strong> Your web server's PHP/Curl installation does not support https access. We cannot access Dropbox without this support. Please contact your web hosting provider's support. UpdraftPlus's Dropbox module <strong>requires</strong> Curl+https. Your only options to get this working are 1) Install/enable curl with https or 2) Hire us or someone else to code additional support options into UpdraftPlus. 3) Wait, possibly forever, for someone else to do this.</p><?php
				}
			}
			?>
			</td>
			</tr>

			<?php
			// This setting should never have been used - it is legacy/deprecated
			?>
			<input type="hidden" name="updraft_dropbox_folder" value="">

			<?php
			// Legacy: only show this next setting to old users who had a setting stored
			if (UpdraftPlus_Options::get_updraft_option('updraft_dropbox_appkey')) {
			?>

				<tr class="updraftplusmethod dropbox">
					<th>Your Dropbox App Key:</th>
					<td><input type="text" autocomplete="off" style="width:332px" id="updraft_dropbox_appkey" name="updraft_dropbox_appkey" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_dropbox_appkey')) ?>" /></td>
				</tr>
				<tr class="updraftplusmethod dropbox">
					<th>Your Dropbox App Secret:</th>
					<td><input type="text" style="width:332px" id="updraft_dropbox_secret" name="updraft_dropbox_secret" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_dropbox_secret')); ?>" /></td>
				</tr>

			<?php } ?>

<!--		<tr class="updraftplusmethod dropbox">
		<th></th>
		<td><p><button id="updraft-dropbox-test" type="button" class="button-primary" style="font-size:18px !important">Test Dropbox Settings</button></p></td>
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

	public static function action_auth() {
		if ( isset( $_GET['oauth_token'] ) ) {
			self::auth_token();
		} elseif (isset($_GET['updraftplus_dropboxauth'])) {
			self::auth_request();
		}
	}

	function show_authed_admin_warning() {
		global $updraftplus;

		$dropbox = self::bootstrap();
		$accountInfo = $dropbox->accountInfo();

		$message = "<strong>Success</strong>: you have authenticated your Dropbox account";

		if ($accountInfo['code'] != "200") {
			$message .= " (though part of the returned information was not as expected - your mileage may vary)". $accountInfo['code'];
		} else {
			$body = $accountInfo['body'];
			$message .= ". Your Dropbox account name: ".htmlspecialchars($body->display_name);
		}
		$updraftplus->show_admin_warning($message);

	}

	public static function auth_token() {
		$previous_token = UpdraftPlus_Options::get_updraft_option("updraft_dropboxtk_request_token","xyz");
		self::bootstrap();
		$new_token = UpdraftPlus_Options::get_updraft_option("updraft_dropboxtk_request_token","xyz");
		if ($new_token && $new_token != "xyz") {
			add_action('admin_notices', array('UpdraftPlus_BackupModule_dropbox', 'show_authed_admin_warning') );
		}
	}

	// Acquire single-use authorization code
	public static function auth_request() {
		self::bootstrap();
	}

	// This basically reproduces the relevant bits of bootstrap.php from the SDK
	function bootstrap() {

		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php'	);
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/Exception.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/ConsumerAbstract.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/StorageInterface.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/Encrypter.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Storage/WordPress.php');
		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/Curl.php');
//		require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/OAuth/Consumer/WordPress.php');

		$key = UpdraftPlus_Options::get_updraft_option('updraft_dropbox_secret');
		$sec = UpdraftPlus_Options::get_updraft_option('updraft_dropbox_appkey');

		// Set the callback URL
		$callback = admin_url('options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth');

		// Instantiate the Encrypter and storage objects
		$encrypter = new Dropbox_Encrypter('ThisOneDoesNotMatterBeyondLength');

		// Instantiate the storage
		$storage = new Dropbox_WordPress($encrypter, "updraft_dropboxtk_");

//		WordPress consumer does not yet work
//		$OAuth = new Dropbox_ConsumerWordPress($sec, $key, $storage, $callback);

		// Get the DropBox API access details
		list($d2, $d1) = self::defaults();
		if (empty($sec)) { $sec = base64_decode($d1); }; if (empty($key)) { $key = base64_decode($d2); }
		$OAuth = new Dropbox_Curl($sec, $key, $storage, $callback);
		return new Dropbox_API($OAuth);
	}

}
