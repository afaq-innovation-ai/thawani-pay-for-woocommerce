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
} )();
