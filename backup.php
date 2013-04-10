<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

// This file contains functions moved out of updraftplus.php that are only needed when a backup is running (reduce memory usage on other site pages)

global $updraftplus_backup;
$updraftplus_backup = new UpdraftPlus_Backup();
if (defined('UPDRAFTPLUS_PREFERPCLZIP') && UPDRAFTPLUS_PREFERPCLZIP == true) $updraftplus_backup->zip_preferpcl = true;

class UpdraftPlus_Backup {

	var $zipfiles_added;
	var $zipfiles_existingfiles;
	var $zipfiles_dirbatched;
	var $zipfiles_batched;

	var $zipfiles_lastwritetime;

	var $zip_preferpcl = false;

	// This function recursively packs the zip, dereferencing symlinks but packing into a single-parent tree for universal unpacking
	function makezip_recursive_add($zipfile, $fullpath, $use_path_when_storing, $original_fullpath) {

		global $updraftplus;

		// De-reference
		$fullpath = realpath($fullpath);

		// Is the place we've ended up above the original base? That leads to infinite recursion
		if (($fullpath !== $original_fullpath && strpos($original_fullpath, $fullpath) === 0) || ($original_fullpath == $fullpath && strpos($use_path_when_storing, '/') !== false) ) {
			$updraftplus->log("Infinite recursion: symlink lead us to $fullpath, which is within $original_fullpath");
			$updraftplus->error(__("Infinite recursion: consult your log for more information",'updraftplus'));
			return false;
		}

		if(is_file($fullpath)) {
			if (is_readable($fullpath)) {
				$key = ($fullpath == $original_fullpath) ? basename($fullpath) : $use_path_when_storing.'/'.basename($fullpath);
				$this->zipfiles_batched[$fullpath] = $key;
				@touch($zipfile);
			} else {
				$updraftplus->log("$fullpath: unreadable file");
				$updraftplus->error("$fullpath: unreadable file");
			}
		} elseif (is_dir($fullpath)) {
			if (!isset($this->existing_files[$use_path_when_storing])) $this->zipfiles_dirbatched[] = $use_path_when_storing;
			if (!$dir_handle = @opendir($fullpath)) {
				$updraftplus->log("Failed to open directory: $fullpath");
				$updraftplus->error("Failed to open directory: $fullpath");
				return;
			}
			while ($e = readdir($dir_handle)) {
				if ($e != '.' && $e != '..') {
					if (is_link($fullpath.'/'.$e)) {
						$deref = realpath($fullpath.'/'.$e);
						if (is_file($deref)) {
							if (is_readable($deref)) {
								$this->zipfiles_batched[$deref] = $use_path_when_storing.'/'.$e;
								@touch($zipfile);
							} else {
								$updraftplus->log("$deref: unreadable file");
								$updraftplus->error("$deref: unreadable file");
							}
						} elseif (is_dir($deref)) {
							$this->makezip_recursive_add($zipfile, $deref, $use_path_when_storing.'/'.$e, $original_fullpath);
						}
					} elseif (is_file($fullpath.'/'.$e)) {
						if (is_readable($fullpath.'/'.$e)) {
							$this->zipfiles_batched[$fullpath.'/'.$e] = $use_path_when_storing.'/'.$e;
							@touch($zipfile);
						} else {
							$updraftplus->log("$fullpath/$e: unreadable file");
							$updraftplus->error("$fullpath/$e: unreadable file");
						}
					} elseif (is_dir($fullpath.'/'.$e)) {
						// no need to addEmptyDir here, as it gets done when we recurse
						$this->makezip_recursive_add($zipfile, $fullpath.'/'.$e, $use_path_when_storing.'/'.$e, $original_fullpath);
					}
				}
			}
			closedir($dir_handle);
		}

		// We don't want to touch the zip file on every single file, so we batch them up
		// We go every 25 files, because if you wait too much longer, the contents may have changed from under you
		// And for some redundancy (redundant because of the touches going on anyway), we try to touch the file after 20 seconds, to help with the "recently modified" check on resumption (we saw a case where the file went for 155 seconds without being touched and so the other runner was not detected)
		if (count($this->zipfiles_batched) > 25 || (file_exists($zipfile) && ((time()-filemtime($zipfile)) > 20) )) {
			$ret = $this->makezip_addfiles($zipfile);
		} else {
			$ret = true;
		}

		return $ret;

	}

