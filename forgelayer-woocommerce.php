<?php
/**
 * Plugin Name: ForgeLayer Crypto Payments for WooCommerce
 * Plugin URI:  https://github.com/forgelayer-tech/forgelayer-woocommerce
 * Description: Accept Bitcoin, Ethereum, BSC, and Tron cryptocurrency payments at checkout via ForgeLayer.
 * Version:     1.1.1
 * Author:      ForgeLayer
 * Author URI:  https://forgelayer.io
 * Text Domain: forgelayer-crypto-payments-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 9.9
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'FL_PLUGIN_VERSION', '1.1.1' );
define( 'FL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Safe logging wrapper — works before WooCommerce logger is initialised.
 *
 * @param string $message
 * @param array  $context
 */
function fl_log_warning( $message, $context = array() ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->warning( $message, array_merge( array( 'source' => 'forgelayer' ), $context ) );
	} else {
		error_log( '[ForgeLayer] ' . $message );
	}
}

// Boot after all plugins are loaded so WooCommerce classes exist
add_action( 'plugins_loaded', 'fl_init_plugin' );
function fl_init_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p>'
				. esc_html__( 'ForgeLayer Payments requires WooCommerce to be installed and active.', 'forgelayer-crypto-payments-for-woocommerce' )
				. '</p></div>';
		} );
		return;
	}

	require_once FL_PLUGIN_DIR . 'includes/class-fl-api.php';
	require_once FL_PLUGIN_DIR . 'includes/class-fl-price.php';
	require_once FL_PLUGIN_DIR . 'includes/class-fl-gateway.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'FL_Gateway';
		return $gateways;
	} );

	// Refresh price cache immediately when the store currency changes
	add_action( 'update_option_woocommerce_currency', function ( $old_currency, $new_currency ) {
		if ( $old_currency === $new_currency ) {
			return;
		}
		$new = strtolower( $new_currency );
		if ( FL_Price::is_currency_supported( $new ) ) {
			FL_Price::refresh_cache( $new );
		}
		// Reschedule cron so it continues refreshing in the new currency
		fl_reschedule_price_cron();
	}, 10, 2 );

	// Blocks checkout sends payment data via REST — map it into $_POST so
	// process_payment() can read fl_payment_option the same way as classic checkout
	add_filter( 'woocommerce_blocks_payment_method_data', function ( $payment_data, $payment_method ) {
		if ( $payment_method === 'forgelayer' && ! empty( $payment_data['fl_payment_option'] ) ) {
			$_POST['fl_payment_option'] = sanitize_text_field( $payment_data['fl_payment_option'] );
		}
		return $payment_data;
	}, 10, 2 );
}

// -------------------------------------------------------------------------
// REST API: webhook receiver
// Registered here — not in the gateway constructor — so the route is always
// available regardless of whether WooCommerce has lazy-loaded the gateway.
// -------------------------------------------------------------------------
add_action( 'rest_api_init', function () {
	register_rest_route( 'forgelayer/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'fl_rest_webhook_handler',
		'permission_callback' => '__return_true',
	) );
} );

/**
 * Rate-limit the webhook REST endpoint: >20 requests/min from a single IP
 * (that is not ForgeLayer's known UA pattern) triggers a 429 rejection.
 *
 * @param  string $ip
 * @return bool  true = request allowed, false = too many requests
 */
function fl_rest_webhook_handler( $request ) {
	// Webhook security relies entirely on HMAC-SHA256 signature verification —
	// not IP rate limiting. An attacker cannot forge a valid signature, so there
	// is nothing to gain from rate limiting here. IP-based limits would block
	// ForgeLayer's own delivery servers during high-volume events (flash sales,
	// bulk confirmations) causing missed payment confirmations.
	$gateway = new FL_Gateway();
	return $gateway->handle_webhook_request( $request );
}

