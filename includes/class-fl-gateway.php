<?php
defined( 'ABSPATH' ) || exit;

/**
 * ForgeLayer WooCommerce Payment Gateway.
 */
class FL_Gateway extends WC_Payment_Gateway {

	/**
	 * Native coin config per chain — these never come from the API.
	 */
	private static $chains = array(
		'bitcoin'  => array(
			'label'  => 'Bitcoin',
			'native' => array(
				'symbol'       => 'BTC',
				'name'         => 'Bitcoin',
				'coingecko_id' => 'bitcoin',
				'decimals'     => 8,
				'contract'     => '',
			),
		),
		'ethereum' => array(
			'label'  => 'Ethereum',
			'native' => array(
				'symbol'       => 'ETH',
				'name'         => 'Ethereum',
				'coingecko_id' => 'ethereum',
				'decimals'     => 18,
				'contract'     => '',
			),
		),
		'bsc'      => array(
			'label'  => 'BNB Smart Chain',
			'native' => array(
				'symbol'       => 'BNB',
				'name'         => 'BNB',
				'coingecko_id' => 'binancecoin',
				'decimals'     => 18,
				'contract'     => '',
			),
		),
		'tron'     => array(
			'label'  => 'Tron',
			'native' => array(
				'symbol'       => 'TRX',
				'name'         => 'TRON',
				'coingecko_id' => 'tron',
				'decimals'     => 6,
				'contract'     => '',
			),
		),
	);

	/**
	 * Known symbol → CoinGecko ID mappings for price conversion.
	 * Tokens not in this map will display fiat amount only.
	 */
	/**
	 * Symbol → CoinGecko ID for all tokens with automatic price conversion.
	 * Any token NOT in this map will show "Calculating..." at checkout unless
	 * the merchant adds it via the custom CoinGecko ID field in settings.
	 */
	private static $coingecko_map = array(
		// ── Stablecoins ──────────────────────────────────────────────────────
		'USDT'   => 'tether',
		'USDC'   => 'usd-coin',
		'BUSD'   => 'binance-usd',
		'DAI'    => 'dai',
		'TUSD'   => 'true-usd',
		'USDP'   => 'pax-dollar',
		'FRAX'   => 'frax',
		'LUSD'   => 'liquity-usd',
		'GUSD'   => 'gemini-dollar',
		'USDD'   => 'usdd',
		// ── Wrapped assets ───────────────────────────────────────────────────
		'WBTC'   => 'wrapped-bitcoin',
		'WETH'   => 'weth',
		'WBNB'   => 'wbnb',
		// ── DeFi Blue-chips ───────────────────────────────────────────────────
		'LINK'   => 'chainlink',
		'UNI'    => 'uniswap',
		'AAVE'   => 'aave',
		'COMP'   => 'compound-governance-token',
		'MKR'    => 'maker',
		'SNX'    => 'havven',
		'YFI'    => 'yearn-finance',
		'SUSHI'  => 'sushi',
		'1INCH'  => '1inch',
		'CRV'    => 'curve-dao-token',
		'BAL'    => 'balancer',
		'LDO'    => 'lido-dao',
		// ── Layer 2 / Infrastructure ──────────────────────────────────────────
		'MATIC'  => 'matic-network',
		'ARB'    => 'arbitrum',
		'OP'     => 'optimism',
		'GRT'    => 'the-graph',
		// ── Meme / Culture tokens ─────────────────────────────────────────────
		'SHIB'   => 'shiba-inu',
		'PEPE'   => 'pepe',
		'FLOKI'  => 'floki',
		'DOGE'   => 'dogecoin',
		// ── Gaming / Metaverse ────────────────────────────────────────────────
		'SAND'   => 'the-sandbox',
		'MANA'   => 'decentraland',
		'AXS'    => 'axie-infinity',
		'APE'    => 'apecoin',
		'IMX'    => 'immutable-x',
		// ── Exchange tokens ───────────────────────────────────────────────────
		'CRO'    => 'crypto-com-chain',
		'FTT'    => 'ftx-token',
		// ── BSC-native ────────────────────────────────────────────────────────
		'CAKE'   => 'pancakeswap-token',
		'XVS'    => 'venus',
		// ── Tron ecosystem ───────────────────────────────────────────────────
		'BTT'    => 'bittorrent',
		'WIN'    => 'wink',
		'JST'    => 'just',
		'SUN'    => 'sun-token',
		// ── Other popular ────────────────────────────────────────────────────
		'BAT'    => 'basic-attention-token',
		'ZRX'    => '0x',
		'ENS'    => 'ethereum-name-service',
		'CHZ'    => 'chiliz',
		'GALA'   => 'gala',
		'FTM'    => 'fantom',
		'GMT'    => 'stepn',
	);

	/**
	 * Human-readable token details for the Supported Tokens display in settings.
	 * Format: SYMBOL => [ name, chains[] ]
	 * chains: E=Ethereum, B=BSC, T=Tron (just for display — actual availability
	 * depends on what the merchant has configured in ForgeLayer)
	 */
	private static $token_directory = array(
		'USDT'  => array( 'Tether USD',              array( 'E', 'B', 'T' ) ),
		'USDC'  => array( 'USD Coin',                array( 'E', 'B', 'T' ) ),
		'BUSD'  => array( 'Binance USD',             array( 'E', 'B' ) ),
		'DAI'   => array( 'Dai Stablecoin',          array( 'E', 'B' ) ),
		'TUSD'  => array( 'TrueUSD',                 array( 'E', 'B', 'T' ) ),
		'USDP'  => array( 'Pax Dollar',              array( 'E' ) ),
		'FRAX'  => array( 'Frax',                    array( 'E', 'B' ) ),
		'LUSD'  => array( 'Liquity USD',             array( 'E' ) ),
		'GUSD'  => array( 'Gemini Dollar',           array( 'E' ) ),
		'USDD'  => array( 'Decentralized USD',       array( 'E', 'B', 'T' ) ),
		'WBTC'  => array( 'Wrapped Bitcoin',         array( 'E', 'B' ) ),
		'WETH'  => array( 'Wrapped Ether',           array( 'B' ) ),
		'WBNB'  => array( 'Wrapped BNB',             array( 'B' ) ),
		'LINK'  => array( 'Chainlink',               array( 'E', 'B' ) ),
		'UNI'   => array( 'Uniswap',                 array( 'E', 'B' ) ),
		'AAVE'  => array( 'Aave',                    array( 'E', 'B' ) ),
		'COMP'  => array( 'Compound',                array( 'E', 'B' ) ),
		'MKR'   => array( 'Maker',                   array( 'E' ) ),
		'SNX'   => array( 'Synthetix',               array( 'E', 'B' ) ),
		'YFI'   => array( 'yearn.finance',           array( 'E' ) ),
		'SUSHI' => array( 'SushiSwap',               array( 'E', 'B' ) ),
		'1INCH' => array( '1inch',                   array( 'E', 'B' ) ),
		'CRV'   => array( 'Curve DAO Token',         array( 'E', 'B' ) ),
		'BAL'   => array( 'Balancer',                array( 'E' ) ),
		'LDO'   => array( 'Lido DAO',                array( 'E' ) ),
		'MATIC' => array( 'Polygon',                 array( 'E', 'B' ) ),
		'ARB'   => array( 'Arbitrum',                array( 'E' ) ),
		'OP'    => array( 'Optimism',                array( 'E' ) ),
		'GRT'   => array( 'The Graph',               array( 'E', 'B' ) ),
		'SHIB'  => array( 'Shiba Inu',               array( 'E', 'B' ) ),
		'PEPE'  => array( 'Pepe',                    array( 'E' ) ),
		'FLOKI' => array( 'FLOKI',                   array( 'E', 'B' ) ),
		'DOGE'  => array( 'Dogecoin (wrapped)',       array( 'E', 'B' ) ),
		'SAND'  => array( 'The Sandbox',             array( 'E', 'B' ) ),
		'MANA'  => array( 'Decentraland',            array( 'E', 'B' ) ),
		'AXS'   => array( 'Axie Infinity',           array( 'E', 'B' ) ),
		'APE'   => array( 'ApeCoin',                 array( 'E', 'B' ) ),
		'IMX'   => array( 'Immutable X',             array( 'E' ) ),
		'CRO'   => array( 'Cronos',                  array( 'E', 'B' ) ),
		'CAKE'  => array( 'PancakeSwap',             array( 'B' ) ),
		'XVS'   => array( 'Venus',                   array( 'B' ) ),
		'BTT'   => array( 'BitTorrent',              array( 'T' ) ),
		'WIN'   => array( 'WINkLink',                array( 'T' ) ),
		'JST'   => array( 'JUST',                    array( 'T' ) ),
		'SUN'   => array( 'Sun Token',               array( 'T' ) ),
		'BAT'   => array( 'Basic Attention Token',   array( 'E', 'B' ) ),
		'ZRX'   => array( '0x Protocol',             array( 'E', 'B' ) ),
		'ENS'   => array( 'Ethereum Name Service',   array( 'E' ) ),
		'CHZ'   => array( 'Chiliz',                  array( 'E', 'B' ) ),
		'GALA'  => array( 'Gala',                    array( 'E', 'B' ) ),
		'FTM'   => array( 'Fantom',                  array( 'E', 'B' ) ),
		'GMT'   => array( 'STEPN',                   array( 'E', 'B' ) ),
	);

	/** Cache duration for fetched token lists. */
	const TOKEN_CACHE_SECONDS = 3600; // 1 hour

	/**
	 * Tracks whether singleton hooks (those that must fire exactly once per request)
	 * have already been registered. Prevents duplicate output when the gateway is
	 * instantiated multiple times — e.g. by AJAX handlers, the blocks integration,
	 * or WooCommerce's own gateway loader all calling new FL_Gateway() in one request.
	 */
	private static $singleton_hooks_registered = false;

	public function __construct() {
		$this->id                 = 'forgelayer';
		$this->has_fields         = true;
		$this->method_title       = __( 'ForgeLayer Crypto Payments', 'forgelayer-crypto-payments-for-woocommerce' );
		$this->method_description = __( 'Accept Bitcoin, Ethereum (ERC-20), BNB Smart Chain (BEP-20), and Tron (TRC-20) payments via ForgeLayer.', 'forgelayer-crypto-payments-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// These hooks must run on every instance because they are settings-related
		// (WooCommerce calls process_admin_options on the authoritative instance).
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'refresh_price_cache_on_save' ) );

