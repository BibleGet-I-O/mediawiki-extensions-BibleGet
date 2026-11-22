<?php

namespace MediaWiki\Extension\BibleGet\Notifications;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\BibleGet\NoticeStore;
use MediaWiki\Extension\Notifications\Model\Event;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the service notices of fresh answers and tells the managers about new ones:
 * one Echo event per new notice when Echo is installed, else the BeforePageDisplay banner.
 */
class NoticeNotifier {

	public const EVENT_TYPE = 'bibleget-service-notice';

	/** @var true[] Ids already handled in this request, so each quote does not write again. */
	private array $seen = [];

	/**
	 * @param NoticeStore $store
	 * @param bool $echoLoaded ExtensionRegistry::isLoaded( 'Echo' ), injected so tests can choose
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly NoticeStore $store,
		private readonly bool $echoLoaded,
		private readonly LoggerInterface $logger
	) {
	}

	/** Whether managers hear of notices through Echo (and not through the banner). */
	public function usesEcho(): bool {
		return $this->echoLoaded;
	}

	/**
	 * Notices seen in a fresh answer, during the parse. Ids already stored are skipped with a
	 * replica read; the others are recorded, and their Echo events created, in a post-send
	 * deferred update, so a GET parse makes no primary write.
	 *
	 * @param array[] $notices Checked notices (see ServiceNotices)
	 */
	public function notify( array $notices ): void {
		$unseen = [];
		foreach ( $notices as $notice ) {
			if ( !isset( $this->seen[$notice['id']] ) ) {
				$this->seen[$notice['id']] = true;
				$unseen[$notice['id']] = $notice;
			}
		}
		if ( $unseen === [] ) {
			return;
		}
		try {
			$known = $this->store->knownIds( array_keys( $unseen ) );
		} catch ( Throwable $e ) {
			$this->logFailure( $e );
			return;
		}
		$unknown = array_values( array_diff_key( $unseen, array_flip( $known ) ) );
		if ( $unknown !== [] ) {
			DeferredUpdates::addCallableUpdate( function () use ( $unknown ) {
				$this->recordNew( $unknown );
			} );
		}
	}

	/**
	 * Record the notices; INSERT IGNORE decides which are new (once, even across concurrent
	 * requests), and each new one gets one Echo event.
	 *
	 * @param array[] $notices
	 * @return array[] The ones new to this wiki
	 */
	public function recordNew( array $notices ): array {
		try {
			$new = $this->store->record( $notices );
		} catch ( Throwable $e ) {
			// A notice must never cost the page (e.g. update.php not run yet).
			$this->logFailure( $e );
			return [];
		}
		if ( $this->echoLoaded ) {
			foreach ( $new as $notice ) {
				$this->createEvent( $notice );
			}
		}
		return $new;
	}

	private function logFailure( Throwable $e ): void {
		$this->logger->warning( 'BibleGet could not record service notices: {error}', [
			'error' => $e->getMessage(),
		] );
	}

	private function createEvent( array $notice ): void {
		try {
			Event::create( [
				'type' => self::EVENT_TYPE,
				'extra' => [
					'notice-id' => $notice['id'],
					'message' => $notice['message'],
					'link' => $notice['link'],
				],
			] );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'BibleGet could not notify a service notice: {error}', [
				'error' => $e->getMessage(),
			] );
		}
	}
}
