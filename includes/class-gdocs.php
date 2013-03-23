<?php

// Originally contained: GDocs class
// Contains: UpdraftPlus_GDocs class (new methods added - could not extend, as too much was private)

// The following copyright notice is reproduced exactly as found in the "Backup" plugin (http://wordpress.org/extend/plugins/backup)
// It applies to the code apart from the methods we added (get_content_link, download_data)

/*
	Copyright 2012 Sorin Iclanzan  (email : sorin@hel.io)

	This file is part of Backup.

	Backup is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Backup is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with Backup. If not, see http://www.gnu.org/licenses/gpl.html.
*/

/**
 * Google Docs class
 *
 * Implements communication with Google Docs via the Google Documents List v3 API.
 *
 * Currently uploading, resuming and deleting resources is implemented as well as retrieving quotas.
 *
 * @uses  WP_Error for storing error messages.
 */
class UpdraftPlus_GDocs {

	/**
	 * Stores the API version.
	 *
	 * @var string
	 * @access private
	 */
	private $gdata_version;


	/**
	 * Stores the base URL for the API requests.
	 *
	 * @var string
	 * @access private
	 */
	private $base_url;

	/**
	 * Stores the URL to the metadata feed.
	 * @var string
	 */
	private $metadata_url;

	/**
	 * Stores the token needed to access the API.
	 * @var string
	 * @access private
	 */
	private $token;

	/**
	 * Stores feeds to avoid requesting them again for successive use.
	 *
	 * @var array
	 * @access private
	 */
	private $cache = array();

	/**
	 * Files are uploadded in chunks of this size in bytes.
	 *
	 * @var integer
	 * @access private
	 */
	private $chunk_size;

	/**
	 * Stores whether or not to verify host SSL certificate.
	 *
	 * @var boolean
	 * @access private
	 */
	private $ssl_verify;

	/**
	 * Stores the number of seconds to wait for a response before timing out.
	 *
	 * @var integer
	 * @access private
	 */
	private $request_timeout;

	/**
	 * Stores the MIME type of the file that is uploading
	 *
	 * @var string
	 * @access private
	 */
	private $upload_file_type;

	/**
	 * Stores info about the file being uploaded.
	 *
	 * @var array
	 * @access private
	 */
	private $file;

	/**
	 * Stores the number of seconds the upload process is allowed to run
	 *
	 * @var integer
	 * @access private
	 */
	private $time_limit;

	/**
	 * Stores a timer for upload processes
	 *
	 * @var array
	 */
	private $timer;

	/**
	 * Constructor - Sets the access token.
	 *
	 * @param  string $token Access token
	 */
	function __construct( $token ) {
		$this->token = $token;
		$this->gdata_version = '3.0';
		$this->base_url = 'https://docs.google.com/feeds/default/private/full/';
		$this->metadata_url = 'https://docs.google.com/feeds/metadata/default';
		$this->chunk_size = 524288; // 512 KiB
		$this->max_resume_attempts = 5;
		$this->request_timeout = 5;
		$this->ssl_verify = true;
		$this->timer = array(
			'start' => 0,
			'stop'  => 0,
			'delta' => 0,
			'cycle' => 0
		);
		$this->time_limit = @ini_get( 'max_execution_time' );
		if ( ! $this->time_limit && '0' !== $this->time_limit )
			$this->time_limit = 30; // default php max exec time
	}

	/**
	 * Sets an option.
	 *
	 * @access public
	 * @param string $option The option to set.
	 * @param mixed  $value  The value to set the option to.
	 */
	public function set_option( $option, $value ) {
		switch ( $option ) {
			case 'chunk_size':
				if ( floatval($value) >= 0.5 ) {
					$this->chunk_size = floatval($value) * 1024 * 1024; // Transform from MiB to bytes
					return true;
				}
				break;
			case 'ssl_verify':
				$this->ssl_verify = ( bool ) $value;
				return true;
			case 'request_timeout':
				if ( intval( $value ) > 0 )	{
					$this->request_timeout = intval( $value );
					return true;
				}
				break;
			case 'max_resume_attempts':
				$this->max_resume_attempts = intval($value);
				return true;
		}
		return false;
	}

