<?php

namespace MediaWiki\Extension\BibleGet\Tests\Integration;

use HashBagOStuff;
use MediaWiki\Extension\BibleGet\ApiResult;
use MediaWiki\Extension\BibleGet\BibleGetClient;
use MediaWiki\Extension\BibleGet\CredentialProvider;
use MediaWiki\Extension\BibleGet\InstallationStore;
use MediaWiki\Extension\BibleGet\QuoteFetcher;
use MediaWiki\Extension\BibleGet\QuoteResult;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\BibleGet\QuoteFetcher
 * @covers \MediaWiki\Extension\BibleGet\QuoteResult
 * @covers \MediaWiki\Extension\BibleGet\CredentialProvider
 */
class QuoteFetcherTest extends MediaWikiIntegrationTestCase {

	private const BASE = 'https://api.example.test/v4/';
	private const PROJECT_KEY = 'bibleget_live_PROJECTKEY';
	private const EXPIRY = 2592000;

	/** @var array[] Recorded quote calls: [ version, reference, credential ] */
	public array $quotes = [];
	/** @var array[] Recorded register calls: [ clientKey, installId, siteUrl ] */
	public array $registers = [];
	/** @var callable Gives the ApiResult for a quote call */
	public $quoteAnswer;
	/** @var ApiResult[] Answers to give to register, in order (the last one repeats) */
	public array $registerAnswers = [];

	private WANObjectCache $wan;
	private BagOStuff $stash;

	protected function setUp(): void {
		parent::setUp();
		$this->wan = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->stash = new HashBagOStuff();
		$this->quotes = [];
		$this->registers = [];
		$this->quoteAnswer = static fn () => new ApiResult( 200, self::fixture( 'good-nabre-jn3-16.html' ) );
		$this->registerAnswers = [];
		ConvertibleTimestamp::setFakeTime( '20261122000000' );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( null );
		parent::tearDown();
	}

