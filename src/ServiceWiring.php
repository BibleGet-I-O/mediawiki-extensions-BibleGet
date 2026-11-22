<?php

// Service wiring for BibleGet.

use MediaWiki\Extension\BibleGet\BibleGetClient;
use MediaWiki\Extension\BibleGet\CredentialProvider;
use MediaWiki\Extension\BibleGet\InstallationStore;
use MediaWiki\Extension\BibleGet\QuoteFetcher;
use MediaWiki\Extension\BibleGet\QuoteRenderer;
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
	'BibleGet.InstallationStore' => static function ( MediaWikiServices $services ): InstallationStore {
		return new InstallationStore( $services->getDBLoadBalancer() );
	},
	'BibleGet.CredentialProvider' => static function ( MediaWikiServices $services ): CredentialProvider {
		$config = $services->getMainConfig();
		$apiKey = $config->get( 'BibleGetApiKey' );
		return new CredentialProvider(
			$apiKey === null ? null : (string)$apiKey,
			(string)$config->get( 'BibleGetClientKey' ),
			(string)$config->get( 'CanonicalServer' ),
			$services->getService( 'BibleGet.InstallationStore' ),
			$services->getService( 'BibleGet.Client' ),
			$services->getMainObjectStash(),
			$services->getGlobalIdGenerator(),
			LoggerFactory::getInstance( 'BibleGet' )
		);
	},
	'BibleGet.QuoteFetcher' => static function ( MediaWikiServices $services ): QuoteFetcher {
		$config = $services->getMainConfig();
		return new QuoteFetcher(
			$services->getService( 'BibleGet.Client' ),
			$services->getService( 'BibleGet.CredentialProvider' ),
			$services->getMainWANObjectCache(),
			$services->getMainObjectStash(),
			(string)$config->get( 'BibleGetApiBase' ),
			(int)$config->get( 'BibleGetCacheExpiry' ),
			(int)$config->get( 'BibleGetMaxVersesPerQuote' ),
			LoggerFactory::getInstance( 'BibleGet' )
		);
	},
	'BibleGet.QuoteRenderer' => static function ( MediaWikiServices $services ): QuoteRenderer {
		$config = $services->getMainConfig();
		return new QuoteRenderer(
			$services->getService( 'BibleGet.QuoteFetcher' ),
			$services->getUserFactory(),
			(string)$config->get( 'BibleGetDefaultBibleVersion' ),
			(int)$config->get( 'BibleGetMaxQuotesPerPage' ),
			(int)$config->get( 'BibleGetMaxVersesPerQuote' )
		);
	},
];
