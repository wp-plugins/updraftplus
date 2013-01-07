<?php

class UpdraftPlus_BackupModule_s3 {

	function backup($backup_array) {

		global $updraftplus;

		if (!class_exists('S3')) require_once(UPDRAFTPLUS_DIR.'/includes/S3.php');

		$s3 = new S3(get_option('updraft_s3_login'), get_option('updraft_s3_pass'));

		$bucket_name = untrailingslashit(get_option('updraft_s3_remote_path'));
		$bucket_path = "";
		$orig_bucket_name = $bucket_name;

		if (preg_match("#^([^/]+)/(.*)$#",$bucket_name,$bmatches)) {
			$bucket_name = $bmatches[1];
			$bucket_path = $bmatches[2]."/";
		}

		// See if we can detect the region (which implies the bucket exists and is ours), or if not create it
		if (@$s3->getBucketLocation($bucket_name) || @$s3->putBucket($bucket_name, S3::ACL_PRIVATE)) {

			foreach($backup_array as $file) {

				// We upload in 5Mb chunks to allow more efficient resuming and hence uploading of larger files
				$fullpath = trailingslashit(get_option('updraft_dir')).$file;
				$chunks = floor(filesize($fullpath) / 5242880)+1;
				$hash = md5($file);

				$updraftplus->log("S3 upload: $fullpath (chunks: $chunks) -> s3://$bucket_name/$bucket_path$file");

				$filepath = $bucket_path.$file;

				// This is extra code for the 1-chunk case, but less overhead (no bothering with transients)
				if ($chunks < 2) {
					if (!$s3->putObjectFile($fullpath, $bucket_name, $filepath)) {
						$updraftplus->log("S3 regular upload: failed");
						$updraftplus->error("S3 Error: Failed to upload $fullpath.");
					} else {
						$updraftplus->log("S3 regular upload: success");
						$updraftplus->uploaded_file($file);
					}
				} else {

					// Retrieve the upload ID
					$uploadId = get_transient("updraft_${hash}_uid");
					if (empty($uploadId)) {
						$uploadId = $s3->initiateMultipartUpload($bucket_name, $filepath);
						if (empty($uploadId)) {
							$updraftplus->log("S3 upload: failed: could not get uploadId for multipart upload");
							continue;
						} else {
							$updraftplus->log("S3 chunked upload: got multipart ID: $uploadId");
							set_transient("updraft_${hash}_uid", $uploadId, 3600*3);
						}
					} else {
						$updraftplus->log("S3 chunked upload: retrieved previously obtained multipart ID: $uploadId");
					}

					$successes = 0;
					$etags = array();
					for ($i = 1 ; $i <= $chunks; $i++) {
						# Shorted to upd here to avoid hitting the 45-character limit
						$etag = get_transient("upd_${hash}_e$i");
						if (strlen($etag) > 0) {
							$updraftplus->log("S3 chunk $i: was already completed (etag: $etag)");
							$successes++;
							array_push($etags, $etag);
						} else {
							$etag = $s3->uploadPart($bucket_name, $filepath, $uploadId, $fullpath, $i);
							if (is_string($etag)) {
								$updraftplus->log("S3 chunk $i: uploaded (etag: $etag)");
								array_push($etags, $etag);
								set_transient("upd_${hash}_e$i", $etag, 3600*3);
								$successes++;
							} else {
								$updraftplus->error("S3 chunk $i: upload failed");
								$updraftplus->log("S3 chunk $i: upload failed");
							}
						}
					}
					if ($successes >= $chunks) {
						$updraftplus->log("S3 upload: all chunks uploaded; will now instruct S3 to re-assemble");
						if ($s3->completeMultipartUpload ($bucket_name, $filepath, $uploadId, $etags)) {
							$updraftplus->log("S3 upload: re-assembly succeeded");
							$updraftplus->uploaded_file($file);
						} else {
							$updraftplus->log("S3 upload: re-assembly failed");
							$updraftplus->error("S3 upload: re-assembly failed");
						}
					} else {
						$updraftplus->log("S3 upload: upload was not completely successful on this run");
					}
				}
			}
			$updraftplus->prune_retained_backups('s3', $this, array('s3_object' => $s3, 's3_orig_bucket_name' => $orig_bucket_name));
		} else {
			$updraftplus->log("S3 Error: Failed to create bucket $bucket_name.");
			$updraftplus->error("S3 Error: Failed to create bucket $bucket_name. Check your permissions and credentials.");
		}
	}

