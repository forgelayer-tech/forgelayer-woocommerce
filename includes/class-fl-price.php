<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles fiat → crypto price conversion using CoinGecko.
 *
 * Architecture
 * ────────────
 * All prices are stored together in a single WP option (FL_Price::CACHE_OPTION).
 * A WP-Cron job refreshes the entire batch every 5 minutes with one API call,
 * so checkout never triggers a live CoinGecko request under normal operation.
 * If the cache is missing or stale at checkout time, a live fetch runs once and
 * re-warms the cache for all subsequent orders.
 *
 * Only currencies listed in SUPPORTED_CURRENCIES are accepted — these are the
 * fiat currencies that CoinGecko's /simple/price endpoint supports.
 */
class FL_Price {

	const COINGECKO_API = 'https://api.coingecko.com/api/v3';

	/** WP option that stores the shared batch price cache. */
	const CACHE_OPTION = 'fl_price_cache';

	/**
	 * Allowed price refresh intervals in seconds.
	 * These map 1-to-1 with WP-Cron schedules registered in forgelayer-woocommerce.php.
	 *
	 * WP-Cron fires on page loads so sub-minute intervals are unreliable on
	 * low-traffic sites. Minimum offered is 1 minute; default is 5 minutes —
	 * consistent with how BitPay/Coinbase Commerce handle rate locking.
	 */
	const ALLOWED_TTLS = array( 60, 120, 300, 600, 900 );
	const DEFAULT_TTL  = 300; // 5 minutes

	/**
	 * Fiat currencies supported by CoinGecko /simple/price.
	 * Stored uppercase to match WooCommerce's get_woocommerce_currency() output.
	 * Source: https://api.coingecko.com/api/v3/simple/supported_vs_currencies
	 */
	const SUPPORTED_CURRENCIES = array(
		'USD', 'AED', 'ARS', 'AUD', 'BDT', 'BHD', 'BMD', 'BRL', 'CAD',
		'CHF', 'CLP', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'GEL', 'HKD',
		'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'KWD', 'LKR', 'MMK',
		'MXN', 'MYR', 'NGN', 'NOK', 'NZD', 'PHP', 'PKR', 'PLN', 'RUB',
		'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'UAH', 'VEF', 'VND', 'ZAR',
	);

	/**
	 * All CoinGecko coin IDs fetched on every cache refresh — one batched API call.
	 * Must stay in sync with FL_Gateway::$coingecko_map.
	 */
	const ALL_COIN_IDS = array(
		// Native chain coins
		'bitcoin', 'ethereum', 'binancecoin', 'tron',
		// Stablecoins
		'tether', 'usd-coin', 'binance-usd', 'dai', 'true-usd',
		'pax-dollar', 'frax', 'liquity-usd', 'gemini-dollar', 'usdd',
		// Wrapped assets
		'wrapped-bitcoin', 'weth', 'wbnb',
		// DeFi blue-chips
		'chainlink', 'uniswap', 'aave', 'compound-governance-token', 'maker',
		'havven', 'yearn-finance', 'sushi', '1inch', 'curve-dao-token',
		'balancer', 'lido-dao',
		// Layer 2 / Infrastructure
		'matic-network', 'arbitrum', 'optimism', 'the-graph',
		// Meme / Culture
		'shiba-inu', 'pepe', 'floki', 'dogecoin',
		// Gaming / Metaverse
		'the-sandbox', 'decentraland', 'axie-infinity', 'apecoin', 'immutable-x',
		// Exchange tokens
		'crypto-com-chain',
		// BSC-native
		'pancakeswap-token', 'venus',
		// Tron ecosystem
		'bittorrent', 'wink', 'just', 'sun-token',
		// Other popular
		'basic-attention-token', '0x', 'ethereum-name-service',
		'chiliz', 'gala', 'fantom', 'stepn',
	);

	/**
	 * Minimum sane prices in USD.
	 * Any price below these is treated as oracle manipulation and discarded. (M-1)
	 */
	const PRICE_FLOORS = array(
		'bitcoin'     => 1000.0,
		'ethereum'    => 10.0,
		'binancecoin' => 0.5,
		'tron'        => 0.0001,
	);

