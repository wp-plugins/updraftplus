<?php

function updraftplus_s3_backup($backup_array) {

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
		$updraftplus->prune_retained_backups('s3',$s3,$orig_bucket_name);
	} else {
		$updraftplus->log("S3 Error: Failed to create bucket $bucket_name.");
		$updraftplus->error("S3 Error: Failed to create bucket $bucket_name. Check your permissions and credentials.");
	}
}

function updraftplus_download_s3_backup($file) {

	global $updraftplus;
	if(!class_exists('S3'))  require_once(UPDRAFTPLUS_DIR.'/includes/S3.php');

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

?>