<?php

namespace MediaWiki\Extension\BibleGet\Tests\Integration;

use MediaWiki\Extension\BibleGet\Tests\FakeQuoteFetcher;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\BibleGet\QuoteRenderer
 * @covers \MediaWiki\Extension\BibleGet\Hooks
 */
class QuoteRendererTest extends MediaWikiIntegrationTestCase {

	private FakeQuoteFetcher $fetcher;

	protected function setUp(): void {
		parent::setUp();
		$this->fetcher = new FakeQuoteFetcher();
		$this->setService( 'BibleGet.QuoteFetcher', $this->fetcher );
		$this->overrideConfigValues( [
			'BibleGetMaxQuotesPerPage' => 50,
			'BibleGetMaxVersesPerQuote' => 30,
			'BibleGetDefaultBibleVersion' => 'NABRE',
		] );
	}

	private function parse( string $wikitext ): ParserOutput {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		return $parser->parse(
			$wikitext,
			Title::makeTitle( NS_MAIN, 'BibleGet test' ),
			ParserOptions::newFromAnon()
		);
	}

	private static function categoryOf( string $key ): string {
		return str_replace( ' ', '_', wfMessage( $key )->inContentLanguage()->text() );
	}

	public function testBlockQuote() {
		$out = $this->parse( '<biblequote ref="Jn 3,16" />' );
		$html = $out->getRawText();
		$this->assertStringContainsString( '<div class="bibleget-quote">', $html );
		$this->assertStringContainsString( 'For God so loved the world', $html );
		$this->assertSame( [ [ 'NABRE', 'Jn 3,16' ] ], $this->fetcher->calls );
		$this->assertContains( 'ext.bibleGet', $out->getModules() );
		$this->assertContains( 'ext.bibleGet.styles', $out->getModuleStyles() );
		$this->assertSame( [], $out->getCategoryNames() );
	}

	public function testInlineQuote() {
		$html = $this->parse( 'See {{#biblequote:Jn 3,16|inline|NABRE}} here.' )->getRawText();
		$this->assertMatchesRegularExpression(
			'/<span class="bibleget-ref" tabindex="0">Jn 3,16<\/span><span class="bibleget-popup">/',
			$html
		);
		$this->assertStringContainsString( 'For God so loved the world', $html );
		$this->assertStringNotContainsString( 'bibleget-quote', $html );
	}

	public function testNoPageWithoutQuoteGetsModules() {
		$out = $this->parse( 'Plain text.' );
		$this->assertNotContains( 'ext.bibleGet', $out->getModules() );
		$this->assertNotContains( 'ext.bibleGet.styles', $out->getModuleStyles() );
	}

	public function testNoReference() {
		$html = $this->parse( '<biblequote /> {{#biblequote:}}' )->getRawText();
		$this->assertSame( 2, substr_count( $html, 'class="error"' ) );
		$this->assertStringContainsString( wfMessage( 'bibleget-error-noref' )->text(), $html );
		$this->assertSame( [], $this->fetcher->calls );
	}

