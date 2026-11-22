<?php

namespace MediaWiki\Extension\BibleGet\Tests;

use MediaWiki\Hook\ParserTestGlobalsHook;
use MediaWiki\MediaWikiServices;

/**
 * Parser tests use FakeQuoteFetcher, so they never call the BibleGet API.
 * The hook only runs in the parser test runner.
 */
class ParserTestHooks implements ParserTestGlobalsHook {

	/**
	 * @param array &$globals
	 */
	public function onParserTestGlobals( &$globals ) {
		$globals[] = static function () {
			$services = MediaWikiServices::getInstance();
			foreach ( [ 'BibleGet.QuoteRenderer', 'BibleGet.QuoteFetcher' ] as $name ) {
				$services->resetServiceForTesting( $name );
			}
			$services->redefineService(
				'BibleGet.QuoteFetcher',
				static fn () => new FakeQuoteFetcher()
			);
		};
	}
}
