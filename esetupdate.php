#!/usr/bin/php
<?php
/**
 * Created by Ashus, all rights reserved
 * https://ashus.ashus.net/viewtopic.php?f=3&t=153
 * https://github.com/Ashus/eset-update
 */

require_once __DIR__ . '/inc.classes.php';
require_once __DIR__ . '/inc.config.php';

EsetConfig::initialize();

EsetUpdate::banner();
EsetUpdate::update();
