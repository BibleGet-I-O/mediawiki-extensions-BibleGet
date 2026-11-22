<?php

namespace MediaWiki\Extension\BibleGet\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\BibleGet\Hooks;
use MediaWiki\Extension\BibleGet\NoticeStore;
use MediaWiki\Extension\BibleGet\Notifications\NoticeNotifier;
use MediaWiki\Extension\BibleGet\Tests\AnswerFixtures;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;

/**
 * The banner that shows service notices to managers when Echo is not installed.
 *
 * @group Database
 * @covers \MediaWiki\Extension\BibleGet\Hooks
 */
class NoticeBannerTest extends MediaWikiIntegrationTestCase {

	private function useEcho( bool $echo ): void {
		$store = new NoticeStore( $this->getServiceContainer()->getDBLoadBalancer() );
		$this->setService( 'BibleGet.NoticeNotifier', new NoticeNotifier( $store, $echo, new NullLogger() ) );
	}

	private function record( array ...$notices ): void {
		( new NoticeStore( $this->getServiceContainer()->getDBLoadBalancer() ) )->record( $notices );
	}

	/** The HTML BeforePageDisplay adds to a page view by $user, and the modules it loads. */
	private function view( User $user ): array {
		$context = new RequestContext();
		$context->setUser( $user );
		$context->setTitle( Title::makeTitle( NS_MAIN, 'Banner test' ) );
		$context->setLanguage( 'en' );
		$out = $context->getOutput();
		( new Hooks() )->onBeforePageDisplay( $out, $context->getSkin() );
		return [ $out->getHTML(), $out->getModules() ];
	}

	private function dismiss( User $user, string $value ): void {
		$options = $this->getServiceContainer()->getUserOptionsManager();
		$options->setOption( $user, 'bibleget-dismissed', $value );
		$options->saveOptions( $user );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->useEcho( false );
		$this->record( AnswerFixtures::serviceNotice() );
	}

	public function testManagerSeesBanner() {
		[ $html, $modules ] = $this->view( $this->getTestSysop()->getUser() );
		$this->assertStringContainsString( 'bibleget-service-notice', $html );
		$this->assertStringContainsString( 'The v3 API retires next year.', $html );
		$this->assertStringContainsString( 'href="https://www.bibleget.io/news/v3"', $html );
		$this->assertStringContainsString( 'data-bibleget-notice="v3-retirement"', $html );
		$this->assertContains( 'ext.bibleGet.notices', $modules );
	}

	public function testNonManagerSeesNothing() {
		[ $html, $modules ] = $this->view( $this->getTestUser()->getUser() );
		$this->assertSame( '', $html );
		$this->assertNotContains( 'ext.bibleGet.notices', $modules );

		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous();
		$this->assertSame( '', $this->view( $anon )[0] );
	}

	public function testDismissedNoticeHidden() {
		$sysop = $this->getTestSysop()->getUser();
		$this->record( AnswerFixtures::serviceNotice( [ 'id' => 'other-notice', 'message' => 'Other text' ] ) );

		$this->dismiss( $sysop, 'v3-retirement' );
		[ $html ] = $this->view( $sysop );
		$this->assertStringNotContainsString( 'The v3 API retires next year.', $html );
		$this->assertStringContainsString( 'Other text', $html );

		$this->dismiss( $sysop, "other-notice|v3-retirement" );
		$this->assertSame( '', $this->view( $sysop )[0] );
	}

	public function testWithEchoNoBanner() {
		$this->useEcho( true );
		$this->assertSame( '', $this->view( $this->getTestSysop()->getUser() )[0] );
	}

	public function testTextEscapedAndUnsafeLinkDropped() {
		$this->record( AnswerFixtures::serviceNotice( [
			'id' => 'evil-notice',
			'message' => '<script>alert(1)</script><b>bold</b>',
			'link' => 'javascript:alert(1)',
		] ) );
		$this->dismiss( $this->getTestSysop()->getUser(), 'v3-retirement' );
		[ $html ] = $this->view( $this->getTestSysop()->getUser() );
		// Html::element escapes "<" (enough to keep the text from becoming markup)
		$this->assertStringContainsString( '&lt;script>alert(1)&lt;/script>&lt;b>bold&lt;/b>', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function testHeaderOnlyNoticeHasLocalisedText() {
		$this->record( [ 'id' => 'sunset:2027-06-30', 'message' => '', 'link' => null ] );
		$this->dismiss( $this->getTestSysop()->getUser(), 'v3-retirement' );
		[ $html ] = $this->view( $this->getTestSysop()->getUser() );
		$this->assertStringContainsString( '2027-06-30', $html );
		$this->assertStringContainsString( 'data-bibleget-notice="sunset:2027-06-30"', $html );
	}

	public function testDismissedIsAnApiPreference() {
		$prefs = [];
		( new Hooks() )->onGetPreferences( $this->getTestSysop()->getUser(), $prefs );
		$this->assertSame( 'api', $prefs['bibleget-dismissed']['type'] );
		$this->assertSame(
			'',
			$this->getServiceContainer()->getUserOptionsLookup()
				->getDefaultOption( 'bibleget-dismissed' )
		);
	}

	public function testMissingTableRendersPageWithoutBanner() {
		// Before update.php the table does not exist: the page must still render.
		$error = new DBQueryError(
			$this->createMock( IDatabase::class ), 'no such table: bibleget_notice', 1, 'SELECT bn_id', __METHOD__
		);
		$this->setService( 'BibleGet.NoticeStore', new class(
			$this->getServiceContainer()->getDBLoadBalancer(), $error
		) extends NoticeStore {
			public function __construct( $lb, private DBQueryError $error ) {
				parent::__construct( $lb );
			}

			public function current(): array {
				throw $this->error;
			}
		} );
		[ $html, $modules ] = $this->view( $this->getTestSysop()->getUser() );
		$this->assertSame( '', $html );
		$this->assertNotContains( 'ext.bibleGet.notices', $modules );
	}
}
