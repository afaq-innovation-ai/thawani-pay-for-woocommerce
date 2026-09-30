/**
 * Thawani Pay — admin interactions: settings navigation, environment-aware fields,
 * connection test, copy webhook URL, sticky save bar and transactions filters.
 */
( function () {
	'use strict';

	var cfg = window.thawaniPayAdmin || { i18n: {} };

	function field( id ) {
		return document.getElementById( 'woocommerce_thawani_' + id );
	}

	function row( input ) {
		return input ? input.closest( 'tr' ) : null;
	}

	/* ------------------------------------------------------------------ settings */

	var settings = document.querySelector( '.tp-settings' );

	if ( settings ) {
		// Show only the keys of the selected environment.
		var testmode = field( 'testmode' );
		var toggleEnv = function () {
			if ( ! testmode ) {
				return;
			}
			var sandbox = testmode.checked;
			[ 'test_secret_key', 'test_publishable_key', 'test_webhook_secret' ].forEach( function ( id ) {
				var r = row( field( id ) );
				if ( r ) {
					r.style.display = sandbox ? '' : 'none';
				}
			} );
			[ 'live_secret_key', 'live_publishable_key', 'live_webhook_secret' ].forEach( function ( id ) {
				var r = row( field( id ) );
				if ( r ) {
					r.style.display = sandbox ? 'none' : '';
				}
			} );
			document.querySelectorAll( '.thawani-pay-test' ).forEach( function ( b ) {
				b.style.display = ( b.getAttribute( 'data-mode' ) === 'test' ) === sandbox ? '' : 'none';
			} );
		};
		if ( testmode ) {
			testmode.addEventListener( 'change', toggleEnv );
			toggleEnv();
		}

		// Sticky save bar.
		var submit = document.querySelector( '#mainform p.submit, form p.submit' );
		var sections = document.querySelector( '.tp-sections' );
		if ( submit && sections ) {
			var bar = document.createElement( 'div' );
			bar.className = 'tp-savebar';
			bar.innerHTML = '<span class="tp-savebar__hint"></span>';
			bar.querySelector( '.tp-savebar__hint' ).textContent = cfg.i18n.unsaved || '';
			bar.querySelector( '.tp-savebar__hint' ).style.visibility = 'hidden';
			bar.appendChild( submit );
			sections.appendChild( bar );
			settings.addEventListener( 'input', function () {
				bar.querySelector( '.tp-savebar__hint' ).style.visibility = 'visible';
			} );
		}

		// Section navigation highlight.
		var links = Array.prototype.slice.call( document.querySelectorAll( '.tp-nav__link' ) );
		var cards = links.map( function ( a ) {
			return document.querySelector( a.getAttribute( 'href' ) );
		} );
		var setActive = function () {
			var current = 0;
			cards.forEach( function ( card, i ) {
				if ( card && card.getBoundingClientRect().top < 140 ) {
					current = i;
				}
			} );
			links.forEach( function ( a, i ) {
				a.classList.toggle( 'is-active', i === current );
			} );
		};
		window.addEventListener( 'scroll', setActive, { passive: true } );
		setActive();
		links.forEach( function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				var target = document.querySelector( a.getAttribute( 'href' ) );
				if ( target ) {
					e.preventDefault();
					target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} );
		} );
	}

	// Connection test.
	document.querySelectorAll( '.thawani-pay-test' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var mode = button.getAttribute( 'data-mode' );
			var out = document.querySelector( '.thawani-pay-test-result' );
			var secret = field( mode + '_secret_key' );
			var pub = field( mode + '_publishable_key' );
			var body = new URLSearchParams();
			body.append( 'action', 'thawani_pay_test_connection' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'mode', mode );
			body.append( 'secret', secret ? secret.value : '' );
			body.append( 'publishable', pub ? pub.value : '' );

			out.className = 'thawani-pay-test-result is-loading';
			out.textContent = cfg.i18n.testing;
			button.disabled = true;

			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					out.className = 'thawani-pay-test-result ' + ( res.success ? 'is-ok' : 'is-error' );
					out.textContent = ( res.data && res.data.message ) || cfg.i18n.failed;
					var tile = document.querySelector( '[data-tile="connection"]' );
					if ( tile ) {
						tile.className = 'tp-tile tp-tile--' + ( res.success ? 'ok' : 'error' );
						tile.querySelector( '.tp-tile__value' ).textContent = res.success ? cfg.i18n.connected : cfg.i18n.notConnected;
					}
				} )
				.catch( function () {
					out.className = 'thawani-pay-test-result is-error';
					out.textContent = cfg.i18n.failed;
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );

	// Copy webhook URL.
	document.querySelectorAll( '.thawani-pay-copy__btn' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var input = document.getElementById( button.getAttribute( 'data-target' ) );
			if ( ! input ) {
				return;
			}
			input.select();
			( navigator.clipboard ? navigator.clipboard.writeText( input.value ) : Promise.resolve( document.execCommand( 'copy' ) ) ).then( function () {
				var label = button.textContent;
				button.textContent = cfg.i18n.copied + ' ✓';
				setTimeout( function () {
					button.textContent = label;
				}, 1600 );
			} );
		} );
	} );

	/* -------------------------------------------------------------- transactions */

	var table = document.querySelector( '.tp-table' );
	if ( table ) {
		var chips = document.querySelectorAll( '.tp-chip' );
		var search = document.querySelector( '.tp-search' );
		var empty = document.querySelector( '.tp-empty' );
		var status = '';

		var apply = function () {
			var q = ( search && search.value ? search.value : '' ).toLowerCase().trim();
			var visible = 0;
			table.querySelectorAll( 'tbody tr' ).forEach( function ( tr ) {
				var show = ( ! status || tr.getAttribute( 'data-status' ) === status ) && ( ! q || tr.getAttribute( 'data-search' ).indexOf( q ) !== -1 );
				tr.hidden = ! show;
				visible += show ? 1 : 0;
			} );
			if ( empty ) {
				empty.hidden = visible > 0;
			}
		};

		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				chips.forEach( function ( c ) {
					c.classList.remove( 'is-active' );
				} );
				chip.classList.add( 'is-active' );
				status = chip.getAttribute( 'data-filter' );
				apply();
			} );
		} );
		if ( search ) {
			search.addEventListener( 'input', apply );
		}
	}
} )();
