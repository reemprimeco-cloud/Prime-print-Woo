<?php
/**
 * Phase 13 — no cross-company ad tracking inside the iOS app.
 *
 * The site runs Meta Pixel and a Pinterest conversion tag for the normal
 * website (both stay exactly as they are for a browser visit — this file
 * changes nothing about that). Inside the app, though, that same tracking
 * would count as Apple's definition of "tracking" (data leaving the device
 * to build an ad profile at another company), which requires an App
 * Tracking Transparency permission prompt before it happens — real native
 * UI this app doesn't have, and Reem's call (2026-09-05) was not to build it
 * for a first submission. So instead: no tracking script ever reaches the
 * app's copy of the page in the first place, which is both the simpler
 * build and the honest one — the App Privacy nutrition label can truthfully
 * say this app does not track.
 *
 * "Reaches the app" is the operative phrase — merely hiding the pixel with
 * JS after the page loads is not enough, since the very first pageview
 * would already have fired before that JS ran. This has to stop the script
 * from ever being sent down at all.
 *
 * Detection: ios-app/capacitor.config.json sets `ios.appendUserAgent` to
 * "PrimePrintingApp/1.0", the one signal available at all — Capacitor's
 * WebView is otherwise indistinguishable from Safari to a normal request.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether this request is the iOS app's own WebView, not a browser.
 *
 * @return bool
 */
