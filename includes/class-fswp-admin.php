<?php
/**
 * Settings screen: Settings -> Rate Limiting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FSWP_Admin {

	/** @var FSWP_Rate_Limiter */
	private $rate_limiter;

	/** @var string */
	private $notice = '';

	public function __construct( FSWP_Rate_Limiter $rate_limiter ) {
		$this->rate_limiter = $rate_limiter;

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_blocked_ip_removals' ) );
		add_action( 'admin_init', array( $this, 'handle_crawl_url' ) );
		add_action( 'admin_init', array( $this, 'handle_weight_removals' ) );
	}

	public function register_settings_page() {
		add_options_page(
			__( 'Rate Limiting', 'fswp-rate-limiter' ),
			__( 'Rate Limiting', 'fswp-rate-limiter' ),
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
			<h1><?php esc_html_e( 'Rate Limiting', 'fswp-rate-limiter' ); ?></h1>
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

			<h2><?php esc_html_e( 'URL request weights', 'fswp-rate-limiter' ); ?></h2>
			<p><?php esc_html_e( "Crawl a URL to record how many resource requests (images, scripts, stylesheets, etc.) one page load generates. When that URL is hit, the general limit counter is incremented by that number instead of 1 — approximating each page's real request cost, since WordPress itself never sees the browser's individual asset requests.", 'fswp-rate-limiter' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'fswp_rate_limiter_crawl_url' ); ?>
				<input type="hidden" name="fswp_rate_limiter_crawl_url" value="1" />
				<input type="text" name="fswp_rate_limiter_crawl_url_value" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
				<?php submit_button( __( 'Crawl & save weight', 'fswp-rate-limiter' ), 'secondary', 'submit', false ); ?>
			</form>

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
