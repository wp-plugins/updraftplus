<?php

/*

This is a bare-bones to get you started with developing an access method. The methods provided below are all ones you will want to use (though note that the provided email.php method is an example of truly bare-bones for a method that cannot delete or download and has no configuration).

Read the existing methods for help. There is no hard-and-fast need to put all your code in this file; it is just for increasing convenience and maintainability; there are no bonus points for 100% elegance. If you need access to some part of WordPress that you can only reach through the main plugin file (updraftplus.php), then go right ahead and patch that.

Some handy tips:
- Search-and-replace "template" for the name of your access method
- You can also add the methods config_print_javascript_onready and credentials_test if you like; see s3.php as an example of how these are used (to provide a "Test Settings" button via AJAX in the settings page)
- Name your file accordingly (it is now template.php)
- Add the method to the array $backup_methods in updraftplus.php when ready
- Use the constant UPDRAFTPLUS_DIR to reach Updraft's plugin directory
- Call $updraftplus->log("my log message") to log things, which greatly helps debugging
- UpdraftPlus is licenced under the GPLv3 or later. In order to combine your backup method with UpdraftPlus, you will need to licence to anyone and everyone that you distribute it to in a compatible way.

*/

class UpdraftPlus_BackupModule_template {

	// backup method: takes an array, and shovels them off to the cloud storage
	function backup($backup_array) {

		global $updraftplus;

		foreach ($backup_array as $file) {

		// Do our uploading stuff...

		// If successful, then you must do this:
		// $updraftplus->uploaded_file($file);

		}

	}

	// delete method: takes a file name (base name), and removes it from the cloud storage
	function delete($file) {

		global $updraftplus;

	}

	// download method: takes a file name (base name), and brings it back from the cloud storage into Updraft's directory
	// $updraftplus->logging is not available here, but you can register errors with $updraftplus->error("my error message")
	function download($file) {

		global $updraftplus;

	}

	// config_print: prints out table rows for the configuration screen
	// Your rows need to have a class exactly matching your method (in this example, template), and also a class of updraftplusmethod
	// Note that logging is not available from this context; it will do nothing.
	public static function config_print() {

		?>
			<tr class="updraftplusmethod template">
			<th>My Method:</th>
			<td>
				
			</td>
			</tr>

		<?php


	}

}
