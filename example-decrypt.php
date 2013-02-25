<?php

/*

To dump the decrypted file using the given key on stdout, call:

rijndael_decrypt_file( '../path/to/file.crypt' , 'mykey' );

Thus, here are the easy instructions:

1) Add a line like the above into this PHP file (not inside these comments, but outside)
e.g.
rijndael_decrypt_file( '/home/myself/myfile.crypt' , 'MYKEY' );

2) Run this file (and make sure that includes/Rijndael.php is available, if you are moving this file around)
e.g. 
php /home/myself/example-decrypt.php >output.sql.gz

3) You may then want to gunzip the resulting file to have a standard SQL file.
e.g.
gunzip output.sql.gz

*/

function rijndael_decrypt_file($file, $key) {

	require_once(dirname(__FILE__).'/includes/phpseclib/Crypt/Rijndael.php');

	$rijndael = new Crypt_Rijndael();
	$rijndael->setKey($key);
	$in_handle = fopen($file,'r');
	$ciphertext = "";
	while (!feof ($in_handle)) {
		$ciphertext .= fread($in_handle, 16384);
	}
	fclose ($in_handle);
	print $rijndael->decrypt($ciphertext);

}

?>
