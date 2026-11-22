<?php

namespace MediaWiki\Extension\BibleGet\Tests\Unit;

use MediaWiki\Extension\BibleGet\BibleGetClient;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use Psr\Log\AbstractLogger;

/**
 * @covers \MediaWiki\Extension\BibleGet\BibleGetClient
 * @covers \MediaWiki\Extension\BibleGet\ApiResult
 */
class BibleGetClientTest extends MediaWikiUnitTestCase {

	private const BASE = 'https://api.example.test/v4/';
	private const SECRET = 'sekrit-credential-123';

	/** @var array Recorded request: url, options, headers */
	private array $seen = [];

	private function client(
		int $status = 200,
		string $body = 'ok',
		array $headers = [],
		?\Throwable $throws = null,
		bool $fails = false,
		?AbstractLogger $logger = null
	): BibleGetClient {
		$req = $this->createMock( MWHttpRequest::class );
		$req->method( 'setHeader' )->willReturnCallback( function ( $n, $v ) {
			$this->seen['headers'][$n] = $v;
		} );
		if ( $throws ) {
			$req->method( 'execute' )->willThrowException( $throws );
		} else {
			$req->method( 'execute' )->willReturn( $fails ? Status::newFatal( 'http-timed-out' ) : Status::newGood() );
		}
		$req->method( 'getStatus' )->willReturn( $fails ? 0 : $status );
		$req->method( 'getContent' )->willReturn( $fails ? '' : $body );
		$req->method( 'getResponseHeaders' )->willReturn( $fails ? [] : $headers );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			function ( $url, $options ) use ( $req ) {
				$this->seen['url'] = $url;
				$this->seen['options'] = $options;
				return $req;
			}
		);
		return new BibleGetClient(
			$factory, self::BASE, '2.0.0',
			$logger ?? new \Psr\Log\NullLogger()
		);
	}

	public function testQuoteRequest() {
		$r = $this->client( 200, '<div/>' )->quote( 'NABRE', 'Jn 3,16', self::SECRET );
		$this->assertSame( self::BASE . 'quote', $this->seen['url'] );
		$this->assertSame( 'POST', $this->seen['options']['method'] );
		$this->assertSame( 5, $this->seen['options']['timeout'] );
		$this->assertEquals(
			[ 'query' => 'Jn 3,16', 'version' => 'NABRE', 'return' => 'html' ],
			$this->seen['options']['postData']
		);
		$this->assertSame( self::SECRET, $this->seen['headers']['X-Api-Key'] );
		$this->assertSame( 200, $r->status );
		$this->assertSame( '<div/>', $r->body );
	}

	public function testReferenceIsEncoded() {
		$this->client()->quote( 'NABRE', 'Mt 5,3&version=X=Y', self::SECRET );
		$data = $this->seen['options']['postData'];
		$this->assertSame( 'Mt 5,3&version=X=Y', $data['query'] );
		$this->assertSame( 'NABRE', $data['version'] );
		$parsed = [];
		parse_str( http_build_query( $data ), $parsed );
		$this->assertSame( $data, $parsed );
	}

	public function testRegisterRequest() {
		$r = $this->client( 201, '{"token":"t","installation_id":"i"}' )
			->register( 'client-key', 'inst-1', 'https://wiki.test' );
		$this->assertSame( self::BASE . 'installations', $this->seen['url'] );
		$this->assertSame( 'POST', $this->seen['options']['method'] );
		$this->assertSame( 'client-key', $this->seen['headers']['X-Api-Key'] );
		$this->assertSame( 'application/json', $this->seen['headers']['Content-Type'] );
		$this->assertSame(
			[ 'install_id' => 'inst-1', 'plugin_version' => '2.0.0', 'site_url' => 'https://wiki.test' ],
			json_decode( $this->seen['options']['postData'], true )
		);
		$this->assertSame( 201, $r->status );
	}

	public function testTimeoutGivesStatusZero() {
		$r = $this->client( fails: true )->quote( 'NABRE', 'Jn 3,16', self::SECRET );
		$this->assertSame( 0, $r->status );
		$this->assertSame( '', $r->body );
		$this->assertNull( $r->retryAfter );
	}

	public function testRetryAfter() {
		$r = $this->client( 429, '', [ 'retry-after' => '120', 'Content-Type' => 'x' ] )
			->quote( 'NABRE', 'Jn 3,16', self::SECRET );
		$this->assertSame( 120, $r->retryAfter );
		$this->assertSame( 'x', $r->headers['content-type'] );
	}

	public function testRetryAfterHttpDate() {
		$date = gmdate( 'D, d M Y H:i:s', time() + 90 ) . ' GMT';
		$r = $this->client( 429, '', [ 'Retry-After' => $date ] )->quote( 'N', 'x', self::SECRET );
		$this->assertGreaterThanOrEqual( 85, $r->retryAfter );
		$this->assertLessThanOrEqual( 90, $r->retryAfter );
		$past = gmdate( 'D, d M Y H:i:s', time() - 500 ) . ' GMT';
		$r = $this->client( 429, '', [ 'Retry-After' => $past ] )->quote( 'N', 'x', self::SECRET );
		$this->assertSame( 0, $r->retryAfter );
	}

	public function testNoSecretInException() {
		$logger = new class extends AbstractLogger {
			public array $records = [];

			public function log( $level, $message, array $context = [] ) {
				$this->records[] = [ $message, $context ];
			}
		};
		$client = $this->client(
			throws: new \RuntimeException( 'boom ' . self::SECRET ), logger: $logger
		);
		$r = $client->quote( 'NABRE', 'Jn 3,16', self::SECRET );
		$this->assertSame( 0, $r->status );
		$this->assertStringNotContainsString( self::SECRET, serialize( $r ) );
		$this->assertNotEmpty( $logger->records );
		$this->assertStringNotContainsString( self::SECRET, serialize( $logger->records ) );
	}
}
