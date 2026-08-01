<?php
/**
 * Plugin Name:       fswp-rate-limiter
 * Description:       Per-IP rate limiting for WordPress using a Cloudflare-style sliding-window counter. Protects the whole site from floods and brute force by counting every request that reaches WordPress, including frontend, admin, AJAX, login, and XML-RPC endpoints, and responds with HTTP 429.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Custom
 * License:           GPL-2.0-or-later
 *
 * ---------------------------------------------------------------------------
 * HOW THIS MIRRORS CLOUDFLARE
 * ---------------------------------------------------------------------------
 * Cloudflare's edge rate limiter (see "How we built rate limiting capable of
 * scaling to millions of domains", blog.cloudflare.com) does NOT use a strict
 * leaky-bucket / token-bucket with per-request timestamp logs. Instead it
 * uses a *sliding window approximation* built on plain counter storage
 * (memcached GET / SET / INCR):
 *
 *   rate ≈ (previous_window_count × fraction_of_window_remaining) + current_window_count
 *
 * This needs only two counters per client (current window, previous window),
 * is cheap enough to run on every request, and in Cloudflare's own testing
 * was wrong on ~0.003% of requests versus a perfect sliding-log implementation.
 * Once a client is over the limit, the "blocked" state is cached separately
 * so subsequent requests short-circuit without re-touching the counters.
 *
 * This plugin re-implements that exact model on top of WordPress:
 *   - Counters live in the object cache (wp_cache_incr, atomic) when a
 *     persistent object cache (Redis/Memcached via a drop-in) is active,
 *     and transparently fall back to transients (wp_options) otherwise.
 *   - A short-lived "blocked" flag is cached per IP so a client that's
 *     already tripped the limit is rejected with one cheap lookup.
 *   - Blocked requests get `429 Too Many Requests` + `Retry-After`, same
 *     as Cloudflare's default mitigation action.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSWP_RATE_LIMITER_VERSION', '1.0.0' );
define( 'FSWP_RATE_LIMITER_OPTION', 'fswp_rate_limiter_settings' );

final class FSWP_Rate_Limiter {

	private static $instance = null;

	/** @var array */
	private $settings;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = wp_parse_args( get_option( FSWP_RATE_LIMITER_OPTION, array() ), self::default_settings() );

		// Fires on every entry point that loads WordPress (index.php,
		// wp-login.php, xmlrpc.php, wp-admin/admin-ajax.php, ...), and
		// fires as early as possible so we do minimal work before blocking.
		add_action( 'plugins_loaded', array( $this, 'maybe_enforce' ), 1 );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_blocked_ip_removals' ) );
	}

	public static function default_settings() {
		return array(
			'behind_cloudflare'   => 0,
			'exempt_wp_admin'     => 0,
			'exempt_admins'       => 1,
			'whitelist_ips'       => '',
			'excluded_urls'       => '',

			'general_enabled'     => 1,
			'general_limit'       => 300,   // requests
			'general_window'      => 60,    // seconds
			'general_mitigation'  => 60,    // seconds blocked once tripped

			'browser_block_enabled' => 0,
			'browser_min_version'   => 0,
			'blocked_useragents'    => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Core enforcement
	 * ------------------------------------------------------------------- */

	public function maybe_enforce() {

		// Never touch WP-CLI or cron; they aren't "clients" in the sense
		// this plugin cares about, and blocking cron would break the site.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$ip = $this->get_client_ip();

		if ( $this->is_whitelisted( $ip ) ) {
			$this->debug_log( "$ip skipped: whitelisted" );
			return;
		}

		if ( $this->exempt_admin_user() ) {
			$this->debug_log( "$ip skipped: exempt admin" );
			return;
		}

		if ( $this->maybe_block_browser( $ip ) ) {
			return;
		}

		if ( ! empty( $this->settings['exempt_wp_admin'] ) && $this->is_wp_admin_request() ) {
			$this->debug_log( "$ip skipped: wp-admin exempt" );
			return;
		}

		if ( empty( $this->settings['general_enabled'] ) ) {
			$this->debug_log( "$ip skipped: general limit disabled" );
			return;
		}

		if ( $this->is_excluded_url() ) {
			$this->debug_log( "$ip skipped: excluded URL ({$_SERVER['REQUEST_URI']})" );
			return;
		}

		$this->enforce_limit(
			'general',
			$ip,
			(int) $this->settings['general_limit'],
			(int) $this->settings['general_window'],
			(int) $this->settings['general_mitigation']
		);
	}

	/**
	 * Check (and update) the sliding-window rate for $ip under $bucket,
	 * and block the request if it's over $limit within $window seconds.
	 */
	private function enforce_limit( $bucket, $ip, $limit, $window, $mitigation ) {
		if ( $limit <= 0 || $window <= 0 ) {
			return;
		}

		$block_key = "fswp_rate_limiter_block_{$bucket}_" . md5( $ip );

		// Cheap path: already mitigating this IP, skip the counters entirely.
		if ( $this->cache_get( $block_key ) ) {
			$this->debug_log( "$ip already mitigated ($bucket)" );
			$this->log_blocked_ip( $ip, 'rate-limit', $bucket );
			$this->send_429( $mitigation );
		}

		$rate = $this->sliding_window_rate( $bucket, $ip, $window );

		$this->debug_log( "$ip $bucket rate=$rate limit=$limit" );

		if ( $rate > $limit ) {
			$this->cache_set( $block_key, 1, $mitigation );
			$this->log_blocked_ip( $ip, 'rate-limit', $bucket );
			$this->send_429( $mitigation );
		}
	}

	private function debug_log( $message ) {
		if ( defined( 'FSWP_RATE_LIMITER_DEBUG' ) && FSWP_RATE_LIMITER_DEBUG ) {
			error_log( '[fswp-rate-limiter] ' . $message );
		}
	}

	/**
	 * Cloudflare-style sliding window approximation:
	 *   rate ≈ previous_window_count * (1 - elapsed/window) + current_window_count
	 */
	private function sliding_window_rate( $bucket, $ip, $window ) {
		$now       = time();
		$window_id = (int) floor( $now / $window );
		$elapsed   = $now % $window;
		$weight    = 1 - ( $elapsed / $window );

		$current_key  = "fswp_rate_limiter_{$bucket}_" . md5( $ip ) . "_{$window_id}";
		$previous_key = "fswp_rate_limiter_{$bucket}_" . md5( $ip ) . '_' . ( $window_id - 1 );

		$current  = $this->cache_incr( $current_key, $window * 2 );
		$previous = (int) $this->cache_get( $previous_key );

		return ( $previous * $weight ) + $current;
	}

	private function send_429( $retry_after ) {
		$message = apply_filters( 'fswp_rate_limiter_rate_limited_message', 'Too Many Requests. Please try again later.' );
		$this->send_block_response( 429, $message, $retry_after );
	}

	private function send_block_response( $status_code, $message, $retry_after = 0 ) {
		nocache_headers();
		status_header( $status_code );
		if ( $retry_after > 0 ) {
			header( 'Retry-After: ' . max( 1, (int) $retry_after ) );
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		die( esc_html( $message ) );
	}

	private function log_blocked_ip( $ip, $reason, $details = '' ) {
		$log = get_option( 'fswp_rate_limiter_block_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$entry = array(
			'ip'         => $ip,
			'reason'     => $reason,
			'details'    => $details,
			'created_at' => current_time( 'timestamp' ),
		);

		$found = false;
		foreach ( $log as $index => $item ) {
			if ( ( $item['ip'] ?? '' ) === $ip && ( $item['reason'] ?? '' ) === $reason ) {
				$log[ $index ] = $entry;
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			$log[] = $entry;
		}

		$log = array_slice( $log, -1000 );
		update_option( 'fswp_rate_limiter_block_log', $log, false );
	}

	private function get_blocked_ip_log() {
		$log = get_option( 'fswp_rate_limiter_block_log', array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_values( array_filter( $log, static function ( $entry ) {
			return ! empty( $entry['ip'] );
		} ) );
	}

	private function maybe_block_browser( $ip ) {
		if ( empty( $this->settings['browser_block_enabled'] ) ) {
			return false;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( '' === $user_agent ) {
			return false;
		}

		$ua_lower = strtolower( $user_agent );
		$blocked_patterns = array_filter( array_map( 'trim', explode( "\n", (string) $this->settings['blocked_useragents'] ) ) );
		foreach ( $blocked_patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $ua_lower, strtolower( $pattern ) ) ) {
				$this->log_blocked_ip( $ip, 'user-agent', $pattern );
				$this->send_block_response( 403, sprintf( 'Blocked user agent: %s', $pattern ) );
				return true;
			}
		}

		$minimum_version = (int) $this->settings['browser_min_version'];
		if ( $minimum_version > 0 ) {
			$browser = $this->detect_browser_version( $user_agent );
			if ( $browser && isset( $browser['version'] ) && null !== $browser['version'] && (int) $browser['version'] < $minimum_version ) {
				$this->log_blocked_ip( $ip, 'browser-version', $browser['name'] . ' ' . $browser['version'] );
				$this->send_block_response( 403, sprintf( 'Browser version too old: %s %s', $browser['name'], $browser['version'] ) );
				return true;
			}
		}

		return false;
	}

	private function detect_browser_version( $user_agent ) {
		$patterns = array(
			'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/i',
			'Firefox' => '/Firefox\/(\d+)/i',
			'Safari' => '/Version\/(\d+).*Safari/i',
			'Edge' => '/Edg\/(\d+)/i',
			'Opera' => '/OPR\/(\d+)/i',
			'IE' => '/MSIE (\d+)/i',
			'IE' => '/rv:(\d+)/i',
		);

		foreach ( $patterns as $name => $pattern ) {
			if ( preg_match( $pattern, $user_agent, $matches ) ) {
				return array(
					'name'    => $name,
					'version' => isset( $matches[1] ) ? (int) $matches[1] : null,
				);
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Storage: object cache when available, transients otherwise.
	 * Both paths degrade gracefully — under a plain transient (DB-backed)
	 * fallback, increments aren't perfectly atomic under heavy concurrency,
	 * same tradeoff Cloudflare accepts for a sliding-window approximation.
	 * Installing a persistent object cache (Redis/Memcached) upgrades this
	 * automatically via wp_cache_incr(), which *is* atomic on those backends.
	 * ------------------------------------------------------------------- */

	private $cache_group = 'fswp_rate_limiter';

	private function cache_incr( $key, $expire ) {
		if ( wp_using_ext_object_cache() ) {
			$added = wp_cache_add( $key, 0, $this->cache_group, $expire );
			if ( false === $added ) {
				// Key already existed; make sure it still has an expiry set
				// (wp_cache_add is a no-op if the key exists).
			}
			$value = wp_cache_incr( $key, 1, $this->cache_group );
			if ( false === $value ) {
				wp_cache_set( $key, 1, $this->cache_group, $expire );
				$value = 1;
			}
			return (int) $value;
		}

		$value = (int) get_transient( $key );
		$value++;
		set_transient( $key, $value, $expire );
		return $value;
	}

	private function cache_get( $key ) {
		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $key, $this->cache_group );
			return false === $value ? 0 : $value;
		}
		$value = get_transient( $key );
		return false === $value ? 0 : $value;
	}

	private function cache_set( $key, $value, $expire ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, $this->cache_group, $expire );
			return;
		}
		set_transient( $key, $value, $expire );
	}

	private function cache_delete( $key ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, $this->cache_group );
			return;
		}
		delete_transient( $key );
	}

	/* ---------------------------------------------------------------------
	 * Request classification helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Check the current request path against the admin-configured exclusion
	 * list. Supports plain substring matches (e.g. "/wp-json/") and simple
	 * wildcard patterns using "*" (e.g. "/feed*", "*.xml").
	 */
	private function is_excluded_url() {
		$patterns = array_filter( array_map( 'trim', explode( "\n", (string) $this->settings['excluded_urls'] ) ) );
		if ( empty( $patterns ) ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( null === $path || false === $path ) {
			$path = $uri;
		}

		foreach ( $patterns as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
				if ( preg_match( $regex, $path ) ) {
					return true;
				}
			} elseif ( false !== strpos( $path, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_wp_admin_request() {
		if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		return ( false !== strpos( $uri, '/wp-admin/' ) ) && ( false === strpos( $uri, 'admin-ajax.php' ) );
	}

	private function exempt_admin_user() {
		if ( empty( $this->settings['exempt_admins'] ) ) {
			return false;
		}
		// is_user_logged_in()/current_user_can() need the user to be
		// resolved; safe to call this early since auth cookies are parsed
		// by the time plugins_loaded runs.
		return function_exists( 'current_user_can' ) && is_user_logged_in() && current_user_can( 'manage_options' );
	}

	private function is_whitelisted( $ip ) {
		$list = array_filter( array_map( 'trim', explode( "\n", (string) $this->settings['whitelist_ips'] ) ) );
		return in_array( $ip, $list, true );
	}

	/**
	 * Resolve the client's real IP. If the site sits behind Cloudflare,
	 * REMOTE_ADDR is a Cloudflare edge IP, not the visitor — use the
	 * CF-Connecting-IP header instead, which Cloudflare sets and which
	 * cannot be spoofed by the client (Cloudflare overwrites/strips any
	 * client-supplied copy of that header at the edge).
	 */
	private function get_client_ip() {
		if ( ! empty( $this->settings['behind_cloudflare'] ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		}
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );
		return $ip ? $ip : '0.0.0.0';
	}

	/* ---------------------------------------------------------------------
	 * Settings screen: Settings -> Rate Limiting
	 * ------------------------------------------------------------------- */

	public function register_settings_page() {
		add_options_page(
			'Rate Limiting',
			'Rate Limiting',
			'manage_options',
			'fswp-rate-limiter-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'fswp_rate_limiter_settings_group', FSWP_RATE_LIMITER_OPTION, array( $this, 'sanitize_settings' ) );
	}

	public function handle_blocked_ip_removals() {
		if ( ! isset( $_POST['fswp_rate_limiter_remove_blocked_ips'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'fswp_rate_limiter_remove_blocked_ips' );

		$selected = isset( $_POST['fswp_rate_limiter_remove_ip'] ) ? array_map( 'sanitize_text_field', (array) $_POST['fswp_rate_limiter_remove_ip'] ) : array();
		if ( empty( $selected ) ) {
			return;
		}

		$log = $this->get_blocked_ip_log();
		$remaining = array_values( array_filter( $log, static function ( $entry ) use ( $selected ) {
			return ! in_array( (string) ( $entry['ip'] ?? '' ), $selected, true );
		} ) );
		update_option( 'fswp_rate_limiter_block_log', $remaining, false );

		foreach ( $selected as $ip ) {
			$this->clear_block_state_for_ip( $ip );
		}
	}

	private function clear_block_state_for_ip( $ip ) {
		$hash = md5( $ip );
		$this->cache_delete( "fswp_rate_limiter_block_general_{$hash}" );
	}

	public function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$out      = array();

		$out['behind_cloudflare'] = empty( $input['behind_cloudflare'] ) ? 0 : 1;
		$out['exempt_wp_admin']   = empty( $input['exempt_wp_admin'] ) ? 0 : 1;
		$out['exempt_admins']     = empty( $input['exempt_admins'] ) ? 0 : 1;
		$out['whitelist_ips']     = isset( $input['whitelist_ips'] )
			? implode( "\n", array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $input['whitelist_ips'] ) ) ) ) )
			: '';
		$out['excluded_urls']     = isset( $input['excluded_urls'] )
			? implode( "\n", array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $input['excluded_urls'] ) ) ) ) )
			: '';

		$out['general_enabled']    = empty( $input['general_enabled'] ) ? 0 : 1;
		$out['general_limit']      = max( 1, (int) ( $input['general_limit'] ?? $defaults['general_limit'] ) );
		$out['general_window']     = max( 1, (int) ( $input['general_window'] ?? $defaults['general_window'] ) );
		$out['general_mitigation'] = max( 1, (int) ( $input['general_mitigation'] ?? $defaults['general_mitigation'] ) );

		$out['browser_block_enabled'] = empty( $input['browser_block_enabled'] ) ? 0 : 1;
		$out['browser_min_version']   = max( 0, (int) ( $input['browser_min_version'] ?? $defaults['browser_min_version'] ) );
		$out['blocked_useragents']    = isset( $input['blocked_useragents'] )
			? implode( "\n", array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $input['blocked_useragents'] ) ) ) ) )
			: '';

		return $out;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = wp_parse_args( get_option( FSWP_RATE_LIMITER_OPTION, array() ), self::default_settings() );
		$using_object_cache = wp_using_ext_object_cache();
		?>
		<div class="wrap">
			<h1>Rate Limiting</h1>
			<p>Counter storage: <strong><?php echo $using_object_cache ? 'Persistent object cache (atomic)' : 'Transients / database (fallback)'; ?></strong>
			<?php if ( ! $using_object_cache ) : ?>
				&mdash; install a persistent object cache (e.g. Redis or Memcached) for fully atomic counting under heavy concurrent load.
			<?php endif; ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'fswp_rate_limiter_settings_group' ); ?>

				<h2>Network</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Behind Cloudflare</th>
						<td>
							<label><input type="checkbox" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[behind_cloudflare]" value="1" <?php checked( $s['behind_cloudflare'], 1 ); ?> />
							Trust the <code>CF-Connecting-IP</code> header for the visitor's real IP (enable this only if the site is actually proxied through Cloudflare, otherwise this header can be spoofed).</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Whitelisted IPs</th>
						<td>
							<textarea name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[whitelist_ips]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['whitelist_ips'] ); ?></textarea>
							<p class="description">One IP per line. Never rate limited.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Exempt logged-in administrators</th>
						<td><label><input type="checkbox" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[exempt_admins]" value="1" <?php checked( $s['exempt_admins'], 1 ); ?> /> Users who can <code>manage_options</code> are never blocked.</label></td>
					</tr>
					<tr>
						<th scope="row">Excluded URLs</th>
						<td>
							<textarea name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[excluded_urls]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['excluded_urls'] ); ?></textarea>
							<p class="description">One path per line, matched against the request URI. Use <code>*</code> as a wildcard (e.g. <code>/wp-json/*</code>) or a plain fragment for a substring match (e.g. <code>/feed</code>). Matching requests are never counted toward the site-wide limit.</p>
						</td>
					</tr>
				</table>

				<h2>Site-wide limit</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enabled</th>
						<td><label><input type="checkbox" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[general_enabled]" value="1" <?php checked( $s['general_enabled'], 1 ); ?> /> Rate limit all requests that reach WordPress per IP, including front-end, admin, AJAX, and other endpoints.</label></td>
					</tr>
					<tr>
						<th scope="row">Exempt <code>/wp-admin/</code></th>
						<td><label><input type="checkbox" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[exempt_wp_admin]" value="1" <?php checked( $s['exempt_wp_admin'], 1 ); ?> /> Exempt dashboard requests from the site-wide limit. This is disabled by default so admin traffic is counted too.</label></td>
					</tr>
					<tr>
						<th scope="row">Limit</th>
						<td>
							<input type="number" min="1" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[general_limit]" value="<?php echo esc_attr( $s['general_limit'] ); ?>" class="small-text" />
							requests per
							<input type="number" min="1" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[general_window]" value="<?php echo esc_attr( $s['general_window'] ); ?>" class="small-text" />
							seconds, per IP.
						</td>
					</tr>
					<tr>
						<th scope="row">Block duration</th>
						<td><input type="number" min="1" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[general_mitigation]" value="<?php echo esc_attr( $s['general_mitigation'] ); ?>" class="small-text" /> seconds.</td>
					</tr>
				</table>

				<h2>Browser &amp; user-agent blocking</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Block outdated browsers</th>
						<td><label><input type="checkbox" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[browser_block_enabled]" value="1" <?php checked( $s['browser_block_enabled'], 1 ); ?> /> Block browsers older than the minimum version below.</label></td>
					</tr>
					<tr>
						<th scope="row">Minimum browser version</th>
						<td>
							<input type="number" min="0" name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[browser_min_version]" value="<?php echo esc_attr( $s['browser_min_version'] ); ?>" class="small-text" />
							<p class="description">Applied to common browsers such as Chrome, Edge, Firefox, Safari, and Opera.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Blocked user-agents</th>
						<td>
							<textarea name="<?php echo FSWP_RATE_LIMITER_OPTION; ?>[blocked_useragents]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['blocked_useragents'] ); ?></textarea>
							<p class="description">One user-agent fragment per line. Requests containing any of these strings will be blocked.</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2>Blocked IP log</h2>
			<p>Blocked IPs are stored here so you can review and remove them manually.</p>
			<form method="post" action="">
				<?php wp_nonce_field( 'fswp_rate_limiter_remove_blocked_ips' ); ?>
				<table class="widefat fixed" role="presentation">
					<thead>
						<tr>
							<th scope="col">IP</th>
							<th scope="col">Reason</th>
							<th scope="col">Details</th>
							<th scope="col">Blocked at</th>
							<th scope="col">Remove</th>
						</tr>
					</thead>
					<tbody>
						<?php $blocked_ips = $this->get_blocked_ip_log(); if ( ! empty( $blocked_ips ) ) : foreach ( $blocked_ips as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
								<td><?php echo esc_html( $entry['reason'] ?? '' ); ?></td>
								<td><?php echo esc_html( $entry['details'] ?? '' ); ?></td>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['created_at'] ?? 0 ) ) ); ?></td>
								<td><label><input type="checkbox" name="fswp_rate_limiter_remove_ip[]" value="<?php echo esc_attr( $entry['ip'] ?? '' ); ?>" /></label></td>
							</tr>
						<?php endforeach; else : ?>
							<tr><td colspan="5">No blocked IPs recorded yet.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="submit">
					<input type="hidden" name="fswp_rate_limiter_remove_blocked_ips" value="1" />
					<?php submit_button( 'Remove selected IPs', 'secondary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}
}

FSWP_Rate_Limiter::instance();