	private static function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../fixtures/answers/' . $name );
	}

	private function client(): BibleGetClient {
		$test = $this;
		return new class( $this->createMock( HttpRequestFactory::class ), $test ) extends BibleGetClient {
			private QuoteFetcherTest $test;

			public function __construct( HttpRequestFactory $http, QuoteFetcherTest $test ) {
				parent::__construct( $http, 'https://api.example.test/v4/', '2.0.0', new NullLogger() );
				$this->test = $test;
			}

			public function quote( string $version, string $reference, string $credential ): ApiResult {
				$this->test->quotes[] = [ $version, $reference, $credential ];
				return ( $this->test->quoteAnswer )( $credential );
			}

			public function register( string $clientKey, string $installId, string $siteUrl ): ApiResult {
				$this->test->registers[] = [ $clientKey, $installId, $siteUrl ];
				return count( $this->test->registerAnswers ) > 1
					? array_shift( $this->test->registerAnswers )
					: $this->test->registerAnswers[0];
			}
		};
	}

	private function fetcher( ?string $apiKey = self::PROJECT_KEY, string $base = self::BASE ): QuoteFetcher {
		$services = $this->getServiceContainer();
		$client = $this->client();
		$store = new InstallationStore( $services->getDBLoadBalancer() );
		$credentials = new CredentialProvider(
			$apiKey, 'bgclient_test_CLIENTKEY', 'https://wiki.example.test', $store, $client,
			$this->stash, $services->getGlobalIdGenerator(), new NullLogger()
		);
		return new QuoteFetcher(
			$client, $credentials, $this->wan, $this->stash, $base, self::EXPIRY, 30, new NullLogger()
		);
	}

	private function user( bool $limited = false ): User {
		$user = $this->createMock( User::class );
		$user->method( 'pingLimiter' )->willReturnCallback(
			static fn ( $action ) => $action === 'bibleget-fetch' && $limited
		);
		return $user;
	}

	private function cachedTtl( QuoteFetcher $fetcher, string $version, string $reference ): float {
		$curTTL = null;
		$value = $this->wan->get( $fetcher->makeCacheKey( $version, $reference ), $curTTL );
		$this->assertNotFalse( $value, 'entry is cached' );
		return (float)$curTTL;
	}

	private function lastFailure(): ?array {
		$value = $this->stash->get( $this->stash->makeKey( 'bibleget', 'lastfailure' ) );
		return $value === false ? null : $value;
	}

	public function testGoodQuote() {
		$result = $this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertInstanceOf( QuoteResult::class, $result );
		$this->assertNull( $result->failureKey );
		$this->assertFalse( $result->fromCache );
		$this->assertSame( 1, $result->answer->verseCount );
		$this->assertSame( [ [ 'NABRE', 'Jn 3,16', self::PROJECT_KEY ] ], $this->quotes );
		$this->assertNull( $this->lastFailure() );
	}

	public function testCacheHitNoRequest() {
		$fetcher = $this->fetcher();
		$first = $fetcher->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$second = $fetcher->fetch( 'NABRE', " Jn3, 16\n", $this->user() );
		$this->assertCount( 1, $this->quotes );
		$this->assertFalse( $first->fromCache );
		$this->assertTrue( $second->fromCache );
		$this->assertNull( $second->failureKey );
		$this->assertSame( $first->answer->html, $second->answer->html );
		$this->assertGreaterThan( 300, $this->cachedTtl( $fetcher, 'NABRE', 'Jn 3,16' ) );

		// A new request (new fetcher) shares the cache.
		$this->assertTrue( $this->fetcher()->fetch( 'NABRE', 'Jn3,16', $this->user() )->fromCache );
		$this->assertCount( 1, $this->quotes );

		// Another API address is another cache.
		$other = $this->fetcher( self::PROJECT_KEY, 'https://query.bibleget.io/dev/' );
		$this->assertFalse( $other->fetch( 'NABRE', 'Jn 3,16', $this->user() )->fromCache );
		$this->assertCount( 2, $this->quotes );
		// Another version is another cache.
		$fetcher->fetch( 'DRB', 'Jn 3,16', $this->user() );
		$this->assertCount( 3, $this->quotes );
	}

	public function testRateLimitOnlyForUncached() {
		$limited = $this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $this->user( true ) );
		$this->assertNull( $limited->answer );
		$this->assertSame( 'bibleget-error-ratelimit', $limited->failureKey );
		$this->assertSame( [], $this->quotes );

		// The rate-limit failure is not cached: another user fetches it.
		$this->assertNull( $this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $this->user() )->failureKey );
		$this->assertCount( 1, $this->quotes );

		// A cached quote does not consult the limiter at all.
		$user = $this->createMock( User::class );
		$user->expects( $this->never() )->method( 'pingLimiter' );
		$cached = $this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $user );
		$this->assertTrue( $cached->fromCache );
		$this->assertNull( $cached->failureKey );
		$this->assertCount( 1, $this->quotes );
	}

	public function testTimeoutIsFailure() {
		$this->quoteAnswer = static fn () => new ApiResult( 0 );
		$result = $this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertNull( $result->answer );
		$this->assertSame( 'bibleget-error-network', $result->failureKey );
		$this->assertFalse( $result->fromCache );
		$this->assertSame(
			[ 'key' => 'bibleget-error-network', 'time' => (int)ConvertibleTimestamp::time() ],
			$this->lastFailure()
		);
	}

	public function testFailureNegativeCacheShort() {
		$this->quoteAnswer = static fn () => new ApiResult( 0 );
		$fetcher = $this->fetcher();
		$fetcher->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertLessThanOrEqual( 300, $this->cachedTtl( $fetcher, 'NABRE', 'Jn 3,16' ) );
		$again = $fetcher->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertTrue( $again->fromCache );
		$this->assertSame( 'bibleget-error-network', $again->failureKey );
		$this->assertCount( 1, $this->quotes );

		$this->quoteAnswer = static fn () => new ApiResult( 200, self::fixture( 'bad-reference.html' ) );
		$bad = $fetcher->fetch( 'NABRE', 'Xyz99,1', $this->user() );
		$this->assertNull( $bad->answer );
		$this->assertSame( 'bibleget-error-reference', $bad->failureKey );
		$this->assertLessThanOrEqual( 300, $this->cachedTtl( $fetcher, 'NABRE', 'Xyz99,1' ) );
	}

	public function testUnknownVersionIs422() {
		$this->quoteAnswer = static fn () => new ApiResult( 422, self::fixture( 'unknown-version.html' ) );
		$result = $this->fetcher()->fetch( 'ZZZZ', 'Jn 3,16', $this->user() );
		$this->assertNull( $result->answer );
		$this->assertSame( 'bibleget-error-version', $result->failureKey );
	}

	public function testOtherStatusIsFailure() {
		$this->quoteAnswer = static fn () => new ApiResult( 503, 'down' );
		$this->assertSame(
			'bibleget-error-status',
			$this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $this->user() )->failureKey
		);
	}

	public function testApi429IsRateLimitFailure() {
		$this->quoteAnswer = static fn () => new ApiResult( 429, '', 60 );
		$fetcher = $this->fetcher();
		$result = $fetcher->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertSame( 'bibleget-error-ratelimit', $result->failureKey );
		$this->assertLessThanOrEqual( 60, $this->cachedTtl( $fetcher, 'NABRE', 'Jn 3,16' ) );
	}

	public function testProjectKeyRejectedIsNotRetried() {
		$this->quoteAnswer = static fn () => new ApiResult( 401, '{"title":"Unauthorized"}' );
		$result = $this->fetcher()->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertSame( 'bibleget-error-status', $result->failureKey );
		$this->assertCount( 1, $this->quotes );
		$this->assertSame( [], $this->registers );
	}

	public function testVerseLimitFromConfig() {
		$this->quoteAnswer = static fn () => new ApiResult( 200, self::fixture( 'full-drb-mt5.html' ) );
		$result = $this->fetcher()->fetch( 'DRB', 'Mt5', $this->user() );
		$this->assertTrue( $result->answer->cut );
		$this->assertSame( 48, $result->answer->verseCount );
	}

	public function testBadCacheEntryIsMiss() {
		$fetcher = $this->fetcher();
		$key = $fetcher->makeCacheKey( 'NABRE', 'Jn 3,16' );
		foreach ( [ [ 'answer' => [ 'html' => 'partial' ] ], 'garbage', [ 'failure' => 42 ], [] ] as $i => $bad ) {
			$this->wan->set( $key, $bad, 3600 );
			$result = $fetcher->fetch( 'NABRE', 'Jn 3,16', $this->user() );
			$this->assertNull( $result->failureKey, "entry $i" );
			$this->assertFalse( $result->fromCache, "entry $i" );
			$this->assertCount( $i + 1, $this->quotes, "entry $i" );
		}
	}

	public function testFirstFetchRegisters() {
		$this->registerAnswers = [
			new ApiResult( 201, json_encode( [ 'token' => 'bginst_TOKEN', 'installation_id' => 'i-1' ] ) ),
		];
		$result = $this->fetcher( null )->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertNull( $result->failureKey );
		$this->assertCount( 1, $this->registers );
		$this->assertSame( 'bginst_TOKEN', $this->quotes[0][2] );
	}

	public function testRegistrationFailureIsFailure() {
		$this->registerAnswers = [ new ApiResult( 401, '' ) ];
		$result = $this->fetcher( null )->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertSame( 'bibleget-error-clientkey-revoked', $result->failureKey );
		$this->assertSame( [], $this->quotes );
	}

	public function testTokenRejectedReRegistersOnce() {
		$this->registerAnswers = [
			new ApiResult( 201, json_encode( [ 'token' => 'bginst_OLD', 'installation_id' => 'i-1' ] ) ),
			new ApiResult( 200, json_encode( [ 'token' => 'bginst_NEW', 'installation_id' => 'i-1' ] ) ),
		];
		$this->quoteAnswer = static fn ( $credential ) => $credential === 'bginst_NEW'
			? new ApiResult( 200, self::fixture( 'good-nabre-jn3-16.html' ) )
			: new ApiResult( 401, '{"title":"Unauthorized"}' );
		$result = $this->fetcher( null )->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertNull( $result->failureKey );
		$this->assertSame( 1, $result->answer->verseCount );
		$this->assertCount( 2, $this->registers );
		$this->assertSame( $this->registers[0][1], $this->registers[1][1], 'same install_id' );
		$this->assertSame( [ 'bginst_OLD', 'bginst_NEW' ], array_column( $this->quotes, 2 ) );
	}

	public function testSecond401IsRegistrationFailure() {
		$this->registerAnswers = [
			new ApiResult( 201, json_encode( [ 'token' => 'bginst_OLD', 'installation_id' => 'i-1' ] ) ),
			new ApiResult( 200, json_encode( [ 'token' => 'bginst_NEW', 'installation_id' => 'i-1' ] ) ),
		];
		$this->quoteAnswer = static fn () => new ApiResult( 401, '{"title":"Unauthorized"}' );
		$fetcher = $this->fetcher( null );
		$result = $fetcher->fetch( 'NABRE', 'Jn 3,16', $this->user() );
		$this->assertNull( $result->answer );
		$this->assertSame( 'bibleget-error-registration', $result->failureKey );
		$this->assertCount( 2, $this->quotes );
		$this->assertCount( 2, $this->registers );

		// A later uncached quote in the same request does not register again.
		$next = $fetcher->fetch( 'NABRE', 'Mt 5,3', $this->user() );
		$this->assertSame( 'bibleget-error-registration', $next->failureKey );
		$this->assertCount( 2, $this->quotes );
		$this->assertCount( 2, $this->registers );
	}

	public function testServicesAreWired() {
		$services = $this->getServiceContainer();
		$this->assertInstanceOf( InstallationStore::class, $services->getService( 'BibleGet.InstallationStore' ) );
		$this->assertInstanceOf( CredentialProvider::class, $services->getService( 'BibleGet.CredentialProvider' ) );
		$this->assertInstanceOf( QuoteFetcher::class, $services->getService( 'BibleGet.QuoteFetcher' ) );
	}
}
