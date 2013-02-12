<?php

// Files can easily get way too big for this method

class UpdraftPlus_BackupModule_email {

	function backup($backup_array) {

		global $updraftplus;

		foreach ($backup_array as $type => $file) {
			$fullpath = trailingslashit(UpdraftPlus_Options::get_updraft_option('updraft_dir')).$file;
			wp_mail(UpdraftPlus_Options::get_updraft_option('updraft_email'), "WordPress Backup ".date('Y-m-d H:i',$updraftplus->backup_time), "Backup is of the $type.  Be wary; email backups may fail because of file size limitations on mail servers.", null, array($fullpath));
			$updraftplus->uploaded_file($file);
		}
		$updraftplus->prune_retained_backups("email", null, null);
	}

	public static function config_print() {
		?>
		<tr class="updraftplusmethod email">
			<th>Note:</th>
			<td>The email address entered above will be used. If choosing &quot;E-Mail&quot;, then be aware that mail servers tend to have size limits; typically around 10-20Mb; backups larger than any limits will not arrive. If you really need a large backup via email, then you could fund a new feature (to break the backup set into configurable-size pieces) - but the demand has not yet existed for such a feature.</td>
		</tr>
		<?php
	}

}

?>