	/**
	 * Gets an option.
	 *
	 * @access public
	 * @param string $option The option to get.
	 */
	public function get_option( $option ) {
		switch ( $option ) {
			case 'chunk_size':
				return $this->chunk_size;
			case 'ssl_verify':
				return $this->ssl_verify;
			case 'request_timeout':
				return $this->request_timeout;
			case 'max_resume_attempts':
				return $this->max_resume_attempts;
		}
		return false;
	}

	/**
	 * This function makes all the requests to the API.
	 *
	 * @uses   wp_remote_request
	 * @access private
	 * @param  string $url     The URL where the request is sent.
	 * @param  string $method  The HTTP request method, defaults to 'GET'.
	 * @param  array  $headers Headers to be sent.
	 * @param  string $body    The body of the request.
	 * @return mixed           Returns an array containing the response on success or an instance of WP_Error on failure.
	 */
	private function request( $url, $method = 'GET', $headers = array(), $body = NULL ) {
		$args = array(
			'method'      => $method,
			'timeout'     => $this->request_timeout,
			'httpversion' => '1.1',
			'redirection' => 0,
			'sslverify'   => $this->ssl_verify,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->token,
				'GData-Version' => $this->gdata_version
			)
		);
		if ( ! empty( $headers ) )
			$args['headers'] = array_merge( $args['headers'], $headers );
		if ( ! empty( $body ) )
			$args['body'] = $body;

