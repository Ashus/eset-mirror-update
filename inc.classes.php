<?php
/**
 * Created by Ashus, all rights reserved
 * https://ashus.ashus.net/viewtopic.php?f=3&t=153
 * https://github.com/Ashus/eset-update
 */

class EsetConfig {
	const FORCE_CHECK_TEMPFILE = '/esetupdate.force-check.tmp';
	const DOWNLOAD_TEMPFILE = '/esetupdate.download.tmp';
	const CREDENTIALS_FILE = '/credentials.txt';

	private static $all_languages = 'bgr,csy,dan,deu,enu,esn,fin,fra,hrv,hun,chs,cht,ita,nld,nor,plk,ptb,rom,rus,sky,slv,sve,trk,ukr';
	private static $excluded_languages_joined = '';

	public static $initialized = false;
	public static $force_check = false;
	public static $clean_unused = true;
	public static $debug = false;
	public static $downloaded_languages = 'enu';
	public static $unrar_method = 'unrar-free -x {sourceFile} {destDir}';
	public static $eset_server_list = ['update.eset.com'];
	public static $temp_path = '/tmp';
	public static $base_path = '';
	public static $base_dirs = ['']; // '/v9'
	public static $user = '';
	public static $pass = '';
	public static $email = '';
	/* @var string */
	public static $eset_server;
	/* @var callable */
	public static $refreshPasswordCallable;

	public static function randomizeServer() {
		static::$eset_server = static::$eset_server_list[mt_rand(0, count(static::$eset_server_list) - 1)];
	}

	public static function getExcludedLanguagesRegexpPart() {
		return static::$excluded_languages_joined;
	}

	private static function loadCredentials() {
		if (!is_readable(__DIR__ . static::CREDENTIALS_FILE)) {
			return;
		}
		$t = explode("\n", file_get_contents(__DIR__ . static::CREDENTIALS_FILE));
		static::$user = trim($t[0]);
		static::$pass = trim($t[1]);
	}

	public static function saveAndApplyCredentials($user, $pass) {
		file_put_contents(__DIR__ . EsetConfig::CREDENTIALS_FILE, $user . "\n" . $pass);
		static::$user = $user;
		static::$pass = $pass;
	}

