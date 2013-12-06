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
		try {
			# The SDK doesn't natively give us a method to get the object meta-data without downloading the entire object - into memory!
			$response = $container_object->getClient()->head($container_object->getUrl($file))->send();
			$response_object = $container_object->dataObject()->populateFromResponse($response)->setName($file);;
			$remote_size = $response_object->getContentLength();
		} catch (Exception $e) {
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

