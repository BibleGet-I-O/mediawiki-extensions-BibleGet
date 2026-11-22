<?php

namespace MediaWiki\Extension\BibleGet\Tests\Unit;

use MediaWiki\Extension\BibleGet\AnswerParser;
use MediaWiki\Extension\BibleGet\QuoteAnswer;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\BibleGet\AnswerParser
 * @covers \MediaWiki\Extension\BibleGet\QuoteAnswer
 */
class AnswerParserTest extends MediaWikiUnitTestCase {

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . "/../fixtures/answers/$name.html" );
	}

	private function parse( string $html, int $max = 30 ): QuoteAnswer {
		return AnswerParser::parse( $html, $max );
	}

	/** @return string[] the text of each verseNum span, by verse number */
	private function verseLabels( QuoteAnswer $a ): array {
		preg_match_all( '!<span class="sup verseNum"[^>]*>([^<]*)</span>!', $a->html, $m );
		return $m[1];
	}

	public function testGoodQuote() {
		$a = $this->parse( $this->fixture( 'good-nabre-jn3-16' ) );
		$this->assertFalse( $a->isFailure() );
		$this->assertSame( 1, $a->verseCount );
		$this->assertStringStartsWith( '<div class="results bibleQuote">', $a->html );
		$this->assertStringNotContainsString( '<input', $a->html );
		$this->assertStringNotContainsString( 'errorsTbl', $a->html );
		$this->assertFalse( $a->cut );
		$this->assertFalse( $a->cappedByApi );
		$this->assertSame( [], $a->notices );
		$this->assertSame( [], $a->serviceNotices );
	}

	public function testPoeticKeepsFormatting() {
		$a = $this->parse( $this->fixture( 'poetic-ps23' ) );
		$this->assertStringContainsString( '<span class="pof">', $a->html );
		$this->assertStringContainsString( '<span class="sm">ORD</span>', $a->html );
		$this->assertStringContainsString( '<br>', $a->html );
	}

	public function testSanitiserDropsScriptAndHandlers() {
		$html = str_replace(
			'For God so loved',
			'<script>evil()</script>Before <b onclick="x" style="x" class="c">bold</b> '
				. '<a href="javascript:x">link</a> <span onclick="y" style="color:red" '
				. 'class="ok" data-omitted="1" data-evil="1">kept</span> For God so loved',
			$this->fixture( 'good-nabre-jn3-16' )
		);
		$a = $this->parse( $html );
		$banned = [ '<script', 'evil()', 'onclick', 'style=', '<a ', 'href', 'javascript', '<b', 'data-evil' ];
		foreach ( $banned as $bad ) {
			$this->assertStringNotContainsString( $bad, $a->html, $bad );
		}
		$this->assertStringContainsString( 'Before bold link', $a->html );
		$this->assertStringContainsString( '<span class="ok" data-omitted="1">kept</span>', $a->html );
	}

	public function testBadReference() {
		$a = $this->parse( $this->fixture( 'bad-reference' ) );
		$this->assertTrue( $a->isFailure() );
		$this->assertSame( 13, $a->errors[0]['num'] );
		$this->assertSame(
			'The book Xyz is not a valid Bible book. Please check the documentation for a list of '
				. 'correct Bible book names, whether full or abbreviated.',
			$a->errors[0]['message']
		);
	}

	public function testCutsPublicDomainToLimit() {
		$a = $this->parse( $this->fixture( 'full-drb-mt5' ), 30 );
		$this->assertSame( 48, $a->verseCount );
		$this->assertTrue( $a->cut );
		$labels = $this->verseLabels( $a );
		$this->assertCount( 30, $labels );
		$this->assertSame( '30', end( $labels ) );
		$this->assertStringContainsString( 'Matthew', $a->html );
	}

	public function testMultiChapterCountsAcrossChapters() {
		$a = $this->parse( $this->fixture( 'multichapter-drb-mt5-48-6-2' ), 2 );
		$this->assertSame( 3, $a->verseCount );
		$this->assertTrue( $a->cut );
		$this->assertSame( [ '48', '1' ], $this->verseLabels( $a ) );
		$this->assertStringContainsString( "Matthew\u{00A0}6", $a->html );
		$this->assertStringNotContainsString( 'sound not a trumpet', $a->html );
	}

	public function testCutDropsEmptyChapterHeading() {
		$a = $this->parse( $this->fixture( 'multichapter-drb-mt5-48-6-2' ), 1 );
		$this->assertSame( [ '48' ], $this->verseLabels( $a ) );
		$this->assertStringNotContainsString( "Matthew\u{00A0}6", $a->html );
	}

	public function testSubVersesCountOnce() {
		$html = '<div class="results bibleQuote"><p class="version bibleVersion">X</p>'
			. '<p class="book bookChapter">Matthew&nbsp;5</p><p class="verses versesParagraph">'
			. '<span class="sup verseNum">5a</span><span class="text verseText">A</span>'
			. '<span class="sup verseNum">5b</span><span class="text verseText">B</span>'
			. '<span class="sup verseNum">6</span><span class="text verseText">C</span>'
			. '</p></div><div class="errors bibleQuote"></div>';
		$a = $this->parse( $html, 1 );
		$this->assertSame( 2, $a->verseCount );
		$this->assertTrue( $a->cut );
		$this->assertSame( [ '5a', '5b' ], $this->verseLabels( $a ) );
		$this->assertStringNotContainsString( '>C<', $a->html );
	}

	public function testSameNumberInNewChapterIsNewVerse() {
		$html = '<div class="results bibleQuote">'
			. '<p class="book bookChapter">A&nbsp;1</p><p class="verses versesParagraph">'
			. '<span class="sup verseNum">1</span><span class="text verseText">a</span></p>'
			. '<p class="book bookChapter">A&nbsp;2</p><p class="verses versesParagraph">'
			. '<span class="sup verseNum">1</span><span class="text verseText">b</span></p></div>';
		$a = $this->parse( $html, 5 );
		$this->assertSame( 2, $a->verseCount );
		$this->assertFalse( $a->cut );
	}

	public function testExactLimitNoCut() {
		$a = $this->parse( $this->fixture( 'capped-nabre-mt5' ), 30 );
		$this->assertSame( 30, $a->verseCount );
		$this->assertFalse( $a->cut );
		$this->assertFalse( $a->cappedByApi );
	}

	public function testCapNoticeSetsCappedByApi() {
		$notice = [ [
			'code' => 'VERSES_CAPPED', 'version' => 'NABRE', 'book' => 'Matthew', 'chapter' => 5,
			'verse_from' => 1, 'verse_to' => 48, 'reference' => 'Matthew 5', 'limit' => 30,
			'message' => 'Capped.',
		] ];
		$html = str_replace(
			'name="notices" value="[]"',
			'name="notices" value="' . htmlspecialchars( json_encode( $notice ) ) . '"',
			$this->fixture( 'capped-nabre-mt5' )
		);
		$this->assertNotSame( $this->fixture( 'capped-nabre-mt5' ), $html );
		$a = $this->parse( $html, 30 );
		$this->assertTrue( $a->cappedByApi );
		$this->assertSame( $notice, $a->notices );
		$this->assertFalse( $a->cut );
	}

	public function testVersionsInfo() {
		$a = $this->parse( $this->fixture( 'good-nabre-jn3-16' ) );
		$this->assertSame( [ 'NABRE' => [
			'name' => 'New American Bible - Revised Edition',
			'year' => '2011',
			'holder' => 'United States Conference of Catholic Bishops',
		] ], $a->versions );
		// The notes field (the last) may hold anything, pipes and newlines included.
		$this->assertSame( "Douay\u{2013}Rheims Challoner Revision",
			$this->parse( $this->fixture( 'full-drb-mt5' ) )->versions['DRB']['name'] );
	}

	public static function provideBadVersionsInfo(): array {
		return [
			'not JSON' => [ 'nope' ],
			'a list' => [ '[]' ],
			'not a string' => [ '{"NABRE":5}' ],
			'bad sigla' => [ '{"NA BRE":"A|2011"}' ],
			'no name' => [ '{"NABRE":"|2011"}' ],
		];
	}

	/**
	 * @dataProvider provideBadVersionsInfo
	 */
	public function testBadVersionsInfoIsIgnored( string $json ) {
		$html = '<div class="results bibleQuote"><p class="verses versesParagraph"><span class="sup verseNum">1</span>'
			. '<span class="text verseText">x</span></p></div><input type="hidden" name="bibleVersionsInfo" value="'
			. htmlspecialchars( $json ) . '">';
		$this->assertSame( [], $this->parse( $html )->versions );
	}

	public function testRoundTrip() {
		$a = new QuoteAnswer( '<div class="results bibleQuote"></div>', [ [ 'num' => 3, 'message' => 'm' ] ],
			7, true, true, [ [ 'code' => 'X' ] ], [ [ 'code' => 'Y' ] ],
			[ 'NABRE' => [ 'name' => 'N', 'year' => '2011', 'holder' => '' ] ] );
		$this->assertEquals( $a, QuoteAnswer::fromArray( $a->toArray() ) );
		$b = $this->parse( $this->fixture( 'full-drb-mt5' ) );
		$this->assertEquals( $b, QuoteAnswer::fromArray( $b->toArray() ) );
	}
}