// -------------------------------------------------------------------------
// WP_DEBUG API key warning
// -------------------------------------------------------------------------
add_action( 'admin_notices', 'fl_debug_api_key_warning' );
function fl_debug_api_key_warning() {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$settings = get_option( 'woocommerce_forgelayer_settings', array() );
	if ( ! empty( $settings['api_key'] ) ) {
		echo '<div class="notice notice-warning"><p>'
			. '<strong>' . esc_html__( 'ForgeLayer Security Warning:', 'forgelayer-crypto-payments-for-woocommerce' ) . '</strong> '
			. esc_html__( 'WP_DEBUG is enabled. Your ForgeLayer API key is stored as plaintext in the database and may appear in debug logs. Disable WP_DEBUG on production sites.', 'forgelayer-crypto-payments-for-woocommerce' )
			. '</p></div>';
	}
}

// Declare WooCommerce HPOS + Cart/Checkout Blocks compatibility
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Register blocks payment integration
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once FL_PLUGIN_DIR . 'includes/class-fl-gateway-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new FL_Gateway_Blocks() );
	} );
} );

// -------------------------------------------------------------------------
// Admin assets — settings page only
// -------------------------------------------------------------------------
add_action( 'admin_enqueue_scripts', 'fl_enqueue_admin_assets' );
function fl_enqueue_admin_assets( $hook ) {
	// Only load on WooCommerce payment settings page
	if ( $hook !== 'woocommerce_page_wc-settings' ) {
		return;
	}
	if ( ! isset( $_GET['section'] ) || $_GET['section'] !== 'forgelayer' ) {
		return;
	}

	wp_enqueue_script(
		'fl-admin',
		FL_PLUGIN_URL . 'assets/js/fl-admin.js',
		array( 'jquery' ),
		FL_PLUGIN_VERSION,
		true
	);

	wp_localize_script( 'fl-admin', 'flAdmin', array(
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'tokenNonce'    => wp_create_nonce( 'fl_refresh_tokens' ),
		'webhookNonce'  => wp_create_nonce( 'fl_setup_webhook' ),
		'usageNonce'    => wp_create_nonce( 'fl_refresh_usage' ),
		'i18n'          => array(
			'refreshing'         => __( 'Refreshing…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'refreshDone'        => __( 'Done! Reloading page…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'refreshError'       => __( 'Error refreshing tokens. Check your API key.', 'forgelayer-crypto-payments-for-woocommerce' ),
			'webhookRegistering' => __( 'Registering webhook…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'webhookDone'        => __( 'Webhook registered! ID: ', 'forgelayer-crypto-payments-for-woocommerce' ),
			'webhookError'       => __( 'Error registering webhook: ', 'forgelayer-crypto-payments-for-woocommerce' ),
			'usageRefreshing'    => __( 'Refreshing usage…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'usageDone'          => __( 'Updated. Reloading…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'usageError'         => __( 'Could not fetch usage. Check your API key.', 'forgelayer-crypto-payments-for-woocommerce' ),
		),
	) );
}

/**
 * Shared rate limiter for admin AJAX endpoints.
 * Allows max $limit requests per $window_seconds per user.
 * Returns true if the request is within limits, false if rate-limited.
 *
 * @param  string $action          Identifier string for this endpoint
 * @param  int    $limit           Max allowed requests per window
 * @param  int    $window_seconds  Rolling window in seconds
 * @return bool
 */
function fl_admin_rate_limit_ok( $action, $limit = 10, $window_seconds = 60 ) {
	$user_id   = get_current_user_id();
	$trans_key = 'fl_rl_' . sanitize_key( $action ) . '_' . absint( $user_id );
	$count     = (int) get_transient( $trans_key );
	if ( $count >= $limit ) {
		return false;
	}
	// On first hit, set the transient; on subsequent hits, increment atomically
	if ( $count === 0 ) {
		set_transient( $trans_key, 1, $window_seconds );
	} else {
		set_transient( $trans_key, $count + 1, $window_seconds );
	}
	return true;
}

// Admin AJAX: register / re-register webhook with ForgeLayer
add_action( 'wp_ajax_fl_setup_webhook', 'fl_ajax_setup_webhook' );
function fl_ajax_setup_webhook() {
	check_ajax_referer( 'fl_setup_webhook', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		return;
	}

	// Rate limit: max 10 requests per minute per user
	if ( ! fl_admin_rate_limit_ok( 'setup_webhook', 10, 60 ) ) {
		wp_send_json_error( array( 'message' => 'Too many requests. Please wait before retrying.' ) );
		return;
	}

	// Input validation: validate API key format before calling ForgeLayer
	$settings = get_option( 'woocommerce_forgelayer_settings', array() );
	$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
	if ( $api_key && ! preg_match( '/^flk_(live|test)_[a-f0-9]{32,}$/', $api_key ) ) {
		wp_send_json_error( array( 'message' => 'Invalid API key format. Expected flk_live_... or flk_test_...' ) );
		return;
	}

	$gateway = new FL_Gateway();
	$result  = $gateway->setup_webhook();

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => sanitize_text_field( $result->get_error_message() ) ) );
		return;
	}

	wp_send_json_success( array(
		'webhook_id' => esc_html( $result['webhook_id'] ),
		// Never return the secret to the client — it is already saved server-side
	) );
}

