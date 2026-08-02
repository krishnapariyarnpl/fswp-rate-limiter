<?php
/**
 * Admin screens: FSWP Rate Limiter's own top-level menu, with a Settings
 * page and a separate URL Weights (crawler) dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FSWP_Admin {

	const CRAWL_BATCH_SIZE      = 5;
	const DISCOVERED_URLS_KEY   = 'fswp_rate_limiter_discovered_urls';
	const AJAX_NONCE_ACTION     = 'fswp_rate_limiter_crawler';

	/** @var FSWP_Rate_Limiter */
	private $rate_limiter;

	/** @var string */
	private $notice = '';

	/** @var string */
	private $weights_page_hook = '';

	public function __construct( FSWP_Rate_Limiter $rate_limiter ) {
		$this->rate_limiter = $rate_limiter;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_blocked_ip_removals' ) );
		add_action( 'admin_init', array( $this, 'handle_crawl_url' ) );
		add_action( 'admin_init', array( $this, 'handle_weight_removals' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_fswp_discover_endpoints', array( $this, 'ajax_discover_endpoints' ) );
		add_action( 'wp_ajax_fswp_crawl_batch', array( $this, 'ajax_crawl_batch' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'FSWP Rate Limiter', 'fswp-rate-limiter' ),
			__( 'Rate Limiter', 'fswp-rate-limiter' ),
			'manage_options',
			'fswp-rate-limiter-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'fswp-rate-limiter-settings',
			__( 'Settings', 'fswp-rate-limiter' ),
			__( 'Settings', 'fswp-rate-limiter' ),
			'manage_options',
			'fswp-rate-limiter-settings',
			array( $this, 'render_settings_page' )
		);

		$this->weights_page_hook = (string) add_submenu_page(
			'fswp-rate-limiter-settings',
			__( 'URL Weights', 'fswp-rate-limiter' ),
			__( 'URL Weights', 'fswp-rate-limiter' ),
			'manage_options',
			'fswp-rate-limiter-weights',
			array( $this, 'render_weights_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->weights_page_hook ) {
			return;
		}

		wp_enqueue_script(
			'fswp-rate-limiter-crawler',
			plugins_url( 'assets/js/admin-crawler.js', FSWP_RATE_LIMITER_FILE ),
			array(),
			FSWP_RATE_LIMITER_VERSION,
			true
		);

		wp_localize_script( 'fswp-rate-limiter-crawler', 'fswpCrawler', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::AJAX_NONCE_ACTION ),
			'discovering'   => __( 'Discovering endpoints…', 'fswp-rate-limiter' ),
			'noneFound'     => __( 'No endpoints found.', 'fswp-rate-limiter' ),
			'error'         => __( 'Something went wrong. Please try again.', 'fswp-rate-limiter' ),
			'progressLabel' => __( 'Crawled', 'fswp-rate-limiter' ),
			'doneLabel'     => __( 'Done — crawled', 'fswp-rate-limiter' ),
			'ofLabel'       => __( 'of', 'fswp-rate-limiter' ),
			'endpointsLabel' => __( 'endpoints.', 'fswp-rate-limiter' ),
		) );
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

		$this->rate_limiter->remove_blocked_ips( $selected );
	}

	public function handle_crawl_url() {
		if ( ! isset( $_POST['fswp_rate_limiter_crawl_url'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'fswp_rate_limiter_crawl_url' );

		$input = isset( $_POST['fswp_rate_limiter_crawl_url_value'] ) ? trim( (string) $_POST['fswp_rate_limiter_crawl_url_value'] ) : '';
		if ( '' === $input ) {
			return;
		}

		$url = ( 0 === strpos( $input, 'http://' ) || 0 === strpos( $input, 'https://' ) )
			? $input
			: home_url( '/' . ltrim( $input, '/' ) );

		$result = FSWP_Crawler::crawl( $url );

		$this->notice = is_wp_error( $result )
			/* translators: %s: error message. */
			? sprintf( __( 'Crawl failed: %s', 'fswp-rate-limiter' ), $result->get_error_message() )
			/* translators: 1: crawled URL, 2: recorded weight. */
			: sprintf( __( 'Crawled %1$s — recorded weight %2$d.', 'fswp-rate-limiter' ), $url, $result );
	}

	public function handle_weight_removals() {
		if ( ! isset( $_POST['fswp_rate_limiter_remove_weights'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'fswp_rate_limiter_remove_weights' );

		$selected = isset( $_POST['fswp_rate_limiter_remove_weight_path'] ) ? array_map( 'sanitize_text_field', (array) $_POST['fswp_rate_limiter_remove_weight_path'] ) : array();
		if ( empty( $selected ) ) {
			return;
		}

		FSWP_Crawler::remove( $selected );
	}

	/**
	 * Step 1 of the auto-crawl: enumerate every endpoint and stash the list
	 * in a transient so the batch requests that follow don't have to
	 * re-query the whole site each time.
	 */
	public function ajax_discover_endpoints() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fswp-rate-limiter' ) ), 403 );
		}

		$urls = FSWP_Crawler::discover_endpoints();
		set_transient( self::DISCOVERED_URLS_KEY, $urls, HOUR_IN_SECONDS );

		wp_send_json_success( array( 'total' => count( $urls ) ) );
	}

	/**
	 * Step 2: crawl a handful of the discovered URLs per call. The browser
	 * drives the loop (see assets/js/admin-crawler.js), calling this
	 * repeatedly until every URL is processed — that keeps each individual
	 * request short enough to never hit PHP's max_execution_time, even on
	 * a site with thousands of URLs.
	 */
	public function ajax_crawl_batch() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fswp-rate-limiter' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$urls   = get_transient( self::DISCOVERED_URLS_KEY );
		if ( ! is_array( $urls ) ) {
			wp_send_json_error( array( 'message' => __( 'Discovery expired, please start again.', 'fswp-rate-limiter' ) ) );
		}

		$batch = array_slice( $urls, $offset, self::CRAWL_BATCH_SIZE );
		foreach ( $batch as $url ) {
			FSWP_Crawler::crawl( $url );
		}

		wp_send_json_success( array(
			'processed' => min( $offset + count( $batch ), count( $urls ) ),
			'total'     => count( $urls ),
		) );
	}

	public function sanitize_settings( $input ) {
		$defaults = FSWP_Rate_Limiter::default_settings();
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
		$out['allowed_useragents']    = isset( $input['allowed_useragents'] )
			? implode( "\n", array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $input['allowed_useragents'] ) ) ) ) )
			: '';

		return $out;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = wp_parse_args( get_option( FSWP_RATE_LIMITER_OPTION, array() ), FSWP_Rate_Limiter::default_settings() );
		$using_object_cache = wp_using_ext_object_cache();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FSWP Rate Limiter', 'fswp-rate-limiter' ); ?></h1>
			<?php if ( '' !== $this->notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $this->notice ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Counter storage:', 'fswp-rate-limiter' ); ?>
				<strong><?php echo $using_object_cache ? esc_html__( 'Persistent object cache (atomic)', 'fswp-rate-limiter' ) : esc_html__( 'Transients / database (fallback)', 'fswp-rate-limiter' ); ?></strong>
			<?php if ( ! $using_object_cache ) : ?>
				&mdash; <?php esc_html_e( 'install a persistent object cache (e.g. Redis or Memcached) for fully atomic counting under heavy concurrent load.', 'fswp-rate-limiter' ); ?>
			<?php endif; ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'fswp_rate_limiter_settings_group' ); ?>

				<h2><?php esc_html_e( 'Network', 'fswp-rate-limiter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Behind Cloudflare', 'fswp-rate-limiter' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[behind_cloudflare]" value="1" <?php checked( $s['behind_cloudflare'], 1 ); ?> />
							<?php esc_html_e( "Trust the CF-Connecting-IP header for the visitor's real IP (enable this only if the site is actually proxied through Cloudflare, otherwise this header can be spoofed).", 'fswp-rate-limiter' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Whitelisted IPs', 'fswp-rate-limiter' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[whitelist_ips]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['whitelist_ips'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One IP per line. Never rate limited.', 'fswp-rate-limiter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Exempt logged-in administrators', 'fswp-rate-limiter' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[exempt_admins]" value="1" <?php checked( $s['exempt_admins'], 1 ); ?> /> <?php esc_html_e( "Users who can manage_options are never blocked.", 'fswp-rate-limiter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excluded URLs', 'fswp-rate-limiter' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[excluded_urls]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['excluded_urls'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One path per line, matched against the request URI. Use * as a wildcard (e.g. /wp-json/*) or a plain fragment for a substring match (e.g. /feed). Matching requests are never counted toward the site-wide limit.', 'fswp-rate-limiter' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Site-wide limit', 'fswp-rate-limiter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'fswp-rate-limiter' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[general_enabled]" value="1" <?php checked( $s['general_enabled'], 1 ); ?> /> <?php esc_html_e( 'Rate limit all requests that reach WordPress per IP, including front-end, admin, AJAX, and other endpoints.', 'fswp-rate-limiter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Exempt /wp-admin/', 'fswp-rate-limiter' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[exempt_wp_admin]" value="1" <?php checked( $s['exempt_wp_admin'], 1 ); ?> /> <?php esc_html_e( 'Exempt dashboard requests from the site-wide limit. This is disabled by default so admin traffic is counted too.', 'fswp-rate-limiter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limit', 'fswp-rate-limiter' ); ?></th>
						<td>
							<input type="number" min="1" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[general_limit]" value="<?php echo esc_attr( $s['general_limit'] ); ?>" class="small-text" />
							<?php esc_html_e( 'requests per', 'fswp-rate-limiter' ); ?>
							<input type="number" min="1" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[general_window]" value="<?php echo esc_attr( $s['general_window'] ); ?>" class="small-text" />
							<?php esc_html_e( 'seconds, per IP.', 'fswp-rate-limiter' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Block duration', 'fswp-rate-limiter' ); ?></th>
						<td><input type="number" min="1" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[general_mitigation]" value="<?php echo esc_attr( $s['general_mitigation'] ); ?>" class="small-text" /> <?php esc_html_e( 'seconds.', 'fswp-rate-limiter' ); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Browser & user-agent blocking', 'fswp-rate-limiter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Block outdated browsers', 'fswp-rate-limiter' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[browser_block_enabled]" value="1" <?php checked( $s['browser_block_enabled'], 1 ); ?> /> <?php esc_html_e( 'Block browsers older than the minimum version below.', 'fswp-rate-limiter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Minimum browser version', 'fswp-rate-limiter' ); ?></th>
						<td>
							<input type="number" min="0" name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[browser_min_version]" value="<?php echo esc_attr( $s['browser_min_version'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Applied to common browsers such as Chrome, Edge, Firefox, Safari, and Opera.', 'fswp-rate-limiter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Blocked user-agents', 'fswp-rate-limiter' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[blocked_useragents]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['blocked_useragents'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One user-agent fragment per line. Requests containing any of these strings will be blocked.', 'fswp-rate-limiter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Always-allowed user-agents', 'fswp-rate-limiter' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( FSWP_RATE_LIMITER_OPTION ); ?>[allowed_useragents]" rows="6" cols="40" class="large-text code"><?php echo esc_textarea( $s['allowed_useragents'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One user-agent fragment per line. Requests whose User-Agent contains any of these strings skip both the rate limit and the browser/user-agent blocker entirely — pre-filled with common legitimate search-engine, social-preview, and uptime-monitor bots. Note: the User-Agent header is sent by the client and can be faked by anyone, so this is a convenience allowlist for known-good automated traffic, not a verified identity check.', 'fswp-rate-limiter' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Blocked IP log', 'fswp-rate-limiter' ); ?></h2>
			<p><?php esc_html_e( 'Blocked IPs are stored here so you can review and remove them manually.', 'fswp-rate-limiter' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'fswp_rate_limiter_remove_blocked_ips' ); ?>
				<table class="widefat fixed" role="presentation">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'IP', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Blocked at', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Remove', 'fswp-rate-limiter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php $blocked_ips = $this->rate_limiter->get_blocked_ip_log(); if ( ! empty( $blocked_ips ) ) : foreach ( $blocked_ips as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
								<td><?php echo esc_html( $entry['reason'] ?? '' ); ?></td>
								<td><?php echo esc_html( $entry['details'] ?? '' ); ?></td>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['created_at'] ?? 0 ) ) ); ?></td>
								<td><label><input type="checkbox" name="fswp_rate_limiter_remove_ip[]" value="<?php echo esc_attr( $entry['ip'] ?? '' ); ?>" /></label></td>
							</tr>
						<?php endforeach; else : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No blocked IPs recorded yet.', 'fswp-rate-limiter' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="submit">
					<input type="hidden" name="fswp_rate_limiter_remove_blocked_ips" value="1" />
					<?php submit_button( __( 'Remove selected IPs', 'fswp-rate-limiter' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public function render_weights_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'URL Weights', 'fswp-rate-limiter' ); ?></h1>
			<?php if ( '' !== $this->notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $this->notice ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( "Crawl a URL to record how many resource requests (images, scripts, stylesheets, etc.) one page load generates. When that URL is hit, the general limit counter is incremented by that number instead of 1 — approximating each page's real request cost, since WordPress itself never sees the browser's individual asset requests.", 'fswp-rate-limiter' ); ?></p>

			<?php $status = FSWP_Rate_Limiter::get_initial_crawl_status(); ?>
			<?php if ( null !== $status ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: 1: number of endpoints crawled so far, 2: total endpoints to crawl. */
							esc_html__( 'Calculating the optimal site-wide limit in the background: crawled %1$d of %2$d endpoints so far. This runs automatically via WordPress Cron and continues even if you leave this page — check back shortly.', 'fswp-rate-limiter' ),
							(int) $status['processed'],
							(int) $status['total']
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php $result = FSWP_Rate_Limiter::get_initial_crawl_result(); ?>
			<?php if ( null !== $result ) : ?>
				<div class="notice notice-success inline">
					<p>
						<?php if ( ! empty( $result['applied'] ) ) : ?>
							<?php
							printf(
								/* translators: 1: number of endpoints crawled, 2: average weight, 3: calculated requests-per-60s limit. */
								esc_html__( 'Initial auto-crawl complete: %1$d endpoints crawled, average weight %2$s. Set the site-wide limit to %3$d requests per 60 seconds on the Settings page — adjust it anytime.', 'fswp-rate-limiter' ),
								(int) $result['endpoints'],
								esc_html( number_format_i18n( $result['average'], 1 ) ),
								(int) $result['limit']
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: 1: number of endpoints crawled, 2: average weight. */
								esc_html__( 'Initial auto-crawl complete: %1$d endpoints crawled, average weight %2$s. The site-wide limit was left as-is since it had already been changed from the default.', 'fswp-rate-limiter' ),
								(int) $result['endpoints'],
								esc_html( number_format_i18n( $result['average'], 1 ) )
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Auto-crawl the whole site', 'fswp-rate-limiter' ); ?></h2>
			<p><?php esc_html_e( 'Discovers every published post, page, custom post type entry, and public taxonomy archive on the site, then crawls each one a few at a time in the background to record its weight. Safe for large sites — it never processes more than a handful of URLs per request, so it can\'t time out.', 'fswp-rate-limiter' ); ?></p>
			<p>
				<button type="button" id="fswp-auto-crawl-start" class="button button-primary"><?php esc_html_e( 'Start auto-crawl', 'fswp-rate-limiter' ); ?></button>
				<span id="fswp-auto-crawl-progress"></span>
			</p>

			<h2><?php esc_html_e( 'Crawl a single URL', 'fswp-rate-limiter' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'fswp_rate_limiter_crawl_url' ); ?>
				<input type="hidden" name="fswp_rate_limiter_crawl_url" value="1" />
				<input type="text" name="fswp_rate_limiter_crawl_url_value" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
				<?php submit_button( __( 'Crawl & save weight', 'fswp-rate-limiter' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Recorded weights', 'fswp-rate-limiter' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'fswp_rate_limiter_remove_weights' ); ?>
				<table class="widefat fixed" role="presentation">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Path', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Weight', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Crawled at', 'fswp-rate-limiter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Remove', 'fswp-rate-limiter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php $weights = FSWP_Crawler::get_weights(); if ( ! empty( $weights ) ) : foreach ( $weights as $path => $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $path ); ?></td>
								<td><?php echo esc_html( $entry['weight'] ?? 1 ); ?></td>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['crawled_at'] ?? 0 ) ) ); ?></td>
								<td><label><input type="checkbox" name="fswp_rate_limiter_remove_weight_path[]" value="<?php echo esc_attr( $path ); ?>" /></label></td>
							</tr>
						<?php endforeach; else : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No URLs crawled yet — uncrawled paths count as weight 1.', 'fswp-rate-limiter' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="submit">
					<input type="hidden" name="fswp_rate_limiter_remove_weights" value="1" />
					<?php submit_button( __( 'Remove selected', 'fswp-rate-limiter' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}
}
