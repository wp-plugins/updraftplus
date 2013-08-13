<?php

if (!defined ('ABSPATH')) die('No direct access allowed');

# We just add a last_error variable for comaptibility with our UpdraftPlus_PclZip object
class UpdraftPlus_ZipArchive extends ZipArchive {
	public $last_error = '(Unknown: ZipArchive does not return error messages)';
}

#class UpdraftPlus_BinZip {
#}

# A ZipArchive compatibility layer, with behaviour sufficient for our usage of ZipArchive
class UpdraftPlus_PclZip {

	private $pclzip;
	private $path;
	private $addfiles;
	private $adddirs;
	private $statindex;
	public $last_error;

	function __construct() {
		$this->addfiles = array();
		$this->adddirs = array();
	}

	public function __get($name) {
		if ($name != 'numFiles') return null;

		if (empty($this->pclzip)) return false;

		$statindex = $this->pclzip->listContent();

		if (empty($statindex)) {
			$this->statindex=array();
			return 0;
		}

		$result = array();
		foreach ($statindex as $i => $file) {
			if (!isset($statindex[$i]['folder']) || 0 == $statindex[$i]['folder']) {
				$result[] = $file;
			}
			unset($statindex[$i]);
		}

		$this->statindex=$result;

		return count($this->statindex);

	}

	public function statIndex($i) {

		if (empty($this->statindex[$i])) return array('name' => null, 'size' => 0);

		return array('name' => $this->statindex[$i]['filename'], 'size' => $this->statindex[$i]['size']);

	}

	public function open($path, $flags = 0) {
		if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
		if(!class_exists('PclZip')) {
			$this->last_error = "No PclZip class was found";
			return false;
		}

		$ziparchive_create_match = (defined('ZIPARCHIVE::CREATE')) ? ZIPARCHIVE::CREATE : 1;

		if ($flags == 1 && file_exists($path)) @unlink($path);

		$this->pclzip = new PclZip($path);
		if (empty($this->pclzip)) {
			$this->last_error = 'Could not get a PclZip object';
			return false;
		}

		# Make the empty directory we need to implement addEmptyDir()
		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();
		if (!is_dir($updraft_dir.'/emptydir') && !mkdir($updraft_dir.'/emptydir')) {
			$this->last_error = "Could not create empty directory ($updraft_dir/emptydir)";
			return false;
		}

		$this->path = $path;

		return true;

	}

	# Do the actual write-out - it is assumed that close() is where this is done. Needs to return true/false
	public function close() {
		if (empty($this->pclzip)) {
			$this->last_error = 'Zip file was not opened';
			return false;
		}

		global $updraftplus;
		$updraft_dir = $updraftplus->backups_dir_location();

		$activity = false;

		# Add the empty directories
		foreach ($this->adddirs as $dir) {
			if (false == $this->pclzip->add($updraft_dir.'/emptydir', PCLZIP_OPT_REMOVE_PATH, $updraft_dir.'/emptydir', PCLZIP_OPT_ADD_PATH, $dir)) {
				$this->last_error = $this->pclzip->errorInfo(true);
				return false;
			}
			$activity = true;
		}

		foreach ($this->addfiles as $rdirname => $adirnames) {
			foreach ($adirnames as $adirname => $files) {
				if (false == $this->pclzip->add($files, PCLZIP_OPT_REMOVE_PATH, $rdirname, PCLZIP_OPT_ADD_PATH, $adirname)) {
					$this->last_error = $this->pclzip->errorInfo(true);
					return false;
				}
				$activity = true;
			}
			unset($this->addfiles[$rdirname]);
		}

		$this->pclzip = false;
		$this->addfiles = array();
		$this->adddirs = array();

		clearstatcache();
		if ($activity && filesize($this->path) < 50) {
			$this->last_error = "Write failed - unknown cause (check your file permissions)";
			return false;
		}

		return true;
	}

	# Note: basename($add_as) is irrelevant; that is, it is actually basename($file) that will be used. But these are always identical in our usage.
	public function addFile($file, $add_as) {
		# Add the files. PclZip appears to do the whole (copy zip to temporary file, add file, move file) cycle for each file - so batch them as much as possible. We have to batch by dirname(). On a test with 1000 files of 25Kb each in the same directory, this reduced the time needed on that directory from 120s to 15s (or 5s with primed caches).
		$rdirname = dirname($file);
		$adirname = dirname($add_as);
		$this->addfiles[$rdirname][$adirname][] = $file;
	}

	# PclZip doesn't have a direct way to do this
	public function addEmptyDir($dir) {
		$this->adddirs[] = $dir;
	}

}