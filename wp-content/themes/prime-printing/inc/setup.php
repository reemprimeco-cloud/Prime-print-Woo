<?php
/**
 * Theme setup — supports, menus, image sizes.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register theme supports and navigation locations.
 */
function prime_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );

	add_theme_support(
		'html5',
		array(
			'search-form',
			'gallery',
			'caption',
			'style',
			'script',
			'navigation-widgets',
		)
	);

	// The design is a fixed brand system, not a user-themable one. Opting out of
	// the editor's colour and size UIs keeps editors from introducing off-brand
	// values that would then need policing.
	add_theme_support( 'disable-custom-colors' );
	add_theme_support( 'disable-custom-font-sizes' );
	add_theme_support( 'editor-color-palette', prime_editor_palette() );

	register_nav_menus(
		array(
			'primary'      => __( 'Primary navigation', 'prime-printing' ),
			'mobile'       => __( 'Mobile full-screen menu', 'prime-printing' ),
			'footer_shop'  => __( 'Footer — shop', 'prime-printing' ),
			'footer_pages' => __( 'Footer — company', 'prime-printing' ),
		)
	);

	/*
	 * Image sizes matched to the reference layouts so WordPress serves a crop
	 * close to the rendered box rather than scaling a full-size upload:
	 *   card  the shop grid tile           (.ph is 246px tall at 4-up)
	 *   tile  the homepage shuffling wall  (square, and 1:2 for the tall variant)
	 *   cat   the category card            (aspect-ratio 1/.86)
	 */
	add_image_size( 'prime-card', 640, 492, true );
	add_image_size( 'prime-tile', 800, 800, true );
	add_image_size( 'prime-tile-tall', 800, 1664, true );
	add_image_size( 'prime-cat', 720, 620, true );
}
add_action( 'after_setup_theme', 'prime_setup' );

/**
 * Register the theme's translation path.
 *
 * Called directly, immediately — not hooked to `init` or anything else.
 * `load_theme_textdomain()` doesn't itself read a .mo file; it only records
 * the theme's languages directory in `WP_Textdomain_Registry` (and clears a
 * stale NOOP registration, if one exists) so that WordPress's "just in time"
 * loader (core since 6.7) resolves the right file the first time any `__()`
 * call for 'prime-printing' actually runs — which can happen well before
 * `init`. Calling this as early as possible only helps; deferring it to a
 * hook risks a premature `__()` call already having JIT-loaded (and cached)
 * the domain by the time this runs, in which case a redundant
 * `unload_textdomain()` here would tear down a translation that already
 * loaded correctly.
 *
 * The .mo file must be named `ar.mo`, not `prime-printing-ar.mo`: the JIT
 * loader uses the bare-locale filename for any textdomain path inside the
 * theme's own directory, and only uses the domain-prefixed filename for
 * translations loaded from the global `WP_LANG_DIR/themes/` directory.
 * Getting this backwards silently fails closed: the domain still registers
 * as "loaded" (no error surfaces anywhere) but every `__()` call for it
 * falls through to the untranslated source string, while `get_locale()`,
 * the URL, and `dir="rtl"` all still correctly show the switched language.
 */
load_theme_textdomain( 'prime-printing', PRIME_DIR . '/languages' );

/**
 * Content width, used by WordPress for oEmbed and large image sizing.
 */
function prime_content_width() {
	$GLOBALS['content_width'] = 1280;
}
add_action( 'after_setup_theme', 'prime_content_width', 0 );

/**
 * The brand palette, exposed to the block editor.
 *
 * These are the same values as the CSS custom properties in
 * assets/css/tokens.css. If one changes, change both.
 *
 * @return array[]
 */
function prime_editor_palette() {
	return array(
		array(
			'name'  => __( 'Navy', 'prime-printing' ),
			'slug'  => 'navy',
			'color' => '#10254A',
		),
		array(
			'name'  => __( 'Navy deep', 'prime-printing' ),
			'slug'  => 'navy-deep',
			'color' => '#08132A',
		),
		array(
			'name'  => __( 'Sky', 'prime-printing' ),
			'slug'  => 'sky',
			'color' => '#7CA5C4',
		),
		array(
			'name'  => __( 'Mist', 'prime-printing' ),
			'slug'  => 'mist',
			'color' => '#F4F7F9',
		),
		array(
			'name'  => __( 'Paper', 'prime-printing' ),
			'slug'  => 'paper',
			'color' => '#FFFFFF',
		),
	);
}

/**
 * Add a body class when the page opens with the dark hero, so the header can
 * switch to its transparent-over-hero mode.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function prime_body_classes( $classes ) {
	if ( is_front_page() && ! is_paged() ) {
		$classes[] = 'prime-has-hero';
	}

	return $classes;
}
add_filter( 'body_class', 'prime_body_classes' );
