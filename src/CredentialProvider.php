<?php

namespace MediaWiki\Extension\BibleGet;

use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Gives the credential for BibleGet requests: the project key if set, else the stored
 * installation token, else a fresh registration. Never logs or exposes a key or token.
 */
class CredentialProvider {

	/** Seconds to wait after a refused registration without Retry-After (and after a revoked client key). */
	private const DEFAULT_WAIT = 3600;
	/**
	 * Seconds this object trusts what it learnt (the token it read, a rejected token, a failed
	 * registration). The service lives as long as the process, which for a job runner is hours.
	 */
	public const MEMORY_TTL = QuoteFetcher::FAILURE_TTL;

	private ?string $token = null;
	/** Until when $token is used without reading the store again */
	private int $tokenUntil = 0;
	/** Token the API rejected; not used again until $rejectedUntil */
	private ?string $rejected = null;
	private bool $reRegistered = false;
	private int $rejectedUntil = 0;
	private ?string $lastFailure = null;
	/** Failure of a registration attempt; not retried until $requestFailureUntil */
	private ?string $requestFailure = null;
	private int $requestFailureUntil = 0;
	/** Until when the tables are taken to be missing (update.php not run) */
	private int $setupFailureUntil = 0;
	private bool $loggedSiteAddress = false;