function prime_is_native_app_request() {
	static $is_app = null;

	if ( null !== $is_app ) {
		return $is_app;
	}

	$is_app = isset( $_SERVER['HTTP_USER_AGENT'] )
		&& false !== strpos( $_SERVER['HTTP_USER_AGENT'], 'PrimePrintingApp' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only string search, never used as a value.

	// Preview from a normal browser (Reem checking app mode in Safari, or a
	// test in the desktop browser pane): the cookie set by
	// prime_app_preview_cookie() below. Presentation and tracker stripping
	// only — nothing about pricing, stock or checkout depends on this.
	if ( ! $is_app && isset( $_COOKIE['prime_app'] ) && '1' === $_COOKIE['prime_app'] ) {
		$is_app = true;
	}

	return $is_app;
}

/**
 * `?prime_app=1` on any URL turns app-mode preview on for this browser for
 * 30 days; `?prime_app=0` turns it off. Runs on `init`, before any output,
 * so the cookie can still be set; the same request then renders in the
 * chosen mode and redirects to the clean URL.
 */
function prime_app_preview_cookie() {
	if ( ! isset( $_GET['prime_app'] ) || is_admin() ) {
		return;
	}

	$on     = '1' === $_GET['prime_app']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display preference, not an action.
	$secure = is_ssl();

	if ( $on ) {
		setcookie( 'prime_app', '1', time() + 30 * DAY_IN_SECONDS, '/', '', $secure, false );
		$_COOKIE['prime_app'] = '1';
	} else {
		setcookie( 'prime_app', '', time() - DAY_IN_SECONDS, '/', '', $secure, false );
		unset( $_COOKIE['prime_app'] );
	}

	$clean = remove_query_arg( 'prime_app' );

	if ( $clean && ! headers_sent() ) {
		wp_safe_redirect( $clean );
		exit;
	}
}
add_action( 'init', 'prime_app_preview_cookie' );

/**
 * Start buffering the whole response so it can be scrubbed before it's sent
 * — the earliest point templates can hook, so it wraps every plugin's own
 * wp_head/wp_footer output that follows.
 */
function prime_maybe_start_tracker_stripping() {
	if ( prime_is_native_app_request() ) {
		ob_start( 'prime_strip_ad_trackers' );
	}
}
add_action( 'template_redirect', 'prime_maybe_start_tracker_stripping', 0 );

/**
 * Remove Meta Pixel's and Pinterest's own script/noscript blocks from the
 * final rendered HTML.
 *
 * A regex pass over the finished page, not a plugin-specific hook, on
 * purpose: Meta Pixel for WordPress and Pinterest for WooCommerce each wire
 * themselves in through their own internal hook names, which is exactly the
 * kind of detail a plugin update changes without notice. Matching the
 * scripts' own well-known signatures (the `fbq(`/`pintrk(` calls, and the
 * `connect.facebook.net` / `s.pinimg.com` / `ct.pinterest.com` hosts they
 * load from) stays correct across those updates instead of silently
 * breaking the moment one of them refactors its output.
 *
 * @param string $html Full response body.
 * @return string
 */
function prime_strip_ad_trackers( $html ) {
	$patterns = array(
		// The official base-code block ("Meta Pixel for WordPress" wraps its
		// loader in these exact HTML comments) — this is the piece that
		// dynamically injects fbevents.js itself; without removing it, the
		// tracking library still loads even once the calls below are gone.
		'#<!--\s*Meta Pixel Code\s*-->.*?<!--\s*End Meta Pixel Code\s*-->#is',
		// The plugin's own wrapper API, a separate script tag from the
		// classic fbq() calls it also emits (both exist on the same page —
		// removing only one leaves the other still reporting).
		'#<script\b[^>]*>(?:(?!</script>).)*?\bFacebookSignal\.\w+\(.*?</script>#is',
		'#<script\b[^>]*>(?:(?!</script>).)*?\bfbq\(.*?</script>#is',
		'#<script\b[^>]*\bsrc=["\'][^"\']*connect\.facebook\.net[^"\']*["\'][^>]*>\s*</script>#is',
		'#<noscript>(?:(?!</noscript>).)*?facebook\.com/tr(?:(?!</noscript>).)*?</noscript>#is',
		'#<script\b[^>]*>(?:(?!</script>).)*?\bpintrk\(.*?</script>#is',
		'#<script\b[^>]*\bsrc=["\'][^"\']*s\.pinimg\.com[^"\']*["\'][^>]*>\s*</script>#is',
		'#<noscript>(?:(?!</noscript>).)*?ct\.pinterest\.com(?:(?!</noscript>).)*?</noscript>#is',
		// Found 2026-09-18 while investigating a slow-navigation report: the
		// two patterns above assume Meta Pixel and Pinterest only ever load
		// through their inline fbq()/pintrk() snippets or their third-party
		// CDN hosts. In practice the "Official Facebook Pixel" plugin also
		// enqueues its own bundled `facebook_signal.js` as a normal WordPress
		// script tag (self-hosted, `wp-content/plugins/official-facebook-pixel/`
		// — no `connect.facebook.net` in the URL, so the pattern above never
		// matched it), and Pinterest's own snippet loads from
		// `assets.pinterest.com`, not `s.pinimg.com`. Both survived every
		// app-mode page load undetected — meaning the app was still shipping
		// Meta's and Pinterest's own tracking libraries to every customer's
		// phone despite Reem's 2026-09-05 call and the "does not track" App
		// Privacy answer given to Apple. Matched by script id and by URL
		// pattern respectively, since the plugin's own file path is stable
		// but its `?ver=` query string is not.
		'#<script\b[^>]*\bid=["\']facebook-signal-js["\'][^>]*>\s*</script>#is',
		'#<script\b[^>]*\bsrc=["\'][^"\']*/official-facebook-pixel/[^"\']*["\'][^>]*>\s*</script>#is',
		'#<script\b[^>]*\bsrc=["\'][^"\']*assets\.pinterest\.com[^"\']*["\'][^>]*>\s*</script>#is',
	);

	$scrubbed = preg_replace( $patterns, '', $html );

	// preg_replace() returns null on a regex engine failure (e.g. hitting
	// pcre.backtrack_limit on a very large page) — fail open to the
	// untouched page rather than serving a blank one.
	return null === $scrubbed ? $html : $scrubbed;
}
