<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed.');

# TODO: Regions
# TODO: Test alternative credentials

# SDK uses namespacing - requires PHP 5.3
use OpenCloud\Rackspace;

# New SDK - https://github.com/rackspace/php-opencloud and http://docs.rackspace.com/sdks/guide/content/php.html
# Uploading: https://github.com/rackspace/php-opencloud/blob/master/docs/userguide/ObjectStore/Storage/Object.md

# Extends the oldsdk: only in that we re-use a few small functions
class UpdraftPlus_BackupModule_cloudfiles_opencloudsdk extends UpdraftPlus_BackupModule_cloudfiles_oldsdk {

	public function get_service($user, $apikey, $authurl, $useservercerts = false, $disablesslverify = null) {

		require_once(UPDRAFTPLUS_DIR.'/includes/php-opencloud/autoload.php');

		global $updraftplus;

		# The new authentication APIs don't match the values we were storing before
		$new_authurl = ('https://lon.auth.api.rackspacecloud.com' == $authurl) ? Rackspace::UK_IDENTITY_ENDPOINT : Rackspace::US_IDENTITY_ENDPOINT;

		if (null === $disablesslverify) $disablesslverify = UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify');

		$updraftplus->log("Cloud Files authentication URL: ".$new_authurl);

		$client = new Rackspace($new_authurl, array(
			'username' => $user,
			'apiKey' => $apikey
		));

		if ($disablesslverify) {
			$client->setSslVerification(false);
		} else {
			if ($useservercerts) {
				$client->setConfig(array($client::SSL_CERT_AUTHORITY, 'system'));
			} else {
				$client->setSslVerification(UPDRAFTPLUS_DIR.'/includes/cacert.pem', true, 2);
			}
		}

		$service = $client->objectStoreService('cloudFiles');

		return $service;

	}

