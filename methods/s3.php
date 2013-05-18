<?php

class UpdraftPlus_BackupModule_s3 {

	function get_config() {
		return array(
			'login' => UpdraftPlus_Options::get_updraft_option('updraft_s3_login'),
			'pass' => UpdraftPlus_Options::get_updraft_option('updraft_s3_pass'),
			'remote_path' => UpdraftPlus_Options::get_updraft_option('updraft_s3_remote_path'),
			'whoweare' => 'S3',
			'whoweare_long' => 'Amazon S3',
			'key' => 's3'
		);
	}

	// Get an S3 object, after setting our options
	function getS3($key, $secret, $useservercerts, $disableverify, $nossl) {
		global $updraftplus;

		if (!class_exists('S3')) require_once(UPDRAFTPLUS_DIR.'/includes/S3.php');

		$s3 = new S3($key, $secret);
		if (!$nossl) {
			$curl_version = (function_exists('curl_version')) ? curl_version() : array('features' => null);
			$curl_ssl_supported= ($curl_version['features'] & CURL_VERSION_SSL);
			if ($curl_ssl_supported) {
				$s3->useSSL = true;
				if ($disableverify) {
					$s3->useSSLValidation = false;
					$updraftplus->log("S3: Disabling verification of SSL certificates");
				}
				if ($useservercerts) {
					$updraftplus->log("S3: Using the server's SSL certificates");
				} else {
					$s3->SSLCACert = UPDRAFTPLUS_DIR.'/includes/cacert.pem';
				}
			} else {
				$updraftplus->log("S3: Curl/SSL is not available. Communications will not be encrypted.");
			}
		} else {
			$s3->useSSL = false;
			$updraftplus->log("SSL was disabled via the user's preference. Communications will not be encrypted.");
		}
		return $s3;
	}

	function set_endpoint($obj, $region) {
		switch ($region) {
			case 'EU':
			case 'eu-west-1':
				$endpoint = 's3-eu-west-1.amazonaws.com';
				break;
			case 'us-west-1':
			case 'us-west-2':
			case 'ap-southeast-1':
			case 'ap-southeast-2':
			case 'ap-northeast-1':
			case 'sa-east-1':
				$endpoint = 's3-'.$region.'.amazonaws.com';
				break;
			default:
				break;
		}
		if (isset($endpoint)) {
			$obj->setEndpoint($endpoint);
		}
	}