	// Caution: $source is allowed to be an array, not just a filename
	function make_zipfile($source, $destination) {

		global $updraftplus;

		// When to prefer PCL:
		// - We were asked to
		// - No zip extension present and no relevant method present
		// The zip extension check is not redundant, because method_exists segfaults some PHP installs, leading to support requests

		// Fallback to PclZip - which my tests show is 25% slower (and we can't resume)
		if ($this->zip_preferpcl || (!extension_loaded('zip') && !method_exists('ZipArchive', 'AddFile'))) {
			if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
			$zip_object = new PclZip($destination);
			$zipcode = $zip_object->create($source, PCLZIP_OPT_REMOVE_PATH, WP_CONTENT_DIR);
			if ($zipcode == 0 ) {
				$updraftplus->log("PclZip Error: ".$zip_object->errorName());
				return $zip_object->errorCode();
			} else {
				return true;
			}
		}

		$this->existing_files = array();

		// If the file exists, then we should grab its index of files inside, and sizes
		// Then, when we come to write a file, we should check if it's already there, and only add if it is not
		if (file_exists($destination) && is_readable($destination)) {
			$zip = new ZipArchive;
			$zip->open($destination);
			$updraftplus->log(basename($destination).": Zip file already exists, with ".$zip->numFiles." files");
			for ($i=0; $i<$zip->numFiles; $i++) {
				$si = $zip->statIndex($i);
				$name = $si['name'];
				$this->existing_files[$name] = $si['size'];
			}
		} elseif (file_exists($destination)) {
			$updraftplus->log("Zip file already exists, but is not readable; will remove: $destination");
			@unlink($destination);
		}

		$this->zipfiles_added = 0;
		$this->zipfiles_dirbatched = array();
		$this->zipfiles_batched = array();
		$this->zipfiles_lastwritetime = time();

		// Magic value, used later to detect no error occurring
		$last_error = 2349864;
		if (is_array($source)) {
			foreach ($source as $element) {
				$howmany = $this->makezip_recursive_add($destination, $element, basename($element), $element);
				if ($howmany < 0) {
					$last_error = $howmany;
				}
			}
		} else {
			$howmany = $this->makezip_recursive_add($destination, $source, basename($source), $source);
			if ($howmany < 0) {
				$last_error = $howmany;
			}
		}

		// Any not yet dispatched?
		if (count($this->zipfiles_dirbatched)>0 || count($this->zipfiles_batched)>0) {
			$howmany = $this->makezip_addfiles($destination);
			if ($howmany < 0) {
				$last_error = $howmany;
			}
		}

		if ($this->zipfiles_added > 0 || $last_error == 2349864) {
			// ZipArchive::addFile sometimes fails
			if (filesize($destination) < 100) {
				// Retry with PclZip
				$updraftplus->log("Zip::addFile apparently failed - retrying with PclZip");
				$this->zip_preferpcl = true;
				return $this->make_zipfile($source, $destination);
			}
			return true;
		} else {
			return $last_error;
		}

	}

	// Q. Why don't we only open and close the zip file just once?
	// A. Because apparently PHP doesn't write out until the final close, and it will return an error if anything file has vanished in the meantime. So going directory-by-directory reduces our chances of hitting an error if the filesystem is changing underneath us (which is very possible if dealing with e.g. 1Gb of files)