	public function backup($backup_array) {

		global $updraftplus, $updraftplus_backup;

		$opts = $this->get_opts();

		$this->container = $opts['path'];

		try {
			$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'));
		} catch(AuthenticationError $e) {
			$updraftplus->log('Cloud Files authentication failed ('.$e->getMessage().')');
			$updraftplus->log(__('Cloud Files authentication failed', 'updraftplus').' ('.$e->getMessage().')', 'error');
			return false;
		} catch (Exception $e) {
			$updraftplus->log('Cloud Files error - failed to access the container ('.$e->getMessage().') (line: '.$e->getLine().', file: '.$e->getFile().')');
			$updraftplus->log(__('Cloud Files error - failed to access the container', 'updraftplus').' ('.$e->getMessage().')', 'error');
			return false;
		}
		# Get the container
		try {
			$this->container_object = $service->getContainer($this->container);
		} catch (Exception $e) {
			$updraftplus->log('Could not access Cloud Files container ('.get_class($e).', '.$e->getMessage().') (line: '.$e->getLine().', file: '.$e->getFile().')');
			$updraftplus->log(__('Could not access Cloud Files container', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')', 'error');
			return false;
		}

		foreach ($backup_array as $key => $file) {

			# First, see the object's existing size (if any)
			$uploaded_size = $this->get_remote_size($file);

			try {
				if (1 === $updraftplus->chunked_upload($this, $file, "cloudfiles://".$this->container."/$file", 'Cloud Files', 5*1024*1024, $uploaded_size)) {
					try {
						if (false !== ($data = fopen($updraftplus->backups_dir_location().'/'.$file, 'r+'))) {
							$this->container_object->uploadObject($file, $data);
							fclose($data);
							$updraftplus->log("$logname regular upload: success");
							$updraftplus->uploaded_file($file);
						} else {
							throw new Exception('uploadObject failed: fopen failed');
						}
					} catch (Exception $e) {
						$this->log("$logname regular upload: failed ($file) (".$e->getMessage().")");
						$this->log("$file: ".sprintf(__('%s Error: Failed to upload','updraftplus'),$logname), 'error');
					}
				}
			} catch (Exception $e) {
				$updraftplus->log(__('Cloud Files error - failed to upload file', 'updraftplus').' ('.$e->getMessage().') (line: '.$e->getLine().', file: '.$e->getFile().')');
				$updraftplus->log(sprintf(__('%s error - failed to upload file', 'updraftplus'),'Cloud Files').' ('.$e->getMessage().')', 'error');
				return false;
			}
		}

		return array('cloudfiles_object' => $this->container_object, 'cloudfiles_orig_path' => $opts['path'], 'cloudfiles_container' => $this->container);

	}

	function get_remote_size($file) {
		try {
			$response = $this->container_object->getClient()->head($this->container_object->getUrl($file))->send();
			$response_object = $this->container_object->dataObject()->populateFromResponse($response)->setName($file);;
			return $response_object->getContentLength();
		} catch (Exception $e) {
			# Allow caller to distinguish between zero-sized and not-found
			return false;
		}
	}

	function chunked_upload_finish($file) {

		$chunk_path = 'chunk-do-not-delete-'.$file;

		try {

			$headers = array(
				'Content-Length'    => 0,
				'X-Object-Manifest' => sprintf('%s/%s/%s/', 
					$this->container,
					$file, 
					$chunk_path
				)
			);
			
			$url = $this->container_object->getUrl($file);
			$this->container_object->getClient()->put($url, $headers)->send();
			return true;

		} catch (Exception $e) {
			return false;
		}
	}

	function chunked_upload($file, $fp, $i, $upload_size, $upload_start, $upload_end) {

		global $updraftplus;

		$upload_remotepath = 'chunk-do-not-delete-'.$file.'_'.$i;

		$remote_size = $this->get_remote_size($upload_remotepath);

		// Without this, some versions of Curl add Expect: 100-continue, which results in Curl then giving this back: curl error: 55) select/poll returned error
		// Didn't make the difference - instead we just check below for actual success even when Curl reports an error
		// $chunk_object->headers = array('Expect' => '');

		if ($remote_size >= $upload_size) {
			$updraftplus->log("Cloud Files: Chunk $i ($upload_start - $upload_end): already uploaded");
		} else {
			$updraftplus->log("Cloud Files: Chunk $i ($upload_start - $upload_end): begin upload");
			// Upload the chunk
			try {
				$data = fread($fp, $upload_size);
				$this->container_object->uploadObject($upload_remotepath, $data);
			} catch (Exception $e) {
				$updraftplus->log("Cloud Files chunk upload: error: ($file / $i) (".$e->getMessage().") (line: ".$e->getLine().', file: '.$e->getFile().')');
				// Experience shows that Curl sometimes returns a select/poll error (curl error 55) even when everything succeeded. Google seems to indicate that this is a known bug.
				
				$remote_size = $this->get_remote_size($upload_remotepath);

				if ($remote_size >= $upload_size) {
					$updraftplus->log("$file: Chunk now exists; ignoring error (presuming it was an apparently known curl bug)");
				} else {
					$updraftplus->log("$file: ".sprintf(__('%s Error: Failed to upload','updraftplus'),'Cloud Files'), 'error');
					return false;
				}
			}
		}
		return true;
	}


	public function delete($files, $data = false) {

		global $updraftplus;
		if (is_string($files)) $files = array($files);

		if (is_array($data)) {
			$container_object = $data['cloudfiles_object'];
			$container = $data['cloudfiles_container'];
			$path = $data['cloudfiles_orig_path'];
		} else {
			$opts = $this->get_opts();
			$container = $opts['path'];
			$path = $container;
			try {
				$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'));
			} catch(AuthenticationError $e) {
				$updraftplus->log('Cloud Files authentication failed ('.$e->getMessage().')');
				$updraftplus->log(__('Cloud Files authentication failed', 'updraftplus').' ('.$e->getMessage().')', 'error');
				return false;
			} catch (Exception $e) {
				$updraftplus->log('Cloud Files error - failed to access the container ('.$e->getMessage().')');
				$updraftplus->log(__('Cloud Files error - failed to access the container', 'updraftplus').' ('.$e->getMessage().')', 'error');
				return false;
			}
			# Get the container
			try {
				$container_object = $service->getContainer($container);
			} catch (Exception $e) {
				$updraftplus->log('Could not access Cloud Files container ('.get_class($e).', '.$e->getMessage().')');
				$updraftplus->log(__('Could not access Cloud Files container', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')', 'error');
				return false;
			}

		}

		$ret = true;
		foreach ($files as $file) {

			$updraftplus->log("Cloud Files: Delete remote: container=$container, path=$file");

			// We need to search for chunks
			$chunk_path = "chunk-do-not-delete-".$file;

			try {
				$objects = $container_object->objectList(array('prefix' => $chunk_path));
				$index = 0;
				while (false !== ($chunk = $objects->offsetGet($index)) && !empty($chunk)) {
					try {
						$name = $chunk->name;
						$container_object->dataObject()->setName($name)->delete();
						$updraftplus->log('Cloud Files: Chunk deleted: '.$name);
					} catch (Exception $e) {
						$updraftplus->log("Cloud Files chunk delete failed: $name: ".$e->getMessage());
					}
					$index++;
				}
			} catch (Exception $e) {
				$updraftplus->log('Cloud Files chunk delete failed: '.$e->getMessage());
			}

			# Finally, delete the object itself
			try {
				$container_object->dataObject()->setName($file)->delete();
				$updraftplus->log('Cloud Files: Deleted: '.$file);
			} catch (Exception $e) {
				$updraftplus->log('Cloud Files delete failed: '.$e->getMessage());
				$ret = false;
			}
		}
		return $ret;
	}

	function download($file) {

		global $updraftplus;

		$opts = $this->get_opts();

		try {
			$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'));
		} catch(AuthenticationError $e) {
			$updraftplus->log('Cloud Files authentication failed ('.$e->getMessage().')');
			$updraftplus->log(__('Cloud Files authentication failed', 'updraftplus').' ('.$e->getMessage().')', 'error');
			return false;
		} catch (Exception $e) {
			$updraftplus->log('Cloud Files error - failed to access the container ('.$e->getMessage().')');
			$updraftplus->log(__('Cloud Files error - failed to access the container', 'updraftplus').' ('.$e->getMessage().')', 'error');
			return false;
		}

		$container = untrailingslashit($opts['path']);
		$updraftplus->log("Cloud Files download: cloudfiles://$container/$file");

		# Get the container
		try {
			$container_object = $service->getContainer($container);
		} catch (Exception $e) {
			$updraftplus->log('Could not access Cloud Files container ('.get_class($e).', '.$e->getMessage().')');
			$updraftplus->log(__('Could not access Cloud Files container', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')', 'error');
			return false;
		}

		# Get information about the object within the container
		if (false === ($remote_size = $this->get_remote_size($file))) {
			$updraftplus->log('Could not access Cloud Files object ('.get_class($e).', '.$e->getMessage().')');
			$updraftplus->log(__('Could not access Cloud Files object', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')', 'error');
			return false;
		}

		return (is_numeric($remote_size)) ? $updraftplus->chunked_download($file, $this, $remote_size, true, $container_object) : false;

	}

	public static function chunked_download($file, $headers, $container_object) {
		try {
			$dl = $container_object->getObject($file, $headers);
		} catch (Exception $e) {
			global $updraftplus;
			$updraftplus->log("$file: Failed to download (".$e->getMessage().")");
			$updraftplus->log("$file: ".sprintf(__("%s Error",'updraftplus'), 'Cloud Files').": ".__('Error downloading remote file: Failed to download'.' ('.$e->getMessage().")",'updraftplus'), 'error');
			return false;
		}
		return $dl->getContent();
	}

	public static function credentials_test() {

		if (empty($_POST['apikey'])) {
			printf(__("Failure: No %s was given.",'updraftplus'),__('API key','updraftplus'));
			die;
		}

		if (empty($_POST['user'])) {
			printf(__("Failure: No %s was given.",'updraftplus'),__('Username','updraftplus'));
			die;
		}

		$key = stripslashes($_POST['apikey']);
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
			$path = '';
		}

		if (empty($container)) {
			_e('Failure: No container details were given.' ,'updraftplus');
			return;
		}

		try {
			$service = self::get_service($user, $key, $authurl, $useservercerts, $disableverify);
		} catch(AuthenticationError $e) {
			echo __('Cloud Files authentication failed', 'updraftplus').' ('.$e->getMessage().')';
			die;
		} catch (Exception $e) {
			echo __('Cloud Files authentication failed', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')';
			die;
		}

		try {
			$container_object = $service->getContainer($container);
		} catch(Guzzle\Http\Exception\ClientErrorResponseException $e) {
			$container_object = $service->createContainer($container);
		} catch (Exception $e) {
			echo __('Cloud Files authentication failed', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')';
			die;
		}

		if (!is_a($container_object, 'OpenCloud\ObjectStore\Resource\Container') && !is_a($container_object, 'Container')) {
			echo __('Cloud Files authentication failed', 'updraftplus').' ('.get_class($container_object).')';
			die;
		}

		$try_file = md5(rand()).'.txt';

		try {
			$object = $container_object->uploadObject($try_file, 'UpdraftPlus test file', array('content-type' => 'text/plain'));
		} catch (Exception $e) {
			echo __('Cloud Files error - we accessed the container, but failed to create a file within it', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')';
			return;
		}

		echo __('Success', 'updraftplus').": ${container_verb}".__('We accessed the container, and were able to create files within it.', 'updraftplus');

		try {
			if (!empty($object)) @$object->delete();
		} catch (Exception $e) {
		}

	}

}