// Admin AJAX: refresh token list from ForgeLayer
add_action( 'wp_ajax_fl_refresh_tokens', 'fl_ajax_refresh_tokens' );
function fl_ajax_refresh_tokens() {
	check_ajax_referer( 'fl_refresh_tokens', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
	}

	// Rate limit: max 10 requests per minute per user
	if ( ! fl_admin_rate_limit_ok( 'refresh_tokens', 10, 60 ) ) {
		wp_send_json_error( array( 'message' => 'Too many requests. Please wait before retrying.' ) );
		return;
	}

	$gateway = new FL_Gateway();
	$results = $gateway->refresh_tokens();

	$errors  = array();
	$counts  = array();
	foreach ( $results as $chain_id => $data ) {
		$safe_chain = sanitize_key( $chain_id );
		if ( is_wp_error( $data ) ) {
			// Sanitize error message before returning to admin JS (L-4)
			$errors[ $safe_chain ] = sanitize_text_field( $data->get_error_message() );
		} else {
			$counts[ $safe_chain ] = count( $data );
		}
	}

	update_option( 'fl_tokens_last_synced', time() );

	wp_send_json_success( array(
		'counts' => $counts,
		'errors' => $errors,
	) );
}

// -------------------------------------------------------------------------
// Front-end assets
// -------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'fl_enqueue_assets' );
function fl_enqueue_assets() {
	if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}

	wp_enqueue_style(
		'fl-checkout',
		FL_PLUGIN_URL . 'assets/css/fl-checkout.css',
		array(),
		FL_PLUGIN_VERSION
	);

	wp_enqueue_script(
		'fl-checkout',
		FL_PLUGIN_URL . 'assets/js/fl-checkout.js',
		array( 'jquery' ),
		FL_PLUGIN_VERSION,
		true
	);

	// Per-order nonce is embedded in the payment-box data attribute in thankyou_page().
	// We only pass the AJAX URL and i18n strings globally here.
	wp_localize_script( 'fl-checkout', 'flCheckout', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'i18n'    => array(
			'copied'    => __( 'Copied!', 'forgelayer-crypto-payments-for-woocommerce' ),
			'copy'      => __( 'Copy', 'forgelayer-crypto-payments-for-woocommerce' ),
			'checking'  => __( 'Checking payment…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'received'  => __( 'Payment received! Confirming…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'confirmed' => __( 'Payment confirmed! Redirecting…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'expired'   => __( 'Payment window expired.', 'forgelayer-crypto-payments-for-woocommerce' ),
		),
	) );
}

