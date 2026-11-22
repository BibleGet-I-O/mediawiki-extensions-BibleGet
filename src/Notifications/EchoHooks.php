<?php

namespace MediaWiki\Extension\BibleGet\Notifications;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;

/**
 * Defines the bibleget category and the bibleget-service-notice event. Echo 1.43 reads
 * notification definitions only from this hook, which only Echo runs.
 */
class EchoHooks implements BeforeCreateEchoEventHook {

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications, array &$notificationCategories, array &$notificationIcons
	) {
		$notificationCategories['bibleget'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-bibleget',
		];
		$notifications[NoticeNotifier::EVENT_TYPE] = [
			'category' => 'bibleget',
			'group' => 'neutral',
			'section' => 'alert',
			'presentation-model' => ServiceNoticePresentationModel::class,
			// A string: Echo reads an array entry as [ callable, options ]
			AttributeManager::ATTR_LOCATORS => [ ManagerLocator::class . '::locateForEcho' ],
		];
	}
}
