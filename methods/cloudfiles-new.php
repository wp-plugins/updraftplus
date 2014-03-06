<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed.');

# SDK uses namespacing - requires PHP 5.3 (actually the SDK states its requirements as 5.3.3)
use OpenCloud\Rackspace;

# New SDK - https://github.com/rackspace/php-opencloud and http://docs.rackspace.com/sdks/guide/content/php.html
# Uploading: https://github.com/rackspace/php-opencloud/blob/master/docs/userguide/ObjectStore/Storage/Object.md

# Extends the oldsdk: only in that we re-use a few small functions
class UpdraftPlus_BackupModule_cloudfiles_opencloudsdk extends UpdraftPlus_BackupModule_cloudfiles_oldsdk {

	const CHUNK_SIZE = 5242880;

	public $client;

	public function get_service($user, $apikey, $authurl, $useservercerts = false, $disablesslverify = null, $region = null) {

		require_once(UPDRAFTPLUS_DIR.'/oc/autoload.php');

		global $updraftplus;

		# The new authentication APIs don't match the values we were storing before
		$new_authurl = ('https://lon.auth.api.rackspacecloud.com' == $authurl || 'uk' == $authurl) ? Rackspace::UK_IDENTITY_ENDPOINT : Rackspace::US_IDENTITY_ENDPOINT;

		if (null === $disablesslverify) $disablesslverify = UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify');

		if (empty($user) || empty($apikey)) throw new Exception(__('Authorisation failed (check your credentials)', 'updraftplus'));

		$updraftplus->log("Cloud Files authentication URL: ".$new_authurl);

		$client = new Rackspace($new_authurl, array(
			'username' => $user,
			'apiKey' => $apikey
		));
		$this->client = $client;

		if ($disablesslverify) {
			$client->setSslVerification(false);
		} else {
			if ($useservercerts) {
				$client->setConfig(array($client::SSL_CERT_AUTHORITY, 'system'));
			} else {
				$client->setSslVerification(UPDRAFTPLUS_DIR.'/includes/cacert.pem', true, 2);
			}
		}

		return $client->objectStoreService('cloudFiles', $region);

	}