// -------------------------------------------------------------------------
// AJAX: check payment status (logged-in & guests)
// -------------------------------------------------------------------------
add_action( 'wp_ajax_fl_check_payment',        'fl_ajax_check_payment' );
add_action( 'wp_ajax_nopriv_fl_check_payment', 'fl_ajax_check_payment' );
function fl_ajax_check_payment() {
	// Verify per-order nonce — ties this nonce to a specific order ID so it cannot
	// be reused across orders. Generated server-side in thankyou_page(). (M-7)
	$order_id = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
	$nonce    = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';

	// Input validation: order_id must be positive integer (already ensured by absint above)
	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => 'Invalid request' ) );
		return;
	}

	// Nonce length guard — nonces are fixed-length WP hashes; reject oversized input
	if ( strlen( $nonce ) > 64 ) {
		wp_send_json_error( array( 'message' => 'Invalid request' ) );
		return;
	}

	$nonce = sanitize_text_field( $nonce );

	if ( ! wp_verify_nonce( $nonce, 'fl_check_payment_' . $order_id ) ) {
		wp_send_json_error( array( 'message' => 'Invalid request' ) );
		return;
	}

	$order_key = sanitize_text_field( isset( $_POST['order_key'] ) ? $_POST['order_key'] : '' );

	// Input validation: order_key must match WooCommerce format
	if ( ! $order_key || ! preg_match( '/^wc_order_[a-zA-Z0-9]+$/', $order_key ) ) {
		wp_send_json_error( array( 'message' => 'Invalid request' ) );
		return;
	}

	// ── Brute-force / rate limiting ───────────────────────────────────────────
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$ip_hash = md5( $ip ); // Never store raw IPs

	// Lockout key is scoped to ip+order so that failures on one order cannot
	// lock out legitimate customers from a shared IP (offices, NAT, CDNs) who
	// are polling their own separate orders.
	$fail_key   = 'fl_chk_fail_' . $ip_hash . '_' . absint( $order_id );
	$fail_count = (int) get_transient( $fail_key );

	// Configurable lockout threshold (default 10, adjustable in settings)
	$settings        = get_option( 'woocommerce_forgelayer_settings', array() );
	$lockout_thresh  = max( 3, (int) ( $settings['rate_lockout_threshold'] ?? 10 ) );
	$soft_thresh     = max( 2, (int) floor( $lockout_thresh / 3 ) );

	if ( $fail_count >= $lockout_thresh ) {
		wp_send_json_error( array( 'message' => 'Too many requests. Please try again later.' ) );
		return;
	}
	if ( $fail_count >= $soft_thresh ) {
		$lock_key = 'fl_chk_lock_' . $ip_hash . '_' . absint( $order_id );
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( array( 'message' => 'Too many requests. Please wait before retrying.' ) );
			return;
		}
	}

	// Per-order per-IP throttle — configurable interval (default 10s)
	$poll_interval = max( 5, (int) ( $settings['rate_poll_interval'] ?? 10 ) );
	$throttle_key  = 'fl_poll_' . $order_id . '_' . $ip_hash;
	if ( get_transient( $throttle_key ) ) {
		wp_send_json_success( array( 'status' => 'pending', 'message' => 'Awaiting payment…' ) );
		return;
	}
	set_transient( $throttle_key, 1, $poll_interval );

	$order = wc_get_order( $order_id );
	if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
		$new_fails = $fail_count + 1;
		$lock_ttl  = $new_fails >= $lockout_thresh ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS;
		set_transient( $fail_key, $new_fails, $lock_ttl );
		if ( $new_fails >= $soft_thresh ) {
			set_transient( 'fl_chk_lock_' . $ip_hash . '_' . absint( $order_id ), 1,
				$new_fails >= $lockout_thresh ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
		}
		wp_send_json_error( array( 'message' => 'Invalid order' ) );
		return;
	}

	$gateway = new FL_Gateway();
	$result  = $gateway->verify_payment( $order );

	// Strip internal financial details from unauthenticated responses (H-4)
	unset( $result['balance'], $result['expected'] );

	wp_send_json_success( $result );
}

