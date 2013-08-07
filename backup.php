<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

// This file contains functions moved out of updraftplus.php that are only needed when a backup is running (reduce memory usage on other site pages)

global $updraftplus_backup;
$updraftplus_backup = new UpdraftPlus_Backup();
if (defined('UPDRAFTPLUS_PREFERPCLZIP') && UPDRAFTPLUS_PREFERPCLZIP == true) $updraftplus_backup->zip_preferpcl = true;

class UpdraftPlus_Backup {

	var $zipfiles_added;
	var $zipfiles_added_thisrun = 0;
	var $zipfiles_existingfiles;
	var $zipfiles_dirbatched;
	var $zipfiles_batched;

	var $zipfiles_lastwritetime;

	var $zip_preferpcl = false;

	var $binzip = false;

	// This function recursively packs the zip, dereferencing symlinks but packing into a single-parent tree for universal unpacking
	function makezip_recursive_add($zipfile, $fullpath, $use_path_when_storing, $original_fullpath) {

		global $updraftplus;

		// De-reference
		$fullpath = realpath($fullpath);
		$original_fullpath = realpath($original_fullpath);

		// Is the place we've ended up above the original base? That leads to infinite recursion
		if (($fullpath !== $original_fullpath && strpos($original_fullpath, $fullpath) === 0) || ($original_fullpath == $fullpath && strpos($use_path_when_storing, '/') !== false) ) {
			$updraftplus->log("Infinite recursion: symlink lead us to $fullpath, which is within $original_fullpath");
			$updraftplus->log(__("Infinite recursion: consult your log for more information",'updraftplus'), 'error');
			return false;
		}

		if(is_file($fullpath)) {
			if (is_readable($fullpath)) {
				$key = ($fullpath == $original_fullpath) ? basename($fullpath) : $use_path_when_storing.'/'.basename($fullpath);
				$this->zipfiles_batched[$fullpath] = $key;
				@touch($zipfile);
			} else {
				$updraftplus->log("$fullpath: unreadable file");
				$updraftplus->log(sprintf(__("%s: unreadable file - could not be backed up", 'updraftplus'), $fullpath), 'warning');
			}
		} elseif (is_dir($fullpath)) {
			if (!isset($this->existing_files[$use_path_when_storing])) $this->zipfiles_dirbatched[] = $use_path_when_storing;
			if (!$dir_handle = @opendir($fullpath)) {
				$updraftplus->log("Failed to open directory: $fullpath");
				$updraftplus->log(sprintf(__("Failed to open directory: %s",'updraftplus'), $fullpath), 'error');
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
								$updraftplus->log(sprintf(__("%s: unreadable file - could not be backed up"), $deref), 'warning');
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
							$updraftplus->log(sprintf(__("%s: unreadable file - could not be backed up", 'updraftplus'), $use_path_when_storing.'/'.$e), 'warning');
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
		// We go every 25 files, because if you wait too much longer, the contents may have changed from under you. Note though that since this fires once-per-directory, the actual number by this stage may be much larger; the most we saw was over 3000; but in that case, makezip_addfiles() will split the write-out up into smaller chunks
		// And for some redundancy (redundant because of the touches going on anyway), we try to touch the file after 20 seconds, to help with the "recently modified" check on resumption (we saw a case where the file went for 155 seconds without being touched and so the other runner was not detected)
		if (count($this->zipfiles_batched) > 25 || (file_exists($zipfile) && ((time()-filemtime($zipfile)) > 20) )) {
			$ret = $this->makezip_addfiles($zipfile);
		} else {
			$ret = true;
		}

		return $ret;

	}

	// Caution: $source is allowed to be an array, not just a filename
	function make_zipfile($source, $destination, $whichone = '') {

		global $updraftplus;
		$destination_base = basename($destination);
		// Legacy/redundant
		if (empty($whichone) && is_string($whichone)) $whichone = basename($source);

		// When to prefer PCL:
		// - We were asked to
		// - No zip extension present and no relevant method present
		// The zip extension check is not redundant, because method_exists segfaults some PHP installs, leading to support requests

		// We need meta-info about $whichone
		$backupable_entities = $updraftplus->get_backupable_file_entities(true, false);

		// Fallback to PclZip - which my tests show is 25% slower (and we can't resume)
		if ($this->zip_preferpcl || (!extension_loaded('zip') && !method_exists('ZipArchive', 'AddFile'))) {
			if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
			$zip_object = new PclZip($destination);
			$remove_path = WP_CONTENT_DIR;
			$add_path = false;
			// Remove prefixes
			if (isset($backupable_entities[$whichone])) {
				if ('plugins' == $whichone || 'themes' == $whichone || 'uploads' == $whichone) {
					$remove_path = dirname($backupable_entities[$whichone]);
					# To normalise instead of removing (which binzip doesn't support, so we don't do it), you'd remove the dirname() in the above line, and uncomment the below one.
					#$add_path = $whichone;
				} else {
					$remove_path = $backupable_entities[$whichone];
				}
			}
			if ($add_path) {
				$zipcode = $zip_object->create($source, PCLZIP_OPT_REMOVE_PATH, $remove_path, PCLZIP_OPT_ADD_PATH, $add_path);
			} else {
				$zipcode = $zip_object->create($source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
			}
			if ($zipcode == 0 ) {
				$updraftplus->log("PclZip Error: ".$zip_object->errorInfo(true), 'warning');
				return $zip_object->errorCode();
			} else {
				return true;
			}
		}

		if ($this->binzip === false && (!defined('UPDRAFTPLUS_NO_BINZIP') || !UPDRAFTPLUS_NO_BINZIP) ) {
			$updraftplus->log('Checking if we have a zip executable available');
			$binzip = $updraftplus->find_working_bin_zip();
			if (is_string($binzip)) {
				$updraftplus->log("Found one: $binzip");
				$this->binzip = $binzip;
			}
		}

		// TODO: Handle stderr?
		// We only use binzip up to resumption 8, in case there is some undetected problem. We can make this more sophisticated if a need arises.
		if (is_string($this->binzip) && $updraftplus->current_resumption <9) {

			if (is_string($source)) $source = array($source);

			$all_ok = true;

			$debug = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');

			# Don't use -q and do use -v, as we rely on output to process to detect useful activity
			$zip_params = '-v';

			$orig_size = file_exists($destination) ? filesize($destination) : 0;
			$last_size = $orig_size;
			clearstatcache();

			foreach ($source as $s) {

				$exec = "cd ".escapeshellarg(dirname($s))."; ".$this->binzip." $zip_params -u -r ".escapeshellarg($destination)." ".escapeshellarg(basename($s))." ";

				$updraftplus->log("Attempting binary zip ($exec)");

				$handle = popen($exec, "r");

				$something_useful_happened = $updraftplus->something_useful_happened;

				if ($handle) {
					while (!feof($handle)) {
						$w = fgets($handle, 1024);
						// Logging all this really slows things down
						if ($w && $debug) $updraftplus->log("Output from zip: ".trim($w), 'debug');
						if (file_exists($destination)) {
							$new_size = filesize($destination);
							if (!$something_useful_happened && $new_size > $orig_size + 20) {
								$updraftplus->something_useful_happened();
								$something_useful_happened = true;
							}
							clearstatcache();
							# Log when 20% bigger or at least every 50Mb
							if ($new_size > $last_size*1.2 || $new_size > $last_size + 52428800) {
								$updraftplus->log(sprintf("$destination_base: size is now: %.2f Mb", round($new_size/1048576,1)));
								$last_size = $new_size;
							}
						}
					}
					$ret = pclose($handle);
					// Code 12 = nothing to do
					if ($ret != 0 && $ret != 12) {
						$updraftplus->log("Binary zip: error (code: $ret)");
						if ($w && !$debug) $updraftplus->log("Last output from zip: ".trim($w), 'debug');
						$all_ok = false;
					}
				} else {
					$updraftplus->log("Error: popen failed");
					$all_ok = false;
				}

			}

			if ($all_ok) {
				$updraftplus->log("Binary zip: apparently successful");
				return true;
			} else {
				$updraftplus->log("Binary zip: an error occured, so we will run over again with ZipArchive");
			}

		}

		$this->existing_files = array();

		// If the file exists, then we should grab its index of files inside, and sizes
		// Then, when we come to write a file, we should check if it's already there, and only add if it is not
		if (file_exists($destination) && is_readable($destination) && filesize($destination)>0) {
			$zip = new ZipArchive;
			$zip->open($destination);

			for ($i=0; $i < $zip->numFiles; $i++) {
				$si = $zip->statIndex($i);
				$name = $si['name'];
				$this->existing_files[$name] = $si['size'];
			}

			$updraftplus->log(basename($destination).": Zip file already exists, with ".count($this->existing_files)." files");

		} elseif (file_exists($destination)) {
			$updraftplus->log("Zip file already exists, but is not readable or was zero-sized; will remove: $destination");
			@unlink($destination);
		}

		$this->zipfiles_added = 0;
		$this->zipfiles_added_thisrun = 0;
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
			if (filesize($destination) < 90) {
				// Retry with PclZip
				$updraftplus->log("Zip::addFile apparently failed ($last_error, ".filesize($destination).") - retrying with PclZip");
				$this->zip_preferpcl = true;
				return $this->make_zipfile($source, $destination, $whichone);
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

		$maxzipbatch = $updraftplus->jobdata_get('maxzipbatch', 26214400);
		if ((int)$maxzipbatch < 1) $maxzipbatch = 26214400;

		// Short-circuit the null case, because we want to detect later if something useful happenned
		if (count($this->zipfiles_dirbatched) == 0 && count($this->zipfiles_batched) == 0) return true;

		// 05-Mar-2013 - added a new check on the total data added; it appears that things fall over if too much data is contained in the cumulative total of files that were addFile'd without a close-open cycle; presumably data is being stored in memory. In the case in question, it was a batch of MP3 files of around 100Mb each - 25 of those equals 2.5Gb!

		$data_added_since_reopen = 0;
		# The following array is used only for error reporting if ZipArchive::close fails (since that method itself reports no error messages - we have to track manually what we were attempting to add)
		$files_zipadded_since_open = array();

		$zip = new ZipArchive;
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

		$zipfiles_added_thisbatch = 0;

		// Go through all those batched files
		foreach ($this->zipfiles_batched as $file => $add_as) {
			$fsize = filesize($file);

			if ($fsize > UPDRAFTPLUS_WARN_FILE_SIZE) {
				$updraftplus->log(sprintf(__('A very large file was encountered: %s (size: %s Mb)', 'updraftplus'), $add_as, round($fsize/1048576, 1)), 'warning');
			}

			// Skips files that are already added
			if (!isset($this->existing_files[$add_as]) || $this->existing_files[$add_as] != $fsize) {

				@touch($zipfile);
				$zip->addFile($file, $add_as);
				$zipfiles_added_thisbatch++;
				$this->zipfiles_added_thisrun++;
				$files_zipadded_since_open[] = array('file' => $file, 'addas' => $add_as);

				$data_added_since_reopen += $fsize;
				/* Conditions for forcing a write-out and re-open:
				- more than $maxzipbatch bytes have been batched
				- more than 1.5 seconds have passed since the last time we wrote
				- NOT YET: POSSIBLE: NEEDS FINE-TUNING: more files are batched than the lesser of (1000, + whats already in the zip file, and that is more than 200
				*/
				if ($data_added_since_reopen > $maxzipbatch || (time() - $this->zipfiles_lastwritetime) > 1.5) {

					$something_useful_sizetest = false;

					$before_size = filesize($zipfile);
					clearstatcache();

					if ($data_added_since_reopen > $maxzipbatch) {

						$something_useful_sizetest = true;

						$updraftplus->log("Adding batch to zip file: over ".round($maxzipbatch/1048576,1)." Mb added on this batch (".round($data_added_since_reopen/1048576,1)." Mb, ".count($this->zipfiles_batched)." files batched, $zipfiles_added_thisbatch (".$this->zipfiles_added_thisrun.") added so far); re-opening (prior size: ".round($before_size/1024,1).' Kb)');

					} else {
						$updraftplus->log("Adding batch to zip file: over 1.5 seconds have passed since the last write (".round($data_added_since_reopen/1048576,1)." Mb, $zipfiles_added_thisbatch (".$this->zipfiles_added_thisrun.") files added so far); re-opening (prior size: ".round($before_size/1024,1).' Kb)');
					}
					if (!$zip->close()) {
						$updraftplus->log(__('A zip error occurred - check your log for more details.', 'updraftplus'), 'warning', 'zipcloseerror');
						$updraftplus->log("ZipArchive::Close returned an error. List of files we were trying to add follows (check their permissions).");
						foreach ($files_zipadded_since_open as $file) {
							$updraftplus->log("File: ".$file['addas']." (exists: ".(int)@file_exists($file['file']).", size: ".@filesize($file['file']).')');
						}
					}
					unset($zip);
					$files_zipadded_since_open = array();
					$zip = new ZipArchive;
					$opencode = $zip->open($zipfile);
					if ($opencode !== true) return array($opencode, 0);
					// Call here, in case we've got so many big files that we don't complete the whole routine
					if (filesize($zipfile) > $before_size) {

						# Need to make sure that something_useful_happened() is always called

						# How long since the current run began? If it's taken long (and we're in danger of not making it at all), or if that is forseeable in future because of general slowness, then we should reduce the parameters.
						if (!$something_useful_sizetest) {
							$updraftplus->something_useful_happened();
						} else {

							// Do this as early as possible
							$updraftplus->something_useful_happened();

							$time_since_began = max(microtime(true)- $this->zipfiles_lastwritetime, 0.000001);
							$normalised_time_since_began = $time_since_began*($maxzipbatch/$data_added_since_reopen);

							// Don't measure speed until after ZipArchive::close()
							$rate = round($data_added_since_reopen/$time_since_began, 1);

							$updraftplus->log(sprintf("A useful amount of data was added after this amount of zip processing: %s s (normalised: %s s, rate: %s Kb/s)", round($time_since_began, 1), round($normalised_time_since_began, 1), round($rate/1024, 1)));

							// We want to detect not only that we need to reduce the size of batches, but also the capability to increase them. This is particularly important because of ZipArchive()'s (understandable, given the tendency of PHP processes being terminated without notice) practice of first creating a temporary zip file via copying before acting on that zip file (so the information is atomic). Unfortunately, once the size of the zip file gets over 100Mb, the copy operation beguns to be significant. By the time you've hit 500Mb on many web hosts the copy is the majority of the time taken. So we want to do more in between these copies if possible.

							/* "Could have done more" - detect as:
							- A batch operation would still leave a "good chunk" of time in a run
							- "Good chunk" means that the time we took to add the batch is less than 50% of a run time
							- We can do that on any run after the first (when at least one ceiling on the maximum time is known)
							- But in the case where a max_execution_time is long (so that resumptions are never needed), and we're always on run 0, we will automatically increase chunk size if the batch took less than 6 seconds.
							*/

							// At one stage we had a strategy of not allowing check-ins to have more than 20s between them. However, once the zip file got to a certain size, PHP's habit of copying the entire zip file first meant that it *always* went over 18s, and thence a drop in the max size was inevitable - which was bad, because with the copy time being something that only grew, the outcome was less data being copied every time

							// Gather the data. We try not to do this unless necessary (may be time-sensitive)
							if ($updraftplus->current_resumption >= 1) {
								$time_passed = $updraftplus->jobdata_get('run_times');
								if (!is_array($time_passed)) $time_passed = array();
								list($max_time, $timings_string, $run_times_known) = $updraftplus->max_time_passed($time_passed, $updraftplus->current_resumption-1);
							} else {
								$run_times_known = 0;
								$max_time = -1;
							}

							// How many is the most seconds we 
// 							$max_tolerate_seconds = 

							// We set at 18, to allow approximately unexpected 10% extra in the batch to take it to 20s
// 							if (($run_times_known <1 && $normalised_time_since_began > 18) || ($run_times_known >=1 && $normalised_time_since_began > $max_time)) {
							// TODO: This is disabled via 1==0 - remove it properly
 							if (1==0 && $normalised_time_since_began > 18) {

								// Don't do more than would have accounted for 18 normalised seconds at the same rate
								// The line below means, do whichever-is-least-of 10% less, or what would have accounted for 18 normalised seconds - but never go lower than 1Mb.
								$new_maxzipbatch = max( floor(min($maxzipbatch*(18/$normalised_time_since_began), $maxzipbatch*0.9)), 1048576);
								if ($new_maxzipbatch < $maxzipbatch) {
									$updraftplus->jobdata_set("maxzipbatch", $new_maxzipbatch);
									$updraftplus->log("More than 18 (normalised) seconds passed since the last check-in, so we will adjust the amount of data we attempt in each batch (time_passed=$time_since_began, normalised_time_passed=$normalised_time_since_began, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)");
									$maxzipbatch = $new_maxzipbatch;
								} else {
									$updraftplus->log("More than 18 (normalised) seconds passed since the last check-in, but the zip-writing threshold is already at its lower limit (1Mb), so will not be further reduced (max_bytes=$maxzipbatch, time_passed=$time_since_began, normalised_time_passed=$normalised_time_since_began)");
								}
							} else {

								if ($normalised_time_since_began<6 || ($updraftplus->current_resumption >=1 && $run_times_known >=1 && $time_since_began < 0.6*$max_time )) {

									// How much can we increase it by?
									if ($normalised_time_since_began <6) {
										if ($run_times_known > 0 && $max_time >0) {
											$new_maxzipbatch = min(floor(max(
												$maxzipbatch*6/$normalised_time_since_began, $maxzipbatch*((0.6*$max_time)/$normalised_time_since_began))),
											200*1024*1024
											);
										} else {
											# Maximum of 200Mb in a batch
											$new_maxzipbatch = min( floor($maxzipbatch*6/$normalised_time_since_began),
											200*1024*1024
											);
										}
									} else {
										// Use up to 60% of available time
										$new_maxzipbatch = min(
										floor($maxzipbatch*((0.6*$max_time)/$normalised_time_since_began)),
										200*1024*1024
										);
									}

									# Throttle increases - don't increase by more than 2x in one go - ???
									# $new_maxzipbatch = floor(min(2*$maxzipbatch, $new_maxzipbatch));
									# Also don't allow anything that is going to be more than 18 seconds - actually, that's harmful because of the basically fixed time taken to copy the file
									# $new_maxzipbatch = floor(min(18*$rate ,$new_maxzipbatch));

									# Don't raise it above a level that failed on a previous run
									$maxzipbatch_ceiling = $updraftplus->jobdata_get('maxzipbatch_ceiling');
									if (is_numeric($maxzipbatch_ceiling) && $maxzipbatch_ceiling > 20*1024*1024 && $new_maxzipbatch > $maxzipbatch_ceiling) {
										$updraftplus->log("Was going to raise maxzipbytes to $new_maxzipbatch, but this is too high: a previous failure led to the ceiling being set at $maxzipbatch_ceiling, which we will use instead");
										$new_maxzipbatch = $maxzipbatch_ceiling;
									}

									// Final sanity check
									if ($new_maxzipbatch > 1024*1024) $updraftplus->jobdata_set("maxzipbatch", $new_maxzipbatch);
									
									if ($new_maxzipbatch <= 1024*1024) {
										$updraftplus->log("Unexpected new_maxzipbatch value obtained (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)");
									} elseif ($new_maxzipbatch > $maxzipbatch) {
										$updraftplus->log("Performance is good - will increase the amount of data we attempt to batch (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)");
									} elseif ($new_maxzipbatch < $maxzipbatch) {
										// Ironically, we thought we were speedy...
										$updraftplus->log("Adjust: Reducing maximum amount of batched data (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, new_max_bytes=$new_maxzipbatch, old_max_bytes=$maxzipbatch)");
									} else {
										$updraftplus->log("Performance is good - but we will not increase the amount of data we batch, as we are already at the present limit (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, max_bytes=$maxzipbatch)");
									}

									if ($new_maxzipbatch > 1024*1024) $maxzipbatch = $new_maxzipbatch;
								}

								// Detect excessive slowness
								// Don't do this until we're on at least resumption 7, as we want to allow some time for things to settle down and the maxiumum time to be accurately known (since reducing the batch size unnecessarily can itself cause extra slowness, due to PHP's usage of temporary zip files)
								 
								// We use a percentage-based system as much as possible, to avoid the various criteria being in conflict with each other (i.e. a run being both 'slow' and 'fast' at the same time, which is increasingly likely as max_time gets smaller).

								if (!$updraftplus->something_useful_happened && $updraftplus->current_resumption >= 7) {

									$updraftplus->something_useful_happened();

									if ($run_times_known >= 5 && ($time_since_began > 0.8 * $max_time || $time_since_began + 7 > $max_time)) {

										$new_maxzipbatch = max(floor($maxzipbatch*0.8), 20971520);
										if ($new_maxzipbatch < $maxzipbatch) {
											$maxzipbatch = $new_maxzipbatch;
											$updraftplus->jobdata_set("maxzipbatch", $new_maxzipbatch);
											$updraftplus->log("We are within a small amount of the expected maximum amount of time available; the zip-writing thresholds will be reduced (time_passed=$time_since_began, normalised_time_passed=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)");
										} else {
											$updraftplus->log("We are within a small amount of the expected maximum amount of time available, but the zip-writing threshold is already at its lower limit (20Mb), so will not be further reduced (max_time=$max_time, data points known=$run_times_known, max_bytes=$maxzipbatch)");
										}
									}

								} else {
									$updraftplus->something_useful_happened();
								}
							}
						}
						$data_added_since_reopen = 0;
					} else {
						# ZipArchive::close() can take a very long time, which we want to know about
						$updraftplus->record_still_alive();
					}
					clearstatcache();
					$this->zipfiles_lastwritetime = time();
				}
			} elseif (0 == $this->zipfiles_added_thisrun) {
				// Update lastwritetime, because otherwise the 1.5-second-activity detection can fire prematurely (e.g. if it takes >1.5 seconds to process the previously-written files, then the detector fires after 1 file. This then can have the knock-on effect of having something_useful_happened() called, but then a subsequent attempt to write out a lot of meaningful data fails, and the maximum batch is not then reduced.
				// Testing shows that calling time() 1000 times takes negligible time
				$this->zipfiles_lastwritetime=time();
			}
			$this->zipfiles_added++;
			// Don't call something_useful_happened() here - nothing necessarily happens until close() is called
			if ($this->zipfiles_added % 100 == 0) $updraftplus->log("Zip: ".basename($zipfile).": ".$this->zipfiles_added." files added (on-disk size: ".round(filesize($zipfile)/1024,1)." Kb)");
		}

		// Reset the array
		$this->zipfiles_batched = array();
		$ret =  $zip->close();
		if (!$ret) {
			$updraftplus->log(__('A zip error occurred - check your log for more details.', 'updraftplus'), 'warning', 'zipcloseerror');
			$updraftplus->log("ZipArchive::Close returned an error. List of files we were trying to add follows (check their permissions).");
			foreach ($files_zipadded_since_open as $file) {
				$updraftplus->log("File: ".$file['addas']." (exists: ".(int)@file_exists($file['file']).", size: ".@filesize($file['file']).')');
			}
		}

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

		if (is_string($create_from_dir) && !file_exists($create_from_dir)) {
			$flag_error = true;
			$updraftplus->log("Does not exist: $create_from_dir");
			if ('mu-plugins' == $whichone) {
				if (!function_exists('get_mu_plugins')) require_once(ABSPATH.'wp-admin/includes/plugin.php');
				$mu_plugins = get_mu_plugins();
				if (count($mu_plugins) == 0) {
					$updraftplus->log("There appear to be no mu-plugins to back up. Will not raise an error.");
					$flag_error = false;
				}
			}
			if ($flag_error) $updraftplus->log(sprintf(__("%s - could not back this entity up; the corresponding directory does not exist (%s)", 'updraftplus'), $whichone, $create_from_dir), 'error');
			return false;
		}

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

		// New (Jun-13) - be more aggressive in removing temporary files from earlier attempts - anything >=600 seconds old of this kind
		$updraftplus->clean_temporary_files('_'.$updraftplus->nonce."-$whichone", 600);

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
		$zipcode = $this->make_zipfile($create_from_dir, $zip_name, $whichone);
		if ($zipcode !== true) {
			$updraftplus->log("ERROR: Zip failure: Could not create $whichone zip: code=$zipcode");
			$updraftplus->log(sprintf(__("Could not create %s zip. Consult the log file for more information.",'updraftplus'),$whichone), 'error');
			return false;
		} else {
			rename($full_path.'.tmp', $full_path);
			$timetaken = max(microtime(true)-$microtime_start, 0.000001);
			$kbsize = filesize($full_path)/1024;
			$rate = round($kbsize/$timetaken, 1);
			$updraftplus->log("Created $whichone zip - file size is ".round($kbsize,1)." Kb in ".round($timetaken,1)." s ($rate Kb/s)");
			// We can now remove any left-over temporary files from this job
			$updraftplus->clean_temporary_files('_'.$updraftplus->nonce."-$whichone", 0);
		}

		return basename($full_path);
	}

}
