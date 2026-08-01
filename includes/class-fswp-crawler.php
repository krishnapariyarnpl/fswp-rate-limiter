<?php
/**
 * Crawl-based per-URL request weights: a hit to a crawled URL counts as more
 * than 1 request toward the general limit, approximating the real number of
 * requests one page load generates (HTML doc + its assets), since
 * WordPress/PHP never sees the browser's individual asset requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FSWP_Crawler {

	public static function get_weights() {
		$weights = get_option( FSWP_RATE_LIMITER_WEIGHTS_OPTION, array() );
		return is_array( $weights ) ? $weights : array();
	}

	public static function normalize_path( $url_or_path ) {
		$path = wp_parse_url( $url_or_path, PHP_URL_PATH );
		if ( null === $path || false === $path || '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		return $path;
	}

	public static function get_weight( $path ) {
		$weights = self::get_weights();
		$path    = self::normalize_path( $path );
		return isset( $weights[ $path ]['weight'] ) ? max( 1, (int) $weights[ $path ]['weight'] ) : 1;
	}

	public static function remove( array $paths ) {
		$weights = self::get_weights();
		foreach ( $paths as $path ) {
			unset( $weights[ $path ] );
		}
		update_option( FSWP_RATE_LIMITER_WEIGHTS_OPTION, $weights, false );
	}

	/**
	 * Fetch $url and count how many resource requests loading it generates:
	 * every <img>, <script>, <link rel=stylesheet|icon|preload|...>,
	 * <source>, <iframe>, <video>, <audio>, <embed>/<object>, plus each
	 * entry in a srcset, and the document itself. This can't see requests
	 * that JavaScript fires after load (that needs a real browser engine,
	 * not available from plain PHP) — it's an approximation from the
	 * static markup, same as counting network waterfall entries by hand.
	 *
	 * Restricted to the site's own host to keep this admin-only action from
	 * being usable as an open URL-fetching proxy (SSRF).
	 *
	 * @return int|WP_Error Recorded weight, or an error.
	 */
	public static function crawl( $url ) {
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $target_host !== $site_host ) {
			return new WP_Error( 'fswp_crawl_bad_host', __( 'Only URLs on this site can be crawled.', 'fswp-rate-limiter' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'reject_unsafe_urls'  => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'fswp_crawl_bad_status',
				sprintf(
					/* translators: %d: HTTP status code returned by the crawled URL. */
					__( 'Crawled URL returned HTTP %d.', 'fswp-rate-limiter' ),
					$code
				)
			);
		}

		$weight = 1 + self::count_resource_tags( wp_remote_retrieve_body( $response ) );
		$path   = self::normalize_path( $url );

		$weights          = self::get_weights();
		$weights[ $path ] = array(
			'weight'     => $weight,
			'url'        => $url,
			'crawled_at' => current_time( 'timestamp' ),
		);
		update_option( FSWP_RATE_LIMITER_WEIGHTS_OPTION, $weights, false );

		return $weight;
	}

	private static function count_resource_tags( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return 0;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();

		$src_tags = array( 'img', 'script', 'source', 'iframe', 'video', 'audio', 'embed' );
		$count    = 0;

		foreach ( $src_tags as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				if ( '' !== trim( (string) $node->getAttribute( 'src' ) ) ) {
					$count++;
				}
				$count += self::count_srcset_entries( $node );
			}
		}

		foreach ( $dom->getElementsByTagName( 'object' ) as $node ) {
			if ( '' !== trim( (string) $node->getAttribute( 'data' ) ) ) {
				$count++;
			}
		}

		foreach ( $dom->getElementsByTagName( 'link' ) as $node ) {
			$rel = strtolower( (string) $node->getAttribute( 'rel' ) );
			if ( preg_match( '/stylesheet|icon|preload|manifest|prefetch|preconnect/', $rel ) && '' !== trim( (string) $node->getAttribute( 'href' ) ) ) {
				$count++;
			}
		}

		return $count;
	}

	private static function count_srcset_entries( $node ) {
		if ( ! $node->hasAttribute( 'srcset' ) ) {
			return 0;
		}
		$entries = array_filter( array_map( 'trim', explode( ',', $node->getAttribute( 'srcset' ) ) ) );
		return count( $entries );
	}
}
