<?php

namespace MediaWiki\Extension\BibleGet;

use DateTimeImmutable;
use DateTimeZone;
use MessageLocalizer;

/**
 * The service notices BibleGet sends: the answer's service_notices field and the
 * Deprecation/Sunset headers, checked and turned into one shape:
 * [ 'id', 'message', 'link' (https or null), 'published', 'deprecation', 'sunset' ].
 */
class ServiceNotices {

	/** Ids of bundled notices: stable slugs. Header notices use "deprecation:" or "sunset:" plus the date. */
	private const ID_PATTERN = '/^[a-z0-9-]{3,64}$/D';
	private const HEADER_KINDS = [ 'deprecation', 'sunset' ];

	/**
	 * The service_notices JSON of an answer. Malformed entries and bad ids are dropped.
	 *
	 * @return array[]
	 */
	public static function fromJson( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( !is_array( $decoded ) || !array_is_list( $decoded ) ) {
			return [];
		}
		$notices = [];
		foreach ( $decoded as $entry ) {
			$notice = self::fromEntry( $entry );
			if ( $notice !== null && !isset( $notices[$notice['id']] ) ) {
				$notices[$notice['id']] = $notice;
			}
		}
		return array_values( $notices );
	}

	/**
	 * @param mixed $entry
	 */
	private static function fromEntry( $entry ): ?array {
		if ( !is_array( $entry ) ) {
			return null;
		}
		$id = $entry['id'] ?? null;
		$message = $entry['message'] ?? null;
		if ( !is_string( $id ) || !preg_match( self::ID_PATTERN, $id )
			|| !is_string( $message ) || trim( $message ) === ''
		) {
			return null;
		}
		return [
			'id' => $id,
			'message' => $message,
			'link' => self::safeLink( $entry['link'] ?? null ),
			'published' => self::stringOrNull( $entry['published'] ?? null ),
			'deprecation' => self::stringOrNull( $entry['deprecation'] ?? null ),
			'sunset' => self::stringOrNull( $entry['sunset'] ?? null ),
		];
	}

	/**
	 * Notices from the Deprecation (RFC 9745, "@<unix time>") and Sunset (RFC 8594, HTTP-date)
	 * headers, each with the Link of the same rel. They have no text of their own.
	 *
	 * @param string[] $headers Response headers, names lower-cased
	 * @return array[]
	 */
	public static function fromHeaders( array $headers ): array {
		$links = self::parseLinks( $headers['link'] ?? '' );
		$notices = [];
		foreach ( self::HEADER_KINDS as $kind ) {
			$time = self::headerTime( $kind, trim( (string)( $headers[$kind] ?? '' ) ) );
			if ( $time === null ) {
				continue;
			}
			$notices[] = [
				'id' => $kind . ':' . gmdate( 'Y-m-d', $time ),
				'message' => '',
				'link' => $links[$kind] ?? null,
				'published' => null,
				'deprecation' => $kind === 'deprecation' ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null,
				'sunset' => $kind === 'sunset' ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null,
			];
		}
		return $notices;
	}

	private static function headerTime( string $kind, string $value ): ?int {
		if ( $value === '' ) {
			return null;
		}
		if ( $kind === 'deprecation' ) {
			return preg_match( '/^@(-?\d+)$/D', $value, $m ) ? (int)$m[1] : null;
		}
		// HTTP-date in its IMF-fixdate form, e.g. "Fri, 01 Jan 2027 00:00:00 GMT"
		$date = DateTimeImmutable::createFromFormat( '!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone( 'UTC' ) );
		return $date === false ? null : $date->getTimestamp();
	}

	/**
	 * The https target of each rel in a Link header (values joined by ", ").
	 *
	 * @return string[] rel => url
	 */
	private static function parseLinks( string $header ): array {
		$links = [];
		foreach ( preg_split( '/,(?=\s*<)/', $header ) ?: [] as $part ) {
			if ( !preg_match( '/^\s*<([^>]*)>(.*)$/s', $part, $m )
				|| !preg_match( '/;\s*rel\s*=\s*(?:"([^"]*)"|([^\s;,]+))/i', $m[2], $rel )
			) {
				continue;
			}
			$url = self::safeLink( $m[1] );
			foreach ( preg_split( '/\s+/', strtolower( trim( ( $rel[1] ?? '' ) . ( $rel[2] ?? '' ) ) ) ) as $name ) {
				if ( $url !== null && !isset( $links[$name] ) ) {
					$links[$name] = $url;
				}
			}
		}
		return $links;
	}

	/**
	 * Bundled notices first; a header notice is dropped when a bundled notice has its id, or
	 * already announces the same deprecation or sunset date (the headers repeat the earliest).
	 *
	 * @param array[] $bundled
	 * @param array[] $fromHeaders
	 * @return array[]
	 */
	public static function merge( array $bundled, array $fromHeaders ): array {
		$merged = [];
		$dates = [];
		foreach ( $bundled as $notice ) {
			$merged[$notice['id']] ??= $notice;
			foreach ( self::HEADER_KINDS as $kind ) {
				$date = self::day( $notice[$kind] ?? null );
				if ( $date !== null ) {
					$dates["$kind:$date"] = true;
				}
			}
		}
		foreach ( $fromHeaders as $notice ) {
			if ( !isset( $merged[$notice['id']] ) && !isset( $dates[$notice['id']] ) ) {
				$merged[$notice['id']] = $notice;
			}
		}
		return array_values( $merged );
	}

	private static function day( ?string $value ): ?string {
		$time = $value === null ? false : strtotime( $value );
		return $time === false ? null : gmdate( 'Y-m-d', $time );
	}

	/**
	 * The link if it is an absolute https URL with a host, else null.
	 *
	 * @param mixed $link
	 */
	public static function safeLink( $link ): ?string {
		if ( !is_string( $link ) || !preg_match( '!^https://[^/?#\s]+[^\s]*$!iD', $link ) ) {
			return null;
		}
		return $link;
	}

	/**
	 * The text to show for a stored notice: its own message, or for a header notice
	 * (no message) a localised one naming the date.
	 *
	 * @param array $notice [ 'id' => string, 'message' => string ]
	 */
	public static function text( array $notice, MessageLocalizer $localizer ): string {
		$message = (string)( $notice['message'] ?? '' );
		if ( trim( $message ) !== '' ) {
			return $message;
		}
		[ $kind, $date ] = array_pad( explode( ':', (string)( $notice['id'] ?? '' ), 2 ), 2, '' );
		$key = $kind === 'sunset' ? 'bibleget-notice-sunset' : 'bibleget-notice-deprecation';
		return $localizer->msg( $key )->plaintextParams( $date )->text();
	}

	/**
	 * @param mixed $value
	 */
	private static function stringOrNull( $value ): ?string {
		return is_string( $value ) ? $value : null;
	}
}
