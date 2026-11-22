<?php

namespace MediaWiki\Extension\BibleGet;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * The one-row bibleget_installation table. Only CredentialProvider uses it: the token is secret.
 */
class InstallationStore {

	public const LOCK_NAME = 'bibleget-register';
	private const TABLE = 'bibleget_installation';
	private const ROW_ID = 1;
	private const LOCK_TIMEOUT = 10;

	public function __construct(
		private readonly ILoadBalancer $lb
	) {
	}

	/**
	 * Read the installation from the primary database. The connection autocommits, so a
	 * read after the lock sees what another request committed before releasing it.
	 *
	 * @return array|null [ 'installId' => string, 'token' => ?string, 'installationId' => ?string ]
	 */
	public function read(): ?array {
		$row = $this->db()->newSelectQueryBuilder()
			->select( [ 'bi_install_id', 'bi_token', 'bi_installation_id' ] )
			->from( self::TABLE )
			->where( [ 'bi_id' => self::ROW_ID ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return [
			'installId' => (string)$row->bi_install_id,
			'token' => $row->bi_token === null || $row->bi_token === '' ? null : (string)$row->bi_token,
			'installationId' => $row->bi_installation_id === null ? null : (string)$row->bi_installation_id,
		];
	}

	/**
	 * Create the row with a new install_id, unless a row already exists.
	 */
	public function createInstallId( string $installId ): void {
		$this->db()->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->ignore()
			->row( [ 'bi_id' => self::ROW_ID, 'bi_install_id' => $installId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function saveToken( string $token, ?string $installationId ): void {
		$db = $this->db();
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'bi_token' => $token,
				'bi_installation_id' => $installationId,
				'bi_registered_at' => $db->timestamp(),
			] )
			->where( [ 'bi_id' => self::ROW_ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Take the registration lock, waiting for another request that holds it.
	 */
	public function lock(): bool {
		return (bool)$this->db()->lock( self::LOCK_NAME, __METHOD__, self::LOCK_TIMEOUT );
	}

	public function unlock(): void {
		$this->db()->unlock( self::LOCK_NAME, __METHOD__ );
	}

	/**
	 * An autocommit connection, so the row is committed before the lock is released even
	 * inside a web request's implicit transaction.
	 */
	private function db(): IDatabase {
		return $this->lb->getConnection( DB_PRIMARY, [], false, ILoadBalancer::CONN_TRX_AUTOCOMMIT );
	}
}
