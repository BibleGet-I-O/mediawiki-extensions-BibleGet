/**
 * Places the popup of an inline Bible quote next to its reference, inside the viewport: beside
 * the reference when there is room on the right, else below it (above when there is no room
 * below), shifted left to fit. A popup taller than the viewport becomes sticky: it stays put,
 * scrolls inside and gets a close button. A tap or click outside closes open popups.
 *
 * The quotes are rendered on the server; this script only positions them and makes no requests.
 */
'use strict';

const MARGIN = 8;
const HIDE_DELAY = 500;
const hideTimers = new WeakMap();

/**
 * @param {EventTarget|null} node
 * @return {Element|null} The inline quote wrapper containing the node
 */
function inlineOf( node ) {
	return node instanceof Element ? node.closest( '.bibleget-inline' ) : null;
}

function cancelHide( wrapper ) {
	clearTimeout( hideTimers.get( wrapper ) );
	hideTimers.delete( wrapper );
}

function reset( wrapper ) {
	const popup = wrapper.querySelector( '.bibleget-popup' );
	wrapper.classList.remove( 'bibleget-open', 'bibleget-sticky' );
	if ( popup ) {
		popup.classList.remove( 'bibleget-placed' );
		popup.style.top = '';
		popup.style.left = '';
	}
}

function dismiss( wrapper ) {
	cancelHide( wrapper );
	reset( wrapper );
	wrapper.classList.add( 'bibleget-dismissed' );
	if ( wrapper.contains( document.activeElement ) ) {
		document.activeElement.blur();
	}
}

function addCloseButton( wrapper, popup ) {
	if ( popup.querySelector( '.bibleget-close' ) ) {
		return;
	}
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'bibleget-close';
	button.textContent = '×';
	button.setAttribute( 'aria-label', mw.msg( 'bibleget-popup-close' ) );
	button.addEventListener( 'click', ( ev ) => {
		ev.stopPropagation();
		dismiss( wrapper );
	} );
	popup.insertBefore( button, popup.firstChild );
}

/**
 * @param {Element} wrapper
 */
function place( wrapper ) {
	const ref = wrapper.querySelector( '.bibleget-ref' );
	const popup = wrapper.querySelector( '.bibleget-popup' );
	if ( !ref || !popup ) {
		return;
	}
	const vw = document.documentElement.clientWidth;
	const vh = window.innerHeight;

	wrapper.classList.remove( 'bibleget-dismissed' );
	wrapper.classList.add( 'bibleget-open' );
	popup.classList.add( 'bibleget-placed' );
	popup.classList.remove( 'bibleget-tall' );
	popup.style.top = '0';
	popup.style.left = '0';
	popup.style.maxWidth = ( vw - 2 * MARGIN ) + 'px';

	const width = popup.offsetWidth;
	const height = popup.offsetHeight;
	const r = ref.getBoundingClientRect();
	let left = r.right + 15;
	let top = r.top;

	if ( left + width > vw - MARGIN ) {
		left = Math.max( MARGIN, Math.min( r.left, vw - width - MARGIN ) );
		top = r.bottom + 6;
	}
	if ( height > vh - 2 * MARGIN ) {
		wrapper.classList.add( 'bibleget-sticky' );
		addCloseButton( wrapper, popup );
		top = MARGIN;
	} else {
		wrapper.classList.remove( 'bibleget-sticky' );
		if ( top + height > vh - MARGIN ) {
			top = r.top - height - 6 >= MARGIN ?
				r.top - height - 6 :
				Math.max( MARGIN, vh - height - MARGIN );
		}
	}
	popup.style.top = top + 'px';
	popup.style.left = left + 'px';
}

function scheduleHide( wrapper ) {
	cancelHide( wrapper );
	if ( wrapper.classList.contains( 'bibleget-sticky' ) ) {
		return;
	}
	hideTimers.set( wrapper, setTimeout( () => {
		if ( !wrapper.matches( ':hover' ) && !wrapper.matches( ':focus-within' ) ) {
			reset( wrapper );
		}
	}, HIDE_DELAY ) );
}

function reposition() {
	Array.from( document.querySelectorAll( '.bibleget-inline.bibleget-open:not(.bibleget-sticky)' ) )
		.forEach( place );
}

document.addEventListener( 'mouseover', ( ev ) => {
	const wrapper = inlineOf( ev.target );
	if ( wrapper && wrapper !== inlineOf( ev.relatedTarget ) ) {
		cancelHide( wrapper );
		place( wrapper );
	}
} );

document.addEventListener( 'mouseout', ( ev ) => {
	const wrapper = inlineOf( ev.target );
	if ( wrapper && wrapper !== inlineOf( ev.relatedTarget ) ) {
		scheduleHide( wrapper );
	}
} );

document.addEventListener( 'focusin', ( ev ) => {
	const wrapper = inlineOf( ev.target );
	if ( wrapper && !wrapper.classList.contains( 'bibleget-open' ) ) {
		cancelHide( wrapper );
		place( wrapper );
	}
} );

document.addEventListener( 'focusout', ( ev ) => {
	const wrapper = inlineOf( ev.target );
	if ( wrapper && wrapper !== inlineOf( ev.relatedTarget ) ) {
		scheduleHide( wrapper );
	}
} );

// A tap outside closes the open popups (touch screens have no mouseleave).
document.addEventListener( 'click', ( ev ) => {
	const own = inlineOf( ev.target );
	const open = document.querySelectorAll( '.bibleget-inline.bibleget-open' );
	Array.from( open ).forEach( ( wrapper ) => {
		if ( wrapper !== own ) {
			dismiss( wrapper );
		}
	} );
} );

document.addEventListener( 'keydown', ( ev ) => {
	if ( ev.key === 'Escape' ) {
		const open = document.querySelectorAll( '.bibleget-inline.bibleget-open' );
		Array.from( open ).forEach( dismiss );
	}
} );

window.addEventListener( 'resize', reposition );
window.addEventListener( 'scroll', reposition, { passive: true } );
