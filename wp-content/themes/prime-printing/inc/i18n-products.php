<?php
/**
 * One product, shown in both languages — instead of one product per language.
 *
 * Polylang's model is a separate post per language, and the Arabic import
 * (inc/i18n-product-import.php, 2026-09-05) duplicated the catalogue that way:
 * 148 English products and 122 Arabic copies, each copy carrying its own
 * stock, price, and URL. Three things went wrong because of it:
 *
 *   1. Stock and price drifted apart. Reem took four UV sticker products out
 *      of stock on the Arabic side, 2026-09-21; all four English copies stayed
 *      on sale and customers kept ordering them.
 *   2. The calculators had to learn about duplicate IDs to keep pricing a
 *      product correctly (prime_product_id_matches() in inc/i18n.php).
 *   3. Category links on /ar/ pointed at the English URLs, so a customer
 *      browsing in Arabic was dropped back into English on the next click.
 *
 * Reem's call, 2026-09-21: "الموقع و المنتج ١ … اذا العميل يبي يعربي الموقع
 * يترجم له -- وليس نسخه اخرى" — one site, one product, translated on display.
 *
 * So products and their categories are NOT translated post types any more.
 * Each product is a single post that carries its Arabic text as meta, and this
 * file serves it in whichever language the URL asks for:
 *
 *   /product/desk-calendar/        English text, LTR
 *   /ar/product/{arabic-slug}/     Arabic text, RTL — the same product, the
 *                                  same stock, the same price
 *
 * Migrating the existing duplicates into that shape is a separate one-time
 * tool: inc/i18n-product-merge.php.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys holding a product's Arabic text, and a term's Arabic name.
 */
const PRIME_AR_TITLE   = '_prime_ar_title';
const PRIME_AR_CONTENT = '_prime_ar_content';
const PRIME_AR_EXCERPT = '_prime_ar_excerpt';
const PRIME_AR_SLUG    = '_prime_ar_slug';
const PRIME_AR_NAME    = '_prime_ar_name';

/**
 * The post types and taxonomies this file makes bilingual on its own.
 *
 * Polylang must not translate these — a translated post type is exactly the
 * duplicate-post model being replaced here. inc/i18n.php keeps them out of
 * Polylang's settings; this list is what "them" means.
 *
 * @return array{post_types: string[], taxonomies: string[]}
 */
function prime_single_post_types() {
	return array(
		'post_types' => array( 'product' ),
		'taxonomies' => array( 'product_cat', 'product_tag' ),
	);
}

/**
 * Is the catalogue in single-product mode yet?
 *
 * Everything below stays dormant until it is. Deploying this file to a site
 * whose products Polylang still translates must change nothing at all: the old
 * duplicate-post model keeps serving customers exactly as before, and the new
 * routing, links and text only take over when Tools → Merge Arabic Products
 * throws the switch. That way the upload and the migration are two separate
 * events, and the first one is not able to break the shop.
 *
 * @return bool
 */
function prime_single_product_mode() {
	if ( ! function_exists( 'pll_is_translated_post_type' ) ) {
		return false;
	}

	return ! pll_is_translated_post_type( 'product' );
}

/**
 * Is the page currently being served in Arabic?
 *
 * Read from Polylang rather than from is_rtl(), because the answer is needed
 * while building links for the *other* language too.
 *
 * @return bool
 */
function prime_is_arabic() {
	// wp-admin always shows the English source text: the product list, the
	// order screen and Quick Edit are where the shop works, and a title that
	// silently changed language there would be a trap. The Arabic text is
	// edited in its own box on the product screen instead.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}

	if ( ! prime_single_product_mode() ) {
		return false;
	}

	if ( function_exists( 'pll_current_language' ) ) {
		$current = pll_current_language();

		if ( $current ) {
			return 'ar' === $current;
		}
	}

	return is_rtl();
}

/**
 * The URL prefix for a language: '' for English (the default, unprefixed),
 * 'ar' for Arabic.
 *
 * @param string $lang Language slug.
 * @return string
 */
