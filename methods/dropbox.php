<?php

// https://www.dropbox.com/developers/apply?cont=/developers/apps

class UpdraftPlus_BackupModule_dropbox {

	var $dropbox = false;

	function backup($backup_array) {

		global $updraftplus;
		$updraftplus->log("DropBox: begin cloud upload");
		if (!class_exists("DropBox\\API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

	}

	function delete($file) {

		global $updraftplus;
		$updraftplus->log("DropBox: request deletion: $file");
		if (!class_exists("DropBox\\API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

	}

	function download($file) {

		global $updraftplus;
		$updraftplus->log("DropBox: request download: $file");
		if (!class_exists("DropBox\\API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

	}

	function config_print() {

		?>
			<tr class="updraftplusmethod dropbox">
				<th>DropBox</th>
				<td>
				<p>Once you have an active DropBox account, <a href="https://www.dropbox.com/developers/apps">get your app key and secret from here</a>.</p>
				<p>Note that UpdraftPlus's DropBox support does not yet support chunked uploading of massive files. This means that UpdraftPlus will need enough resources each time WordPress calls it in order to upload at least one complete file from your backup set.</p>
				</td>
			</tr>

			<tr class="updraftplusmethod dropbox">
				<th>Your DropBox App Key:</th>
				<td><input type="text" autocomplete="off" style="width:332px" name="updraft_dropbox_appkey" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_appkey')) ?>" /></td>
			</tr>
			<tr class="updraftplusmethod dropbox">
				<th>Your DropBox App Secret:</th>
				<td><input type="text" style="width:332px" name="updraft_dropbox_secret" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_secret')); ?>" /></td>
			</tr>
			<tr class="updraftplusmethod dropbox">
				<th>Google Drive Folder ID:</th>
				<td><input type="text" style="width:332px" name="updraft_dropbox_folder" value="<?php echo htmlspecialchars(get_option('updraft_dropbox_folder')); ?>" /></td>
			</tr>

			<tr class="updraftplusmethod dropbox">
			<th></th>
			<td>
			<?php
			// Check requirements
			if (version_compare(phpversion(), "5.3.1", "<")) {
				?><p><strong>Warning:</strong> Your web server is running PHP <?php echo phpversion(); ?>. UpdraftPlus's DropBox module requires at least PHP 5.3.1. Possibly UpdraftPlus may work for you, but if it does not then please do not request support - we cannot help. Note that the popular &quot;DropBox for WordPress&quot; module is using the same DropBox toolkit as UpdraftPlus, so you will not have any more success there! PHP before 5.3.1 has been obsolete for a long time and you should ask your provider for an upgrade (or try <a href="http://www.simbahosting.co.uk">Simba Hosting</a>).</p><?php
			}
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
		<th></th>
		<td><p><button id="updraft-dropbox-test" type="button" class="button-primary" style="font-size:18px !important">Test DropBox Settings</button></p></td>
		</tr>
		<?php
	}

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
	}

	// This basically reproduces the relevant bits of bootstrap.php from the SDK
	function bootstrap($key, $secret) {
		// Register a simple autoload function
		spl_autoload_register(function($class){
			$class = str_replace('\\', '/', $class);
			require_once(UPDRAFTPLUS_DIR.'/includes/' . $class . '.php');
		});

		// Set the callback URL
		$callback = admin_url('options-general.php?page=updraftplus&action=updraftmethod-dropbox-auth');

		// Instantiate the Encrypter and storage objects
		$encrypter = new \Dropbox\OAuth\Storage\Encrypter('ThisOneDoesNotMatter');

		// User ID assigned by your auth system (used by persistent storage handlers)
		$userID = 1;

		// Instantiate the storage
		$storage = new \Dropbox\OAuth\Storage\Session($encrypter);

		$OAuth = new \Dropbox\OAuth\Consumer\Curl($key, $secret, $storage, $callback);
		return new \Dropbox\API($OAuth);
	}

	function credentials_test() {

		$key = $_POST['appkey'];
		$secret = $_POST['secret'];
		$folder = $_POST['folder'];

		if (empty($folder)) {
			echo "Failure: No bucket details were given.";
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

		if (!class_exists("DropBox\\API")) require_once(UPDRAFTPLUS_DIR.'/includes/Dropbox/API.php');

		echo "Not yet implemented. $key $secret $folder";

		$dropbox = self::bootstrap($key, $secret);

		$account_info = $dropbox->accountInfo();

		print_r($account_info);

	}



}