	/** USD-pegged stablecoins — amount equals fiat amount when store currency is USD. */
	const STABLE_USD = array( 'USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'USDP' );

	/** Symbol → CoinGecko ID fallback for the most common assets. */
	const COIN_IDS = array(
		'BTC'  => 'bitcoin',
		'ETH'  => 'ethereum',
		'BNB'  => 'binancecoin',
		'TRX'  => 'tron',
		'USDT' => 'tether',
		'USDC' => 'usd-coin',
	);

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Return the configured cache TTL in seconds.
	 * Reads from gateway settings; falls back to DEFAULT_TTL if missing or invalid.
	 *
	 * @return int
	 */
	public static function get_cache_ttl() {
		$settings = get_option( 'woocommerce_forgelayer_settings', array() );
		$ttl      = isset( $settings['price_refresh_interval'] ) ? (int) $settings['price_refresh_interval'] : self::DEFAULT_TTL;
		return in_array( $ttl, self::ALLOWED_TTLS, true ) ? $ttl : self::DEFAULT_TTL;
	}

	/**
	 * Return true if the given currency code is supported by CoinGecko.
	 *
	 * @param  string $currency  WooCommerce currency code, e.g. 'USD'
	 * @return bool
	 */
	public static function is_currency_supported( $currency ) {
		return in_array( strtoupper( $currency ), self::SUPPORTED_CURRENCIES, true );
	}

	/**
	 * Convert a fiat amount to its crypto equivalent.
	 * Reads from the batch price cache — no live API call unless cache is stale.
	 *
	 * @param  float  $fiat_amount
	 * @param  string $fiat_currency  ISO code, e.g. 'USD'
	 * @param  string $symbol         Crypto symbol, e.g. 'ETH'
	 * @param  string $coingecko_id   CoinGecko ID override (resolved by gateway)
	 * @return string|WP_Error        Formatted amount string, or WP_Error on failure
	 */
	public function fiat_to_crypto( $fiat_amount, $fiat_currency, $symbol, $coingecko_id = '' ) {
		$symbol   = strtoupper( $symbol );
		$currency = strtoupper( $fiat_currency );

		if ( ! self::is_currency_supported( $currency ) ) {
			return new WP_Error(
				'fl_unsupported_currency',
				sprintf(
					/* translators: 1: currency code */
					__( '"%s" is not supported. ForgeLayer accepts payments only when the store currency is one of the CoinGecko-supported fiat currencies.', 'forgelayer-crypto-payments-for-woocommerce' ),
					$currency
				)
			);
		}

		// USD stablecoin shortcut — no price lookup needed
		if ( in_array( $symbol, self::STABLE_USD, true ) && $currency === 'USD' ) {
			return number_format( $fiat_amount, 2, '.', '' );
		}

		$coin_id = $coingecko_id
			? $coingecko_id
			: ( isset( self::COIN_IDS[ $symbol ] ) ? self::COIN_IDS[ $symbol ] : '' );

		if ( ! $coin_id ) {
			return new WP_Error( 'fl_price_unknown', "No CoinGecko ID for symbol: {$symbol}" );
		}

		$price = $this->get_price( $coin_id, strtolower( $fiat_currency ) );

		if ( is_wp_error( $price ) ) {
			return $price;
		}

		if ( $price <= 0 ) {
			return new WP_Error( 'fl_price_invalid', "Non-positive price for {$coin_id}." );
		}

		$amount = $fiat_amount / $price;

		if ( $symbol === 'BTC' ) {
			$precision = 8;
		} elseif ( $symbol === 'TRX' ) {
			$precision = 2;
		} else {
			$precision = 6;
		}

		return number_format( $amount, $precision, '.', '' );
	}

	/**
	 * Return the price of a single coin from the saved batch cache.
	 *
	 * This method is read-only — it NEVER calls CoinGecko directly.
	 * Prices are written to the cache by WP-Cron (fl_refresh_prices, every 5 min)
	 * and by the activation hook. Checkout reads from here with zero latency.
	 *
	 * If the cache is empty or covers a different currency, the cron has not
	 * fired yet — returns WP_Error so checkout degrades gracefully.
	 *
	 * @param  string $coin_id      CoinGecko coin ID, e.g. 'bitcoin'
	 * @param  string $vs_currency  Lowercase fiat code, e.g. 'usd'
	 * @return float|WP_Error
	 */
	public function get_price( $coin_id, $vs_currency ) {
		if ( ! preg_match( '/^[a-z0-9\-]{1,64}$/', $coin_id ) ) {
			return new WP_Error( 'fl_price_invalid_id', "Invalid CoinGecko ID format: {$coin_id}" );
		}

		$vs_currency = strtolower( $vs_currency );
		$cache       = self::read_cache();
		$cached_cur  = isset( $cache['_currency'] ) ? $cache['_currency'] : '';

		// Serve from cache regardless of age — stale prices are better than a
		// live API call during checkout. Cron keeps them fresh in the background.
		if ( $cached_cur === $vs_currency && isset( $cache[ $coin_id ] ) ) {
			return (float) $cache[ $coin_id ];
		}

		// Cache is empty or covers a different currency.
		// Log once so the merchant can diagnose, but do not block checkout.
		fl_log_warning(
			sprintf(
				'ForgeLayer: price cache miss for %s/%s (cache currency: %s). WP-Cron will populate shortly.',
				$coin_id,
				$vs_currency,
				$cached_cur ?: 'none'
			),
			array( 'source' => 'forgelayer' )
		);

		return new WP_Error(
			'fl_price_cache_miss',
			sprintf( 'Prices not yet cached for currency %s. Please wait a moment and try again.', strtoupper( $vs_currency ) )
		);
	}

	// =========================================================================
	// Batch cache management (called by WP-Cron and on cache miss)
	// =========================================================================

	/**
	 * Fetch ALL_COIN_IDS prices in one CoinGecko API call and persist to WP option.
	 * This is the only place that calls CoinGecko — everything else reads the cache.
	 *
	 * @param  string $vs_currency  Lowercase fiat code, e.g. 'usd'
	 * @return array|WP_Error  The populated cache array on success
	 */
	public static function refresh_cache( $vs_currency = 'usd' ) {
		$vs_currency = strtolower( trim( $vs_currency ) );

		if ( ! self::is_currency_supported( $vs_currency ) ) {
			return new WP_Error(
				'fl_unsupported_currency',
				"Currency '{$vs_currency}' is not supported by CoinGecko."
			);
		}

		$url = self::COINGECKO_API . '/simple/price?ids='
			. rawurlencode( implode( ',', self::ALL_COIN_IDS ) )
			. '&vs_currencies=' . rawurlencode( $vs_currency );

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			return new WP_Error( 'fl_price_http', "CoinGecko returned HTTP {$http_code}." );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return new WP_Error( 'fl_price_parse', 'Could not parse CoinGecko price response.' );
		}

		$cache = array(
			'_updated'  => time(),
			'_currency' => $vs_currency,
		);

		foreach ( $data as $coin_id => $prices ) {
			if ( ! isset( $prices[ $vs_currency ] ) || ! is_numeric( $prices[ $vs_currency ] ) ) {
				continue;
			}

			$price = (float) $prices[ $vs_currency ];

			if ( $price <= 0 ) {
				continue;
			}

			// Sanity floor for known coins in USD (M-1)
			if ( $vs_currency === 'usd' && isset( self::PRICE_FLOORS[ $coin_id ] ) ) {
				if ( $price < self::PRICE_FLOORS[ $coin_id ] ) {
					fl_log_warning(
						sprintf(
							'ForgeLayer: price for %s ($%s) is below the safety floor — discarded.',
							$coin_id,
							$price
						),
						array( 'source' => 'forgelayer' )
					);
					continue;
				}
			}

			$cache[ $coin_id ] = $price;
		}

		update_option( self::CACHE_OPTION, $cache, false );

		return $cache;
	}

	/**
	 * Return cache metadata for display in admin (last updated, currency, coin count).
	 *
	 * @return array
	 */
	public static function get_cache_info() {
		$cache    = self::read_cache();
		$updated  = isset( $cache['_updated'] )  ? (int) $cache['_updated']  : 0;
		$currency = isset( $cache['_currency'] ) ? $cache['_currency']       : '';
		$count    = max( 0, count( $cache ) - 2 ); // subtract _updated and _currency keys

		return array(
			'updated'  => $updated,
			'currency' => strtoupper( $currency ),
			'count'    => $count,
			'age'      => $updated ? ( time() - $updated ) : null,
		);
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	private static function read_cache() {
		$cache = get_option( self::CACHE_OPTION, array() );
		return is_array( $cache ) ? $cache : array();
	}

}
