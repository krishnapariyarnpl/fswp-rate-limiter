=== FSWP Rate Limiter ===
Contributors: (add your wordpress.org username here)
Tags: rate limiting, security, brute force, firewall, ddos
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-IP rate limiting for WordPress using a Cloudflare-style sliding-window counter, with a fully user-defined block duration and per-URL request weighting.

== Description ==

fswp-rate-limiter counts every request that reaches WordPress per client IP (front-end, admin, AJAX, login, XML-RPC, REST) using the same sliding-window-approximation model Cloudflare's edge rate limiter is built on: two cheap counters per client (current window, previous window) instead of a per-request timestamp log. Once a client goes over the configured limit, it gets HTTP 429 with a `Retry-After` header for a block duration you choose — not a fixed value dictated by an upstream CDN plan.

Features:

* Site-wide per-IP request limit with a configurable window and block duration.
* Optional exemption for logged-in administrators, `/wp-admin/`, and a manual IP whitelist.
* URL exclusion list (plain substrings or `*` wildcards) to skip specific paths entirely.
* Optional outdated-browser / user-agent blocking.
* Crawl-based per-URL request weighting: record how many resource requests one page actually generates, and count a hit to that page as that many requests instead of 1.
* Works with a persistent object cache (Redis/Memcached) for atomic counting, and falls back to transients otherwise.
* Blocked-IP log with one-click removal/unblock.

Requires the PHP `dom` extension (bundled with PHP by default) for the URL-weight crawler.

== Installation ==

1. Upload the `fswp-rate-limiter` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Configure limits under Settings > Rate Limiting.

== Frequently Asked Questions ==

= Will this see every HTTP request, including images/CSS/JS? =

No. Like any WordPress plugin, it only runs when PHP executes, so it can't see static assets served directly by the web server. Real flood/brute-force traffic almost always hits dynamic endpoints (front-end pages, `wp-login.php`, `xmlrpc.php`, REST API, admin-ajax), all of which do invoke WordPress and are counted. Use the URL-weight crawler to approximate a page's full asset cost if you need that reflected in the count.

= What happens under heavy concurrent traffic without Redis/Memcached? =

Counting falls back to transients (options table) and isn't perfectly atomic under very high concurrency — the same tradeoff Cloudflare's own sliding-window approximation accepts. Install a persistent object cache for atomic counting.

== Changelog ==

= 1.1.0 =
* Restructured into a standard multi-file plugin layout (includes/, uninstall.php, readme.txt).
* Added crawl-based per-URL request weighting.
* Added a URL exclusion list.
* Removed the separate login/XML-RPC tier in favor of one site-wide limit (it already counted those endpoints).
* Added full internationalization (text domain `fswp-rate-limiter`).
* Fixed a duplicate array key that silently disabled Internet Explorer/MSIE version detection.
* Hardened the URL crawler against SSRF (same-host only, `reject_unsafe_urls`).

= 1.0.0 =
* Initial release.
