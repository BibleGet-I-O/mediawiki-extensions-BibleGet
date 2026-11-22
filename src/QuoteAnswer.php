<?php

namespace MediaWiki\Extension\BibleGet;

/**
 * A parsed, sanitised BibleGet answer. Round-trips through toArray()/fromArray()
 * so it can live in the object cache.
 */
class QuoteAnswer {

	/**
	 * @param string $html Sanitised div.results, possibly cut
	 * @param array[] $errors List of [ 'num' => int, 'message' => string ]
	 * @param int $verseCount Verses in the answer before any cut
	 * @param bool $cut Whether we cut the answer to the verse limit
	 * @param bool $cappedByApi Whether the API itself capped the quote
	 * @param array[] $notices Per-quote notices (decoded BibleGetNotices)
	 * @param array[] $serviceNotices Service notices bundled with the answer (see ServiceNotices)
	 * @param array[] $versions The answer's editions by sigla: [ 'name' => …, 'year' => …, 'holder' => … ]
	 */
	public function __construct(
		public readonly string $html,
		public readonly array $errors,
		public readonly int $verseCount,
		public readonly bool $cut,
		public readonly bool $cappedByApi,
		public readonly array $notices,
		public readonly array $serviceNotices = [],
		public readonly array $versions = []
	) {
	}

	public function isFailure(): bool {
		return $this->errors !== [] || $this->verseCount === 0;
	}

	public function toArray(): array {
		return [
			'html' => $this->html,
			'errors' => $this->errors,
			'verseCount' => $this->verseCount,
			'cut' => $this->cut,
			'cappedByApi' => $this->cappedByApi,
			'notices' => $this->notices,
			'serviceNotices' => $this->serviceNotices,
			'versions' => $this->versions,
		];
	}

	public static function fromArray( array $data ): self {
		return new self(
			(string)$data['html'],
			$data['errors'] ?? [],
			(int)$data['verseCount'],
			(bool)$data['cut'],
			(bool)$data['cappedByApi'],
			$data['notices'] ?? [],
			$data['serviceNotices'] ?? [],
			$data['versions'] ?? []
		);
	}
}
