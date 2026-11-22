<?php

namespace MediaWiki\Extension\BibleGet\Tests\Unit;

use MediaWiki\Extension\BibleGet\AnswerParser;
use MediaWiki\Extension\BibleGet\QuoteAnswer;
use MediaWiki\Extension\BibleGet\ServiceNotices;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\BibleGet\AnswerParser
 * @covers \MediaWiki\Extension\BibleGet\QuoteAnswer
 * @covers \MediaWiki\Extension\BibleGet\ServiceNotices
 */
class AnswerParserNoticesTest extends MediaWikiUnitTestCase {

	/** 2027-01-01T00:00:00Z */
	private const JAN_2027 = 1798761600;

	/**
	 * The good Jn 3:16 answer with a service_notices input holding $value (raw attribute text
	 * before escaping).
	 */
	public static function answerWith( string $value ): string {
		$html = file_get_contents( __DIR__ . '/../fixtures/answers/good-nabre-jn3-16.html' );
		$input = '<input type="hidden" name="service_notices" value="' . htmlspecialchars( $value )
			. '" class="BibleGetServiceNotices">';
		return str_replace(
			'<input type="hidden" name="notices" value="[]" class="BibleGetNotices">',
			'<input type="hidden" name="notices" value="[]" class="BibleGetNotices">' . $input,
			$html
		);
	}

	private static function notice( array $over = [] ): array {
		return $over + [
			'id' => 'v3-retirement',
			'message' => 'The v3 API retires next year.',
			'link' => 'https://www.bibleget.io/news/v3',
			'published' => '2026-11-01',
			'deprecation' => '2027-01-01T00:00:00Z',
			'sunset' => null,
		];
	}

	public function testFixtureHasNoServiceNotices() {
		$html = file_get_contents( __DIR__ . '/../fixtures/answers/good-nabre-jn3-16.html' );
		$this->assertSame( [], AnswerParser::parse( $html, 30 )->serviceNotices );
	}

	public function testBundledNoticesParsed() {
		$a = AnswerParser::parse( self::answerWith( json_encode( [ self::notice() ] ) ), 30 );
		$this->assertSame( [ self::notice() ], $a->serviceNotices );
		$this->assertFalse( $a->isFailure(), 'the quote itself is untouched' );
		$this->assertStringNotContainsString( 'v3-retirement', $a->html );
	}

	public function testMalformedEntriesDropped() {
		$entries = [
			'a string',
			42,
			[ 'message' => 'no id' ],
			self::notice( [ 'id' => 'AB' ] ),
			self::notice( [ 'id' => 'ab' ] ),
			self::notice( [ 'id' => str_repeat( 'a', 65 ) ] ),
			self::notice( [ 'id' => 'has space' ] ),
			self::notice( [ 'id' => 'colon:id' ] ),
			self::notice( [ 'id' => 42 ] ),
			self::notice( [ 'id' => 'no-message', 'message' => '' ] ),
			self::notice( [ 'id' => 'array-message', 'message' => [ 'x' ] ] ),
			self::notice( [ 'id' => 'kept-' . str_repeat( 'a', 59 ) ] ),
			self::notice( [ 'id' => 'kept', 'published' => 5, 'sunset' => [ 'x' ] ] ),
		];
		$a = AnswerParser::parse( self::answerWith( json_encode( $entries ) ), 30 );
		$this->assertSame( [ 'kept-' . str_repeat( 'a', 59 ), 'kept' ], array_column( $a->serviceNotices, 'id' ) );
		$this->assertNull( $a->serviceNotices[1]['published'] );
		$this->assertNull( $a->serviceNotices[1]['sunset'] );
	}

	public function testDuplicateIdKeptOnce() {
		$json = json_encode( [ self::notice(), self::notice( [ 'message' => 'Second copy' ] ) ] );
		$a = AnswerParser::parse( self::answerWith( $json ), 30 );
		$this->assertSame( [ self::notice() ], $a->serviceNotices );
	}

	public function testBadJsonIsNoNotices() {
		foreach ( [ 'not json', '{"id":"single-object","message":"x"}', '"text"', '' ] as $value ) {
			$this->assertSame( [], AnswerParser::parse( self::answerWith( $value ), 30 )->serviceNotices, $value );
		}
	}

	public static function provideLinks(): array {
		return [
			'https' => [ 'https://www.bibleget.io/x', 'https://www.bibleget.io/x' ],
			'javascript' => [ 'javascript:alert(1)', null ],
			'javascript mixed case' => [ ' JavaScript:alert(1)', null ],
			'http' => [ 'http://www.bibleget.io/x', null ],
			'relative' => [ '/x', null ],
			'protocol-relative' => [ '//evil.example/x', null ],
			'data' => [ 'data:text/html,<script>alert(1)</script>', null ],
			'not a string' => [ [ 'https://x' ], null ],
			'no host' => [ 'https:///path', null ],
			'null' => [ null, null ],
		];
	}