	public static function initialize() {
		// determine languages to skip
		$all_languages = explode(',', static::$all_languages);
		$downloaded_languages = explode(',', static::$downloaded_languages);
		static::$excluded_languages_joined = implode('|', array_diff($all_languages, $downloaded_languages));

		// load credentials
		if (empty(static::$user) || empty(static::$pass)) {
			static::loadCredentials();
		}
		if (empty(static::$user) || empty(static::$pass)) {
			die('Credentials were not set statically, file ' . static::CREDENTIALS_FILE . ' was not found or empty. First line should be username, second the password.');
		}

		// parse CLI parameters
		global $argv;
		if (isset($argv[1])) {
			$pars = $argv;
			array_shift($pars);
			foreach ($pars as $par) {
				$cleanPar = ltrim($par, '-/');
				if ($cleanPar == 'h' || $cleanPar == 'help') {
					EsetUpdate::banner();
					EsetUpdate::usage();
					exit;
				} elseif ($cleanPar == 'noemail') {
					static::$email = null;
				} else {
					if ($cleanPar == 'check') {
						static::$force_check = true;
					} else {
						if ($cleanPar == 'debug') {
							static::$debug = true;
						}
					}
				}
			}
		}

		// check all files if last update was not successful
		if (is_file(EsetConfig::$temp_path . EsetConfig::FORCE_CHECK_TEMPFILE)) {
			echo 'Note: Full forced check detected from previous unsuccessful run.' . "\n";
			EsetConfig::$force_check = true;
			unlink(EsetConfig::$temp_path . EsetConfig::FORCE_CHECK_TEMPFILE);
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
		curl_setopt($ch, CURLOPT_USERAGENT,
			'ESS Update (Windows; U; 64bit; BPC 9.0.374.1; OS: 10.0.10586 SP 0.0 NT; TDB 28246; CL 1.1.1; x64c; APP ess; BEO 1; ASP 0.10; RA 0; HWF: 7822CF11-CDFE-4077-92FF-5E6CF5F99ECD; PLOC cs_cz; PCODE 110.0.0; PAR 0; ATH 0; DC 0; PLID 33D-W8W-NCD; SEAT 660a0d66)');
		curl_setopt($ch, CURLOPT_USERPWD, EsetConfig::$user . ':' . EsetConfig::$pass);
		return $ch;
	}

	private static function getContentAndFreeCurl($ch) {
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}

	public static function getEsetProtectedFile($request) {
		if (EsetConfig::$debug) {
			echo ' - trying to download http://' . EsetConfig::$eset_server . $request . "\n";
		}

		$ch = static::initializeCurl();
		curl_setopt($ch, CURLOPT_URL, 'http://' . EsetConfig::$eset_server . $request);
		return static::getContentAndFreeCurl($ch);
	}

	public static function findExistingFilesRecursively($path, $exclude = ".|..", $include = '*.nup') {
		$path = rtrim($path, "/") . "/";
		$folder_handle = opendir($path);
		$exclude_array = explode("|", $exclude);
		$result = [];
		while (false !== ($filename = readdir($folder_handle))) {
			if (!in_array(strtolower($filename), $exclude_array)) {
				if (is_dir($path . $filename . "/")) {
					$result = array_merge($result, static::findExistingFilesRecursively($path . $filename, $exclude, $include));
				} else {
					if (preg_match('`^' . strtr(preg_quote($include, '`'), ['\*' => '.*?', '\?' => '.']) . '$`i', $filename)) {
						$result[] = $path . $filename;
					}
				}
			}
		}
		return $result;
	}

	public static function RemoveEmptySubFolders($path) {
		$empty = true;
		foreach (glob($path . DIRECTORY_SEPARATOR . "*") as $file) {
			$empty &= is_dir($file) && static::RemoveEmptySubFolders($file);
		}
		return $empty && rmdir($path);
	}

	public static function formatBytes($a) {
		$unim = ["B", "KB", "MB", "GB", "TB", "PB"];
		$c = 0;
		while ($a >= 1024) {
			$c++;
			$a = $a / 1024;
		}
		return number_format($a, ($c ? 2 : 0), ",", " ") . " " . $unim[$c];
	}

	public static function checkForUnauthorized($content) {
		if ((strpos($content, 'HTTP/1.1 401 Unauthorized') !== false) || (strpos($content, '401 Authorization Required') !== false)) {
			if (is_callable(EsetConfig::$refreshPasswordCallable)) {
				echo 'Note: Trying to get fresh credentials.' . "\n";
				if (!call_user_func(EsetConfig::$refreshPasswordCallable)) {
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

	public static function isIniFileUnpacked($filename) {
		return strpos(file_get_contents($filename), '.eset.com/eset_upd/') !== false;
	}
}

class EsetFileHandle {
	private $ch;

	public function __construct($request) {
		$this->ch = EsetUtils::initializeCurl();
		curl_setopt($this->ch, CURLOPT_URL, 'http://' . EsetConfig::$eset_server . $request);
	}

	public function __destruct() {
		if ($this->ch) {
			curl_close($this->ch);
		}
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

class EsetUpdateResult {
	/* @var bool */
	public $all_ok = true;
	/* @var int */
	public $updated_files = 0;
	/* @var int */
	public $total_size = 0;
}

class EsetUpdate {
	private static $updateVerCompressedContent = [];
	private static $updateFiles = [];
	/* @var EsetUpdateResult */
	private static $result;

	public static function banner() {
		echo '-------------------------' . "\n"
			. '-  EsetUpdate by Ashus  -' . "\n"
			. '-------------------------' . "\n" . "\n";
	}

	public static function usage() {
		echo 'Available parameters:' . "\n"
			. '   --noemail         does not send an e-mail if there is a problem with authorization' . "\n"
			. '   --check           forces full re-check' . "\n"
			. '   --debug           shows verbose messages' . "\n";
	}

	public static function update() {
		static::reportBegin();
		static::downloadMetaFiles();
		static::downloadUpdateFiles();
		static::updateMetaFiles();
		static::forceFullCheckIfNeeded();
		static::reportSummary();
		static::cleanOldUpdateFiles();
	}

	private static function downloadMetaFiles() {
		$data = null;

		foreach (EsetConfig::$base_dirs as $base_suffix) {
			// download update.ver meta info
			for ($i = 3; $i > 0; $i--) {
				EsetConfig::randomizeServer();

				$data = EsetUtils::getEsetProtectedFile('/eset_upd' . $base_suffix . '/update.ver');
				if (!$data) {
					echo 'Could not connect to server ' . EsetConfig::$eset_server . '.' . "\n";
					if ($i == 1) {
						die('Maximum tries reached. Bye.' . "\n");
					}
				}

				if (!EsetUtils::checkForUnauthorized($data)) {
					break;
				}
			}

			if ((is_file(EsetConfig::$base_path . $base_suffix . '/update.ver')) &&
				(md5_file(EsetConfig::$base_path . $base_suffix . '/update.ver', true) == md5($data, true))
			) {
				if (EsetConfig::$force_check) {
					echo 'Note: Update.ver checksum is the same, however force checking is enabled.' . "\n";
				} else {
					die('Update.ver checksum is the same, update not needed. Bye.' . "\n");
				}
			}

			file_put_contents(EsetConfig::$temp_path . '/nod32update.rar', $data);

			if (!EsetUtils::isIniFileUnpacked(EsetConfig::$temp_path . '/nod32update.rar')) {
				if (empty(EsetConfig::$unrar_method)) {
					if (in_array('rar', stream_get_wrappers())) {
						// extract update.ver from RAR to variable   uses PECL mod_rar 3.0
						$update_ver = '';
						$rar_file = @fopen('rar://' . urlencode(EsetConfig::$temp_path) . '/nod32update.rar#update.ver', 'r');
						if ($rar_file === false) {
							die('Could not extract update.ver, mod_rar 3.0 failed. Bye.' . "\n");
						}
						while ($s = @fread($rar_file, 1024)) {
							$update_ver .= $s;
						}
						@fclose($rar_file);
					} else {
						// extract update.ver from RAR to variable   uses PECL mod_rar 2.0
						if (function_exists('rar_open') && function_exists('rar_list') && function_exists('rar_close')) {
							$rar_arch = @rar_open(EsetConfig::$temp_path . '/nod32update.rar');
							if ($rar_arch === false) {
								die('Could not extract update.ver, mod_rar 2.0 failed. Bye.' . "\n");
							}

							list($rar_entry) = rar_list($rar_arch);
							/** @var object $rar_entry */
							$rar_stream = $rar_entry->getStream();
							$update_ver = stream_get_contents($rar_stream);
							fclose($rar_stream);
							rar_close($rar_arch);
						} else {
							die('Could not extract update.ver, no rar module found. Bye.' . "\n");
						}
					}

				} else {

					// external exec
					if (is_file(EsetConfig::$temp_path . '/update.ver')) {
						unlink(EsetConfig::$temp_path . '/update.ver');
					}
					$cmd = strtr(EsetConfig::$unrar_method, [
						'{sourceFile}' => '"' . EsetConfig::$temp_path . '/nod32update.rar"',
						'{destDir}' => '"' . EsetConfig::$temp_path . '/"'
					]);
					$res3 = exec($cmd, $res2, $res);
					if (($res != 0) || (!is_file(EsetConfig::$temp_path . '/update.ver'))) {
						die('Update.ver failed to extract [' . implode("\n", $res2) . ']. Bye.' . "\n");
					}

					$update_ver = file_get_contents(EsetConfig::$temp_path . '/update.ver');
					unlink(EsetConfig::$temp_path . '/update.ver');
				}

			} else {
				$update_ver = file_get_contents(EsetConfig::$temp_path . '/nod32update.rar');
			}


			if ($update_ver == '') {
				die('Update.ver failed to extract. Bye.' . "\n");
			}

			static::$updateVerCompressedContent[$base_suffix] = file_get_contents(EsetConfig::$temp_path . '/nod32update.rar');
			unlink(EsetConfig::$temp_path . '/nod32update.rar');

			// find all update files
			preg_match_all('`\sfile=(.*\.nup)\s`', $update_ver, $update_new);
			static::$updateFiles = array_merge(static::$updateFiles, $update_new[1]);
		}
	}

	private static function downloadUpdateFiles() {
		static::$result = new EsetUpdateResult();

		foreach (static::$updateFiles as $upd_file) {
			// skip excluded languages
			if (preg_match('`_(' . EsetConfig::getExcludedLanguagesRegexpPart() . ')\.nup$`', $upd_file)) {
				if (EsetConfig::$debug) {
					echo ' - skipped (wrong lang): ' . $upd_file . "\n";
				}
				continue;
			}

			$fileHandle = new EsetFileHandle($upd_file);
			$info = $fileHandle->getInfo();

			if (EsetUtils::checkForUnauthorized($info)) {
				EsetConfig::$clean_unused = false;
				continue;
			}

			$time_modified = 0;
			if (preg_match('`\sLast\-Modified\: (.*)$`im', $info, $time)) {
				$time_modified = strtotime(trim($time[1]));
			} else {
				echo 'Failed to receive modification time from header of ' . $upd_file . '. Downloading again.' . "\n";
			}

			$size = -1;
			if (preg_match('`\Content\-Length\: (.*)$`im', $info, $size)) {
				$size = (float)(trim($size[1]));
			} else {
				echo 'Failed to receive filesize from header of ' . $upd_file . '.' . "\n";
			}

			if ((!is_file(EsetConfig::$base_path . $upd_file)) ||
				($time_modified < filemtime(EsetConfig::$base_path . $upd_file)) ||
				($size != filesize(EsetConfig::$base_path . $upd_file))
			) {

				// download complete file
				if (EsetConfig::$debug) {
					echo ' - downloading: ' . $upd_file . '' . "\n";
				}

				$dir = dirname(EsetConfig::$base_path . $upd_file);
				if (!is_dir($dir)) {
					mkdir($dir, 0777, true);
				}

				$df = fopen(EsetConfig::$temp_path . EsetConfig::DOWNLOAD_TEMPFILE, 'w');
				$fileHandle->getContentsToStream($df);
				unset($fileHandle);
				fclose($df);

				clearstatcache();
				$size_real = filesize(EsetConfig::$temp_path . EsetConfig::DOWNLOAD_TEMPFILE);
				if ($size_real > 0) {
					rename(EsetConfig::$temp_path . EsetConfig::DOWNLOAD_TEMPFILE, EsetConfig::$base_path . $upd_file);
					static::$result->updated_files++;
					static::$result->total_size += $size_real;

					if ($size_real == $size) {
						touch(EsetConfig::$base_path . $upd_file, $time_modified);
					} else {
						touch(EsetConfig::$base_path . $upd_file, ($time_modified - 3600));
						static::$result->all_ok = false;
						echo 'Warning: Filesize of ' . $upd_file . ' is different than expected, will be retried on next run.' . "\n";
					}

				} else {
					touch(EsetConfig::$base_path . $upd_file, ($time_modified - 3600));
					static::$result->all_ok = false;
					echo 'Warning: Filesize of ' . $upd_file . ' is zero, keeping old file.' . "\n";
					unlink(EsetConfig::$temp_path . EsetConfig::DOWNLOAD_TEMPFILE);
				}

			}
		}
	}

	private static function updateMetaFiles() {
		foreach (EsetConfig::$base_dirs as $base_suffix) {
			$dir = EsetConfig::$base_path . $base_suffix;
			if (!is_dir($dir)) {
				mkdir($dir, 0777, true);
			}
			file_put_contents($dir . '/update.ver', static::$updateVerCompressedContent[$base_suffix]);
		}
	}

	private static function forceFullCheckIfNeeded() {
		if (!static::$result->all_ok) {
			echo 'Note: Forcing full check on next run.' . "\n";
			file_put_contents(EsetConfig::$temp_path . EsetConfig::FORCE_CHECK_TEMPFILE, '');
		}
	}

	private static function reportBegin() {
		echo 'Update launched: ' . date('Y-m-d H:i:s') . "\n";
	}

	private static function reportSummary() {
		echo ((static::$result->updated_files > 0) ? 'Successfully updated ' . static::$result->updated_files . ' files in size of ' . EsetUtils::formatBytes(static::$result->total_size) . '.'
				: 'All files successfully checked.') . "\n";
	}

	private static function cleanOldUpdateFiles() {
		if (static::$result->all_ok && count(static::$updateFiles) && EsetConfig::$clean_unused) {
			$removed_files = 0;
			$removed_size = 0;

			$existing_files = EsetUtils::findExistingFilesRecursively(EsetConfig::$base_path);

			foreach ($existing_files as $curfile) {
				$curfile = str_replace(EsetConfig::$base_path, '', $curfile);
				if (!in_array($curfile, static::$updateFiles, true)) {
					$size = filesize(EsetConfig::$base_path . $curfile);
					if (unlink(EsetConfig::$base_path . $curfile)) {
						$removed_files++;
						$removed_size += $size;
					} else {
						echo 'Could not remove unused file (' . $curfile . ').' . "\n";
					}
				}
			}

			EsetUtils::RemoveEmptySubFolders(EsetConfig::$base_path);

			echo 'Clean-up complete.' . (($removed_files > 0) ? ' ' . $removed_files . ' files in size of ' . EsetUtils::formatBytes($removed_size) . ' were removed.' : ' No files were removed.') . "\n";
		}
	}

}
