#!/usr/bin/php
<?php

require_once __DIR__ . '/inc.classes.php';
require_once __DIR__ . '/inc.config.php';

EsetConfig::initialize();

echo '-------------------------' . "\n"
	. '-  EsetUpdate by Ashus  -' . "\n"
	. '-------------------------' . "\n" . "\n"
	. 'Launched: ' . date('Y-m-d H:i:s') . "\n";

// check all files if last update was not successful
if (is_file(EsetConfig::$temp_path . EsetConfig::FORCE_CHECK_TEMPFILE)) {
	echo 'Note: Full forced check detected from previous unsuccessful run.' . "\n";
	EsetConfig::$force_check = true;
	unlink(EsetConfig::$temp_path . EsetConfig::FORCE_CHECK_TEMPFILE);
}


$updateVerCompressed = $update_files = [];
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

		if (!EsetUtils::checkForUnauthorized($data))
			break;
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


	if (empty(EsetConfig::$unrar_method)) {

		if (in_array('rar', stream_get_wrappers())) {
			// extract update.ver from RAR to variable   uses PECL mod_rar 3.0
			$update_ver = '';
			$rar_file = @fopen('rar://' . urlencode(EsetConfig::$temp_path) . '/nod32update.rar#update.ver', 'r');
			if ($rar_file === false)
				die('Could not extract update.ver, mod_rar 3.0 failed. Bye.' . "\n");
			while ($s = @fread($rar_file, 1024)) {
				$update_ver .= $s;
			}
			@fclose($rar_file);
		} else {
			// extract update.ver from RAR to variable   uses PECL mod_rar 2.0
			if (function_exists('rar_open') && function_exists('rar_list') && function_exists('rar_close')) {
				$rar_arch = @rar_open(EsetConfig::$temp_path . '/nod32update.rar');
				if ($rar_arch === false)
					die('Could not extract update.ver, mod_rar 2.0 failed. Bye.' . "\n");

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
			'{destDir}' => '"' . EsetConfig::$temp_path . '/"']);
		$res3 = exec($cmd, $res2, $res);
		if (($res != 0) || (!is_file(EsetConfig::$temp_path . '/update.ver'))) {
			die('Update.ver failed to extract [' . $res3 . ']. Bye.' . "\n");
		}

		$update_ver = file_get_contents(EsetConfig::$temp_path . '/update.ver');
		unlink(EsetConfig::$temp_path . '/update.ver');
	}


	if ($update_ver == '') {
		die('Update.ver failed to extract. Bye.' . "\n");
	}

	$updateVerCompressed[$base_suffix] = file_get_contents(EsetConfig::$temp_path . '/nod32update.rar');
	unlink(EsetConfig::$temp_path . '/nod32update.rar');

	// find all update files
	preg_match_all('`\sfile=(.*\.nup)\s`', $update_ver, $update_new);
	$update_files = array_merge($update_files, $update_new[1]);
}


// download new and updated files

$all_ok = true;
$updated_files = 0;
$total_size = 0;

foreach ($update_files as $upd_file) {
	// skip excluded languages
	if (preg_match('`_(' . EsetConfig::getExcludedLanguagesRegexpPart() . ')\.nup$`', $upd_file)) {
		if (EsetConfig::$debug)
			echo ' - skipped (wrong lang): ' . $upd_file . "\n";
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
			$updated_files++;
			$total_size += $size_real;

			if ($size_real == $size) {
				touch(EsetConfig::$base_path . $upd_file, $time_modified);
			} else {
				touch(EsetConfig::$base_path . $upd_file, ($time_modified - 3600));
				$all_ok = false;
				echo 'Warning: Filesize of ' . $upd_file . ' is different than expected, will be retried on next run.' . "\n";
			}

		} else {
			touch(EsetConfig::$base_path . $upd_file, ($time_modified - 3600));
			$all_ok = false;
			echo 'Warning: Filesize of ' . $upd_file . ' is zero, keeping old file.' . "\n";
			unlink(EsetConfig::$temp_path . EsetConfig::DOWNLOAD_TEMPFILE);
		}

	}
}

foreach (EsetConfig::$base_dirs as $base_suffix) {
	// update meta file
	$dir = EsetConfig::$base_path . $base_suffix;
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($dir . '/update.ver', $updateVerCompressed[$base_suffix]);
}


if (!$all_ok) {
	echo 'Note: Forcing full check on next run.' . "\n";
	file_put_contents(EsetConfig::$temp_path . EsetConfig::FORCE_CHECK_TEMPFILE, '');
}

echo (($updated_files > 0) ? 'Successfully updated ' . $updated_files . ' files in size of ' . EsetUtils::formatBytes($total_size) . '.'
		: 'All files successfully checked.') . "\n";


// clean the old, outdated nup files
if (count($update_files) && EsetConfig::$clean_unused) {
	$removed_files = 0;
	$total_size = 0;

	$existing_files = EsetUtils::findExistingFilesRecursively(EsetConfig::$base_path);

	foreach ($existing_files as $curfile) {
		$curfile = str_replace(EsetConfig::$base_path, '', $curfile);
		if (!in_array($curfile, $update_files, true)) {
			$size = filesize(EsetConfig::$base_path . $curfile);
			if (unlink(EsetConfig::$base_path . $curfile)) {
				$removed_files++;
				$total_size += $size;
			} else {
				echo 'Could not remove unused file (' . $curfile . ').' . "\n";
			}
		}
	}

	EsetUtils::RemoveEmptySubFolders(EsetConfig::$base_path);

	echo 'Clean-up complete.' . (($removed_files > 0) ? ' ' . $removed_files . ' files in size of ' . EsetUtils::formatBytes($total_size) . ' were removed.' : ' No files were removed.') . "\n";
}
