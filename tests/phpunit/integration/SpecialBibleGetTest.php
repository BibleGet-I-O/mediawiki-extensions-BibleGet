<?php

namespace MediaWiki\Extension\BibleGet\Tests\Integration;

use HashBagOStuff;
use MediaWiki\Extension\BibleGet\ApiResult;
use MediaWiki\Extension\BibleGet\BibleGetClient;
use MediaWiki\Extension\BibleGet\CredentialProvider;
use MediaWiki\Extension\BibleGet\InstallationStore;
use MediaWiki\Extension\BibleGet\NoticeStore;
use MediaWiki\Extension\BibleGet\Special\SpecialBibleGet;
use MediaWiki\Http\HttpRequestFactory;
use PermissionsError;
use Psr\Log\NullLogger;
use SpecialPageTestBase;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\DBUnexpectedError;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\BibleGet\Special\SpecialBibleGet
 * @covers \MediaWiki\Extension\BibleGet\CredentialProvider::mode
 */
class SpecialBibleGetTest extends SpecialPageTestBase {

	private const PROJECT_KEY = 'bibleget_live_SECRETPROJECTKEY';
	private const TOKEN = 'bginst_SECRETTOKEN';

	private BagOStuff $stash;
	private ?string $apiKey = null;
	private string $site = 'https://wiki.example.org';
	private ?NoticeStore $noticeStore = null;

	protected function setUp(): void {
		parent::setUp();
		$this->stash = new HashBagOStuff();
		$this->apiKey = null;
		$this->site = 'https://wiki.example.org';
		$this->noticeStore = null;
		ConvertibleTimestamp::setFakeTime( '20261122120000' );
		$this->overrideConfigValue( 'RateLimits', [
			'bibleget-fetch' => [
				'anon' => [ 31, 61 ], 'ip' => [ 32, 62 ], 'newbie' => [ 33, 63 ], 'user' => [ 124, 64 ],
			],
		] );
		$this->overrideConfigValue( 'BibleGetMaxVersesPerQuote', 37 );
		$this->overrideConfigValue( 'BibleGetMaxQuotesPerPage', 58 );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( null );
		parent::tearDown();
	}

	protected function newSpecialPage() {
		$services = $this->getServiceContainer();
		$credentials = new CredentialProvider(
			$this->apiKey, 'bgclient_test', $this->site,
			new InstallationStore( $services->getDBLoadBalancer() ),
			new BibleGetClient(
				$this->createMock( HttpRequestFactory::class ), 'https://x.test/', '2', new NullLogger()
			),
			$this->stash, $services->getGlobalIdGenerator(), new NullLogger()
		);
		return new SpecialBibleGet(
			$credentials,
			$this->noticeStore ?? new NoticeStore( $services->getDBLoadBalancer() ),
			$this->stash,
			$services->getMainConfig()
		);
	}

	private function manager() {
		return $this->getTestUser( [ 'sysop' ] )->getUser();
	}

	private function render(): string {
		[ $html ] = $this->executeSpecialPage( '', null, 'en', $this->manager() );
		return $html;
	}

	public function testNonManagerGetsPermissionError() {
		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage( '', null, 'en', $this->getTestUser()->getUser() );
	}

	public function testAnonGetsPermissionError() {
		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage( '', null, 'en', $this->getServiceContainer()->getUserFactory()->newAnonymous() );
	}

	public function testManagerSeesLimitsAndRateLimits() {
		$html = $this->render();
		$this->assertStringContainsString( '37', $html );
		$this->assertStringContainsString( '58', $html );
		foreach ( [ '31', '61', '32', '62', '33', '63', '124', '64' ] as $number ) {
			$this->assertStringContainsString( $number, $html );
		}
	}

	public function testUnregisteredMode() {
		$this->assertStringContainsString( 'not registered', $this->render() );
	}

	public function testInternalAddressMode() {
		$this->site = 'http://localhost:8043';
		$html = $this->render();
		$this->assertStringContainsString( 'not a public https address', $html );
		$this->assertStringContainsString( 'localhost', $html );
		$this->assertStringContainsString( '$wgBibleGetApiKey', $html );
		$this->assertStringNotContainsString( 'not registered', $html );
	}

	public function testProjectKeyModeHidesKey() {
		$this->apiKey = self::PROJECT_KEY;
		$html = $this->render();
		$this->assertStringContainsString( 'project key', $html );
		$this->assertStringNotContainsString( self::PROJECT_KEY, $html );
		$this->assertStringNotContainsString( 'SECRETPROJECTKEY', $html );
	}