	/**
	 * @dataProvider provideLinks
	 */
	public function testOnlyHttpsLinksKept( $link, ?string $expected ) {
		$a = AnswerParser::parse( self::answerWith( json_encode( [ self::notice( [ 'link' => $link ] ) ] ) ), 30 );
		$this->assertCount( 1, $a->serviceNotices );
		$this->assertSame( $expected, $a->serviceNotices[0]['link'] );
		$this->assertSame( $expected, ServiceNotices::safeLink( $link ) );
	}

	public function testMessageStaysText() {
		$message = '<b>Bold</b> & <script>alert(1)</script>';
		$json = json_encode( [ self::notice( [ 'message' => $message ] ) ] );
		$a = AnswerParser::parse( self::answerWith( $json ), 30 );
		$this->assertSame( $message, $a->serviceNotices[0]['message'] );
	}

	public function testServiceNoticesRoundTripThroughCache() {
		$a = AnswerParser::parse( self::answerWith( json_encode( [ self::notice() ] ) ), 30 );
		$this->assertEquals( $a, QuoteAnswer::fromArray( $a->toArray() ) );

		$old = $a->toArray();
		unset( $old['serviceNotices'] );
		$this->assertSame( [], QuoteAnswer::fromArray( $old )->serviceNotices, 'old cache entries still load' );
	}

	public function testDeprecationHeader() {
		$notices = ServiceNotices::fromHeaders( [
			'deprecation' => '@' . self::JAN_2027,
			'link' => '<https://www.bibleget.io/deprecation>; rel="deprecation"; type="text/html"',
		] );
		$this->assertSame( [ [
			'id' => 'deprecation:2027-01-01',
			'message' => '',
			'link' => 'https://www.bibleget.io/deprecation',
			'published' => null,
			'deprecation' => '2027-01-01T00:00:00Z',
			'sunset' => null,
		] ], $notices );
	}

	public function testSunsetHeader() {
		$notices = ServiceNotices::fromHeaders( [
			'sunset' => 'Fri, 01 Jan 2027 00:00:00 GMT',
			'link' => '<https://www.bibleget.io/sunset>; rel="sunset"; type="text/html"',
		] );
		$this->assertSame( [ [
			'id' => 'sunset:2027-01-01',
			'message' => '',
			'link' => 'https://www.bibleget.io/sunset',
			'published' => null,
			'deprecation' => null,
			'sunset' => '2027-01-01T00:00:00Z',
		] ], $notices );
	}

	public function testBothHeadersEachTakeTheirLink() {
		// BibleGetClient joins repeated headers with ", "
		$notices = ServiceNotices::fromHeaders( [
			'deprecation' => '@' . self::JAN_2027,
			'sunset' => 'Sat, 01 Jan 2028 00:00:00 GMT',
			'link' => '<https://www.bibleget.io/s>; rel="sunset"; type="text/html", '
				. '<https://www.bibleget.io/d>; rel=deprecation, <https://www.bibleget.io/other>; rel="help"',
		] );
		$this->assertSame(
			[
				'deprecation:2027-01-01' => 'https://www.bibleget.io/d',
				'sunset:2028-01-01' => 'https://www.bibleget.io/s',
			],
			array_column( $notices, 'link', 'id' )
		);
	}

	public function testHeaderWithoutLinkOrWithUnsafeLink() {
		$this->assertNull( ServiceNotices::fromHeaders( [ 'sunset' => 'Fri, 01 Jan 2027 00:00:00 GMT' ] )[0]['link'] );
		$notices = ServiceNotices::fromHeaders( [
			'deprecation' => '@' . self::JAN_2027,
			'link' => '<javascript:alert(1)>; rel="deprecation"',
		] );
		$this->assertCount( 1, $notices );
		$this->assertNull( $notices[0]['link'] );
	}

	public function testBadHeaderValuesIgnored() {
		$this->assertSame( [], ServiceNotices::fromHeaders( [] ) );
		$this->assertSame( [], ServiceNotices::fromHeaders( [
			'deprecation' => 'yesterday',
			'sunset' => 'not a date',
			'link' => '<https://www.bibleget.io/d>; rel="deprecation"',
		] ) );
		$this->assertSame( [], ServiceNotices::fromHeaders( [ 'deprecation' => '@abc' ] ) );
		$this->assertSame( [], ServiceNotices::fromHeaders( [ 'sunset' => 'tomorrow' ] ), 'only an HTTP-date' );
	}

	public function testBundledWinsOverDuplicateHeader() {
		$bundled = [ self::notice() ];
		$headers = ServiceNotices::fromHeaders( [
			'deprecation' => '@' . self::JAN_2027,
			'sunset' => 'Sat, 01 Jan 2028 00:00:00 GMT',
		] );
		$merged = ServiceNotices::merge( $bundled, $headers );
		$this->assertSame( [ 'v3-retirement', 'sunset:2028-01-01' ], array_column( $merged, 'id' ),
			'the Deprecation header repeats the bundled notice; the Sunset header is new' );
		$this->assertSame( self::notice(), $merged[0] );

		// Same id: the bundled one wins
		$sameId = [ [ 'id' => 'v3-retirement', 'message' => 'header copy' ] + self::notice() ];
		$this->assertSame( [ self::notice() ], ServiceNotices::merge( $bundled, $sameId ) );
	}
}
