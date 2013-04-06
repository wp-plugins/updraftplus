<?php

// TODO: Chunking and resuming on uploading is not yet tested

class UpdraftPlus_BackupModule_cloudfiles {

	function backup($backup_array) {

		global $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location().'/';
		$path = untrailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_path'));
		$authurl = UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_authurl', 'https://auth.api.rackspacecloud.com');

		$updraftplus->log("Cloud Files authentication URL: $authurl");

		if (preg_match("#^([^/]+)/(.*)$#", $path, $bmatches)) {
			$container = $bmatches[1];
			$path = $bmatches[2];
		} else {
			$container = $path;
			$path = "";
		}

		if(!class_exists('CF_Authentication')) require_once(UPDRAFTPLUS_DIR.'/includes/cloudfiles/cloudfiles.php');

		$user = UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_user');

		$auth = new CF_Authentication($user, UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_apikey'), NULL, $authurl);
		try {
			$auth->authenticate();
		} catch(Exception $e) {
			$updraftplus->log('Cloud Files authentication failed ('.$e->getMessage().')');
			$updraftplus->error(__('Cloud Files authentication failed','updraftplus').' ('.$e->getMessage().')');
		}

		try {
			$conn = new CF_Connection($auth);
			if (!UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')) {
				$conn->ssl_use_cabundle(UPDRAFTPLUS_DIR.'/includes/cacert.pem');
			}
			$cont_obj = $conn->create_container($container);
		} catch (Exception $e) {
			$updraftplus->log('Cloud Files error - failed to create and access the container ('.$e->getMessage().')');
			$updraftplus->error(__('Cloud Files error - failed to create and access the container', 'updraftplus').' ('.$e->getMessage().')');
			return;
		}

		$chunk_size = 0.2*1024*1024;

		foreach($backup_array as $key => $file) {

			$fullpath = $updraft_dir.$file;
			$orig_file_size = filesize($fullpath);

			$cfpath = ($path == '') ? $file : "$path/$file";

			try {
				$object = new CF_Object($cont_obj, $cfpath);
				$object->content_type = "application/zip";

				$uploaded_size = (isset($object->content_length)) ? $object->content_length : 0;

				if ($uploaded_size <= $orig_file_size) {

					$fp = @fopen($fullpath, "rb");
					if (!$fp) {
						$updraftplus->log("Cloud Files: failed to open file: $fullpath");
						$updraftplus->error("$file: ".sprintf(__('%s Error: Failed to open local file','updraftplus'),'Cloud Files'));
						return false;
					}

					$chunks = floor($orig_file_size / $chunk_size);
					// There will be a remnant unless the file size was exactly on a 5Mb boundary
					if ($orig_file_size % $chunk_size > 0 ) $chunks++;

					$updraftplus->log("Cloud Files upload: $file (chunks: $chunks) -> cloudfiles://$user@$container/$cfpath ($uploaded_size)");

					if ($chunks < 2) {
						if (!$object->load_from_filename($fullpath)) {
							$updraftplus->log("Cloud Files regular upload: failed ($fullpath)");
							$updraftplus->error("$file: ".sprintf(__('%s Error: Failed to upload','updraftplus'),'Cloud Files'));
						} else {
							$updraftplus->log("Cloud Files regular upload: success");
							$updraftplus->uploaded_file($file);
						}
					} else {
						$errors_so_far = 0;
						for ($i = 1 ; $i <= $chunks; $i++) {
							$upload_start = ($i-1)*$chunk_size;
							$upload_end = max($i*$chunk_size-1, $orig_file_size);
							$upload_remotepath = $cfpath."_$i";
							$upload_size = $upload_end - $upload_start;

							$chunk_object = new CF_Object($cont_obj, $upload_remotepath);
							$chunk_object->content_type = "application/zip";

							$remote_size = (isset($chunk_object->content_length)) ? $chunk_object->content_length : 0;

							if ($remote_size >= $upload_size) {
								$updraftplus->log("Cloud Files: Chunk $i: already uploaded");
							} else {
								$updraftplus->log("Cloud Files: Chunk $i: begin upload");
								// Upload the chunk
								fseek($fp, $upload_start);
								try {
									$chunk_object->write($fp, $upload_size, false);
									$updraftplus->record_uploaded_chunk(round(100*$i/$chunks,1), $i, $fullpath);
								} catch (Exception $e) {
									$updraftplus->log("Cloud Files chunk upload: failed ($file / $i) (".$e->getMessage().")");
									$updraftplus->error("$file: ".sprintf(__('%s Error: Failed to upload','updraftplus'),'Cloud Files'));
									$errors_so_far++;
									if ($errors_so_far >=3 ) return false;
								}
							}
						}
						if ($errors_so_far) return false;
						// All chunks are uploaded - now upload the manifest
						$object->manifest = $container."/".$cfpath."_";
						try {
							$object->sync_manifest();
// 						} catch (InvalidResponseException $e) {
						} catch (Exception $e) {
							$updraftplus->log('Cloud Files error - failed to re-assemble chunks ('.$e->getMessage().')');
							$updraftplus->error(__('Cloud Files error - failed to re-assemble chunks', 'updraftplus').' ('.$e->getMessage().')');
						}
					}

				}

				$updraftplus->log("Cloud Files upload: success");
				$updraftplus->uploaded_file($file);

			} catch (Exception $e) {
				$updraftplus->log(__('Cloud Files error - failed to upload file', 'updraftplus').' ('.$e->getMessage().')');
				$updraftplus->error(__('Cloud Files error - failed to upload file', 'updraftplus').' ('.$e->getMessage().')');
				return;
			}

		}

		$updraftplus->prune_retained_backups('cloudfiles', $this, array('cloudfiles_object' => $cont_obj, 'cloudfiles_orig_path' => $path, 'cloudfiles_container' => $container));

	}

	function delete($file, $cloudfilesarr) {

		global $updraftplus;

		$cont_obj = $cloudfilesarr['cloudfiles_object'];
		$container = $cloudfilesarr['cloudfiles_container'];
		$path = $cloudfilesarr['cloudfiles_orig_path'];
		$fpath = ($path == '') ? $file : "$path/$file";

		$updraftplus->log("Cloud Files: Delete remote: container=$container, path=$fpath");

		try {
			if (!$cont_obj->delete_object($fpath)) {
				$updraftplus->log("Cloud Files: Delete failed");
			}
		} catch  (Exception $e) {
			$updraftplus->log('Cloud Files delete failed: '.$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
		}

	}

	function download($file) {

		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();

		if(!class_exists('CF_Authentication')) require_once(UPDRAFTPLUS_DIR.'/includes/cloudfiles/cloudfiles.php');

		$user = UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_user');
		$authurl = UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_authurl', 'https://auth.api.rackspacecloud.com');

		$auth = new CF_Authentication($user, UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_apikey'), NULL, $authurl);

		try {
			$auth->authenticate();
		} catch(Exception $e) {
			$updraftplus->error(__('Cloud Files authentication failed','updraftplus').' ('.$e->getMessage().')');
		}

		$conn = new CF_Connection($auth);
		if (!UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts')) {
			$conn->ssl_use_cabundle(UPDRAFTPLUS_DIR.'/includes/cacert.pem');
		}

		$path = untrailingslashit(get_option('updraft_cloudfiles_path'));

		if (preg_match("#^([^/]+)/(.*)$#", $path, $bmatches)) {
			$container = $bmatches[1];
			$path = $bmatches[2];
		} else {
			$container = $path;
			$path = "";
		}

		try {
			$cont_obj = $conn->create_container($container);
		} catch(Exception $e) {
			$updraftplus->error(__('Cloud Files error - failed to create and access the container','updraftplus').' ('.$e->getMessage().')');
		}

		$path = ($path == '') ? $file : "$path/$file";

		$updraftplus->log("Cloud Files download: cloudfiles://$user@$container/$path");

		try {
			// The third parameter causes an exception to be thrown if the object does not exist remotely
			$object = new CF_Object($cont_obj, $path, true);
			
			$fullpath = $updraft_dir.'/'.$file;

			$start_offset =  (file_exists($fullpath)) ? filesize($fullpath): 0;

			// Get file size from remote - see if we've already finished

			$remote_size = $object->content_length;

			if ($start_offset >= $remote_size) {
				$updraftplus->log("Cloud Files: file is already completely downloaded ($start_offset/$remote_size)");
				return true;
			}

			// Some more remains to download - so let's do it
			if (!$fh = fopen($fullpath, 'a')) {
				$updraftplus->log("Cloud Files: Error opening local file: $fullpath");
				$updraftplus->error("$file: ".__("Cloud Files Error",'updraftplus').": ".__('Error opening local file: Failed to download','updraftplus'));
				return false;
			}

			$headers = array();
			// If resuming, then move to the end of the file
			if ($start_offset) {
				$updraftplus->log("Cloud Files: local file is already partially downloaded ($start_offset/$remote_size)");
				fseek($fh, $start_offset);
				$headers['Range'] = "bytes=$start_offset-";
			}

			// Now send the request itself
			try {
				$object->stream($fh, $headers);
			} catch (Exception $e) {
				$updraftplus->log("Cloud Files: Failed to download: $file (".$e->getMessage().")");
				$updraftplus->error("$file: ".__("Cloud Files Error",'updraftplus').": ".__('Error downloading remote file: Failed to download'.' ('.$e->getMessage().")",'updraftplus'));
				return false;
			}
			
			// All-in-one-go method:
			// $object->save_to_filename($fullpath);

		} catch (NoSuchObjectException $e) {
			$updraftplus->log(__('Cloud Files error - no such file exists at Cloud Files','updraftplus').' ('.$e->getMessage().')');
			$updraftplus->error(__('Cloud Files error - no such file exists at Cloud Files)','updraftplus').' ('.$e->getMessage().')');
			return false;
		} catch(Exception $e) {
			$updraftplus->log(__('Cloud Files error - failed to download the file','updraftplus').' ('.$e->getMessage().')');
			$updraftplus->error(__('Cloud Files error - failed to download the file','updraftplus').' ('.$e->getMessage().')');
			return false;
		}

	}

	public static function config_print_javascript_onready() {
		?>
		jQuery('#updraft-cloudfiles-test').click(function(){
			var data = {
				action: 'updraft_ajax',
				subaction: 'credentials_test',
				method: 'cloudfiles',
				nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>',
				apikey: jQuery('#updraft_cloudfiles_apikey').val(),
				user: jQuery('#updraft_cloudfiles_user').val(),
				path: jQuery('#updraft_cloudfiles_path').val(),
				authurl: jQuery('#updraft_cloudfiles_authurl').val(),
				useservercerts: jQuery('#updraft_cloudfiles_useservercerts').val(),
				disableverify: jQuery('#updraft_ssl_disableverify').val()
			};
			jQuery.post(ajaxurl, data, function(response) {
				alert('Settings test result: ' + response);
			});
		});
		<?php
	}

	public static function config_print() {

		?>
		<tr class="updraftplusmethod cloudfiles">
			<td></td>
			<td><img alt="Rackspace Cloud Files" src="<?php echo UPDRAFTPLUS_URL.'/images/rackspacecloud-logo.png' ?>">
				<p><em>Chunked uploading is not yet supported, so your web server must allow PHP to run long enough to upload the largest archive.<!-- <?php printf(__('%s is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your blog is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.','updraftplus'),'Rackspace Cloud Files');?> --></em></p></td>
		</tr>



		<tr class="updraftplusmethod cloudfiles">
		<th></th>
			<td>
				<p><?php _e('Get your API key <a href="https://mycloud.rackspace.com/">from your Rackspace Cloud console</a> (read instructions <a href="http://www.rackspace.com/knowledge_center/article/rackspace-cloud-essentials-1-generating-your-api-key">here</a>), then pick a container name to use for storage. This container will be created for you if it does not already exist.','updraftplus');?></p>
			</td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('US or UK Cloud','updraftplus');?>:</th>
			<td>
				<?php
					$authurl = UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_authurl');
				?>
				<select id="updraft_cloudfiles_authurl" name="updraft_cloudfiles_authurl">
					<option <?php if ($authurl !='https://lon.auth.api.rackspacecloud.com') echo 'selected="selected"'; ?> value="https://auth.api.rackspacecloud.com"><?php _e('US (default)','updraftplus'); ?></option>
					<option <?php if ($authurl =='https://lon.auth.api.rackspacecloud.com') echo 'selected="selected"'; ?> value="https://lon.auth.api.rackspacecloud.com"><?php _e('UK', 'updraftplus'); ?></option>
				</select>
			</td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('Cloud Files username','updraftplus');?>:</th>
			<td><input type="text" autocomplete="off" style="width: 252px" id="updraft_cloudfiles_user" name="updraft_cloudfiles_user" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_user')) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('Cloud Files API key','updraftplus');?>:</th>
			<td><input type="text" autocomplete="off" style="width: 252px" id="updraft_cloudfiles_apikey" name="updraft_cloudfiles_apikey" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_apikey')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('Cloud Files container','updraftplus');?>:</th>
			<td><input type="text" style="width: 252px" name="updraft_cloudfiles_path" id="updraft_cloudfiles_path" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_cloudfiles_path')); ?>" /></td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
			<th></th>
			<td>
			<?php
			// Check requirements.
			if (!function_exists('mb_substr')) {
				?><p><strong><?php _e('Warning','updraftplus'); ?>:</strong> <?php echo sprintf(__('Your web server\'s PHP installation does not included a required module (%s). Please contact your web hosting provider\'s support.', 'updraftplus'), 'mbstring'); ?> <?php echo sprintf(__("UpdraftPlus's %s module <strong>requires</strong> %s. Please do not file any support requests; there is no alternative.",'updraftplus'),'Cloud Files', 'mbstring');?></p><?php
			}
			global $updraftplus_admin;
			$updraftplus_admin->curl_check('Rackspace Cloud Files', false);
			?>
			</td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
		<th></th>
		<td><p><button id="updraft-cloudfiles-test" type="button" class="button-primary" style="font-size:18px !important"><?php echo sprintf(__('Test %s Settings','updraftplus'),'Cloud Files');?></button></p></td>
		</tr>
	<?php
	}

	public static function credentials_test() {

		if (empty($_POST['apikey'])) {
			printf(__("Failure: No %s was given.",'updraftplus'),__('API key','updraftplus'));
			return;
		}

		if (empty($_POST['user'])) {
			printf(__("Failure: No %s was given.",'updraftplus'),__('Username','updraftplus'));
			return;
		}

		$key = $_POST['apikey'];
		$user = $_POST['user'];
		$path = $_POST['path'];
		$authurl = $_POST['authurl'];
		$useservercerts = $_POST['useservercerts'];
		$disableverify = $_POST['disableverify'];

		if (preg_match("#^([^/]+)/(.*)$#", $path, $bmatches)) {
			$container = $bmatches[1];
			$path = $bmatches[2];
		} else {
			$container = $path;
			$path = "";
		}

		if (empty($container)) {
			_e("Failure: No container details were given.",'updraftplus');
			return;
		}

		if (!class_exists('CF_Authentication')) require_once(UPDRAFTPLUS_DIR.'/includes/cloudfiles/cloudfiles.php');

		$auth = new CF_Authentication($user, $key, NULL, $authurl);

		try {
			$auth->authenticate();
		} catch(Exception $e) {
			echo __('Cloud Files authentication failed', 'updraftplus').' ('.$e->getMessage().')';
			die;
		}

		try {
			$conn = new CF_Connection($auth);
			if (!$useservercerts) {
				$conn->ssl_use_cabundle(UPDRAFTPLUS_DIR.'/includes/cacert.pem');
			}
			$cont_obj = $conn->create_container($container);
		} catch (Exception $e) {
			echo __('Cloud Files error - failed to create and access the container', 'updraftplus').' ('.$e->getMessage().')';
			return;
		}

		$try_file = md5(rand());

		try {
			$object = $cont_obj->create_object($try_file);
			$object->write('UpdraftPlus test file');
		} catch (Exception $e) {
			echo __('Cloud Files error - we accessed the container, but failed to create a file within it', 'updraftplus').' ('.$e->getMessage().')';
			return;
		}

		echo  __('Success','updraftplus').": ${container_verb}".__('We accessed the container, and were able to create files within it.','updraftplus');

		@$cont_obj->delete_object($try_file);
	}

}
?>
