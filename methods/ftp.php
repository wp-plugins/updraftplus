<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed.');

# Converted to array options: yes
# Converted to job_options: yes

# Migrate options to new-style storage - May 2014
if (!is_array(UpdraftPlus_Options::get_updraft_option('updraft_ftp')) && '' != UpdraftPlus_Options::get_updraft_option('updraft_server_address', '')) {
	$opts = array(
		'user' => UpdraftPlus_Options::get_updraft_option('updraft_ftp_login'),
		'pass' => UpdraftPlus_Options::get_updraft_option('updraft_ftp_pass'),
		'host' => UpdraftPlus_Options::get_updraft_option('updraft_server_address'),
		'path' => UpdraftPlus_Options::get_updraft_option('updraft_ftp_remote_path')
	);
	UpdraftPlus_Options::update_updraft_option('updraft_ftp', $opts);
	UpdraftPlus_Options::delete_updraft_option('updraft_server_address');
	UpdraftPlus_Options::delete_updraft_option('updraft_ftp_pass');
	UpdraftPlus_Options::delete_updraft_option('updraft_ftp_remote_path');
	UpdraftPlus_Options::delete_updraft_option('updraft_ftp_login');
}

class UpdraftPlus_BackupModule_ftp {

	// Get FTP object with parameters set
	private function getFTP($server, $user, $pass, $disable_ssl = false, $disable_verify = true, $use_server_certs = false, $passive = true) {

		if ('' == $server || '' == $user || '' == $pass) return new WP_Error('no_settings', sprintf(__('No %s settings were found','updraftplus'), 'FTP'));

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

	private function get_opts() {
		global $updraftplus;
		$opts = $updraftplus->get_job_option('updraft_ftp');
		if (!is_array($opts)) $opts = array();
		if (empty($opts['host'])) $opts['host'] = '';
		if (empty($opts['user'])) $opts['user'] = '';
		if (empty($opts['pass'])) $opts['pass'] = '';
		if (empty($opts['path'])) $opts['path'] = '';
		return $opts;
	}

	public function backup($backup_array) {

		global $updraftplus, $updraftplus_backup;

		$opts = $this->get_opts();

		$ftp = $this->getFTP(
			$opts['host'],
			$opts['user'],
			$opts['pass'],
			$updraftplus->get_job_option('updraft_ssl_nossl'),
			$updraftplus->get_job_option('updraft_ssl_disableverify'),
			$updraftplus->get_job_option('updraft_ssl_useservercerts')
		);
		if (is_wp_error($ftp)) return $ftp;

		if (!$ftp->connect()) {
			$updraftplus->log("FTP Failure: we did not successfully log in with those credentials.");
			$updraftplus->log(sprintf(__("%s login failure",'updraftplus'), 'FTP'), 'error');
			return false;
		}

		//$ftp->make_dir(); we may need to recursively create dirs? TODO

		$updraft_dir = $updraftplus->backups_dir_location().'/';

		$ftp_remote_path = trailingslashit($opts['path']);
		foreach($backup_array as $file) {
			$fullpath = $updraft_dir.$file;
			$updraftplus->log("FTP upload attempt: $file -> ftp://".$opts['user']."@".$opts['host']."/${ftp_remote_path}${file}");
			$timer_start = microtime(true);
			$size_k = round(filesize($fullpath)/1024,1);
			# Note :Setting $resume to true unnecessarily is not meant to be a problem. Only ever (Feb 2014) seen one weird FTP server where calling SIZE on a non-existent file did create a problem. So, this code just helps that case. (the check for non-empty upload_status[p] is being cautious.
			$upload_status = $updraftplus->jobdata_get('uploading_substatus');
			if (0 == $updraftplus->current_resumption || (is_array($upload_status) && !empty($upload_status['p']) && $upload_status['p'] == 0)) {
				$resume = false;
			} else {
				$resume = true;
			}

			if ($ftp->put($fullpath, $ftp_remote_path.$file, FTP_BINARY, $resume, $updraftplus)) {
				$updraftplus->log("FTP upload attempt successful (".$size_k."Kb in ".(round(microtime(true)-$timer_start,2)).'s)');
				$updraftplus->uploaded_file($file);
			} else {
				$updraftplus->log("ERROR: FTP upload failed" );
				$updraftplus->log(sprintf(__("%s upload failed",'updraftplus'), 'FTP'), 'error');
			}
		}

		return array('ftp_object' => $ftp, 'ftp_remote_path' => $ftp_remote_path);
	}

	public function listfiles($match = 'backup_') {
		global $updraftplus;

		$opts = $this->get_opts();

		$ftp = $this->getFTP(
			$opts['host'],
			$opts['user'],
			$opts['pass'],
			$updraftplus->get_job_option('updraft_ssl_nossl'),
			$updraftplus->get_job_option('updraft_ssl_disableverify'),
			$updraftplus->get_job_option('updraft_ssl_useservercerts')
		);
		if (is_wp_error($ftp)) return $ftp;

		if (!$ftp->connect()) return new WP_Error('ftp_login_failed', sprintf(__("%s login failure",'updraftplus'), 'FTP'));

		$ftp_remote_path = $opts['path'];
		if ($ftp_remote_path) $ftp_remote_path = trailingslashit($ftp_remote_path);

		$dirlist = $ftp->dir_list($ftp_remote_path);

		if (!is_array($dirlist)) return array();

		$results = array();

		foreach ($dirlist as $k => $path) {

			if ($ftp_remote_path) {
				if (0 !== strpos($path, $ftp_remote_path)) continue;
				$path = substr($path, strlen($ftp_remote_path));
				if (0 === strpos($path, $match)) $results[]['name'] = $path;
			} else {
				if ('/' == substr($path, 0, 1)) $path = substr($path, 1);
				if (false !== strpos($path, '/')) continue;
				if (0 === strpos($path, $match)) $results[]['name'] = $path;
			}

			unset($dirlist[$k]);
		}

		# ftp_nlist() doesn't return file sizes. rawlist() does, but is tricky to parse. So, we get the sizes manually.
		foreach ($results as $ind => $name) {
			$size = $ftp->size($ftp_remote_path.$name['name']);
			if (0 === $size) {
				unset($results[$ind]);
			} elseif ($size>0) {
				$results[$ind]['size'] = $size;
			}
		}

		return $results;

	}

	public function delete($files, $ftparr = array()) {

		global $updraftplus;
		if (is_string($files)) $files=array($files);

		$opts = $this->get_opts();

		if (isset($ftparr['ftp_object'])) {
			$ftp = $ftparr['ftp_object'];
		} else {

			$ftp = $this->getFTP(
				$opts['host'],
				$opts['user'],
				$opts['pass'],
				$updraftplus->get_job_option('updraft_ssl_nossl'),
				$updraftplus->get_job_option('updraft_ssl_disableverify'),
				$updraftplus->get_job_option('updraft_ssl_useservercerts')
			);
			if (is_wp_error($ftp)) return $ftp;

			if (!$ftp->connect()) {
				$updraftplus->log("FTP Failure: we did not successfully log in with those credentials (host=".$opts['host'].").");
				return false;
			}

		}

		$ftp_remote_path = isset($ftparr['ftp_remote_path']) ? $ftparr['ftp_remote_path'] : trailingslashit($opts['path']);

		$ret = true;
		foreach ($files as $file) {
			if (@$ftp->delete($ftp_remote_path.$file)) {
				$updraftplus->log("FTP delete: succeeded (${ftp_remote_path}${file})");
			} else {
				$updraftplus->log("FTP delete: failed (${ftp_remote_path}${file})");
				$ret = false;
			}
		}
		return $ret;

	}

	public function download($file) {

		global $updraftplus;

		$opts = $this->get_opts();

		$ftp = $this->getFTP(
			$opts['host'],
			$opts['user'],
			$opts['pass'],
			$updraftplus->get_job_option('updraft_ssl_nossl'),
			$updraftplus->get_job_option('updraft_ssl_disableverify'),
			$updraftplus->get_job_option('updraft_ssl_useservercerts')
		);
		if (is_wp_error($ftp)) return $ftp;

		if (!$ftp->connect()) {
			$updraftplus->log("FTP Failure: we did not successfully log in with those credentials.");
			$updraftplus->log(sprintf(__("%s login failure",'updraftplus'), 'FTP'), 'error');
			return false;
		}

		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit($opts['path']);
		$fullpath = $updraftplus->backups_dir_location().'/'.$file;

		$resume = false;
		if (file_exists($fullpath)) {
			$resume = true;
			$updraftplus->log("File already exists locally; will resume: size: ".filesize($fullpath));
		}

		return $ftp->get($fullpath, $ftp_remote_path.$file, FTP_BINARY, $resume, $updraftplus);
	}

	public function config_print_javascript_onready() {
		?>
		jQuery('#updraft-ftp-test').click(function(){
			jQuery('#updraft-ftp-test').html('<?php echo esc_js(sprintf(__('Testing %s Settings...', 'updraftplus'),'FTP')); ?>');
				var data = {
				action: 'updraft_ajax',
				subaction: 'credentials_test',
				method: 'ftp',
				nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>',
				server: jQuery('#updraft_ftp_host').val(),
				login: jQuery('#updraft_ftp_user').val(),
				pass: jQuery('#updraft_ftp_pass').val(),
				path: jQuery('#updraft_ftp_path').val(),
				disableverify: (jQuery('#updraft_ssl_disableverify').is(':checked')) ? 1 : 0,
				useservercerts: (jQuery('#updraft_ssl_useservercerts').is(':checked')) ? 1 : 0,
				nossl: (jQuery('#updraft_ssl_nossl').is(':checked')) ? 1 : 0,
			};
			jQuery.post(ajaxurl, data, function(response) {
				jQuery('#updraft-ftp-test').html('<?php echo esc_js(sprintf(__('Test %s Settings', 'updraftplus'),'FTP')); ?>');
				alert('<?php echo esc_js(sprintf(__('%s settings test result:', 'updraftplus'), 'FTP'));?> ' + response);

			});
		});
		<?php
	}

	private function ftp_possible() {
		$funcs_disabled = array();
		foreach (array('ftp_connect', 'ftp_login', 'ftp_nb_fput') as $func) {
			if (!function_exists($func)) $funcs_disabled['ftp'][] = $func;
		}
		$funcs_disabled = apply_filters('updraftplus_ftp_possible', $funcs_disabled);
		return (0 == count($funcs_disabled)) ? true : $funcs_disabled;
	}

	public function config_print() {
		global $updraftplus;

		$possible = $this->ftp_possible();
		if (is_array($possible)) {
			?>
			<tr class="updraftplusmethod ftp">
			<th></th>
			<td>
			<?php
				// Check requirements.
				global $updraftplus_admin;
				$trans = array(
					'ftp' => __('regular non-encrypted FTP', 'updraftplus'),
					'ftpsslimplicit' => __('encrypted FTP (implicit encryption)', 'updraftplus'),
					'ftpsslexplicit' => __('encrypted FTP (explicit encryption)', 'updraftplus')
				);
				foreach ($possible as $type => $missing) {
					$updraftplus_admin->show_double_warning('<strong>'.__('Warning','updraftplus').':</strong> '. sprintf(__("Your web server's PHP installation has these functions disabled: %s.", 'updraftplus'), implode(', ', $missing)).' '.sprintf(__('Your hosting company must enable these functions before %s can work.', 'updraftplus'), $trans[$type]), 'ftp');
				}
			?>
			</td>
			</tr>
			<?php
		}

		$opts = $this->get_opts();

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
			<td><input type="text" size="40" id="updraft_ftp_host" name="updraft_ftp[host]" value="<?php echo htmlspecialchars($opts['host']); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th><?php _e('FTP Login','updraftplus');?>:</th>
			<td><input type="text" size="40" id="updraft_ftp_user" name="updraft_ftp[user]" value="<?php echo htmlspecialchars($opts['user']) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th><?php _e('FTP Password','updraftplus');?>:</th>
			<td><input type="<?php echo apply_filters('updraftplus_admin_secret_field_type', 'text'); ?>" size="40" id="updraft_ftp_pass" name="updraft_ftp[pass]" value="<?php echo htmlspecialchars($opts['pass']); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod ftp">
			<th><?php _e('Remote Path','updraftplus');?>:</th>
			<td><input type="text" size="64" id="updraft_ftp_path" name="updraft_ftp[path]" value="<?php echo htmlspecialchars($opts['path']); ?>" /> <em><?php _e('Needs to already exist','updraftplus');?></em></td>
		</tr>
		<tr class="updraftplusmethod ftp">
		<th></th>
		<td><p><button id="updraft-ftp-test" type="button" class="button-primary" style="font-size:18px !important"><?php echo sprintf(__('Test %s Settings','updraftplus'),'FTP');?></button></p></td>
		</tr>
		<?php
	}

	public function get_credentials() {
		return array('updraft_ftp', 'updraft_ssl_disableverify', 'updraft_ssl_nossl', 'updraft_ssl_useservercerts');
	}

	public function credentials_test() {

		$server = $_POST['server'];
		$login = stripslashes($_POST['login']);
		$pass = stripslashes($_POST['pass']);
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

		if (preg_match('#ftp(es|s)?://(.*)#i', $server, $matches)) $server = untrailingslashit($matches[2]);

		$ftp = $this->getFTP($server, $login, $pass, $nossl, $disable_verify, $use_server_certs);

		if (!$ftp->connect()) {
			_e('Failure: we did not successfully log in with those credentials.', 'updraftplus');
			return;
		}
		//$ftp->make_dir(); we may need to recursively create dirs? TODO

		$file = md5(rand(0,99999999)).'.tmp';
		$fullpath = trailingslashit($path).$file;
		if (!file_exists(ABSPATH.WPINC.'/version.php')) {
			_e("Failure: an unexpected internal UpdraftPlus error occurred when testing the credentials - please contact the developer");
			return;
		}
		if ($ftp->put(ABSPATH.WPINC.'/version.php', $fullpath, FTP_BINARY, false, true)) {
			echo __("Success: we successfully logged in, and confirmed our ability to create a file in the given directory (login type:",'updraftplus')." ".$ftp->login_type.')';
			@$ftp->delete($fullpath);
		} else {
			_e('Failure: we successfully logged in, but were not able to create a file in the given directory.');
		}

	}

}