	public function __construct(
		private readonly ?string $apiKey,
		private readonly string $clientKey,
		private readonly string $siteUrl,
		private readonly InstallationStore $store,
		private readonly BibleGetClient $client,
		private readonly BagOStuff $stash,
		private readonly GlobalIdGenerator $idGenerator,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * @return string|null The credential, or null when none is available now (see lastFailure())
	 */
	public function get(): ?string {
		$this->lastFailure = null;
		if ( $this->apiKey !== null && $this->apiKey !== '' ) {
			return $this->apiKey;
		}
		$now = self::now();
		if ( $this->token !== null && $this->tokenUntil > $now ) {
			return $this->token;
		}
		$this->token = null;
		if ( $this->rejected !== null && $this->rejectedUntil <= $now ) {
			$this->rejected = null;
			$this->reRegistered = false;
		}
		if ( $this->setupFailureUntil > $now ) {
			return $this->fail( 'bibleget-error-setup' );
		}
		try {
			$row = $this->store->read();
			if ( $row && $row['token'] !== null && $row['token'] !== $this->rejected ) {
				return $this->useToken( $row['token'] );
			}
			return $this->register();
		} catch ( DBError $e ) {
			// Most likely update.php has not run. The message can hold the SQL, and so the
			// token: log the class only.
			$this->logger->warning(
				'BibleGet could not use its installation table ({class}): {failure}; run update.php',
				[ 'class' => get_class( $e ), 'failure' => 'bibleget-error-setup' ]
			);
			$this->setupFailureUntil = $now + self::MEMORY_TTL;
			return $this->fail( 'bibleget-error-setup' );
		}
	}

	/**
	 * How this wiki authenticates, without registering and without exposing a credential:
	 * 'project-key', 'registered' (an installation token is stored), 'site-address' (the wiki's address is
	 * internal, so it does not register) or 'unregistered'.
	 */
	public function mode(): string {
		if ( $this->apiKey !== null && $this->apiKey !== '' ) {
			return 'project-key';
		}
		if ( $this->token !== null ) {
			return 'registered';
		}
		$row = $this->store->read();
		if ( $row && $row['token'] !== null ) {
			return 'registered';
		}
		return SiteAddress::isPublicHttps( $this->siteUrl ) ? 'unregistered' : 'site-address';
	}

	/**
	 * The wiki's address, as registration would send it.
	 */
	public function siteUrl(): string {
		return $this->siteUrl;
	}

	/**
	 * Message key of the reason the last get() returned null.
	 */
	public function lastFailure(): ?string {
		return $this->lastFailure;
	}

	/**
	 * The API rejected this credential (401). A token is replaced by one new registration
	 * with the same install_id on the next get(); the project key is left as is.
	 */
	public function invalidate( string $credential ): void {
		if ( $credential === $this->apiKey ) {
			return;
		}
		$this->rejected = $credential;
		$this->rejectedUntil = self::now() + self::MEMORY_TTL;
		if ( $this->token === $credential ) {
			$this->token = null;
		}
		$this->logger->info( 'BibleGet rejected the installation token' );
	}

	private function register(): ?string {
		if ( !SiteAddress::isPublicHttps( $this->siteUrl ) ) {
			// Registration counts real installations: a wiki without a public https address uses a project key.
			if ( !$this->loggedSiteAddress ) {
				$this->logger->warning(
					'BibleGet: this wiki\'s address is not a public https address, so it does not register; ' .
						'set $wgBibleGetApiKey'
				);
				$this->loggedSiteAddress = true;
			}
			return $this->fail( 'bibleget-error-site-address' );
		}
		if ( $this->clientKey === '' ) {
			$this->logger->warning( 'BibleGet: no client key to register this wiki and no project key' );
			return $this->fail( 'bibleget-error-registration' );
		}
		if ( $this->rejected !== null && $this->reRegistered ) {
			// One re-registration per window: a second rejection is a failure.
			return $this->fail( 'bibleget-error-registration' );
		}
		if ( $this->requestFailure !== null && $this->requestFailureUntil > self::now() ) {
			return $this->fail( $this->requestFailure );
		}
		$wait = $this->stash->get( $this->waitKey() );
		if ( is_array( $wait ) && ( $wait['until'] ?? 0 ) > ConvertibleTimestamp::time() ) {
			return $this->fail( (string)( $wait['failure'] ?? 'bibleget-error-registration' ) );
		}

		if ( !$this->store->lock() ) {
			$this->logger->warning( 'BibleGet: timed out waiting for another registration' );
			return $this->fail( 'bibleget-error-registration' );
		}
		try {
			// Another request may have registered while this one waited for the lock.
			$row = $this->store->read();
			if ( $row && $row['token'] !== null && $row['token'] !== $this->rejected ) {
				return $this->useToken( $row['token'] );
			}
			if ( !$row ) {
				$this->store->createInstallId( $this->idGenerator->newUUIDv4() );
				$row = $this->store->read();
				if ( !$row ) {
					// The insert left no row: the table is not usable.
					return $this->failRequest( 'bibleget-error-setup' );
				}
			}
			if ( $this->rejected !== null ) {
				$this->reRegistered = true;
			}
			return $this->callRegister( $row['installId'] );
		} finally {
			$this->store->unlock();
		}
	}

	private function callRegister( string $installId ): ?string {
		$result = $this->client->register( $this->clientKey, $installId, $this->siteUrl );
		if ( $result->status === 200 || $result->status === 201 ) {
			$data = json_decode( $result->body, true );
			$token = is_array( $data ) ? ( $data['token'] ?? null ) : null;
			if ( is_string( $token ) && $token !== '' ) {
				$installationId = $data['installation_id'] ?? null;
				$this->store->saveToken( $token, is_scalar( $installationId ) ? (string)$installationId : null );
				$this->logger->info( 'BibleGet: this wiki registered as an installation' );
				return $this->useToken( $token );
			}
			$this->logger->warning( 'BibleGet registration answer had no token' );
			return $this->failRequest( 'bibleget-error-registration' );
		}
		$this->logger->warning( 'BibleGet registration refused with HTTP {status}', [ 'status' => $result->status ] );
		if ( $result->status === 0 ) {
			return $this->failRequest( 'bibleget-error-network' );
		}
		if ( $result->status === 429 ) {
			return $this->failAndWait( 'bibleget-error-registration', $result->retryAfter ?? self::DEFAULT_WAIT );
		}
		if ( $result->status === 401 ) {
			return $this->failAndWait( 'bibleget-error-clientkey-revoked', self::DEFAULT_WAIT );
		}
		return $this->failRequest( 'bibleget-error-registration' );
	}

	private function failAndWait( string $failure, int $seconds ): ?string {
		$seconds = max( 1, $seconds );
		$this->stash->set(
			$this->waitKey(),
			[ 'failure' => $failure, 'until' => self::now() + $seconds ],
			$seconds
		);
		return $this->failRequest( $failure, $seconds );
	}

	/**
	 * Remember a failed registration for $seconds (the wait, or MEMORY_TTL), so later
	 * quotes fail at once instead of registering again.
	 */
	private function failRequest( string $failure, int $seconds = self::MEMORY_TTL ): ?string {
		$this->requestFailure = $failure;
		$this->requestFailureUntil = self::now() + $seconds;
		return $this->fail( $failure );
	}

	private function useToken( string $token ): string {
		$this->token = $token;
		$this->tokenUntil = self::now() + self::MEMORY_TTL;
		return $token;
	}

	private static function now(): int {
		return (int)ConvertibleTimestamp::time();
	}

	private function fail( string $failure ): ?string {
		$this->lastFailure = $failure;
		return null;
	}

	private function waitKey(): string {
		return $this->stash->makeKey( 'bibleget', 'register-wait' );
	}
}
