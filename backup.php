<?php

if (!defined ('ABSPATH')) die('No direct access allowed');

if (!class_exists('UpdraftPlus_PclZip')) require(UPDRAFTPLUS_DIR.'/class-zip.php');

// This file contains functions that are only needed/loaded when a backup is running (reduces memory usage on other site pages)

global $updraftplus_backup;
$updraftplus_backup = new UpdraftPlus_Backup();

class UpdraftPlus_Backup {

	public $index = 0;

	private $zipfiles_added;
	private $zipfiles_added_thisrun = 0;
	private $zipfiles_dirbatched;
	private $zipfiles_batched;
	private $zip_split_every = 838860800; # 800Mb
	private $zip_last_ratio = 1;
	private $whichone;
	private $zip_basename = '';
	private $zipfiles_lastwritetime;
	// 0 = unknown; false = failed
	public $binzip = 0;

	private $dbhandle;
	private $dbhandle_isgz;

	private $use_zip_object = 'UpdraftPlus_ZipArchive';
	public $debug = false;

	private $updraft_dir;

	public function __construct() {

		global $updraftplus;

		# Decide which zip engine to begin with

		$this->debug = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');
		$this->updraft_dir = $updraftplus->backups_dir_location();

		// false means 'tried + failed'; whereas 0 means 'not yet tried'
		if ($this->binzip === 0 && (!defined('UPDRAFTPLUS_PREFERPCLZIP') || UPDRAFTPLUS_PREFERPCLZIP != true) && (!defined('UPDRAFTPLUS_NO_BINZIP') || !UPDRAFTPLUS_NO_BINZIP) && $updraftplus->current_resumption <9) {
			$updraftplus->log('Checking if we have a zip executable available');
			$binzip = $updraftplus->find_working_bin_zip();
			if (is_string($binzip)) {
				$updraftplus->log("Zip engine: found/will use a binary zip: $binzip");
				$this->binzip = $binzip;
				$this->use_zip_object = 'UpdraftPlus_BinZip';
			}
		}

		# In tests, PclZip was found to be 25% slower than ZipArchive
		if ($this->use_zip_object != 'UpdraftPlus_PclZip' && empty($this->binzip) && ((defined('UPDRAFTPLUS_PREFERPCLZIP') && UPDRAFTPLUS_PREFERPCLZIP == true) || !class_exists('ZipArchive') || !class_exists('UpdraftPlus_ZipArchive') || (!extension_loaded('zip') && !method_exists('ZipArchive', 'AddFile')))) {
			global $updraftplus;
			$updraftplus->log("Zip engine: ZipArchive is not available or is disabled (will use PclZip if needed)");
			$this->use_zip_object = 'UpdraftPlus_PclZip';
		}

	}

	public function create_zip($create_from_dir, $whichone, $backup_file_basename, $index) {
		// Note: $create_from_dir can be an array or a string
		@set_time_limit(900);

		$original_index = $index;
		$this->index = $index;
		$this->whichone = $whichone;

		global $updraftplus;

		$this->zip_split_every = max((int)$updraftplus->jobdata_get('split_every'), UPDRAFTPLUS_SPLIT_MIN)*1024*1024;

		if ('others' != $whichone) $updraftplus->log("Beginning creation of dump of $whichone (split every: ".round($this->zip_split_every/1048576,1)." Mb)");

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

		$itext = (empty($index)) ? '' : ($index+1);
		$base_path = $backup_file_basename.'-'.$whichone.$itext.'.zip';
		$full_path = $this->updraft_dir.'/'.$base_path;
		$time_now = time();

		if (file_exists($full_path)) {
			$time_mod = (int)@filemtime($full_path);
			$updraftplus->log($base_path.": this file has already been created (age: ".round($time_now-$time_mod,1)." s)");
			if ($time_mod>100 && ($time_now-$time_mod)<30) {
				$updraftplus->terminate_due_to_activity($base_path, $time_now, $time_mod);
			}
			# Gather any further files that may also exist
			$files_existing = array();
			while (file_exists($full_path)) {
				$files_existing[] = $base_path;
				$index++;
				$base_path = $backup_file_basename.'-'.$whichone.$index.'.zip';
				$full_path = $this->updraft_dir.'/'.$base_path;
			}
			return $files_existing;
		}

		// Temporary file, to be able to detect actual completion (upon which, it is renamed)

		// New (Jun-13) - be more aggressive in removing temporary files from earlier attempts - anything >=600 seconds old of this kind
		$updraftplus->clean_temporary_files('_'.$updraftplus->nonce."-$whichone", 600);

		// Firstly, make sure that the temporary file is not already being written to - which can happen if a resumption takes place whilst an old run is still active
		$zip_name = $full_path.'.tmp';
		$time_mod = (int)@filemtime($zip_name);
		if (file_exists($zip_name) && $time_mod>100 && ($time_now-$time_mod)<30) {
			$updraftplus->terminate_due_to_activity($zip_name, $time_now, $time_mod);
		} elseif (file_exists($zip_name)) {
			$updraftplus->log("File exists ($zip_name), but was apparently not modified within the last 30 seconds, so we assume that any previous run has now terminated (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod).")");
		}

		// Now, check for other forms of temporary file, which would indicate that some activity is going on (even if it hasn't made it into the main zip file yet)
		// Note: this doesn't catch PclZip temporary files
		$d = dir($this->updraft_dir);
		$match = '_'.$updraftplus->nonce."-".$whichone;
		while (false !== ($e = $d->read())) {
			if ('.' == $e || '..' == $e || !is_file($this->updraft_dir.'/'.$e)) continue;
			$ziparchive_match = preg_match("/$match([0-9]+)?\.zip\.tmp\.([A-Za-z0-9]){6}?$/i", $e);
			$binzip_match = preg_match("/^zi([A-Za-z0-9]){6}$/", $e);
			if ($time_now-filemtime($this->updraft_dir.'/'.$e) < 30 && ($ziparchive_match || $binzip_match)) {
				$updraftplus->terminate_due_to_activity($this->updraft_dir.'/'.$e, $time_now, filemtime($this->updraft_dir.'/'.$e));
			}
		}
		@$d->close();
		clearstatcache();

		$this->zip_microtime_start = microtime(true);
		# The paths in the zip should then begin with '$whichone', having removed WP_CONTENT_DIR from the front
		$zipcode = $this->make_zipfile($create_from_dir, $backup_file_basename, $whichone);
		if ($zipcode !== true) {
			$updraftplus->log("ERROR: Zip failure: Could not create $whichone zip (".$this->index." / $index)");
			$updraftplus->log(sprintf(__("Could not create %s zip. Consult the log file for more information.",'updraftplus'),$whichone), 'error');
			# The caller is required to update $index from $this->index
			return false;
		} else {
			$itext = (empty($this->index)) ? '' : ($this->index+1);
			$full_path = $this->updraft_dir.'/'.$backup_file_basename.'-'.$whichone.$itext.'.zip';
			if (file_exists($full_path.'.tmp')) {
				$sha = sha1_file($full_path.'.tmp');
				$updraftplus->jobdata_set('sha1-'.$whichone.$this->index, $sha);
				@rename($full_path.'.tmp', $full_path);
				$timetaken = max(microtime(true)-$this->zip_microtime_start, 0.000001);
				$kbsize = filesize($full_path)/1024;
				$rate = round($kbsize/$timetaken, 1);
				$updraftplus->log("Created $whichone zip (".$this->index.") - ".round($kbsize,1)." Kb in ".round($timetaken,1)." s ($rate Kb/s) (SHA1 checksum: $sha)");
				// We can now remove any left-over temporary files from this job
				
			} elseif ($this->index > $original_index) {
				$updraftplus->log("Did not create $whichone zip (".$this->index.") - not needed");
			} else {
				$updraftplus->log("Looked-for $whichone zip (".$this->index.") was not found (".basename($full_path).".tmp)", 'warning');
			}
			$updraftplus->clean_temporary_files('_'.$updraftplus->nonce."-$whichone", 0);
		}

		# Create the results array to send back (just the new ones, not any prior ones)
		$files_existing = array();
		$res_index = 0;
		for ($i = $original_index; $i<= $this->index; $i++) {
			$itext = (empty($i)) ? '' : ($i+1);
			$full_path = $this->updraft_dir.'/'.$backup_file_basename.'-'.$whichone.$itext.'.zip';
			if (file_exists($full_path)) {
				$files_existing[$res_index] = $backup_file_basename.'-'.$whichone.$itext.'.zip';
			}
			$res_index++;
		}
		return $files_existing;
	}