function prime_lang_prefix( $lang ) {
	$default = function_exists( 'pll_default_language' ) ? pll_default_language() : 'en';

	return $lang === $default ? '' : $lang;
}

/* -------------------------------------------------------------------------
 * Routing: /ar/product/…, /ar/product-category/… resolve to the one product.
 * ---------------------------------------------------------------------- */

/**
 * Teach WordPress the Arabic URLs.
 *
 * Without these, /ar/product/x/ is not a route WordPress knows: it falls back
 * to guessing a post from the slug and redirects to the unprefixed permalink,
 * which is how the Arabic category pages ended up on a random product page.
 */
function prime_add_language_rewrites() {
	if ( ! prime_single_product_mode() ) {
		return;
	}

	$langs = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array();

	foreach ( $langs as $lang ) {
		$prefix = prime_lang_prefix( $lang );

		if ( '' === $prefix ) {
			continue;
		}

		$product_base  = prime_permalink_base( 'product' );
		$category_base = prime_permalink_base( 'category' );
		$tag_base      = prime_permalink_base( 'tag' );

		add_rewrite_rule( "^{$prefix}/{$product_base}/([^/]+)/?$", 'index.php?post_type=product&name=$matches[1]', 'top' );
		add_rewrite_rule( "^{$prefix}/{$category_base}/(.+?)/page/([0-9]+)/?$", 'index.php?product_cat=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( "^{$prefix}/{$category_base}/(.+?)/?$", 'index.php?product_cat=$matches[1]', 'top' );
		add_rewrite_rule( "^{$prefix}/{$tag_base}/([^/]+)/?$", 'index.php?product_tag=$matches[1]', 'top' );
	}
}
add_action( 'init', 'prime_add_language_rewrites', 20 );

/**
 * WooCommerce's configured URL bases ("product", "product-category", …), which
 * the shop owner can change in Settings → Permalinks.
 *
 * @param string $which 'product' | 'category' | 'tag'.
 * @return string
 */
function prime_permalink_base( $which ) {
	$defaults  = array( 'product' => 'product', 'category' => 'product-category', 'tag' => 'product-tag' );
	$permalinks = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : array();
	$keys       = array( 'product' => 'product_rewrite_slug', 'category' => 'category_rewrite_slug', 'tag' => 'tag_rewrite_slug' );

	$value = isset( $keys[ $which ], $permalinks[ $keys[ $which ] ] ) ? trim( (string) $permalinks[ $keys[ $which ] ], '/' ) : '';

	return $value ? $value : $defaults[ $which ];
}

/**
 * Flush the rewrite rules once after this module is deployed, and again
 * whenever WooCommerce's permalink bases change.
 *
 * Rewrite rules are cached in the database; new ones do not take effect until
 * they are rebuilt, and on this host there is no WP-CLI to do it by hand.
 */
function prime_maybe_flush_language_rewrites() {
	$signature = wp_json_encode(
		array(
			prime_single_product_mode(),
			prime_permalink_base( 'product' ),
			prime_permalink_base( 'category' ),
			prime_permalink_base( 'tag' ),
			function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array(),
			2, // Bump to force a rebuild after changing the rules above.
		)
	);

	if ( get_option( 'prime_lang_rewrite_signature' ) === $signature ) {
		return;
	}

	update_option( 'prime_lang_rewrite_signature', $signature );
	flush_rewrite_rules( false );
}
add_action( 'wp_loaded', 'prime_maybe_flush_language_rewrites', 99 );

/**
 * The spellings one slug can arrive in.
 *
 * @param string $slug A slug from a URL or from post_name.
 * @return string[] Unique candidates.
 */
function prime_slug_spellings( $slug ) {
	$decoded = rawurldecode( (string) $slug );

	return array_values( array_unique( array( (string) $slug, $decoded, rawurlencode( $decoded ) ) ) );
}

/**
 * An Arabic product URL uses the Arabic slug, which no post actually has —
 * the Arabic slug lives as meta on the one product. Swap it for the real slug
 * before the query runs.
 *
 * @param array $query_vars Query vars parsed from the URL.
 * @return array
 */
function prime_resolve_arabic_product_slug( $query_vars ) {
	if ( ! prime_single_product_mode() ) {
		return $query_vars;
	}

	if ( empty( $query_vars['name'] ) || empty( $query_vars['post_type'] ) || 'product' !== $query_vars['post_type'] ) {
		return $query_vars;
	}

	// The Arabic slug is looked up first, and deliberately so: after the merge
	// (inc/i18n-product-merge.php) the retired Arabic post still holds that slug
	// as a draft, so asking WordPress for it by name would find the draft and
	// 404. Asking the meta finds the live product the URL now belongs to, which
	// is what keeps every Arabic URL Google already indexed working unchanged.
	//
	// Both spellings are tried because an Arabic slug has two: WordPress stores
	// post_name percent-encoded (%d8%a7…), while a slug typed by hand, or read
	// back out of a decoded URL, is the Arabic letters themselves.
	$found = get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed meta lookup, only for a product URL.
			'meta_query'       => array( array( 'key' => PRIME_AR_SLUG, 'value' => prime_slug_spellings( $query_vars['name'] ), 'compare' => 'IN' ) ),
		)
	);

	if ( $found ) {
		$query_vars['name'] = get_post_field( 'post_name', $found[0] );
	}

	return $query_vars;
}
add_filter( 'request', 'prime_resolve_arabic_product_slug' );

