<?php

namespace MediaWiki\Extension\BibleGet;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Creates the extension's tables on update.php. No service dependencies (installer context).
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$type = $updater->getDB()->getType();
		// A new wiki gets both tables from tables-generated.sql; one that has the first already
		// gets bibleget_notice from its patch.
		$updater->addExtensionTable( 'bibleget_installation', "$dir/$type/tables-generated.sql" );
		$updater->addExtensionTable( 'bibleget_notice', "$dir/$type/patch-bibleget_notice.sql" );
	}
}