	function backup($backup_array) {

		global $updraftplus;

		$config = $this->get_config();
		$whoweare = $config['whoweare'];
		$whoweare_key = $config['key'];
		$whoweare_keys = substr($whoweare_key, 0, 1);

		$s3 = $this->getS3(
			$config['login'],
			$config['pass'],
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'),
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_nossl')
		);

		$bucket_name = untrailingslashit($config['remote_path']);
		$bucket_path = "";
		$orig_bucket_name = $bucket_name;

		if (preg_match("#^([^/]+)/(.*)$#",$bucket_name,$bmatches)) {
			$bucket_name = $bmatches[1];
			$bucket_path = $bmatches[2]."/";
		}

		$region = ($config['key'] == 'dreamobjects') ? $config['whoweare'] : @$s3->getBucketLocation($bucket);

		// See if we can detect the region (which implies the bucket exists and is ours), or if not create it
		if (!empty($region) || @$s3->putBucket($bucket_name, S3::ACL_PRIVATE)) {

			if (empty($region)) $region = $s3->getBucketLocation($bucket_name);
			$this->set_endpoint($s3, $region);

			$updraft_dir = $updraftplus->backups_dir_location().'/';

			foreach($backup_array as $key => $file) {

				// We upload in 5Mb chunks to allow more efficient resuming and hence uploading of larger files
				// N.B.: 5Mb is Amazon's minimum. So don't go lower or you'll break it.
				$fullpath = $updraft_dir.$file;
				$orig_file_size = filesize($fullpath);
				$chunks = floor($orig_file_size / 5242880);
				// There will be a remnant unless the file size was exactly on a 5Mb boundary
				if ($orig_file_size % 5242880 > 0 ) $chunks++;
				$hash = md5($file);

				$updraftplus->log("$whoweare upload ($region): $file (chunks: $chunks) -> s3://$bucket_name/$bucket_path$file");

				$filepath = $bucket_path.$file;

				// This is extra code for the 1-chunk case, but less overhead (no bothering with transients)
				if ($chunks < 2) {
					if (!$s3->putObjectFile($fullpath, $bucket_name, $filepath)) {
						$updraftplus->log("$whoweare regular upload: failed ($fullpath)");
						$updraftplus->error("$file: ".sprintf(__('%s Error: Failed to upload','updraftplus'),$whoweare));
					} else {
						$updraftplus->log("$whoweare regular upload: success");
						$updraftplus->uploaded_file($file);
					}
				} else {

					// Retrieve the upload ID
					$uploadId = get_transient("upd_${whoweare_keys}_${hash}_uid");
					if (empty($uploadId)) {
						$s3->setExceptions(true);
						try {
							$uploadId = $s3->initiateMultipartUpload($bucket_name, $filepath);
						} catch (Exception $e) {
							$updraftplus->log("$whoweare error whilst trying initiateMultipartUpload: ".$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
							$uploadId = false;
						}
						$s3->setExceptions(false);

						if (empty($uploadId)) {
							$updraftplus->log("$whoweare upload: failed: could not get uploadId for multipart upload ($filepath)");
							$updraftplus->error(sprintf(__("%s upload: getting uploadID for multipart upload failed - see log file for more details",'updraftplus'),$whoweare));
							continue;
						} else {
							$updraftplus->log("$whoweare chunked upload: got multipart ID: $uploadId");
							set_transient("upd_${whoweare_keys}_${hash}_uid", $uploadId, UPDRAFT_TRANSTIME);
						}
					} else {
						$updraftplus->log("$whoweare chunked upload: retrieved previously obtained multipart ID: $uploadId");
					}

					$successes = 0;
					$etags = array();
					for ($i = 1 ; $i <= $chunks; $i++) {
						# Shorted to upd here to avoid hitting the 45-character limit
						$etag = get_transient("ud_${whoweare_keys}_${hash}_e$i");
						if (strlen($etag) > 0) {
							$updraftplus->log("$whoweare chunk $i: was already completed (etag: $etag)");
							$successes++;
							array_push($etags, $etag);
						} else {
							// Sanity check: we've seen a case where an overlap was truncating the file from underneath us
							if (filesize($fullpath) < $orig_file_size) {
								$updraftplus->log("$whoweare error: $key: chunk $i: file was truncated underneath us (orig_size=$orig_file_size, now_size=".filesize($fullpath).")");
								$updraftplus->error(sprintf(__('%s error: file %s was shortened unexpectedly', 'updraftplus'), $whoweare, $fullpath));
							}
							$etag = $s3->uploadPart($bucket_name, $filepath, $uploadId, $fullpath, $i);
							if ($etag !== false && is_string($etag)) {
								$updraftplus->record_uploaded_chunk(round(100*$i/$chunks,1), "$i, $etag", $fullpath);
								array_push($etags, $etag);
								set_transient("ud_${whoweare_keys}_${hash}_e$i", $etag, UPDRAFT_TRANSTIME);
								$successes++;
							} else {
								$updraftplus->log("$whoweare chunk $i: upload failed");
								$updraftplus->error(sprintf(__("%s chunk %s: upload failed",'updraftplus'),$whoweare, $i));
							}
						}
					}
					if ($successes >= $chunks) {
						$updraftplus->log("$whoweare upload: all chunks uploaded; will now instruct $whoweare to re-assemble");

						$s3->setExceptions(true);
						try {
							if ($s3->completeMultipartUpload($bucket_name, $filepath, $uploadId, $etags)) {
								$updraftplus->log("$whoweare upload ($key): re-assembly succeeded");
								$updraftplus->uploaded_file($file);
							} else {
								$updraftplus->log("$whoweare upload ($key): re-assembly failed ($file)");
								$updraftplus->error(sprintf(__('%s upload (%s): re-assembly failed (see log for more details)','updraftplus'),$whoweare, $key));
							}
						} catch (Exception $e) {
							$updraftplus->log("$whoweare re-assembly error ($key): ".$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
							$updraftplus->error($e->getMessage().": ".sprint(__('%s re-assembly error (%s): (see log file for more)','updraftplus'),$whoweare, $e->getMessage()));
						}
						// Remember to unset, as the deletion code later reuses the object
						$s3->setExceptions(false);
					} else {
						$updraftplus->log("$whoweare upload: upload was not completely successful on this run");
					}
				}
			}
			$updraftplus->prune_retained_backups($config['key'], $this, array('s3_object' => $s3, 's3_orig_bucket_name' => $orig_bucket_name));
		} else {
			$updraftplus->log("$whoweare Error: Failed to create bucket $bucket_name.");
			$updraftplus->error(sprintf(__('%s Error: Failed to create bucket %s. Check your permissions and credentials.','updraftplus'),$whoweare, $bucket_name));
		}
	}

	function delete($file, $s3arr) {

		global $updraftplus;

		$config = $this->get_config();
		$whoweare = $config['whoweare'];

		$s3 = $s3arr['s3_object'];
		$orig_bucket_name = $s3arr['s3_orig_bucket_name'];

		if (preg_match("#^([^/]+)/(.*)$#", $orig_bucket_name, $bmatches)) {
			$s3_bucket=$bmatches[1];
			$s3_uri = $bmatches[2]."/".$file;
		} else {
			$s3_bucket = $orig_bucket_name;
			$s3_uri = $file;
		}
		$updraftplus->log("$whoweare: Delete remote: bucket=$s3_bucket, URI=$s3_uri");

		$s3->setExceptions(true);
		try {
			if (!$s3->deleteObject($s3_bucket, $s3_uri)) {
				$updraftplus->log("$whoweare: Delete failed");
			}
		} catch  (Exception $e) {
			$updraftplus->log("$whoweare delete failed: ".$e->getMessage().' (line: '.$e->getLine().', file: '.$e->getFile().')');
		}
		$s3->setExceptions(false);

	}

	function download($file) {

		global $updraftplus;

		$config = $this->get_config();
		$whoweare = $config['whoweare'];

		$s3 = $this->getS3(
			$config['login'],
			$config['pass'],
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'),
			UpdraftPlus_Options::get_updraft_option('updraft_ssl_nossl')
		);

		$bucket_name = untrailingslashit($config['remote_path']);
		$bucket_path = "";

		if (preg_match("#^([^/]+)/(.*)$#", $bucket_name, $bmatches)) {
			$bucket_name = $bmatches[1];
			$bucket_path = $bmatches[2]."/";
		}

		$region = ($config['key'] == 'dreamobjects') ? $config['whoweare'] : @$s3->getBucketLocation($bucket);
		if (!empty($region)) {
			$this->set_endpoint($s3, $region);
			$fullpath = $updraftplus->backups_dir_location().'/'.$file;
			if (!$s3->getObject($bucket_name, $bucket_path.$file, $fullpath, true)) {
				$updraftplus->log("$whoweare Error: Failed to download $file. Check your permissions and credentials.");
				$updraftplus->error(sprintf(__('%s Error: Failed to download %s. Check your permissions and credentials.','updraftplus'),$whoweare, $file));
			}
		} else {
			$updraftplus->log("$whoweare Error: Failed to access bucket $bucket_name. Check your permissions and credentials.");
			$updraftplus->error(sprintf(__('%s Error: Failed to access bucket %s. Check your permissions and credentials.','updraftplus'),$whoweare, $bucket_name));
		}

	}

	public static function config_print_javascript_onready() {
		self::config_print_javascript_onready_engine('s3');
	}

	public static function config_print_javascript_onready_engine($key) {

		?>
		jQuery('#updraft-<?php echo $key; ?>-test').click(function(){
			var data = {
				action: 'updraft_ajax',
				subaction: 'credentials_test',
				method: '<?php echo $key; ?>',
				nonce: '<?php echo wp_create_nonce('updraftplus-credentialtest-nonce'); ?>',
				apikey: jQuery('#updraft_<?php echo $key; ?>_apikey').val(),
				apisecret: jQuery('#updraft_<?php echo $key; ?>_apisecret').val(),
				path: jQuery('#updraft_<?php echo $key; ?>_path').val(),
				disableverify: (jQuery('#updraft_ssl_disableverify').is(':checked')) ? 1 : 0,
				useservercerts: (jQuery('#updraft_ssl_useservercerts').is(':checked')) ? 1 : 0,
				nossl: (jQuery('#updraft_ssl_nossl').is(':checked')) ? 1 : 0,
			};
			jQuery.post(ajaxurl, data, function(response) {
					alert('Settings test result: ' + response);
			});
		});
		<?php
	}

	public static function config_print() {
	
		self::config_print_engine('s3', 'S3', 'Amazon S3', 'AWS', 'http://aws.amazon.com/console/', '<img src="https://d36cz9buwru1tt.cloudfront.net/Powered-by-Amazon-Web-Services.jpg" alt="Amazon Web Services">');
		
	}

	public static function config_print_engine($key, $whoweare_short, $whoweare_long, $console_descrip, $console_url, $img_html = '') {

	?>
		<tr class="updraftplusmethod <?php echo $key; ?>">
			<td></td>
			<td><?php echo $img_html ?><p><em><?php printf(__('%s is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your site is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.','updraftplus'),$whoweare_long);?></em></p></td>
		</tr>
		<tr class="updraftplusmethod <?php echo $key; ?>">
		<th></th>
		<td>
			<p><?php echo sprintf(__('Get your access key and secret key <a href="%s">from your %s console</a>, then pick a (globally unique - all %s users) bucket name (letters and numbers) (and optionally a path) to use for storage. This bucket will be created for you if it does not already exist.','updraftplus'), $console_url, $console_descrip, $whoweare_long);?> <a href="http://updraftplus.com/faqs/i-get-ssl-certificate-errors-when-backing-up-andor-restoring/"><?php _e('If you see errors about SSL certificates, then please go here for help.','updraftplus');?></a></p>
		</td></tr>
		<tr class="updraftplusmethod <?php echo $key; ?>">
			<th><?php echo sprintf(__('%s access key','updraftplus'), $whoweare_short);?>:</th>
			<td><input type="text" autocomplete="off" style="width: 292px" id="updraft_<?php echo $key; ?>_apikey" name="updraft_<?php echo $key; ?>_login" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_'.$key.'_login')) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod <?php echo $key; ?>">
			<th><?php echo sprintf(__('%s secret key','updraftplus'), $whoweare_short);?>:</th>
			<td><input type="text" autocomplete="off" style="width: 292px" id="updraft_<?php echo $key; ?>_apisecret" name="updraft_<?php echo $key; ?>_pass" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_'.$key.'_pass')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod <?php echo $key; ?>">
			<th><?php echo sprintf(__('%s location','updraftplus'), $whoweare_short);?>:</th>
			<td><?php echo $key; ?>://<input type="text" style="width: 292px" name="updraft_<?php echo $key; ?>_remote_path" id="updraft_<?php echo $key; ?>_path" value="<?php echo htmlspecialchars(UpdraftPlus_Options::get_updraft_option('updraft_'.$key.'_remote_path')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod <?php echo $key; ?>">
		<th></th>
		<td><p><button id="updraft-<?php echo $key; ?>-test" type="button" class="button-primary" style="font-size:18px !important"><?php echo sprintf(__('Test %s Settings','updraftplus'),$whoweare_short);?></button></p></td>
		</tr>

		<tr class="updraftplusmethod <?php echo $key; ?>">
		<th></th>
		<td>
			<?php global $updraftplus_admin; $updraftplus_admin->curl_check($whoweare_long, true); ?>
		</td>
		</tr>
	<?php
	}

	public static function credentials_test() {
		self::credentials_test_engine(self::get_config());
	}

	public static function credentials_test_engine($config) {

		if (empty($_POST['apikey'])) {
			printf(__("Failure: No %s was given.",'updraftplus'),__('API key','updraftplus'));
			return;
		}
		if (empty($_POST['apisecret'])) {
			printf(__("Failure: No %s was given.",'updraftplus'),__('API secret','updraftplus'));
			return;
		}

		$key = $_POST['apikey'];
		$secret = $_POST['apisecret'];
		$path = $_POST['path'];
		$useservercerts = (isset($_POST['useservercerts'])) ? absint($_POST['useservercerts']) : 0;
		$disableverify = (isset($_POST['disableverify'])) ? absint($_POST['disableverify']) : 0;
		$nossl = (isset($_POST['nossl'])) ? absint($_POST['nossl']) : 0;

		if (preg_match("#^([^/]+)/(.*)$#", $path, $bmatches)) {
			$bucket = $bmatches[1];
			$path = $bmatches[2];
		} else {
			$bucket = $path;
			$path = "";
		}

		if (empty($bucket)) {
			_e("Failure: No bucket details were given.",'updraftplus');
			return;
		}

		$whoweare = $config['whoweare'];

		$s3 = self::getS3($key, $secret, $useservercerts, $disableverify, $nossl);

		$location = ($config['key'] == 'dreamobjects') ? $config['whoweare'] : @$s3->getBucketLocation($bucket);
		if ($location) {
			$bucket_exists = true;
			$bucket_verb = ($config['key'] == 'dreamobjects') ? '' : __('Region','updraftplus').": $location: ";
// 			$bucket_verb = "accessed (".__('Amazon region','updraftplus').": $location)";
			$bucket_region = $location;
		} else {
			$try_to_create_bucket = @$s3->putBucket($bucket, S3::ACL_PRIVATE);
			if ($try_to_create_bucket) {
// 				$bucket_verb = 'created';
 				$bucket_verb = '';
				$bucket_exists = true;
			} else {
				echo sprintf(__("Failure: We could not successfully access or create such a bucket. Please check your access credentials, and if those are correct then try another bucket name (as another %s user may already have taken your name).",'updraftplus'),$whoweare);
			}
		}

		if (isset($bucket_exists)) {
			$try_file = md5(rand());
			call_user_func(array('UpdraftPlus_BackupModule_'.$config['key'], 'set_endpoint'), $s3, $location);
			$s3->setExceptions(true);
			try {

				if (!$s3->putObjectString($try_file, $bucket, $path.$try_file)) {
					echo __('Failure','updraftplus').": ${bucket_verb}".__('We successfully accessed the bucket, but the attempt to create a file in it failed.','updraftplus');
				} else {
					echo  __('Success','updraftplus').": ${bucket_verb}".__('We accessed the bucket, and were able to create files within it.','updraftplus').' ';
					if ($s3->useSSL) {
						echo sprintf(__('The communication with %s was encrypted.', 'updraftplus'), $config['whoweare_long']);
					} else {
						echo sprintf(__('The communication with %s was not encrypted.', 'updraftplus'), $config['whoweare_long']);
					}
					@$s3->deleteObject($bucket, $path.$try_file);
				}
			} catch (Exception $e) {
				echo __('Failure','updraftplus').": ${bucket_verb}".__('We successfully accessed the bucket, but the attempt to create a file in it failed.','updraftplus').' ('.$e->getMessage().')';
			}
		}

	}

}
?>