	public function testReferenceIsLiteral() {
		$this->editPage( 'Template:Tl', 'TEMPLATE-WAS-CALLED' );
		$out = $this->parse( '<biblequote>Mt 5,3 <b>"x"</b> {{tl}} [[A]]</biblequote>' );
		$html = $out->getRawText();
		$this->assertStringContainsString(
			'Mt 5,3 &lt;b&gt;"x"&lt;/b&gt; {{tl}} [[A]]',
			str_replace( '&quot;', '"', $html )
		);
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'TEMPLATE-WAS-CALLED', $html );
		$this->assertSame( [], $out->getTemplates() );
		$this->assertSame( [], $out->getLinks() );
		// The reference reaches the fetcher as written.
		$this->assertSame( [ [ 'NABRE', 'Mt 5,3 <b>"x"</b> {{tl}} [[A]]' ] ], $this->fetcher->calls );
	}

	public function testFunctionReferenceIsEscaped() {
		$this->editPage( 'Template:Tl', '<b>T</b>[[B]]' );
		// Core's preprocessor expands the part after the colon before any parser-function
		// hook runs (that is what lets a template pass {{{1}}}), so {{tl}} is transcluded.
		// The expanded text is still only escaped, never parsed: no HTML, no links.
		$out = $this->parse( '{{#biblequote:Mt 5,3 <b>"x"</b> & {{tl}} [[A]]}}' );
		$html = $out->getRawText();
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertSame( [], $out->getLinks() );
		$this->assertStringContainsString(
			'<span class="bibleget-failed">Mt 5,3 &lt;b&gt;"x"&lt;/b&gt; &amp; &lt;b&gt;T&lt;/b&gt;[[B]] [[A]]</span>',
			str_replace( '&quot;', '"', $html )
		);
		$this->assertSame( [ [ 'NABRE', 'Mt 5,3 <b>"x"</b> & <b>T</b>[[B]] [[A]]' ] ], $this->fetcher->calls );
	}

	public function testSameReferenceFetchedOnce() {
		$this->overrideConfigValue( 'BibleGetMaxQuotesPerPage', 1 );
		$out = $this->parse( "<biblequote>Jn 3,16</biblequote>\n\n{{#biblequote:Jn3, 16|inline}}" );
		$html = $out->getRawText();
		$this->assertCount( 1, $this->fetcher->calls );
		$this->assertSame( 2, substr_count( $html, 'For God so loved the world' ) );
		$this->assertStringNotContainsString( 'bibleget-failed', $html );
		$this->assertSame(
			[ $this->fetcher->makeCacheKey( 'NABRE', 'Jn3,16' ) ],
			array_keys( $out->getExtensionData( 'bibleget-fetched' ) )
		);
	}

	public function testPerPageLimit() {
		$this->overrideConfigValue( 'BibleGetMaxQuotesPerPage', 2 );
		$out = $this->parse(
			"<biblequote>Jn 3,16</biblequote>\n\n<biblequote>Ps 23</biblequote>\n\n<biblequote>Mt 5</biblequote>"
		);
		$html = $out->getRawText();
		$this->assertCount( 2, $this->fetcher->calls );
		$this->assertSame( 2, substr_count( $html, 'class="bibleget-quote"' ) );
		$this->assertStringContainsString( '<span class="bibleget-failed">Mt 5</span>', $html );
		$this->assertStringContainsString(
			'title="' . wfMessage( 'bibleget-error-toomany' )->escaped() . '"',
			$html
		);
		$this->assertContains( self::categoryOf( 'bibleget-tracking-category' ), $out->getCategoryNames() );
	}

	public function testFailureCategoryAndExpiry() {
		$out = $this->parse( '<biblequote version="ZZZZ">Jn 3,16</biblequote>' );
		$html = $out->getRawText();
		$this->assertStringContainsString( '<span class="bibleget-failed">Jn 3,16</span>', $html );
		$this->assertStringContainsString(
			'<sup class="bibleget-failed-marker" title="'
				. wfMessage( 'bibleget-error-version' )->escaped() . '">[!]</sup>',
			$html
		);
		$this->assertContains( self::categoryOf( 'bibleget-tracking-category' ), $out->getCategoryNames() );
		$this->assertLessThanOrEqual( 3600, $out->getCacheExpiry() );
		$this->assertSame( [ [ 'ZZZZ', 'Jn 3,16' ] ], $this->fetcher->calls );
	}

	public function testSuccessKeepsCacheExpiry() {
		$this->assertGreaterThan( 3600, $this->parse( '<biblequote>Jn 3,16</biblequote>' )->getCacheExpiry() );
	}

	public function testCutNoteAndCategory() {
		$out = $this->parse( '<biblequote version="DRB">Mt 5</biblequote>' );
		$html = $out->getRawText();
		$this->assertSame( 30, substr_count( $html, 'class="sup verseNum"' ) );
		$this->assertStringContainsString(
			'<div class="bibleget-note">' . wfMessage( 'bibleget-note-cut', 30 )->escaped() . '</div>',
			$html
		);
		$this->assertContains( self::categoryOf( 'bibleget-toolong-category' ), $out->getCategoryNames() );
		$this->assertNotContains( self::categoryOf( 'bibleget-tracking-category' ), $out->getCategoryNames() );

		$out = $this->parse( '<biblequote version="NABRE">Mt 5</biblequote>' );
		$this->assertStringNotContainsString( 'bibleget-note', $out->getRawText() );
		$this->assertSame( [], $out->getCategoryNames() );
	}

	public function testNoSecretInOutput() {
		$this->overrideConfigValue( 'BibleGetApiKey', 'bibleget_test_SECRET' );
		$out = $this->parse(
			"<biblequote>Jn 3,16</biblequote>\n\n<biblequote version=\"ZZZZ\">Jn 3,16</biblequote>"
		);
		$this->assertStringNotContainsString( 'SECRET', $out->getRawText() );
		$this->assertStringNotContainsString( 'SECRET', json_encode( $out->getJsConfigVars() ) );
		$this->assertStringNotContainsString( 'SECRET', json_encode( $out->getExtensionData( 'bibleget-fetched' ) ) );
	}

	public function testRendererIsWired() {
		$this->assertInstanceOf(
			\MediaWiki\Extension\BibleGet\QuoteRenderer::class,
			$this->getServiceContainer()->getService( 'BibleGet.QuoteRenderer' )
		);
	}
}
