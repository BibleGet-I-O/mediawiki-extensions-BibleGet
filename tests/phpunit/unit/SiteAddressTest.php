<?php

namespace MediaWiki\Extension\BibleGet\Tests\Unit;

use MediaWiki\Extension\BibleGet\SiteAddress;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\BibleGet\SiteAddress
 */
class SiteAddressTest extends MediaWikiUnitTestCase {

	public static function providePublic(): array {
		return [
			[ 'https://en.seminaverbi.bibleget.io' ],
			[ 'https://wiki.example.org:8443' ],
			[ 'https://WIKI.Example.ORG.' ],
			[ 'https://8.8.8.8' ],
			[ 'https://[2001:4860:4860::8888]' ],
		];
	}

	/**
	 * @dataProvider providePublic
	 */
	public function testPublic( string $url ) {
		$this->assertTrue( SiteAddress::isPublicHttps( $url ) );
	}

	public static function provideInternal(): array {
		return [
			'http' => [ 'http://wiki.example.org' ],
			'protocol-relative' => [ '//wiki.example.org' ],
			'other scheme' => [ 'ftp://wiki.example.org' ],
			'empty' => [ '' ],
			'no host' => [ 'https://' ],
			'not a URL' => [ 'not a url' ],
			'localhost' => [ 'https://localhost:8043' ],
			'localhost subdomain' => [ 'https://wiki.localhost' ],
			'single label' => [ 'https://wiki' ],
			'mDNS' => [ 'https://wiki.local' ],
			'localdomain' => [ 'https://wiki.localdomain' ],
			'internal' => [ 'https://wiki.corp.internal' ],
			'intranet' => [ 'https://wiki.intranet' ],
			'lan' => [ 'https://nas.lan' ],
			'home' => [ 'https://wiki.home' ],
			'corp' => [ 'https://wiki.corp' ],
			'private' => [ 'https://wiki.private' ],
			'test' => [ 'https://wiki.example.test' ],
			'example' => [ 'https://wiki.example' ],
			'invalid' => [ 'https://wiki.invalid' ],
			'home.arpa' => [ 'https://wiki.home.arpa' ],
			'loopback' => [ 'https://127.0.0.1' ],
			'private 10' => [ 'https://10.1.2.3' ],
			'private 172' => [ 'https://172.16.0.5:8080' ],
			'private 192' => [ 'https://192.168.1.10' ],
			'link-local' => [ 'https://169.254.10.1' ],
			'CGNAT' => [ 'https://100.64.1.1' ],
			'unspecified' => [ 'https://0.0.0.0' ],
			'IPv6 loopback' => [ 'https://[::1]' ],
			'IPv6 unique local' => [ 'https://[fd12:3456::1]' ],
			'IPv6 link-local' => [ 'https://[fe80::1]' ],
		];
	}

	/**
	 * @dataProvider provideInternal
	 */
	public function testInternal( string $url ) {
		$this->assertFalse( SiteAddress::isPublicHttps( $url ) );
	}
}