// -------------------------------------------------------------------------
// WP-Cron: register all intervals used by this plugin
// -------------------------------------------------------------------------
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['fl_1_minute']    = array( 'interval' => 60,   'display' => __( 'Every 1 Minute',   'forgelayer-crypto-payments-for-woocommerce' ) );
	$schedules['fl_2_minutes']   = array( 'interval' => 120,  'display' => __( 'Every 2 Minutes',  'forgelayer-crypto-payments-for-woocommerce' ) );
	$schedules['fl_five_minutes'] = array( 'interval' => 300, 'display' => __( 'Every 5 Minutes',  'forgelayer-crypto-payments-for-woocommerce' ) );
	$schedules['fl_10_minutes']  = array( 'interval' => 600,  'display' => __( 'Every 10 Minutes', 'forgelayer-crypto-payments-for-woocommerce' ) );
	$schedules['fl_15_minutes']  = array( 'interval' => 900,  'display' => __( 'Every 15 Minutes', 'forgelayer-crypto-payments-for-woocommerce' ) );
	$schedules['fl_30_minutes']  = array( 'interval' => 1800, 'display' => __( 'Every 30 Minutes', 'forgelayer-crypto-payments-for-woocommerce' ) );
	return $schedules;
} );

/**
 * Map a TTL in seconds to the matching WP-Cron schedule name.
 */
function fl_get_cron_schedule_for_ttl( $ttl ) {
	$map = array(
		60  => 'fl_1_minute',
		120 => 'fl_2_minutes',
		300 => 'fl_five_minutes',
		600 => 'fl_10_minutes',
		900 => 'fl_15_minutes',
	);
	return isset( $map[ $ttl ] ) ? $map[ $ttl ] : 'fl_five_minutes';
}

/**
 * (Re)schedule fl_refresh_prices using the merchant's configured interval.
 * Called on activation and whenever settings are saved.
 */
function fl_reschedule_price_cron() {
	$ttl      = FL_Price::get_cache_ttl();
	$schedule = fl_get_cron_schedule_for_ttl( $ttl );

	$next = wp_next_scheduled( 'fl_refresh_prices' );
	if ( $next ) {
		// Only reschedule if the interval has changed
		$current = wp_get_schedule( 'fl_refresh_prices' );
		if ( $current === $schedule ) {
			return;
		}
		wp_clear_scheduled_hook( 'fl_refresh_prices' );
	}

	wp_schedule_event( time(), $schedule, 'fl_refresh_prices' );
}

// Reschedule when the merchant saves gateway settings
add_action( 'woocommerce_update_options_payment_gateways_forgelayer', 'fl_reschedule_price_cron' );

// -------------------------------------------------------------------------
// WP-Cron: account usage check (every 30 minutes)
// Fetches billing/usage and fires admin warnings before customers are affected.
// -------------------------------------------------------------------------
add_action( 'fl_check_usage', 'fl_cron_check_usage' );
function fl_cron_check_usage() {
	$settings = get_option( 'woocommerce_forgelayer_settings', array() );
	$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
	if ( ! $api_key ) {
		return;
	}

	$api    = new FL_API( $api_key );
	$result = $api->get_usage();

	if ( is_wp_error( $result ) ) {
		if ( $result->get_error_code() === 'fl_no_billing' ) {
			return; // Free plan or no billing record — skip silently
		}
		// Store the error so the settings page can show it
		update_option( 'fl_usage_cache', array( 'error' => $result->get_error_message() ), false );
		return;
	}

	// Persist with a fetch timestamp
	$result['_fetched'] = time();
	update_option( 'fl_usage_cache', $result, false );

	fl_evaluate_usage_alerts( $result, $settings );
}

/**
 * Evaluate usage percentages and send admin emails when thresholds are crossed.
 * Emails are deduplicated — one email per resource per threshold per billing cycle.
 *
 * @param array $usage_data  The full usage response from get_usage()
 * @param array $settings    Gateway settings
 */
