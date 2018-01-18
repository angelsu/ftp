<?php
namespace angelsu\ftp;

class FTP {

    public $dir = '.';
    protected $server;
    protected $username;
    protected $password;
    protected $ftp_root;
    private $ftp_conn;

    /**
     * Create an instance of FTP
     * @param string $server
     * @param string $username
     * @param string $password
     * @param string $ftp_root
     */
    function __construct($server = null, $username = null, $password = null, $ftp_root = '') {
        $this->server = $server;
        $this->username = $username;
        $this->password = $password;
        $this->ftp_conn = ftp_connect($server);
        $this->ftp_root = $ftp_root;
        ftp_login($this->ftp_conn, $this->username, $this->password);
        ftp_pasv($this->ftp_conn, true);
    }

    /**
     * Close connection
     */
    public function close() {
        ftp_close($this->ftp_conn);
    }

    /**
     * Save file to FTP (create directory if it doesn't exists)
     * @param string $local_file
     * @param string $remote_file
     * @param int $mode
     * @return boolean
     */
    public function Put($local_file, $remote_file, $mode = FTP_BINARY) {
        $remfileExplode = explode('/', $remote_file);
        $filename = array_pop($remfileExplode);
        $directory = implode('/', $remfileExplode);
        if (!empty($directory) && !$this->IsDir($this->ftp_root . $directory)) {
            $this->mkdir($this->ftp_root . $directory);
        }
        $fp = fopen($local_file, 'r');
        $this->chdir($this->ftp_root . $directory);
        $ret = ftp_nb_fput($this->ftp_conn, $filename, $fp, $mode);
        while ($ret == FTP_MOREDATA) {
            $ret = ftp_nb_continue($this->ftp_conn);
        }
        fclose($fp);
        return ($ret == FTP_FINISHED) ? true : false;
    }

    /**
     * Get file from FTP
     * @param string $remote_file
     * @param string $local_file
     * @param int $mode
     * @return boolean|string (false if file not exist filename if it exists)
     */
    public function Get($remote_file, $local_file = NULL, $mode = FTP_BINARY) {
        if (!$this->IsFileByCommand($this->ftp_root . $remote_file)) {
            return false;
        }
        $remfileExplode = explode('/', $remote_file);
        $filename = array_pop($remfileExplode);
        $_local_file = $local_file = sys_get_temp_dir() . "/$filename";
        $fp = fopen($_local_file, 'w');
        $ret = ftp_nb_fget($this->ftp_conn, $fp, $this->ftp_root . $remote_file, $mode);
        while ($ret == FTP_MOREDATA) {
            $ret = ftp_nb_continue($this->ftp_conn);
        }
        fclose($fp);
        return $_local_file;
    }

    /**
     * Descruct the ftp instance
     */
    public function __destruct() {
        if ($this->ftp_conn) {
            ftp_close($this->ftp_conn);
        }
    }

    /**
     * Create the directory/subdirectories
     * @param string $dir
     * @return boolean
     */
    public function mkdir($dir) {
        $_dir = (strpos($dir, '/', 1)) ? array_filter(explode('/', $dir)) : $dir;
        if ($this->IsDir($dir)) {
            return true;
        }
        $this->chdir('/');
        if (is_array($_dir)) {
            $root = '';
            foreach ($_dir as $folder) {
                if (!$this->IsDir($root . $folder)) {
                    ftp_mkdir($this->ftp_conn, $root . $folder);
                }
                $root .= $folder . '/';
            }
        } else {
            ftp_mkdir($this->ftp_conn, $dir);
        }
    }

    /**
     * Check id $dir is a valid directory on server
     * @param string $dir
     * @return boolean
     */
    public function IsDir($dir) {
        return is_dir("ftp://{$this->username}:{$this->password}@{$this->server}/$dir");
    }

    /**
     * Check if is a valid file
     * @param string $file
     * @return boolean
     */
    public function IsFile($file) {
        $list = $this->GetList();
        $fileInServer = array_filter($list, function($item) use($file) {
            return isset($item['name']) && $item['name'] == $file;
        });
        return !empty($fileInServer);
    }

    public function IsFileByCommand($file) {
        return is_file("ftp://{$this->username}:{$this->password}@{$this->server}/$file");
    }

