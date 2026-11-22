<?php

namespace MediaWiki\Extension\BibleGet;

/**
 * Whether a wiki's address ($wgCanonicalServer) is a public https address. Registration counts real
 * installations, so a wiki on any other address (http, localhost, a private IP address, a name
 * without a public domain) does not register, and its address is never sent: it uses a project key
 * instead. The API applies the same rule. Only the address is looked at; nothing is resolved or fetched.
 */
class SiteAddress {

	/** Top-level labels that are not public domains (RFC 2606, RFC 6761, RFC 6762, common intranet use) */
	private const INTERNAL_TLDS = [
		'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp',
		'private', 'test', 'example', 'invalid',
	];

	/** Ranges that FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE miss on PHP 8.1 */
	private const INTERNAL_RANGES = [
		'100.64.0.0/10',
		'::1/128',
		'::/128',
		'fc00::/7',
		'fe80::/10',
	];

	public static function isPublicHttps( string $url ): bool {
		if ( strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) ) !== 'https' ) {
			return false;
		}
		$host = parse_url( $url, PHP_URL_HOST );
		if ( !is_string( $host ) || $host === '' ) {
			return false;
		}
		$host = rtrim( strtolower( $host ), '.' );
		$ip = trim( $host, '[]' );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
			return self::isPublicIp( $ip );
		}
		$labels = explode( '.', $host );
		if ( count( $labels ) < 2 || in_array( '', $labels, true ) ) {
			return false;
		}
		$last = end( $labels );
		if ( ctype_digit( $last ) || preg_match( '/^0x[0-9a-f]*$/', $last ) ) {
			// An IPv4 address in another form (127.1, 2130706433, 0x7f.1), never a public name
			return false;
		}
		if ( in_array( $last, self::INTERNAL_TLDS, true ) ) {
			return false;
		}
		return !str_ends_with( $host, '.home.arpa' );
	}

	private static function isPublicIp( string $ip ): bool {
		$embedded = self::embeddedIpv4( $ip );
		if ( $embedded !== null ) {
			return self::isPublicIp( $embedded );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return false;
		}
		foreach ( self::INTERNAL_RANGES as $range ) {
			if ( self::inRange( $ip, $range ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The IPv4 address inside an IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96) or NAT64
	 * (64:ff9b::/96) IPv6 address, else null: PHP 8.1's filter does not look inside these.
	 */
	private static function embeddedIpv4( string $ip ): ?string {
		$bin = inet_pton( $ip );
		if ( $bin === false || strlen( $bin ) !== 16 ) {
			return null;
		}
		$prefix = substr( $bin, 0, 12 );
		$prefixes = [
			str_repeat( "\0", 10 ) . "\xff\xff",
			str_repeat( "\0", 12 ),
			"\0\x64\xff\x9b" . str_repeat( "\0", 8 ),
		];
		if ( !in_array( $prefix, $prefixes, true ) ) {
			return null;
		}
		$ipv4 = inet_ntop( substr( $bin, 12 ) );
		return $ipv4 === false ? null : $ipv4;
	}

	private static function inRange( string $ip, string $cidr ): bool {
		[ $net, $bits ] = explode( '/', $cidr );
		$ipBin = inet_pton( $ip );
		$netBin = inet_pton( $net );
		if ( $ipBin === false || $netBin === false || strlen( $ipBin ) !== strlen( $netBin ) ) {
			return false;
		}
		$bits = (int)$bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ipBin, 0, $bytes ) !== substr( $netBin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( $rest === 0 ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $ipBin[$bytes] ) & $mask ) === ( ord( $netBin[$bytes] ) & $mask );
	}
}