function fl_evaluate_usage_alerts( $usage_data, $settings ) {
	$percentages = isset( $usage_data['percentages'] ) ? $usage_data['percentages'] : array();
	$limits      = isset( $usage_data['limits'] )      ? $usage_data['limits']      : array();
	$reset_at    = isset( $usage_data['usage']['resetAt'] ) ? $usage_data['usage']['resetAt'] : '';

	$resources = array(
		'addresses'   => __( 'Wallet Addresses', 'forgelayer-crypto-payments-for-woocommerce' ),
		'webhooks'    => __( 'Webhooks', 'forgelayer-crypto-payments-for-woocommerce' ),
		'apiRequests' => __( 'API Requests', 'forgelayer-crypto-payments-for-woocommerce' ),
	);

	// Thresholds in ascending order — we track the highest one already emailed
	$thresholds = array( 80, 90, 100 );

	// Stored as: { addresses: 80, webhooks: 0, apiRequests: 90, _reset_at: '...' }
	$alerted = get_option( 'fl_usage_alerted', array() );

	// Clear alerts when a new billing cycle starts
	if ( $reset_at && isset( $alerted['_reset_at'] ) && $alerted['_reset_at'] !== $reset_at ) {
		$alerted = array();
	}
	$alerted['_reset_at'] = $reset_at;

	$admin_email  = get_option( 'admin_email' );
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=forgelayer' );
	$reset_label  = $reset_at ? date_i18n( get_option( 'date_format' ), strtotime( $reset_at ) ) : __( 'your next billing cycle', 'forgelayer-crypto-payments-for-woocommerce' );

	foreach ( $resources as $key => $label ) {
		$pct   = (int) ( $percentages[ $key ] ?? 0 );
		$limit = $limits[ $key ] ?? -1;

		if ( $limit === -1 ) {
			continue; // Unlimited — never alert
		}

		$last_alerted = (int) ( $alerted[ $key ] ?? 0 );

		foreach ( array_reverse( $thresholds ) as $threshold ) {
			if ( $pct < $threshold ) {
				continue;
			}
			if ( $last_alerted >= $threshold ) {
				break; // Already sent this level for this cycle
			}

			// Send the email
			$subject = $threshold >= 100
				? sprintf( __( '[Action Required] ForgeLayer %s limit reached', 'forgelayer-crypto-payments-for-woocommerce' ), $label )
				: sprintf( __( '[Warning] ForgeLayer %s at %d%%', 'forgelayer-crypto-payments-for-woocommerce' ), $label, $pct );

			$impact = '';
			if ( $key === 'apiRequests' && $threshold >= 100 ) {
				$impact = __( "\nIMPACT: Cryptocurrency checkout is now disabled. Customers cannot pay with crypto until this resets.", 'forgelayer-crypto-payments-for-woocommerce' );
			} elseif ( $key === 'addresses' && $threshold >= 100 ) {
				$impact = __( "\nIMPACT: No new wallet addresses can be generated. Customers may be unable to check out with crypto (address reuse may still work).", 'forgelayer-crypto-payments-for-woocommerce' );
			} elseif ( $key === 'webhooks' && $threshold >= 100 ) {
				$impact = __( "\nIMPACT: No new webhooks can be registered. Payment confirmation will fall back to polling.", 'forgelayer-crypto-payments-for-woocommerce' );
			}

			$body = sprintf(
				__( "Your ForgeLayer %1\$s usage has reached %2\$d%%.\n\nCurrent usage : %3\$d / %4\$d\nResets on     : %5\$s%6\$s\n\nLog into your ForgeLayer dashboard to upgrade your plan or manage your usage.\n\nForgeLayer Settings: %7\$s", 'forgelayer-crypto-payments-for-woocommerce' ),
				$label,
				$pct,
				$usage_data['usage'][ $key === 'addresses' ? 'addressesGenerated' : ( $key === 'webhooks' ? 'webhooksCreated' : 'apiRequestsMade' ) ] ?? 0,
				$limit,
				$reset_label,
				$impact,
				$settings_url
			);

			wp_mail( $admin_email, $subject, $body );

			$alerted[ $key ] = $threshold;
			break;
		}
	}

	update_option( 'fl_usage_alerted', $alerted, false );
}

