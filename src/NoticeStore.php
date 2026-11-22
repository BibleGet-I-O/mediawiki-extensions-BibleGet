<?php

namespace MediaWiki\Extension\BibleGet;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * The bibleget_notice table: each service notice this wiki has seen, once per id. Ids are
 * never edited by BibleGet (a changed notice gets a new id), so a known id is never updated.
 */
class NoticeStore {

	private const TABLE = 'bibleget_notice';

	public function __construct(
		private readonly ILoadBalancer $lb
	) {
	}

	/**
	 * Store the notices this wiki has not seen yet.
	 *
	 * @param array[] $notices [ 'id' => string, 'message' => string, 'link' => ?string, ... ]
	 * @return array[] The notices that were new, as given (with an unsafe link removed)
	 */
	public function record( array $notices ): array {
		$db = $this->db();
		$new = [];
		foreach ( $notices as $notice ) {
			$id = is_array( $notice ) ? ( $notice['id'] ?? null ) : null;
			if ( !is_string( $id ) || $id === '' || strlen( $id ) > 64 ) {
				continue;
			}
			$notice['message'] = is_string( $notice['message'] ?? null ) ? $notice['message'] : '';
			$notice['link'] = ServiceNotices::safeLink( $notice['link'] ?? null );
			// Insert-ignore: of two requests seeing a notice at once, only one inserts it.
			$db->newInsertQueryBuilder()
				->insertInto( self::TABLE )
				->ignore()
				->row( [
					'bn_id' => $id,
					'bn_message' => $notice['message'],
					'bn_link' => $notice['link'],
					'bn_first_seen' => $db->timestamp(),
				] )
				->caller( __METHOD__ )
				->execute();
			if ( $db->affectedRows() > 0 ) {
				$new[] = $notice;
			}
		}
		return $new;
	}

	/**
	 * Every stored notice, newest first.
	 *
	 * @return array[] [ 'id' => string, 'message' => string, 'link' => ?string, 'firstSeen' => string (TS_MW) ]
	 */
	public function current(): array {
		$res = $this->lb->getConnection( DB_REPLICA )->newSelectQueryBuilder()
			->select( [ 'bn_id', 'bn_message', 'bn_link', 'bn_first_seen' ] )
			->from( self::TABLE )
			->orderBy( [ 'bn_first_seen', 'bn_id' ], 'DESC' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$notices = [];
		foreach ( $res as $row ) {
			$notices[] = [
				'id' => (string)$row->bn_id,
				'message' => (string)$row->bn_message,
				'link' => ServiceNotices::safeLink( $row->bn_link ),
				'firstSeen' => wfTimestamp( TS_MW, $row->bn_first_seen ),
			];
		}
		return $notices;
	}

	/**
	 * An autocommit connection: notices are recorded while a page is parsed, often in a GET.
	 */
	private function db(): IDatabase {
		return $this->lb->getConnection( DB_PRIMARY, [], false, ILoadBalancer::CONN_TRX_AUTOCOMMIT );
	}
}
