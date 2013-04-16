<?php

// Files can easily get way too big for this method

class UpdraftPlus_BackupModule_email {

	function backup($backup_array) {

		global $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location().'/';

		foreach ($backup_array as $type => $file) {
			$fullpath = $updraft_dir.$file;
			wp_mail(UpdraftPlus_Options::get_updraft_option('updraft_email'), __("WordPress Backup",'updraftplus')." ".date('Y-m-d H:i',$updraftplus->backup_time), __("Backup is of:",'updraftplus')." ".$type.".  ".__('Be wary; email backups may fail because of file size limitations on mail servers.','updraftplus'), null, array($fullpath));
			$updraftplus->uploaded_file($file);
		}
		$updraftplus->prune_retained_backups("email", null, null);
	}

	public static function config_print() {
		?>
		<tr class="updraftplusmethod email">
			<th><?php _e('Note:','updraftplus');?></th>
			<td><?php echo str_replace('&gt;','>', str_replace('&lt;','<',htmlspecialchars(__('The email address entered above will be used. If choosing "E-Mail", then <strong>be aware</strong> that mail servers tend to have size limits; typically around 10-20Mb; backups larger than any limits will not arrive. If you really need a large backup via email, then you could fund a new feature (to break the backup set into configurable-size pieces) - but the demand has not yet existed for such a feature.','updraftplus'))));?></td>
		</tr>
		<?php
	}

}

?>