/**
 * Keep the Arabic URL. WordPress and Polylang both consider the unprefixed
 * permalink canonical for a product with no language of its own, and would
 * bounce /ar/product/x/ back to /product/x/ — taking the customer out of
 * Arabic mid-browse.
 *
 * @param string|false $redirect Where the request was about to be sent.
 * @return string|false
 */
function prime_keep_language_url( $redirect ) {
	return ( prime_single_product_mode() && prime_is_product_context() ) ? false : $redirect;
}
add_filter( 'redirect_canonical', 'prime_keep_language_url', 20 );
add_filter( 'pll_check_canonical_url', 'prime_keep_language_url', 20 );

/**
 * A shop URL this file is responsible for.
 *
 * @return bool
 */
function prime_is_product_context() {
	return is_singular( 'product' ) || is_post_type_archive( 'product' ) || is_tax( array( 'product_cat', 'product_tag' ) );
}

/* -------------------------------------------------------------------------
 * Links: stay in the language the customer is browsing in.
 * ---------------------------------------------------------------------- */

/**
 * Put the language prefix (and the Arabic slug) on a product's permalink while
 * the customer is browsing in Arabic, so every card, breadcrumb and cart line
 * keeps them there.
 *
 * @param string  $permalink The product URL.
 * @param WP_Post $post      The product.
 * @return string
 */
function prime_product_permalink( $permalink, $post ) {
	if ( ! $post || 'product' !== $post->post_type || ! prime_is_arabic() ) {
		return $permalink;
	}

	$arabic_slug = (string) get_post_meta( $post->ID, PRIME_AR_SLUG, true );

	if ( $arabic_slug ) {
		$base      = prime_permalink_base( 'product' );
		$permalink = preg_replace( '#/' . preg_quote( $base, '#' ) . '/[^/]+/?$#', '/' . $base . '/' . $arabic_slug . '/', $permalink );
	}

	return prime_with_language_prefix( $permalink );
}
add_filter( 'post_type_link', 'prime_product_permalink', 10, 2 );

/**
 * The same for product category and tag archives — the links that were
 * dropping Arabic customers back into English.
 *
 * @param string  $link Term URL.
 * @param WP_Term $term Term.
 * @return string
 */
function prime_product_term_link( $link, $term ) {
	if ( ! $term || ! in_array( $term->taxonomy, prime_single_post_types()['taxonomies'], true ) || ! prime_is_arabic() ) {
		return $link;
	}

	return prime_with_language_prefix( $link );
}
add_filter( 'term_link', 'prime_product_term_link', 10, 2 );

/**
 * Insert /ar/ after the site root, once.
 *
 * @param string $url An URL on this site.
 * @return string
 */
