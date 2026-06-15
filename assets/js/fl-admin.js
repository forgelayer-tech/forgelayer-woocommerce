/* global flAdmin, jQuery */
jQuery( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Refresh Token List
	// -------------------------------------------------------------------------
	var $tokenBtn    = $( '#fl-refresh-tokens' );
	var $tokenStatus = $( '#fl-refresh-status' );

	if ( $tokenBtn.length ) {
		$tokenBtn.on( 'click', function () {
			$tokenBtn.prop( 'disabled', true );
			$tokenStatus.text( flAdmin.i18n.refreshing );

			$.post( flAdmin.ajaxUrl, {
				action: 'fl_refresh_tokens',
				nonce:  flAdmin.tokenNonce,
			} )
			.done( function ( response ) {
				if ( response.success ) {
					var counts  = response.data.counts || {};
					var errors  = response.data.errors || {};
					var summary = [];

					$.each( counts, function ( chain, count ) {
						// chain and count come from sanitized server-side data; use .text() below
						summary.push( count + ' token(s) on ' + chain );
					} );
					$.each( errors, function ( chain, msg ) {
						summary.push( chain + ': ' + msg );
					} );

					// .text() is intentional — must NOT be changed to .html() (L-4)
					$tokenStatus.text(
						flAdmin.i18n.refreshDone + ( summary.length ? ' (' + summary.join( ', ' ) + ')' : '' )
					);

					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					$tokenStatus.text( flAdmin.i18n.refreshError );
					$tokenBtn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				$tokenStatus.text( flAdmin.i18n.refreshError );
				$tokenBtn.prop( 'disabled', false );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Setup / Re-register Webhook
	// -------------------------------------------------------------------------
	var $webhookBtn    = $( '#fl-setup-webhook' );
	var $webhookStatus = $( '#fl-webhook-status' );

	if ( $webhookBtn.length ) {
		$webhookBtn.on( 'click', function () {
			$webhookBtn.prop( 'disabled', true );
			// .text() is intentional (L-4)
			$webhookStatus.text( flAdmin.i18n.webhookRegistering );

			$.post( flAdmin.ajaxUrl, {
				action: 'fl_setup_webhook',
				nonce:  flAdmin.webhookNonce,
			} )
			.done( function ( response ) {
				if ( response.success ) {
					var id = response.data.webhook_id || '';
					// .text() is intentional — webhook_id is server-sanitized (L-4)
					$webhookStatus.text( flAdmin.i18n.webhookDone + id );
					// Reload so the button label and status field refresh
					setTimeout( function () { window.location.reload(); }, 1500 );
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : 'Unknown error';
					$webhookStatus.text( flAdmin.i18n.webhookError + msg );
					$webhookBtn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				$webhookStatus.text( flAdmin.i18n.webhookError + 'Request failed.' );
				$webhookBtn.prop( 'disabled', false );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Usage: auto-fetch on page load + manual refresh button
	// -------------------------------------------------------------------------
	var $usageGrid   = $( '#fl-usage-grid' );
	var $usageBtn    = $( '#fl-refresh-usage' );
	var $usageStatus = $( '#fl-usage-status' );

	/**
	 * Update the usage bars in place from the AJAX response data.
	 * No page reload needed.
	 */
	function applyUsageData( data ) {
		var resources = {
			addresses:   { used: ( data.usage.addressesGenerated || 0 ), limit: data.limits.addresses,   pct: ( data.percentages.addresses   || 0 ) },
			webhooks:    { used: ( data.usage.webhooksCreated    || 0 ), limit: data.limits.webhooks,    pct: ( data.percentages.webhooks    || 0 ) },
			apiRequests: { used: ( data.usage.apiRequestsMade    || 0 ), limit: data.limits.apiRequests, pct: ( data.percentages.apiRequests || 0 ) },
		};

		$.each( resources, function ( key, res ) {
			var pct       = parseInt( res.pct, 10 ) || 0;
			var unlimited = res.limit === -1;
			var color     = pct >= 100 ? '#dc2626' : ( pct >= 90 ? '#ea580c' : ( pct >= 80 ? '#d97706' : '#16a34a' ) );
			var limitTxt  = unlimited ? 'Unlimited' : res.limit.toLocaleString();

			$( '#fl-usage-count-' + key ).text( res.used.toLocaleString() + ' / ' + limitTxt );
			$( '#fl-usage-bar-'   + key ).css( { width: ( unlimited ? '4' : Math.min( 100, pct ) ) + '%', background: color } );
			$( '#fl-usage-pct-'   + key ).text( unlimited ? '' : pct + '%' ).css( 'color', color );
		} );

		if ( data.usage.resetAt ) {
			var resetDate = new Date( data.usage.resetAt );
			$( '#fl-usage-reset' ).text( 'Resets on ' + resetDate.toLocaleDateString() + '.  ' );
		}
		$( '#fl-usage-fetched' ).text( 'Last updated: just now.' );
	}

	function fetchUsage( onComplete ) {
		$.post( flAdmin.ajaxUrl, {
			action: 'fl_refresh_usage',
			nonce:  flAdmin.usageNonce,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				applyUsageData( response.data );
				$usageStatus.text( '' );
			} else {
				var msg = ( response.data && response.data.message ) ? response.data.message : '';
				$usageStatus.text( flAdmin.i18n.usageError + ( msg ? ': ' + msg : '' ) );
			}
		} )
		.fail( function () {
			$usageStatus.text( flAdmin.i18n.usageError );
		} )
		.always( function () {
			$usageBtn.prop( 'disabled', false );
			if ( typeof onComplete === 'function' ) { onComplete(); }
		} );
	}

	// Auto-fetch when the grid is present (page load)
	if ( $usageGrid.length ) {
		fetchUsage();
	}

	// Manual refresh button
	if ( $usageBtn.length ) {
		$usageBtn.on( 'click', function () {
			$usageBtn.prop( 'disabled', true );
			$usageStatus.text( flAdmin.i18n.usageRefreshing );
			fetchUsage();
		} );
	}

	// Token search filter
	var $tokenSearch = $( '#fl-token-search' );
	if ( $tokenSearch.length ) {
		$tokenSearch.on( 'input', function () {
			var q = $( this ).val().toLowerCase();
			$( '.fl-token-row' ).each( function () {
				$( this ).toggle( $( this ).text().toLowerCase().indexOf( q ) !== -1 );
			} );
		} );
	}
} );
