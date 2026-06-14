/* global flCheckout, jQuery */
jQuery( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Checkout page: highlight selected payment option
	// -------------------------------------------------------------------------
	$( document ).on( 'change', 'input[name="fl_payment_option"]', function () {
		$( '.fl-option' ).removeClass( 'fl-option-selected' );
		$( this ).closest( '.fl-option' ).addClass( 'fl-option-selected' );
	} );

	$( 'input[name="fl_payment_option"]:checked' ).trigger( 'change' );

	// -------------------------------------------------------------------------
	// Payment page: copy-to-clipboard buttons
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.fl-copy-btn', function () {
		var $btn = $( this );
		var text = String( $btn.data( 'copy' ) || '' );

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( function () { flashCopied( $btn ); } );
		} else {
			// Fallback for non-HTTPS / older browsers
			// NOTE: execCommand is deprecated; keep only as a last-resort fallback (L-3)
			var $tmp = $( '<textarea>' ).css( { position: 'fixed', opacity: 0 } ).val( text );
			$( 'body' ).append( $tmp );
			$tmp.select();
			try { document.execCommand( 'copy' ); } catch (e) { /* silent */ }
			$tmp.remove();
			flashCopied( $btn );
		}
	} );

	function flashCopied( $btn ) {
		var original = $btn.text();
		$btn.text( flCheckout.i18n.copied );
		setTimeout( function () { $btn.text( original ); }, 2000 );
	}

	// -------------------------------------------------------------------------
	// Payment page: countdown timer + AJAX polling
	// -------------------------------------------------------------------------
	var $box = $( '.fl-payment-box' );
	if ( ! $box.length ) {
		return;
	}

	var expiresAt  = parseInt( $box.data( 'expires' ), 10 ) * 1000;
	var orderId    = $box.data( 'order-id' );
	var orderKey   = $box.data( 'order-key' );
	// Per-order nonce embedded by PHP — scoped to this specific order (M-7)
	var orderNonce = $box.data( 'nonce' );
	var pollHandle, countdownHandle;
	var isResolved = false;

	// -- Countdown --
	function updateCountdown() {
		var remaining = Math.max( 0, expiresAt - Date.now() );
		var mins      = Math.floor( remaining / 60000 );
		var secs      = Math.floor( ( remaining % 60000 ) / 1000 );
		$( '#fl-timer' ).text( pad( mins ) + ':' + pad( secs ) );

		if ( remaining === 0 && ! isResolved ) {
			clearInterval( countdownHandle );
			clearInterval( pollHandle );
			setStatus( 'expired', flCheckout.i18n.expired );
		}
	}

	function pad( n ) { return n < 10 ? '0' + n : String( n ); }

	countdownHandle = setInterval( updateCountdown, 1000 );
	updateCountdown();

	// -- AJAX poll --
	function checkPayment() {
		if ( isResolved ) { return; }

		$.post( flCheckout.ajaxUrl, {
			action:    'fl_check_payment',
			nonce:     orderNonce,
			order_id:  orderId,
			order_key: orderKey,
		} )
		.done( function ( response ) {
			if ( ! response.success || ! response.data ) { return; }

			var data = response.data;

			// If crypto amount was missing at checkout, fill it in once available
			if ( data.crypto_amount && data.token_symbol ) {
				var $amountVal = $( '#fl-amount-value' );
				var $amountRow = $( '#fl-amount-row' );
				if ( $amountVal.length && $amountVal.text().indexOf( 'Calculating' ) !== -1 ) {
					var amountText = data.crypto_amount + ' ' + data.token_symbol;
					// Replace calculating placeholder with real amount + copy button
					$amountRow.html(
						'<span class="fl-amount-value" id="fl-amount-value">' + amountText + '</span>' +
						'<button type="button" class="fl-copy-btn" data-copy="' + data.crypto_amount + '">' +
						( flCheckout.i18n.copy || 'Copy' ) + '</button>'
					);
					$amountRow.removeClass( 'fl-amount-calculating' ).addClass( 'fl-copy-row' );
				}
			}

			if ( data.status === 'confirmed' ) {
				isResolved = true;
				clearInterval( pollHandle );
				clearInterval( countdownHandle );
				setStatus( 'confirmed', flCheckout.i18n.confirmed );
				// Validate redirect URL is same-origin before following it (C-1)
				setTimeout( function () { safeRedirect( data.redirect ); }, 2500 );

			} else if ( data.status === 'received' ) {
				setStatus( 'received', flCheckout.i18n.received );

			} else if ( data.status === 'expired' ) {
				isResolved = true;
				clearInterval( pollHandle );
				clearInterval( countdownHandle );
				setStatus( 'expired', flCheckout.i18n.expired );
			}
			// 'pending' and unknown statuses — no UI change needed
		} );
	}

	/**
	 * Only redirect to same-origin URLs to prevent open-redirect attacks (C-1).
	 * Falls back to the homepage if the URL is invalid or cross-origin.
	 */
	function safeRedirect( url ) {
		if ( ! url ) { return; }
		try {
			var parsed = new URL( url, window.location.origin );
			if ( parsed.origin !== window.location.origin ) {
				throw new Error( 'cross-origin redirect blocked' );
			}
			window.location.href = parsed.href;
		} catch (e) {
			window.location.href = '/';
		}
	}

	/**
	 * Update the status indicator.
	 * Status is whitelisted so no arbitrary class names can be injected (H-2).
	 */
	function setStatus( status, message ) {
		var allowed = { pending: 1, received: 1, confirmed: 1, expired: 1, error: 1 };
		if ( ! allowed[ status ] ) {
			status = 'error';
		}
		$( '.fl-status-dot' ).attr( 'class', 'fl-status-dot fl-status-' + status );
		// Use .text() — must NEVER be changed to .html() (L-4)
		$( '#fl-status-text' ).text( message );
	}

	setTimeout( checkPayment, 6000 );
	pollHandle = setInterval( checkPayment, 30000 );
} );