function prime_with_language_prefix( $url ) {
	$prefix = prime_lang_prefix( function_exists( 'pll_current_language' ) ? (string) pll_current_language() : 'en' );

	if ( '' === $prefix ) {
		return $url;
	}

	$home = trailingslashit( home_url( '/' ) );

	if ( 0 !== strpos( $url, $home ) ) {
		return $url;
	}

	$path = substr( $url, strlen( $home ) );

	if ( 0 === strpos( $path, $prefix . '/' ) ) {
		return $url;
	}

	return $home . $prefix . '/' . $path;
}

/* -------------------------------------------------------------------------
 * Text: the Arabic title and description of the one product.
 * ---------------------------------------------------------------------- */

/**
 * The Arabic text stored on a product, if there is any.
 *
 * @param int    $product_id Product ID.
 * @param string $key        One of the PRIME_AR_* meta keys.
 * @return string '' when nothing was translated — the English text then stands.
 */
function prime_ar_text( $product_id, $key ) {
	if ( ! prime_is_arabic() ) {
		return '';
	}

	return (string) get_post_meta( $product_id, $key, true );
}

/**
 * @param string $title   Title.
 * @param int    $post_id Post ID.
 * @return string
 */
function prime_translate_product_title( $title, $post_id = 0 ) {
	if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
		return $title;
	}

	$arabic = prime_ar_text( $post_id, PRIME_AR_TITLE );

	return $arabic ? $arabic : $title;
}
add_filter( 'the_title', 'prime_translate_product_title', 10, 2 );

/**
 * WooCommerce reads a product's name through the CRUD, not through the_title —
 * cart lines, order items, emails and the REST API all come through here.
 *
 * @param string     $name    Product name.
 * @param WC_Product $product Product.
 * @return string
 */
function prime_translate_product_name( $name, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $name;
	}

	$arabic = prime_ar_text( $product->get_id(), PRIME_AR_TITLE );

	return $arabic ? $arabic : $name;
}
add_filter( 'woocommerce_product_get_name', 'prime_translate_product_name', 10, 2 );

/**
 * @param string $content Post content.
 * @return string
 */
function prime_translate_product_content( $content ) {
	if ( ! is_singular( 'product' ) && ! doing_filter( 'woocommerce_product_tabs' ) ) {
		return $content;
	}

	$arabic = prime_ar_text( get_the_ID(), PRIME_AR_CONTENT );

	return $arabic ? $arabic : $content;
}
add_filter( 'the_content', 'prime_translate_product_content', 5 );

/**
 * The short and long description, read through the CRUD.
 *
 * Both are filtered at the product object rather than at the template, because
 * that is the one place every caller passes through — the product page, the
 * shop card, the REST API the mobile app reads, and the order emails.
 *
 * @param string     $value   The English text.
 * @param WC_Product $product Product.
 * @return string
 */
function prime_translate_product_short_description( $value, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $value;
	}

	$arabic = prime_ar_text( $product->get_id(), PRIME_AR_EXCERPT );

	return $arabic ? $arabic : $value;
}
add_filter( 'woocommerce_product_get_short_description', 'prime_translate_product_short_description', 10, 2 );

/**
 * @param string     $value   The English text.
 * @param WC_Product $product Product.
 * @return string
 */
function prime_translate_product_description( $value, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $value;
	}

	$arabic = prime_ar_text( $product->get_id(), PRIME_AR_CONTENT );

	return $arabic ? $arabic : $value;
}
add_filter( 'woocommerce_product_get_description', 'prime_translate_product_description', 10, 2 );

/**
 * The document title — the browser tab, the bookmark, and the blue line in a
 * Google result. It is built from single_post_title(), not the_title(), so it
 * needs saying separately or the Arabic page is listed under its English name.
 *
 * @param array $parts Title parts.
 * @return array
 */
