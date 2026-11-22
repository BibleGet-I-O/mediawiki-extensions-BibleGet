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
	/** Wait after an HTTP 429 without Retry-After, and the longest wait honoured */
	public const THROTTLE_DEFAULT = 60;
	public const THROTTLE_MAX = 3600;

	/**
	 * Until when this process skips the API after it gave no answer, without asking the stash.
	 * An expiry, not a flag: a job runner keeps this object for hours.
	 */
	private int $unreachableUntil = 0;

	public function __construct(
		private readonly BibleGetClient $client,
		private readonly CredentialProvider $credentials,
		private readonly WANObjectCache $cache,
		private readonly BagOStuff $stash,
		private readonly string $apiBase,
		private readonly int $cacheExpiry,
		private readonly int $maxVerses,
		private readonly LoggerInterface $logger,
		private readonly ?NoticeNotifier $notifier = null,
		private readonly bool $limitFetches = true
	) {
	}

	/**
	 * Cache key of a quote's answer, shared by the wikis of a farm: the API address, the
	 * verse limit the answer was cut to, the version and the reference without whitespace.
	 */
	public function makeCacheKey( string $version, string $reference ): string {
		return $this->cache->makeGlobalKey(
			'bibleget-quote',
			md5( $this->apiBase ),
			$this->maxVerses,
			$version,
			self::referenceHash( $reference )
		);
	}

	/**
	 * What the API last said about an edition (full name, year, copyright holder), from any answer
	 * that named it. Kept apart from the answers, so an answer cached without these details (or before
	 * they were kept) still gets them once any quote of that edition is fetched.
	 *
	 * @return array|null [ 'name' => string, 'year' => string, 'holder' => string ], or null if unknown
	 */
	public function versionInfo( string $sigla ): ?array {
		$info = $this->cache->get( $this->makeVersionKey( $sigla ) );
		return is_array( $info ) && is_string( $info['name'] ?? null ) && $info['name'] !== ''
			&& is_string( $info['year'] ?? null ) && is_string( $info['holder'] ?? null )
			? $info : null;
	}

	private function makeVersionKey( string $sigla ): string {
		return $this->cache->makeGlobalKey( 'bibleget-version', md5( $this->apiBase ), $sigla );
	}

	/**
	 * Cache key of a quote's failure. Wiki-local: a failure can depend on this wiki's credential.
	 */
	private function makeFailureKey( string $version, string $reference ): string {
		return $this->cache->makeKey(
			'bibleget-quote-failure',
			md5( $this->apiBase ),
			$version,
			self::referenceHash( $reference )
		);
	}

	private static function referenceHash( string $reference ): string {
		return md5( preg_replace( '/\s+/u', '', $reference ) ?? $reference );
	}

	public function fetch( string $version, string $reference, User $user ): QuoteResult {
		$key = $this->makeCacheKey( $version, $reference );
		$failureKey = $this->makeFailureKey( $version, $reference );
		$entries = $this->cache->getMulti( [ $key, $failureKey ] );
		$cached = $this->fromCache( $entries[$key] ?? false, 'answer' )
			?? $this->fromCache( $entries[$failureKey] ?? false, 'failure' );
		if ( $cached ) {
			return $cached;
		}

		if ( $this->isUnreachable() ) {
			// No call: one timeout per request (and per minute) is enough.
			return $this->failure( 'bibleget-error-network' );
		}
		if ( $this->isThrottled() ) {
			// BibleGet asked this wiki to wait (HTTP 429): no quote is fetched until Retry-After has passed.
			return new QuoteResult( null, 'bibleget-error-ratelimit', false );
		}

		// Not for maintenance scripts: a job runner's reparses run as one user for hours.
		if ( $this->limitFetches && $user->pingLimiter( 'bibleget-fetch' ) ) {
			// The user's limit, not the quote's: not cached, and not a BibleGet failure to record.
			return new QuoteResult( null, 'bibleget-error-ratelimit', false, true );
		}

		[ $result, $ttl ] = $this->fetchFresh( $version, $reference );
		if ( $result->failureKey === 'bibleget-error-network' ) {
			$this->markUnreachable();
		}
		if ( $result->answer ) {
			$this->cache->set( $key, [ 'answer' => $result->answer->toArray() ], $ttl );
			foreach ( $result->answer->versions as $sigla => $info ) {
				$this->cache->set( $this->makeVersionKey( (string)$sigla ), $info, $this->cacheExpiry );
			}
		} else {
			$this->cache->set( $failureKey, [ 'failure' => $result->failureKey ], $ttl );
		}
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
			// A refused credential may come with a Sunset or Deprecation notice: managers must still see it.
			$this->notifyNotices( [], $result->headers );
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
				$this->notifyNotices( [], $result->headers );
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
			$this->markThrottled( $result->retryAfter );
			return $this->fresh( 'bibleget-error-ratelimit', $result->retryAfter );
		}
		if ( !$answer ) {
			$this->logger->warning( 'BibleGet quote answered HTTP {status}', [ 'status' => $result->status ] );
			return $this->fresh( 'bibleget-error-status' );
		}
		if ( $answer->isFailure() ) {
			return $this->fresh( 'bibleget-error-reference' );
		}
		return [ new QuoteResult( $answer, null, false ), $this->answerTtl() ];
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
	 * @param string $kind 'answer' or 'failure': what the key holds
	 */
	private function fromCache( $value, string $kind ): ?QuoteResult {
		if ( !is_array( $value ) ) {
			return null;
		}
		if ( $kind === 'failure' ) {
			return is_string( $value['failure'] ?? null ) ? new QuoteResult( null, $value['failure'], true ) : null;
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
			&& is_array( $data['serviceNotices'] ?? [] )
			&& is_array( $data['versions'] ?? [] );
	}

	private function isUnreachable(): bool {
		$now = (int)ConvertibleTimestamp::time();
		if ( $this->unreachableUntil > $now ) {
			return true;
		}
		$flag = $this->stash->get( $this->unreachableKey() );
		return is_int( $flag ) && $flag > $now;
	}

	private function markUnreachable(): void {
		$this->unreachableUntil = (int)ConvertibleTimestamp::time() + self::UNREACHABLE_TTL;
		$this->stash->set( $this->unreachableKey(), $this->unreachableUntil, self::UNREACHABLE_TTL );
	}

	/**
	 * The configured expiry, give or take 15% at random: quotes fetched together (after a template edit,
	 * or a new install) then expire over days, not all at once against BibleGet's hourly limit.
	 */
	private function answerTtl(): int {
		return (int)round( $this->cacheExpiry * mt_rand( 85, 115 ) / 100 );
	}

	private function isThrottled(): bool {
		$until = $this->stash->get( $this->throttleKey() );
		return is_int( $until ) && $until > (int)ConvertibleTimestamp::time();
	}

	/**
	 * Every request on this wiki waits as long as BibleGet asked: its limit is per installation, so
	 * any further call before then would only be refused (and count against the limit) too.
	 */
	private function markThrottled( ?int $retryAfter ): void {
		$wait = max( 1, min( self::THROTTLE_MAX, $retryAfter ?? self::THROTTLE_DEFAULT ) );
		$this->stash->set( $this->throttleKey(), (int)ConvertibleTimestamp::time() + $wait, $wait );
	}

	private function throttleKey(): string {
		return $this->stash->makeKey( 'bibleget', 'throttled' );
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