    /**
     * Change current working directory
     * @param string $dir
     * @return boolean
     */
    public function chdir($dir) {
        if ($this->IsDir($dir)) {
            $this->dir = $dir;
            ftp_chdir($this->ftp_conn, $dir);
            return true;
        }
        return false;
    }

    /**
     * Get detailed list of current directory
     * @return array
     */
    public function GetDetailedList() {
        $_list = array();
        $this->dir = (strlen($this->dir) && $this->dir[0] != '/') ? '/' . $this->dir : $this->dir;
        $typedir = ftp_systype($this->ftp_conn) == "UNIX" ? '.' : $this->dir;
        $list = ftp_rawlist($this->ftp_conn, $typedir);
        foreach ($list as $idx => $item) {
            $match = array();
            if (preg_match('/^([\d-]+)\s+([\d:]+[A|P]M)\s+<DIR>\s+(\d+)\s?$/m', $item, $match)) {
                $_list[$idx] = array('name' => rtrim($this->dir, '/') . '/' . $match[3], 'size' => 0, 'date' => $match[1], 'time' => $match[2], 'type' => 'directory');
            } else if (preg_match('/^([\d-]+)\s+([\d:]+[A|P]M)\s+(\d+)\s+(.+\.\w+)\s?$/m', $item, $match)) {
                $_list[$idx] = array('name' => rtrim($this->dir, '/') . '/' . $match[4], 'size' => $this->FormatBytesSize($match[3]), 'date' => $match[1], 'time' => $match[2], 'type' => 'file');
            } else if (preg_match('/^([drxw-]+)\s+(\d)\s+([\w]+\s+[\w]+)\s+(\d)\s+([\w]+\s+[\d]+)\s+([\d]+:[\d]+)\s+([\w]+)$/m', $item, $match)) {
                $_list[$idx] = array('name' => rtrim($this->dir, '/') . '/' . $match[7], 'size' => $match[4] != '0' ? $this->FormatBytesSize($match[4]) : $match[4], 'date' => $match[5], 'time' => $match[6], 'type' => 'file');
            } else if (preg_match('/^([drxw-]+)\s+(\d)\s+([\w]+\s+[\w]+)\s+(\d+)\s+([\w]+\s+[\d]+)\s+([\d:?\d?]+)\s+(.+\.\w+)\s?$/m', $item, $match)) {
                $_list[$idx] = array('name' => rtrim($this->dir, '/') . '/' . $match[7], 'size' => $match[4] != '0' ? $this->FormatBytesSize($match[4]) : $match[4], 'date' => $match[5], 'time' => $match[6], 'type' => 'file');
            }
        }
        return $_list;
    }

    /**
     * Get a list of currrent directory's files
     * @return Array
     */
    public function GetList() {
        $_list = array();
        $typedir = ftp_systype($this->ftp_conn) == "UNIX" ? '.' : $this->dir;
        $list = ftp_nlist($this->ftp_conn, $typedir);
        foreach ($list as $idx => $item) {
            $_list[$idx] = array('name' => $item, 'size' => ftp_size($this->ftp_conn, $item));
        }
        return $_list;
    }

    /**
     * Shows an readable size number (ex: 1024 => 1kb)
     * @param int $size
     * @param int $precision
     * @return int
     */
    public function FormatBytesSize($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = array('', 'kb', 'Mb', 'Gb', 'Tb');
        return round(pow(1024, $base - floor($base)), $precision) . $suffixes[floor($base)];
    }

    /**
     * Delete a file from ftp
     * @param string $file
     * @return boolean
     */
    public function DeleteFile($file) {
        if ($file == '/') {
            return false;
        }
        $fixFile = (strlen($file) && $file[0] == '/') ? $file : '/' . $file;
        return ftp_delete($this->ftp_conn, $this->ftp_root . $fixFile);
    }

    /**
     * Delete a directory from ftp
     * @param string $dir
     * @return boolean
     */
    public function DeleteDir($dir) {
        if ($dir == '/') {
            return false;
        }
        $fixDir = (strlen($dir) && $dir[0] == '/') ? $dir : '/' . $dir;
        return ftp_rmdir($this->ftp_conn, $this->ftp_root . $fixDir);
    }

    /**
     * Return Current Working directory
     * @return string
     */
    public function GetPWD() {
        return ftp_pwd($this->ftp_conn);
    }

}