	// Dispatch to the relevant function
	public function cloud_backup($backup_array) {

		global $updraftplus;

		$services = $updraftplus->just_one($updraftplus->jobdata_get('service'));
		if (!is_array($services)) $services = array($services);

		$updraftplus->jobdata_set('jobstatus', 'clouduploading');

		add_action('http_api_curl', array($updraftplus, 'add_curl_capath'));

		$upload_status = $updraftplus->jobdata_get('uploading_substatus');
		if (!is_array($upload_status) || !isset($upload_status['t'])) {
			$upload_status = array('i' => 0, 't' => max(1, count($services))*count($backup_array));
			$updraftplus->jobdata_set('uploading_substatus', $upload_status);
		}

		$do_prune = array();

		# If there was no check-in last time, then attempt a different service first - in case a time-out on the attempted service leads to no activity and everything stopping
		if (count($services) >1 && !empty($updraftplus->no_checkin_last_time)) {
			$updraftplus->log('No check-in last time: will try a different remote service first');
			array_push($services, array_shift($services));
			if (1 == ($updraftplus->current_resumption % 2) && count($services)>2) array_push($services, array_shift($services));
		}

		foreach ($services as $ind => $service) {

			# Used for logging by record_upload_chunk()
			$this->current_service = $service;
			# Used when deciding whether to delete the local file
			$this->last_service = ($ind+1 >= count($services)) ? true : false;

			$updraftplus->log("Cloud backup selection: ".$service);
			@set_time_limit(900);

			$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
			if (file_exists($method_include)) require_once($method_include);

			if ($service == "none" || $service == "") {
				$updraftplus->log("No remote despatch: user chose no remote backup service");
				$this->prune_retained_backups(array("none" => array(null, null)));
			} else {
				$updraftplus->log("Beginning dispatch of backup to remote ($service)");
				$sarray = array();
				foreach ($backup_array as $bind => $file) {
					if ($updraftplus->is_uploaded($file, $service)) {
						$updraftplus->log("Already uploaded to $service: $file");
					} else {
						$sarray[$bind] = $file;
					}
				}
				if (count($sarray)>0) {
					$objname = "UpdraftPlus_BackupModule_${service}";
					if (class_exists($objname)) {
						$remote_obj = new $objname;
						$pass_to_prune = $remote_obj->backup($backup_array);
						$do_prune[$service] = array($remote_obj, $pass_to_prune);
					} else {
						$updraftplus->log("Unexpected error: no class '$objname' was found ($method_include)");
						$updraftplus->log(__("Unexpected error: no class '$objname' was found (your UpdraftPlus installation seems broken - try re-installing)",'updraftplus'), 'error');
					}
				}
			}
		}

		if (!empty($do_prune)) $this->prune_retained_backups($do_prune);

		remove_action('http_api_curl', array($updraftplus, 'add_curl_capath'));

	}

	// Carries out retain behaviour. Pass in a valid S3 or FTP object and path if relevant.
	// Services *must* be an array
	public function prune_retained_backups($services) {

		global $updraftplus;

		// If they turned off deletion on local backups, then there is nothing to do
		if (UpdraftPlus_Options::get_updraft_option('updraft_delete_local') == 0 && count($services) == 1 && in_array('none', $services)) {
			$updraftplus->log("Prune old backups from local store: nothing to do, since the user disabled local deletion and we are using local backups");
			return;
		}

		$updraftplus->jobdata_set('jobstatus', 'pruning');
		$updraftplus->log("Retain: beginning examination of existing backup sets");

		// Number of backups to retain - files
		$updraft_retain = UpdraftPlus_Options::get_updraft_option('updraft_retain', 1);
		$updraft_retain = (is_numeric($updraft_retain)) ? $updraft_retain : 1;
		$updraftplus->log("Retain files: user setting: number to retain = $updraft_retain");

		// Number of backups to retain - db
		$updraft_retain_db = UpdraftPlus_Options::get_updraft_option('updraft_retain_db', $updraft_retain);
		$updraft_retain_db = (is_numeric($updraft_retain_db)) ? $updraft_retain_db : 1;
		$updraftplus->log("Retain db: user setting: number to retain = $updraft_retain_db");

		// Returns an array, most recent first, of backup sets
		$backup_history = $updraftplus->get_backup_history();
		$db_backups_found = 0;
		$file_backups_found = 0;
		$updraftplus->log("Number of backup sets in history: ".count($backup_history));

		$backupable_entities = $updraftplus->get_backupable_file_entities(true);

		foreach ($backup_history as $backup_datestamp => $backup_to_examine) {
			// $backup_to_examine is an array of file names, keyed on db/plugins/themes/uploads
			// The new backup_history array is saved afterwards, so remember to unset the ones that are to be deleted
			$updraftplus->log("Examining backup set with datestamp: $backup_datestamp");

			if (isset($backup_to_examine['db'])) {
				$db_backups_found++;
				$fname = (is_string($backup_to_examine['db'])) ? $backup_to_examine['db'] : $backup_to_examine['db'][0];
				$updraftplus->log("$backup_datestamp: this set includes a database (".$fname."); db count is now $db_backups_found");
				if ($db_backups_found > $updraft_retain_db) {
					$updraftplus->log("$backup_datestamp: over retain limit ($updraft_retain_db); will delete this database");
					if (!empty($backup_to_examine['db'])) {
						foreach ($services as $service => $sd) $this->prune_file($service, $backup_to_examine['db'], $sd[0], $sd[1]);
					}
					unset($backup_to_examine['db']);
					$updraftplus->record_still_alive();
				}
			}

			$contains_files = false;
			foreach ($backupable_entities as $entity => $info) {
				if (isset($backup_to_examine[$entity])) {
					$contains_files = true;
					break;
				}
			}

			if ($contains_files) {
				$file_backups_found++;
				$updraftplus->log("$backup_datestamp: this set includes files; fileset count is now $file_backups_found");
				if ($file_backups_found > $updraft_retain) {
					$updraftplus->log("$backup_datestamp: over retain limit ($updraft_retain); will delete this file set");
					foreach ($backupable_entities as $entity => $info) {
						if (!empty($backup_to_examine[$entity])) {
							foreach ($services as $service => $sd) $this->prune_file($service, $backup_to_examine[$entity], $sd[0], $sd[1]);
						}
						unset($backup_to_examine[$entity]);
						$updraftplus->record_still_alive();
					}

				}
			}

			// Get new result, post-deletion
			$contains_files = false;
			foreach ($backupable_entities as $entity => $info) {
				if (isset($backup_to_examine[$entity])) {
					$contains_files = true;
					break;
				}
			}

			// Delete backup set completely if empty, o/w just remove DB
			// We search on the four keys which represent data, allowing other keys to be used to track other things
			if (!$contains_files && !isset($backup_to_examine['db']) ) {
				$updraftplus->log("$backup_datestamp: this backup set is now empty; will remove from history");
				unset($backup_history[$backup_datestamp]);
				if (isset($backup_to_examine['nonce'])) {
					$fullpath = $this->updraft_dir.'/log.'.$backup_to_examine['nonce'].'.txt';
					if (is_file($fullpath)) {
						$updraftplus->log("$backup_datestamp: deleting log file (log.".$backup_to_examine['nonce'].".txt)");
						@unlink($fullpath);
					} else {
						$updraftplus->log("$backup_datestamp: corresponding log file not found - must have already been deleted");
					}
				} else {
					$updraftplus->log("$backup_datestamp: no nonce record found in the backup set, so cannot delete any remaining log file");
				}
			} else {
				$updraftplus->log("$backup_datestamp: this backup set remains non-empty; will retain in history");
				$backup_history[$backup_datestamp] = $backup_to_examine;
			}
		}
		$updraftplus->log("Retain: saving new backup history (sets now: ".count($backup_history).") and finishing retain operation");
		UpdraftPlus_Options::update_updraft_option('updraft_backup_history',$backup_history);
	}

	private function prune_file($service, $dofiles, $method_object = null, $object_passback = null ) {
		global $updraftplus;
		if (!is_array($dofiles)) $dofiles=array($dofiles);
		foreach ($dofiles as $dofile) {
			if (empty($dofile)) continue;
			$updraftplus->log("Delete file: $dofile, service=$service");
			$fullpath = $this->updraft_dir.'/'.$dofile;
			// delete it if it's locally available
			if (file_exists($fullpath)) {
				$updraftplus->log("Deleting local copy ($dofile)");
				@unlink($fullpath);
			}
		}
		// Despatch to the particular method's deletion routine
		if (!is_null($method_object)) $method_object->delete($dofiles, $object_passback);
	}

