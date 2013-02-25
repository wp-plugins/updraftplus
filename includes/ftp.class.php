<?php
/* from http://www.solutionbot.com/2009/01/02/php-ftp-class/ */
class UpdraftPlus_ftp_wrapper
{
	private $conn_id;
	private $host;
	private $username;
	private $password;
	private $port;
	public  $timeout = 90;
	public  $passive = true;
	public  $system_type = '';
	public $login_type = 'non-encrypted';
 
	public function __construct($host, $username, $password, $port = 21)
	{
		$this->host     = $host;
		$this->username = $username;
		$this->password = $password;
		$this->port     = $port;
	}
 
	public function connect()
	{
		$this->conn_id = ftp_connect($this->host, $this->port);

		if ($this->conn_id === false) return false;
 
		$result = ftp_login($this->conn_id, $this->username, $this->password);
 
		if ($result == true)
		{
			ftp_set_option($this->conn_id, FTP_TIMEOUT_SEC, $this->timeout);
 
			if ($this->passive == true)
			{
				ftp_pasv($this->conn_id, true);
			}
			else
			{
				ftp_pasv($this->conn_id, false);
			}
 
			$this->system_type = ftp_systype($this->conn_id);
 
			return true;
		}
		else
		{
			return false;
		}
	}
 
	public function put($local_file_path, $remote_file_path, $mode = FTP_ASCII)
	{
		if (ftp_put($this->conn_id, $remote_file_path, $local_file_path, $mode))
		{
			return true;
		}
		else
		{
			return false;
		}
	}
 
	public function get($local_file_path, $remote_file_path, $mode = FTP_ASCII)
	{
		if (ftp_get($this->conn_id, $local_file_path, $remote_file_path, $mode))
		{
			return true;
		}
		else
		{
			return false;
		}
	}
 
	public function chmod($permissions, $remote_filename)
	{
		if ($this->is_octal($permissions))
		{
			$result = ftp_chmod($this->conn_id, $permissions, $remote_filename);
			if ($result)
			{
				return true;
			}
			else
			{
				return false;
			}
		}
		else
		{
			throw new Exception('$permissions must be an octal number');
		}
	}
 
	public function chdir($directory)
	{
		ftp_chdir($this->conn_id, $directory);
	}
 
	public function delete($remote_file_path)
	{
		if (ftp_delete($this->conn_id, $remote_file_path))
		{
			return true;
		}
		else
		{
			return false;
		}
	}
 
	public function make_dir($directory)
	{
		if (ftp_mkdir($this->conn_id, $directory))
		{
			return true;
		}
		else 
		{
			return false;
		}
	}
 
	public function rename($old_name, $new_name)
	{
		if (ftp_rename($this->conn_id, $old_name, $new_name))
		{
			return true;
		}
		else
		{
			return false;
		}
	}
 
	public function remove_dir($directory)
	{
		if (ftp_rmdir($this->conn_id, $directory))
		{
			return true;
		}
		else
		{
			return false;
		}
	}
 
	public function dir_list($directory)
	{
		$contents = ftp_nlist($this->conn_id, $directory);
		return $contents;
	}
 
	public function cdup()
	{
		ftp_cdup($this->conn_id);
	}
 
	public function current_dir()
	{
		return ftp_pwd($this->conn_id);
	}
 
	private function is_octal($i) 
	{
    	return decoct(octdec($i)) == $i;
	}
 
	public function __destruct()
	{
		if ($this->conn_id)
		{
			ftp_close($this->conn_id);
		}
	}
}
?>