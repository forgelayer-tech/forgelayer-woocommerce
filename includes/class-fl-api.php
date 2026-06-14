<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the ForgeLayer REST API.
 */
class FL_API {

	const BASE_URL = 'https://api.forgelayer.io/v1';

	/** @var string */
	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	// -------------------------------------------------------------------------
	// Address endpoints
	// -------------------------------------------------------------------------

	/**
	 * Generate a new receiving address on a given chain.
	 *
	 * @param  string $chain    ethereum | bsc | bitcoin | tron
	 * @param  string $label    Human-readable label (e.g. "Order #123")
	 * @param  string $user_ref Internal reference (e.g. WC order ID)
	 * @param  string $tag      Optional tag (e.g. customer email)
	 * @return array|WP_Error
	 */
	public function generate_address( $chain, $label = '', $user_ref = '', $tag = '' ) {
		$body = array( 'chain' => $chain );

		if ( $label )    { $body['label']   = $label; }
		if ( $user_ref ) { $body['userRef'] = $user_ref; }
		if ( $tag )      { $body['tag']     = $tag; }

		return $this->request( 'POST', '/addresses', $body );
	}

	/**
	 * Check the balance of a generated address.
	 * Pass $token_contract + $decimals for ERC-20 / BEP-20 / TRC-20 tokens.
	 *
	 * @param  string $address
	 * @param  string $chain
	 * @param  string $token_contract  Contract address (empty for native coin)
	 * @param  int    $decimals
	 * @return array|WP_Error
	 */
	public function check_balance( $address, $chain, $token_contract = '', $decimals = 18 ) {
		$params = array( 'chain' => $chain );

		if ( $token_contract ) {
			$params['tokenContract'] = $token_contract;
			$params['decimals']      = $decimals;
		}

		return $this->request( 'GET', '/addresses/' . rawurlencode( $address ) . '/balance', null, $params );
	}