		return wp_remote_request( $url, $args );
	}

	/**
	 * Returns the feed from a URL.
	 *
	 * @access public
	 * @param  string $url The feed URL.
	 * @return mixed       Returns the feed as an instance of SimpleXMLElement on success or an instance of WP_Error on failure.
	 */
	public function get_feed( $url ) {
		if ( ! isset( $this->cache[$url] ) ) {
			$result = $this->cache_feed( $url );
			if ( is_wp_error( $result ) )
				return $result;
		}

		return $this->cache[$url];
	}

	/**
	 * Requests a feed and adds it to cache.
	 *
	 * @access private
	 * @param  string $url The feed URL.
	 * @return mixed       Returns TRUE on success or an instance of WP_Error on failure.
	 */
	private function cache_feed( $url ) {
		$result = $this->request( $url );

		if ( is_wp_error( $result ) )
			return $result;

		if ( $result['response']['code'] == '200' ) {
			$feed = @simplexml_load_string( $result['body'] );
			if ( $feed === false )
				return new WP_Error( 'invalid_data', "Could not create SimpleXMLElement from '" . $result['body'] . "'." );

			$this->cache[$url] = $feed;
			return true;
		}
		return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to get '" . $url . "'. Response body: " . $result['body'] );

	}

	/**
	 * Deletes a resource from Google Docs.
	 *
	 * @access public
	 * @param  string $id Gdata Id of the resource to be deleted.
	 * @return mixed      Returns TRUE on success, an instance of WP_Error on failure.
	 */
	public function delete_resource( $id ) {
		$headers = array( 'If-Match' => '*' );

		$result = $this->request( $this->base_url . $id . '?delete=true', 'DELETE', $headers );
		if ( is_wp_error( $result ) )
			return $result;

		if ( $result['response']['code'] == '200' )
			return true;
		return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to delete resource '" . $id . "'. The resource might not have been deleted." );
	}

	/**
	 * Get the resumable-create-media link needed to upload files.
	 *
	 * @access private
	 * @param  string $parent The Id of the folder where the upload is to be made. Default is empty string.
	 * @return mixed          Returns a link on success, instance of WP_Error on failure.
	 */
	private function get_resumable_create_media_link( $parent = '' ) {
		$url = $this->base_url;
		if ( $parent )
			$url .= $parent;

		$feed = $this->get_feed( $url );

		if ( is_wp_error( $feed ) )
			return $feed;

		foreach ( $feed->link as $link )
			if ( $link['rel'] == 'http://schemas.google.com/g/2005#resumable-create-media' )
				return ( string ) $link['href'];
		return new WP_Error( 'not_found', "The 'resumable_create_media_link' was not found in feed." );
	}

	/**
	 * Get used quota in bytes.
	 *
	 * @access public
	 * @return mixed  Returns the number of bytes used in Google Docs on success or an instance of WP_Error on failure.
	 */
	public function get_quota_used() {
		$feed = $this->get_feed( $this->metadata_url );
		if ( is_wp_error( $feed ) )
			return $feed;
		return ( string ) $feed->children( "http://schemas.google.com/g/2005" )->quotaBytesUsed;
	}

	/**
	 * Get total quota in bytes.
	 *
	 * @access public
	 * @return string|WP_Error Returns the total quota in bytes in Google Docs on success or an instance of WP_Error on failure.
	 */
	public function get_quota_total() {
		$feed = $this->get_feed( $this->metadata_url );
		if ( is_wp_error( $feed ) )
			return $feed;
		return ( string ) $feed->children( "http://schemas.google.com/g/2005" )->quotaBytesTotal;
	}

	/**
	 * Function to prepare a file to be uploaded to Google Docs.
	 *
	 * The function requests a URI for uploading and prepends a new element in the resume_list array.
	 *
	 * @uses   wp_check_filetype
	 * @access public
	 *
	 * @param  string  $file   Path to the file that is to be uploaded.
	 * @param  string  $title  Title to be given to the file.
	 * @param  string  $parent ID of the folder in which to upload the file.
	 * @param  string  $type   MIME type of the file to be uploaded. The function tries to identify the type if it is omitted.
	 * @return mixed           Returns the URI where to upload on success, an instance of WP_Error on failure.
	 */
	public function prepare_upload( $file, $title, $parent = '', $type = '' ) {
		if ( ! @is_readable( $file ) )
			return new WP_Error( 'not_file', "The path '" . $file . "' does not point to a readable file." );

		// If a mime type wasn't passed try to guess it from the extension based on the WordPress allowed mime types
		if ( empty( $type ) ) {
			$check = wp_check_filetype( $file );
			$this->upload_file_type = $type = $check['type'];
		}

		$size = filesize( $file );

		$body = '<?xml version=\'1.0\' encoding=\'UTF-8\'?><entry xmlns="http://www.w3.org/2005/Atom" xmlns:docs="http://schemas.google.com/docs/2007"><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/docs/2007#file"/><title>' . $title . '</title></entry>';

		$headers = array(
			'Content-Type' => 'application/atom+xml',
			'X-Upload-Content-Type' => $type,
			'X-Upload-Content-Length' => (string) $size
		);

		$url = $this->get_resumable_create_media_link( $parent );

		if ( is_wp_error( $url ) )
			return $url;

		$url .= '?convert=false'; // needed to upload a file

		$result = $this->request( $url, 'POST', $headers, $body );

		if ( is_wp_error( $result ) )
			return $result;

		if ( $result['response']['code'] != '200' )
			return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to get '" . $url . "'." );

		$this->file = array(
			'path'      => $file,
			'size'      => $size,
			'location'  => $result['headers']['location'],
			'pointer'   => 0
		);

		// Open file for reading.
		if ( !$this->file['handle'] = fopen( $file, "rb" ) )
			return new WP_Error( 'open_error', "Could not open file '" . $file . "' for reading." );

		// Start timer
		$this->timer['start'] = microtime( true );

		return $result['headers']['location'];
	}


	/**
	 * Resume an upload.
	 *
	 * @access public
	 * @param  string $file     Path to the file which needs to be uploaded
	 * @param  string $location URI where to upload the file
	 * @return mixed            Returns the next location URI on success, an instance of WP_Error on failure.
	 */
	public function resume_upload( $file, $location ) {

		if ( ! @is_readable( $file ) )
			return new WP_Error( 'not_file', "The path '" . $this->resume_list[$id]['path'] . "' does not point to a readable file. Upload has been canceled." );

		$size = filesize( $file );

		$headers = array( 'Content-Range' => 'bytes */' . $size );
		$result = $this->request( $location, 'PUT', $headers );
		if( is_wp_error( $result ) )
			return $result;

		if ( '308' != $result['response']['code'] ) {
			if ( '201' == $result['response']['code'] ) {
				$feed = @simplexml_load_string( $result['body'] );
				if ( $feed === false )
					return new WP_Error( 'invalid_data', "Could not create SimpleXMLElement from '" . $result['body'] . "'." );
				$this->file['id'] = substr( ( string ) $feed->children( "http://schemas.google.com/g/2005" )->resourceId, 5 );
				return true;
			}
			return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to resume the upload of file '" . $file . "'." );
		}
		if( isset( $result['headers']['location'] ) )
			$location = $result['headers']['location'];
		$pointer = $this->pointer( $result['headers']['range'] );

		$this->file = array(
			'path'      => $file,
			'size'      => $size,
			'location'  => $location,
			'pointer'   => $pointer
		);

		// Open file for reading.
		if ( !$this->file['handle'] = fopen( $file, "rb" ) )
			return new WP_Error( 'open_error', "Could not open file '" . $file . "' for reading." );

		// Start timer
		$this->timer['start'] = microtime( true );

		return $location;
	}

	/**
	 * Work out where the file pointer should be from the range header.
	 *
	 * @access private
	 * @param  string  $range The range HTTP response header.
	 * @return integer        Returns the number of bytes that have been uploaded.
	 */
	private function pointer( $range ) {
		return intval(substr( $range, strpos( $range, '-' ) + 1 )) + 1;
	}

	/**
	 * Uploads a chunk of the file being uploaded.
	 *
	 * @access public
	 * @return mixed   Returns TRUE if the chunk was uploaded successfully;
	 *                 returns Google Docs resource ID if the file upload finished;
	 *                 returns an instance of WP_Error on failure.
	 */
	public function upload_chunk() {
		if ( !isset( $this->file['handle'] ) )
			return new WP_Error( "no_upload", "There is no file being uploaded." );

		$cycle_start = microtime( true );
		fseek( $this->file['handle'], $this->file['pointer'] );
		$chunk = @fread( $this->file['handle'], $this->chunk_size );
		if ( false === $chunk ) {
			$is_file = (is_file($this->file['path'])) ? 1 : 0;
			$is_readable = (is_readable($this->file['path'])) ? 1 : 0;
			return new WP_Error( 'read_error', "Failed to read from file (path: ".$this->file['path'].", size: ".$this->file['size'].", pointer: ".$this->file['pointer'].", is_file: $is_file, is_readable: $is_readable)");
		}

		$chunk_size = strlen( $chunk );
		$bytes = 'bytes ' . (string)$this->file['pointer'] . '-' . (string)($this->file['pointer'] + $chunk_size - 1) . '/' . (string)$this->file['size'];

		$headers = array( 'Content-Range' => $bytes );

		$result = $this->request( $this->file['location'], 'PUT', $headers, $chunk );

		if ( !is_wp_error( $result ) )
			if ( '308' == $result['response']['code'] ) {
				if ( isset( $result['headers']['range'] ) )
					$this->file['pointer'] = $this->pointer( $result['headers']['range'] );
				else
					$this->file['pointer'] += $chunk_size;

				if ( isset( $result['headers']['location'] ) )
					$this->file['location'] = $result['headers']['location'];

				if ( $this->timer['cycle'] )
					$this->timer['cycle'] = ( microtime( true ) - $cycle_start + $this->timer['cycle'] ) / 2;
				else
					$this->timer['cycle'] = microtime(true) - $cycle_start;

				return $this->file['location'];
			}
			elseif ( '201' == $result['response']['code'] ) {
				fclose( $this->file['handle'] );

				// Stop timer
				$this->timer['stop'] = microtime(true);
				$this->timer['delta'] = $this->timer['stop'] - $this->timer['start'];

				if ( $this->timer['cycle'] )
					$this->timer['cycle'] = ( microtime( true ) - $cycle_start + $this->timer['cycle'] ) / 2;
				else
					$this->timer['cycle'] = microtime(true) - $cycle_start;

				$this->file['pointer'] = $this->file['size'];

				$feed = @simplexml_load_string( $result['body'] );
				if ( $feed === false )
					return new WP_Error( 'invalid_data', "Could not create SimpleXMLElement from '" . $result['body'] . "'." );
				$this->file['id'] = substr( ( string ) $feed->children( "http://schemas.google.com/g/2005" )->resourceId, 5 );
				return true;
			}

		// If we got to this point it means the upload wasn't successful.
		fclose( $this->file['handle'] );
		if ( is_wp_error( $result ) )
			return $result;
		return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to upload a file chunk." );
	}

	/**
	 * Get the resource ID of the most recent uploaded file.
	 *
	 * @access public
	 * @return string The ID of the uploaded file or an empty string.
	 */
	public function get_file_id() {
		if ( isset( $this->file['id'] ) )
			return $this->file['id'];
		return '';
	}

	/**
	 * Get the upload speed recorded on the last upload performed.
	 *
	 * @access public
	 * @return integer  Returns the upload speed in bytes/second or 0.
	 */
	public function get_upload_speed() {
		if ( $this->timer['cycle'] > 0 )
			if ( $this->file['size'] < $this->chunk_size )
				return $this->file['size'] / $this->timer['cycle'];
			else
				return $this->chunk_size / $this->timer['cycle'];
		return 0;
	}

	/**
	 * Get the percentage of the file uploaded.
	 *
	 * @return float Returns a percentage on success, 0 on failure.
	 */
	public function get_upload_percentage() {
		if ( isset( $this->file['path'] ) )
			return $this->file['pointer'] * 100 / $this->file['size'];
		return 0;
	}

	/**
	 * Returns the time taken for an upload to complete.
	 *
	 * @access public
	 * @return float  Returns the number of seconds the last upload took to complete, 0 if there has been no completed upload.
	 */
	public function time_taken() {
		return $this->timer['delta'];
	}

	public function get_content_link( $id, $title ) {

		$feed = $this->get_feed($this->base_url . $id);

		if ( is_wp_error( $feed ) )
			return $feed;

		if ( $feed->title != $title )
			return new WP_Error( 'bad_response', "Unexpected response");

		$att = $feed->content->attributes();
		return $att['src'];

	}

	public function download_data( $link, $saveas, $allow_resume = false ) {

		if ($allow_resume && is_file($saveas)) {
			$headers = array('Range' => 'bytes='.filesize($saveas).'-');
			$put_flag = FILE_APPEND;
		} else {
			$headers = array();
			$put_flag = NULL;
		}

		$result = $this->request($link, 'GET', $headers);

		if ( is_wp_error( $result ) )
			return $result;

		if ( $result['response']['code'] != '200' && (!$allow_resume || 206 !== $result['response']['code']))
			return new WP_Error( 'bad_response', "Received response code '" . $result['response']['code'] . " " . $result['response']['message'] . "' while trying to get '" . $url . "'." );

		global $updraftplus;
		$updraftplus->log("Google Drive downloaded bytes: ".strlen($result['body']));

		file_put_contents($saveas, $result['body'], $put_flag);

	}



}
