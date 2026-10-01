<?php
/**
 * Phase 6 — bilingual system.
 *
 * Polylang does the heavy lifting (language-prefixed URLs, hreflang tags, the
 * detection/redirect logic, translated-post relationships). This file's job is
 * narrow: register the two languages once on a fresh install, expose the
 * theme's dynamic strings (Customizer text, category names pulled from
 * WooCommerce) to Polylang's string-translation screen, and register the
 * language switcher in the primary nav location so Polylang's own menu
 * item type can be placed there from wp-admin.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register English (default) and Arabic on first run.
 *
 * Polylang's own onboarding wizard normally does this from wp-admin; a
 * headless/CLI-provisioned install (this dev environment, and potentially the
 * real Cloudways deploy if the theme is activated before anyone visits
 * wp-admin) never sees that wizard, so the languages would otherwise simply
 * not exist and every __()/_e() call would have nothing to switch to.
 *
 * Hooked to both `pll_init` and `admin_init`. Polylang only builds its full
 * request context (and fires `pll_init`) in the admin, or once at least one
 * language already exists — on a plain frontend request with zero languages
 * configured, `Polylang::init()` deliberately skips context setup entirely
 * (see `src/class-polylang.php`), so `pll_init` never fires there on a fresh
 * install. `admin_init` is the reliable backstop: it always fires on any
 * wp-admin request, by which point Polylang's admin context is guaranteed to
 * have loaded. The function's own "already exists" checks make firing from
 * both hooks harmless — the two-plugin activation quirk documented in
 * dev/boot.sh means a real wp-admin visit (which `--login` alone does not
 * provide — it does not itself load /wp-admin/) is what actually triggers
 * this either way, so `admin_init` is really the hook doing the work in this
 * dev environment; `pll_init` stays wired for a normal production activation
 * flow where languages may already be getting configured through Polylang's
 * own setup wizard before this ever needs to run.
 */
function prime_setup_languages() {
	if ( ! function_exists( 'PLL' ) ) {
		return;
	}

	$model = PLL()->model ?? null;

	// is_callable(), not method_exists(): `add_language()` is not a real
	// declared method on PLL_Model — it is delegated through PHP's __call()
	// magic method to $model->languages->add() (see the @method PHPDoc
	// annotation on PLL_Model). method_exists() does not see through magic
	// __call(), so it reports false for a perfectly callable method — which
	// silently defeated this guard on every plain /wp-admin/ request (a
	// Polylang settings-specific request gets a different model class,
	// PLL_Admin_Model, where this happened not to matter; the ordinary
	// admin_init context this actually runs in uses the base PLL_Model).
	if ( ! is_object( $model ) || ! is_callable( array( $model, 'add_language' ) ) ) {
		return;
	}

	if ( empty( $model->get_language( 'en' ) ) ) {
		$model->add_language(
			array(
				'locale'     => 'en_US',
				'slug'       => 'en',
				'name'       => 'English',
				'rtl'        => 0,
				'term_group' => 0,
				'flag'       => 'us',
			)
		);
	}

	if ( empty( $model->get_language( 'ar' ) ) ) {
		$model->add_language(
			array(
				'locale'     => 'ar',
				'slug'       => 'ar',
				'name'       => 'العربية',
				'rtl'        => 1,
				'term_group' => 1,
				'flag'       => 'sa',
			)
		);
	}

	// English default, both languages shown (no "hide default language slug"),
	// URL structure /ar/... for Arabic — matches the build plan's
	// primeprint.com.kw/... (EN) vs primeprint.com.kw/ar/... (AR) split.
	$options = get_option( 'polylang', array() );
	$options['default_lang'] = 'en';
	$options['hide_default'] = 1; // English has no /en/ prefix.
	$options['force_lang']   = 1; // Language is read from the URL slug.
	$options['media_support'] = 0;
	update_option( 'polylang', $options );

	if ( method_exists( PLL(), 'sync' ) === false ) {
		flush_rewrite_rules();
	}
}
add_action( 'pll_init', 'prime_setup_languages' );
add_action( 'admin_init', 'prime_setup_languages' );

/**
 * Expose theme strings that don't come from post content to Polylang's
 * Languages → Translations screen.
 *
 * Anything wrapped in __()/_e() with the 'prime-printing' text domain is
 * already translatable through the theme's own languages/ar.mo file — this
 * covers the separate case of strings stored as *data* (theme mod values,
 * category names) rather than in template code.
 */
function prime_register_translatable_strings() {
	if ( ! function_exists( 'pll_register_string' ) ) {
		return;
	}

	$contact_defaults = array(
		'prime_contact_phone' => __( 'Phone number', 'prime-printing' ),
		'prime_contact_email' => __( 'Contact email', 'prime-printing' ),
	);

	foreach ( $contact_defaults as $key => $label ) {
		$value = get_theme_mod( $key, '' );

		if ( $value ) {
			pll_register_string( $label, $value, 'Prime Printing' );
		}
	}
}
add_action( 'init', 'prime_register_translatable_strings', 20 );

