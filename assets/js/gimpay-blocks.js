/* Registers GIM Pay in the WooCommerce block-based checkout (no build step). */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settingsApi ) {
		return;
	}

	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var settings = settingsApi.getSetting( 'gimpay_data', {} );
	var title = decode( settings.title || 'GIM Pay' );

	var Content = function () {
		return el( 'div', null, decode( settings.description || '' ) );
	};

	var Label = function ( props ) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		if ( ! settings.icon ) {
			return el( PaymentMethodLabel, { text: title } );
		}
		return el(
			'span',
			{ style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%', gap: '8px' } },
			el( PaymentMethodLabel, { text: title } ),
			el( 'img', { src: settings.icon, alt: 'GIM-UEMOA', width: 54, height: 32, style: { height: '32px', width: 'auto' } } )
		);
	};

	registry.registerPaymentMethod( {
		name: 'gimpay',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
} )();
