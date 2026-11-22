<?php

namespace MediaWiki\Extension\BibleGet\Notifications;

use MediaWiki\Extension\BibleGet\ServiceNotices;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;

/**
 * An Echo notification for a BibleGet service notice. The notice text is a plain-text
 * parameter (escaped by Echo); only an https link becomes the primary link.
 */
class ServiceNoticePresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function canRender() {
		return (string)$this->event->getExtraParam( 'notice-id', '' ) !== '';
	}

	/** @inheritDoc */
	public function getIconType() {
		return 'site';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		$text = ServiceNotices::text( [
			'id' => (string)$this->event->getExtraParam( 'notice-id', '' ),
			'message' => (string)$this->event->getExtraParam( 'message', '' ),
		], $this );
		return $this->msg( 'notification-header-bibleget-service-notice' )->plaintextParams( $text );
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		$link = ServiceNotices::safeLink( $this->event->getExtraParam( 'link' ) );
		return $link === null ? false : [
			'url' => $link,
			'label' => $this->msg( 'bibleget-notice-link' )->text(),
		];
	}
}
