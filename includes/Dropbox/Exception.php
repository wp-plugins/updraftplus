<?php

/**
 * Dropbox Exception class
 * @author Ben Tadiar <ben@handcraftedbyben.co.uk>
 * @link https://github.com/benthedesigner/dropbox
 * @package Dropbox
 */
if (!class_exists('Dropbox_Exception')):
class Dropbox_Exception extends Exception {
}
endif;

class Dropbox_BadRequestException extends Exception {
}

class Dropbox_CurlException extends Exception {
}

class Dropbox_NotAcceptableException extends Exception {
}

class Dropbox_NotFoundException extends Exception {
}

class Dropbox_NotModifiedException extends Exception {
}

class Dropbox_UnsupportedMediaTypeException extends Exception {
}
