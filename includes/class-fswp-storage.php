<?php
/**
 * Counter storage: object cache when available, transients otherwise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FSWP_Storage {

	const CACHE_GROUP = 'fswp_rate_limiter';

	public static function incr( $key, $expire, $amount = 1 ) {
		if ( wp_using_ext_object_cache() ) {
			// wp_cache_add is a no-op if the key exists, which is what we want:
			// it seeds a fresh counter without disturbing one already counting.
			wp_cache_add( $key, 0, self::CACHE_GROUP, $expire );

			$value = wp_cache_incr( $key, $amount, self::CACHE_GROUP );
			if ( false === $value ) {
				wp_cache_set( $key, $amount, self::CACHE_GROUP, $expire );
				$value = $amount;
			}
			return (int) $value;
		}

		$value  = (int) get_transient( $key );
		$value += $amount;
		set_transient( $key, $value, $expire );
		return $value;
	}

	public static function get( $key ) {
		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $key, self::CACHE_GROUP );
			return false === $value ? 0 : $value;
		}
		$value = get_transient( $key );
		return false === $value ? 0 : $value;
	}

	public static function set( $key, $value, $expire ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::CACHE_GROUP, $expire );
			return;
		}
		set_transient( $key, $value, $expire );
	}

	public static function delete( $key ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			return;
		}
		delete_transient( $key );
	}
}
