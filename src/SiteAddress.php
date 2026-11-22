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

	/** IPv4 ranges that FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE miss on PHP 8.1 */
	private const INTERNAL_IPV4_RANGES = [
		'100.64.0.0/10',
		'224.0.0.0/4',
	];

	/**
	 * Prefixes inside global unicast that are not a site's own address: documentation (RFC 3849,
	 * RFC 9637) and 6to4 (RFC 3056), refused whatever IPv4 address it carries
	 */
	private const INTERNAL_IPV6_RANGES = [
		'2001:db8::/32',
		'3fff::/20',
		'2002::/16',
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
		return $host !== 'home.arpa' && !str_ends_with( $host, '.home.arpa' );
	}

	/**
	 * Judged from the address's bytes, the same way the API does: an IPv4-mapped IPv6 address by its
	 * IPv4 address, any other IPv6 address only if it is global unicast (2000::/3), so IPv4-compatible
	 * and NAT64 addresses, multicast, site-local, unique local and link-local are all refused.
	 */
	private static function isPublicIp( string $ip ): bool {
		$bin = inet_pton( $ip );
		if ( $bin === false ) {
			return false;
		}
		if ( strlen( $bin ) === 16 ) {
			if ( substr( $bin, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
				$ipv4 = inet_ntop( substr( $bin, 12 ) );
				return $ipv4 !== false && self::isPublicIp( $ipv4 );
			}
			if ( ( ord( $bin[0] ) & 0xe0 ) !== 0x20 ) {
				return false;
			}
			$ranges = self::INTERNAL_IPV6_RANGES;
		} else {
			$ranges = self::INTERNAL_IPV4_RANGES;
		}
		// The canonical form: PHP reads a dotted tail (2606:4700::104.16.132.229) as an IPv4 address.
		$canonical = inet_ntop( $bin );
		if ( $canonical === false || filter_var( $canonical, FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false
		) {
			return false;
		}
		foreach ( $ranges as $range ) {
			if ( self::inRange( $canonical, $range ) ) {
				return false;
			}
		}
		return true;
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
