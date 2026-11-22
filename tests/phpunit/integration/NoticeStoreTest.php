<?php

namespace MediaWiki\Extension\BibleGet\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\BibleGet\NoticeStore;
use MediaWiki\Extension\BibleGet\Notifications\ManagerLocator;
use MediaWiki\Extension\BibleGet\Notifications\NoticeNotifier;
use MediaWiki\Extension\BibleGet\Tests\AnswerFixtures;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use TestLogger;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\BibleGet\NoticeStore
 * @covers \MediaWiki\Extension\BibleGet\Notifications\NoticeNotifier
 * @covers \MediaWiki\Extension\BibleGet\Notifications\ManagerLocator
 * @covers \MediaWiki\Extension\BibleGet\Notifications\EchoHooks
 * @covers \MediaWiki\Extension\BibleGet\Notifications\ServiceNoticePresentationModel
 */
class NoticeStoreTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( '20261122000000' );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( null );
		parent::tearDown();
	}

	private static function notice( array $over = [] ): array {
		return AnswerFixtures::serviceNotice( $over );
	}

	private function store(): NoticeStore {
		return new NoticeStore( $this->getServiceContainer()->getDBLoadBalancer() );
	}

	private function notifier( bool $echo ): NoticeNotifier {
		return new NoticeNotifier( $this->store(), $echo, new NullLogger() );
	}

	private function requireEcho(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			$this->markTestSkipped( 'Echo is not loaded' );
		}
	}

	/** Echo events of our type, oldest first. */
	private function events(): array {
		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'event_id', 'event_extra' ] )
			->from( 'echo_event' )
			->where( [ 'event_type' => 'bibleget-service-notice' ] )
			->orderBy( 'event_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		return iterator_to_array( $rows, false );
	}

	private function notificationsFor( User $user ): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'echo_notification' )
			->join( 'echo_event', null, 'notification_event = event_id' )
			->where( [ 'notification_user' => $user->getId(), 'event_type' => 'bibleget-service-notice' ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function testRecordReturnsNewOnce() {
		$store = $this->store();
		$this->assertSame( [], $store->current() );

		$new = $store->record( [ self::notice() ] );
		$this->assertSame( [ 'v3-retirement' ], array_column( $new, 'id' ) );
		$this->assertSame( [], $store->record( [ self::notice() ] ), 'the same id is not new again' );
		$this->assertSame( [], $this->store()->record( [ self::notice( [ 'message' => 'Edited' ] ) ] ),
			'ids are never edited: a known id stays as first seen' );

		$current = $store->current();
		$this->assertCount( 1, $current );
		$this->assertSame( 'v3-retirement', $current[0]['id'] );
		$this->assertSame( 'The v3 API retires next year.', $current[0]['message'] );
		$this->assertSame( 'https://www.bibleget.io/news/v3', $current[0]['link'] );
		$this->assertSame( '20261122000000', $current[0]['firstSeen'] );
	}

	public function testRecordSeveralAndHeaderIds() {
		$store = $this->store();
		$new = $store->record( [
			self::notice(),
			self::notice( [ 'id' => 'sunset:2027-06-30', 'message' => '', 'link' => null ] ),
		] );
		$this->assertSame( [ 'v3-retirement', 'sunset:2027-06-30' ], array_column( $new, 'id' ) );
		$new = $store->record( [ self::notice(), self::notice( [ 'id' => 'another-one' ] ) ] );
		$this->assertSame( [ 'another-one' ], array_column( $new, 'id' ) );
		$this->assertCount( 3, $store->current() );
	}

	public function testMessageStoredAsTextAndUnsafeLinkDropped() {
		$message = '<b>x</b> & <script>alert(1)</script>';
		$store = $this->store();
		$store->record( [ self::notice( [ 'message' => $message, 'link' => 'javascript:alert(1)' ] ) ] );
		$current = $store->current();
		$this->assertSame( $message, $current[0]['message'] );
		$this->assertNull( $current[0]['link'] );
	}

	public function testInvalidNoticesNotStored() {
		$store = $this->store();
		$this->assertSame( [], $store->record( [ [ 'id' => '', 'message' => 'x' ], 'junk', [ 'message' => 'x' ] ] ) );
		$this->assertSame( [], $store->current() );
	}

	public function testManagerLocator() {
		$sysop = $this->getTestSysop()->getUser();
		$plain = $this->getTestUser()->getUser();
		$expired = $this->getMutableTestUser()->getUser();
		$this->getServiceContainer()->getUserGroupManager()
			->addUserToGroup( $expired, 'sysop', '20261121000000' );

		$managers = $this->getServiceContainer()->getService( 'BibleGet.ManagerLocator' )->locate();
		$this->assertContainsOnlyInstancesOf( User::class, $managers, 'Echo only notifies User objects' );
		$ids = array_map( static fn ( $u ) => $u->getId(), $managers );
		$this->assertContains( $sysop->getId(), $ids );
		$this->assertNotContains( $plain->getId(), $ids );
		$this->assertNotContains( $expired->getId(), $ids, 'expired membership' );
		$this->assertSame( array_unique( $ids ), $ids, 'each manager once' );
	}

	public function testManagerLocatorIgnoresImplicitGroups() {
		$this->setGroupPermissions( 'user', 'bibleget-manage', true );
		$plain = $this->getTestUser()->getUser();
		$locator = $this->getServiceContainer()->getService( 'BibleGet.ManagerLocator' );
		$this->assertInstanceOf( ManagerLocator::class, $locator );
		$ids = array_map( static fn ( $u ) => $u->getId(), $locator->locate() );
		$this->assertNotContains( $plain->getId(), $ids );
	}

	public function testWithoutEchoNoEvent() {
		$this->requireEcho();
		$this->getTestSysop();
		$this->notifier( false )->notify( [ self::notice() ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( [ 'v3-retirement' ], array_column( $this->store()->current(), 'id' ) );
		$this->runJobs( [ 'minJobs' => 0 ] );
		$this->assertSame( [], $this->events() );
	}

	public function testOneEchoEventPerNewNotice() {
		$this->requireEcho();
		$sysop = $this->getTestSysop()->getUser();
		$plain = $this->getTestUser()->getUser();

		$notifier = $this->notifier( true );
		$notifier->notify( [ self::notice(), self::notice( [ 'id' => 'second-notice', 'link' => null ] ) ] );
		$notifier->notify( [ self::notice() ] );
		// A later request (a new notifier) sees the same notice again
		$this->notifier( true )->notify( [ self::notice() ] );
		DeferredUpdates::doUpdates();
		$this->runJobs( [ 'minJobs' => 0 ] );

		$events = $this->events();
		$this->assertCount( 2, $events, 'one event per new notice, not repeated' );
		$extra = unserialize( $events[0]->event_extra );
		$this->assertSame( 'v3-retirement', $extra['notice-id'] );
		$this->assertSame( 2, $this->notificationsFor( $sysop ), 'one notification per notice for a manager' );
		$this->assertSame( 0, $this->notificationsFor( $plain ), 'none for a non-manager' );
	}

	/**
	 * A NoticeStore that counts its calls; record() can be made to throw.
	 */
	private function spyStore( bool $failRecord = false ): NoticeStore {
		return new class( $this->getServiceContainer()->getDBLoadBalancer(), $failRecord ) extends NoticeStore {
			public int $records = 0;
			public int $knownChecks = 0;

			public function __construct( $lb, private readonly bool $failRecord ) {
				parent::__construct( $lb );
			}

			public function knownIds( array $ids ): array {
				$this->knownChecks++;
				return parent::knownIds( $ids );
			}

			public function record( array $notices ): array {
				$this->records++;
				if ( $this->failRecord ) {
					throw new RuntimeException( 'no table' );
				}
				return parent::record( $notices );
			}
		};
	}

	public function testKnownNoticeMakesNoPrimaryWrite() {
		$this->store()->record( [ self::notice() ] );
		$spy = $this->spyStore();
		$notifier = new NoticeNotifier( $spy, true, new NullLogger() );

		$notifier->notify( [ self::notice() ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( 1, $spy->knownChecks, 'a replica read' );
		$this->assertSame( 0, $spy->records, 'no write (and no Echo event) for a known id' );

		// An unknown one in the same list is still recorded, once
		$notifier->notify( [ self::notice(), self::notice( [ 'id' => 'fresh-notice' ] ) ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( 1, $spy->records );
		$this->assertCount( 2, $this->store()->current() );
	}

	public function testRecordIsDeferred() {
		$spy = $this->spyStore();
		$notifier = new NoticeNotifier( $spy, false, new NullLogger() );
		$scope = DeferredUpdates::preventOpportunisticUpdates();
		$notifier->notify( [ self::notice() ] );
		$this->assertSame( 0, $spy->records, 'nothing written during the parse' );
		$this->assertSame( [], $this->store()->current() );
		DeferredUpdates::doUpdates();
		$this->assertSame( 1, $spy->records );
		$this->assertSame( [ 'v3-retirement' ], array_column( $this->store()->current(), 'id' ) );
	}

	public function testDeferredFailureIsLogged() {
		$logger = new TestLogger( true, null, true );
		$notifier = new NoticeNotifier( $this->spyStore( true ), true, $logger );
		$notifier->notify( [ self::notice() ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( 'warning', $logger->getBuffer()[0][0] ?? null );
		$this->assertSame( 'no table', $logger->getBuffer()[0][2]['error'] ?? null );
	}

	public function testPresentationModel() {
		$this->requireEcho();
		$sysop = $this->getTestSysop()->getUser();
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );

		$event = Event::newFromRow( (object)[
			'event_id' => 1, 'event_type' => 'bibleget-service-notice', 'event_variant' => null,
			'event_agent_id' => null, 'event_agent_ip' => null, 'event_page_id' => null, 'event_deleted' => 0,
			'event_extra' => serialize( [
				'notice-id' => 'x-1', 'message' => '<script>alert(1)</script> retires', 'link' => 'javascript:alert(1)',
			] ),
			'notification_timestamp' => '20261122000000', 'notification_read_timestamp' => null,
			'notification_bundle_hash' => '',
		] );
		$model = EchoEventPresentationModel::factory( $event, $lang, $sysop );
		$this->assertTrue( $model->canRender() );
		$header = $model->getHeaderMessage()->parse();
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; retires', $header );
		$this->assertStringNotContainsString( '<script>', $header );
		$this->assertFalse( $model->getPrimaryLink(), 'no link for javascript:' );

		$event->setExtra( 'link', 'https://www.bibleget.io/x' );
		$model = EchoEventPresentationModel::factory( $event, $lang, $sysop );
		$this->assertSame( 'https://www.bibleget.io/x', $model->getPrimaryLink()['url'] );

		// A header-only notice has no text of its own
		$event->setExtra( 'notice-id', 'sunset:2027-06-30' );
		$event->setExtra( 'message', '' );
		$model = EchoEventPresentationModel::factory( $event, $lang, $sysop );
		$this->assertStringContainsString( '2027-06-30', $model->getHeaderMessage()->text() );

		$event->setExtra( 'notice-id', '' );
		$this->assertFalse( EchoEventPresentationModel::factory( $event, $lang, $sysop )->canRender() );
	}

	public function testServicesAreWired() {
		$services = $this->getServiceContainer();
		$this->assertInstanceOf( NoticeStore::class, $services->getService( 'BibleGet.NoticeStore' ) );
		$this->assertInstanceOf( ManagerLocator::class, $services->getService( 'BibleGet.ManagerLocator' ) );
		$notifier = $services->getService( 'BibleGet.NoticeNotifier' );
		$this->assertInstanceOf( NoticeNotifier::class, $notifier );
		$this->assertSame( ExtensionRegistry::getInstance()->isLoaded( 'Echo' ), $notifier->usesEcho() );
	}
}
