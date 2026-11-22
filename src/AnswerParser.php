<?php

namespace MediaWiki\Extension\BibleGet;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns a BibleGet HTML answer into a QuoteAnswer: only the API's div.results
 * reaches the output, rebuilt through an allowlist, and cut to a verse limit.
 */
class AnswerParser {

	/** Code of the per-quote notice the endpoint adds when its own verse cap cuts a quote. */
	public const CAP_NOTICE_CODE = 'VERSES_CAPPED';

	private const ALLOWED_ELEMENTS = [ 'div', 'p', 'span', 'br', 'i', 'sup' ];
	private const ALLOWED_ATTRIBUTES = [ 'class', 'data-printed-chapter', 'data-verse-label', 'data-omitted' ];
	private const DROP_WITH_CONTENT = [ 'script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed' ];

	public static function parse( string $html, int $maxVerses ): QuoteAnswer {
		$doc = self::load( $html );
		$xpath = new DOMXPath( $doc );

		$errors = self::parseErrors( $xpath );
		$notices = self::parseNotices( $xpath );
		$cappedByApi = false;
		foreach ( $notices as $notice ) {
			if ( is_array( $notice ) && ( $notice['code'] ?? null ) === self::CAP_NOTICE_CODE ) {
				$cappedByApi = true;
				break;
			}
		}

		$out = '';
		$verseCount = 0;
		$cut = false;
		$resultsQuery = '(//div[contains(concat(" ", normalize-space(@class), " "), " results ")])[1]';
		$results = $xpath->query( $resultsQuery )->item( 0 );
		if ( $results instanceof DOMElement ) {
			[ $verseCount, $cut ] = self::cut( $xpath, $results, $maxVerses );
			$out = self::serialise( $results );
		}

		return new QuoteAnswer(
			$out, $errors, $verseCount, $cut, $cappedByApi, $notices, self::parseServiceNotices( $xpath )
		);
	}

	private static function load( string $html ): DOMDocument {
		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}

	private static function parseErrors( DOMXPath $xpath ): array {
		$errors = [];
		foreach ( $xpath->query( '//tr[td[contains(@class,"errNumVal")]]' ) as $row ) {
			$num = $xpath->query( './/td[contains(@class,"errNumVal")]', $row )->item( 0 );
			$msg = $xpath->query( './/td[contains(@class,"errMessageVal")]', $row )->item( 0 );
			$errors[] = [
				'num' => (int)( $num ? $num->textContent : 0 ),
				'message' => trim( $msg ? $msg->textContent : '' ),
			];
		}
		return $errors;
	}

	private static function parseNotices( DOMXPath $xpath ): array {
		$input = $xpath->query( '//input[@name="notices"]' )->item( 0 );
		if ( !$input instanceof DOMElement ) {
			return [];
		}
		$decoded = json_decode( $input->getAttribute( 'value' ), true );
		return is_array( $decoded ) ? array_values( $decoded ) : [];
	}

	/**
	 * The service notices bundled with the answer (input.BibleGetServiceNotices), checked.
	 *
	 * @return array[]
	 */
	private static function parseServiceNotices( DOMXPath $xpath ): array {
		$input = $xpath->query( '//input[@name="service_notices"]' )->item( 0 );
		return $input instanceof DOMElement ? ServiceNotices::fromJson( $input->getAttribute( 'value' ) ) : [];
	}

