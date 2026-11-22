<?php

namespace MediaWiki\Extension\BibleGet;

use DOMDocument;
use DOMElement;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\User\UserFactory;
use WeakMap;

/**
 * Turns a reference into page HTML: the quote as a block or an inline popup, or the
 * fallback (the escaped display text, by default the reference, and a [!] marker) when the quote cannot be had.
 */
class QuoteRenderer {

	/** ParserOutput extension data: set of the cache keys this page has quoted. */
	public const FETCHED_KEY = 'bibleget-fetched';
	/** Parser cache expiry of a page with a failed quote, so it retries soon. */
	public const FAILURE_EXPIRY = 3600;

	/** @var WeakMap<ParserOutput,QuoteResult[]> Results per parse, by cache key */
	private WeakMap $results;

	public function __construct(
		private readonly QuoteFetcher $fetcher,
		private readonly UserFactory $userFactory,
		private readonly string $defaultVersion,
		private readonly int $maxQuotesPerPage,
		private readonly int $maxVersesPerQuote
	) {
		$this->results = new WeakMap();
	}

	/**
	 * @param Parser $parser
	 * @param string $reference As the editor wrote it; never parsed
	 * @param string|null $version Null or empty for the default version
	 * @param bool $inline
	 * @param string|null $display Text readers see (inline reference, fallback); null or empty for the reference
	 * @return string HTML
	 */
	public function render(
		Parser $parser, string $reference, ?string $version, bool $inline, ?string $display = null
	): string {
		$reference = trim( $parser->killMarkers( $reference ) );
		if ( $reference === '' ) {
			return Html::element( 'strong', [ 'class' => 'error' ],
				$parser->msg( 'bibleget-error-noref' )->text() );
		}
		// The text readers see: the given display text, else the reference itself.
		$display = trim( $parser->killMarkers( (string)$display ) );
		if ( $display === '' ) {
			$display = $reference;
		}
		$version = trim( (string)$version );
		if ( $version === '' ) {
			$version = $this->defaultVersion;
		}

		$output = $parser->getOutput();
		$output->addModuleStyles( [ 'ext.bibleGet.styles' ] );

		$result = $this->fetch( $parser, $output, $version, $reference );
		if ( $result->answer === null ) {
			return $this->renderFailure(
				$parser, $display, $result->failureKey ?? 'bibleget-error-status', $result->userLimited
			);
		}

		$answer = $result->answer;
		$html = $answer->html;
		if ( $answer->cut || $answer->cappedByApi ) {
			// Cut here (more verses than the limit) or by the API (its VERSES_CAPPED notice):
			// either way the quote is incomplete, so it gets the note and the category.
			$parser->addTrackingCategory( 'bibleget-toolong-category' );
			$shown = $answer->cut ? $this->maxVersesPerQuote : $answer->verseCount;
			$html .= Html::element( 'div', [ 'class' => 'bibleget-note' ],
				$parser->msg( 'bibleget-note-cut' )->numParams( $shown )->text() );
		}
		if ( !$inline ) {
			return Html::rawElement( 'div', [ 'class' => 'bibleget-quote' ], $html );
		}
		// The popup script (and its styles) only serves inline quotes.
		$output->addModules( [ 'ext.bibleGet' ] );
		return Html::rawElement( 'span', [ 'class' => 'bibleget-inline' ],
			Html::element( 'span', [ 'class' => 'bibleget-ref', 'tabindex' => '0' ], $display )
			. Html::rawElement( 'span', [ 'class' => 'bibleget-popup' ], self::toPhrasing( $html ) )
		);
	}

	/**
	 * The quote for this parse: each reference is fetched once, and at most
	 * $maxQuotesPerPage distinct references are fetched.
	 */
	private function fetch( Parser $parser, ParserOutput $output, string $version, string $reference ): QuoteResult {
		$key = $this->fetcher->makeCacheKey( $version, $reference );
		$results = $this->results[$output] ?? [];
		if ( isset( $results[$key] ) ) {
			return $results[$key];
		}
		$fetched = $output->getExtensionData( self::FETCHED_KEY ) ?? [];
		if ( !isset( $fetched[$key] ) && count( $fetched ) >= $this->maxQuotesPerPage ) {
			return new QuoteResult( null, 'bibleget-error-toomany', false );
		}
		$output->appendExtensionData( self::FETCHED_KEY, $key );
		$result = $this->fetcher->fetch(
			$version,
			$reference,
			$this->userFactory->newFromUserIdentity( $parser->getUserIdentity() )
		);
		$results[$key] = $result;
		$this->results[$output] = $results;
		return $result;
	}

	private function renderFailure(
		Parser $parser, string $display, string $failureKey, bool $userLimited = false
	): string {
		$parser->addTrackingCategory( 'bibleget-tracking-category' );
		// One user over the wiki's rate limit must not leave the fallback in the shared parser cache
		// for other readers: that page output is not cached at all.
		$parser->getOutput()->updateCacheExpiry( $userLimited ? 0 : self::FAILURE_EXPIRY );
		$reason = $parser->msg( $failureKey )->text();
		return Html::element( 'span', [ 'class' => 'bibleget-failed' ], $display )
			. Html::element( 'sup', [ 'class' => 'bibleget-failed-marker', 'title' => $reason ], '[!]' );
	}

	/**
	 * The quote as phrasing content, so it can sit in a span inside a paragraph:
	 * div and p become span with the class bibleget-block.
	 *
	 * @param string $html Sanitised answer HTML
	 * @return string
	 */
	private static function toPhrasing( string $html ): string {
		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="UTF-8"><body>' . $html . '</body>',
			LIBXML_NONET | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( !$body instanceof DOMElement ) {
			return '';
		}
		$blocks = [];
		foreach ( [ 'div', 'p' ] as $tag ) {
			foreach ( $body->getElementsByTagName( $tag ) as $element ) {
				$blocks[] = $element;
			}
		}
		foreach ( $blocks as $element ) {
			/** @var DOMElement $element */
			$span = $doc->createElement( 'span' );
			foreach ( $element->attributes as $attribute ) {
				$span->setAttribute( $attribute->name, $attribute->value );
			}
			$span->setAttribute( 'class', trim( $element->getAttribute( 'class' ) . ' bibleget-block' ) );
			while ( $element->firstChild ) {
				$span->appendChild( $element->firstChild );
			}
			$element->parentNode->replaceChild( $span, $element );
		}

		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		return $out;
	}
}