	// We batch up the files, rather than do them one at a time. So we are more efficient than open,one-write,close.
	function makezip_addfiles($zipfile) {

		global $updraftplus;

		// Short-circuit the null case, because we want to detect later if something useful happenned
		if (count($this->zipfiles_dirbatched) == 0 && count($this->zipfiles_batched) == 0) return true;

		// 05-Mar-2013 - added a new check on the total data added; it appears that things fall over if too much data is contained in the cumulative total of files that were addFile'd without a close-open cycle; presumably data is being stored in memory. In the case in question, it was a batch of MP3 files of around 100Mb each - 25 of those equals 2.5Gb!

		$data_added_since_reopen = 0;

		$zip = new ZipArchive();
		if (file_exists($zipfile)) {
			$opencode = $zip->open($zipfile);
			$original_size = filesize($zipfile);
			clearstatcache();
		} else {
			$opencode = $zip->open($zipfile, ZIPARCHIVE::CREATE);
			$original_size = 0;
		}

		if ($opencode !== true) return array($opencode, 0);
		// Make sure all directories are created before we start creating files
		while ($dir = array_pop($this->zipfiles_dirbatched)) {
			$zip->addEmptyDir($dir);
		}
		foreach ($this->zipfiles_batched as $file => $add_as) {
			$fsize = filesize($file);
			if (!isset($this->existing_files[$add_as]) || $this->existing_files[$add_as] != $fsize) {

				@touch($zipfile);
				$zip->addFile($file, $add_as);

				$data_added_since_reopen += $fsize;
				# 25Mb - force a write-out and re-open
				if ($data_added_since_reopen > 26214400 || (time() - $this->zipfiles_lastwritetime) > 2) {

					$before_size = filesize($zipfile);
					clearstatcache();

					if ($data_added_since_reopen > 26214400) {
						$updraftplus->log("Adding batch to zip file: over 25Mb added on this batch (".round($data_added_since_reopen/1048576,1)." Mb); re-opening (prior size: ".round($before_size/1024,1).' Kb)');
					} else {
						$updraftplus->log("Adding batch to zip file: over 2 seconds have passed since the last write (".round($data_added_since_reopen/1048576,1)." Mb); re-opening (prior size: ".round($before_size/1024,1).' Kb)');
					}
					if (!$zip->close()) {
						$updraftplus->log("zip::Close returned an error");
					}
					unset($zip);
					$zip = new ZipArchive();
					$opencode = $zip->open($zipfile);
					if ($opencode !== true) return array($opencode, 0);
					$data_added_since_reopen = 0;
					$this->zipfiles_lastwritetime = time();
					// Call here, in case we've got so many big files that we don't complete the whole routine
					if (filesize($zipfile) > $before_size) $updraftplus->something_useful_happened();
					clearstatcache();
				}
			}
			$this->zipfiles_added++;
			// Don't call something_useful_happened() here - nothing necessarily happens until close() is called
			if ($this->zipfiles_added % 100 == 0) $updraftplus->log("Zip: ".basename($zipfile).": ".$this->zipfiles_added." files added (on-disk size: ".round(filesize($zipfile)/1024,1)." Kb)");
		}
		// Reset the array
		$this->zipfiles_batched = array();
		$ret =  $zip->close();
		$this->zipfiles_lastwritetime = time();
		if (filesize($zipfile) > $original_size) $updraftplus->something_useful_happened();
		clearstatcache();
		return $ret;
	}

	function create_zip($create_from_dir, $whichone, $create_in_dir, $backup_file_basename) {
		// Note: $create_from_dir can be an array or a string
		@set_time_limit(900);

		global $updraftplus;

		if ($whichone != "others") $updraftplus->log("Beginning creation of dump of $whichone");

		$full_path = $create_in_dir.'/'.$backup_file_basename.'-'.$whichone.'.zip';
		$time_now = time();

		if (file_exists($full_path)) {
			$updraftplus->log("$backup_file_basename-$whichone.zip: this file has already been created");
			$time_mod = (int)@filemtime($full_path);
			if ($time_mod>100 && ($time_now-$time_mod)<30) {
				$updraftplus->log("Terminate: the zip $full_path already exists, and was modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=".filesize($full_path)."). This likely means that another UpdraftPlus run is still at work; so we will exit.");
				$updraftplus->increase_resume_and_reschedule(120);
				die;
			}
			return basename($full_path);
		}

		// Temporary file, to be able to detect actual completion (upon which, it is renamed)

		// Firstly, make sure that the temporary file is not already being written to - which can happen if a resumption takes place whilst an old run is still active
		$zip_name = $full_path.'.tmp';
		$time_mod = (int)@filemtime($zip_name);
		if (file_exists($zip_name) && $time_mod>100 && ($time_now-$time_mod)<30) {
			$file_size = filesize($zip_name);
			$updraftplus->log("Terminate: the temporary file $zip_name already exists, and was modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).", size=$file_size). This likely means that another UpdraftPlus run is still at work; so we will exit.");
			$updraftplus->increase_resume_and_reschedule(120);
			die;
		} elseif (file_exists($zip_name)) {
			$updraftplus->log("File exists ($zip_name), but was apparently not modified within the last 30 seconds, so we assume that any previous run has now terminated (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).")");
		}

		$microtime_start = microtime(true);
		# The paths in the zip should then begin with '$whichone', having removed WP_CONTENT_DIR from the front
		$zipcode = $this->make_zipfile($create_from_dir, $zip_name);
		if ($zipcode !== true) {
			$updraftplus->log("ERROR: Zip failure: Could not create $whichone zip: code=$zipcode");
			$updraftplus->error(sprintf(__("Could not create %s zip. Consult the log file for more information.",'updraftplus'),$whichone));
			return false;
		} else {
			rename($full_path.'.tmp', $full_path);
			$timetaken = max(microtime(true)-$microtime_start, 0.000001);
			$kbsize = filesize($full_path)/1024;
			$rate = round($kbsize/$timetaken, 1);
			$updraftplus->log("Created $whichone zip - file size is ".round($kbsize,1)." Kb in ".round($timetaken,1)." s ($rate Kb/s)");
		}

		return basename($full_path);
	}


}