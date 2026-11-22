<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Echo is optional; its classes are used only when it is loaded.
$cfg['directory_list'] = array_merge( $cfg['directory_list'], [ '../../extensions/Echo' ] );
$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [ '../../extensions/Echo' ]
);

// extension.json requires PHP 8.1 (promoted readonly properties, non-capturing catch).
$cfg['minimum_target_php_version'] = '8.1';

return $cfg;
