<?php

class EsetConfig {
	const FORCE_CHECK_TEMPFILE = '/esetupdate.force-check.tmp';
	const DOWNLOAD_TEMPFILE = '/esetupdate.download.tmp';
	const CREDENTIALS_FILE = '/credentials.txt';

	public static $initialized = false;

	// recheck completion of every datafile each execution :  false - faster - mimics NOD32; true - slower, safer for unstable networks
	static public $force_check = false;

	// clean-up old unused *.nup files - saves disk space:  false | true
	static public $clean_unused = true;

	// show individual downloaded files in log:  false | true
	static public $debug = false;

	static private $all_languages = 'bgr,csy,dan,deu,enu,esn,fin,fra,hrv,hun,chs,cht,ita,nld,nor,plk,ptb,rom,rus,sky,slv,sve,trk,ukr';
	// what languages to download, comma separated from the list above
	static public $downloaded_languages = 'enu';

	// unrar method - '' - use internal php mod_rar v2 or higher; use any non-empty string as an external command - 'unrar-free'
	// - external command should contain placeholders {sourceFile} and {destDir} `unrar-free -x {sourceFile} {destDir}` => `unrar-free -x /tmp/file.rar /tmp/`
	static public $unrar_method = 'unrar-free -x {sourceFile} {destDir}';

	// list of update servers to use
	static public $eset_server_list = ['update.eset.com'];
	static public $eset_server;

	// local temp folder, must exist and be writeable eg. /tmp
	static public $temp_path = '/tmp';

	// local shared folder eg. /var/www/eset
	// $base_path is our VirtualHost's DocumentRoot
	static public $base_path = '';

	// download eset updates for versions 3/4/5 and v9
	static public $base_dirs = ['', '/v9'];

	static public $user = '';
	static public $pass = '';

	// email to send authentication error info to
	static public $email = '';

	static private $excluded_languages_joined = '';

	static public function randomizeServer() {
		static::$eset_server = static::$eset_server_list[mt_rand(0, count(static::$eset_server_list) - 1)];
	}

	static public function getExcludedLanguagesRegexpPart() {
		return static::$excluded_languages_joined;
	}

	static private function loadCredentials() {
		if (!is_readable(__DIR__ . static::CREDENTIALS_FILE))
			return;
		$t = explode("\n", file_get_contents(__DIR__ . static::CREDENTIALS_FILE));
		static::$user = trim($t[0]);
		static::$pass = trim($t[1]);
	}

	static public function initialize() {
		// determine languages to skip
		$all_languages = explode(',', static::$all_languages);
		$downloaded_languages = explode(',', static::$downloaded_languages);
		static::$excluded_languages_joined = implode('|', array_diff($all_languages, $downloaded_languages));

		// load credentials
		static::loadCredentials();
		if (empty(static::$user) || empty(static::$pass))
			die('Credentials file ' . static::CREDENTIALS_FILE . ' not found or empty. First line should be username, second the password.');

		// parse CLI parameters
		global $argv;
		if (isset($argv[1])) {
			foreach ($argv as $par) {
				$cleanPar = ltrim($par, '-/');
				if ($cleanPar == 'h' || $cleanPar == 'help') {
					die('Available parameters:' . "\n"
						.'   noemail		does not send an e-mail if there is a problem with authorization' . "\n"
						.'   check			forces full re-check' . "\n"
						.'   debug			shows verbose messages' . "\n"
					);
				} elseif ($cleanPar == 'noemail') {
					static::$email = null;
				} else if ($cleanPar == 'check') {
					static::$force_check = true;
				} else if ($cleanPar == 'debug') {
					static::$debug = true;
				}
			}
		}

		static::$initialized = true;
	}
}

class EsetUtils {

