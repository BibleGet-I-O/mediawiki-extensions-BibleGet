<?php

namespace MediaWiki\Extension\BibleGet;

use MediaWiki\Extension\BibleGet\Notifications\NoticeNotifier;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Fetches a Bible quote: from the object cache, or (within the user's rate limit) from the
 * BibleGet API with a credential from CredentialProvider. The service notices of every
 * fresh (non-cached) answer go to the NoticeNotifier.
 */
class QuoteFetcher {

	/** Longest time a failure is cached, so the page retries soon. */
	public const FAILURE_TTL = 300;
	private const LAST_FAILURE_TTL = 30 * 86400;
	/** Seconds other requests skip the API after it gave no answer. */
	public const UNREACHABLE_TTL = 60;

	/** The API gave no answer in this request: later uncached fetches fail at once. */
	private bool $unreachable = false;

	public function __construct(
		private readonly BibleGetClient $client,
		private readonly CredentialProvider $credentials,
		private readonly WANObjectCache $cache,
		private readonly BagOStuff $stash,
		private readonly string $apiBase,
		private readonly int $cacheExpiry,
		private readonly int $maxVerses,
		private readonly LoggerInterface $logger,
		private readonly ?NoticeNotifier $notifier = null
	) {
	}

	/**
	 * Cache key of a quote: the API address, the version and the reference without whitespace.
	 */
	public function makeCacheKey( string $version, string $reference ): string {
		return $this->cache->makeGlobalKey(
			'bibleget-quote',
			md5( $this->apiBase ),
			$version,
			md5( preg_replace( '/\s+/u', '', $reference ) ?? $reference )
		);
	}

	public function fetch( string $version, string $reference, User $user ): QuoteResult {
		$key = $this->makeCacheKey( $version, $reference );
		$cached = $this->fromCache( $this->cache->get( $key ) );
		if ( $cached ) {
			return $cached;
		}

		if ( $this->isUnreachable() ) {
			// No call: one timeout per request (and per minute) is enough.
			return $this->failure( 'bibleget-error-network' );
		}

		if ( $user->pingLimiter( 'bibleget-fetch' ) ) {
			// The user's limit, not the quote's: not cached, and not a BibleGet failure to record.
			return new QuoteResult( null, 'bibleget-error-ratelimit', false );
		}

		[ $result, $ttl ] = $this->fetchFresh( $version, $reference );
		if ( $result->failureKey === 'bibleget-error-network' ) {
			$this->markUnreachable();
		}
		$this->cache->set( $key, $result->answer
			? [ 'answer' => $result->answer->toArray() ]
			: [ 'failure' => $result->failureKey ], $ttl );
		if ( $result->failureKey !== null ) {
			$this->recordFailure( $result->failureKey );
		}
		return $result;
	}

	/**
	 * @return array [ QuoteResult, cache TTL ]
	 */
	private function fetchFresh( string $version, string $reference ): array {
		$credential = $this->credentials->get();
		if ( $credential === null ) {
			return $this->fresh( $this->credentials->lastFailure() ?? 'bibleget-error-registration' );
		}
		$result = $this->client->quote( $version, $reference, $credential );
		if ( $result->status === 401 ) {
			$this->credentials->invalidate( $credential );
			$retry = $this->credentials->get();
			if ( $retry === null ) {
				return $this->fresh( $this->credentials->lastFailure() ?? 'bibleget-error-registration' );
			}
			if ( $retry === $credential ) {
				// The project key itself was refused: registering would not help.
				$this->logger->warning( 'BibleGet refused the project key' );
				return $this->fresh( 'bibleget-error-status' );
			}
			$result = $this->client->quote( $version, $reference, $retry );
			if ( $result->status === 401 ) {
				$this->credentials->invalidate( $retry );
				return $this->fresh( 'bibleget-error-registration' );
			}
		}

		if ( $result->status === 0 ) {
			return $this->fresh( 'bibleget-error-network' );
		}
		$ok = $result->status >= 200 && $result->status < 300;
		$answer = $ok ? AnswerParser::parse( $result->body, $this->maxVerses ) : null;
		$this->notifyNotices( $answer?->serviceNotices ?? [], $result->headers );

		if ( $result->status === 422 ) {
			return $this->fresh( 'bibleget-error-version' );
		}
		if ( $result->status === 429 ) {
			return $this->fresh( 'bibleget-error-ratelimit', $result->retryAfter );
		}
		if ( !$answer ) {
			$this->logger->warning( 'BibleGet quote answered HTTP {status}', [ 'status' => $result->status ] );
			return $this->fresh( 'bibleget-error-status' );
		}
		if ( $answer->isFailure() ) {
			return $this->fresh( 'bibleget-error-reference' );
		}
		return [ new QuoteResult( $answer, null, false ), $this->cacheExpiry ];
	}

	/**
	 * Hand the notices of a fresh answer (bundled, then from the headers) to the notifier.
	 *
	 * @param array[] $bundled
	 * @param string[] $headers
	 */
	private function notifyNotices( array $bundled, array $headers ): void {
		$notices = ServiceNotices::merge( $bundled, ServiceNotices::fromHeaders( $headers ) );
		if ( $notices !== [] && $this->notifier ) {
			$this->notifier->notify( $notices );
		}
	}

	/**
	 * @return array [ QuoteResult, cache TTL ]
	 */
	private function fresh( string $failureKey, ?int $retryAfter = null ): array {
		$ttl = $retryAfter === null ? self::FAILURE_TTL : max( 1, min( self::FAILURE_TTL, $retryAfter ) );
		return [ new QuoteResult( null, $failureKey, false ), $ttl ];
	}

	private function failure( string $failureKey ): QuoteResult {
		$this->recordFailure( $failureKey );
		return new QuoteResult( null, $failureKey, false );
	}

	/**
	 * A cache entry, or null for a miss. A bad, stale or partial entry is a miss.
	 *
	 * @param mixed $value
	 */
	private function fromCache( $value ): ?QuoteResult {
		if ( !is_array( $value ) ) {
			return null;
		}
		if ( isset( $value['failure'] ) ) {
			return is_string( $value['failure'] ) ? new QuoteResult( null, $value['failure'], true ) : null;
		}
		$data = $value['answer'] ?? null;
		if ( !is_array( $data ) || !self::isAnswerData( $data ) ) {
			return null;
		}
		try {
			return new QuoteResult( QuoteAnswer::fromArray( $data ), null, true );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	private static function isAnswerData( array $data ): bool {
		return is_string( $data['html'] ?? null )
			&& is_array( $data['errors'] ?? null )
			&& is_int( $data['verseCount'] ?? null )
			&& is_bool( $data['cut'] ?? null )
			&& is_bool( $data['cappedByApi'] ?? null )
			&& is_array( $data['notices'] ?? [] )
			&& is_array( $data['serviceNotices'] ?? [] );
	}

	private function isUnreachable(): bool {
		if ( $this->unreachable ) {
			return true;
		}
		$flag = $this->stash->get( $this->unreachableKey() );
		return is_int( $flag ) && $flag > ConvertibleTimestamp::time();
	}

	private function markUnreachable(): void {
		$this->unreachable = true;
		$this->stash->set(
			$this->unreachableKey(),
			(int)ConvertibleTimestamp::time() + self::UNREACHABLE_TTL,
			self::UNREACHABLE_TTL
		);
	}

	private function unreachableKey(): string {
		return $this->stash->makeKey( 'bibleget', 'unreachable' );
	}

	private function recordFailure( string $failureKey ): void {
		$this->stash->set(
			$this->stash->makeKey( 'bibleget', 'lastfailure' ),
			[ 'key' => $failureKey, 'time' => (int)ConvertibleTimestamp::time() ],
			self::LAST_FAILURE_TTL
		);
	}
}
