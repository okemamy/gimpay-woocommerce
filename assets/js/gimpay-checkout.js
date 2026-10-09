/* global Lightbox, gimpayParams */
( function () {
	'use strict';

	var params = window.gimpayParams;
	var root, message, payButton;
	var busy = false;

	function setMessage( text, isError ) {
		message.textContent = text;
		message.className = 'gimpay-message' + ( isError ? ' woocommerce-error' : '' );
	}

	function post( event, data ) {
		return fetch( params.callbackUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				event: event,
				order_id: params.orderId,
				order_key: params.orderKey,
				nonce: params.nonce,
				data: data || {}
			} )
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// Chaque tentative utilise une nouvelle référence signée : on recharge la page.
	function retry() {
		payButton.disabled = true;
		window.location.reload();
	}

	function onComplete( data ) {
		if ( busy ) {
			return;
		}
		busy = true;
		Lightbox.Checkout.closeLightbox();
		payButton.disabled = true;
		setMessage( params.i18n.verifying, false );

		post( 'complete', data )
			.then( function ( result ) {
				if ( result && result.success && result.data.redirect ) {
					window.location.href = result.data.redirect;
					return;
				}
				busy = false;
				setMessage( ( result && result.data && result.data.message ) || params.i18n.network, true );
			} )
			.catch( function () {
				busy = false;
				setMessage( params.i18n.network, true );
			} );
	}

	function onError( error ) {
		Lightbox.Checkout.closeLightbox();
		var reason = ( error && ( error.error || error.ErrorMessage ) ) || '';
		setMessage( params.i18n.error + ( reason ? ' : ' + reason : '.' ), true );
		payButton.disabled = false;
		payButton.onclick = retry;
		post( 'error', error ).catch( function () {} );
	}

	function onCancel() {
		if ( busy ) {
			return;
		}
		setMessage( params.i18n.cancelled, false );
		payButton.disabled = false;
		payButton.onclick = retry;
	}

	function open() {
		var config = Object.assign( {}, params.config, {
			completeCallback: onComplete,
			errorCallback: onError,
			cancelCallback: onCancel
		} );
		Lightbox.Checkout.configure = config;
		Lightbox.Checkout.showLightbox();
	}

	function init() {
		root = document.getElementById( 'gimpay-payment' );
		if ( ! root || ! params ) {
			return;
		}
		message = root.querySelector( '.gimpay-message' );
		payButton = root.querySelector( '.gimpay-pay' );

		if ( typeof Lightbox === 'undefined' || ! Lightbox.Checkout ) {
			setMessage( params.i18n.loadError, true );
			payButton.disabled = false;
			payButton.onclick = retry;
			return;
		}

		payButton.disabled = false;
		payButton.onclick = open;
		open();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