	public static function initializeCurl() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 900);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'ESS Update (Windows; U; 64bit; BPC 9.0.374.1; OS: 10.0.10586 SP 0.0 NT; TDB 28246; CL 1.1.1; x64c; APP ess; BEO 1; ASP 0.10; RA 0; HWF: 7822CF11-CDFE-4077-92FF-5E6CF5F99ECD; PLOC cs_cz; PCODE 110.0.0; PAR 0; ATH 0; DC 0; PLID 33D-W8W-NCD; SEAT 660a0d66)');
		curl_setopt($ch, CURLOPT_USERPWD, EsetConfig::$user . ':' . EsetConfig::$pass);
		return $ch;
	}

	private static function getContentAndFreeCurl($ch) {
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}

	public static function getEsetProtectedFile($request) {
		if (EsetConfig::$debug)
			echo ' - trying to download http://' . EsetConfig::$eset_server . $request . "\n";

		$ch = static::initializeCurl();
		curl_setopt($ch, CURLOPT_URL, 'http://' . EsetConfig::$eset_server . $request);
		return static::getContentAndFreeCurl($ch);
	}

	public static function findExistingFilesRecursively($path, $exclude = ".|..", $include = '*.nup') {
		$path = rtrim($path, "/") . "/";
		$folder_handle = opendir($path);
		$exclude_array = explode("|", $exclude);
		$result = array();
		while (false !== ($filename = readdir($folder_handle))) {
			if (!in_array(strtolower($filename), $exclude_array)) {
				if (is_dir($path . $filename . "/")) {
					$result = array_merge($result, static::findExistingFilesRecursively($path . $filename, $exclude, $include));
				} else {
					if (preg_match('`^' . strtr(preg_quote($include, '`'), array('\*' => '.*?', '\?' => '.')) . '$`i', $filename)) {
						$result[] = $path . $filename;
					}
				}
			}
		}
		return $result;
	}

	public static function RemoveEmptySubFolders($path) {
		$empty = true;
		foreach (glob($path . DIRECTORY_SEPARATOR . "*") as $file)
			$empty &= is_dir($file) && static::RemoveEmptySubFolders($file);
		return $empty && rmdir($path);
	}

	public static function formatBytes($a) {
		$unim = array("B", "KB", "MB", "GB", "TB", "PB");
		$c = 0;
		while ($a >= 1024) {
			$c++;
			$a = $a / 1024;
		}
		return number_format($a, ($c ? 2 : 0), ",", " ") . " " . $unim[$c];
	}

	public static $refreshPasswordCallable;

	public static function checkForUnauthorized($content) {
		if ((strpos($content, 'HTTP/1.1 401 Unauthorized') !== false) || (strpos($content, '401 Authorization Required') !== false)) {
			if (is_callable(EsetUtils::$refreshPasswordCallable)) {
				echo 'Note: Trying to get fresh credentials.' . "\n";
				if (!call_user_func(EsetUtils::$refreshPasswordCallable)) {
					if (EsetConfig::$email) {
						mail(EsetConfig::$email,
							'ESET update server needs password',
							'Unauthorized error occured. I could not get a fresh username and password.');
						EsetConfig::$email = null;
					}
					die('Unauthorized error occured. I could not get a fresh username and password. Bye.' . "\n");
				} else {
					if (EsetConfig::$email) {
						mail(EsetConfig::$email,
							'ESET update server needs password',
							'Unauthorized error occured. I need a fresh username and password.'
							. "\n\nObtained automatically:\n" . EsetConfig::$user . ' / ' . EsetConfig::$pass);
						EsetConfig::$email = null;
					}
					echo 'Note: Fresh credentials: ' . EsetConfig::$user . ' / ' . EsetConfig::$pass . "\n";
				}
			} else {
				die('Unauthorized error occured. I need a fresh username and password. Bye.' . "\n");
			}
			return true;
		}
		return false;
	}
}

class EsetFileHandle {
	private $ch;

	public function __construct($request) {
		$this->ch = EsetUtils::initializeCurl();
		curl_setopt($this->ch, CURLOPT_URL, 'http://' . EsetConfig::$eset_server . $request);
	}

	public function __destruct() {
		if ($this->ch)
			curl_close($this->ch);
	}

	public function getInfo() {
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_NOBODY, true);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		$data = curl_exec($this->ch);
		return $data;
	}

	/**
	 * @param resource $stream
	 */
	public function getContentsToStream($stream) {
		curl_setopt($this->ch, CURLOPT_HEADER, false);
		curl_setopt($this->ch, CURLOPT_NOBODY, false);
		curl_setopt($this->ch, CURLOPT_FILE, $stream);
		curl_exec($this->ch);
	}

}