	/**
	 * List tokens configured on the account for a given chain.
	 * Returns a normalised array of token objects, or WP_Error on failure.
	 *
	 * Each token: { symbol, name, contract, decimals, enabled }
	 *
	 * @param  string $chain  ethereum | bsc | bitcoin | tron
	 * @return array|WP_Error
	 */
	public function list_tokens( $chain ) {
		$raw = $this->request( 'GET', '/tokens', null, array( 'chain' => $chain ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		// request() already unwraps the "data" envelope, so $raw is the payload.
		// Response payload: { "tokens": [...] } or a bare array
		$items = isset( $raw['tokens'] ) ? $raw['tokens'] : $raw;

		if ( ! is_array( $items ) ) {
			return array();
		}

		$normalised = array();
		foreach ( $items as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}

			// Contract address field name varies across API versions
			$contract = '';
			foreach ( array( 'contractAddress', 'contract', 'address', 'tokenAddress' ) as $key ) {
				if ( ! empty( $t[ $key ] ) ) {
					$contract = $t[ $key ];
					break;
				}
			}

			$symbol = strtoupper( isset( $t['symbol'] ) ? $t['symbol'] : '' );
			if ( ! $symbol ) {
				continue;
			}

			$normalised[] = array(
				'symbol'   => $symbol,
				'name'     => isset( $t['name'] ) ? $t['name'] : $symbol,
				'contract' => $contract,
				'decimals' => isset( $t['decimals'] ) ? (int) $t['decimals'] : 18,
				'enabled'  => isset( $t['enabled'] ) ? (bool) $t['enabled'] : true,
			);
		}

		return $normalised;
	}

	// -------------------------------------------------------------------------
	// Webhook endpoints
	// -------------------------------------------------------------------------

	/**
	 * Ask ForgeLayer to generate a cryptographically random 32-byte hex secret.
	 * Alternatively, callers can generate their own via bin2hex( random_bytes( 32 ) ).
	 *
	 * @return array|WP_Error  e.g. { "secret": "abcdef..." }
	 */
	public function generate_webhook_secret() {
		return $this->request( 'GET', '/webhooks/generate-secret' );
	}

	/**
	 * Register a new webhook with ForgeLayer.
	 *
	 * @param  string   $url                 HTTPS endpoint that will receive POST payloads
	 * @param  string   $secret              HMAC-SHA256 signing secret
	 * @param  string[] $events              e.g. ['deposit_confirmed']
	 * @param  int[]    $confirmation_levels e.g. [1] — fire after N on-chain confirmations
	 * @return array|WP_Error
	 */
	public function create_webhook( $url, $secret, $events = array( 'deposit_confirmed' ), $confirmation_levels = array( 1 ) ) {
		$body = array(
			'url'    => $url,
			'secret' => $secret,
			'events' => $events,
		);
		if ( ! empty( $confirmation_levels ) ) {
			$body['confirmationLevels'] = array_values( array_map( 'intval', $confirmation_levels ) );
		}
		return $this->request( 'POST', '/webhooks', $body );
	}

	/**
	 * Update an existing webhook.
	 *
	 * @param  string $webhook_id
	 * @param  array  $fields  Subset of create fields to update
	 * @return array|WP_Error
	 */
	public function update_webhook( $webhook_id, $fields ) {
		return $this->request( 'PUT', '/webhooks/' . rawurlencode( $webhook_id ), $fields );
	}

	/**
	 * Delete a webhook by ID.
	 *
	 * @param  string $webhook_id
	 * @return array|WP_Error
	 */
	public function delete_webhook( $webhook_id ) {
		return $this->request( 'DELETE', '/webhooks/' . rawurlencode( $webhook_id ) );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @return array|WP_Error
	 */
	public function list_webhooks() {
		return $this->request( 'GET', '/webhooks' );
	}

	/**
	 * Send a test payload to a registered webhook to verify delivery.
	 *
	 * @param  string $webhook_id
	 * @return array|WP_Error
	 */
	public function test_webhook( $webhook_id ) {
		return $this->request( 'POST', '/webhooks/' . rawurlencode( $webhook_id ) . '/test' );
	}

	/**
	 * Fetch current billing usage and plan limits.
	 *
	 * Response shape (after envelope unwrap):
	 * {
	 *   usage:       { addressesGenerated, webhooksCreated, apiRequestsMade, resetAt }
	 *   limits:      { addresses, webhooks, apiRequests }   (-1 = unlimited)
	 *   percentages: { addresses, webhooks, apiRequests }   (0 for unlimited)
	 *   addonBenefits: { bonusAddresses, bonusTokenSlots, bonusApiCredits }
	 * }
	 *
	 * Returns WP_Error with code 'fl_no_billing' when the account has no billing
	 * record yet (404), so callers can handle it silently.
	 *
	 * @return array|WP_Error
	 */
	public function get_usage() {
		$raw = $this->request( 'GET', '/billing/usage' );

		if ( is_wp_error( $raw ) ) {
			$data = $raw->get_error_data();
			if ( isset( $data['status'] ) && (int) $data['status'] === 404 ) {
				return new WP_Error( 'fl_no_billing', 'No billing record found — account may be on a free plan.' );
			}
			return $raw;
		}

		return $raw;
	}

	// -------------------------------------------------------------------------
	// HTTP transport
	// -------------------------------------------------------------------------

	/**
	 * @param  string     $method   GET | POST | DELETE | PUT
	 * @param  string     $endpoint Path starting with /
	 * @param  array|null $body     JSON body for POST/PUT
	 * @param  array      $query    URL query params
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, $body = null, $query = array() ) {
		$url = self::BASE_URL . $endpoint;

		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );

		// ForgeLayer API envelope: { "success": bool, "data": {...}, "error": "..." | {...} }
		// Treat success:false as an error regardless of HTTP status code.
		// Preserve the ForgeLayer error code (LIMIT_EXCEEDED, SUBSCRIPTION_EXPIRED, etc.)
		// as the WP_Error code so callers can branch on it without string-matching messages.
		if ( is_array( $data ) && isset( $data['success'] ) && $data['success'] === false ) {
			$err     = isset( $data['error'] ) ? $data['error'] : ( isset( $data['message'] ) ? $data['message'] : null );
			$fl_code = 'fl_api_error';
			$message = 'API returned success:false';

			if ( is_array( $err ) ) {
				$message = isset( $err['message'] ) ? $err['message'] : ( isset( $err['code'] ) ? $err['code'] : 'Unknown error' );
				if ( ! empty( $err['code'] ) ) {
					$fl_code = sanitize_text_field( $err['code'] );
				}
			} else {
				$message = $err ?: 'API returned success:false';
			}

			// Do not include raw body — it may contain sensitive info (H-5)
			return new WP_Error( $fl_code, $message, array( 'status' => $status ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = '';
			if ( is_array( $data ) ) {
				$message = isset( $data['error'] ) ? $data['error']
					: ( isset( $data['message'] ) ? $data['message'] : '' );
			}
			if ( ! $message ) {
				$message = "HTTP {$status}";
			}
			return new WP_Error( 'fl_api_error', $message, array( 'status' => $status ) );
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		// Unwrap the "data" envelope so callers always get the payload directly
		// e.g. { "success": true, "data": { "address": "0x..." } } → { "address": "0x..." }
		return isset( $data['data'] ) ? $data['data'] : $data;
	}
}