// -------------------------------------------------------------------------
// Admin AJAX: manual usage refresh from settings page
// -------------------------------------------------------------------------
add_action( 'wp_ajax_fl_refresh_usage', 'fl_ajax_refresh_usage' );
function fl_ajax_refresh_usage() {
	check_ajax_referer( 'fl_refresh_usage', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		return;
	}

	// Rate limit: max 10 requests per minute per user
	if ( ! fl_admin_rate_limit_ok( 'refresh_usage', 10, 60 ) ) {
		wp_send_json_error( array( 'message' => 'Too many requests. Please wait before retrying.' ) );
		return;
	}

	fl_cron_check_usage();
	$cached = get_option( 'fl_usage_cache', array() );

	// Surface API errors so the JS can show them rather than silently reloading
	if ( ! empty( $cached['error'] ) ) {
		wp_send_json_error( array( 'message' => sanitize_text_field( $cached['error'] ) ) );
		return;
	}

	wp_send_json_success( array(
		'percentages' => isset( $cached['percentages'] ) ? $cached['percentages'] : array(),
		'limits'      => isset( $cached['limits'] )      ? $cached['limits']      : array(),
		'usage'       => isset( $cached['usage'] )       ? $cached['usage']       : array(),
	) );
}

// -------------------------------------------------------------------------
// WP-Cron: batch price cache refresh
// Pre-fetches all coin prices in one CoinGecko call so checkout never
// triggers a live API request. Fires every 5 minutes.
// -------------------------------------------------------------------------
add_action( 'fl_refresh_prices', 'fl_cron_refresh_prices' );
function fl_cron_refresh_prices() {
	$currency = strtolower( get_woocommerce_currency() );
	if ( FL_Price::is_currency_supported( $currency ) ) {
		FL_Price::refresh_cache( $currency );
	}
}

// -------------------------------------------------------------------------
// WP-Cron: background payment checking every 5 minutes
// -------------------------------------------------------------------------
add_action( 'fl_check_pending_orders', 'fl_cron_check_pending_orders' );
function fl_cron_check_pending_orders() {
	$orders = wc_get_orders( array(
		'payment_method' => 'forgelayer',
		'status'         => array( 'pending', 'on-hold' ),
		'limit'          => 20,
		'meta_key'       => '_fl_address',
		'meta_compare'   => 'EXISTS',
	) );

	if ( empty( $orders ) ) {
		return;
	}

	$gateway = new FL_Gateway();
	foreach ( $orders as $order ) {
		$gateway->verify_payment( $order );
	}
}

register_activation_hook( __FILE__, function () {
	// The activation hook fires before plugins_loaded, so the class files
	// have not been auto-loaded yet — require them explicitly here.
	require_once FL_PLUGIN_DIR . 'includes/class-fl-price.php';
	require_once FL_PLUGIN_DIR . 'includes/class-fl-api.php';
	require_once FL_PLUGIN_DIR . 'includes/class-fl-gateway.php';

	// Schedule crons using the saved interval (defaults to 5 min on first install)
	if ( ! wp_next_scheduled( 'fl_refresh_prices' ) ) {
		$ttl      = FL_Price::get_cache_ttl();
		$schedule = fl_get_cron_schedule_for_ttl( $ttl );
		wp_schedule_event( time(), $schedule, 'fl_refresh_prices' );
	}
	if ( ! wp_next_scheduled( 'fl_check_pending_orders' ) ) {
		wp_schedule_event( time() + 30, 'fl_five_minutes', 'fl_check_pending_orders' );
	}
	if ( ! wp_next_scheduled( 'fl_check_usage' ) ) {
		wp_schedule_event( time() + 60, 'fl_30_minutes', 'fl_check_usage' );
	}

	// Warm the price cache immediately so the first checkout has prices.
	// Wrapped in try/catch — any failure here must not block activation.
	try {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtolower( get_woocommerce_currency() );
			if ( FL_Price::is_currency_supported( $currency ) ) {
				FL_Price::refresh_cache( $currency );
			}
		}
	} catch ( Exception $e ) {
		// Non-fatal — prices will be fetched on the first WP-Cron run
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'fl_refresh_prices' );
	wp_clear_scheduled_hook( 'fl_check_pending_orders' );
	wp_clear_scheduled_hook( 'fl_check_usage' );
} );
