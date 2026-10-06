( function () {
	'use strict';

	var cfg = window.PixelForgeMedia || {};

	function request( action, id ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'id', id );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function cellFor( el ) {
		var c = el;
		while ( c && ! ( c.classList && c.classList.contains( 'pf-cell' ) ) ) {
			c = c.parentNode;
		}
		return c;
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.classList ) {
			return;
		}
		var isConvert = t.classList.contains( 'pf-convert-one' );
		var isRemove = t.classList.contains( 'pf-remove-one' );
		if ( ! isConvert && ! isRemove ) {
			return;
		}
		e.preventDefault();

		var id = t.getAttribute( 'data-id' );
		if ( ! id ) {
			return;
		}
		if ( isRemove && ! window.confirm( cfg.i18n.confirm ) ) {
			return;
		}

		var cell = cellFor( t );
		if ( cell ) {
			cell.textContent = cfg.i18n.working;
		}

		request( isConvert ? 'pixelforge_convert_one' : 'pixelforge_remove_one', id ).then( function ( res ) {
			if ( res && res.success && res.data && cell ) {
				cell.outerHTML = res.data.html;
			} else if ( cell ) {
				cell.textContent = ( res && res.data && res.data.message ) ? res.data.message : cfg.i18n.error;
			}
		} ).catch( function () {
			if ( cell ) {
				cell.textContent = cfg.i18n.error;
			}
		} );
	} );
}() );
