/**
 * Dismiss button of the BibleGet service notice banner (shown to managers when Echo is not
 * installed): adds the notice id to the user's bibleget-dismissed option and hides the notice.
 */
'use strict';

const OPTION = 'bibleget-dismissed';

function dismiss( button ) {
	const id = button.getAttribute( 'data-bibleget-notice' );
	const ids = String( mw.user.options.get( OPTION ) || '' ).split( '|' ).filter( Boolean );
	if ( ids.indexOf( id ) === -1 ) {
		ids.push( id );
	}
	button.disabled = true;
	new mw.Api().saveOption( OPTION, ids.join( '|' ) ).then( () => {
		mw.user.options.set( OPTION, ids.join( '|' ) );
		const notice = button.closest( '.bibleget-service-notice' );
		if ( notice ) {
			notice.remove();
		}
	}, () => {
		button.disabled = false;
	} );
}

document.addEventListener( 'click', ( event ) => {
	const button = event.target instanceof Element &&
		event.target.closest( '.bibleget-service-notice-dismiss' );
	if ( button ) {
		dismiss( button );
	}
} );