	public function backup($backup_array) {

		global $updraftplus, $updraftplus_backup;

		$opts = $this->get_opts();

		$this->container = $opts['path'];

		try {
			$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'), $opts['region']);
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
				if (1 === $updraftplus->chunked_upload($this, $file, "cloudfiles://".$this->container."/$file", 'Cloud Files', UpdraftPlus_BackupModule_cloudfiles_opencloudsdk::CHUNK_SIZE, $uploaded_size)) {
					try {
						if (false !== ($data = fopen($updraftplus->backups_dir_location().'/'.$file, 'r+'))) {
							$this->container_object->uploadObject($file, $data);
							$updraftplus->log("Cloud Files regular upload: success");
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

	private function get_remote_size($file) {
		try {
			$response = $this->container_object->getClient()->head($this->container_object->getUrl($file))->send();
			$response_object = $this->container_object->dataObject()->populateFromResponse($response)->setName($file);
			return $response_object->getContentLength();
		} catch (Exception $e) {
			# Allow caller to distinguish between zero-sized and not-found
			return false;
		}
	}

	public function listfiles($match = 'backup_') {
		$opts = $this->get_opts();
		$container = $opts['path'];
		$path = $container;

		if (empty($opts['user']) || empty($opts['apikey'])) return new WP_Error('no_settings', __('No settings were found','updraftplus'));

		try {
			$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'), $opts['region']);
		} catch (Exception $e) {
			return new WP_Error('no_access', __('Cloud Files error - failed to access the container', 'updraftplus').' ('.$e->getMessage().')');
		}

		# Get the container
		try {
			$container_object = $service->getContainer($container);
		} catch (Exception $e) {
			return new WP_Error('no_access', __('Cloud Files error - failed to access the container', 'updraftplus').' ('.$e->getMessage().')');
		}

		$results = array();
		try {
			$objects = $container_object->objectList(array('prefix' => $match));
			$index = 0;
			while (false !== ($file = $objects->offsetGet($index)) && !empty($file)) {
				try {
					if ((!is_object($file) || empty($file->name)) && (!isset($file->bytes) || $file->bytes >0)) continue;
					$result = array('name' => $file->name);
					if (isset($file->bytes)) $result['size'] = $file->bytes;
					$results[] = $result;
					#$container_object->dataObject()->setName($name)->delete();
				} catch (Exception $e) {
				}
				$index++;
			}
		} catch (Exception $e) {
		}

		return $results;
	}

	public function chunked_upload_finish($file) {

		$chunk_path = 'chunk-do-not-delete-'.$file;
		try {

			$headers = array(
				'Content-Length'    => 0,
				'X-Object-Manifest' => sprintf('%s/%s', 
					$this->container,
					$chunk_path.'_'
				)
			);
			
			$url = $this->container_object->getUrl($file);
			$this->container_object->getClient()->put($url, $headers)->send();
			return true;

		} catch (Exception $e) {
			return false;
		}
	}

	public function chunked_upload($file, $fp, $i, $upload_size, $upload_start, $upload_end) {

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
				$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'), $opts['region']);
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
			$service = $this->get_service($opts['user'], $opts['apikey'], $opts['authurl'], UpdraftPlus_Options::get_updraft_option('updraft_ssl_useservercerts'), UpdraftPlus_Options::get_updraft_option('updraft_ssl_disableverify'), $opts['region']);
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
			$this->container_object = $service->getContainer($container);
		} catch (Exception $e) {
			$updraftplus->log('Could not access Cloud Files container ('.get_class($e).', '.$e->getMessage().')');
			$updraftplus->log(__('Could not access Cloud Files container', 'updraftplus').' ('.get_class($e).', '.$e->getMessage().')', 'error');
			return false;
		}

		# Get information about the object within the container
		$remote_size = $this->get_remote_size($file);
		if (false === $remote_size) {
			$updraftplus->log('Could not access Cloud Files object');
			$updraftplus->log(__('The Cloud Files object was not found', 'error'));
			return false;
		}

		return (!is_bool($remote_size)) ? $updraftplus->chunked_download($file, $this, $remote_size, true, $this->container_object) : false;

	}

	public function chunked_download($file, $headers, $container_object) {
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

	public function credentials_test() {

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
		$region = (empty($_POST['region'])) ? null : $_POST['region'];

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
			$method = new UpdraftPlus_BackupModule_cloudfiles_opencloudsdk;
			$service = $method->get_service($user, $key, $authurl, $useservercerts, $disableverify, $region);
		} catch(Guzzle\Http\Exception\ClientErrorResponseException $e) {
			$response = $e->getResponse();
			$code = $response->getStatusCode();
			$reason = $response->getReasonPhrase();
			if (401 == $code && 'Unauthorized' == $reason) {
				echo __('Authorisation failed (check your credentials)', 'updraftplus');
			} else {
				echo __('Authorisation failed (check your credentials)', 'updraftplus')." ($code:$reason)";
			}
			die;
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
			$response = $e->getResponse();
			$code = $response->getStatusCode();
			$reason = $response->getReasonPhrase();
			if (404 == $code) {
				$container_object = $service->createContainer($container);
			} else {
				echo __('Authorisation failed (check your credentials)', 'updraftplus')." ($code:$reason)";
				die;
			}
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

		echo __('Success', 'updraftplus').": ".__('We accessed the container, and were able to create files within it.', 'updraftplus');

		try {
			if (!empty($object)) @$object->delete();
		} catch (Exception $e) {
		}

	}

	public function config_print() {

		$opts = $this->get_opts();

		?>
		<tr class="updraftplusmethod cloudfiles">
			<td></td>
			<td><img alt="Rackspace Cloud Files" src="<?php echo UPDRAFTPLUS_URL.'/images/rackspacecloud-logo.png' ?>">
				<p><em><?php printf(__('%s is a great choice, because UpdraftPlus supports chunked uploads - no matter how big your site is, UpdraftPlus can upload it a little at a time, and not get thwarted by timeouts.','updraftplus'),'Rackspace Cloud Files');?></em></p></td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
			<th></th>
			<td>
			<?php
			// Check requirements.
			global $updraftplus_admin;
			if (!function_exists('mb_substr')) {
				$updraftplus_admin->show_double_warning('<strong>'.__('Warning','updraftplus').':</strong> '.sprintf(__('Your web server\'s PHP installation does not included a required module (%s). Please contact your web hosting provider\'s support.', 'updraftplus'), 'mbstring').' '.sprintf(__("UpdraftPlus's %s module <strong>requires</strong> %s. Please do not file any support requests; there is no alternative.",'updraftplus'),'Cloud Files', 'mbstring'), 'cloudfiles');
			}
			$updraftplus_admin->curl_check('Rackspace Cloud Files', false, 'cloudfiles');
			?>
			</td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
		<th></th>
			<td>
				<p><?php _e('Get your API key <a href="https://mycloud.rackspace.com/">from your Rackspace Cloud console</a> (read instructions <a href="http://www.rackspace.com/knowledge_center/article/rackspace-cloud-essentials-1-generating-your-api-key">here</a>), then pick a container name to use for storage. This container will be created for you if it does not already exist.','updraftplus');?> <a href="http://updraftplus.com/faqs/there-appear-to-be-lots-of-extra-files-in-my-rackspace-cloud-files-container/"><?php _e('Also, you should read this important FAQ.', 'updraftplus'); ?></a></p>
			</td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th title="<?php _e('Accounts created at rackspacecloud.com are US accounts; accounts created at rackspace.co.uk are UK accounts.', 'updraftplus');?>"><?php _e('US or UK-based Rackspace Account','updraftplus');?>:</th>
			<td>
				<select id="updraft_cloudfiles_authurl" name="updraft_cloudfiles[authurl]" title="<?php _e('Accounts created at rackspacecloud.com are US-accounts; accounts created at rackspace.co.uk are UK-based', 'updraftplus');?>">
					<option <?php if ($opts['authurl'] != 'https://lon.auth.api.rackspacecloud.com') echo 'selected="selected"'; ?> value="https://auth.api.rackspacecloud.com"><?php _e('US (default)','updraftplus'); ?></option>
					<option <?php if ($opts['authurl'] =='https://lon.auth.api.rackspacecloud.com') echo 'selected="selected"'; ?> value="https://lon.auth.api.rackspacecloud.com"><?php _e('UK', 'updraftplus'); ?></option>
				</select>
			</td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('Cloud Files Storage Region','updraftplus');?>:</th>
			<td>
				<select id="updraft_cloudfiles_region" name="updraft_cloudfiles[region]">
					<?php
						$regions = array(
							'DFW' => __('Dallas (DFW) (default)', 'updraftplus'),
							'SYD' => __('Sydney (SYD)', 'updraftplus'),
							'ORD' => __('Chicago (ORD)', 'updraftplus'),
							'IAD' => __('Northern Virginia (IAD)', 'updraftplus'),
							'HKG' => __('Hong Kong (HKG)', 'updraftplus'),
							'LON' => __('London (LON)', 'updraftplus')
						);
						$selregion = (empty($opts['region'])) ? 'DFW' : $opts['region'];
						foreach ($regions as $reg => $desc) {
							?>
							<option <?php if ($selregion == $reg) echo 'selected="selected"'; ?> value="<?php echo $reg;?>"><?php echo htmlspecialchars($desc); ?></option>
							<?php
						}
					?>
				</select>
			</td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('Cloud Files Username','updraftplus');?>:</th>
			<td><input type="text" autocomplete="off" style="width: 282px" id="updraft_cloudfiles_user" name="updraft_cloudfiles[user]" value="<?php echo htmlspecialchars($opts['user']) ?>" />
			<div style="clear:both;">
			<?php echo apply_filters('updraft_cloudfiles_apikeysetting', '<a href="http://updraftplus.com/shop/cloudfiles-enhanced/"><em>'.__('To create a new Rackspace API sub-user and API key that has access only to this Rackspace container, use this add-on.', 'updraftplus')).'</em></a>'; ?>
			</div>
			</td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th><?php _e('Cloud Files API Key','updraftplus');?>:</th>
			<td><input type="<?php echo apply_filters('updraftplus_admin_secret_field_type', 'text'); ?>" autocomplete="off" style="width: 282px" id="updraft_cloudfiles_apikey" name="updraft_cloudfiles[apikey]" value="<?php echo htmlspecialchars($opts['apikey']); ?>" />
			</td>
		</tr>
		<tr class="updraftplusmethod cloudfiles">
			<th><?php echo apply_filters('updraftplus_cloudfiles_location_description',__('Cloud Files Container','updraftplus'));?>:</th>
			<td><input type="text" style="width: 282px" name="updraft_cloudfiles[path]" id="updraft_cloudfiles_path" value="<?php echo htmlspecialchars($opts['path']); ?>" /></td>
		</tr>

		<tr class="updraftplusmethod cloudfiles">
		<th></th>
		<td><p><button id="updraft-cloudfiles-test" type="button" class="button-primary" style="font-size:18px !important"><?php echo sprintf(__('Test %s Settings','updraftplus'),'Cloud Files');?></button></p></td>
		</tr>
	<?php
	}

}
