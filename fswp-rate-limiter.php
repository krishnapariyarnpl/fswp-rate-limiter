<?php
/**
 * Plugin Name:       FSWP Rate Limiter
 * Description:       Per-IP rate limiting for WordPress using a Cloudflare-style sliding-window counter. Protects the whole site from floods and brute force by counting every request that reaches WordPress, including frontend, admin, AJAX, login, and XML-RPC endpoints, and responds with HTTP 429.
 * Version:           1.2.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Custom
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fswp-rate-limiter
 * Domain Path:       /languages
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

define( 'FSWP_RATE_LIMITER_VERSION', '1.2.0' );
define( 'FSWP_RATE_LIMITER_FILE', __FILE__ );
define( 'FSWP_RATE_LIMITER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSWP_RATE_LIMITER_OPTION', 'fswp_rate_limiter_settings' );
define( 'FSWP_RATE_LIMITER_WEIGHTS_OPTION', 'fswp_rate_limiter_url_weights' );
define( 'FSWP_RATE_LIMITER_BLOCK_LOG_OPTION', 'fswp_rate_limiter_block_log' );

require_once FSWP_RATE_LIMITER_DIR . 'includes/class-fswp-storage.php';
require_once FSWP_RATE_LIMITER_DIR . 'includes/class-fswp-crawler.php';
require_once FSWP_RATE_LIMITER_DIR . 'includes/class-fswp-rate-limiter.php';

register_activation_hook( FSWP_RATE_LIMITER_FILE, array( 'FSWP_Rate_Limiter', 'on_activate' ) );
register_deactivation_hook( FSWP_RATE_LIMITER_FILE, array( 'FSWP_Rate_Limiter', 'on_deactivate' ) );

add_action( 'init', function () {
	load_plugin_textdomain( 'fswp-rate-limiter', false, dirname( plugin_basename( FSWP_RATE_LIMITER_FILE ) ) . '/languages' );
} );

$fswp_rate_limiter = FSWP_Rate_Limiter::instance();

if ( is_admin() ) {
	require_once FSWP_RATE_LIMITER_DIR . 'includes/class-fswp-admin.php';
	new FSWP_Admin( $fswp_rate_limiter );
}
