<?php

namespace MediaWiki\Extension\BibleGet;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

/**
 * Registers <biblequote> and {{#biblequote:}}; both render through QuoteRenderer.
 */
class Hooks implements ParserFirstCallInitHook {

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'biblequote', [ self::class, 'renderTag' ] );
		$parser->setFunctionHook( 'biblequote', [ self::class, 'renderFunction' ] );
	}

	/**
	 * <biblequote ref="…" version="…" inline="…">reference</biblequote>
	 *
	 * @param string|null $input The body: the reference when there is no ref attribute
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML
	 */
	public static function renderTag( ?string $input, array $args, Parser $parser, PPFrame $frame ): string {
		return self::renderer()->render(
			$parser,
			(string)( $args['ref'] ?? $input ?? '' ),
			$args['version'] ?? null,
			self::isInline( $args['inline'] ?? '' )
		);
	}

	/**
	 * {{#biblequote:reference|display|version}}
	 *
	 * @param Parser $parser
	 * @param string $reference
	 * @param string $display inline/true or block/false
	 * @param string $version
	 * @return array
	 */
	public static function renderFunction(
		Parser $parser, string $reference = '', string $display = '', string $version = ''
	): array {
		$html = self::renderer()->render( $parser, $reference, $version, self::isInline( $display ) );
		return [ $html, 'isHTML' => true ];
	}

	/**
	 * The display argument: what 1.0 accepted for inline (a boolean), or inline/block.
	 */
	private static function isInline( string $display ): bool {
		$display = strtolower( trim( $display ) );
		return $display === 'inline' || filter_var( $display, FILTER_VALIDATE_BOOLEAN );
	}

	private static function renderer(): QuoteRenderer {
		// Looked up per call (not injected), so a redefined fetcher is always the one used.
		return MediaWikiServices::getInstance()->getService( 'BibleGet.QuoteRenderer' );
	}
}
