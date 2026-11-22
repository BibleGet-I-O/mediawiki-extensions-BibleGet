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
			[ 'https://[::ffff:8.8.8.8]' ],
			'dotted-tail global IPv6' => [ 'https://[2606:4700::104.16.132.229]' ],
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
			'short IPv4' => [ 'https://127.1' ],
			'IPv4 as one number' => [ 'https://2130706433' ],
			'hex IPv4' => [ 'https://0x7f.1' ],
			'empty label' => [ 'https://a..b.org' ],
			'unspecified' => [ 'https://0.0.0.0' ],
			'IPv6 loopback' => [ 'https://[::1]' ],
			'IPv6 unique local' => [ 'https://[fd12:3456::1]' ],
			'IPv6 link-local' => [ 'https://[fe80::1]' ],
			'IPv4-mapped loopback' => [ 'https://[::ffff:7f00:1]' ],
			'IPv4-mapped private, dotted' => [ 'https://[::ffff:192.168.1.10]' ],
			'IPv4-compatible private' => [ 'https://[::10.0.0.1]' ],
			'NAT64 loopback' => [ 'https://[64:ff9b::127.0.0.1]' ],
			'IPv4-compatible public' => [ 'https://[::8.8.8.8]' ],
			'NAT64 public' => [ 'https://[64:ff9b::808:808]' ],
			'IPv6 multicast' => [ 'https://[ff02::1]' ],
			'IPv6 site-local' => [ 'https://[fec0::1]' ],
			'IPv6 documentation' => [ 'https://[2001:db8::1]' ],
			'IPv6 documentation (RFC 9637)' => [ 'https://[3fff::1]' ],
			'IPv4 multicast' => [ 'https://224.0.0.1' ],
			'6to4 private' => [ 'https://[2002:c0a8:101::1]' ],
			'6to4 public' => [ 'https://[2002:808:808::1]' ],
			'home.arpa itself' => [ 'https://home.arpa' ],
		];
	}

	/**
	 * @dataProvider provideInternal
	 */
	public function testInternal( string $url ) {
		$this->assertFalse( SiteAddress::isPublicHttps( $url ) );
	}
}
