<?php

namespace MediaWiki\Extension\BibleGet\Notifications;

use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The wiki's BibleGet managers: members of the groups that hold the bibleget-manage right.
 * Implicit groups (*, user, autoconfirmed…) are not listed: their members are not enumerable.
 */
class ManagerLocator {

	public const RIGHT = 'bibleget-manage';

	public function __construct(
		private readonly GroupPermissionsLookup $groupPermissions,
		private readonly UserGroupManager $userGroupManager,
		private readonly IConnectionProvider $dbProvider,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly UserFactory $userFactory
	) {
	}

	/**
	 * @return User[] Users (not bare identities): Echo drops anything else
	 */
	public function locate(): array {
		$groups = array_values( array_diff(
			$this->groupPermissions->getGroupsWithPermission( self::RIGHT ),
			$this->userGroupManager->listAllImplicitGroups()
		) );
		if ( $groups === [] ) {
			return [];
		}
		$dbr = $this->dbProvider->getReplicaDatabase();
		$ids = $dbr->newSelectQueryBuilder()
			->select( 'ug_user' )
			->distinct()
			->from( 'user_groups' )
			->where( [
				'ug_group' => $groups,
				$dbr->expr( 'ug_expiry', '=', null )->or( 'ug_expiry', '>', $dbr->timestamp() ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( $ids === [] ) {
			return [];
		}
		$users = [];
		$identities = $this->userIdentityLookup->newSelectQueryBuilder()
			->whereUserIds( array_map( 'intval', $ids ) )
			->caller( __METHOD__ )
			->fetchUserIdentities();
		foreach ( $identities as $identity ) {
			$users[] = $this->userFactory->newFromUserIdentity( $identity );
		}
		return $users;
	}

	/**
	 * Echo's user locator for bibleget-service-notice (called with the event, which is not needed).
	 *
	 * @return User[]
	 */
	public static function locateForEcho(): array {
		/** @var ManagerLocator $locator */
		$locator = MediaWikiServices::getInstance()->getService( 'BibleGet.ManagerLocator' );
		return $locator->locate();
	}
}
