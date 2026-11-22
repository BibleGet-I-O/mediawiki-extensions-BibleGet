<?php

namespace MediaWiki\Extension\BibleGet\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\BibleGet\CredentialProvider;
use MediaWiki\Extension\BibleGet\NoticeStore;
use MediaWiki\Extension\BibleGet\QuoteFetcher;
use MediaWiki\Extension\BibleGet\ServiceNotices;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\DBError;

/**
 * Where the wiki's Bible quotes come from, for every reader: each quote's source line links here
 * by default (bibleget-source-page). BibleGet managers also see the status: how the wiki
 * authenticates, the limits, the last API failure and the service notices. It never shows the
 * project key or the token.
 */
class SpecialBibleGet extends SpecialPage {

	public function __construct(
		private readonly CredentialProvider $credentials,
		private readonly NoticeStore $notices,
		private readonly BagOStuff $stash,
		private readonly Config $config,
		private readonly QuoteFetcher $fetcher
	) {
		parent::__construct( 'BibleGet' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->addHTML( $this->sourceSection() );
		if ( !$this->getAuthority()->isAllowed( 'bibleget-manage' ) ) {
			return;
		}
		$out->addHTML( Html::element( 'p', [], $this->msg( 'bibleget-special-intro' )->text() ) );

		$missingTables = false;
		$out->addHTML( $this->modeSection( $missingTables ) );
		$out->addHTML( $this->limitsSection() );
		$out->addHTML( $this->failureSection() );
		$out->addHTML( $this->noticesSection( $missingTables ) );
		if ( $missingTables ) {
			$out->addHTML( Html::warningBox( $this->msg( 'bibleget-special-notices-missing-table' )->parse() ) );
		}
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}

	/**
	 * Where the quotes come from (BibleGet, its foundation, the copyright agreements), and the
	 * editions this wiki has quoted with their copyright holders, as BibleGet named them.
	 */
	private function sourceSection(): string {
		$copyrightUrl = $this->msg( 'bibleget-copyright-url' )->inContentLanguage()->text();
		$html = Html::element( 'h2', [], $this->msg( 'bibleget-special-source-heading' )->text() )
			. $this->msg( 'bibleget-special-source-intro' )->parseAsBlock()
			. $this->msg( 'bibleget-special-source-copyright' )->params( $copyrightUrl )->parseAsBlock()
			. Html::element( 'h3', [], $this->msg( 'bibleget-special-editions-heading' )->text() );
		$editions = $this->fetcher->knownVersions();
		if ( $editions === [] ) {
			return $html . Html::element( 'p', [], $this->msg( 'bibleget-special-editions-none' )->text() );
		}
		$head = '';
		foreach ( [ 'edition', 'abbr', 'year', 'holder' ] as $column ) {
			// Messages: bibleget-special-editions-edition, -abbr, -year, -holder
			$head .= Html::element( 'th', [], $this->msg( 'bibleget-special-editions-' . $column )->text() );
		}
		$rows = Html::rawElement( 'tr', [], $head );
		foreach ( $editions as $sigla => $info ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $info['name'] )
				. Html::element( 'td', [], (string)$sigla )
				. Html::element( 'td', [], $info['year'] )
				. Html::element( 'td', [], $info['holder'] )
			);
		}
		return $html . Html::rawElement( 'table', [ 'class' => 'wikitable' ], $rows );
	}

	private function modeSection( bool &$missingTables ): string {
		try {
			$mode = $this->credentials->mode();
		} catch ( DBError ) {
			$missingTables = true;
			return '';
		}
		return Html::element( 'h2', [], $this->msg( 'bibleget-special-mode-heading' )->text() )
			// Messages: bibleget-special-mode-project-key, -registered, -site-address, -unregistered
			. Html::element( 'p', [], $this->msg( 'bibleget-special-mode-' . $mode )
				->plaintextParams( $this->credentials->siteUrl() )->text() );
	}

	private function limitsSection(): string {
		$html = Html::element( 'h2', [], $this->msg( 'bibleget-special-limits-heading' )->text() );
		$html .= Html::rawElement( 'ul', [],
			Html::element( 'li', [], $this->msg( 'bibleget-special-limit-verses' )
				->numParams( (int)$this->config->get( 'BibleGetMaxVersesPerQuote' ) )->text() )
			. Html::element( 'li', [], $this->msg( 'bibleget-special-limit-quotes' )
				->numParams( (int)$this->config->get( 'BibleGetMaxQuotesPerPage' ) )->text() )
		);
		$html .= Html::element( 'h3', [], $this->msg( 'bibleget-special-ratelimit-heading' )->text() );
		$items = '';
		$limits = $this->config->get( 'RateLimits' )['bibleget-fetch'] ?? [];
		foreach ( $limits as $group => $limit ) {
			if ( !is_array( $limit ) || count( $limit ) < 2 ) {
				continue;
			}
			$items .= Html::element( 'li', [], $this->msg( 'bibleget-special-ratelimit-line' )
				->plaintextParams( (string)$group )
				->numParams( (int)$limit[0], (int)$limit[1] )->text() );
		}
		return $html . ( $items !== ''
			? Html::rawElement( 'ul', [], $items )
			: Html::element( 'p', [], $this->msg( 'bibleget-special-ratelimit-none' )->text() ) );
	}

	private function failureSection(): string {
		$html = Html::element( 'h2', [], $this->msg( 'bibleget-special-failure-heading' )->text() );
		$failure = $this->stash->get( $this->stash->makeKey( 'bibleget', 'lastfailure' ) );
		if ( !is_array( $failure ) || !is_string( $failure['key'] ?? null ) ) {
			return $html . Html::element( 'p', [], $this->msg( 'bibleget-special-failure-none' )->text() );
		}
		$key = $failure['key'];
		$message = str_starts_with( $key, 'bibleget-error-' ) && $this->msg( $key )->exists()
			? $this->msg( $key )->text()
			: $key;
		return $html . Html::element( 'p', [], $this->msg( 'bibleget-special-failure-line' )
			->plaintextParams( $message, $this->formatDate( (int)( $failure['time'] ?? 0 ) ) )->text() );
	}

	private function noticesSection( bool &$missingTables ): string {
		$html = Html::element( 'h2', [], $this->msg( 'bibleget-special-notices-heading' )->text() );
		try {
			$notices = $this->notices->current();
		} catch ( DBError ) {
			$missingTables = true;
			return $html;
		}
		if ( !$notices ) {
			return $html . Html::element( 'p', [], $this->msg( 'bibleget-special-notices-none' )->text() );
		}
		$items = '';
		foreach ( $notices as $notice ) {
			$item = Html::element( 'span', [], $this->msg( 'bibleget-special-notice-line' )
				->plaintextParams(
					ServiceNotices::text( $notice, $this ),
					$this->formatDate( (int)wfTimestamp( TS_UNIX, $notice['firstSeen'] ) )
				)->text() );
			$link = ServiceNotices::safeLink( $notice['link'] );
			if ( $link !== null ) {
				$item .= ' ' . Html::element( 'a', [ 'href' => $link, 'class' => 'external', 'rel' => 'nofollow' ],
					$this->msg( 'bibleget-special-notice-link' )->text() );
			}
			$items .= Html::rawElement( 'li', [], $item );
		}
		return $html . Html::rawElement( 'ul', [], $items );
	}

	private function formatDate( int $unixTime ): string {
		return $this->getLanguage()->userTimeAndDate( wfTimestamp( TS_MW, $unixTime ), $this->getUser() );
	}
}
