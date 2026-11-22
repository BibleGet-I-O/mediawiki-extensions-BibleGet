<?php

namespace MediaWiki\Extension\BibleGet;

/**
 * Raw outcome of one BibleGet API request.
 */
class ApiResult {

	/**
	 * @param int $status HTTP status; 0 when there was no answer
	 * @param string $body Response body
	 * @param int|null $retryAfter Seconds from the Retry-After header, if any
	 * @param string[] $headers Response headers, names lower-cased
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body = '',
		public readonly ?int $retryAfter = null,
		public readonly array $headers = []
	) {
	}
}
