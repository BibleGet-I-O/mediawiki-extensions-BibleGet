<?php

namespace MediaWiki\Extension\BibleGet;

use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP client for the BibleGet v4 API. Never throws and never logs a credential.
 */
class BibleGetClient {

	private const TIMEOUT = 5;

	public function __construct(
		private readonly HttpRequestFactory $http,
		private readonly string $apiBase,
		private readonly string $pluginVersion,
		private readonly LoggerInterface $logger
	) {
	}

	public function quote( string $version, string $reference, string $credential ): ApiResult {
		return $this->send( 'quote', [
			'query' => $reference,
			'version' => $version,
			'return' => 'html',
		], $credential, [] );
	}

	/**
	 * The list of Bible editions (metadata/bibleversions, JSON): per sigla the same
	 * fullname|year|…|copyright_holder|notes string a quote answer carries in bibleVersionsInfo.
	 */
	public function bibleVersions( string $credential ): ApiResult {
		return $this->send( 'metadata/bibleversions', [ 'return' => 'json' ], $credential, [] );
	}

	public function register( string $clientKey, string $installId, string $siteUrl ): ApiResult {
		return $this->send( 'installations', json_encode( [
			'install_id' => $installId,
			'plugin_version' => $this->pluginVersion,
			'site_url' => $siteUrl,
		] ), $clientKey, [ 'Content-Type' => 'application/json' ] );
	}

	/**
	 * @param string $route
	 * @param array|string $postData
	 * @param string $key
	 * @param string[] $extraHeaders
	 */
	private function send( string $route, array|string $postData, string $key, array $extraHeaders ): ApiResult {
		try {
			$req = $this->http->create(
				rtrim( $this->apiBase, '/' ) . '/' . $route,
				[ 'method' => 'POST', 'postData' => $postData, 'timeout' => self::TIMEOUT ],
				__METHOD__
			);
			$req->setHeader( 'X-Api-Key', $key );
			foreach ( $extraHeaders as $name => $value ) {
				$req->setHeader( $name, $value );
			}
			$req->execute();
// execute() reports any HTTP code >= 400 as a fatal Status, so the code decides.
			$code = $req->getStatus();
			if ( $code === 0 ) {
				$this->logger->warning( 'BibleGet API request to {route} got no answer', [ 'route' => $route ] );
				return new ApiResult( 0 );
			}
			$headers = [];
			foreach ( $req->getResponseHeaders() as $name => $value ) {
				$headers[strtolower( (string)$name )] = is_array( $value ) ? implode( ', ', $value ) : (string)$value;
			}
			return new ApiResult(
				$code,
				(string)$req->getContent(),
				self::parseRetryAfter( $headers['retry-after'] ?? null ),
				$headers
			);
		} catch ( Throwable $e ) {
			// The message could echo request details, so log only the class.
			$this->logger->warning( 'BibleGet API request to {route} failed: {class}', [
				'route' => $route,
				'class' => get_class( $e ),
			] );
			return new ApiResult( 0 );
		}
	}

	private static function parseRetryAfter( ?string $value ): ?int {
		if ( $value === null || trim( $value ) === '' ) {
			return null;
		}
		$value = trim( $value );
		if ( ctype_digit( $value ) ) {
			return (int)$value;
		}
		$time = strtotime( $value );
		return $time === false ? null : max( 0, $time - time() );
	}
}
