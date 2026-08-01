<?php
/**
 * Uninstall cleanup: remove every option and transient this plugin created.
 * Runs only when the plugin is deleted from the Plugins screen, not on
 * deactivation.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'fswp_rate_limiter_settings' );
delete_option( 'fswp_rate_limiter_block_log' );
delete_option( 'fswp_rate_limiter_url_weights' );

global $wpdb;
$like = $wpdb->esc_like( 'fswp_rate_limiter_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $like,
		'_transient_timeout_' . $like
	)
);
