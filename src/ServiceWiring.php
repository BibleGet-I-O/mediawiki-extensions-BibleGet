<?php

// Service wiring for BibleGet; services are added by later tasks.

use MediaWiki\Extension\BibleGet\BibleGetClient;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

return [
	'BibleGet.Client' => static function ( MediaWikiServices $services ): BibleGetClient {
		$version = ExtensionRegistry::getInstance()->getAllThings()['BibleGet']['version'] ?? '2.0.0';
		return new BibleGetClient(
			$services->getHttpRequestFactory(),
			(string)$services->getMainConfig()->get( 'BibleGetApiBase' ),
			$version,
			LoggerFactory::getInstance( 'BibleGet' )
		);
	},
];
