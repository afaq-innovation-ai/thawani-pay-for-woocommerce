/**
 * Thawani Pay — settings screen helpers: environment-aware fields, connection test, copy webhook URL.
 */
( function () {
	'use strict';

	var cfg = window.thawaniPayAdmin || {};

	function field( id ) {
		return document.getElementById( 'woocommerce_thawani_' + id );
	}

	function row( input ) {
		return input ? input.closest( 'tr' ) : null;
	}

	// Show only the keys of the selected environment.
	var testmode = field( 'testmode' );
	function toggleEnv() {
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
	}
	if ( testmode ) {
		testmode.addEventListener( 'change', toggleEnv );
		toggleEnv();
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
} )();