/**
 * Whether Polylang is active and languages are actually registered.
 *
 * Guards every place the theme assumes a working bilingual setup (the
 * language switcher, RTL-dependent markup) so an install without Polylang yet
 * — or one where language registration above has not run — degrades to a
 * plain English site instead of a half-broken switcher pointing nowhere.
 *
 * @return bool
 */
function prime_has_polylang() {
	return function_exists( 'pll_languages_list' ) && count( (array) pll_languages_list() ) > 1;
}

/**
 * Whether a product ID is "the" canonical product — the one a calculator
 * file (uv-dtf-calculator.php and its three siblings) was built for — OR one
 * of its Polylang translations.
 *
 * Every one of those files hard-codes a single English product ID as its
 * gate (PRIME_UV_DTF_PRODUCT_ID and friends), because that ID is also the
 * one place their pricing constants and markup are attached to. The Arabic
 * bulk import (2026-09-05, inc/i18n-product-import.php) duplicated all four
 * of those products like any other — a new post ID, with no calculator UI
 * or server-side price enforcement wired to it, since nothing in these files
 * knew that new ID existed. A customer on the Arabic UV DTF Sticker page got
 * a bare "Add to cart" at whatever price happened to be copied onto the
 * duplicate, with none of the real per-cm pricing behind it.
 *
 * Rather than hard-code a second (Arabic) ID next to every canonical one —
 * fragile the moment a third language or a re-import changes it — this asks
 * Polylang directly: is $candidate_id equal to $canonical_id, or is it what
 * Polylang has on file as $canonical_id's translation into any registered
 * language? True either way means "treat it exactly like the canonical
 * product."
 *
 * @param int|string $candidate_id  The product ID actually being rendered/priced.
 * @param int        $canonical_id  The calculator's own hard-coded product ID.
 * @return bool
 */
