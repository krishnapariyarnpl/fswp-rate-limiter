<?php
/**
 * Core enforcement: per-IP sliding-window rate limiting, browser/user-agent
 * blocking, and the blocked-IP log. See the plugin header for the full
 * Cloudflare-style sliding-window explanation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FSWP_Rate_Limiter {

	const INITIAL_CRAWL_HOOK          = 'fswp_rate_limiter_initial_crawl_batch';
	const INITIAL_CRAWL_URLS_OPTION   = 'fswp_rate_limiter_initial_crawl_urls';
	const INITIAL_CRAWL_OFFSET_OPTION = 'fswp_rate_limiter_initial_crawl_offset';
	const INITIAL_CRAWL_RESULT_OPTION = 'fswp_rate_limiter_initial_crawl_result';
	const INITIAL_CRAWL_BATCH_SIZE    = 10;

	private static $instance = null;

	/** @var array */
	private $settings;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Runs once on plugin activation: add the server's own IP to the
	 * whitelist so the server can never rate-limit itself (loopback
	 * requests, WP-Cron pinging its own site, local health checks, etc.),
	 * and — on a genuinely fresh install only — schedule a background
	 * crawl of every endpoint to calculate and apply an optimal site-wide
	 * limit instead of leaving the generic default in place.
	 */
	public static function on_activate() {
		$is_fresh_install = ( false === get_option( FSWP_RATE_LIMITER_OPTION, false ) );

		$ip = self::detect_server_ip();
		if ( '' !== $ip ) {
			$settings = wp_parse_args( get_option( FSWP_RATE_LIMITER_OPTION, array() ), self::default_settings() );
			$list     = array_filter( array_map( 'trim', explode( "\n", (string) $settings['whitelist_ips'] ) ) );
			if ( ! in_array( $ip, $list, true ) ) {
				$list[]                    = $ip;
				$settings['whitelist_ips'] = implode( "\n", $list );
				update_option( FSWP_RATE_LIMITER_OPTION, $settings );
			}
		}

		if ( $is_fresh_install ) {
			self::schedule_initial_crawl();
		}
	}

	public static function on_deactivate() {
		wp_clear_scheduled_hook( self::INITIAL_CRAWL_HOOK );
	}

	private static function schedule_initial_crawl() {
		if ( wp_next_scheduled( self::INITIAL_CRAWL_HOOK ) ) {
			return;
		}
		update_option( self::INITIAL_CRAWL_URLS_OPTION, FSWP_Crawler::discover_endpoints(), false );
		update_option( self::INITIAL_CRAWL_OFFSET_OPTION, 0, false );
		wp_schedule_single_event( time() + 10, self::INITIAL_CRAWL_HOOK );
	}

	/**
	 * One batch of the background initial crawl. Reschedules itself until
	 * every discovered URL has been crawled, then calculates the optimal
	 * limit from the average recorded weight. Kept small per run (see
	 * INITIAL_CRAWL_BATCH_SIZE) so it can't hit PHP's max_execution_time,
	 * the same reasoning as the admin-triggered auto-crawl.
	 */
	public function run_initial_crawl_batch() {
		$urls = get_option( self::INITIAL_CRAWL_URLS_OPTION, array() );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			$this->finish_initial_crawl();
			return;
		}

		$offset = (int) get_option( self::INITIAL_CRAWL_OFFSET_OPTION, 0 );
		$batch  = array_slice( $urls, $offset, self::INITIAL_CRAWL_BATCH_SIZE );

		foreach ( $batch as $url ) {
			FSWP_Crawler::crawl( $url );
		}

		$offset += count( $batch );

		if ( $offset < count( $urls ) ) {
			update_option( self::INITIAL_CRAWL_OFFSET_OPTION, $offset, false );
			wp_schedule_single_event( time() + 30, self::INITIAL_CRAWL_HOOK );
			return;
		}

		$this->finish_initial_crawl();
	}

	private function finish_initial_crawl() {
		delete_option( self::INITIAL_CRAWL_URLS_OPTION );
		delete_option( self::INITIAL_CRAWL_OFFSET_OPTION );

		$average = FSWP_Crawler::average_weight();
		if ( null === $average ) {
			return;
		}

		$defaults = self::default_settings();
		$settings = wp_parse_args( get_option( FSWP_RATE_LIMITER_OPTION, array() ), $defaults );

		$applied = false;
		// Don't clobber a limit the admin has already changed themselves
		// in the meantime — only apply the calculated value while it's
		// still sitting at the built-in default.
		if ( (int) $settings['general_limit'] === (int) $defaults['general_limit'] ) {
			$settings['general_limit'] = self::calculate_optimal_limit( $average );
			update_option( FSWP_RATE_LIMITER_OPTION, $settings );
			$applied = true;
		}

		update_option( self::INITIAL_CRAWL_RESULT_OPTION, array(
			'endpoints'   => count( FSWP_Crawler::get_weights() ),
			'average'     => $average,
			'limit'       => $settings['general_limit'],
			'applied'     => $applied,
			'finished_at' => current_time( 'timestamp' ),
		), false );
	}

	/**
	 * A human browsing quickly might open a new page every few seconds;
	 * 20 page loads within a 60-second window is a generous upper bound
	 * that still comfortably excludes an automated flood. Multiplying by
	 * the average recorded page weight converts that into the same
	 * weighted-hit units the counter itself uses, so heavier sites (more
	 * assets per page) get a proportionally higher limit.
	 */
	private static function calculate_optimal_limit( $average_weight ) {
		$pages_per_window = (int) apply_filters( 'fswp_rate_limiter_pages_per_window', 20 );
		return max( 60, (int) round( $average_weight * $pages_per_window ) );
	}

	/**
	 * @return array{processed:int,total:int}|null Progress of the
	 *         in-progress background crawl, or null if none is running.
	 */
	public static function get_initial_crawl_status() {
		$urls = get_option( self::INITIAL_CRAWL_URLS_OPTION, false );
		if ( false === $urls || ! is_array( $urls ) ) {
			return null;
		}
		return array(
			'processed' => (int) get_option( self::INITIAL_CRAWL_OFFSET_OPTION, 0 ),
			'total'     => count( $urls ),
		);
	}

	public static function get_initial_crawl_result() {
		$result = get_option( self::INITIAL_CRAWL_RESULT_OPTION, false );
		return is_array( $result ) ? $result : null;
	}

	private static function detect_server_ip() {
		$candidates = array();
		if ( ! empty( $_SERVER['SERVER_ADDR'] ) ) {
			$candidates[] = $_SERVER['SERVER_ADDR'];
		}
		if ( ! empty( $_SERVER['LOCAL_ADDR'] ) ) {
			// IIS uses LOCAL_ADDR instead of SERVER_ADDR.
			$candidates[] = $_SERVER['LOCAL_ADDR'];
		}

		$hostname = gethostname();
		if ( $hostname ) {
			$resolved = gethostbyname( $hostname );
			// gethostbyname() returns the input unchanged when it can't resolve.
			if ( $resolved && $resolved !== $hostname ) {
				$candidates[] = $resolved;
			}
		}

		foreach ( $candidates as $candidate ) {
			$ip = filter_var( $candidate, FILTER_VALIDATE_IP );
			if ( $ip ) {
				return $ip;
			}
		}

		return '';
	}

	private function __construct() {
		$this->settings = wp_parse_args( get_option( FSWP_RATE_LIMITER_OPTION, array() ), self::default_settings() );

		// Fires on every entry point that loads WordPress (index.php,
		// wp-login.php, xmlrpc.php, wp-admin/admin-ajax.php, ...), and
		// fires as early as possible so we do minimal work before blocking.
		add_action( 'plugins_loaded', array( $this, 'maybe_enforce' ), 1 );

		add_action( self::INITIAL_CRAWL_HOOK, array( $this, 'run_initial_crawl_batch' ) );
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
			'allowed_useragents'    => implode( "\n", self::default_allowed_useragents() ),
		);
	}

	/**
	 * Common legitimate crawlers/bots that make automated, sometimes-fast
	 * requests and shouldn't be caught by the general rate limit or the
	 * browser/user-agent blocker. Note: the User-Agent header is client-
	 * supplied and trivially spoofable — this is a convenience allowlist,
	 * not an identity check. Don't rely on it to distinguish a real
	 * Googlebot request from an attacker sending the same header.
	 */
	public static function default_allowed_useragents() {
		return array(
			'Googlebot',
			'Bingbot',
			'Slurp',
			'DuckDuckBot',
			'Baiduspider',
			'YandexBot',
			'Applebot',
			'facebookexternalhit',
			'Twitterbot',
			'LinkedInBot',
			'WhatsApp',
			'TelegramBot',
			'Slackbot',
			'Pingdom',
			'UptimeRobot',
			'GTmetrix',
			'StatusCake',
			'Google-PageSpeed',
			'Chrome-Lighthouse',
		);
	}

	public function get_settings() {
		return $this->settings;
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

		if ( $this->is_allowed_user_agent() ) {
			$this->debug_log( "$ip skipped: allowed user agent" );
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
			$this->debug_log( "$ip skipped: excluded URL (" . $this->get_request_path() . ')' );
			return;
		}

		$weight = FSWP_Crawler::get_weight( $this->get_request_path() );

		$this->enforce_limit(
			'general',
			$ip,
			(int) $this->settings['general_limit'],
			(int) $this->settings['general_window'],
			(int) $this->settings['general_mitigation'],
			$weight
		);
	}

	/**
	 * Check (and update) the sliding-window rate for $ip under $bucket,
	 * and block the request if it's over $limit within $window seconds.
	 * $weight is how many "requests" this one hit counts as (see
	 * FSWP_Crawler::get_weight() — crawled pages can cost more than 1).
	 */
	private function enforce_limit( $bucket, $ip, $limit, $window, $mitigation, $weight = 1 ) {
		if ( $limit <= 0 || $window <= 0 ) {
			return;
		}

		$block_key = "fswp_rate_limiter_block_{$bucket}_" . md5( $ip );

		// Cheap path: already mitigating this IP, skip the counters entirely.
		if ( FSWP_Storage::get( $block_key ) ) {
			$this->debug_log( "$ip already mitigated ($bucket)" );
			$this->log_blocked_ip( $ip, 'rate-limit', $bucket );
			$this->send_429( $mitigation );
		}

		$rate = $this->sliding_window_rate( $bucket, $ip, $window, $weight );

		$this->debug_log( "$ip $bucket rate=$rate limit=$limit weight=$weight" );

		if ( $rate > $limit ) {
			FSWP_Storage::set( $block_key, 1, $mitigation );
			$this->log_blocked_ip( $ip, 'rate-limit', $bucket );
			$this->send_429( $mitigation );
		}
	}

	private function debug_log( $message ) {
		if ( defined( 'FSWP_RATE_LIMITER_DEBUG' ) && FSWP_RATE_LIMITER_DEBUG ) {
			error_log( '[fswp-rate-limiter] ' . str_replace( array( "\r", "\n" ), '', $message ) );
		}
	}

	/**
	 * Cloudflare-style sliding window approximation:
	 *   rate ≈ previous_window_count * (1 - elapsed/window) + current_window_count
	 * $request_weight is added to the current window's counter instead of a
	 * flat 1, so a page that costs more (see FSWP_Crawler::get_weight())
	 * drains the budget faster than a cheap one.
	 */
	private function sliding_window_rate( $bucket, $ip, $window, $request_weight = 1 ) {
		$now         = time();
		$window_id   = (int) floor( $now / $window );
		$elapsed     = $now % $window;
		$time_weight = 1 - ( $elapsed / $window );

		$current_key  = "fswp_rate_limiter_{$bucket}_" . md5( $ip ) . "_{$window_id}";
		$previous_key = "fswp_rate_limiter_{$bucket}_" . md5( $ip ) . '_' . ( $window_id - 1 );

		$current  = FSWP_Storage::incr( $current_key, $window * 2, $request_weight );
		$previous = (int) FSWP_Storage::get( $previous_key );

		return ( $previous * $time_weight ) + $current;
	}

	private function send_429( $retry_after ) {
		$message = apply_filters( 'fswp_rate_limiter_rate_limited_message', __( 'Too Many Requests. Please try again later.', 'fswp-rate-limiter' ) );
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
		$log = get_option( FSWP_RATE_LIMITER_BLOCK_LOG_OPTION, array() );
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
		update_option( FSWP_RATE_LIMITER_BLOCK_LOG_OPTION, $log, false );
	}

	public function get_blocked_ip_log() {
		$log = get_option( FSWP_RATE_LIMITER_BLOCK_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_values( array_filter( $log, static function ( $entry ) {
			return ! empty( $entry['ip'] );
		} ) );
	}

	/**
	 * Remove the given IPs from the blocked-IP log and clear any active
	 * mitigation state for them, so they're unblocked immediately.
	 */
	public function remove_blocked_ips( array $ips ) {
		$log       = $this->get_blocked_ip_log();
		$remaining = array_values( array_filter( $log, static function ( $entry ) use ( $ips ) {
			return ! in_array( (string) ( $entry['ip'] ?? '' ), $ips, true );
		} ) );
		update_option( FSWP_RATE_LIMITER_BLOCK_LOG_OPTION, $remaining, false );

		foreach ( $ips as $ip ) {
			FSWP_Storage::delete( 'fswp_rate_limiter_block_general_' . md5( $ip ) );
		}
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
				/* translators: %s: the matched user-agent fragment. */
				$this->send_block_response( 403, sprintf( __( 'Blocked user agent: %s', 'fswp-rate-limiter' ), $pattern ) );
				return true;
			}
		}

		$minimum_version = (int) $this->settings['browser_min_version'];
		if ( $minimum_version > 0 ) {
			$browser = $this->detect_browser_version( $user_agent );
			if ( $browser && isset( $browser['version'] ) && null !== $browser['version'] && (int) $browser['version'] < $minimum_version ) {
				$this->log_blocked_ip( $ip, 'browser-version', $browser['name'] . ' ' . $browser['version'] );
				/* translators: 1: browser name, 2: browser version. */
				$this->send_block_response( 403, sprintf( __( 'Browser version too old: %1$s %2$s', 'fswp-rate-limiter' ), $browser['name'], $browser['version'] ) );
				return true;
			}
		}

		return false;
	}

	private function detect_browser_version( $user_agent ) {
		$patterns = array(
			'Chrome'  => '/(?:Chrome|CriOS)\/(\d+)/i',
			'Firefox' => '/Firefox\/(\d+)/i',
			'Safari'  => '/Version\/(\d+).*Safari/i',
			'Edge'    => '/Edg\/(\d+)/i',
			'Opera'   => '/OPR\/(\d+)/i',
			'IE'      => '/MSIE (\d+)/i',
		);

		foreach ( $patterns as $name => $pattern ) {
			if ( preg_match( $pattern, $user_agent, $matches ) ) {
				return array(
					'name'    => $name,
					'version' => isset( $matches[1] ) ? (int) $matches[1] : null,
				);
			}
		}

		if ( preg_match( '/rv:(\d+)/i', $user_agent, $matches ) ) {
			return array(
				'name'    => 'IE',
				'version' => (int) $matches[1],
			);
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Request classification helpers
	 * ------------------------------------------------------------------- */

	private function get_request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( null === $path || false === $path ) {
			$path = $uri;
		}
		return $path;
	}

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

		$path = $this->get_request_path();

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

	private function is_allowed_user_agent() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( '' === $user_agent ) {
			return false;
		}

		$ua_lower = strtolower( $user_agent );
		$patterns = array_filter( array_map( 'trim', explode( "\n", (string) $this->settings['allowed_useragents'] ) ) );
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $ua_lower, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
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
}