	public function testRegisteredModeHidesToken() {
		$store = new InstallationStore( $this->getServiceContainer()->getDBLoadBalancer() );
		$store->createInstallId( 'install-id-1' );
		$store->saveToken( self::TOKEN, 'inst-1' );
		$this->apiKey = null;
		$html = $this->render();
		$this->assertStringContainsString( 'registered', $html );
		$this->assertStringNotContainsString( 'not registered', $html );
		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringNotContainsString( 'SECRETTOKEN', $html );
	}

	public function testNeitherSecretInOutputWhenBothSet() {
		$store = new InstallationStore( $this->getServiceContainer()->getDBLoadBalancer() );
		$store->createInstallId( 'install-id-1' );
		$store->saveToken( self::TOKEN, 'inst-1' );
		$this->apiKey = self::PROJECT_KEY;
		$html = $this->render();
		$this->assertStringContainsString( 'project key', $html );
		$this->assertStringNotContainsString( 'SECRET', $html );
	}

	public function testModeDoesNotRegister() {
		$called = false;
		$client = new class( $this->createMock( HttpRequestFactory::class ), $called ) extends BibleGetClient {
			public function __construct( HttpRequestFactory $http, private bool &$called ) {
				parent::__construct( $http, 'https://x.test/', '2', new NullLogger() );
			}

			public function register( string $clientKey, string $installId, string $siteUrl ): ApiResult {
				$this->called = true;
				return new ApiResult( 0 );
			}
		};
		$services = $this->getServiceContainer();
		$provider = new CredentialProvider(
			null, 'bgclient_test', 'https://wiki.example.org',
			new InstallationStore( $services->getDBLoadBalancer() ), $client,
			$this->stash, $services->getGlobalIdGenerator(), new NullLogger()
		);
		$this->assertSame( 'unregistered', $provider->mode() );
		$this->assertFalse( $called );
		$this->assertSame( 'unregistered', ( new CredentialProvider(
			'', 'k', 'https://wiki.example.org', new InstallationStore( $services->getDBLoadBalancer() ), $client,
			$this->stash, $services->getGlobalIdGenerator(), new NullLogger()
		) )->mode() );
	}

	public function testNoFailureRecorded() {
		$this->assertStringContainsString( 'No failure', $this->render() );
	}

	public function testLastFailureAndDate() {
		$this->stash->set(
			$this->stash->makeKey( 'bibleget', 'lastfailure' ),
			[ 'key' => 'bibleget-error-network', 'time' => (int)wfTimestamp( TS_UNIX, '20261120083000' ) ]
		);
		$html = $this->render();
		$this->assertStringContainsString( 'The BibleGet service could not be reached.', $html );
		$this->assertStringContainsString( '20 November 2026', $html );
	}

	public function testUnknownFailureKeyIsEscaped() {
		$this->stash->set(
			$this->stash->makeKey( 'bibleget', 'lastfailure' ),
			[ 'key' => '<script>alert(1)</script>', 'time' => 1 ]
		);
		$html = $this->render();
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
	}

	public function testNoticesListed() {
		$store = new NoticeStore( $this->getServiceContainer()->getDBLoadBalancer() );
		$store->record( [
			[ 'id' => 'n-one', 'message' => 'Service <b>moves</b>', 'link' => 'https://example.test/more' ],
			[ 'id' => 'sunset:2028-01-01', 'message' => '', 'link' => null ],
		] );
		$html = $this->render();
		$this->assertStringContainsString( 'Service &lt;b>moves&lt;/b>', $html );
		$this->assertStringNotContainsString( '<b>moves', $html );
		$this->assertStringContainsString( 'href="https://example.test/more"', $html );
		$this->assertStringContainsString( 'will stop working on 2028-01-01', $html );
		$this->assertStringContainsString( '22 November 2026', $html );
	}

	public function testNoNotices() {
		$this->assertStringContainsString( 'No service notices', $this->render() );
	}

	public function testMissingTableSaysSo() {
		$this->noticeStore = new class( $this->getServiceContainer()->getDBLoadBalancer() ) extends NoticeStore {
			public function current(): array {
				throw new DBUnexpectedError( null, 'no such table: bibleget_notice' );
			}
		};
		$html = $this->render();
		$this->assertStringContainsString( 'update.php', $html );
		$this->assertStringNotContainsString( 'no such table', $html );
	}
}
