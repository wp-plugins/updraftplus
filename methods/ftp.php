<?php

class UpdraftPlus_BackupModule_ftp {

	// Get FTP object with parameters set
	function getFTP($server, $user, $pass, $disable_ssl = false, $disable_verify = true, $use_server_certs = false, $passive = true) {

		if( !class_exists('UpdraftPlus_ftp_wrapper')) require_once(UPDRAFTPLUS_DIR.'/includes/ftp.class.php');

		$port = 21;
		if (preg_match('/^(.*):(\d+)$/', $server, $matches)) {
			$server = $matches[1];
			$port = $matches[2];
		}

		$ftp = new UpdraftPlus_ftp_wrapper($server, $user, $pass, $port);

		if ($disable_ssl) $ftp->ssl = false;
		$ftp->use_server_certs = $use_server_certs;
		$ftp->disable_verify = $disable_verify;
		if ($passive) $ftp->passive = true;

		return $ftp;

	}

	function backup($backup_array) {

		global $updraftplus;

		$server = UpdraftPlus_Options::get_updraft_option('updraft_server_address');
		$user = UpdraftPlus_Options::get_updraft_option('updraft_ftp_login');

		$ftp = $this->getFTP(
			$server,
			$user,
			UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_nossl'),
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'),
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')
		);

		if (!$ftp->connect()) {
			$updraftplus->log("FTP Failure: we did not successfully log in with those credentials.");
			$updraftplus->error(__("FTP login failure",'updraftplus'));
			return false;
		}

		//$ftp->make_dir(); we may need to recursively create dirs? TODO

		$updraft_dir = $updraftplus->backups_dir_location().'/';

		$ftp_remote_path = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path'));
		foreach($backup_array as $file) {
			$fullpath = $updraft_dir.$file;
			$updraftplus->log("FTP upload attempt: $file -> ftp://$user@$server/${ftp_remote_path}${file}");
			$timer_start = microtime(true);
			$size_k = round(filesize($fullpath)/1024,1);
			if ($ftp->put($fullpath, $ftp_remote_path.$file, FTP_BINARY, true, $updraftplus)) {
				$updraftplus->log("FTP upload attempt successful (".$size_k."Kb in ".(round(microtime(true)-$timer_start,2)).'s)');
				$updraftplus->uploaded_file($file);
			} else {
				$updraftplus->log("ERROR: FTP upload failed" );
				$updraftplus->error(__("FTP upload failed",'updraftplus'));
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

		global $updraftplus;

		$ftp = $this->getFTP(
			UpdraftPlus_Options::get_updraft_option('updraft_server_address'),
			UpdraftPlus_Options::get_updraft_option('updraft_ftp_login'),
			UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_nossl'),
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'),
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')
		);

		if (!$ftp->connect()) {
			$updraftplus->log("FTP Failure: we did not successfully log in with those credentials.");
			$updraftplus->error(__("FTP login failure",'updraftplus'));
			return false;
		}

		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path'));
		$fullpath = $updraftplus->backups_dir_location().'/'.$file;

		$resume = false;
		if (file_exists($fullpath)) {
			$resume = true;
			$updraftplus->log("File already exists locally; will resume: size: ".filesize($fullpath));
		}

		$ftp->get($fullpath, $ftp_remote_path.$file, FTP_BINARY, $resume, $updraftplus);
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
				path: jQuery('#updraft_ftp_remote_path').val(),
				disableverify: (jQuery('#updraft_ssl_disableverify').is(':checked')) ? 1 : 0,
				useservercerts: (jQuery('#updraft_ssl_useservercerts').is(':checked')) ? 1 : 0,
				nossl: (jQuery('#updraft_ssl_nossl').is(':checked')) ? 1 : 0,
			};
			jQuery.post(ajaxurl, data, function(response) {
				alert('<?php _e('Settings test result','updraftplus');?>: ' + response);
			});
		});
		<?php
	}

	public static function config_print() {
		?>

		<tr class="updraftplusmethod ftp">
			<td></td>
			<td><p><em><?php printf(__('%s is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your site is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.','updraftplus'),'FTP');?></em></p></td>
		</tr>

		<tr class="updraftplusmethod ftp">
			<th></th>
			<td><em><?php echo apply_filters('updraft_sftp_ftps_notice', '<strong>'.htmlspecialchars(__('Only non-encrypted FTP is supported by regular UpdraftPlus.')).'</strong> <a href="http://updraftplus.com/shop/sftp/">'.__('If you want encryption (e.g. you are storing sensitive business data), then an add-on is available.','updraftplus')).'</a>'; ?></em></td>
		</tr>

		<tr class="updraftplusmethod ftp">
			<th><?php _e('FTP Server','updraftplus');?>:</th>
			<td><input type="text" size="40" id="updraft_server_address" name="updraft_server_address" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_server_address')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th><?php _e('FTP Login','updraftplus');?>:</th>
			<td><input type="text" size="40" id="updraft_ftp_login" name="updraft_ftp_login" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_ftp_login')) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th><?php _e('FTP Password','updraftplus');?>:</th>
			<td><input type="text" size="40" id="updraft_ftp_pass" name="updraft_ftp_pass" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th><?php _e('Remote Path','updraftplus');?>:</th>
			<td><input type="text" size="64" id="updraft_ftp_remote_path" name="updraft_ftp_remote_path" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path')); ?>" /> <em><?php _e('Needs to already exist','updraftplus');?></em></td>
		</tr>
		<tr class="updraftplusmethod ftp">
		<th></th>
		<td><p><button id="updraft-ftp-test" type="button" class="button-primary" style="font-size:18px !important"><?php echo sprintf(__('Test %s Settings','updraftplus'),'FTP');?></button></p></td>
		</tr>
		<?php
	}

	public static function credentials_test() {

		$server = $_POST['server'];
		$login = $_POST['login'];
		$pass = $_POST['pass'];
		$path = $_POST['path'];
		$nossl = $_POST['nossl'];

		$disable_verify = $_POST['disableverify'];
		$use_server_certs = $_POST['useservercerts'];

		if (empty($server)) {
			_e("Failure: No server details were given.",'updraftplus');
			return;
		}
		if (empty($login)) {
			printf(__("Failure: No %s was given.",'updraftplus'),'login');
			return;
		}
		if (empty($pass)) {
			printf(__("Failure: No %s was given.",'updraftplus'),'password');
			return;
		}

		$ftp = self::getFTP($server, $login, $pass, $nossl, $disable_verify, $use_server_certs);

		if (!$ftp->connect()) {
			_e("Failure: we did not successfully log in with those credentials.",'updraftplus');
			return;
		}
		//$ftp->make_dir(); we may need to recursively create dirs? TODO

		$file = md5(rand(0,99999999)).'.tmp';
		$fullpath = trailingslashit($path).$file;
		if (!file_exists(ABSPATH.'wp-includes/version.php')) {
			_e("Failure: an unexpected internal UpdraftPlus error occurred when testing the credentials - please contact the developer");
			return;
		}
		if ($ftp->put(ABSPATH.'wp-includes/version.php', $fullpath, FTP_BINARY, false, true)) {
			echo __("Success: we successfully logged in, and confirmed our ability to create a file in the given directory (login type:",'updraftplus')." ".$ftp->login_type.')';
			@$ftp->delete($fullpath);
		} else {
			_e('Failure: we successfully logged in, but were not able to create a file in the given directory.');
		}

	}

}

?>