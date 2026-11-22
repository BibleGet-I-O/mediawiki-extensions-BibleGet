<?php

namespace MediaWiki\Extension\BibleGet\Tests\Integration;

use HashBagOStuff;
use MediaWiki\Extension\BibleGet\ApiResult;
use MediaWiki\Extension\BibleGet\BibleGetClient;
use MediaWiki\Extension\BibleGet\CredentialProvider;
use MediaWiki\Extension\BibleGet\InstallationStore;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\BibleGet\CredentialProvider
 * @covers \MediaWiki\Extension\BibleGet\InstallationStore
 */
class CredentialProviderTest extends MediaWikiIntegrationTestCase {

	private const CLIENT_KEY = 'bgclient_test_CLIENTKEY';
	private const SITE = 'https://wiki.example.test';

	/** @var array[] Recorded register calls: [ clientKey, installId, siteUrl ] */
	private array $registers = [];
	/** @var ApiResult[] Answers to give to register, in order (the last one repeats) */
	private array $registerAnswers = [];
	private BagOStuff $stash;
	/** @var string[] Everything logged, messages with their context */
	private array $logs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->stash = new HashBagOStuff();
		$this->registers = [];
		$this->logs = [];
		$this->registerAnswers = [ self::registered( 'bginst_FIRST' ) ];
		ConvertibleTimestamp::setFakeTime( '20261122000000' );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( null );
		parent::tearDown();
	}

	private static function registered( string $token ): ApiResult {
		return new ApiResult( 201, json_encode( [ 'token' => $token, 'installation_id' => 'inst-42' ] ) );
	}

	private function client(): BibleGetClient {
		$test = $this;
		return new class( $this->createMock( HttpRequestFactory::class ), $test ) extends BibleGetClient {
			private CredentialProviderTest $test;

			public function __construct( HttpRequestFactory $http, CredentialProviderTest $test ) {
				parent::__construct( $http, 'https://api.example.test/v4/', '2.0.0', new NullLogger() );
				$this->test = $test;
			}

			public function quote( string $version, string $reference, string $credential ): ApiResult {
				throw new \LogicException( 'quote() is not expected here' );
			}

			public function register( string $clientKey, string $installId, string $siteUrl ): ApiResult {
				return $this->test->onRegister( $clientKey, $installId, $siteUrl );
			}
		};
	}

	/** @internal for the fake client */
	public function onRegister( string $clientKey, string $installId, string $siteUrl ): ApiResult {
		$this->registers[] = [ $clientKey, $installId, $siteUrl ];
		return count( $this->registerAnswers ) > 1
			? array_shift( $this->registerAnswers )
			: $this->registerAnswers[0];
	}

	private function logger(): AbstractLogger {
		$logs = &$this->logs;
		return new class( $logs ) extends AbstractLogger {
			private array $logs;

			public function __construct( array &$logs ) {
				$this->logs = &$logs;
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->logs[] = $message . ' ' . json_encode( $context );
			}
		};
	}

	private function store(): InstallationStore {
		return new InstallationStore( $this->getServiceContainer()->getDBLoadBalancer() );
	}

	private function provider(
		?string $apiKey = null,
		string $clientKey = self::CLIENT_KEY,
		?InstallationStore $store = null
	): CredentialProvider {
		return new CredentialProvider(
			$apiKey,
			$clientKey,
			self::SITE,
			$store ?? $this->store(),
			$this->client(),
			$this->stash,
			$this->getServiceContainer()->getGlobalIdGenerator(),
			$this->logger()
		);
	}

	private function rowCount(): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'bibleget_installation' )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function testProjectKeyNeverRegisters() {
		$provider = $this->provider( 'bibleget_live_PROJECTKEY' );
		$this->assertSame( 'bibleget_live_PROJECTKEY', $provider->get() );
		$this->assertNull( $provider->lastFailure() );
		$provider->invalidate( 'bibleget_live_PROJECTKEY' );
		$this->assertSame( 'bibleget_live_PROJECTKEY', $provider->get() );
		$this->assertSame( [], $this->registers );
		$this->assertSame( 0, $this->rowCount() );
	}

	public function testFirstUseRegisters() {
		$provider = $this->provider();
		$this->assertSame( 'bginst_FIRST', $provider->get() );
		$this->assertCount( 1, $this->registers );
		[ $clientKey, $installId, $siteUrl ] = $this->registers[0];
		$this->assertSame( self::CLIENT_KEY, $clientKey );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $installId
		);
		$this->assertSame( self::SITE, $siteUrl );

		$row = $this->store()->read();
		$this->assertSame( $installId, $row['installId'] );
		$this->assertSame( 'bginst_FIRST', $row['token'] );
		$this->assertSame( 'inst-42', $row['installationId'] );

		$this->assertSame( 'bginst_FIRST', $provider->get() );
		$this->assertSame( 'bginst_FIRST', $this->provider()->get() );
		$this->assertCount( 1, $this->registers );
	}

	public function testInvalidateReRegistersWithSameInstallId() {
		$this->registerAnswers = [ self::registered( 'bginst_FIRST' ), self::registered( 'bginst_SECOND' ) ];
		$provider = $this->provider();
		$this->assertSame( 'bginst_FIRST', $provider->get() );
		$provider->invalidate( 'bginst_FIRST' );
		$this->assertSame( 'bginst_SECOND', $provider->get() );
		$this->assertCount( 2, $this->registers );
		$this->assertSame( $this->registers[0][1], $this->registers[1][1] );
		$this->assertSame( 'bginst_SECOND', $this->store()->read()['token'] );
		$this->assertSame( 1, $this->rowCount() );

		// Only one re-registration per provider: a second rejection is a failure.
		$provider->invalidate( 'bginst_SECOND' );
		$this->assertNull( $provider->get() );
		$this->assertSame( 'bibleget-error-registration', $provider->lastFailure() );
		$this->assertCount( 2, $this->registers );
	}

	public function testRegistration429Waits() {
		$this->registerAnswers = [ new ApiResult( 429, '', 600 ) ];
		$provider = $this->provider();
		$this->assertNull( $provider->get() );
		$this->assertSame( 'bibleget-error-registration', $provider->lastFailure() );
		$this->assertCount( 1, $this->registers );

		// The wait lives in the object stash, so a new provider (another request) waits too.
		ConvertibleTimestamp::setFakeTime( '20261122000940' );
		$other = $this->provider();
		$this->assertNull( $other->get() );
		$this->assertSame( 'bibleget-error-registration', $other->lastFailure() );
		$this->assertCount( 1, $this->registers );

		ConvertibleTimestamp::setFakeTime( '20261122001001' );
		$this->registerAnswers = [ self::registered( 'bginst_LATER' ) ];
		$this->assertSame( 'bginst_LATER', $this->provider()->get() );
		$this->assertCount( 2, $this->registers );
	}

	public function testRevokedClientKeyWaitsAnHour() {
		$this->registerAnswers = [ new ApiResult( 401, '{"title":"Unauthorized"}' ) ];
		$provider = $this->provider();
		$this->assertNull( $provider->get() );
		$this->assertSame( 'bibleget-error-clientkey-revoked', $provider->lastFailure() );

		ConvertibleTimestamp::setFakeTime( '20261122005950' );
		$other = $this->provider();
		$this->assertNull( $other->get() );
		$this->assertSame( 'bibleget-error-clientkey-revoked', $other->lastFailure() );
		$this->assertCount( 1, $this->registers );

		ConvertibleTimestamp::setFakeTime( '20261122010001' );
		$this->assertNull( $this->provider()->get() );
		$this->assertCount( 2, $this->registers );
	}

	public function testEmptyClientKeyDoesNotRegister() {
		$provider = $this->provider( null, '' );
		$this->assertNull( $provider->get() );
		$this->assertSame( 'bibleget-error-registration', $provider->lastFailure() );
		$this->assertSame( [], $this->registers );
	}

	public function testRegistrationWithoutTokenIsFailure() {
		$this->registerAnswers = [ new ApiResult( 201, 'not json' ) ];
		$provider = $this->provider();
		$this->assertNull( $provider->get() );
		$this->assertSame( 'bibleget-error-registration', $provider->lastFailure() );
	}

	public function testRegistrationNetworkFailure() {
		$this->registerAnswers = [ new ApiResult( 0 ) ];
		$provider = $this->provider();
		$this->assertNull( $provider->get() );
		$this->assertSame( 'bibleget-error-network', $provider->lastFailure() );
		// Not retried within the same request.
		$this->assertNull( $provider->get() );
		$this->assertCount( 1, $this->registers );
	}

	public function testConcurrentRegistrationOnce() {
		$first = $this->provider();
		$lockNames = [];
		// The second provider's store: while it waits for the lock, the first provider
		// (another parse holding the lock) registers and stores its token.
		$waitingStore = new class(
			$this->getServiceContainer()->getDBLoadBalancer(), $first, $lockNames
		) extends InstallationStore {
			private CredentialProvider $first;
			private array $lockNames;

			public function __construct( $dbProvider, CredentialProvider $first, array &$lockNames ) {
				parent::__construct( $dbProvider );
				$this->first = $first;
				$this->lockNames = &$lockNames;
			}

			public function lock(): bool {
				$this->lockNames[] = InstallationStore::LOCK_NAME;
				$this->first->get();
				return parent::lock();
			}
		};
		$second = $this->provider( null, self::CLIENT_KEY, $waitingStore );

		$this->assertSame( 'bginst_FIRST', $second->get() );
		$this->assertSame( [ 'bibleget-register' ], $lockNames );
		$this->assertCount( 1, $this->registers );
		$this->assertSame( 1, $this->rowCount() );
	}

	public function testNoSecretInLogs() {
		$this->registerAnswers = [
			new ApiResult( 500, 'bginst_LEAK' ),
		];
		$failing = $this->provider();
		$this->assertNull( $failing->get() );
		$this->assertSame( 'bibleget-error-registration', $failing->lastFailure() );
		$this->registerAnswers = [ self::registered( 'bginst_LEAK' ) ];
		$provider = $this->provider();
		$this->assertSame( 'bginst_LEAK', $provider->get() );
		$provider->invalidate( 'bginst_LEAK' );
		$this->assertNotEmpty( $this->logs );
		foreach ( $this->logs as $line ) {
			$this->assertStringNotContainsString( 'LEAK', $line );
			$this->assertStringNotContainsString( 'CLIENTKEY', $line );
		}
	}
}
