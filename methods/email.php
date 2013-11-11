<?php

// Files can easily get too big for this method

class UpdraftPlus_BackupModule_email {

	public function backup($backup_array) {

		global $updraftplus, $updraftplus_backup;

		$updraft_dir = trailingslashit($updraftplus->backups_dir_location());

		$email = UpdraftPlus_Options::get_updraft_option('updraft_email');
		if (is_array($email)) $email = implode(',', $email);

		foreach ($backup_array as $type => $file) {

			$descrip_type = (preg_match('/^(.*)\d+$/', $type, $matches)) ? $matches[1] : $type;

			$fullpath = $updraft_dir.$file;
			#if (file_exists($fullpath) && filesize($fullpath) > ...
			$any_sent = false;
			foreach (explode(',', $email) as $sendmail_addr) {
				$send_short = (strlen($sendmail_addr)>5) ? substr($sendmail_addr, 0, 5).'...' : $sendmail_addr;
				$updraftplus->log("$file: email to: $send_short");

				$sent = wp_mail(trim($sendmail_addr), __("WordPress Backup",'updraftplus')." ".get_date_from_gmt(gmdate('Y-m-d H:i:s', $updraftplus->backup_time), 'Y-m-d H:i'), sprintf(__("Backup is of: %s.",'updraftplus'), $descrip_type).' '.__('Be wary; email backups may fail because of file size limitations on mail servers.','updraftplus'), null, array($fullpath));
				if ($sent) $any_sent = true;
			}
			if ($any_sent) {
				$updraftplus->uploaded_file($file);
			} else {
				$updraftplus->log('Mails were not sent successfully');
				$updraftplus->log(__('The attempt to send the backup via email failed (probably the backup was too large for this method)', 'updraftplus'), 'error');
			}
		}
		return null;
	}

	public static function config_print() {
		?>
		<tr class="updraftplusmethod email">
			<th><?php _e('Note:','updraftplus');?></th>
			<td><?php echo str_replace('&gt;','>', str_replace('&lt;','<',htmlspecialchars(__('The email address entered above will be used. If choosing "E-Mail", then <strong>be aware</strong> that mail servers tend to have size limits; typically around 10-20Mb; <strong>backups larger than any limits will not arrive.</strong>','updraftplus'))));?></td>
		</tr>
		<?php
	}

	public function delete($files) {
	}

}
