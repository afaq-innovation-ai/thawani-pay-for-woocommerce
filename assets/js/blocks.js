/**
 * Thawani Pay — WooCommerce Cart & Checkout blocks integration.
 * Plain ES5 with WordPress globals, so no build step is required.
 */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settings ) {
		return;
	}

	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var __ = window.wp.i18n.__;
	var data = settings.getSetting( 'thawani_data', {} );
	var title = decode( data.title || __( 'Thawani Pay', 'thawani-pay-for-woocommerce' ) );

	function Icons() {
		if ( ! data.icons || ! data.icons.length ) {
			return null;
		}
		return el(
			'span',
			{ className: 'thawani-pay-icons' },
			data.icons.map( function ( icon ) {
				return el( 'img', { key: icon.id, src: icon.src, alt: icon.alt, width: 38, height: 24 } );
			} )
		);
	}

	function Label( props ) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		return el(
			'span',
			{ className: 'thawani-pay-label' },
			el( PaymentMethodLabel, { text: title } ),
			el( Icons )
		);
	}

	function Content() {
		return el(
			'div',
			{ className: 'thawani-pay-blocks' },
			data.testMode
				? el(
						'p',
						{ className: 'thawani-pay-test-notice' },
						__( 'Sandbox mode — use test card 4242 4242 4242 4242, any future expiry, any CVV and OTP 1234.', 'thawani-pay-for-woocommerce' )
				  )
				: null,
			data.description ? el( 'p', null, decode( data.description ) ) : null,
			el(
				'p',
				{ className: 'thawani-pay-secure' },
				el( 'span', { 'aria-hidden': 'true' }, '🔒 ' ),
				__( 'Card details are entered on the secure Thawani payment page.', 'thawani-pay-for-woocommerce' )
			)
		);
	}

	registry.registerPaymentMethod( {
		name: 'thawani',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: data.supports || [ 'products' ],
			showSavedCards: !! data.showSavedCards,
			showSaveOption: !! data.showSaveOption,
		},
	} );
	/*
	 * Saved Thawani cards: WooCommerce renders them as plain text radio options.
	 * Decorate each option with a mini bank card (the native radio stays in place,
	 * so selection and payment keep working exactly as before).
	 */
	var cards = data.cards || {};
	var logos = {
		visa: '<svg viewBox="0 0 64 22" width="46" height="16" aria-hidden="true"><text x="0" y="19" text-anchor="start" font-family="Arial Black,Arial,sans-serif" font-size="21" font-style="italic" font-weight="900" fill="#fff">VISA</text></svg>',
		mastercard: '<svg viewBox="0 0 46 28" width="36" height="22" aria-hidden="true"><circle cx="16" cy="14" r="12" fill="#eb001b"/><circle cx="30" cy="14" r="12" fill="#f79e1b"/><path d="M23 4.3a12 12 0 0 1 0 19.4 12 12 0 0 1 0-19.4z" fill="#ff5f00"/></svg>',
		card: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#fff" stroke-width="1.8" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
	};

	function esc( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	}

	function miniCard( card ) {
		var el = document.createElement( 'span' );
		el.className = 'tp-mini tp-cc tp-cc--' + card.brand + ( card.expired ? ' is-expired' : '' );
		el.setAttribute( 'aria-hidden', 'true' );
		el.innerHTML =
			'<span class="tp-mini__top"><span class="tp-mini__title"><span class="tp-mini__check"></span><span class="tp-mini__name">' + esc( card.name || card.brandLabel ) + '</span></span><span class="tp-cc__brand">' + ( logos[ card.brand ] || logos.card ) + '</span></span>' +
			'<span class="tp-cc__chip"><span></span></span>' +
			'<span class="tp-mini__number" dir="ltr">•••• ' + esc( card.last4 ) + '</span>' +
			'<span class="tp-mini__bottom"><span dir="ltr">' + esc( card.expiry ) + '</span><span>' + esc( card.funding ) + '</span></span>';
		return el;
	}

	function decorate() {
		var inputs = document.querySelectorAll( 'input[name="radio-control-wc-payment-method-saved-tokens"]' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			var card = cards[ input.value ];
			var option = input.closest( 'label' );
			if ( ! card || ! option ) {
				return;
			}
			option.classList.add( 'tp-token-option' );
			if ( option.parentElement ) {
				option.parentElement.classList.add( 'tp-card-picker' );
			}
			var layout = option.querySelector( '.wc-block-components-radio-control__option-layout' ) || option;
			if ( ! layout.querySelector( '.tp-mini' ) ) {
				layout.appendChild( miniCard( card ) );
			}
		} );
	}

	if ( Object.keys( cards ).length && window.MutationObserver ) {
		var pending = false;
		new MutationObserver( function () {
			if ( pending ) {
				return;
			}
			pending = true;
			window.requestAnimationFrame( function () {
				pending = false;
				decorate();
			} );
		} ).observe( document.body, { childList: true, subtree: true } );
		decorate();
	}
} )();