function prime_translate_document_title( $parts ) {
	if ( is_singular( 'product' ) ) {
		$arabic = prime_ar_text( get_queried_object_id(), PRIME_AR_TITLE );

		if ( $arabic ) {
			$parts['title'] = $arabic;
		}
	}

	return $parts;
}
add_filter( 'document_title_parts', 'prime_translate_document_title' );

/**
 * Category and tag names, where an Arabic name has been entered for the term.
 *
 * Nothing is translated until someone fills these in (Products → Categories);
 * until then the English name shows on both sides, which is what happens today.
 *
 * @param string $name    Term name.
 * @param int    $term_id Term ID.
 * @return string
 */
function prime_translate_term_name( $name, $term_id ) {
	if ( ! prime_is_arabic() ) {
		return $name;
	}

	$arabic = (string) get_term_meta( $term_id, PRIME_AR_NAME, true );

	return $arabic ? $arabic : $name;
}

/**
 * @param WP_Term $term Term.
 * @return WP_Term
 */
function prime_translate_term( $term ) {
	if ( $term instanceof WP_Term && in_array( $term->taxonomy, prime_single_post_types()['taxonomies'], true ) ) {
		$term->name = prime_translate_term_name( $term->name, $term->term_id );
	}

	return $term;
}
add_filter( 'get_term', 'prime_translate_term' );

/* -------------------------------------------------------------------------
 * Search engines: two URLs, one product.
 * ---------------------------------------------------------------------- */

/**
 * Tell search engines that the English and Arabic URLs are the same product in
 * two languages, rather than two competing pages.
 */
function prime_product_hreflang() {
	if ( ! prime_single_product_mode() || ! is_singular( 'product' ) || ! function_exists( 'pll_languages_list' ) ) {
		return;
	}

	$product_id = get_queried_object_id();
	$english    = prime_product_url_in( $product_id, 'en' );
	$arabic     = prime_product_url_in( $product_id, 'ar' );

	printf( '<link rel="alternate" href="%s" hreflang="en" />' . "\n", esc_url( $english ) );
	printf( '<link rel="alternate" href="%s" hreflang="ar" />' . "\n", esc_url( $arabic ) );
	printf( '<link rel="alternate" href="%s" hreflang="x-default" />' . "\n", esc_url( $english ) );
	printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( prime_is_arabic() ? $arabic : $english ) );
}
add_action( 'wp_head', 'prime_product_hreflang', 1 );

/**
 * A product's URL in a given language, whatever language this page is in.
 *
 * @param int    $product_id Product ID.
 * @param string $lang       Language slug.
 * @return string
 */
function prime_product_url_in( $product_id, $lang ) {
	$base   = prime_permalink_base( 'product' );
	$slug   = (string) get_post_field( 'post_name', $product_id );
	$prefix = prime_lang_prefix( $lang );

	if ( '' !== $prefix ) {
		$arabic_slug = (string) get_post_meta( $product_id, PRIME_AR_SLUG, true );
		$slug        = $arabic_slug ? $arabic_slug : $slug;
	}

	return home_url( ( '' !== $prefix ? '/' . $prefix : '' ) . '/' . $base . '/' . $slug . '/' );
}

/**
 * Where the language switcher should send a visitor: this same page in the
 * other language.
 *
 * Polylang only knows the pages it translates itself. Products and their
 * categories are single posts now (this file), so for them it has no
 * "translation" to offer and falls back to the other language's home page.
 * Reem, 2026-10-01: choosing العربية on a product must turn that product
 * Arabic, not drop her on the home page.
 *
 * @param string $lang     Target language slug.
 * @param string $fallback Polylang's own URL for it.
 * @return string
 */
