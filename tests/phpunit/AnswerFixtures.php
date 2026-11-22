<?php

namespace MediaWiki\Extension\BibleGet\Tests;

/**
 * Captured answers changed for a test.
 */
class AnswerFixtures {

	/**
	 * The good Jn 3:16 answer with a service_notices input holding $value (raw attribute text
	 * before escaping).
	 */
	public static function withServiceNotices( string $value ): string {
		$html = file_get_contents( __DIR__ . '/fixtures/answers/good-nabre-jn3-16.html' );
		$notices = '<input type="hidden" name="notices" value="[]" class="BibleGetNotices">';
		$input = '<input type="hidden" name="service_notices" value="' . htmlspecialchars( $value )
			. '" class="BibleGetServiceNotices">';
		return str_replace( $notices, $notices . $input, $html );
	}

	/** A well-formed service notice, with $over replacing fields. */
	public static function serviceNotice( array $over = [] ): array {
		return $over + [
			'id' => 'v3-retirement',
			'message' => 'The v3 API retires next year.',
			'link' => 'https://www.bibleget.io/news/v3',
			'published' => '2026-11-01',
			'deprecation' => '2027-01-01T00:00:00Z',
			'sunset' => null,
		];
	}
}
