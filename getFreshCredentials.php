#!/usr/bin/php
<?php
/**
 * Created by Ashus, all rights reserved
 * https://ashus.ashus.net/viewtopic.php?f=3&t=153
 * https://github.com/Ashus/eset-update
 */

require_once __DIR__ . '/inc.classes.php';

function getFreshCredentials() {

//	$page = file_get_contents('http://pakeset4u.blogspot.com/');
//	preg_match_all('`Username:\s?([A-Z0-9\-]+)(?:.*?)Password:\s?([a-z0-9]+)`ms', $page, $res);
//
//	$page = file_get_contents('http://esetnodman12.blogspot.com/');
//	preg_match_all('`\s?((?:EAV|TRIAL)[0-9\-]+)(?:.*?)>([a-z0-9]+)`ms', $page, $res);
//
//	$page = file_get_contents('https://docs.google.com/document/d/1R9yq6UGUSkZrjW0tEZWsUb38CxC_3zZ-7eUywhLPExg/pub');
//	preg_match_all('`\s?((?:EAV|TRIAL)[0-9\-]+)(?:.*?)>([a-z0-9]+)`ms', $page, $res);
//
//	$page = file_get_contents('http://www.nod327.net/');
//	preg_match_all('`Username:\s?([A-Z]+\-[0-9]+)(?:.*?)nod32key\s?\:?\s?([a-z0-9]+)`ms', strip_tags($page), $res);
//
//	$page = file_get_contents('http://updateanti-virus.com/');
//	preg_match_all('`Username:\s?([A-Z]+\-[0-9]+)(?:.*?)Password\s?\:?\s?([a-z0-9]+)`ms', strip_tags($page), $res);
//
	$page = file_get_contents('http://keynod.blogsky.com/');
	preg_match_all('`Username:\s?([A-Z]+\-[0-9]+)(?:.*?)Password\s?\:?\s?([a-z0-9]+)`ms', strip_tags($page), $res);


	if (count($res[1]) > 0) {
		$klist = array();
		foreach ($res[1] as $n => $v) {
			$klist[] = $v . "\n" . $res[2][$n];
			if (count($klist) == 70) {
				break;
			}
		}
// 		var_dump($klist); exit;
		$key = $klist[mt_rand(0, count($klist) - 1)];
		$data = explode("\n", $key);

		EsetConfig::saveAndApplyCredentials($data[0], $data[1]);
		if (EsetConfig::$initialized)
			sleep(1);
		return true;
	}

	return false;
}

if (count(get_included_files()) == 1) {
	getFreshCredentials();
}
