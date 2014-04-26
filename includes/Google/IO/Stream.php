<?php
/*
 * Copyright 2013 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Http Streams based implementation of Google_IO.
 *
 * @author Stuart Langley <slangley@google.com>
 */

require_once 'Google/IO/Abstract.php';

class Google_IO_Stream extends Google_IO_Abstract
{
  const TIMEOUT = "timeout";
  const ZLIB = "compress.zlib://";
  private $options = array();

  private static $DEFAULT_HTTP_CONTEXT = array(
    "follow_location" => 0,
    "ignore_errors" => 1,
  );

  private static $DEFAULT_SSL_CONTEXT = array(
    "verify_peer" => true,
  );

  /**
   * Execute an HTTP Request
   *
   * @param Google_HttpRequest $request the http request to be executed
   * @return Google_HttpRequest http request with the response http code,
   * response headers and response body filled in
   * @throws Google_IO_Exception on curl or IO error
   */
  public function executeRequest(Google_Http_Request $request)
  {
    $default_options = stream_context_get_options(stream_context_get_default());

    $requestHttpContext = array_key_exists('http', $default_options) ?
        $default_options['http'] : array();

    if ($request->getPostBody()) {
      $requestHttpContext["content"] = $request->getPostBody();
    }

    $requestHeaders = $request->getRequestHeaders();
    if ($requestHeaders && is_array($requestHeaders)) {
      $headers = "";
      foreach ($requestHeaders as $k => $v) {
        $headers .= "$k: $v\r\n";
      }
      $requestHttpContext["header"] = $headers;
    }

    $requestHttpContext["method"] = $request->getRequestMethod();
    $requestHttpContext["user_agent"] = $request->getUserAgent();

    $requestSslContext = array_key_exists('ssl', $default_options) ?
        $default_options['ssl'] : array();

//     if (!array_key_exists("cafile", $requestSslContext)) {
//       $requestSslContext["cafile"] = dirname(__FILE__) . '/cacerts.pem';
//     }


    $url = $request->getUrl();

    if (preg_match('#^https?://([^/]+)/#', $url, $umatches)) { $cname = $umatches[1]; } else { $cname = false; }

// Added
if (empty($this->options['disable_verify_peer'])) {
	$requestSslContext['verify_peer'] = true;
	if (!empty($cname)) $requestSslContext['CN_match'] = $cname;
} else {
	$requestSslContext['allow_self_signed'] = true;
}
if (!empty($this->options['cafile'])) $requestSslContext['cafile'] = $this->options['cafile'];

    $options = array(
        "http" => array_merge(
            self::$DEFAULT_HTTP_CONTEXT,
            $requestHttpContext
        ),
#            self::$DEFAULT_SSL_CONTEXT,
        "ssl" => array_merge(
            $requestSslContext
        )
    );

    $context = stream_context_create($options);

    if ($request->canGzip()) {
      $url = self::ZLIB . $url;
    }

    // Not entirely happy about this, but supressing the warning from the
    // fopen seems like the best situation here - we can't do anything
    // useful with it, and failure to connect is a legitimate run
    // time situation.
    @$fh = fopen($url, 'r', false, $context);

    $response_data = false;
    $respHttpCode = self::UNKNOWN_CODE;
    if ($fh) {
      if (isset($this->options[self::TIMEOUT])) {
        stream_set_timeout($fh, $this->options[self::TIMEOUT]);
      }

      $response_data = stream_get_contents($fh);
      fclose($fh);

      $respHttpCode = $this->getHttpResponseCode($http_response_header);
    }

    if (false === $response_data) {
      throw new Google_IO_Exception(
          sprintf(
              "HTTP Error: Unable to connect: '%s'",
              $respHttpCode
          ),
          $respHttpCode
      );
    }

    $responseHeaders = $this->getHttpResponseHeaders($http_response_header);

    return array($response_data, $responseHeaders, $respHttpCode);
  }

  /**
   * Set options that update the transport implementation's behavior.
   * @param $options
   */
  public function setOptions($options)
  {
    $this->options = $options + $this->options;
  }

  /**
   * Set the maximum request time in seconds.
   * @param $timeout in seconds
   */
  public function setTimeout($timeout)
  {
    $this->options[self::TIMEOUT] = $timeout;
  }

  /**
   * Get the maximum request time in seconds.
   * @return timeout in seconds
   */
  public function getTimeout()
  {
    return $this->options[self::TIMEOUT];
  }

  /**
   * Determine whether "Connection Established" quirk is needed
   * @return boolean
   */
  protected function needsQuirk()
  {
      // Stream needs the special quirk
      return true;
  }

  protected function getHttpResponseCode($response_headers)
  {
    $header_count = count($response_headers);

    for ($i = 0; $i < $header_count; $i++) {
      $header = $response_headers[$i];
      if (strncasecmp("HTTP", $header, strlen("HTTP")) == 0) {
        $response = explode(' ', $header);
        return $response[1];
      }
    }
    return self::UNKNOWN_CODE;
  }
}