	public function send_results_email($final_message) {

		global $updraftplus;

		$debug_mode = UpdraftPlus_Options::get_updraft_option('updraft_debug_mode');

		$sendmail_to = UpdraftPlus_Options::get_updraft_option('updraft_email');

		$backup_files = $updraftplus->jobdata_get('backup_files');
		$backup_db = $updraftplus->jobdata_get('backup_database');

		if ($backup_files == 'finished' && ( $backup_db == 'finished' || $backup_db == 'encrypted' ) ) {
			$backup_contains = "Files and database";
		} elseif ($backup_files == 'finished') {
			$backup_contains = ($backup_db == "begun") ? "Files (database backup has not completed)" : "Files only (database was not part of this particular schedule)";
		} elseif ($backup_db == 'finished' || $backup_db == 'encrypted') {
			$backup_contains = ($backup_files == "begun") ? "Database (files backup has not completed)" : "Database only (files were not part of this particular schedule)";
		} else {
			$backup_contains = "Unknown/unexpected error - please raise a support request";
		}

		$updraftplus->log("Sending email ('$backup_contains') report to: ".substr($sendmail_to, 0, 5)."...");

		$append_log = '';
		$attachments = array();
		if ($updraftplus->error_count() > 0) {
			$append_log .= __('Errors encountered:', 'updraftplus')."\r\n";
			$attachments[0] = $updraftplus->logfile_name;
			foreach ($updraftplus->errors as $err) {
				if (is_wp_error($err)) {
					foreach ($err->get_error_messages() as $msg) {
						$append_log .= "* ".rtrim($msg)."\r\n";
					}
				} elseif (is_array($err) && 'error' == $err['level']) {
					$append_log .= "* ".rtrim($err['message'])."\r\n";
				} elseif (is_string($err)) {
					$append_log .= "* ".rtrim($err)."\r\n";
				}
			}
			$append_log.="\n";
		}
		$warnings = $updraftplus->jobdata_get('warnings');
		if (is_array($warnings) && count($warnings) >0) {
			$append_log .= __('Warnings encountered:', 'updraftplus')."\r\n";
			$attachments[0] = $updraftplus->logfile_name;
			foreach ($warnings as $err) {
				$append_log .= "* ".rtrim($err)."\r\n";
			}
			$append_log.="\n";
		}

		$append_log .= ($debug_mode && $updraftplus->logfile_name != "") ? "\r\nLog contents:\r\n".file_get_contents($updraftplus->logfile_name) : "" ;

		// We have to use the action in order to set the MIME type on the attachment - by default, WordPress just puts application/octet-stream
		if (count($attachments)>0) add_action('phpmailer_init', array($this, 'phpmailer_init'));
		foreach (explode(',', $sendmail_to) as $sendmail_addr) {

			wp_mail(trim($sendmail_addr), __('Backed up', 'updraftplus').': '.get_bloginfo('name').' (UpdraftPlus '.$updraftplus->version.') '.get_date_from_gmt(gmdate('Y-m-d H:i:s', time()), 'Y-m-d H:i'),'Site: '.site_url()."\r\nUpdraftPlus: ".__('WordPress backup is complete','updraftplus').".\r\n".__('Backup contains','updraftplus').': '.$backup_contains."\r\n".__('Latest status', 'updraftplus').": $final_message\r\n\r\n".$updraftplus->wordshell_random_advert(0)."\r\n".$append_log);
			if (count($attachments)>0) remove_action('phpmailer_init', array($this, 'phpmailer_init'));
		}

	}

	// The purpose of this function is to make sure that the options table is put in the database first, then the users table, then the usermeta table; and after that the core WP tables - so that when restoring we restore the core tables first
	private function backup_db_sorttables($a, $b) {
		global $updraftplus, $wpdb;
		$our_table_prefix = $this->table_prefix;
		if ($a == $b) return 0;
		if ($a == $our_table_prefix.'options') return -1;
		if ($b ==  $our_table_prefix.'options') return 1;
		if ($a == $our_table_prefix.'users') return -1;
		if ($b ==  $our_table_prefix.'users') return 1;
		if ($a == $our_table_prefix.'usermeta') return -1;
		if ($b ==  $our_table_prefix.'usermeta') return 1;

		try {
			$core_tables = array_merge($wpdb->tables, $wpdb->global_tables, $wpdb->ms_global_tables);
		} catch (Exception $e) {
		}
		if (empty($core_tables)) $core_tables = array('terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'postmeta', 'posts', 'site', 'sitemeta', 'blogs', 'blogversions');

		global $updraftplus;
		$na = $updraftplus->str_replace_once($our_table_prefix, '', $a);
		$nb = $updraftplus->str_replace_once($our_table_prefix, '', $b);
		if (in_array($na, $core_tables) && !in_array($nb, $core_tables)) return -1;
		if (!in_array($na, $core_tables) && in_array($nb, $core_tables)) return 1;
		return strcmp($a, $b);
	}

	// This function is resumable
	public function backup_dirs($job_status) {

		global $updraftplus;

		if(!$updraftplus->backup_time) $updraftplus->backup_time_nonce();

		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', str_replace(' ','_', substr(get_bloginfo(), 0, 32)));
		if (!$blog_name) $blog_name = 'non_alpha_name';
		$blog_name = apply_filters('updraftplus_blog_name', $blog_name);

		$backup_file_basename = 'backup_'.get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraftplus->backup_time), 'Y-m-d-Hi').'_'.$blog_name.'_'.$updraftplus->nonce;

		$backup_array = array();

		$possible_backups = $updraftplus->get_backupable_file_entities(true);

		// Was there a check-in last time? If not, then reduce the amount of data attempted
		if ($job_status != 'finished' && $updraftplus->current_resumption >= 2 && $updraftplus->current_resumption<=10) {
			$maxzipbatch = $updraftplus->jobdata_get('maxzipbatch', 26214400);
			if ((int)$maxzipbatch < 1) $maxzipbatch = 26214400;

			# NOTYET: Possible amendment to original algorithm; not just no check-in, but if the check in was very early (can happen if we get a very early checkin for some trivial operation, then attempt something too big)
			if (!empty($updraftplus->no_checkin_last_time)) {
				$new_maxzipbatch = max(floor($maxzipbatch * 0.75), 20971520);
				if ($new_maxzipbatch < $maxzipbatch) {
					$updraftplus->log("No check-in was detected on the previous run - as a result, we are reducing the batch amount (old=$maxzipbatch, new=$new_maxzipbatch)");
					$updraftplus->jobdata_set('maxzipbatch', $new_maxzipbatch);
					$updraftplus->jobdata_set('maxzipbatch_ceiling', $new_maxzipbatch);
				}
			}
		}

		if($job_status != 'finished' && !$updraftplus->really_is_writable($this->updraft_dir)) {
			$updraftplus->log("Backup directory (".$this->updraft_dir.") is not writable, or does not exist");
			$updraftplus->log(sprintf(__("Backup directory (%s) is not writable, or does not exist.", 'updraftplus'), $this->updraft_dir), 'error');
			return array();
		}

		$job_file_entities = $updraftplus->jobdata_get('job_file_entities');
		# This is just used for the visual feedback (via the 'substatus' key)
		$which_entity = 0;
		# e.g. plugins, themes, uploads, others
		foreach ($possible_backups as $youwhat => $whichdir) {

			if (isset($job_file_entities[$youwhat])) {

				$index = (int)$job_file_entities[$youwhat]['index'];
				if (empty($index)) $index=0;
				$indextext = (0 == $index) ? '' : (1+$index);
				$zip_file = $this->updraft_dir.'/'.$backup_file_basename.'-'.$youwhat.$indextext.'.zip';

				# Split needed?
				$split_every=max((int)$updraftplus->jobdata_get('split_every'), 250);
				if (file_exists($zip_file) && filesize($zip_file) > $split_every*1024*1024) {
					$index++;
					$job_file_entities[$youwhat]['index'] = $index;
					$updraftplus->jobdata_set('job_file_entities', $job_file_entities);
				}

				// Populate prior parts of array, if we're on a subsequent zip file
				if ($index >0) {
					for ($i=0; $i<$index; $i++) {
						$itext = (0 == $i) ? '' : ($i+1);
						$backup_array[$youwhat][$i] = $backup_file_basename.'-'.$youwhat.$itext.'.zip';
						$z = $this->updraft_dir.'/'.$backup_file_basename.'-'.$youwhat.$itext.'.zip';
						$itext = (0 == $i) ? '' : $i;
						if (file_exists($z)) $backup_array[$youwhat.$itext.'-size'] = filesize($z);
					}
				}

				if ($job_status == 'finished') {
					// Add the final part of the array
					if ($index >0) {
						$fbase = $backup_file_basename.'-'.$youwhat.($index+1).'.zip';
						$z = $this->updraft_dir.'/'.$fbase;
						if (file_exists($z)) {
							$backup_array[$youwhat][$index] = $fbase;
							$backup_array[$youwhat.$index.'-size'] = filesize($z);
						}
					} else {
						$backup_array[$youwhat] = $backup_file_basename.'-'.$youwhat.'.zip';
						if (file_exists($zip_file)) $backup_array[$youwhat.'-size'] = filesize($zip_file);
					}
				} else {

					$which_entity++;
					$updraftplus->jobdata_set('filecreating_substatus', array('e' => $youwhat, 'i' => $which_entity, 't' => count($job_file_entities)));

					if ('others' == $youwhat) $updraftplus->log("Beginning backup of other directories found in the content directory (index: $index)");

					# Apply a filter to allow add-ons to provide their own method for creating a zip of the entity
					$created = apply_filters('updraftplus_backup_makezip_'.$youwhat, $whichdir, $backup_file_basename, $index);
					# If the filter did not lead to something being created, then use the default method
					if ($created == $whichdir) {

						// http://www.phpconcept.net/pclzip/user-guide/53
						/* First parameter to create is:
							An array of filenames or dirnames,
							or
							A string containing the filename or a dirname,
							or
							A string containing a list of filename or dirname separated by a comma.
						*/

						$dirlist = ('others' == $youwhat) ? $updraftplus->backup_others_dirlist() : $whichdir;

						if (count($dirlist)>0) {
							$created = $this->create_zip($dirlist, $youwhat, $backup_file_basename, $index);
							# Now, store the results
							if (!is_string($created) && !is_array($created)) $updraftplus->log("$youwhat: create_zip returned an error");
						} else {
							$updraftplus->log("No backup of $youwhat: there was nothing found to back up");
						}
					}

					if ( $created != $whichdir && (is_string($created) || is_array($created))) {
						if (is_string($created)) $created=array($created);
						foreach ($created as $findex => $fname) {
							$backup_array[$youwhat][$index] = $fname;
							$itext = ($index == 0) ? '' : $index;
							$index++;
							$backup_array[$youwhat.$itext.'-size'] = filesize($this->updraft_dir.'/'.$fname);
						}
					}

					$job_file_entities[$youwhat]['index'] = $this->index;
					$updraftplus->jobdata_set('job_file_entities', $job_file_entities);

				}
			} else {
				$updraftplus->log("No backup of $youwhat: excluded by user's options");
			}
		}

