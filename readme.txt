=== FSWP Rate Limiter ===
Contributors: (add your wordpress.org username here)
Tags: rate limiting, security, brute force, firewall, ddos
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-IP rate limiting for WordPress using a Cloudflare-style sliding-window counter, with a fully user-defined block duration and per-URL request weighting.

== Description ==

fswp-rate-limiter counts every request that reaches WordPress per client IP (front-end, admin, AJAX, login, XML-RPC, REST) using the same sliding-window-approximation model Cloudflare's edge rate limiter is built on: two cheap counters per client (current window, previous window) instead of a per-request timestamp log. Once a client goes over the configured limit, it gets HTTP 429 with a `Retry-After` header for a block duration you choose — not a fixed value dictated by an upstream CDN plan.

Features:

* Site-wide per-IP request limit with a configurable window and block duration.
* Optional exemption for logged-in administrators, `/wp-admin/`, and a manual IP whitelist.
* Automatically whitelists the server's own IP on activation, so it can never rate-limit itself.
* URL exclusion list (plain substrings or `*` wildcards) to skip specific paths entirely.
* Optional outdated-browser / user-agent blocking, plus an always-allowed user-agent list pre-filled with common legitimate bots (Googlebot, Bingbot, social-preview bots, uptime monitors).
* Crawl-based per-URL request weighting: record how many resource requests one page actually generates, and count a hit to that page as that many requests instead of 1. A dedicated URL Weights dashboard can auto-discover and crawl every endpoint on the site in small background batches.
* On first activation, automatically crawls the whole site in the background and calculates an optimal site-wide limit from the average page weight, instead of leaving a generic default in place.
* Works with a persistent object cache (Redis/Memcached) for atomic counting, and falls back to transients otherwise.
* Blocked-IP log with one-click removal/unblock.

Requires the PHP `dom` extension (bundled with PHP by default) for the URL-weight crawler.

== Installation ==

1. Upload the `fswp-rate-limiter` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Configure limits under the "Rate Limiter" menu in the admin sidebar (Settings and URL Weights sub-pages).

== Frequently Asked Questions ==

= Will this see every HTTP request, including images/CSS/JS? =

No. Like any WordPress plugin, it only runs when PHP executes, so it can't see static assets served directly by the web server. Real flood/brute-force traffic almost always hits dynamic endpoints (front-end pages, `wp-login.php`, `xmlrpc.php`, REST API, admin-ajax), all of which do invoke WordPress and are counted. Use the URL-weight crawler to approximate a page's full asset cost if you need that reflected in the count.

= What happens under heavy concurrent traffic without Redis/Memcached? =

Counting falls back to transients (options table) and isn't perfectly atomic under very high concurrency — the same tradeoff Cloudflare's own sliding-window approximation accepts. Install a persistent object cache for atomic counting.

= How is the "optimal" limit calculated on first activation? =

The background crawl records a weight (resource-request count) for every discovered endpoint, averages them, and multiplies by 20 — a generous estimate of how many pages a fast human visitor might load in 60 seconds — with a floor of 60. Heavier average pages (more images/scripts/stylesheets) get a proportionally higher limit. It only runs once, on a fresh install, and never overwrites a limit you've already changed yourself. Filter `fswp_rate_limiter_pages_per_window` to change the 20-page assumption.

== Changelog ==

= 1.2.0 =
* Moved settings out of Settings into their own top-level "Rate Limiter" admin menu.
* Moved the URL crawler to its own URL Weights dashboard, with an auto-crawl button that discovers and crawls every post/page/CPT/taxonomy archive in small background batches.
* Added an always-allowed user-agent list, pre-filled with common legitimate bots.
* Added automatic whitelisting of the server's own IP on activation.
* Added automatic calculation of an optimal site-wide limit from a background crawl of the whole site on first activation.

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