		// These hooks must fire exactly once per request — guard against duplicate
		// registrations caused by multiple FL_Gateway instantiations.
		if ( ! self::$singleton_hooks_registered ) {
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
			add_action( 'wp', array( $this, 'maybe_set_security_headers' ) );
			add_action( 'admin_notices', array( $this, 'currency_support_notice' ) );
			self::$singleton_hooks_registered = true;
		}
	}

	/**
	 * Disable the gateway at checkout when the store currency is not in CoinGecko's
	 * supported list — prevents orders that can never have their crypto amount calculated.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! FL_Price::is_currency_supported( get_woocommerce_currency() ) ) {
			return false;
		}
		if ( empty( $this->get_enabled_options() ) ) {
			return false;
		}
		// Block checkout if API requests are exhausted — no ForgeLayer calls can succeed
		$cached = get_option( 'fl_usage_cache', array() );
		if ( ! empty( $cached['percentages']['apiRequests'] ) && (int) $cached['percentages']['apiRequests'] >= 100
			&& isset( $cached['limits']['apiRequests'] ) && $cached['limits']['apiRequests'] !== -1 ) {
			return false;
		}

		// Block checkout for hard subscription errors (expired, pending, no subscription)
		// LIMIT_EXCEEDED is NOT in this list — address reuse may still allow checkout
		$hard_block_codes = array( 'SUBSCRIPTION_EXPIRED', 'SUBSCRIPTION_PENDING', 'NO_SUBSCRIPTION' );
		$sub_error        = get_transient( 'fl_subscription_error' );
		if ( ! empty( $sub_error['code'] ) && in_array( $sub_error['code'], $hard_block_codes, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Show admin notices for misconfigured gateway states.
	 */
	public function currency_support_notice() {
		if ( $this->enabled !== 'yes' ) {
			return;
		}

		$currency = get_woocommerce_currency();

		if ( ! FL_Price::is_currency_supported( $currency ) ) {
			$supported = implode( ', ', FL_Price::SUPPORTED_CURRENCIES );
			echo '<div class="notice notice-error"><p>'
				. sprintf(
					wp_kses_post( __( '<strong>ForgeLayer Payments</strong> is enabled but your store currency <strong>%1$s</strong> is not supported. The gateway will be hidden at checkout. Supported currencies: %2$s', 'forgelayer-crypto-payments-for-woocommerce' ) ),
					esc_html( $currency ),
					esc_html( $supported )
				)
				. '</p></div>';
			return;
		}

		if ( empty( $this->get_enabled_options() ) ) {
			echo '<div class="notice notice-warning"><p>'
				. wp_kses_post( sprintf(
					/* translators: %s: URL to ForgeLayer settings page */
					__( '<strong>ForgeLayer Payments</strong> is enabled but no chains or tokens are active. Go to <a href="%s">ForgeLayer settings</a>, enable at least one chain and save.', 'forgelayer-crypto-payments-for-woocommerce' ),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=forgelayer' ) )
				) )
				. '</p></div>';
		}

		// Subscription error from a failed checkout attempt
		$sub_error = get_transient( 'fl_subscription_error' );
		if ( ! empty( $sub_error['code'] ) ) {
			$notices = array(
				'LIMIT_EXCEEDED'       => __( 'Address limit reached. New addresses cannot be generated. Address reuse may still work.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'SUBSCRIPTION_EXPIRED' => __( 'Subscription expired. Cryptocurrency checkout is fully blocked. Renew at forgelayer.io/dashboard/billing.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'SUBSCRIPTION_PENDING' => __( 'Subscription pending payment. Cryptocurrency checkout is blocked. Complete payment at forgelayer.io/dashboard/billing.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'NO_SUBSCRIPTION'      => __( 'No active ForgeLayer subscription. Cryptocurrency checkout is blocked. Subscribe at forgelayer.io/dashboard/billing.', 'forgelayer-crypto-payments-for-woocommerce' ),
			);
			$notice_text = isset( $notices[ $sub_error['code'] ] ) ? $notices[ $sub_error['code'] ] : sanitize_text_field( $sub_error['message'] );
			echo '<div class="notice notice-error"><p>'
				. '<strong>' . esc_html__( 'ForgeLayer:', 'forgelayer-crypto-payments-for-woocommerce' ) . '</strong> '
				. esc_html( $notice_text )
				. '</p></div>';
		}

		// Usage warnings from cached data
		$cached = get_option( 'fl_usage_cache', array() );
		if ( empty( $cached ) || isset( $cached['error'] ) ) {
			return;
		}

		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=forgelayer' );
		$resource_labels = array(
			'addresses'   => __( 'Wallet Addresses', 'forgelayer-crypto-payments-for-woocommerce' ),
			'webhooks'    => __( 'Webhooks', 'forgelayer-crypto-payments-for-woocommerce' ),
			'apiRequests' => __( 'API Requests', 'forgelayer-crypto-payments-for-woocommerce' ),
		);

		foreach ( $resource_labels as $key => $label ) {
			$pct     = (int) ( $cached['percentages'][ $key ] ?? 0 );
			$limit   = $cached['limits'][ $key ] ?? -1;
			if ( $limit === -1 || $pct < 80 ) {
				continue;
			}
			if ( $pct >= 100 ) {
				echo '<div class="notice notice-error"><p>'
					. sprintf(
						wp_kses_post( __( '<strong>ForgeLayer: %1$s limit reached (100%%).</strong> %2$s Go to <a href="%3$s">ForgeLayer settings</a> or upgrade your plan.', 'forgelayer-crypto-payments-for-woocommerce' ) ),
						esc_html( $label ),
						$key === 'apiRequests' ? esc_html__( 'Cryptocurrency checkout is disabled until this resets.', 'forgelayer-crypto-payments-for-woocommerce' ) : esc_html__( 'New resources cannot be created until this resets.', 'forgelayer-crypto-payments-for-woocommerce' ),
						esc_url( $settings_url )
					)
					. '</p></div>';
			} elseif ( $pct >= 90 ) {
				echo '<div class="notice notice-warning"><p>'
					. sprintf(
						wp_kses_post( __( '<strong>ForgeLayer: %1$s at %2$d%%.</strong> Approaching your plan limit. <a href="%3$s">View usage</a> or upgrade your plan before customers are affected.', 'forgelayer-crypto-payments-for-woocommerce' ) ),
						esc_html( $label ),
						$pct,
						esc_url( $settings_url )
					)
					. '</p></div>';
			} else {
				echo '<div class="notice notice-warning" style="border-left-color:#d97706"><p>'
					. sprintf(
						wp_kses_post( __( '<strong>ForgeLayer: %1$s at %2$d%%.</strong> <a href="%3$s">View usage</a>.', 'forgelayer-crypto-payments-for-woocommerce' ) ),
						esc_html( $label ),
						$pct,
						esc_url( $settings_url )
					)
					. '</p></div>';
			}
		}
	}

	/**
	 * Render the account usage overview in settings.
	 */
	public function generate_account_usage_html( $key, $data ) {
		$api_key = $this->get_option( 'api_key', '' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc" style="vertical-align:top;padding-top:14px">
				<?php esc_html_e( 'Usage Overview', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
			</th>
			<td class="forminp">
		<?php if ( empty( $api_key ) ) : ?>
				<p class="description"><?php esc_html_e( 'Enter and save your API key to see usage.', 'forgelayer-crypto-payments-for-woocommerce' ); ?></p>
		<?php else : ?>
				<?php
				// Render the three resource rows as a static skeleton.
				// JS fills in live values on page load via AJAX.
				$resource_defs = array(
					'addresses'   => __( 'Wallet Addresses', 'forgelayer-crypto-payments-for-woocommerce' ),
					'webhooks'    => __( 'Webhooks', 'forgelayer-crypto-payments-for-woocommerce' ),
					'apiRequests' => __( 'API Requests', 'forgelayer-crypto-payments-for-woocommerce' ),
				);
				?>
				<div id="fl-usage-grid" style="max-width:480px">
				<?php foreach ( $resource_defs as $res_key => $res_label ) : ?>
					<div style="margin-bottom:14px">
						<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
							<strong><?php echo esc_html( $res_label ); ?></strong>
							<span id="fl-usage-count-<?php echo esc_attr( $res_key ); ?>" style="color:#6b7280">
								<?php esc_html_e( 'Loading…', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
							</span>
						</div>
						<div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden">
							<div id="fl-usage-bar-<?php echo esc_attr( $res_key ); ?>"
							     style="height:100%;width:0%;background:#16a34a;transition:width .4s ease"></div>
						</div>
						<div id="fl-usage-pct-<?php echo esc_attr( $res_key ); ?>"
						     style="font-size:11px;color:#6b7280;margin-top:3px"></div>
					</div>
				<?php endforeach; ?>
				</div>

				<p class="description" style="margin-top:6px">
					<span id="fl-usage-reset"></span>
					<span id="fl-usage-fetched"></span>
				</p>
		<?php endif; ?>

				<button type="button" id="fl-refresh-usage" class="button button-secondary"
				        style="margin-top:8px" <?php echo empty( $api_key ) ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Refresh Usage', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
				</button>
				<span id="fl-usage-status" style="margin-left:10px;font-style:italic;color:#6b7280"></span>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the price cache status row in settings.
	 */
	public function generate_price_cache_status_html( $key, $data ) {
		$info     = FL_Price::get_cache_info();
		$currency = get_woocommerce_currency();

		if ( ! FL_Price::is_currency_supported( $currency ) ) {
			$msg = sprintf(
				__( 'Store currency <strong>%s</strong> is not supported. Supported currencies: %s', 'forgelayer-crypto-payments-for-woocommerce' ),
				esc_html( $currency ),
				esc_html( implode( ', ', FL_Price::SUPPORTED_CURRENCIES ) )
			);
			$color = '#b00';
		} elseif ( ! $info['updated'] ) {
			$msg   = __( 'Not yet cached. Prices will be fetched on the next WP-Cron run or on the first order.', 'forgelayer-crypto-payments-for-woocommerce' );
			$color = '#996600';
		} else {
			$age              = $info['age'];
			$ttl              = FL_Price::get_cache_ttl();
			$store_currency   = strtoupper( get_woocommerce_currency() );
			$cache_currency   = strtoupper( $info['currency'] );
			$currency_mismatch = $cache_currency && $store_currency !== $cache_currency;

			if ( $currency_mismatch ) {
				$msg   = sprintf(
					__( 'Cache currency (%1$s) does not match store currency (%2$s). Prices are being refreshed for %2$s now.', 'forgelayer-crypto-payments-for-woocommerce' ),
					esc_html( $cache_currency ),
					esc_html( $store_currency )
				);
				$color = '#996600';
				// Trigger an async refresh so next page load has the right currency
				FL_Price::refresh_cache( strtolower( $store_currency ) );
			} else {
				$msg = sprintf(
					/* translators: 1: coin count, 2: currency, 3: age, 4: TTL seconds */
					__( '%1$d coin prices cached in %2$s &mdash; refreshed %3$s ago &mdash; refresh interval: %4$ss.', 'forgelayer-crypto-payments-for-woocommerce' ),
					$info['count'],
					esc_html( $cache_currency ),
					esc_html( human_time_diff( $info['updated'] ) ),
					$ttl
				);
				$color = $age <= $ttl ? '#276b27' : '#996600';
			}
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php esc_html_e( 'Price Cache', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
			</th>
			<td class="forminp" style="color:<?php echo esc_attr( $color ); ?>">
				<?php echo wp_kses_post( $msg ); ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Refresh the price cache whenever the merchant saves gateway settings.
	 * Ensures prices are available immediately after a currency change or first setup.
	 */
	public function refresh_price_cache_on_save() {
		$currency = strtolower( get_woocommerce_currency() );
		if ( FL_Price::is_currency_supported( $currency ) ) {
			FL_Price::refresh_cache( $currency );
		}
	}

	/**
	 * Emit comprehensive security headers on ForgeLayer payment pages (L-6).
	 * Includes frame protection, CSP, XSS protection, content-type sniff prevention,
	 * and referrer policy.
	 */
	public function maybe_set_security_headers() {
		if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			if ( ! headers_sent() ) {
				// Clickjacking protection
				header( 'X-Frame-Options: SAMEORIGIN' );

				// Content sniffing prevention
				header( 'X-Content-Type-Options: nosniff' );

				// Legacy XSS filter (IE/older Chrome)
				header( 'X-XSS-Protection: 1; mode=block' );

				// Referrer policy — do not leak order data in Referer header
				header( 'Referrer-Policy: strict-origin-when-cross-origin' );

				// Tight Content Security Policy for the payment page:
				// - Scripts: only same-origin plugin scripts
				// - Styles:  same-origin + inline (WooCommerce adds inline styles)
				// - Images:  same-origin + qrserver.com (QR code) + data URIs
				// - Fonts:   same-origin only
				// - Connect: same-origin AJAX
				// - frame-ancestors: 'self' (replaces X-Frame-Options)
				$plugin_url = esc_url_raw( FL_PLUGIN_URL );
				header(
					"Content-Security-Policy: " .
					"default-src 'self'; " .
					"script-src 'self'; " .
					"style-src 'self' 'unsafe-inline'; " .
					"img-src 'self' https://api.qrserver.com data:; " .
					"font-src 'self'; " .
					"connect-src 'self'; " .
					"frame-ancestors 'self'; " .
					"object-src 'none'; " .
					"base-uri 'self';"
				);
			}
		}
	}

	// =========================================================================
	// Token cache
	// =========================================================================

	/**
	 * Return the cached token list for a chain.
	 * Returns an empty array if tokens have not been fetched yet.
	 *
	 * @param  string $chain_id
	 * @return array
	 */
	public function get_chain_tokens( $chain_id ) {
		$stored = get_option( 'fl_tokens_' . sanitize_key( $chain_id ), array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		// Validate and sanitize every token entry on read to guard against
		// tampered or corrupted option data (M-8)
		$validated = array();
		foreach ( $stored as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$symbol = strtoupper( sanitize_text_field( isset( $token['symbol'] ) ? $token['symbol'] : '' ) );
			// Symbols must be 1–20 uppercase alphanumeric characters
			if ( ! $symbol || ! preg_match( '/^[A-Z0-9]{1,20}$/', $symbol ) ) {
				continue;
			}

			$decimals = isset( $token['decimals'] ) ? (int) $token['decimals'] : 18;
			// Reject out-of-range decimals
			if ( $decimals < 0 || $decimals > 18 ) {
				continue;
			}

			$validated[] = array(
				'symbol'   => $symbol,
				'name'     => sanitize_text_field( isset( $token['name'] ) ? $token['name'] : $symbol ),
				'contract' => sanitize_text_field( isset( $token['contract'] ) ? $token['contract'] : '' ),
				'decimals' => $decimals,
				'enabled'  => isset( $token['enabled'] ) ? (bool) $token['enabled'] : true,
			);
		}

		return $validated;
	}

	/**
	 * Fetch tokens for all enabled chains from the ForgeLayer API and cache them.
	 * Returns an associative array: chain_id => tokens[] or WP_Error.
	 *
	 * @return array { chain_id: array|WP_Error }
	 */
	public function refresh_tokens() {
		$api_key = $this->get_option( 'api_key' );
		if ( ! $api_key ) {
			return array();
		}

		$api     = new FL_API( $api_key );
		$results = array();

		foreach ( array_keys( self::$chains ) as $chain_id ) {
			$tokens = $api->list_tokens( $chain_id );

			if ( is_wp_error( $tokens ) ) {
				$results[ $chain_id ] = $tokens;
				continue;
			}

			// Always sanitize_key before building option/transient names (M-5)
			update_option( 'fl_tokens_' . sanitize_key( $chain_id ), $tokens, false );
			set_transient( 'fl_tokens_fresh_' . sanitize_key( $chain_id ), 1, self::TOKEN_CACHE_SECONDS );
			$results[ $chain_id ] = $tokens;
		}

		return $results;
	}

	/**
	 * Fetch tokens for a single chain if the cache is stale, then return them.
	 *
	 * @param  string $chain_id
	 * @return array
	 */
	private function maybe_refresh_chain_tokens( $chain_id ) {
		$safe_chain = sanitize_key( $chain_id );
		if ( ! get_transient( 'fl_tokens_fresh_' . $safe_chain ) ) {
			$api_key = $this->get_option( 'api_key' );
			if ( $api_key ) {
				$api    = new FL_API( $api_key );
				$tokens = $api->list_tokens( $chain_id );
				if ( ! is_wp_error( $tokens ) ) {
					update_option( 'fl_tokens_' . $safe_chain, $tokens, false );
					set_transient( 'fl_tokens_fresh_' . $safe_chain, 1, self::TOKEN_CACHE_SECONDS );
				}
			}
		}

		return $this->get_chain_tokens( $chain_id );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public function init_form_fields() {
		$fields = array(
			'enabled' => array(
				'title'   => __( 'Enable / Disable', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable ForgeLayer Crypto Payments', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to customers at checkout.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'     => __( 'Pay with Cryptocurrency', 'forgelayer-crypto-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely using Bitcoin, Ethereum, BNB, or Tron. A unique wallet address will be generated for your order.', 'forgelayer-crypto-payments-for-woocommerce' ),
			),

			// ---- API ----
			'api_section' => array(
				'title' => __( 'ForgeLayer API', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'title',
			),
			'api_key' => array(
				'title'       => __( 'API Key', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your ForgeLayer API key. Found in your ForgeLayer dashboard under API Settings. Stored in the WordPress options table — restrict admin access and protect database backups accordingly.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_mode' => array(
				'title'   => __( 'Sandbox / Test Mode', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable sandbox mode (use a test API key starting with flk_test_)', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default' => 'yes',
			),

			// ---- Price refresh interval ----
			'price_refresh_interval' => array(
				'title'       => __( 'Price Refresh Interval', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'How often WP-Cron refreshes cryptocurrency prices in the background. Prices are cached in the database — checkout never calls CoinGecko directly. Crypto prices are stable over minutes; 5 minutes matches industry standard (BitPay, Coinbase Commerce).', 'forgelayer-crypto-payments-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => '300',
				'options'     => array(
					'60'  => __( '1 minute', 'forgelayer-crypto-payments-for-woocommerce' ),
					'120' => __( '2 minutes', 'forgelayer-crypto-payments-for-woocommerce' ),
					'300' => __( '5 minutes (Recommended)', 'forgelayer-crypto-payments-for-woocommerce' ),
					'600' => __( '10 minutes', 'forgelayer-crypto-payments-for-woocommerce' ),
					'900' => __( '15 minutes', 'forgelayer-crypto-payments-for-woocommerce' ),
				),
			),

			// ---- Account usage ----
			'account_usage_section' => array(
				'title'       => __( 'Account Usage', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Live usage against your ForgeLayer plan limits. Refreshes every 30 minutes in the background. You will receive an email and see a notice here when any resource reaches 80%.', 'forgelayer-crypto-payments-for-woocommerce' ),
			),
			'account_usage' => array(
				'title' => __( 'Usage Overview', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'account_usage',
			),

			// ---- Price cache status ----
			'price_cache_status' => array(
				'title' => __( 'Price Cache', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'price_cache_status',
			),

			// ---- Payment ----
			'payment_section' => array(
				'title' => __( 'Payment Settings', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'title',
			),
			'payment_window' => array(
				'title'             => __( 'Payment Window (minutes)', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Minutes the customer has to send payment before the order is cancelled.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'           => '30',
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => '5', 'max' => '1440', 'step' => '1' ),
			),
			'accept_late_payments' => array(
				'title'       => __( 'Accept Late Payments', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Reopen and fulfil orders when payment arrives after the window expires', 'forgelayer-crypto-payments-for-woocommerce' ),
				'description' => __( 'Blockchain addresses never expire — funds sent after the window are received in your ForgeLayer wallet regardless. Enabling this automatically reopens the cancelled order when ForgeLayer confirms the deposit. Recommended: ON.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'     => 'yes',
			),
			'late_payment_grace' => array(
				'title'             => __( 'Late Payment Grace Period (minutes)', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'How many minutes after the payment window expires you still want to auto-reopen the order. Payments arriving later than this are held for manual review — you receive an admin email to decide. Set to 0 to always require manual review for any late payment.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'           => '60',
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => '0', 'max' => '10080', 'step' => '1' ),
			),
			'reuse_addresses' => array(
				'title'       => __( 'Reuse Inactive Addresses', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Reuse addresses from completed or cancelled orders instead of generating a new one each time', 'forgelayer-crypto-payments-for-woocommerce' ),
				'description' => __( 'When enabled, the plugin first checks for an existing address on the same chain that is no longer active. Only generates a new address if none is available. The previous balance is snapshotted so old funds never trigger a false confirmation.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'     => 'no',
			),

			// ---- Rate limiting ----
			'rate_section' => array(
				'title'       => __( 'Traffic & Rate Limiting', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Configure request limits to balance security against high-traffic tolerance. Increase these values for busy stores, B2B merchants, or sites behind a CDN/proxy where many customers share one IP address.', 'forgelayer-crypto-payments-for-woocommerce' ),
			),
			'rate_poll_interval' => array(
				'title'             => __( 'Payment Status Poll Interval (seconds)', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Minimum seconds between payment status checks per customer. Lower = more responsive confirmation. Higher = less server load. Recommended: 10.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'           => '10',
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => '5', 'max' => '60', 'step' => '1' ),
			),
			'rate_lockout_threshold' => array(
				'title'             => __( 'Brute-Force Lockout Threshold', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Number of failed payment status attempts before an IP is temporarily locked out for that order. Increase for shared-IP environments (offices, CDNs). Each customer\'s lockout is scoped to their own order — one bad actor cannot block others. Recommended: 10.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'           => '10',
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => '3', 'max' => '100', 'step' => '1' ),
			),

			// ---- Webhook ----
			'webhook_section' => array(
				'title'       => __( 'Webhook Configuration', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Webhooks give instant payment confirmation without polling. ForgeLayer sends a signed POST to your site whenever a deposit is confirmed.', 'forgelayer-crypto-payments-for-woocommerce' ),
			),
			'webhook_url_display' => array(
				'title' => __( 'Your Webhook URL', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'webhook_url_display',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook Secret', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'HMAC-SHA256 key used to verify payloads from ForgeLayer. Auto-filled when you click "Setup Webhook" below.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'webhook_confirmation_levels' => array(
				'title'             => __( 'Required Confirmations', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Number of on-chain confirmations before ForgeLayer fires the deposit_confirmed event.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'           => '1',
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => '1', 'max' => '100', 'step' => '1' ),
			),
			'webhook_setup' => array(
				'title' => __( 'Register Webhook', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'webhook_setup_button',
			),

			// ---- Supported tokens reference ----
			'supported_tokens_section' => array(
				'title'       => __( 'Supported Tokens for Price Conversion', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'These tokens have automatic price conversion via CoinGecko. Add any of them to your ForgeLayer account and they will work out of the box. Tokens outside this list will show the order total in fiat at checkout.', 'forgelayer-crypto-payments-for-woocommerce' ),
			),
			'supported_tokens_list' => array(
				'title' => __( 'Token Directory', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'supported_tokens_list',
			),
			'custom_coingecko_ids' => array(
				'title'       => __( 'Custom CoinGecko IDs', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'For tokens not in the directory above, add their CoinGecko IDs here. One per line: <code>SYMBOL|coingecko-id</code><br>Example: <code>MYTOKEN|my-token-coingecko-id</code><br>Find IDs at coingecko.com — it\'s the slug in the URL (e.g. coingecko.com/en/coins/<strong>shiba-inu</strong>).', 'forgelayer-crypto-payments-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => false,
			),

			// ---- Token refresh ----
			'token_section' => array(
				'title'       => __( 'Supported Chains & Tokens', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Tokens are fetched directly from your ForgeLayer account. Click <strong>Refresh Token List</strong> after adding or removing tokens in your ForgeLayer dashboard.', 'forgelayer-crypto-payments-for-woocommerce' ),
			),
			'token_refresh' => array(
				'title' => __( 'Token List', 'forgelayer-crypto-payments-for-woocommerce' ),
				'type'  => 'token_refresh_button',
			),
		);

		// Per-chain: enable toggle + native coin + fetched tokens
		foreach ( self::$chains as $chain_id => $chain ) {
			$fields[ "chain_{$chain_id}_enabled" ] = array(
				'title'   => sprintf( __( 'Enable %s', 'forgelayer-crypto-payments-for-woocommerce' ), $chain['label'] ),
				'type'    => 'checkbox',
				'label'   => sprintf( __( 'Accept payments on the %s network', 'forgelayer-crypto-payments-for-woocommerce' ), $chain['label'] ),
				'default' => 'no',
			);

			// Native coin is always available
			$n = $chain['native'];
			$fields[ "token_{$chain_id}_native" ] = array(
				'title'   => sprintf( '%s (%s)', $n['name'], $n['symbol'] ),
				'type'    => 'checkbox',
				'label'   => sprintf( __( 'Accept %s (native coin)', 'forgelayer-crypto-payments-for-woocommerce' ), $n['symbol'] ),
				'default' => 'yes',
			);

			// Fetched tokens — add a checkbox for each
			foreach ( $this->get_chain_tokens( $chain_id ) as $token ) {
				$symbol = isset( $token['symbol'] ) ? $token['symbol'] : '';
				$name   = isset( $token['name'] )   ? $token['name']   : $symbol;
				if ( ! $symbol ) {
					continue;
				}
				// Use sanitize_key() for the option key so special chars cannot pollute the options table (H-3)
				$safe_key = sanitize_key( $symbol );
				$fields[ "token_{$chain_id}_{$safe_key}" ] = array(
					'title'   => esc_html( $name ),
					'type'    => 'checkbox',
					'label'   => sprintf( __( 'Accept %s', 'forgelayer-crypto-payments-for-woocommerce' ), esc_html( $symbol ) ),
					'default' => 'yes',
				);
			}
		}

		$this->form_fields = $fields;
	}

	/**
	 * Render the custom "token_refresh_button" field type.
	 *
	 * @param  string $key
	 * @param  array  $data
	 */
	public function generate_token_refresh_button_html( $key, $data ) {
		$has_key     = (bool) $this->get_option( 'api_key' );
		$last_synced = get_option( 'fl_tokens_last_synced', 0 );
		$synced_msg  = $last_synced
			? sprintf( __( 'Last synced: %s', 'forgelayer-crypto-payments-for-woocommerce' ), esc_html( human_time_diff( $last_synced ) . ' ago' ) )
			: __( 'Not synced yet.', 'forgelayer-crypto-payments-for-woocommerce' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html( $data['title'] ); ?>
			</th>
			<td class="forminp">
				<?php if ( ! $has_key ) : ?>
					<p class="description">
						<?php esc_html_e( 'Enter and save your API key above, then click Refresh to load your ForgeLayer tokens.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
					</p>
				<?php else : ?>
					<button type="button" id="fl-refresh-tokens" class="button button-secondary">
						<?php esc_html_e( 'Refresh Token List', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
					</button>
					<span id="fl-refresh-status" style="margin-left:10px;font-style:italic;color:#666">
						<?php echo esc_html( $synced_msg ); ?>
					</span>
					<p class="description" style="margin-top:6px">
						<?php esc_html_e( 'Fetches the latest tokens from your ForgeLayer account for all chains. The page will reload to update the token checkboxes below.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the supported token directory table.
	 * Groups tokens by chain compatibility so merchants can easily find
	 * what to add in their ForgeLayer dashboard.
	 */
	public function generate_supported_tokens_list_html( $key, $data ) {
		$chain_labels = array(
			'E' => '<span style="background:#627eea;color:#fff;border-radius:3px;padding:1px 5px;font-size:10px;font-weight:700">ETH</span>',
			'B' => '<span style="background:#f0b90b;color:#000;border-radius:3px;padding:1px 5px;font-size:10px;font-weight:700">BSC</span>',
			'T' => '<span style="background:#ef0027;color:#fff;border-radius:3px;padding:1px 5px;font-size:10px;font-weight:700">TRX</span>',
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc" style="vertical-align:top;padding-top:14px">
				<?php esc_html_e( 'Token Directory', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
			</th>
			<td class="forminp">
				<input type="text" id="fl-token-search"
				       placeholder="<?php esc_attr_e( 'Search tokens…', 'forgelayer-crypto-payments-for-woocommerce' ); ?>"
				       style="width:240px;margin-bottom:10px;padding:5px 8px;border:1px solid #ddd;border-radius:4px">

				<table id="fl-token-table"
				       style="border-collapse:collapse;width:100%;max-width:680px;font-size:13px">
					<thead>
						<tr style="background:#f9f9f9;border-bottom:2px solid #e0e0e0">
							<th style="padding:7px 10px;text-align:left"><?php esc_html_e( 'Symbol', 'forgelayer-crypto-payments-for-woocommerce' ); ?></th>
							<th style="padding:7px 10px;text-align:left"><?php esc_html_e( 'Token Name', 'forgelayer-crypto-payments-for-woocommerce' ); ?></th>
							<th style="padding:7px 10px;text-align:left"><?php esc_html_e( 'Networks', 'forgelayer-crypto-payments-for-woocommerce' ); ?></th>
							<th style="padding:7px 10px;text-align:left"><?php esc_html_e( 'Price', 'forgelayer-crypto-payments-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$i = 0;
					foreach ( self::$token_directory as $symbol => $info ) {
						list( $name, $chains ) = $info;
						$row_bg = $i % 2 === 0 ? '#fff' : '#fafafa';
						$chain_tags = implode( ' ', array_map( function( $c ) use ( $chain_labels ) {
							return isset( $chain_labels[ $c ] ) ? $chain_labels[ $c ] : esc_html( $c );
						}, $chains ) );
						?>
						<tr class="fl-token-row" style="background:<?php echo esc_attr( $row_bg ); ?>;border-bottom:1px solid #f0f0f0">
							<td style="padding:6px 10px;font-weight:700;font-family:monospace">
								<?php echo esc_html( $symbol ); ?>
							</td>
							<td style="padding:6px 10px;color:#444">
								<?php echo esc_html( $name ); ?>
							</td>
							<td style="padding:6px 10px">
								<?php echo wp_kses( $chain_tags, array( 'span' => array( 'style' => array() ) ) ); ?>
							</td>
							<td style="padding:6px 10px;color:#27ae60;font-weight:600">Yes</td>
						</tr>
						<?php
						$i++;
					}
					?>
					</tbody>
				</table>

				<p class="description" style="margin-top:10px;max-width:680px">
					<?php
					printf(
						wp_kses_post( __( '<strong>%d tokens</strong> supported. To add a token not listed here, first add it in your <a href="https://forgelayer.io/dashboard" target="_blank" rel="noopener">ForgeLayer dashboard</a> then enter its CoinGecko ID in the field below.', 'forgelayer-crypto-payments-for-woocommerce' ) ),
						count( self::$token_directory )
					);
					?>
				</p>

				<script>
				document.getElementById('fl-token-search').addEventListener('input', function() {
					var q = this.value.toLowerCase();
					document.querySelectorAll('.fl-token-row').forEach(function(row) {
						row.style.display = row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
					});
				});
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the read-only webhook URL display field.
	 */
	public function generate_webhook_url_display_html( $key, $data ) {
		$webhook_url = rest_url( 'forgelayer/v1/webhook' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php esc_html_e( 'Your Webhook URL', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
			</th>
			<td class="forminp">
				<input type="text" readonly
				       value="<?php echo esc_attr( $webhook_url ); ?>"
				       style="width:100%;max-width:520px;font-family:monospace"
				       onclick="this.select()">
				<p class="description">
					<?php esc_html_e( 'Copy this URL into the "Setup Webhook" button below — it will be registered automatically.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
				</p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the "Setup Webhook" action button.
	 */
	public function generate_webhook_setup_button_html( $key, $data ) {
		$has_key      = (bool) $this->get_option( 'api_key' );
		$webhook_id   = get_option( 'fl_webhook_id', '' );
		$status_label = $webhook_id
			? sprintf( __( 'Active (ID: %s)', 'forgelayer-crypto-payments-for-woocommerce' ), esc_html( $webhook_id ) )
			: __( 'Not registered yet.', 'forgelayer-crypto-payments-for-woocommerce' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html( $data['title'] ); ?>
			</th>
			<td class="forminp">
				<?php if ( ! $has_key ) : ?>
					<p class="description">
						<?php esc_html_e( 'Enter and save your API key above first.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
					</p>
				<?php else : ?>
					<button type="button" id="fl-setup-webhook" class="button button-primary">
						<?php echo $webhook_id
							? esc_html__( 'Re-register Webhook', 'forgelayer-crypto-payments-for-woocommerce' )
							: esc_html__( 'Setup Webhook', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
					</button>
					<span id="fl-webhook-status" style="margin-left:10px;font-style:italic;color:#666">
						<?php echo esc_html( $status_label ); ?>
					</span>
					<p class="description" style="margin-top:6px">
						<?php esc_html_e( 'Generates a secret, registers your webhook URL with ForgeLayer, and saves everything automatically. Save your API key and Confirmation setting first.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	// =========================================================================
	// Webhook: REST endpoint + handler
	// =========================================================================

	/**
	 * Register the public REST endpoint that ForgeLayer posts events to.
	 */
	public function register_webhook_endpoint() {
		register_rest_route( 'forgelayer/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook_request' ),
			'permission_callback' => '__return_true', // Auth is HMAC signature, not WP capability
		) );
	}

	/**
	 * Receive, verify, and process a ForgeLayer webhook payload.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook_request( $request ) {
		$secret = $this->get_option( 'webhook_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_Error( 'fl_webhook_not_configured', 'Webhook not configured.', array( 'status' => 500 ) );
		}

		// Read raw body — must happen before WP parses JSON (signature is over raw bytes)
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'x_webhook_signature' );

		if ( empty( $signature ) ) {
			return new WP_Error( 'fl_missing_signature', 'Missing X-Webhook-Signature header.', array( 'status' => 401 ) );
		}

		// Verify HMAC-SHA256 signature using timing-safe comparison
		$sig_clean = strtolower( $signature );
		if ( strpos( $sig_clean, 'sha256=' ) === 0 ) {
			$sig_clean = substr( $sig_clean, 7 );
		}
		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		if ( ! hash_equals( $expected, $sig_clean ) ) {
			fl_log_warning( sprintf( 'ForgeLayer webhook: signature mismatch. Header: %s', $signature ), array( 'source' => 'forgelayer' ) );
			return new WP_Error( 'fl_invalid_signature', 'Signature mismatch.', array( 'status' => 401 ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'fl_invalid_payload', 'Could not parse JSON body.', array( 'status' => 400 ) );
		}

		// ── Replay attack prevention: timestamp check ─────────────────────────
		// Reject payloads with a timestamp outside ±5 minutes of now.
		// ForgeLayer sends createdAt (ISO 8601) or timestamp (Unix epoch).
		$payload_time = null;
		if ( ! empty( $payload['createdAt'] ) ) {
			$payload_time = strtotime( $payload['createdAt'] );
		} elseif ( ! empty( $payload['timestamp'] ) && is_numeric( $payload['timestamp'] ) ) {
			$payload_time = (int) $payload['timestamp'];
		} elseif ( ! empty( $payload['data']['createdAt'] ) ) {
			$payload_time = strtotime( $payload['data']['createdAt'] );
		}
		if ( null !== $payload_time && $payload_time !== false ) {
			$skew = abs( time() - $payload_time );
			if ( $skew > 300 ) { // 5 minutes
				fl_log_warning(
					sprintf( 'ForgeLayer webhook: timestamp skew %ds exceeds 300s — possible replay attack.', $skew ),
					array( 'source' => 'forgelayer' )
				);
				return new WP_Error( 'fl_replay_detected', 'Payload timestamp outside acceptable window.', array( 'status' => 400 ) );
			}
		}

		// Normalise payload — handle top-level or nested data key
		$event = sanitize_text_field( isset( $payload['event'] ) ? $payload['event'] : ( isset( $payload['type'] ) ? $payload['type'] : '' ) );
		$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;

		if ( $event !== 'deposit_confirmed' ) {
			// Acknowledge unsupported events without processing them
			return rest_ensure_response( array( 'received' => true ) );
		}

		$address  = sanitize_text_field( isset( $data['address'] )  ? $data['address']  : '' );
		$user_ref = sanitize_text_field( isset( $data['userRef'] )  ? $data['userRef']  : ( isset( $data['user_ref'] ) ? $data['user_ref'] : '' ) );
		$amount   = sanitize_text_field( isset( $data['amount'] )   ? $data['amount']   : ( isset( $data['balance'] ) ? $data['balance'] : '' ) );
		$tx_hash  = sanitize_text_field( isset( $data['txHash'] )   ? $data['txHash']   : ( isset( $data['txid'] ) ? $data['txid'] : ( isset( $data['tx_hash'] ) ? $data['tx_hash'] : '' ) ) );

		fl_log_warning(
			sprintf( 'ForgeLayer webhook received: event=%s address=%s userRef=%s amount=%s txHash=%s', $event, $address, $user_ref, $amount, $tx_hash ),
			array( 'source' => 'forgelayer' )
		);

		// ── Replay attack prevention: txHash deduplication ────────────────────
		// Reject payloads with a txHash we have already processed within 24 hours.
		if ( $tx_hash ) {
			$tx_key = 'fl_txhash_' . md5( $tx_hash );
			if ( get_transient( $tx_key ) ) {
				fl_log_warning(
					sprintf( 'ForgeLayer webhook: duplicate txHash %s rejected.', $tx_hash ),
					array( 'source' => 'forgelayer' )
				);
				return rest_ensure_response( array( 'received' => true, 'note' => 'duplicate_tx' ) );
			}
			// Mark this txHash as seen for 24 hours
			set_transient( $tx_key, 1, DAY_IN_SECONDS );
		}

		// Find the WooCommerce order
		$order = null;

		// 1. Fast path: userRef was set to the WC order ID when the address was generated
		if ( $user_ref && is_numeric( $user_ref ) ) {
			$candidate = wc_get_order( (int) $user_ref );
			if ( $candidate && $candidate->get_payment_method() === $this->id ) {
				$order = $candidate;
			}
		}

		// 2. Fallback: search by stored address meta
		if ( ! $order && $address ) {
			$matches = wc_get_orders( array(
				'payment_method' => $this->id,
				'limit'          => 1,
				'meta_key'       => '_fl_address',
				'meta_value'     => $address,
			) );
			if ( ! empty( $matches ) ) {
				$order = $matches[0];
			}
		}

		if ( ! $order ) {
			// Acknowledge so ForgeLayer doesn't retry, but log the miss
			fl_log_warning(
				sprintf( 'ForgeLayer webhook: could not find order for address "%s" / userRef "%s"', $address, $user_ref ),
				array( 'source' => 'forgelayer' )
			);
			return rest_ensure_response( array( 'received' => true, 'note' => 'order_not_found' ) );
		}

		// Order already fulfilled — check for a duplicate payment on the same address
		if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			$original_tx = $order->get_meta( '_fl_tx_hash' );

			// Only alert if this is a different transaction (not a ForgeLayer webhook retry)
			if ( $tx_hash && $original_tx && $tx_hash !== $original_tx ) {
				$this->handle_duplicate_payment( $order, $amount, $tx_hash );
			}

			return rest_ensure_response( array( 'received' => true, 'note' => 'already_confirmed' ) );
		}

		// Late payment — order was cancelled after the window expired
		$is_late   = in_array( $order->get_status(), array( 'cancelled' ), true )
			&& $order->get_meta( '_fl_payment_status' ) === 'expired';
		$expires_at_stored = (int) $order->get_meta( '_fl_expires_at' );

		// Always accumulate received amount first so admin always sees accurate totals
		$expected_amount     = $order->get_meta( '_fl_crypto_amount' );
		$previously_received = (float) ( $order->get_meta( '_fl_received_amount' ) ?: 0 );
		$total_received      = $amount ? $previously_received + (float) $amount : $previously_received;
		if ( $amount ) {
			$order->update_meta_data( '_fl_received_amount', (string) $total_received );
			$order->save();
		}

		if ( $is_late ) {
			if ( ! $this->is_within_grace_period( $expires_at_stored ) ) {
				// Either late payments are OFF, or the payment arrived beyond the grace period
				$this->notify_admin_late_payment_review( $order, $amount, $tx_hash, $expires_at_stored );
				return rest_ensure_response( array( 'received' => true, 'note' => 'late_payment_manual_review' ) );
			}
			// Within grace period — fall through to confirm below
		}

		// Verify amount if we have a stored expected value
		if ( $expected_amount && $amount ) {

			$sufficient = function_exists( 'bccomp' )
				? bccomp( number_format( $total_received, 18, '.', '' ), number_format( (float) $expected_amount, 18, '.', '' ), 18 ) >= 0
				: $total_received >= (float) $expected_amount;

			if ( ! $sufficient ) {
				$order->add_order_note( sprintf(
					__( 'ForgeLayer webhook: underpayment detected. Expected %1$s, total received so far %2$s. Waiting for top-up.', 'forgelayer-crypto-payments-for-woocommerce' ),
					esc_html( $expected_amount ),
					esc_html( $total_received )
				) );
				return rest_ensure_response( array( 'received' => true, 'note' => 'underpayment' ) );
			}
		}

		// Confirm the order — sanitize all meta values defensively before storage
		$order->update_meta_data( '_fl_payment_status', 'confirmed' );
		if ( $tx_hash ) {
			$order->update_meta_data( '_fl_tx_hash', sanitize_text_field( $tx_hash ) );
		}
		$order->save();

		// payment_complete() transitions the order to 'processing' even from 'cancelled'
		$order->payment_complete( $tx_hash );

		if ( $is_late ) {
			$order->add_order_note( sprintf(
				__( 'ForgeLayer: LATE payment confirmed after order window expired. Amount: %1$s. Tx: %2$s. Order reopened automatically.', 'forgelayer-crypto-payments-for-woocommerce' ),
				esc_html( $amount ),
				$tx_hash ? esc_html( $tx_hash ) : __( 'N/A', 'forgelayer-crypto-payments-for-woocommerce' )
			) );
		} else {
			$order->add_order_note( sprintf(
				__( 'ForgeLayer: payment confirmed. Amount: %1$s. Tx: %2$s', 'forgelayer-crypto-payments-for-woocommerce' ),
				esc_html( $amount ),
				$tx_hash ? esc_html( $tx_hash ) : __( 'N/A', 'forgelayer-crypto-payments-for-woocommerce' )
			) );
		}

		return rest_ensure_response( array( 'received' => true, 'late' => $is_late ) );
	}

	/**
	 * Find an existing ForgeLayer address on the given chain that is no longer
	 * tied to an active order and can be safely reused.
	 *
	 * "Inactive" means the originating order is completed, cancelled, refunded, or failed.
	 * We also verify no other active (pending/on-hold) order is currently using the address,
	 * so two concurrent customers never share the same address.
	 *
	 * @param  string $chain_id  e.g. 'ethereum'
	 * @return array|null  { address, address_id } or null if nothing reusable found
	 */
	private function find_inactive_address( $chain_id ) {
		// Whitelist chain_id and sanitize before passing to meta_query (SQL injection prevention)
		$allowed_chains = array( 'bitcoin', 'ethereum', 'bsc', 'tron' );
		if ( ! in_array( $chain_id, $allowed_chains, true ) ) {
			return null;
		}
		$safe_chain_id = sanitize_text_field( $chain_id );

		$inactive_statuses = array( 'completed', 'cancelled', 'refunded', 'failed' );

		$candidates = wc_get_orders( array(
			'payment_method' => $this->id,
			'status'         => $inactive_statuses,
			'limit'          => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array( 'key' => '_fl_address', 'compare' => 'EXISTS' ),
				array( 'key' => '_fl_chain',   'value'   => $safe_chain_id ),
			),
		) );

		foreach ( $candidates as $candidate ) {
			$address    = $candidate->get_meta( '_fl_address' );
			$address_id = $candidate->get_meta( '_fl_address_id' );

			if ( ! $address ) {
				continue;
			}

			// Confirm no active order is already using this address right now
			$in_use = wc_get_orders( array(
				'payment_method' => $this->id,
				'status'         => array( 'pending', 'on-hold' ),
				'limit'          => 1,
				'meta_query'     => array(
					array( 'key' => '_fl_address', 'value' => $address ),
				),
			) );

			if ( empty( $in_use ) ) {
				return array(
					'address'    => $address,
					'address_id' => $address_id,
				);
			}
		}

		return null;
	}

	/**
	 * Handle a payment received on an address that belongs to an already-completed order.
	 * Funds are in the ForgeLayer wallet — no automatic action is taken on the order.
	 * We log the event, add a visible order note, and email the admin.
	 *
	 * @param  WC_Order $order
	 * @param  string   $amount
	 * @param  string   $tx_hash
	 */
	private function handle_duplicate_payment( $order, $amount, $tx_hash ) {
		$token_symbol = $order->get_meta( '_fl_token_symbol' );
		$address      = $order->get_meta( '_fl_address' );
		$chain_id     = $order->get_meta( '_fl_chain' );

		// Order note visible to admin in WooCommerce
		$order->add_order_note( sprintf(
			/* translators: 1: amount+symbol, 2: tx hash, 3: address */
			__( 'DUPLICATE PAYMENT: %1$s received on address %2$s (%3$s) after this order was already completed. Tx: %4$s. Funds are in your ForgeLayer wallet — issue a manual refund if required.', 'forgelayer-crypto-payments-for-woocommerce' ),
			esc_html( $amount . ' ' . $token_symbol ),
			esc_html( $address ),
			esc_html( $chain_id ),
			esc_html( $tx_hash )
		) );

		// Log for server-side debugging
		fl_log_warning(
			sprintf(
				'ForgeLayer duplicate payment: order #%d already completed. New tx: %s, amount: %s %s, address: %s',
				$order->get_id(),
				$tx_hash,
				$amount,
				$token_symbol,
				$address
			),
			array( 'source' => 'forgelayer' )
		);

		// Email the site admin
		$subject = sprintf(
			/* translators: order number */
			__( '[Action Required] Duplicate crypto payment on Order #%d', 'forgelayer-crypto-payments-for-woocommerce' ),
			$order->get_id()
		);

		$message = sprintf(
			__( "A second cryptocurrency payment was received on an address belonging to an already-completed order.\n\nOrder   : #%1\$d\nCustomer: %2\$s\nAddress : %3\$s (%4\$s)\nAmount  : %5\$s %6\$s\nTx Hash : %7\$s\n\nThe funds are sitting in your ForgeLayer wallet. Please log in to your ForgeLayer dashboard and issue a manual refund to the customer.\n\nOrder URL: %8\$s", 'forgelayer-crypto-payments-for-woocommerce' ),
			$order->get_id(),
			$order->get_formatted_billing_full_name(),
			$address,
			$chain_id,
			$amount,
			$token_symbol,
			$tx_hash,
			admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' )
		);

		wp_mail(
			get_option( 'admin_email' ),
			$subject,
			$message
		);
	}

	/**
	 * Register or re-register the webhook on ForgeLayer.
	 * Called from the admin AJAX handler.
	 *
	 * @return array|WP_Error  { webhook_id, secret } on success
	 */
	public function setup_webhook() {
		$api_key = $this->get_option( 'api_key' );
		if ( ! $api_key ) {
			return new WP_Error( 'fl_no_api_key', 'API key is not configured.' );
		}

		$api          = new FL_API( $api_key );
		$webhook_url  = rest_url( 'forgelayer/v1/webhook' );
		$confirmations = max( 1, min( 100, (int) $this->get_option( 'webhook_confirmation_levels', 1 ) ) );

		// Delete any previously registered webhook so we don't accumulate duplicates
		$old_id = get_option( 'fl_webhook_id', '' );
		if ( $old_id ) {
			$api->delete_webhook( $old_id ); // best-effort, ignore errors
		}

		// Generate a fresh signing secret (32 random bytes = 64-char hex)
		$secret = bin2hex( random_bytes( 32 ) );

		$result = $api->create_webhook(
			$webhook_url,
			$secret,
			array( 'deposit_confirmed' ),
			array( $confirmations )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Response: { "webhook": { "id": "...", ... } }
		$webhook_data = isset( $result['webhook'] ) ? $result['webhook'] : $result;
		$webhook_id   = sanitize_text_field(
			isset( $webhook_data['id'] )        ? $webhook_data['id']
			: ( isset( $webhook_data['webhookId'] ) ? $webhook_data['webhookId'] : '' )
		);

		// Persist to settings and a standalone option
		$settings                    = get_option( 'woocommerce_forgelayer_settings', array() );
		$settings['webhook_secret']  = $secret;
		update_option( 'woocommerce_forgelayer_settings', $settings );
		update_option( 'fl_webhook_id', $webhook_id );

		return array(
			'webhook_id' => $webhook_id,
			'secret'     => $secret,
		);
	}

	// =========================================================================
	// Checkout rendering
	// =========================================================================

	public function payment_fields() {
		if ( $this->description ) {
			// Restrict to safe inline elements only — wp_kses_post is too permissive for checkout (L-2)
			$allowed = array(
				'a'      => array( 'href' => array(), 'title' => array() ),
				'em'     => array(),
				'strong' => array(),
				'br'     => array(),
			);
			echo '<p>' . wp_kses( $this->description, $allowed ) . '</p>';
		}

		$options = $this->get_enabled_options();

		if ( empty( $options ) ) {
			echo '<p class="fl-error">'
				. esc_html__( 'No payment options are currently available. Please contact the store owner.', 'forgelayer-crypto-payments-for-woocommerce' )
				. '</p>';
			return;
		}

		echo '<div class="fl-payment-options">';
		echo '<p class="fl-select-label"><strong>'
			. esc_html__( 'Select network & currency:', 'forgelayer-crypto-payments-for-woocommerce' )
			. '</strong></p>';
		echo '<div class="fl-options-grid">';

		$first = true;
		foreach ( $options as $value => $label ) {
			$checked = $first ? ' checked' : '';
			$first   = false;
			echo '<label class="fl-option">';
			echo '<input type="radio" name="fl_payment_option" value="' . esc_attr( $value ) . '"' . $checked . '>';
			echo '<span class="fl-option-label">' . esc_html( $label ) . '</span>';
			echo '</label>';
		}

		echo '</div></div>';
	}

	public function validate_fields() {
		$option = sanitize_text_field( isset( $_POST['fl_payment_option'] ) ? $_POST['fl_payment_option'] : '' );

		if ( empty( $option ) ) {
			wc_add_notice( __( 'Please select a payment network.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return false;
		}

		// Whitelist validation: chain_id and token symbol
		$parts     = explode( ':', $option, 2 );
		$chain_id  = isset( $parts[0] ) ? $parts[0] : '';
		$token_key = isset( $parts[1] ) ? $parts[1] : '';

		$allowed_chains = array( 'bitcoin', 'ethereum', 'bsc', 'tron' );
		if ( ! in_array( $chain_id, $allowed_chains, true ) ) {
			wc_add_notice( __( 'Invalid payment option selected.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return false;
		}

		if ( $token_key !== 'native' && ! preg_match( '/^[A-Z0-9]{1,20}$/', $token_key ) ) {
			wc_add_notice( __( 'Invalid payment option selected.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return false;
		}

		if ( ! array_key_exists( $option, $this->get_enabled_options() ) ) {
			wc_add_notice( __( 'Invalid payment option selected.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return false;
		}

		return true;
	}

	// =========================================================================
	// Process payment
	// =========================================================================

	public function process_payment( $order_id ) {
		$order  = wc_get_order( absint( $order_id ) );
		$option = sanitize_text_field( isset( $_POST['fl_payment_option'] ) ? $_POST['fl_payment_option'] : '' );

		// Parse "chain_id:token_key"
		$parts     = explode( ':', $option, 2 );
		$chain_id  = isset( $parts[0] ) ? $parts[0] : '';
		$token_key = isset( $parts[1] ) ? $parts[1] : 'native';

		// Whitelist chain_id against allowed values
		$allowed_chains = array( 'bitcoin', 'ethereum', 'bsc', 'tron' );
		if ( ! in_array( $chain_id, $allowed_chains, true ) ) {
			wc_add_notice( __( 'Invalid payment option. Please try again.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Validate token symbol format (if not native)
		if ( $token_key !== 'native' && ! preg_match( '/^[A-Z0-9]{1,20}$/', $token_key ) ) {
			wc_add_notice( __( 'Invalid payment option. Please try again.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$token_info = $this->get_token_info( $chain_id, $token_key );

		if ( ! $token_info ) {
			wc_add_notice( __( 'Invalid payment option. Please try again.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$api_key = $this->get_option( 'api_key' );
		if ( empty( $api_key ) ) {
			wc_add_notice( __( 'Payment configuration error. Please contact the store owner.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$api             = new FL_API( $api_key );
		$address         = '';
		$address_id      = '';
		$starting_balance = 0.0;
		$reused          = false;

		// Try to reuse an inactive address if the merchant enabled the option
		if ( $this->get_option( 'reuse_addresses' ) === 'yes' ) {
			$existing = $this->find_inactive_address( $chain_id );

			if ( $existing ) {
				$address    = $existing['address'];
				$address_id = $existing['address_id'];
				$reused     = true;

				// Snapshot current balance so old funds never trigger a false confirmation
				$token_contract = isset( $token_info['contract'] ) ? $token_info['contract'] : '';
				$token_decimals = isset( $token_info['decimals'] ) ? (int) $token_info['decimals'] : 18;
				$bal_result = $api->check_balance( $address, $chain_id, $token_contract, $token_decimals );
				if ( ! is_wp_error( $bal_result ) ) {
					$starting_balance = (float) ( isset( $bal_result['balance'] ) ? $bal_result['balance'] : ( isset( $bal_result['amount'] ) ? $bal_result['amount'] : 0 ) );
				}
			}
		}

		// Generate a fresh address if no reusable one was found
		if ( ! $address ) {
			$result = $api->generate_address(
				$chain_id,
				'Order #' . $order->get_order_number(),
				(string) $order_id,
				$order->get_billing_email()
			);

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				if ( in_array( $code, self::$subscription_error_codes, true ) ) {
					// Subscription / limit error — notify admin, show generic customer message
					$this->handle_checkout_api_error( $result, $order );
					wc_add_notice(
						__( 'Cryptocurrency payment is temporarily unavailable. Please try another payment method or contact us.', 'forgelayer-crypto-payments-for-woocommerce' ),
						'error'
					);
				} else {
					// Transient / network error
					$order->add_order_note( 'ForgeLayer API error: ' . sanitize_text_field( $result->get_error_message() ) );
					wc_add_notice(
						__( 'Could not generate a payment address. Please try again or choose another method.', 'forgelayer-crypto-payments-for-woocommerce' ),
						'error'
					);
				}
				return array( 'result' => 'failure' );
			}

			// Sanitize all API-supplied values before trusting them (H-1)
			$address    = sanitize_text_field( isset( $result['address'] ) ? $result['address'] : '' );
			$address_id = sanitize_text_field( isset( $result['id'] )      ? $result['id']      : '' );
		}

		if ( empty( $address ) ) {
			wc_add_notice( __( 'Received an invalid response from the payment provider. Please try again.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Address generated successfully — clear any stored subscription error
		delete_transient( 'fl_subscription_error' );

		// Validate address format matches expected pattern for this chain (H-1)
		if ( ! $this->validate_address_format( $chain_id, $address ) ) {
			wc_add_notice( __( 'Received a malformed payment address. Please try again.', 'forgelayer-crypto-payments-for-woocommerce' ), 'error' );
			$order->add_order_note( 'ForgeLayer returned an address that failed format validation for chain: ' . sanitize_text_field( $chain_id ) );
			return array( 'result' => 'failure' );
		}

		// Convert order total to crypto amount
		$pricer         = new FL_Price();
		$order_total    = (float) $order->get_total();
		$order_currency = $order->get_currency();
		$coingecko_id   = $this->resolve_coingecko_id( $token_info['symbol'] );
		$crypto_amount  = $pricer->fiat_to_crypto( $order_total, $order_currency, $token_info['symbol'], $coingecko_id );

		if ( is_wp_error( $crypto_amount ) ) {
			$order->add_order_note( 'ForgeLayer price lookup failed: ' . sanitize_text_field( $crypto_amount->get_error_message() ) );
			$crypto_amount = '';
		}

		// Enforce bounds server-side — HTML min/max are client-side only (M-4)
		$window     = max( 5, min( 1440, (int) $this->get_option( 'payment_window', 30 ) ) );
		$expires_at = time() + ( $window * 60 );

		// Defensive sanitization of all order meta values before storage
		$order->update_meta_data( '_fl_address',           sanitize_text_field( $address ) );
		$order->update_meta_data( '_fl_chain',             sanitize_text_field( $chain_id ) );
		$order->update_meta_data( '_fl_token_key',         sanitize_text_field( $token_key ) );
		$order->update_meta_data( '_fl_token_symbol',      sanitize_text_field( $token_info['symbol'] ) );
		$order->update_meta_data( '_fl_token_contract',    sanitize_text_field( isset( $token_info['contract'] ) ? $token_info['contract'] : '' ) );
		$order->update_meta_data( '_fl_token_decimals',    absint( isset( $token_info['decimals'] ) ? $token_info['decimals'] : 18 ) );
		$order->update_meta_data( '_fl_crypto_amount',     sanitize_text_field( $crypto_amount ) );
		$order->update_meta_data( '_fl_expires_at',        absint( $expires_at ) );
		$order->update_meta_data( '_fl_address_id',        sanitize_text_field( $address_id ) ); // Internal ID — never rendered in HTML
		$order->update_meta_data( '_fl_payment_status',    'pending' );
		$order->update_meta_data( '_fl_starting_balance',  (float) $starting_balance );
		$order->update_meta_data( '_fl_address_reused',    $reused ? 'yes' : 'no' );
		$order->save();

		$order->update_status( 'on-hold', __( 'Awaiting cryptocurrency payment via ForgeLayer.', 'forgelayer-crypto-payments-for-woocommerce' ) );

		if ( $reused ) {
			$order->add_order_note( sprintf(
				__( 'ForgeLayer address reused: %1$s on %2$s (starting balance: %3$s %4$s). Expecting additional %5$s %4$s.', 'forgelayer-crypto-payments-for-woocommerce' ),
				esc_html( $address ), esc_html( $chain_id ),
				esc_html( number_format( $starting_balance, 8, '.', '' ) ),
				esc_html( $token_info['symbol'] ),
				esc_html( $crypto_amount )
			) );
		} else {
			$order->add_order_note( sprintf(
				__( 'ForgeLayer address generated: %1$s on %2$s. Expecting %3$s %4$s.', 'forgelayer-crypto-payments-for-woocommerce' ),
				esc_html( $address ), esc_html( $chain_id ), esc_html( $crypto_amount ), esc_html( $token_info['symbol'] )
			) );
		}

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	// =========================================================================
	// Thank-you / payment instructions page
	// =========================================================================

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		$address       = $order->get_meta( '_fl_address' );
		$chain_id      = $order->get_meta( '_fl_chain' );
		$token_symbol  = $order->get_meta( '_fl_token_symbol' );
		$crypto_amount = $order->get_meta( '_fl_crypto_amount' );
		$expires_at    = (int) $order->get_meta( '_fl_expires_at' );

		if ( empty( $address ) ) {
			return;
		}

		if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			echo '<div class="fl-payment-confirmed">'
				. esc_html__( 'Payment confirmed. Thank you! Your order is being processed.', 'forgelayer-crypto-payments-for-woocommerce' )
				. '</div>';
			return;
		}

		$chain_label = isset( self::$chains[ $chain_id ]['label'] ) ? self::$chains[ $chain_id ]['label'] : $chain_id;

		$network_warnings = array(
			'bitcoin'  => __( 'Send on the Bitcoin mainnet only. Lightning Network payments are not supported.', 'forgelayer-crypto-payments-for-woocommerce' ),
			'ethereum' => __( 'Send on the Ethereum (ETH) mainnet only. Do NOT send from other networks.', 'forgelayer-crypto-payments-for-woocommerce' ),
			'bsc'      => __( 'Send on BNB Smart Chain (BSC / BEP-20) only. Do NOT use the Ethereum network.', 'forgelayer-crypto-payments-for-woocommerce' ),
			'tron'     => __( 'Send on the Tron (TRC-20) network only. Do NOT use Ethereum or BSC.', 'forgelayer-crypto-payments-for-woocommerce' ),
		);
		?>
		<div class="fl-payment-box"
		     data-order-id="<?php echo esc_attr( $order_id ); ?>"
		     data-order-key="<?php echo esc_attr( $order->get_order_key() ); ?>"
		     data-expires="<?php echo esc_attr( $expires_at ); ?>"
		     data-nonce="<?php echo esc_attr( wp_create_nonce( 'fl_check_payment_' . $order_id ) ); ?>">

			<h2 class="fl-payment-title">
				<?php esc_html_e( 'Complete Your Payment', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
			</h2>

			<div class="fl-payment-status">
				<span class="fl-status-dot fl-status-pending"></span>
				<span id="fl-status-text"><?php esc_html_e( 'Awaiting payment…', 'forgelayer-crypto-payments-for-woocommerce' ); ?></span>
			</div>

			<div class="fl-countdown">
				<?php esc_html_e( 'Time remaining:', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
				<span id="fl-timer">--:--</span>
			</div>

			<?php if ( ! empty( $network_warnings[ $chain_id ] ) ) : ?>
			<div class="fl-network-warning">
				<strong><?php esc_html_e( 'Important:', 'forgelayer-crypto-payments-for-woocommerce' ); ?></strong>
				<?php echo esc_html( $network_warnings[ $chain_id ] ); ?>
			</div>
			<?php endif; ?>

			<div class="fl-payment-details">

				<div class="fl-qr-container">
					<img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo rawurlencode( $address ); ?>"
					     alt="<?php esc_attr_e( 'Payment QR Code', 'forgelayer-crypto-payments-for-woocommerce' ); ?>"
					     width="180" height="180" class="fl-qr-code">
					<p class="fl-qr-label"><?php esc_html_e( 'Scan to pay', 'forgelayer-crypto-payments-for-woocommerce' ); ?></p>
				</div>

				<div class="fl-payment-info">

					<div class="fl-info-block" id="fl-amount-block">
						<label><?php esc_html_e( 'Amount to Send', 'forgelayer-crypto-payments-for-woocommerce' ); ?></label>
						<?php if ( $crypto_amount ) : ?>
						<div class="fl-copy-row" id="fl-amount-row">
							<span class="fl-amount-value" id="fl-amount-value">
								<?php echo esc_html( $crypto_amount ); ?>
								<small class="fl-token-sym"><?php echo esc_html( $token_symbol ); ?></small>
							</span>
							<button type="button" class="fl-copy-btn" data-copy="<?php echo esc_attr( $crypto_amount ); ?>">
								<?php esc_html_e( 'Copy', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
							</button>
						</div>
						<?php else : ?>
						<div id="fl-amount-row" class="fl-amount-calculating">
							<span id="fl-amount-value" data-symbol="<?php echo esc_attr( $token_symbol ); ?>">
								<?php esc_html_e( 'Calculating…', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
							</span>
							<small style="display:block;margin-top:4px">
								<?php esc_html_e( 'Price loading — appears in a few seconds.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
							</small>
						</div>
						<?php endif; ?>
					</div>

					<div class="fl-info-block">
						<label><?php echo esc_html( $chain_label ); ?> <?php esc_html_e( 'Address', 'forgelayer-crypto-payments-for-woocommerce' ); ?></label>
						<div class="fl-copy-row">
							<span class="fl-address-value"><?php echo esc_html( $address ); ?></span>
							<button type="button" class="fl-copy-btn" data-copy="<?php echo esc_attr( $address ); ?>">
								<?php esc_html_e( 'Copy', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
							</button>
						</div>
					</div>

					<div class="fl-meta-row">
						<div class="fl-network-badge">
							<span class="fl-network-badge-dot"></span>
							<?php echo esc_html( $chain_label ); ?> · <?php echo esc_html( $token_symbol ); ?>
						</div>
						<span class="fl-help-inline">
							<?php esc_html_e( 'Send exact amount shown above.', 'forgelayer-crypto-payments-for-woocommerce' ); ?>
						</span>
					</div>

				</div><!-- .fl-payment-info -->
			</div><!-- .fl-payment-details -->
		</div>
		<?php
	}

	// =========================================================================
	// Email instructions
	// =========================================================================

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( $order->get_status() !== 'on-hold' ) {
			return;
		}

		$address       = $order->get_meta( '_fl_address' );
		$chain_id      = $order->get_meta( '_fl_chain' );
		$token_symbol  = $order->get_meta( '_fl_token_symbol' );
		$crypto_amount = $order->get_meta( '_fl_crypto_amount' );
		$chain_label   = isset( self::$chains[ $chain_id ]['label'] ) ? self::$chains[ $chain_id ]['label'] : $chain_id;

		if ( ! $address ) {
			return;
		}

		if ( $plain_text ) {
			// Plain-text email — esc_html not needed for email body, but sanitize all variables
			echo "\n" . strtoupper( esc_html( __( 'Cryptocurrency Payment Instructions', 'forgelayer-crypto-payments-for-woocommerce' ) ) ) . "\n";
			if ( $crypto_amount ) {
				echo esc_html( sprintf( __( 'Amount : %1$s %2$s', 'forgelayer-crypto-payments-for-woocommerce' ), $crypto_amount, $token_symbol ) ) . "\n";
			}
			echo esc_html( sprintf( __( 'Network: %s', 'forgelayer-crypto-payments-for-woocommerce' ), $chain_label ) ) . "\n";
			echo esc_html( sprintf( __( 'Address: %s', 'forgelayer-crypto-payments-for-woocommerce' ), $address ) ) . "\n\n";
		} else {
			echo '<h2 style="color:#555;font-size:18px">'
				. esc_html__( 'Cryptocurrency Payment Instructions', 'forgelayer-crypto-payments-for-woocommerce' )
				. '</h2>';
			echo '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e5e5e5">';
			if ( $crypto_amount ) {
				echo '<tr><td style="border:1px solid #e5e5e5;padding:8px"><strong>'
					. esc_html__( 'Amount', 'forgelayer-crypto-payments-for-woocommerce' ) . '</strong></td>'
					. '<td style="border:1px solid #e5e5e5;padding:8px">'
					. esc_html( $crypto_amount ) . ' ' . esc_html( $token_symbol ) . '</td></tr>';
			}
			echo '<tr><td style="border:1px solid #e5e5e5;padding:8px"><strong>'
				. esc_html__( 'Network', 'forgelayer-crypto-payments-for-woocommerce' ) . '</strong></td>'
				. '<td style="border:1px solid #e5e5e5;padding:8px">'
				. esc_html( $chain_label ) . '</td></tr>';
			echo '<tr><td style="border:1px solid #e5e5e5;padding:8px"><strong>'
				. esc_html__( 'Payment Address', 'forgelayer-crypto-payments-for-woocommerce' ) . '</strong></td>'
				. '<td style="border:1px solid #e5e5e5;padding:8px;word-break:break-all;font-family:monospace">'
				. esc_html( $address ) . '</td></tr>';
			echo '</table><br>';
		}
	}

	// =========================================================================
	// Payment verification (AJAX + WP-Cron)
	// =========================================================================

	/**
	 * @param  WC_Order $order
	 * @return array { status, message, redirect? }
	 */
	public function verify_payment( $order ) {
		$address        = $order->get_meta( '_fl_address' );
		$chain_id       = $order->get_meta( '_fl_chain' );
		$token_contract = $order->get_meta( '_fl_token_contract' );
		$token_decimals = (int) ( $order->get_meta( '_fl_token_decimals' ) ?: 18 );
		$crypto_amount  = $order->get_meta( '_fl_crypto_amount' );
		$expires_at     = (int) $order->get_meta( '_fl_expires_at' );
		$payment_status = $order->get_meta( '_fl_payment_status' );

		// If price lookup failed at checkout time, retry it now
		if ( empty( $crypto_amount ) ) {
			$token_key    = $order->get_meta( '_fl_token_key' );
			$token_symbol = $order->get_meta( '_fl_token_symbol' );
			$token_info   = $this->get_token_info( $chain_id, $token_key ?: 'native' );

			if ( $token_info ) {
				$pricer       = new FL_Price();
				$coingecko_id = $this->resolve_coingecko_id( $token_symbol ?: $token_info['symbol'] );
				$recalculated = $pricer->fiat_to_crypto(
					(float) $order->get_total(),
					$order->get_currency(),
					$token_info['symbol'],
					$coingecko_id
				);

				if ( ! is_wp_error( $recalculated ) && $recalculated ) {
					$crypto_amount = $recalculated;
					$order->update_meta_data( '_fl_crypto_amount', $crypto_amount );
					$order->save();
				}
			}
		}

		if ( ! $address || ! $chain_id ) {
			return array( 'status' => 'error', 'message' => 'Missing payment data.' );
		}

		if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return array(
				'status'   => 'confirmed',
				'message'  => __( 'Payment confirmed.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$is_expired = $expires_at && time() > $expires_at;

		if ( $is_expired ) {
			// Mark the order cancelled if not already done
			if ( $payment_status !== 'expired' ) {
				$order->update_meta_data( '_fl_payment_status', 'expired' );
				$order->save();
				$order->update_status( 'cancelled', __( 'ForgeLayer: payment window expired.', 'forgelayer-crypto-payments-for-woocommerce' ) );
				$payment_status = 'expired';
			}

			// AJAX frontend poll: always stop — don't keep checking after expiry
			// WP-Cron path: continue only if within the grace period
			$called_from_cron = ! defined( 'DOING_AJAX' ) || ! DOING_AJAX;
			if ( ! $called_from_cron || ! $this->is_within_grace_period( $expires_at ) ) {
				return array( 'status' => 'expired', 'message' => __( 'Payment window has expired.', 'forgelayer-crypto-payments-for-woocommerce' ) );
			}
			// Within grace period and called from cron — fall through to balance check
		}

		$api_key = $this->get_option( 'api_key' );
		if ( ! $api_key ) {
			return array( 'status' => 'pending', 'message' => __( 'Awaiting payment…', 'forgelayer-crypto-payments-for-woocommerce' ) );
		}

		$api    = new FL_API( $api_key );
		$result = $api->check_balance( $address, $chain_id, $token_contract, $token_decimals );

		if ( is_wp_error( $result ) ) {
			return array( 'status' => 'pending', 'message' => __( 'Awaiting payment…', 'forgelayer-crypto-payments-for-woocommerce' ) );
		}

		$raw_balance = 0.0;
		if ( isset( $result['balance'] ) ) {
			$raw_balance = (float) $result['balance'];
		} elseif ( isset( $result['amount'] ) ) {
			$raw_balance = (float) $result['amount'];
		}

		// For reused addresses, only count funds received AFTER this order was assigned
		$starting_balance = (float) ( $order->get_meta( '_fl_starting_balance' ) ?: 0 );
		$balance          = max( 0.0, $raw_balance - $starting_balance );

		$expected = (float) $crypto_amount; // empty string casts to 0.0 — guard below handles it

		// Use bccomp for precise decimal comparison (exact match required)
		$balance_str  = number_format( $balance,  18, '.', '' );
		$expected_str = number_format( $expected, 18, '.', '' );
		$sufficient   = $expected > 0 && (
			function_exists( 'bccomp' )
				? bccomp( $balance_str, $expected_str, 18 ) >= 0
				: $balance >= $expected
		);

		if ( $sufficient ) {
			// Re-fetch fresh from DB to narrow race window (C-2)
			$fresh = wc_get_order( $order->get_id() );
			if ( in_array( $fresh->get_status(), array( 'processing', 'completed' ), true ) ) {
				return array(
					'status'   => 'confirmed',
					'message'  => __( 'Payment confirmed.', 'forgelayer-crypto-payments-for-woocommerce' ),
					'redirect' => $this->get_return_url( $fresh ),
				);
			}

			$fresh->update_meta_data( '_fl_payment_status', 'confirmed' );
			$fresh->save();
			$fresh->payment_complete();

			if ( $is_expired ) {
				// Late payment within grace period, caught by cron (no webhook)
				$fresh->add_order_note( sprintf(
					__( 'ForgeLayer: LATE payment detected by cron (within grace period). Balance: %s. Order reopened automatically.', 'forgelayer-crypto-payments-for-woocommerce' ),
					number_format( $balance, 8, '.', '' )
				) );
			} else {
				$fresh->add_order_note( sprintf(
					__( 'ForgeLayer: payment confirmed. Balance: %s', 'forgelayer-crypto-payments-for-woocommerce' ),
					number_format( $balance, 8, '.', '' )
				) );
			}

			return array(
				'status'   => 'confirmed',
				'message'  => __( 'Payment confirmed!', 'forgelayer-crypto-payments-for-woocommerce' ),
				'redirect' => $this->get_return_url( $fresh ),
			);
		}

		// balance and expected are intentionally NOT returned — the AJAX handler
		// strips them before sending to unauthenticated clients (H-4)
		// crypto_amount IS returned so the frontend can display it if it was
		// empty at checkout time (price lookup may have been retried above)
		return array(
			'status'         => 'pending',
			'message'        => __( 'Awaiting payment…', 'forgelayer-crypto-payments-for-woocommerce' ),
			'crypto_amount'  => $crypto_amount,
			'token_symbol'   => $order->get_meta( '_fl_token_symbol' ),
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build the flat list of enabled chain:token options for the checkout radio grid.
	 *
	 * @return array<string,string>  key => display label
	 */
	public function get_enabled_options() {
		$options = array();

		foreach ( self::$chains as $chain_id => $chain ) {
			if ( $this->get_option( "chain_{$chain_id}_enabled" ) !== 'yes' ) {
				continue;
			}

			// Native coin
			$n = $chain['native'];
			if ( $this->get_option( "token_{$chain_id}_native", 'yes' ) !== 'no' ) {
				$options[ "{$chain_id}:native" ] = $n['symbol'] . ' — ' . $chain['label'];
			}

			// Fetched tokens
			foreach ( $this->get_chain_tokens( $chain_id ) as $token ) {
				$symbol = isset( $token['symbol'] ) ? $token['symbol'] : '';
				if ( ! $symbol ) {
					continue;
				}
				// Use same sanitize_key() as init_form_fields so option lookup is consistent (H-3)
				if ( $this->get_option( 'token_' . $chain_id . '_' . sanitize_key( $symbol ) ) === 'yes' ) {
					$options[ "{$chain_id}:{$symbol}" ] = $symbol . ' — ' . $chain['label'];
				}
			}
		}

		return $options;
	}

	/**
	 * Resolve token info array for a given chain + token key.
	 *
	 * @param  string $chain_id
	 * @param  string $token_key  "native" or a symbol like "USDT"
	 * @return array|null
	 */
	private function get_token_info( $chain_id, $token_key ) {
		if ( ! isset( self::$chains[ $chain_id ] ) ) {
			return null;
		}

		if ( $token_key === 'native' ) {
			return self::$chains[ $chain_id ]['native'];
		}

		// Search cached tokens by symbol
		foreach ( $this->get_chain_tokens( $chain_id ) as $token ) {
			if ( isset( $token['symbol'] ) && $token['symbol'] === $token_key ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Look up a CoinGecko ID for a given symbol.
	 * Falls back to lowercased symbol (works for many coins on CoinGecko).
	 *
	 * @param  string $symbol
	 * @return string
	 */
	/**
	 * ForgeLayer error codes that indicate a subscription or limit problem.
	 * Used to distinguish billing failures from transient network errors.
	 */
	private static $subscription_error_codes = array(
		'LIMIT_EXCEEDED',
		'SUBSCRIPTION_EXPIRED',
		'SUBSCRIPTION_PENDING',
		'NO_SUBSCRIPTION',
	);

	/**
	 * Handle a ForgeLayer subscription/limit error that blocked a customer checkout.
	 *
	 * - Stores the error code so is_available() can suppress the gateway.
	 * - Sends a one-time admin email (deduplicated per code, max once per 24 h).
	 * - Adds an order note if an order is provided.
	 * - Shows the customer a generic, non-technical error message.
	 *
	 * @param  WP_Error       $error
	 * @param  WC_Order|null  $order
	 */
	private function handle_checkout_api_error( $error, $order = null ) {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		$is_subscription_error = in_array( $code, self::$subscription_error_codes, true );

		// Store the active error so is_available() and admin notices can read it.
		// Expires after 24 h — cleared immediately on a successful generate_address() call.
		if ( $is_subscription_error ) {
			set_transient( 'fl_subscription_error', array(
				'code'    => $code,
				'message' => $message,
				'at'      => time(),
			), DAY_IN_SECONDS );
		}

		// Order note (internal — not customer-facing)
		if ( $order ) {
			$order->add_order_note( sprintf(
				'ForgeLayer checkout blocked — %s: %s',
				$code,
				sanitize_text_field( $message )
			) );
		}

		// Deduplicated admin email — one per error code per 24 h
		$email_key = 'fl_error_email_sent_' . sanitize_key( strtolower( $code ) );
		if ( ! get_transient( $email_key ) ) {
			$dashboard_url = 'https://forgelayer.io/dashboard/billing';
			$labels = array(
				'LIMIT_EXCEEDED'         => __( 'Usage limit reached', 'forgelayer-crypto-payments-for-woocommerce' ),
				'SUBSCRIPTION_EXPIRED'   => __( 'Subscription expired', 'forgelayer-crypto-payments-for-woocommerce' ),
				'SUBSCRIPTION_PENDING'   => __( 'Subscription pending payment', 'forgelayer-crypto-payments-for-woocommerce' ),
				'NO_SUBSCRIPTION'        => __( 'No active subscription', 'forgelayer-crypto-payments-for-woocommerce' ),
			);
			$label = isset( $labels[ $code ] ) ? $labels[ $code ] : __( 'API error', 'forgelayer-crypto-payments-for-woocommerce' );

			$impacts = array(
				'LIMIT_EXCEEDED'         => __( 'Customers cannot generate new payment addresses. Address reuse may still work if enabled.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'SUBSCRIPTION_EXPIRED'   => __( 'All cryptocurrency checkout is blocked until you renew.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'SUBSCRIPTION_PENDING'   => __( 'All cryptocurrency checkout is blocked until payment is completed.', 'forgelayer-crypto-payments-for-woocommerce' ),
				'NO_SUBSCRIPTION'        => __( 'All cryptocurrency checkout is blocked. You need an active ForgeLayer subscription.', 'forgelayer-crypto-payments-for-woocommerce' ),
			);
			$impact = isset( $impacts[ $code ] ) ? $impacts[ $code ] : __( 'Cryptocurrency checkout may be affected.', 'forgelayer-crypto-payments-for-woocommerce' );

			$order_ref = $order ? sprintf( ' (Order #%d attempted)', $order->get_id() ) : '';

			wp_mail(
				get_option( 'admin_email' ),
				sprintf( __( '[Action Required] ForgeLayer: %s — checkout blocked%s', 'forgelayer-crypto-payments-for-woocommerce' ), $label, $order_ref ),
				sprintf(
					"%s\n\n%s\n\nError: %s\n\nLog in to your ForgeLayer dashboard to resolve this:\n%s\n\nThis alert will not repeat for 24 hours.",
					$label,
					$impact,
					$message,
					$dashboard_url
				)
			);

			set_transient( $email_key, 1, DAY_IN_SECONDS );
		}
	}

	private function is_within_grace_period( $expires_at ) {
		if ( $this->get_option( 'accept_late_payments', 'yes' ) !== 'yes' ) {
			return false;
		}
		$grace_minutes = max( 0, (int) $this->get_option( 'late_payment_grace', 60 ) );
		// Grace of 0 means the merchant always wants manual review for late payments
		if ( $grace_minutes === 0 ) {
			return false;
		}
		$seconds_late = time() - (int) $expires_at;
		return $seconds_late <= ( $grace_minutes * 60 );
	}

	/**
	 * Send an admin notification asking for manual review of a late payment
	 * that arrived beyond the grace period.
	 *
	 * @param  WC_Order $order
	 * @param  string   $amount    Crypto amount received
	 * @param  string   $tx_hash
	 * @param  int      $expires_at
	 */
	private function notify_admin_late_payment_review( $order, $amount, $tx_hash, $expires_at ) {
		$minutes_late  = (int) round( ( time() - $expires_at ) / 60 );
		$grace_minutes = (int) $this->get_option( 'late_payment_grace', 60 );
		$token_symbol  = $order->get_meta( '_fl_token_symbol' );
		$chain_id      = $order->get_meta( '_fl_chain' );

		$order->add_order_note( sprintf(
			__( 'ForgeLayer: late payment received %1$d min after expiry (grace period: %2$d min). Amount: %3$s %4$s. Tx: %5$s. Manual review required — funds are in your ForgeLayer wallet.', 'forgelayer-crypto-payments-for-woocommerce' ),
			$minutes_late,
			$grace_minutes,
			esc_html( $amount ),
			esc_html( $token_symbol ),
			$tx_hash ? esc_html( $tx_hash ) : 'N/A'
		) );

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: order number */
				__( '[Action Required] Late crypto payment — Order #%d (beyond grace period)', 'forgelayer-crypto-payments-for-woocommerce' ),
				$order->get_id()
			),
			sprintf(
				__( "A cryptocurrency payment arrived %1\$d minutes after the order window expired, which exceeds your %2\$d-minute grace period. The order has NOT been automatically reopened.\n\nOrder   : #%3\$d\nCustomer: %4\$s\nAmount  : %5\$s %6\$s\nNetwork : %7\$s\nTx Hash : %8\$s\n\nOptions:\n• If the amount and price are acceptable, manually set the order status to Processing in WooCommerce.\n• If you want to refund, log into your ForgeLayer dashboard and return the funds.\n\nOrder URL: %9\$s", 'forgelayer-crypto-payments-for-woocommerce' ),
				$minutes_late,
				$grace_minutes,
				$order->get_id(),
				$order->get_formatted_billing_full_name(),
				$amount,
				$token_symbol,
				$chain_id,
				$tx_hash ?: 'N/A',
				admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' )
			)
		);
	}

	private function resolve_coingecko_id( $symbol ) {
		$symbol = strtoupper( $symbol );

		// 1. Built-in map
		if ( isset( self::$coingecko_map[ $symbol ] ) ) {
			return self::$coingecko_map[ $symbol ];
		}

		// 2. Merchant-supplied custom CoinGecko IDs (settings: custom_coingecko_ids)
		$raw = $this->get_option( 'custom_coingecko_ids', '' );
		foreach ( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( count( $parts ) === 2 && strtoupper( $parts[0] ) === $symbol ) {
				$id = sanitize_text_field( $parts[1] );
				if ( preg_match( '/^[a-z0-9\-]{1,64}$/', $id ) ) {
					return $id;
				}
			}
		}

		// 3. No match — price lookup will fail gracefully
		return '';
	}

	/**
	 * Validate that a blockchain address matches the expected format for the given chain (H-1).
	 *
	 * @param  string $chain_id
	 * @param  string $address
	 * @return bool
	 */
	private function validate_address_format( $chain_id, $address ) {
		if ( empty( $address ) ) {
			return false;
		}
		switch ( $chain_id ) {
			case 'bitcoin':
				// Mainnet P2PKH, P2SH, and Bech32 (bc1)
				return (bool) preg_match( '/^(bc1[a-zA-HJ-NP-Z0-9]{6,87}|[13][a-zA-HJ-NP-Z0-9]{25,34})$/', $address );
			case 'ethereum':
			case 'bsc':
				return (bool) preg_match( '/^0x[a-fA-F0-9]{40}$/', $address );
			case 'tron':
				return (bool) preg_match( '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address );
			default:
				// Unknown chain — basic sanity check only
				return strlen( $address ) >= 10 && strlen( $address ) <= 200
					&& preg_match( '/^[a-zA-Z0-9]+$/', $address );
		}
	}
}