		return $backup_array;
	}

	// This uses a saved status indicator; its only purpose is to indicate *total* completion; there is no actual danger, just wasted time, in resuming when it was not needed. So the saved status indicator just helps save resources.
	public function resumable_backup_of_files($resumption_no) {
		global $updraftplus;
		//backup directories and return a numerically indexed array of file paths to the backup files
		$bfiles_status = $updraftplus->jobdata_get('backup_files');
		if ('finished' == $bfiles_status) {
			$updraftplus->log("Creation of backups of directories: already finished");
			$backup_array = $updraftplus->jobdata_get('backup_files_array');
			if (!is_array($backup_array)) $backup_array = array();

			# Check for recent activity
			foreach ($backup_array as $files) {
				if (!is_array($files)) $files=array($files);
				foreach ($files as $file) $updraftplus->check_recent_modification($this->updraft_dir.'/'.$file);
			}

		} elseif ('begun' == $bfiles_status) {
			if ($resumption_no>0) {
				$updraftplus->log("Creation of backups of directories: had begun; will resume");
			} else {
				$updraftplus->log("Creation of backups of directories: beginning");
			}
			$updraftplus->jobdata_set('jobstatus', 'filescreating');
			$backup_array = $this->backup_dirs($bfiles_status);
			$updraftplus->jobdata_set('backup_files_array', $backup_array);
			$updraftplus->jobdata_set('backup_files', 'finished');
			$updraftplus->jobdata_set('jobstatus', 'filescreated');
		} else {
			# This is not necessarily a backup run which is meant to contain files at all
			$updraftplus->log("This backup run is not intended for files - skipping");
			return array();
		}

		/*
		// DOES NOT WORK: there is no crash-safe way to do this here - have to be renamed at cloud-upload time instead
		$new_backup_array = array();
		foreach ($backup_array as $entity => $files) {
			if (!is_array($files)) $files=array($files);
			$outof = count($files);
			foreach ($files as $ind => $file) {
				$nval = $file;
				if (preg_match('/^(backup_[\-0-9]{15}_.*_[0-9a-f]{12}-[\-a-z]+)([0-9]+)?\.zip$/i', $file, $matches)) {
					$num = max((int)$matches[2],1);
					$new = $matches[1].$num.'of'.$outof.'.zip';
					if (file_exists($this->updraft_dir.'/'.$file)) {
						if (@rename($this->updraft_dir.'/'.$file, $this->updraft_dir.'/'.$new)) {
							$updraftplus->log(sprintf("Renaming: %s to %s", $file, $new));
							$nval = $new;
						}
					} elseif (file_exists($this->updraft_dir.'/'.$new)) {
						$nval = $new;
					}
				}
				$new_backup_array[$entity][$ind] = $nval;
			}
		}
		*/
		return $backup_array;
	}

	/* This function is resumable, using the following method:
	- Each table is written out to ($final_filename).table.tmp
	- When the writing finishes, it is renamed to ($final_filename).table
	- When all tables are finished, they are concatenated into the final file
	*/
	public function backup_db($already_done = "begun") {

		global $updraftplus, $wpdb;

		$this->table_prefix = $updraftplus->get_table_prefix();

		$errors = 0;

		if (!$updraftplus->backup_time) $updraftplus->backup_time_nonce();
		if (!$updraftplus->opened_log_time) $updraftplus->logfile_open($updraftplus->nonce);

		// Get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', str_replace(' ','_', substr(get_bloginfo(), 0, 32)));
		if (!$blog_name) $blog_name = 'non_alpha_name';
		$blog_name = apply_filters('updraftplus_blog_name', $blog_name);

		$file_base = 'backup_'.get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraftplus->backup_time), 'Y-m-d-Hi').'_'.$blog_name.'_'.$updraftplus->nonce;
		$backup_file_base = $this->updraft_dir.'/'.$file_base;

		if ('finished' == $already_done) return basename($backup_file_base.'-db.gz');
		if ('encrypted' == $already_done) return basename($backup_file_base.'-db.gz.crypt');

		$updraftplus->jobdata_set('jobstatus', 'dbcreating');

		$binsqldump = $updraftplus->find_working_sqldump();

		$total_tables = 0;

		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);

		// Put the options table first
		usort($all_tables, array($this, 'backup_db_sorttables'));

		if (!$updraftplus->really_is_writable($this->updraft_dir)) {
			$updraftplus->log("The backup directory (".$this->updraft_dir.") is not writable.");
			$updraftplus->log($this->updraft_dir.": ".__('The backup directory is not writable - the database backup is expected to shortly fail.','updraftplus'), 'warning');
			# Why not just fail now? We saw a bizarre case when the results of really_is_writable() changed during the run.
		}

		$stitch_files = array();

		$how_many_tables = count($all_tables);

		foreach ($all_tables as $table) {

			$manyrows_warning = false;
			$total_tables++;

			// Increase script execution time-limit to 15 min for every table.
			@set_time_limit(900);
			// The table file may already exist if we have produced it on a previous run
			$table_file_prefix = $file_base.'-db-table-'.$table.'.table';
			if (file_exists($this->updraft_dir.'/'.$table_file_prefix.'.gz')) {
				$updraftplus->log("Table $table: corresponding file already exists; moving on");
			} else {
				// Open file, store the handle
				$opened = $this->backup_db_open($this->updraft_dir.'/'.$table_file_prefix.'.tmp.gz', true);
				if (false === $opened) return false;
				# === is needed, otherwise 'false' matches (i.e. prefix does not match)
				if ( strpos($table, $this->table_prefix) === 0 ) {
					// Create the SQL statements
					$this->stow("# " . sprintf(__('Table: %s','wp-db-backup'),$updraftplus->backquote($table)) . "\n");
					$updraftplus->jobdata_set('dbcreating_substatus', array('t' => $table, 'i' => $total_tables, 'a' => $how_many_tables));

					$table_status = $wpdb->get_row("SHOW TABLE STATUS WHERE Name='$table'");
					if (isset($table_status->Rows)) {
						$rows = $table_status->Rows;
						$updraftplus->log("Table $table: Total expected rows (approximate): ".$rows);
						$this->stow("# Approximate rows expected in table: $rows\n");
						if ($rows > UPDRAFTPLUS_WARN_DB_ROWS) {
							$manyrows_warning = true;
							$updraftplus->log(sprintf(__("Table %s has very many rows (%s) - we hope your web hosting company gives you enough resources to dump out that table in the backup", 'updraftplus'), $table, $rows), 'warning', 'manyrows_'.$table);
						}
					}

					# Don't include the job data for any backups - so that when the database is restored, it doesn't continue an apparently incomplete backup
					if  ($this->table_prefix.'sitemeta' == $table) {
						$where = 'meta_key NOT LIKE "updraft_jobdata_%"';
					} elseif ($this->table_prefix.'options' == $table) {
						$where = 'option_name NOT LIKE "updraft_jobdata_%"';
					} else {
						$where = '';
					}

					# TODO: If no check-in last time, then try the other method (but - any point in retrying slow method on large tables??)

					# TODO: Lower this from 10,000 if the feedback is good
					$bindump = (isset($rows) && $rows>10000 && is_string($binsqldump)) ? $this->backup_table_bindump($binsqldump, $table, $where) : false;
					if (!$bindump) $this->backup_table($table, $where);

					if (!empty($manyrows_warning)) $updraftplus->log_removewarning('manyrows_'.$table);

				} else {
					$this->stow("# " . sprintf(__('Skipping table (lacks our prefix): %s','wp-db-backup'),$updraftplus->backquote($table)) . "\n");
				}
				// Close file
				$this->close($this->dbhandle);
				$updraftplus->log("Table $table: finishing file (${table_file_prefix}.gz)");
				rename($this->updraft_dir.'/'.$table_file_prefix.'.tmp.gz', $this->updraft_dir.'/'.$table_file_prefix.'.gz');
				$updraftplus->something_useful_happened();
			}
			$stitch_files[] = $table_file_prefix;
		}

		// Race detection - with zip files now being resumable, these can more easily occur, with two running side-by-side
		$backup_final_file_name = $backup_file_base.'-db.gz';
		$time_now = time();
		$time_mod = (int)@filemtime($backup_final_file_name);
		if (file_exists($backup_final_file_name) && $time_mod>100 && ($time_now-$time_mod)<30) {
			$updraftplus->terminate_due_to_activity($backup_final_file_name, $time_now, $time_mod);
		} elseif (file_exists($backup_final_file_name)) {
			$updraftplus->log("The final database file ($backup_final_file_name) exists, but was apparently not modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod)."). Thus we assume that another UpdraftPlus terminated; thus we will continue.");
		}

		// Finally, stitch the files together
		$opendb = $this->backup_db_open($backup_final_file_name, true);
		if (false === $opendb) return false;
		$this->backup_db_header();

		// We delay the unlinking because if two runs go concurrently and fail to detect each other (should not happen, but there's no harm in assuming the detection failed) then that leads to files missing from the db dump
		$unlink_files = array();

		foreach ($stitch_files as $table_file) {
			$updraftplus->log("{$table_file}.gz: adding to final database dump");
			if (!$handle = gzopen($this->updraft_dir.'/'.$table_file.'.gz', "r")) {
				$updraftplus->log("Error: Failed to open database file for reading: ${table_file}.gz");
				$updraftplus->log("Failed to open database file for reading: ${table_file}.gz", 'error');
				$errors++;
			} else {
				while ($line = gzgets($handle, 2048)) { $this->stow($line); }
				gzclose($handle);
				$unlink_files[] = $this->updraft_dir.'/'.$table_file.'.gz';
			}
		}

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
		}

		$updraftplus->log($file_base.'-db.gz: finished writing out complete database file ('.round(filesize($backup_final_file_name)/1024,1).' Kb)');
		if (!$this->close($this->dbhandle)) {
			$updraftplus->log('An error occurred whilst closing the final database file');
			$updraftplus->log(__('An error occurred whilst closing the final database file', 'updraftplus'), 'error');
			$errors++;
		}

		foreach ($unlink_files as $unlink_file) @unlink($unlink_file);

		if ($errors > 0) {
			return false;
		} else {
			# We no longer encrypt here - because the operation can take long, we made it resumable and moved it to the upload loop
			$updraftplus->jobdata_set('jobstatus', 'dbcreated');
			$sha = sha1_file($backup_final_file_name);
			$updraftplus->jobdata_set('sha1-db0', $sha);
			$updraftplus->log("Total database tables backed up: $total_tables (".basename($backup_final_file_name).": checksum (SHA1): $sha)");
			return basename($backup_file_base.'-db.gz');
		}

	} //wp_db_backup

	private function backup_table_bindump($potsql, $table_name, $where) {

		$microtime = microtime(true);

		global $updraftplus;

		$pfile = md5(time().rand()).'.tmp';
		file_put_contents($this->updraft_dir.'/'.$pfile, "[mysqldump]\npassword=".DB_PASSWORD."\n");

		if ($where) $where="--where='".escapeshellarg($where)."'";

		$exec = "cd ".escapeshellarg($this->updraft_dir)."; $potsql  --defaults-file=$pfile $where --max_allowed_packet=1M --quote-names --add-drop-table --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --user=".escapeshellarg(DB_USER)." --host=".escapeshellarg(DB_HOST)." ".DB_NAME." ".escapeshellarg($table_name);

		$ret = false;
		$any_output = false;
		$writes = 0;
		$handle = popen($exec, "r");
		if ($handle) {
			while (!feof($handle)) {
				$w = fgets($handle);
				if ($w) {
					$this->stow($w);
					$writes++;
					$any_output = true;
				}
			}
			$ret = pclose($handle);
			if ($ret != 0) {
				$updraftplus->log("Binary mysqldump: error (code: $ret)");
				// Keep counter of failures? Change value of binsqldump?
			} else {
				if ($any_output) {
					$updraftplus->log("Table $table_name: binary mysqldump finished (writes: $writes) in ".sprintf("%.02f",max(microtime(true)-$microtime,0.00001))." seconds");
					$ret = true;
				}
			}
		} else {
			$updraftplus->log("Binary mysqldump error: bindump popen failed");
		}

		# Clean temporary files
		@unlink($this->updraft_dir.'/'.$pfile);

		return $ret;

	}

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	private function backup_table($table, $where = '', $segment = 'none') {
		global $wpdb, $updraftplus;

		$microtime = microtime(true);

		$total_rows = 0;

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			//$updraftplus->log(__('Error getting table details','wp-db-backup') . ": $table", 'error');
			return false;
		}
	
		if($segment == 'none' || $segment == 0) {
			// Add SQL statement to drop existing table
			$this->stow("\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup'),$updraftplus->backquote($table)) . "\n\n");
			$this->stow("DROP TABLE IF EXISTS " . $updraftplus->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n");
			$this->stow("# " . sprintf(__('Table structure of table %s','wp-db-backup'),$updraftplus->backquote($table)) . "\n\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE `$table`", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
				//$updraftplus->log($err_msg, 'error');
				$this->stow("#\n# $err_msg\n#\n");
			}
			$create_line = $updraftplus->str_lreplace('TYPE=', 'ENGINE=', $create_table[0][1]);

			# Remove PAGE_CHECKSUM parameter from MyISAM - was internal, undocumented, later removed (so causes errors on import)
			if (preg_match('/ENGINE=([^\s;]+)/', $create_line, $eng_match)) {
				$engine = $eng_match[1];
				if ('myisam' == strtolower($engine)) {
					$create_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $create_line, 1);
				}
			}

			$this->stow($create_line.' ;');
			
			if (false === $table_structure) {
				$err_msg = sprintf('Error getting table structure of %s', $table);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n# " . sprintf('Data contents of table %s',$updraftplus->backquote($table)) . "\n\n");

		}
		
		// In UpdraftPlus, segment is always 'none'
		if($segment == 'none' || $segment >= 0) {
			$defs = array();
			$integer_fields = array();
			// $table_structure was from "DESCRIBE $table"
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) || (0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) || (0 === strpos(strtolower($struct->Type), 'int')) || (0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$integer_fields[strtolower($struct->Field)] = "1";
				}
			}

			// Experimentation here shows that on large tables (we tested with 180,000 rows) on MyISAM, 1000 makes the table dump out 3x faster than the previous value of 100. After that, the benefit diminishes (increasing to 4000 only saved another 12%)
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = 1000;
			} else {
				$row_start = $segment * 1000;
				$row_inc = 1000;
			}

			$search = array("\x00", "\x0a", "\x0d", "\x1a");
			$replace = array('\0', '\n', '\r', '\Z');

			if ($where) $where = "WHERE $where";

			do {
				@set_time_limit(900);

				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);
				$entries = 'INSERT INTO ' . $updraftplus->backquote($table) . ' VALUES ';
				//    \x08\\x09, not required
				if($table_data) {
					$thisentry = "";
					foreach ($table_data as $row) {
						$total_rows++;
						$values = array();
						foreach ($row as $key => $value) {
							if (isset($integer_fields[strtolower($key)])) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = (null === $value) ? 'NULL' : "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
							}
						}
						if ($thisentry) $thisentry .= ",\n ";
						$thisentry .= '('.implode(', ', $values).')';
						// Flush every 512Kb
						if (strlen($thisentry) > 524288) {
							$this->stow(" \n".$entries.$thisentry.';');
							$thisentry = "";
						}
						
					}
					if ($thisentry) $this->stow(" \n".$entries.$thisentry.';');
					$row_start += $row_inc;
				}
			} while(count($table_data) > 0 && 'none' == $segment);
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup'),$updraftplus->backquote($table)) . "\n");
			$this->stow("\n");
		}
 		$updraftplus->log("Table $table: Total rows added: $total_rows in ".sprintf("%.02f",max(microtime(true)-$microtime,0.00001))." seconds");

	} // end backup_table()


	/*END OF WP-DB-BACKUP BLOCK */

	// Encrypts the file if the option is set; returns the basename of the file (according to whether it was encrypted or nto)
	public function encrypt_file($file) {
		global $updraftplus;
		$encryption = UpdraftPlus_Options::get_updraft_option('updraft_encryptionphrase');
		if (strlen($encryption) > 0) {
			$updraftplus->log("$file: applying encryption");
			$updraftplus->jobdata_set('jobstatus', 'dbencrypting');
			$encryption_error = 0;
			$microstart = microtime(true);
			$file_size = @filesize($this->updraft_dir.'/'.$file)/1024;

			if (false === file_put_contents($this->updraft_dir.'/'.$file.'.crypt' , $updraftplus->encrypt($this->updraft_dir.'/'.$file, $encryption))) $encryption_error = 1;
			if (0 == $encryption_error) {
				$time_taken = max(0.000001, microtime(true)-$microstart);

				$sha = sha1_file($this->updraft_dir.'/'.$file.'.crypt');
				$updraftplus->jobdata_set('sha1-db0.crypt', $sha);

				$updraftplus->log("$file: encryption successful: ".round($file_size,1)."Kb in ".round($time_taken,2)."s (".round($file_size/$time_taken, 1)."Kb/s) (SHA1 checksum: $sha)");
				# Delete unencrypted file
				@unlink($this->updraft_dir.'/'.$file);
				$updraftplus->jobdata_set('jobstatus', 'dbencrypted');
				return basename($file.'.crypt');
			} else {
				$updraftplus->log("Encryption error occurred when encrypting database. Encryption aborted.");
				$updraftplus->log(__("Encryption error occurred when encrypting database. Encryption aborted.",'updraftplus'), 'error');
				return basename($file);
			}
		} else {
			return basename($file);
		}
	}

	private function close($handle) {
		if ($this->dbhandle_isgz) {
			return gzclose($handle);
		} else {
			return fclose($handle);
		}
	}

	// Open a file, store its filehandle
	private function backup_db_open($file, $allow_gz = true) {
		if (function_exists('gzopen') && $allow_gz == true) {
			$this->dbhandle = @gzopen($file, 'w');
			$this->dbhandle_isgz = true;
		} else {
			$this->dbhandle = @fopen($file, 'w');
			$this->dbhandle_isgz = false;
		}
		if(false === $this->dbhandle) {
			global $updraftplus;
			$updraftplus->log("ERROR: $file: Could not open the backup file for writing");
			$updraftplus->log($file.": ".__("Could not open the backup file for writing",'updraftplus'), 'error');
		}
		return $this->dbhandle;
	}

	private function stow($query_line) {
		if ($this->dbhandle_isgz) {
			if(! @gzwrite($this->dbhandle, $query_line)) {
				//$updraftplus->log(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg, 'error');
			}
		} else {
			if(false === @fwrite($this->dbhandle, $query_line)) {
				//$updraftplus->log(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg, 'error');
			}
		}
	}

	private function backup_db_header() {

		@include(ABSPATH.'wp-includes/version.php');
		global $wp_version, $updraftplus;

		// Will need updating when WP stops being just plain MySQL
		$mysql_version = (function_exists('mysql_get_server_info')) ? @mysql_get_server_info() : '?';

		$this->stow("# WordPress MySQL database backup\n");
		$this->stow("# Created by UpdraftPlus version ".$updraftplus->version." (http://updraftplus.com)\n");
		$this->stow("# WordPress Version: $wp_version, running on PHP ".phpversion()." (".$_SERVER["SERVER_SOFTWARE"]."), MySQL $mysql_version\n");
		$this->stow("# Backup of: ".site_url()."\n");
		$this->stow("# Home URL: ".home_url()."\n");
		$this->stow("# Table prefix: ".$this->table_prefix."\n");
		$this->stow("# Site info: multisite=".(is_multisite() ? '1' : '0')."\n");
		$this->stow("# Site info: end\n");

		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$updraftplus->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
			$this->stow("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
			$this->stow("/*!40101 SET NAMES " . DB_CHARSET . " */;\n");
		}
		$this->stow("/*!40101 SET foreign_key_checks = 0 */;\n\n");
	}

	public function phpmailer_init($phpmailer) {
		global $updraftplus;
		$phpmailer->AddAttachment($updraftplus->logfile_name, '', 'base64', 'text/plain');
	}

	// This function recursively packs the zip, dereferencing symlinks but packing into a single-parent tree for universal unpacking
	private function makezip_recursive_add($fullpath, $use_path_when_storing, $original_fullpath) {

		$zipfile = $this->zip_basename.(($this->index == 0) ? '' : ($this->index+1)).'.zip.tmp';

		global $updraftplus;

		// De-reference. Important to do to both, because on Windows only doing it to one can make them non-equal, where they were previously equal - something which we later rely upon
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
				$this->makezip_recursive_batchedbytes += @filesize($fullpath);
				#@touch($zipfile);
			} else {
				$updraftplus->log("$fullpath: unreadable file");
				$updraftplus->log(sprintf(__("%s: unreadable file - could not be backed up (check the file permissions)", 'updraftplus'), $fullpath), 'warning');
			}
		} elseif (is_dir($fullpath)) {
			if (!isset($this->existing_files[$use_path_when_storing])) $this->zipfiles_dirbatched[] = $use_path_when_storing;
			if (!$dir_handle = @opendir($fullpath)) {
				$updraftplus->log("Failed to open directory: $fullpath");
				$updraftplus->log(sprintf(__("Failed to open directory (check the file permissions): %s",'updraftplus'), $fullpath), 'error');
				return false;
			}
			while (false !== ($e = readdir($dir_handle))) {
				if ($e != '.' && $e != '..') {
					if (is_link($fullpath.'/'.$e)) {
						$deref = realpath($fullpath.'/'.$e);
						if (is_file($deref)) {
							if (is_readable($deref)) {
								$this->zipfiles_batched[$deref] = $use_path_when_storing.'/'.$e;
								$this->makezip_recursive_batchedbytes += @filesize($deref);
								#@touch($zipfile);
							} else {
								$updraftplus->log("$deref: unreadable file");
								$updraftplus->log(sprintf(__("%s: unreadable file - could not be backed up"), $deref), 'warning');
							}
						} elseif (is_dir($deref)) {
							$this->makezip_recursive_add($deref, $use_path_when_storing.'/'.$e, $original_fullpath);
						}
					} elseif (is_file($fullpath.'/'.$e)) {
						if (is_readable($fullpath.'/'.$e)) {
							$this->zipfiles_batched[$fullpath.'/'.$e] = $use_path_when_storing.'/'.$e;
							$this->makezip_recursive_batchedbytes += @filesize($fullpath.'/'.$e);
							#@touch($zipfile);
						} else {
							$updraftplus->log("$fullpath/$e: unreadable file");
							$updraftplus->log(sprintf(__("%s: unreadable file - could not be backed up", 'updraftplus'), $use_path_when_storing.'/'.$e), 'warning');
						}
					} elseif (is_dir($fullpath.'/'.$e)) {
						// no need to addEmptyDir here, as it gets done when we recurse
						$this->makezip_recursive_add($fullpath.'/'.$e, $use_path_when_storing.'/'.$e, $original_fullpath);
					}
				}
			}
			closedir($dir_handle);
		} else {
			$updraftplus->log("Unexpected: path fails both is_file() and is_dir(): $fullpath");
		}

		// We don't want to tweak the zip file on every single file, so we batch them up
		// We go every 25 files, because if you wait too much longer, the contents may have changed from under you. Note though that since this fires once-per-directory, the actual number by this stage may be much larger; the most we saw was over 3000; but in that case, makezip_addfiles() will split the write-out up into smaller chunks
		// And for some redundancy (redundant because of the touches going on anyway), we try to touch the file after 20 seconds, to help with the "recently modified" check on resumption (we saw a case where the file went for 155 seconds without being touched and so the other runner was not detected)
		if (count($this->zipfiles_batched) > 25 || (file_exists($zipfile) && ((time()-filemtime($zipfile)) > 20) )) {
			$ret = true;
			# In fact, this is entirely redundant, and slows things down - the logic in makezip_addfiles() now does this, much better
			# If adding this back in, then be careful - we now assume that makezip_recursive_add() does *not* touch the zipfile
// 			$ret = $this->makezip_addfiles();
		} else {
			$ret = true;
		}

		return $ret;

	}

	// Caution: $source is allowed to be an array, not just a filename
	// $destination is the temporary file (ending in .tmp)
	private function make_zipfile($source, $backup_file_basename, $whichone = '') {

		global $updraftplus;

		$original_index = $this->index;

		$itext = (empty($this->index)) ? '' : ($this->index+1);
		$destination_base = $backup_file_basename.'-'.$whichone.$itext.'.zip.tmp';
		$destination = $this->updraft_dir.'/'.$destination_base;

		// Legacy/redundant
		if (empty($whichone) && is_string($whichone)) $whichone = basename($source);

		// When to prefer PCL:
		// - We were asked to
		// - No zip extension present and no relevant method present
		// The zip extension check is not redundant, because method_exists segfaults some PHP installs, leading to support requests

		// We need meta-info about $whichone
		$backupable_entities = $updraftplus->get_backupable_file_entities(true, false);

		# This is only used by one corner-case in BinZip
		$this->make_zipfile_source = (isset($backupable_entities[$whichone])) ? $backupable_entities[$whichone] : $source;

		$this->existing_files = array();
		# Used for tracking compression ratios
		$this->existing_files_rawsize = 0;
		$this->existing_zipfiles_size = 0;

		// Enumerate existing files
		for ($j=0; $j<=$this->index; $j++) {
			$jtext = ($j == 0) ? '' : ($j+1);
			$examine_zip = $this->updraft_dir.'/'.$backup_file_basename.'-'.$whichone.$jtext.'.zip'.(($j == $this->index) ? '.tmp' : '');

			// If the file exists, then we should grab its index of files inside, and sizes
			// Then, when we come to write a file, we should check if it's already there, and only add if it is not
			if (file_exists($examine_zip) && is_readable($examine_zip) && filesize($examine_zip)>0) {

				$this->existing_zipfiles_size += filesize($examine_zip);
				$zip = new $this->use_zip_object;
				if (!$zip->open($examine_zip)) {
					$updraftplus->log("Could not open zip file to examine (".$zip->last_error."); will remove: ".basename($examine_zip));
					@unlink($examine_zip);
				} else {

					# Don't put this in the for loop, or the magic __get() method gets called and opens the zip file every time the loop goes round
					$numfiles = $zip->numFiles;

					for ($i=0; $i < $numfiles; $i++) {
						$si = $zip->statIndex($i);
						$name = $si['name'];
						$this->existing_files[$name] = $si['size'];
						$this->existing_files_rawsize += $si['size'];
					}

					@$zip->close();
				}

				$updraftplus->log(basename($examine_zip).": Zip file already exists, with ".count($this->existing_files)." files");

			} elseif (file_exists($examine_zip)) {
				$updraftplus->log("Zip file already exists, but is not readable or was zero-sized; will remove: ".basename($examine_zip));
				@unlink($examine_zip);
			}
		}

		$this->zip_last_ratio = ($this->existing_files_rawsize > 0) ? ($this->existing_zipfiles_size/$this->existing_files_rawsize) : 1;

		$this->zipfiles_added = 0;
		$this->zipfiles_added_thisrun = 0;
		$this->zipfiles_dirbatched = array();
		$this->zipfiles_batched = array();
		$this->zipfiles_lastwritetime = time();

		$this->zip_basename = $this->updraft_dir.'/'.$backup_file_basename.'-'.$whichone;

		$error_occured = false;

		# Store this in its original form
		$this->source = $source;

		# Reset. This counter is used only with PcLZip, to decide if it's better to do it all-in-one
		$this->makezip_recursive_batchedbytes = 0;
		if (!is_array($source)) $source=array($source);
		foreach ($source as $element) {
			$add_them = $this->makezip_recursive_add($element, basename($element), $element);
			if (is_wp_error($add_them) || false === $add_them) $error_occured = true;
		}

		// Any not yet dispatched? Under our present scheme, at this point nothing has yet been despatched. And since the enumerating of all files can take a while, we can at this point do a further modification check to reduce the chance of overlaps.
		// This relies on us *not* touch()ing the zip file to indicate to any resumption 'behind us' that we're already here. Rather, we're relying on the combined facts that a) if it takes us a while to search the directory tree, then it should do for the one behind us too (though they'll have the benefit of cache, so could catch very fast) and b) we touch *immediately* after finishing the enumeration of the files to add.
		$updraftplus->check_recent_modification($destination);
		// Here we're relying on the fact that both PclZip and ZipArchive will happily operate on an empty file. Note that BinZip *won't* (for that, may need a new strategy - e.g. add the very first file on its own, in order to 'lay down a marker')
		@touch($destination);

		if (count($this->zipfiles_dirbatched)>0 || count($this->zipfiles_batched)>0) {
			$updraftplus->log(sprintf("Total entities for the zip file: %d directories, %d files, %s Mb", count($this->zipfiles_dirbatched), count($this->zipfiles_batched), round($this->makezip_recursive_batchedbytes/1048576,1)));
			$add_them = $this->makezip_addfiles();
			if (is_wp_error($add_them)) {
				foreach ($add_them->get_error_messages() as $msg) {
					$updraftplus->log("Error returned from makezip_addfiles: ".$msg);
				}
				$error_occured = true;
			} elseif (false === $add_them) {
				$updraftplus->log("Error: makezip_addfiles returned false");
				$error_occured = true;
			}
		}

		# Reset these variables because the index may have changed since we began

		$itext = (empty($this->index)) ? '' : ($this->index+1);
		$destination_base = $backup_file_basename.'-'.$whichone.$itext.'.zip.tmp';
		$destination = $this->updraft_dir.'/'.$destination_base;

		if ($this->zipfiles_added > 0 || $error_occured == false) {
			// ZipArchive::addFile sometimes fails
			if ((file_exists($destination) || $this->index == $original_index) && @filesize($destination) < 90 && 'UpdraftPlus_ZipArchive' == $this->use_zip_object) {
				$updraftplus->log("makezip_addfiles(ZipArchive) apparently failed ($last_error, type=$whichone, size=".filesize($destination).") - retrying with PclZip");
				$this->use_zip_object = 'UpdraftPlus_PclZip';
				return $this->make_zipfile($source, $backup_file_basename, $whichone);
			}
			return true;
		} else {
			# If ZipArchive, and if an error occurred, and if apparently ZipArchive did nothing, then immediately retry with PclZip. Q. Why this specific criteria? A. Because we've seen it in the wild, and it's quicker to try PcLZip now than waiting until resumption 9 when the automatic switchover happens.
			if ($error_occurred != false && (file_exists($destination) || $this->index == $original_index) && @filesize($destination) < 90 && 'UpdraftPlus_ZipArchive' == $this->use_zip_object) {
				$updraftplus->log("makezip_addfiles(ZipArchive) apparently failed ($last_error, type=$whichone, size=".filesize($destination).") - retrying with PclZip");
				$this->use_zip_object = 'UpdraftPlus_PclZip';
				return $this->make_zipfile($source, $backup_file_basename, $whichone);
			}
			$updraftplus->log("makezip failure: zipfiles_added=".$this->zipfiles_added.", error_occurred=".$error_occurred." (method=".$this->use_zip_object.")");
			return false;
		}

	}

	// Q. Why don't we only open and close the zip file just once?
	// A. Because apparently PHP doesn't write out until the final close, and it will return an error if anything file has vanished in the meantime. So going directory-by-directory reduces our chances of hitting an error if the filesystem is changing underneath us (which is very possible if dealing with e.g. 1Gb of files)

	// We batch up the files, rather than do them one at a time. So we are more efficient than open,one-write,close.
	private function makezip_addfiles() {

		global $updraftplus;

		# Used to detect requests to bump the size
		$bump_index = false;

		$zipfile = $this->zip_basename.(($this->index == 0) ? '' : ($this->index+1)).'.zip.tmp';

		$maxzipbatch = $updraftplus->jobdata_get('maxzipbatch', 26214400);
		if ((int)$maxzipbatch < 1) $maxzipbatch = 26214400;

		// Short-circuit the null case, because we want to detect later if something useful happenned
		if (count($this->zipfiles_dirbatched) == 0 && count($this->zipfiles_batched) == 0) return true;

		# If on PclZip, then if possible short-circuit to a quicker method (makes a huge time difference - on a folder of 1500 small files, 2.6s instead of 76.6)
		# This assumes that makezip_addfiles() is only called once so that we know about all needed files (the new style)
		# This is rather conservative - because it assumes zero compression. But we can't know that in advance.
		if (0 == $this->index && 'UpdraftPlus_PclZip' == $this->use_zip_object && $this->makezip_recursive_batchedbytes < $this->zip_split_every && ($this->makezip_recursive_batchedbytes < 512*1024*1024 || (defined('UPDRAFTPLUS_PCLZIP_FORCEALLINONE') && UPDRAFTPLUS_PCLZIP_FORCEALLINONE == true))) {
			$updraftplus->log("PclZip, and only one archive required - will attempt to do in single operation (data: ".round($this->makezip_recursive_batchedbytes/1024,1)." Kb, split: ".round($this->zip_split_every/1024, 1)." Kb)");
			if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
			$zip = new PclZip($zipfile);
			$remove_path = ($this->whichone == 'wpcore') ? untrailingslashit(ABSPATH) : WP_CONTENT_DIR;
			$add_path = false;
			// Remove prefixes
			$backupable_entities = $updraftplus->get_backupable_file_entities(true);
			if (isset($backupable_entities[$this->whichone])) {
					if ('plugins' == $this->whichone || 'themes' == $this->whichone || 'uploads' == $this->whichone) {
						$remove_path = dirname($backupable_entities[$this->whichone]);
						# To normalise instead of removing (which binzip doesn't support, so we don't do it), you'd remove the dirname() in the above line, and uncomment the below one.
						#$add_path = $this->whichone;
					} else {
						$remove_path = $backupable_entities[$this->whichone];
					}
			}
			if ($add_path) {
					$zipcode = $zip->create($this->source, PCLZIP_OPT_REMOVE_PATH, $remove_path, PCLZIP_OPT_ADD_PATH, $add_path);
			} else {
				$zipcode = $zip->create($this->source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
			}
			if ($zipcode == 0 ) {
				$updraftplus->log("PclZip Error: ".$zip->errorInfo(true), 'warning');
				return $zip->errorCode();
			} else {
				return true;
			}
		}

		// 05-Mar-2013 - added a new check on the total data added; it appears that things fall over if too much data is contained in the cumulative total of files that were addFile'd without a close-open cycle; presumably data is being stored in memory. In the case in question, it was a batch of MP3 files of around 100Mb each - 25 of those equals 2.5Gb!

		$data_added_since_reopen = 0;
		# The following array is used only for error reporting if ZipArchive::close fails (since that method itself reports no error messages - we have to track manually what we were attempting to add)
		$files_zipadded_since_open = array();

		$zip = new $this->use_zip_object;
		if (file_exists($zipfile)) {
			$opencode = $zip->open($zipfile);
			$original_size = filesize($zipfile);
			clearstatcache();
		} else {
			$create_code = (defined('ZIPARCHIVE::CREATE')) ? ZIPARCHIVE::CREATE : 1;
			$opencode = $zip->open($zipfile, $create_code);
			$original_size = 0;
		}

		if ($opencode !== true) return new WP_Error('no_open', sprintf(__('Failed to open the zip file (%s) - %s', 'updraftplus'),$zipfile, $zip->last_error));
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
				- that adding this batch of data is likely already enough to take us over the split limit (and if that happens, then do actually split - to prevent a scenario of progressively tinier writes as we approach but don't actually reach the limit)
				- more than 500 files batched (should perhaps intelligently lower this as the zip file gets bigger - not yet needed)
				*/

				# Add 10% margin. It only really matters when the OS has a file size limit, exceeding which causes failure (e.g. 2Gb on 32-bit)
				# Since we don't test before the file has been created (so that zip_last_ratio has meaningful data), we rely on max_zip_batch being less than zip_split_every - which should always be the case
				$reaching_split_limit = ( $this->zip_last_ratio > 0 && $original_size>0 && ($original_size + 1.1*$data_added_since_reopen*$this->zip_last_ratio) > $this->zip_split_every) ? true : false;

				if ($zipfiles_added_thisbatch > 500 || $reaching_split_limit || $data_added_since_reopen > $maxzipbatch || (time() - $this->zipfiles_lastwritetime) > 1.5) {

					$something_useful_sizetest = false;

					if ($data_added_since_reopen > $maxzipbatch) {
						$something_useful_sizetest = true;
						$updraftplus->log("Adding batch to zip file (".$this->use_zip_object."): over ".round($maxzipbatch/1048576,1)." Mb added on this batch (".round($data_added_since_reopen/1048576,1)." Mb, ".count($this->zipfiles_batched)." files batched, $zipfiles_added_thisbatch (".$this->zipfiles_added_thisrun.") added so far); re-opening (prior size: ".round($original_size/1024,1).' Kb)');
					} elseif ($zipfiles_added_thisbatch >500) {
						$updraftplus->log("Adding batch to zip file (".$this->use_zip_object."): over 500 files added on this batch (".round($data_added_since_reopen/1048576,1)." Mb, ".count($this->zipfiles_batched)." files batched, $zipfiles_added_thisbatch (".$this->zipfiles_added_thisrun.") added so far); re-opening (prior size: ".round($original_size/1024,1).' Kb)');
					} elseif (!$reaching_split_limit) {
						$updraftplus->log("Adding batch to zip file (".$this->use_zip_object."): over 1.5 seconds have passed since the last write (".round($data_added_since_reopen/1048576,1)." Mb, $zipfiles_added_thisbatch (".$this->zipfiles_added_thisrun.") files added so far); re-opening (prior size: ".round($original_size/1024,1).' Kb)');
					} else {
						$updraftplus->log("Adding batch to zip file (".$this->use_zip_object."): possibly approaching split limit (".round($data_added_since_reopen/1048576,1)." Mb, $zipfiles_added_thisbatch (".$this->zipfiles_added_thisrun.") files added so far); last ratio: ".round($this->zip_last_ratio,4)."; re-opening (prior size: ".round($original_size/1024,1).' Kb)');
					}
					if (!$zip->close()) {
						$updraftplus->log(__('A zip error occurred - check your log for more details.', 'updraftplus'), 'warning', 'zipcloseerror');
						$updraftplus->log("The attempt to close the zip file returned an error (".$zip->last_error."). List of files we were trying to add follows (check their permissions).");
						foreach ($files_zipadded_since_open as $ffile) {
							$updraftplus->log("File: ".$ffile['addas']." (exists: ".(int)@file_exists($ffile['file']).", size: ".@filesize($ffile['file']).')');
						}
					}
					$zipfiles_added_thisbatch = 0;
					
					# This triggers a re-open, later
					unset($zip);
					$files_zipadded_since_open = array();
					// Call here, in case we've got so many big files that we don't complete the whole routine
					if (filesize($zipfile) > $original_size) {

						# It is essential that this does not go above 1, even though in reality (and this can happen at the start, if just 1 file is added (e.g. due to >1.5s detection) the 'compressed' zip file may be *bigger* than the files stored in it. When that happens, if the ratio is big enough, it can then fire the "approaching split limit" detection (very) prematurely
						$this->zip_last_ratio = ($data_added_since_reopen > 0) ? min((filesize($zipfile) - $original_size)/$data_added_since_reopen, 1) : 1;

						# We need a rolling update of this
						$original_size = filesize($zipfile);

						# Move on to next zip?
						if ($reaching_split_limit || filesize($zipfile) > $this->zip_split_every) {
							$bump_index = true;
							# Take the filesize now because later we wanted to know we did clearstatcache()
							$bumped_at = round(filesize($zipfile)/1048576, 1);
						}

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

								# Don't go above the split amount (though we expect that to be higher anyway, unless sending via email)
								$new_maxzipbatch = min($new_maxzipbatch, $this->zip_split_every);

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
			if ($this->zipfiles_added % 100 == 0) $updraftplus->log("Zip: ".basename($zipfile).": ".$this->zipfiles_added." files added (on-disk size: ".round(@filesize($zipfile)/1024,1)." Kb)");

			if ($bump_index) {
				$updraftplus->log(sprintf("Zip size is at/near split limit (%s Mb / %s Mb) - bumping index (from: %d)", $bumped_at, round($this->zip_split_every/1048576, 1), $this->index));
				$bump_index = false;
				$this->bump_index();
				$zipfile = $this->zip_basename.($this->index+1).'.zip.tmp';
			}
			if (empty($zip)) {
				$zip = new $this->use_zip_object;

				if (file_exists($zipfile)) {
					$opencode = $zip->open($zipfile);
					$original_size = filesize($zipfile);
					clearstatcache();
				} else {
					$create_code = (defined('ZIPARCHIVE::CREATE')) ? ZIPARCHIVE::CREATE : 1;
					$opencode = $zip->open($zipfile, $create_code);
					$original_size = 0;
				}

				if ($opencode !== true) return new WP_Error('no_open', sprintf(__('Failed to open the zip file (%s) - %s', 'updraftplus'),$zipfile, $zip->last_error));
			}

		}

		# Reset array
		$this->zipfiles_batched = array();

		$ret = $zip->close();
		if (!$ret) {
			$updraftplus->log(__('A zip error occurred - check your log for more details.', 'updraftplus'), 'warning', 'zipcloseerror');
			$updraftplus->log("Closing the zip file returned an error (".$zip->last_error."). List of files we were trying to add follows (check their permissions).");
			foreach ($files_zipadded_since_open as $ffile) {
				$updraftplus->log("File: ".$ffile['addas']." (exists: ".(int)@file_exists($ffile['file']).", size: ".@filesize($ffile['file']).')');
			}
		}

		$this->zipfiles_lastwritetime = time();
		# May not exist if the last thing we did was bump
		if (file_exists($zipfile) && filesize($zipfile) > $original_size) $updraftplus->something_useful_happened();

		# Move on to next archive?
		if (file_exists($zipfile) && filesize($zipfile) > $this->zip_split_every) {
			$updraftplus->log(sprintf("Zip size has gone over split limit (%s, %s) - bumping index (%d)", round(filesize($zipfile)/1048576,1), round($this->zip_split_every/1048576, 1), $this->index));
			$this->bump_index();
		}

		clearstatcache();

		return $ret;
	}

	private function bump_index() {
		global $updraftplus;
		$job_file_entities = $updraftplus->jobdata_get('job_file_entities');
		$youwhat = $this->whichone;

		$timetaken = max(microtime(true)-$this->zip_microtime_start, 0.000001);

		$itext = ($this->index == 0) ? '' : ($this->index+1);
		$full_path = $this->zip_basename.$itext.'.zip';
		$sha = sha1_file($full_path.'.tmp');
		$updraftplus->jobdata_set('sha1-'.$youwhat.$this->index, $sha);
		@rename($full_path.'.tmp', $full_path);
		$kbsize = filesize($full_path)/1024;
		$rate = round($kbsize/$timetaken, 1);
		$updraftplus->log("Created ".$this->whichone." zip (".$this->index.") - ".round($kbsize,1)." Kb in ".round($timetaken,1)." s ($rate Kb/s) (SHA1 checksum: ".$sha.")");
		$this->zip_microtime_start = microtime(true);

		# No need to add $itext here - we can just delete any temporary files for this zip
		$updraftplus->clean_temporary_files('_'.$updraftplus->nonce."-".$youwhat, 600);

		$this->index++;
		$job_file_entities[$youwhat]['index']=$this->index;
		$updraftplus->jobdata_set('job_file_entities', $job_file_entities);
	}

}