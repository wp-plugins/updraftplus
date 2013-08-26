<?php

/**
 * This class provides the functionality to encrypt
 * and decrypt access tokens stored by the application
 * @author Ben Tadiar <ben@handcraftedbyben.co.uk>
 * @link https://github.com/benthedesigner/dropbox
 * @package Dropbox\Oauth
 * @subpackage Storage
 */

class Dropbox_Encrypter
{    
    // Encryption settings - default settings yield encryption to AES (256-bit) standard
    // @todo Provide PHPDOC for each class constant
    const CIPHER = MCRYPT_RIJNDAEL_128;
    const MODE = MCRYPT_MODE_CBC;
    const KEY_SIZE = 32;
    const IV_SIZE = 16;
    
    /**
     * Encryption key
     * @var null|string
     */
    private $key = null;
    
    /**
     * Check Mcrypt is loaded and set the encryption key
     * @param string $key
     * @return void
     */
    public function __construct($key)
    {
        if (!extension_loaded('mcrypt')) {
            throw new Dropbox_Exception('The storage encrypter requires the PHP MCrypt extension to be available. Please check your PHP configuration.');
        } elseif (preg_match('/^[A-Za-z0-9]+$/', $key) && $length = strlen($key) === self::KEY_SIZE) {
            # Short-cut so that the mbstring extension is not required
            $this->key = $key;
        } elseif (($length = mb_strlen($key, '8bit')) !== self::KEY_SIZE) {
            throw new Dropbox_Exception('Expecting a ' .  self::KEY_SIZE . ' byte key, got ' . $length);
        } else {
            // Set the encryption key
            $this->key = $key;
        }
    }
    
    /**
     * Encrypt the OAuth token
     * @param \stdClass $token Serialized token object
     * @return string
     */
    public function encrypt($token)
    {
        // This now sends all Windows users to MCRYPT_RAND - used to send PHP>5.3 to MCRYPT_DEV_URANDOM, but we came across a user this failed for
        // Only MCRYPT_RAND is available on Windows prior to PHP 5.3
        if (version_compare(phpversion(), '5.3.0', '<') && strtoupper(substr(php_uname('s'), 0, 3)) === 'WIN') {
            $crypt_source = MCRYPT_RAND;
        } elseif (@is_readable("/dev/urandom")) {
            // Note that is_readable is not a true test of whether the mcrypt_create_iv call would work, because when open_basedir restrictions exist, is_readable returns false, but mcrypt_create_iv is not subject to that restriction internally, so would actually have succeeded.
            $crypt_source = MCRYPT_DEV_URANDOM;
        } else {
            $crypt_source = MCRYPT_RAND;
        }
        $iv = @mcrypt_create_iv(self::IV_SIZE, $crypt_source);
        
        if ($iv === false && $crypt_source != MCRYPT_RAND) {
            $iv = mcrypt_create_iv(self::IV_SIZE, MCRYPT_RAND);
        }

        $cipherText = @mcrypt_encrypt(self::CIPHER, $this->key, $token, self::MODE, $iv);
        return base64_encode($iv . $cipherText);
    }
    
    /**
     * Decrypt the ciphertext
     * @param string $cipherText
     * @return object \stdClass Unserialized token
     */
    public function decrypt($cipherText)
    {
        $cipherText = base64_decode($cipherText);
        $iv = substr($cipherText, 0, self::IV_SIZE);
        $cipherText = substr($cipherText, self::IV_SIZE);
        $token = @mcrypt_decrypt(self::CIPHER, $this->key, $cipherText, self::MODE, $iv);
        return $token;
    }
}