function prime_product_id_matches( $candidate_id, $canonical_id ) {
	$candidate_id = (int) $candidate_id;
	$canonical_id = (int) $canonical_id;

	if ( $candidate_id === $canonical_id ) {
		return true;
	}

	// The calculator's product by its URL slug, for when the product the ID
	// names is gone (the Arabic clean-up of 2026-10-01 deleted two PP sticker
	// posts in a row) and the one on sale is a fresh post under a known slug.
	$slugs = (array) apply_filters( 'prime_calculator_product_slugs', array(), $canonical_id );

	if ( $slugs && in_array( get_post_field( 'post_name', $candidate_id ), $slugs, true ) ) {
		return true;
	}

	// Pinned by hand on the product (Product data → General → "Price calculator").
	if ( (int) get_post_meta( $candidate_id, PRIME_CALCULATOR_META, true ) === $canonical_id ) {
		return true;
	}

	// After the single-product switch: the calculator's own product may be the
	// copy that was retired (Polylang can have filed it as the Arabic side), in
	// which case its text and slug now live on the product that is on sale.
	if ( defined( 'PRIME_AR_SLUG' ) && 'publish' !== get_post_status( $canonical_id ) ) {
		$canonical_slug = (string) get_post_field( 'post_name', $canonical_id );
		$carried        = (string) get_post_meta( $candidate_id, PRIME_AR_SLUG, true );

		if ( '' !== $canonical_slug && '' !== $carried && rawurldecode( $carried ) === rawurldecode( $canonical_slug ) ) {
			return true;
		}
	}

	// Polylang's own translation group, read straight from its taxonomy. This
	// keeps working after products stop being a translated post type (the
	// single-product switch in inc/i18n-products.php), when pll_get_post()
	// no longer answers for them.
	if ( taxonomy_exists( 'post_translations' ) ) {
		foreach ( (array) wp_get_object_terms( $candidate_id, 'post_translations' ) as $term ) {
			$group = is_object( $term ) ? maybe_unserialize( $term->description ) : null;
			if ( is_array( $group ) && in_array( $canonical_id, array_map( 'intval', $group ), true ) ) {
				return true;
			}
		}
	}

	if ( ! function_exists( 'pll_get_post' ) ) {
		return false;
	}

	foreach ( (array) pll_languages_list() as $language ) {
		$translated_id = pll_get_post( $canonical_id, $language );

		if ( $translated_id && (int) $translated_id === $candidate_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Product meta that pins a product to one of the price calculators, by the
 * calculator's own product ID. For a copy (another language, a duplicate)
 * the automatic match cannot see.
 */
const PRIME_CALCULATOR_META = '_prime_calculator_of';

/**
 * The calculators a product can be pinned to: canonical product ID => label.
 *
 * @return array<int, string>
 */
function prime_calculator_choices() {
	$choices = array();
	foreach ( array(
		'PRIME_PP_STICKER_PRODUCT_ID'    => 'PP sticker',
		'PRIME_PAPER_STICKER_PRODUCT_ID' => 'Paper sticker',
		'PRIME_UV_DTF_PRODUCT_ID'        => 'UV DTF sticker',
		'PRIME_DIECUT_PRODUCT_ID'        => 'Die-cut cards',
	) as $const => $label ) {
		if ( defined( $const ) ) {
			$choices[ (int) constant( $const ) ] = $label;
		}
	}

	return $choices;
}

/**
 * "Price calculator" select on the product's General tab.
 */
function prime_render_calculator_field() {
	global $post;
	if ( ! $post ) {
		return;
	}

	$options = array( '' => __( 'Automatic (by product)', 'prime-printing' ) );
	foreach ( prime_calculator_choices() as $id => $label ) {
		$options[ (string) $id ] = $label;
	}

	echo '<div class="options_group">';
	woocommerce_wp_select(
		array(
			'id'          => PRIME_CALCULATOR_META,
			'label'       => __( 'Price calculator', 'prime-printing' ),
			'options'     => $options,
			'value'       => (string) get_post_meta( $post->ID, PRIME_CALCULATOR_META, true ),
			'desc_tip'    => true,
			'description' => __( 'Show a sticker price calculator (size, shape, quantity) on this product. Automatic works for the original products; pick one here if a copy of a product lost its calculator.', 'prime-printing' ),
		)
	);
	echo '</div>';
}
add_action( 'woocommerce_product_options_general_product_data', 'prime_render_calculator_field' );

/**
 * @param int $post_id Product id.
 */
function prime_save_calculator_field( $post_id ) {
	// WooCommerce has already checked the product-save nonce and capability.
	$v = isset( $_POST[ PRIME_CALCULATOR_META ] ) ? absint( wp_unslash( $_POST[ PRIME_CALCULATOR_META ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

	if ( $v && isset( prime_calculator_choices()[ $v ] ) ) {
		update_post_meta( $post_id, PRIME_CALCULATOR_META, $v );
	} else {
		delete_post_meta( $post_id, PRIME_CALCULATOR_META );
	}
}
add_action( 'woocommerce_process_product_meta', 'prime_save_calculator_field' );

/**
 * Assign the site's default language to every existing post/page.
 *
 * Polylang only knows a post's language once something tells it — for
 * content created before Polylang was active (every page and product this
 * seeded catalogue has, and every page a real migration will import in Phase
 * 10), that never happens automatically. Without it, Polylang has no
 * language to serve a request in and falls back inconsistently (a 404, or
 * silently re-serving the English version under an `/ar/` URL) rather than
 * cleanly reporting "no Arabic translation exists yet for this page" the way
 * it does once every post has a language.
 *
 * This assigns English to anything unassigned — it does not create Arabic
 * translations, which is real content work (Phase 6's own "translate:
 * homepage copy, shop UI strings…" task list), not something to fabricate
 * here. Runs once per post, guarded by checking each post's own language
 * rather than a single "has this ever run" flag, so it stays correct as new
 * content (a migrated page, a newly added product) shows up over time.
 */
function prime_assign_default_language_to_existing_content() {
	if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_set_post_language' ) ) {
		return;
	}

	$post_types = array( 'page', 'post', 'product', 'client_logo' );

	/*
	 * Only the posts that still have no language, and only a batch of them.
	 *
	 * This runs on every single wp-admin request, so the original
	 * `posts_per_page => -1` meant loading all ~300 products, pages and posts
	 * and asking Polylang for each one's language on every admin page load,
	 * for the life of the site — fine against a seeded dev catalogue, not
	 * against the real one (2026-09-05). A NOT EXISTS query on Polylang's own
	 * `language` taxonomy asks the database the actual question instead, so
	 * once the backfill is complete this costs one cheap query that returns
	 * nothing. The batch cap keeps the first admin request after activation
	 * from doing all the work at once; the next few requests finish it.
	 */
	$posts = get_posts(
		array(
			'post_type'              => $post_types,
			'post_status'            => 'any',
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- this is the query that makes it fast; see above.
				array(
					'taxonomy' => 'language',
					'operator' => 'NOT EXISTS',
				),
			),
		)
	);

	foreach ( $posts as $post_id ) {
		if ( ! pll_get_post_language( $post_id ) ) {
			pll_set_post_language( $post_id, 'en' );
		}
	}
}
add_action( 'admin_init', 'prime_assign_default_language_to_existing_content', 11 );
