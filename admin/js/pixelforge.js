( function () {
	'use strict';

	var cfg = window.PixelForge || {};
	var running = false;
	var total = 0; // total convertible (done + pending), fixed for a run

	function $( id ) {
		return document.getElementById( id );
	}

	function request( action, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				var v = extra[ k ];
				if ( Array.isArray( v ) ) {
					v.forEach( function ( item ) {
						body.append( k + '[]', item );
					} );
				} else {
					body.append( k, v );
				}
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function num( n ) {
		return ( n || 0 ).toLocaleString();
	}

	function selectedFormats() {
		var f = [];
		if ( $( 'pf-fmt-webp' ) && $( 'pf-fmt-webp' ).checked ) {
			f.push( 'webp' );
		}
		if ( $( 'pf-fmt-avif' ) && $( 'pf-fmt-avif' ).checked ) {
			f.push( 'avif' );
		}
		return f;
	}

	function applyStats( s, pending ) {
		if ( ! s ) {
			return;
		}
		$( 'pf-stat-images' ).textContent = num( s.images );
		$( 'pf-stat-files' ).textContent = num( s.files );
		if ( typeof pending === 'number' ) {
			$( 'pf-stat-pending' ).textContent = num( pending );
		}
		var primary = s.bytes_webp > 0 ? s.bytes_webp : s.bytes_avif;
		if ( s.bytes_source > 0 && primary > 0 ) {
			var pct = Math.round( ( 1 - primary / s.bytes_source ) * 100 );
			$( 'pf-stat-saved' ).textContent = pct + '%';
		} else {
			$( 'pf-stat-saved' ).textContent = '—';
		}
	}

	function setBar( done ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		$( 'pf-bar' ).style.width = pct + '%';
	}

	function log( line, isErr ) {
		var row = document.createElement( 'div' );
		if ( isErr ) {
			row.className = 'pf-err';
		}
		row.textContent = line;
		var box = $( 'pf-log' );
		box.insertBefore( row, box.firstChild );
	}

	function nextBatch() {
		if ( ! running ) {
			return;
		}
		request( 'pixelforge_convert_batch' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				var msg = res && res.data && res.data.message ? res.data.message : cfg.i18n.errorNo;
				$( 'pf-status' ).textContent = msg;
				stop();
				return;
			}
			var d = res.data;
			applyStats( d.stats, d.pending );
			setBar( d.done );

			( d.log || [] ).forEach( function ( item ) {
				if ( item.errors && item.errors.length ) {
					log( item.title + ' — ' + item.errors.join( '; ' ), true );
				} else {
					log( item.title + ' — ' + item.created + ' file(s)' );
				}
			} );

			if ( d.pending > 0 && d.processed > 0 ) {
				$( 'pf-status' ).textContent = cfg.i18n.converting + ' ' + num( d.done ) + ' / ' + num( total );
				nextBatch();
			} else {
				$( 'pf-status' ).textContent = cfg.i18n.done;
				stop();
			}
		} ).catch( function () {
			$( 'pf-status' ).textContent = cfg.i18n.errorNo;
			stop();
		} );
	}

	function start() {
		if ( running ) {
			return;
		}
		running = true;
		$( 'pf-start' ).disabled = true;
		$( 'pf-stop' ).disabled = false;
		request( 'pixelforge_status' ).then( function ( res ) {
			if ( res && res.success ) {
				total = res.data.done + res.data.pending;
			}
			$( 'pf-status' ).textContent = cfg.i18n.converting;
			nextBatch();
		} );
	}

	function stop() {
		running = false;
		$( 'pf-start' ).disabled = false;
		$( 'pf-stop' ).disabled = true;
		if ( $( 'pf-status' ).textContent === cfg.i18n.converting ) {
			$( 'pf-status' ).textContent = cfg.i18n.stopped;
		}
	}

	function saveSettings() {
		var msg = $( 'pf-save-msg' );
		msg.textContent = '';
		request( 'pixelforge_save_settings', {
			formats: selectedFormats(),
			quality: $( 'pf-quality' ).value,
			serve: $( 'pf-serve' ).checked ? 1 : 0
		} ).then( function ( res ) {
			msg.textContent = res && res.success ? cfg.i18n.saved : cfg.i18n.errorNo;
			setTimeout( function () {
				msg.textContent = '';
			}, 2500 );
		} );
	}

	function rollbackStep() {
		request( 'pixelforge_rollback_batch' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				$( 'pf-rollback-msg' ).textContent = cfg.i18n.errorNo;
				$( 'pf-rollback' ).disabled = false;
				return;
			}
			var d = res.data;
			if ( d.remaining > 0 ) {
				$( 'pf-rollback-msg' ).textContent = cfg.i18n.rollbackRun;
				rollbackStep();
			} else {
				$( 'pf-rollback-msg' ).textContent = cfg.i18n.rollbackEnd;
				$( 'pf-rollback' ).disabled = false;
				applyStats( d.stats || { images: 0, files: 0, bytes_source: 0, bytes_webp: 0, bytes_avif: 0 }, d.pending );
				$( 'pf-stat-images' ).textContent = '0';
				$( 'pf-stat-files' ).textContent = '0';
				$( 'pf-stat-saved' ).textContent = '—';
				setBar( 0 );
			}
		} );
	}

	function rollback() {
		if ( ! window.confirm( cfg.i18n.confirmRoll ) ) {
			return;
		}
		$( 'pf-rollback' ).disabled = true;
		$( 'pf-rollback-msg' ).textContent = cfg.i18n.rollbackRun;
		rollbackStep();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! $( 'pf-start' ) ) {
			return;
		}

		var q = $( 'pf-quality' );
		if ( q ) {
			q.addEventListener( 'input', function () {
				$( 'pf-quality-out' ).textContent = q.value;
			} );
		}
		$( 'pf-save' ).addEventListener( 'click', saveSettings );
		$( 'pf-start' ).addEventListener( 'click', start );
		$( 'pf-stop' ).addEventListener( 'click', stop );
		$( 'pf-rollback' ).addEventListener( 'click', rollback );

		request( 'pixelforge_status' ).then( function ( res ) {
			if ( res && res.success ) {
				applyStats( res.data.stats, res.data.pending );
			}
		} );
	} );
}() );
