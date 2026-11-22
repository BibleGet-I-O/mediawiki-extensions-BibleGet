<?php

namespace MediaWiki\Extension\BibleGet\Tests;

use MediaWiki\Extension\BibleGet\AnswerParser;
use MediaWiki\Extension\BibleGet\QuoteAnswer;
use MediaWiki\Extension\BibleGet\QuoteFetcher;
use MediaWiki\Extension\BibleGet\QuoteResult;
use MediaWiki\User\User;

/**
 * A QuoteFetcher that answers from the captured fixtures and records its calls.
 * Never calls the BibleGet API.
 */
class FakeQuoteFetcher extends QuoteFetcher {

	/** Fixture per version and reference without whitespace. */
	private const FIXTURES = [
		'NABRE' => [
			'Jn3,16' => 'good-nabre-jn3-16.html',
			'Mt5' => 'capped-nabre-mt5.html',
			// The same answer with the endpoint's VERSES_CAPPED notice.
			'Mt5,1-48' => 'capped-notice-nabre-mt5.html',
			'Ps23' => 'poetic-ps23.html',
			'Xyz99,1' => 'bad-reference.html',
		],
		'DRB' => [
			'Mt5' => 'full-drb-mt5.html',
			'Mt5,48-6,2' => 'multichapter-drb-mt5-48-6-2.html',
		],
	];

	/** @var array[] Recorded calls: [ version, reference ] */
	public array $calls = [];

	/** @var array[] What versionInfo() returns, by sigla */
	public array $versionInfo = [];

	/** @var string[] Editions noteQuoted() was told of, in order */
	public array $noted = [];

	/** Answer without the version details, like an answer cached before they were kept */
	public bool $dropVersions = false;

	public function __construct( private readonly int $maxVersesLimit = 30 ) {
		// No parent constructor: nothing here reaches the client, cache or API.
	}

	public function makeCacheKey( string $version, string $reference ): string {
		return 'fake:' . $version . ':' . preg_replace( '/\s+/u', '', $reference );
	}

	public function fetch( string $version, string $reference, User $user ): QuoteResult {
		$this->calls[] = [ $version, $reference ];
		if ( $version === 'LIMITED' ) {
			// The wiki's own rate limit refused this user's fetch.
			return new QuoteResult( null, 'bibleget-error-ratelimit', false, true );
		}
		if ( $version === 'ZZZZ' ) {
			return new QuoteResult( null, 'bibleget-error-version', false );
		}
		$file = self::FIXTURES[$version][preg_replace( '/\s+/u', '', $reference )] ?? null;
		if ( $file === null ) {
			return new QuoteResult( null, 'bibleget-error-network', false );
		}
		$answer = AnswerParser::parse(
			file_get_contents( __DIR__ . '/fixtures/answers/' . $file ),
			$this->maxVersesLimit
		);
		if ( $answer->isFailure() ) {
			return new QuoteResult( null, 'bibleget-error-reference', false );
		}
		if ( $this->dropVersions ) {
			$answer = new QuoteAnswer( $answer->html, $answer->errors, $answer->verseCount, $answer->cut,
				$answer->cappedByApi, $answer->notices, $answer->serviceNotices );
		}
		return new QuoteResult( $answer, null, false );
	}

	public function versionInfo( string $sigla ): ?array {
		return $this->versionInfo[$sigla] ?? null;
	}

	public function noteQuoted( string $sigla ): void {
		$this->noted[] = $sigla;
	}

	public function knownVersions(): array {
		$known = $this->versionInfo;
		ksort( $known );
		return $known;
	}
}
