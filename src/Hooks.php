<?php

namespace MediaWiki\Extension\BibleGet;

use MediaWiki\Extension\BibleGet\Notifications\ManagerLocator;
use MediaWiki\Extension\BibleGet\Notifications\NoticeNotifier;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use Wikimedia\Rdbms\DBError;

/**
 * Registers <biblequote> and {{#biblequote:}}; both render through QuoteRenderer.
 * Without Echo, shows the service notices to the wiki's managers in a banner.
 */
class Hooks implements ParserFirstCallInitHook, BeforePageDisplayHook, GetPreferencesHook {

	/** User option: the notice ids the user dismissed, separated by "|". */
	public const DISMISSED_OPTION = 'bibleget-dismissed';

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'biblequote', [ self::class, 'renderTag' ] );
		$parser->setFunctionHook( 'biblequote', [ self::class, 'renderFunction' ] );
	}

	/**
	 * <biblequote ref="…" version="…" inline="…">text shown</biblequote>
	 *
	 * @param string|null $input The body: the reference when there is no ref attribute, else the
	 *  text readers see (the inline reference and the fallback), e.g. the wiki's own abbreviation
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML
	 */
	public static function renderTag( ?string $input, array $args, Parser $parser, PPFrame $frame ): string {
		$hasRef = isset( $args['ref'] );
		return self::renderer()->render(
			$parser,
			(string)( $hasRef ? $args['ref'] : ( $input ?? '' ) ),
			$args['version'] ?? null,
			self::isInline( $args['inline'] ?? '' ),
			$hasRef ? $input : null
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

	/**
	 * The service notice banner, for managers when Echo is not installed.
	 *
	 * @param OutputPage $out
	 * @param \Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$services = MediaWikiServices::getInstance();
		/** @var NoticeNotifier $notifier */
		$notifier = $services->getService( 'BibleGet.NoticeNotifier' );
		$user = $out->getUser();
		if ( $notifier->usesEcho() || !$user->isRegistered()
			|| !$out->getAuthority()->isAllowed( ManagerLocator::RIGHT )
		) {
			return;
		}
		$dismissed = array_flip( explode( '|',
			(string)$services->getUserOptionsLookup()->getOption( $user, self::DISMISSED_OPTION, '' )
		) );
		try {
			$notices = $services->getService( 'BibleGet.NoticeStore' )->current();
		} catch ( DBError $e ) {
			// Most likely update.php has not run: the page must still render.
			LoggerFactory::getInstance( 'BibleGet' )->warning(
				'BibleGet could not read its service notices ({class}); run update.php',
				[ 'class' => get_class( $e ) ]
			);
			return;
		}
		$html = '';
		foreach ( $notices as $notice ) {
			if ( !isset( $dismissed[$notice['id']] ) ) {
				$html .= self::banner( $notice, $out );
			}
		}
		if ( $html !== '' ) {
			$out->prependHTML( $html );
			$out->addModules( 'ext.bibleGet.notices' );
		}
	}

	/** One notice: text and https link escaped, and a dismiss button. */
	private static function banner( array $notice, OutputPage $out ): string {
		$body = Html::element( 'strong', [], $out->msg( 'bibleget-notice-banner-label' )->text() )
			. ' ' . Html::element( 'span', [ 'class' => 'bibleget-service-notice-text' ],
				ServiceNotices::text( $notice, $out ) );
		$link = ServiceNotices::safeLink( $notice['link'] );
		if ( $link !== null ) {
			$body .= ' ' . Html::element( 'a', [ 'href' => $link, 'class' => 'external', 'rel' => 'nofollow' ],
				$out->msg( 'bibleget-notice-link' )->text() );
		}
		$body .= ' ' . Html::element( 'button', [
			'type' => 'button',
			'class' => 'bibleget-service-notice-dismiss',
			'data-bibleget-notice' => $notice['id'],
		], $out->msg( 'bibleget-notice-dismiss' )->text() );
		return Html::rawElement( 'div', [
			'class' => 'bibleget-service-notice',
			'data-bibleget-notice' => $notice['id'],
		], Html::warningBox( $body ) );
	}

	/**
	 * bibleget-dismissed is set through the API (by the banner's dismiss button), not on a form.
	 *
	 * @param \MediaWiki\User\User $user
	 * @param array &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences[self::DISMISSED_OPTION] = [ 'type' => 'api' ];
	}
}
