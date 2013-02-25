<?php

class UpdraftPlus_BackupModule_ftp {

	function backup($backup_array) {

		global $updraftplus;

		if( !class_exists('UpdraftPlus_ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

		$server = UpdraftPlus_Options::get_updraft_option('updraft_server_address');

		$user = UpdraftPlus_Options::get_updraft_option('updraft_ftp_login');
		$ftp = new UpdraftPlus_ftp_wrapper($server , $user, UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass'));
		$ftp->passive = true;

		if (!$ftp->connect()) {
			$updraftplus->log("FTP Failure: we did not successfully log in with those credentials.");
			$updraftplus->error("FTP login failure");
			return false;
		}

		//$ftp->make_dir(); we may need to recursively create dirs? TODO

		$ftp_remote_path = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path'));
		foreach($backup_array as $file) {
			$fullpath = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir')).$file;
			$updraftplus->log("FTP upload attempt: $file -> ftp://$user@$server/${ftp_remote_path}${file}");
			$timer_start = microtime(true);
			$size_k = round(filesize($fullpath)/1024,1);
			if ($ftp->put($fullpath, $ftp_remote_path.$file, FTP_BINARY)) {
				$updraftplus->log("FTP upload attempt successful (".$size_k."Kb in ".(round(microtime(true)-$timer_start,2)).'s)');
				$updraftplus->uploaded_file($file);
			} else {
				$updraftplus->log("ERROR: FTP upload failed" );
				$updraftplus->error("FTP upload failed" );
			}
		}

		$updraftplus->prune_retained_backups("ftp", $this, array('ftp_object' => $ftp, 'ftp_remote_path' => $ftp_remote_path));
	}

	function delete($file, $ftparr) {
		global $updraftplus;
		$ftp = $ftparr['ftp_object'];
		$ftp_remote_path = $ftparr['ftp_remote_path'];
		if (@$ftp->delete($ftp_remote_path.$file)) {
			$updraftplus->log("FTP delete: succeeded (${ftp_remote_path}${file})");
		} else {
			$updraftplus->log("FTP delete: failed (${ftp_remote_path}${file})");
		}
	}

	function download($file) {
		if( !class_exists('UpdraftPlus_ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

		//handle errors at some point TODO
		$ftp = new UpdraftPlus_ftp_wrapper(UpdraftPlus_Options::get_updraft_option('updraft_server_address'),UpdraftPlus_Options::get_updraft_option('updraft_ftp_login'),UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass'));
		$ftp->passive = true;

		if (!$ftp->connect()) {
			$updraftplus->log("FTP Failure: we did not successfully log in with those credentials.");
			$updraftplus->error("FTP login failure");
			return false;
		}

		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path'));
		$fullpath = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir')).$file;

		$ftp->get($fullpath, $ftp_remote_path.$file, FTP_BINARY);
	}

	public static function config_print_javascript_onready() {
		?>
		jQuery('#updraft-ftp-test').click(function(){
			var data = {
				action: 'updraft_ajax',
				subaction: 'credentials_test',
				method: 'ftp',
				nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>',
				server: jQuery('#updraft_server_address').val(),
				login: jQuery('#updraft_ftp_login').val(),
				pass: jQuery('#updraft_ftp_pass').val(),
				path: jQuery('#updraft_ftp_remote_path').val()
			};
			jQuery.post(ajaxurl, data, function(response) {
					alert('Settings test result: ' + response);
			});
		});
		<?php
	}

	public static function config_print() {
		?>

		<tr class="updraftplusmethod ftp">
			<th></th>
			<td><em><?php echo apply_filters('updraft_sftp_ftps_notice', '<strong>Only non-encrypted FTP is supported by regular UpdraftPlus.</strong> If you want encryption (e.g. you are storing sensitive business data), then <a href="http://updraftplus.com/shop/sftp/">an add-on is available.</a>'); ?></em></td>
		</tr>

		<tr class="updraftplusmethod ftp">
			<th>FTP Server:</th>
			<td><input type="text" size="40" id="updraft_server_address" name="updraft_server_address" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_server_address')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th>FTP Login:</th>
			<td><input type="text" size="40" id="updraft_ftp_login" name="updraft_ftp_login" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_ftp_login')) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th>FTP Password:</th>
			<td><input type="text" size="40" id="updraft_ftp_pass" name="updraft_ftp_pass" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th>Remote Path:</th>
			<td><input type="text" size="64" id="updraft_ftp_remote_path" name="updraft_ftp_remote_path" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path')); ?>" /> <em>Needs to already exist</em></td>
		</tr>
		<tr class="updraftplusmethod ftp">
		<th></th>
		<td><p><button id="updraft-ftp-test" type="button" class="button-primary" style="font-size:18px !important">Test FTP Login</button></p></td>
		</tr>
		<?php
	}

	public static function credentials_test() {

		$server = $_POST['server'];
		$login = $_POST['login'];
		$pass = $_POST['pass'];
		$path = $_POST['path'];

		if (empty($server)) {
			echo "Failure: No server details were given.";
			return;
		}
		if (empty($login)) {
			echo "Failure: No login was given.";
			return;
		}
		if (empty($pass)) {
			echo "Failure: No password was given.";
			return;
		}

		if( !class_exists('UpdraftPlus_ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

		//handle SSL and errors at some point TODO
		$ftp = new UpdraftPlus_ftp_wrapper($server, $login, $pass);
		$ftp->passive = true;

		if (!$ftp->connect()) {
			echo "Failure: we did not successfully log in with those credentials.";
			return;
		}
		//$ftp->make_dir(); we may need to recursively create dirs? TODO

		$file = md5(rand(0,99999999)).'.tmp';
		$fullpath = trailingslashit($path).$file;
		if (!file_exists(ABSPATH.'wp-includes/version.php')) {
			echo "Failure: an unexpected internal UpdraftPlus error occurred when testing the credentials - please contact the developer";
			return;
		}
		if ($ftp->put(ABSPATH.'wp-includes/version.php', $fullpath, FTP_BINARY)) {
			echo "Success: we successfully logged in, and confirmed our ability to create a file in the given directory (login type: ".$ftp->login_type.')';
			@$ftp->delete($fullpath);
		} else {
			echo "Failure: we successfully logged in, but were not able to create a file in the given directory.";
		}

	}

}

?>