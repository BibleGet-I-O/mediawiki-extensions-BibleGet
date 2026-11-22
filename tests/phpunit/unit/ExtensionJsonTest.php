<?php

namespace MediaWiki\Extension\BibleGet\Tests\Unit;

use MediaWikiUnitTestCase;

/**
 * @coversNothing
 */
class ExtensionJsonTest extends MediaWikiUnitTestCase {

	private function load(): array {
		$json = file_get_contents( __DIR__ . '/../../../extension.json' );
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	public function testConfigDefaults() {
		$ext = $this->load();
		$this->assertSame( '2.0.0', $ext['version'] );
		$this->assertSame( 2, $ext['manifest_version'] );
		$this->assertSame( '>= 1.43.0', $ext['requires']['MediaWiki'] );
		$this->assertArrayNotHasKey( 'ext-curl', $ext['requires']['platform'] );

		$expected = [
			'BibleGetApiBase' => 'https://query.bibleget.io/v4/',
			'BibleGetApiKey' => null,
			'BibleGetClientKey' => '',
			'BibleGetDefaultBibleVersion' => 'NABRE',
			'BibleGetCacheExpiry' => 2592000,
			'BibleGetMaxQuotesPerPage' => 50,
			'BibleGetMaxVersesPerQuote' => 30,
		];
		foreach ( $expected as $key => $value ) {
			$this->assertArrayHasKey( $key, $ext['config'], $key );
			$this->assertSame( $value, $ext['config'][$key]['value'], $key );
		}

		$this->assertContains( 'bibleget-manage', $ext['AvailableRights'] );
		$this->assertTrue( $ext['GroupPermissions']['sysop']['bibleget-manage'] );

		$this->assertSame( [
			'anon' => [ 'ip' => [ 30, 60 ] ],
			'ip' => [ 30, 60 ],
			'newbie' => [ 30, 60 ],
			'user' => [ 120, 60 ],
		], $ext['RateLimits']['bibleget-fetch'] );

		$this->assertSame(
			[ 'bibleget-tracking-category', 'bibleget-toolong-category' ],
			$ext['TrackingCategories']
		);
		$this->assertSame( [ 'src/ServiceWiring.php' ], $ext['ServiceWiringFiles'] );
	}
}
