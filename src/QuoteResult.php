<?php

namespace MediaWiki\Extension\BibleGet;

/**
 * Outcome of one QuoteFetcher::fetch(): an answer, or the message key of the failure reason.
 */
class QuoteResult {

	/**
	 * @param QuoteAnswer|null $answer The quote; null on failure
	 * @param string|null $failureKey Message key of the failure reason; null on success
	 * @param bool $fromCache Whether the result came from the object cache
	 */
	public function __construct(
		public readonly ?QuoteAnswer $answer,
		public readonly ?string $failureKey,
		public readonly bool $fromCache
	) {
	}
}