function prime_language_switch_url( $lang, $fallback ) {
	if ( ! prime_single_product_mode() ) {
		return $fallback;
	}

	if ( is_singular( 'product' ) ) {
		return prime_product_url_in( get_queried_object_id(), $lang );
	}

	if ( is_tax( prime_single_post_types()['taxonomies'] ) ) {
		// Category and tag URLs differ only by the /ar/ prefix (same slug), so
		// swap the prefix on the path being viewed; pagination and filters in
		// the query string carry over.
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$home    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path    = ltrim( substr( $request, strlen( rtrim( $home, '/' ) ) ), '/' );

		foreach ( (array) pll_languages_list() as $code ) {
			$p = prime_lang_prefix( $code );

			if ( '' !== $p && ( $path === $p || 0 === strpos( $path, $p . '/' ) ) ) {
				$path = ltrim( substr( $path, strlen( $p ) ), '/' );
				break;
			}
		}

		$prefix = prime_lang_prefix( $lang );

		return home_url( '/' . ( '' !== $prefix ? $prefix . '/' : '' ) . $path );
	}

	return $fallback;
}

/**
 * WordPress prints its own canonical for a single product, which would name
 * the English URL on the Arabic page. Ours, above, replaces it.
 */
function prime_drop_core_canonical() {
	if ( prime_single_product_mode() && is_singular( 'product' ) ) {
		remove_action( 'wp_head', 'rel_canonical' );
	}
}
add_action( 'template_redirect', 'prime_drop_core_canonical' );

/* -------------------------------------------------------------------------
 * The product screen: where the Arabic text is written.
 * ---------------------------------------------------------------------- */

/**
 * An "Arabic" box on the product edit screen — the one place a product's
 * Arabic title, short description and description are entered now that there
 * is no second post to hold them.
 */
function prime_add_arabic_meta_box() {
	add_meta_box(
		'prime-arabic',
		__( 'Arabic (العربية)', 'prime-printing' ),
		'prime_render_arabic_meta_box',
		'product',
		'normal',
		'low'
	);
}
add_action( 'add_meta_boxes', 'prime_add_arabic_meta_box' );

/**
 * @param WP_Post $post The product.
 */
function prime_render_arabic_meta_box( $post ) {
	wp_nonce_field( 'prime_arabic_save', 'prime_arabic_nonce' );

	$fields = array(
		PRIME_AR_TITLE   => array( __( 'Product name in Arabic', 'prime-printing' ), 'text' ),
		PRIME_AR_SLUG    => array( __( 'Arabic URL slug', 'prime-printing' ), 'text' ),
		PRIME_AR_EXCERPT => array( __( 'Short description in Arabic', 'prime-printing' ), 'textarea' ),
		PRIME_AR_CONTENT => array( __( 'Description in Arabic', 'prime-printing' ), 'editor' ),
	);

	echo '<p class="description">' . esc_html__( 'Leave a field empty to show the English text on the Arabic site.', 'prime-printing' ) . '</p>';

	foreach ( $fields as $key => $field ) {
		list( $label, $type ) = $field;
		$value                = (string) get_post_meta( $post->ID, $key, true );

		printf( '<p><label for="%1$s"><strong>%2$s</strong></label><br>', esc_attr( $key ), esc_html( $label ) );

		if ( 'editor' === $type ) {
			wp_editor( $value, $key, array( 'textarea_rows' => 8, 'media_buttons' => false, 'textarea_name' => $key ) );
		} elseif ( 'textarea' === $type ) {
			printf( '<textarea id="%1$s" name="%1$s" rows="3" style="width:100%%" dir="rtl">%2$s</textarea>', esc_attr( $key ), esc_textarea( $value ) );
		} else {
			printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s" style="width:100%%" dir="rtl">', esc_attr( $key ), esc_attr( $value ) );
		}

		echo '</p>';
	}
}

/**
 * @param int $post_id Product ID.
 */
function prime_save_arabic_meta( $post_id ) {
	if ( ! isset( $_POST['prime_arabic_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_arabic_nonce'] ) ), 'prime_arabic_save' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$clean = array(
		PRIME_AR_TITLE   => 'sanitize_text_field',
		PRIME_AR_SLUG    => 'sanitize_title',
		PRIME_AR_EXCERPT => 'wp_kses_post',
		PRIME_AR_CONTENT => 'wp_kses_post',
	);

	foreach ( $clean as $key => $sanitize ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}

		$value = call_user_func( $sanitize, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by the callback on this line.

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
add_action( 'save_post_product', 'prime_save_arabic_meta' );
