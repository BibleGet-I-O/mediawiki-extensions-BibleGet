<?php

namespace MediaWiki\Extension\BibleGet;

use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Gives the credential for BibleGet requests: the project key if set, else the stored
 * installation token, else a fresh registration. Never logs or exposes a key or token.
 */
class CredentialProvider {

	/** Seconds to wait after a refused registration without Retry-After (and after a revoked client key). */
	private const DEFAULT_WAIT = 3600;

	private ?string $token = null;
	/** Token the API rejected; not used again */
	private ?string $rejected = null;
	private bool $reRegistered = false;
	private ?string $lastFailure = null;
	/** Failure of a registration attempt in this request; not retried in the same request */
	private ?string $requestFailure = null;

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
		if ( $this->token !== null ) {
			return $this->token;
		}
		$row = $this->store->read();
		if ( $row && $row['token'] !== null && $row['token'] !== $this->rejected ) {
			$this->token = $row['token'];
			return $this->token;
		}
		return $this->register();
	}

	/**
	 * How this wiki authenticates, without registering and without exposing a credential:
	 * 'project-key', 'registered' (an installation token is stored) or 'unregistered'.
	 */
	public function mode(): string {
		if ( $this->apiKey !== null && $this->apiKey !== '' ) {
			return 'project-key';
		}
		if ( $this->token !== null ) {
			return 'registered';
		}
		$row = $this->store->read();
		return $row && $row['token'] !== null ? 'registered' : 'unregistered';
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
		if ( $this->token === $credential ) {
			$this->token = null;
		}
		$this->logger->info( 'BibleGet rejected the installation token' );
	}

	private function register(): ?string {
		if ( $this->clientKey === '' ) {
			$this->logger->warning( 'BibleGet: no client key to register this wiki and no project key' );
			return $this->fail( 'bibleget-error-registration' );
		}
		if ( $this->rejected !== null && $this->reRegistered ) {
			// One re-registration per request: a second rejection is a failure.
			return $this->fail( 'bibleget-error-registration' );
		}
		if ( $this->requestFailure !== null ) {
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
				$this->token = $row['token'];
				return $this->token;
			}
			if ( !$row ) {
				$this->store->createInstallId( $this->idGenerator->newUUIDv4() );
				$row = $this->store->read();
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
				$this->token = $token;
				$this->logger->info( 'BibleGet: this wiki registered as an installation' );
				return $token;
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
			[ 'failure' => $failure, 'until' => (int)ConvertibleTimestamp::time() + $seconds ],
			$seconds
		);
		return $this->failRequest( $failure );
	}

	private function failRequest( string $failure ): ?string {
		$this->requestFailure = $failure;
		return $this->fail( $failure );
	}

	private function fail( string $failure ): ?string {
		$this->lastFailure = $failure;
		return null;
	}

	private function waitKey(): string {
		return $this->stash->makeKey( 'bibleget', 'register-wait' );
	}
}