	function delete($file, $s3arr) {

		global $updraftplus;

		$s3 = $s3arr['s3_object'];
		$orig_bucket_name = $s3arr['s3_orig_bucket_name'];

		if (preg_match("#^([^/]+)/(.*)$#", $orig_bucket_name, $bmatches)) {
			$s3_bucket=$bmatches[1];
			$s3_uri = $bmatches[2]."/".$file;
		} else {
			$s3_bucket = $remote_path;
			$s3_uri = $file;
		}
		$updraftplus->log("S3: Delete remote: bucket=$s3_bucket, URI=$s3_uri");

		# Here we brought in the contents of the S3.php function deleteObject in order to get more direct access to any error
		$rest = new S3Request('DELETE', $s3_bucket, $s3_uri);
		$rest = $rest->getResponse();
		if ($rest->error === false && $rest->code !== 204) {
			$updraftplus->log("S3 Error: Expected HTTP response 204; got: ".$rest->code);
			$updraftplus->error("S3 Error: Unexpected HTTP response code ".$rest->code." (expected 204)");
		} elseif ($rest->error !== false) {
			$updraftplus->log("S3 Error: ".$rest->error['code'].": ".$rest->error['message']);
			$updraftplus->error("S3 delete error: ".$rest->error['code'].": ".$rest->error['message']);
		}

	}

	function download($file) {

		global $updraftplus;
		if(!class_exists('S3')) require_once(UPDRAFTPLUS_DIR.'/includes/S3.php');

		$s3 = new S3(get_option('updraft_s3_login'), get_option('updraft_s3_pass'));
		$bucket_name = untrailingslashit(get_option('updraft_s3_remote_path'));
		$bucket_path = "";

		if (preg_match("#^([^/]+)/(.*)$#",$bucket_name,$bmatches)) {
			$bucket_name = $bmatches[1];
			$bucket_path = $bmatches[2]."/";
		}

		if (@$s3->getBucketLocation($bucket_name)) {
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			if (!$s3->getObject($bucket_name, $bucket_path.$file, $fullpath)) {
				$updraftplus->error("S3 Error: Failed to download $fullpath. Check your permissions and credentials.");
			}
		} else {
			$updraftplus->error("S3 Error: Failed to access bucket $bucket_name. Check your permissions and credentials.");
		}

	}

	function config_print() {

	?>
		<tr class="updraftplusmethod s3">
			<td></td>
			<td><em>Amazon S3 is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your blog is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.</em></td>
		</tr>
		<tr class="updraftplusmethod s3">
		<th></th>
		<td>
			<p>Get your access key and secret key <a href="http://aws.amazon.com/console/">from your AWS console</a>, then pick a (globally unique - all Amazon S3 users) bucket name (letters and numbers) (and optionally a path) to use for storage. This bucket will be created for you if it does not already exist.</p>
		</td></tr>
		<tr class="updraftplusmethod s3">
			<th>S3 access key:</th>
			<td><input type="text" autocomplete="off" style="width: 292px" id="updraft_s3_apikey" name="updraft_s3_login" value="<?php echo htmlspecialchars(get_option('updraft_s3_login')) ?>" /></td>
		</tr>
		<tr class="updraftplusmethod s3">
			<th>S3 secret key:</th>
			<td><input type="text" autocomplete="off" style="width: 292px" id="updraft_s3_apisecret" name="updraft_s3_pass" value="<?php echo htmlspecialchars(get_option('updraft_s3_pass')); ?>" /></td>
		</tr>
		<tr class="updraftplusmethod s3">
			<th>S3 location:</th>
			<td>s3://<input type="text" style="width: 292px" name="updraft_s3_remote_path" id="updraft_s3_path" value="<?php echo htmlspecialchars(get_option('updraft_s3_remote_path')); ?>" /></td>
		</tr>
		<tr>
		<th></th>
		<td><p><button id="updraft-s3-test" type="button" class="button-primary" style="font-size:18px !important">Test S3 Settings</button></p></td>
		</tr>
	<?php
	}

	function s3_test($key, $secret, $path) {
		$bucket =  (preg_match("#^([^/]+)/(.*)$#", $path, $bmatches)) ? $bmatches[1] : $path;

		if (empty($bucket)) {
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

		if (!class_exists('S3')) require_once(UPDRAFTPLUS_DIR.'/includes/S3.php');
		$s3 = new S3($key, $secret);

		$location = @$s3->getBucketLocation($bucket);
		if ($location) {
			echo "Success: this bucket exists (Amazon region: $location) and we have access to it.";
		} else {
			$try_to_create = @$s3->putBucket($bucket, S3::ACL_PRIVATE);
			if ($try_to_create) {
				echo "Success: We have successfully created this bucket in your S3 account.";
			} else {
				echo "Failure: We could not successfully access or create such a bucket. Please check your access credentials, and if those are correct then try another bucket name (as another S3 user may already have taken this name).";
			}
		}

	}

}
?>