	/**
	 * Count verses (a verse is a chapter plus the leading number of its label, so 5a and 5b
	 * are one) and remove everything after the $max-th. Mutates $results.
	 *
	 * @return array [ int $verseCount, bool $cut ]
	 */
	private static function cut( DOMXPath $xpath, DOMElement $results, int $max ): array {
		$chapter = '';
		$seen = [];
		$cut = false;
		$drop = [];

		$query = './/p[contains(@class,"bookChapter")] | .//span[contains(@class,"verseNum")]';
		foreach ( $xpath->query( $query, $results ) as $node ) {
			/** @var DOMElement $node */
			if ( $node->nodeName === 'p' ) {
				$heading = str_replace( "\u{00A0}", ' ', $node->textContent );
				$chapter = preg_match( '/(\d+)\s*$/u', $heading, $m ) ? $m[1] : $chapter;
				continue;
			}
			if ( $node->hasAttribute( 'data-printed-chapter' ) ) {
				$chapter = $node->getAttribute( 'data-printed-chapter' );
			}
			$label = $node->getAttribute( 'data-verse-label' ) ?: $node->textContent;
			$key = $chapter . ':' . ( preg_match( '/\d+/', $label, $m ) ? (int)$m[0] : trim( $label ) );
			$isNew = !isset( $seen[$key] );
			if ( $isNew && count( $seen ) >= $max ) {
				$cut = true;
			}
			if ( !$cut ) {
				$seen[$key] = true;
				continue;
			}
			$seen[$key] = true;
			// The verse number plus its text, up to the next verse number
			$drop[] = $node;
			for ( $sib = $node->nextSibling; $sib && !self::isVerseNum( $sib ); $sib = $sib->nextSibling ) {
				$drop[] = $sib;
			}
		}

		foreach ( $drop as $node ) {
			$node->parentNode?->removeChild( $node );
		}
		if ( $cut ) {
			self::pruneEmpty( $xpath, $results );
		}
		return [ count( $seen ), $cut ];
	}

	private static function isVerseNum( DOMNode $n ): bool {
		return $n instanceof DOMElement && str_contains( ' ' . $n->getAttribute( 'class' ) . ' ', ' verseNum ' );
	}

	/** Remove verse paragraphs left without verses, and chapter headings left without verses. */
	private static function pruneEmpty( DOMXPath $xpath, DOMElement $results ): void {
		foreach ( iterator_to_array( $xpath->query( './/p[contains(@class,"versesParagraph")]', $results ) ) as $p ) {
			if ( $xpath->query( './/span[contains(@class,"verseNum")]', $p )->length === 0 ) {
				$p->parentNode->removeChild( $p );
			}
		}
		foreach ( iterator_to_array( $xpath->query( './/p[contains(@class,"bookChapter")]', $results ) ) as $p ) {
			$next = $p->nextSibling;
			while ( $next && !$next instanceof DOMElement ) {
				$next = $next->nextSibling;
			}
			if ( !$next || !str_contains( $next->getAttribute( 'class' ), 'versesParagraph' ) ) {
				$p->parentNode->removeChild( $p );
			}
		}
	}

	/** Rebuild $source through the allowlist and return it as HTML. */
	private static function serialise( DOMElement $source ): string {
		$clean = new DOMDocument( '1.0', 'UTF-8' );
		$root = $clean->createElement( 'div' );
		$clean->appendChild( $root );
		self::copyAttributes( $source, $root );
		self::copyChildren( $source, $root, $clean );
		return $clean->saveHTML( $root );
	}

	private static function copyChildren( DOMNode $from, DOMNode $to, DOMDocument $clean ): void {
		foreach ( $from->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$name = strtolower( $child->nodeName );
				if ( in_array( $name, self::DROP_WITH_CONTENT, true ) ) {
					continue;
				}
				if ( in_array( $name, self::ALLOWED_ELEMENTS, true ) ) {
					$el = $clean->createElement( $name );
					self::copyAttributes( $child, $el );
					$to->appendChild( $el );
					self::copyChildren( $child, $el, $clean );
				} else {
					// Disallowed element: keep its text and allowed descendants, not the tag
					self::copyChildren( $child, $to, $clean );
				}
			} elseif ( $child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE ) {
				$to->appendChild( $clean->createTextNode( $child->nodeValue ) );
			}
		}
	}

	private static function copyAttributes( DOMElement $from, DOMElement $to ): void {
		foreach ( self::ALLOWED_ATTRIBUTES as $attr ) {
			if ( $from->hasAttribute( $attr ) ) {
				$to->setAttribute( $attr, $from->getAttribute( $attr ) );
			}
		}
	}
}
