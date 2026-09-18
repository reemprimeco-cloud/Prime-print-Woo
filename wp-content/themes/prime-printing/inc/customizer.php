<?php
/**
 * Customizer settings.
 *
 * Everything the references hard-code as placeholder copy — the announcement
 * bar, the phone number, the hero headline, the service list — is a setting
 * here, so none of it needs a developer to change.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register panels, sections and settings.
 *
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
function prime_customize_register( $wp_customize ) {
	$wp_customize->add_panel(
		'prime_panel',
		array(
			'title'       => __( 'Prime Printing', 'prime-printing' ),
			'description' => __( 'Content that appears across the site and on the homepage.', 'prime-printing' ),
			'priority'    => 20,
		)
	);

	/* ---- Site-wide ------------------------------------------------------- */

	$wp_customize->add_section(
		'prime_site',
		array(
			'title' => __( 'Header & footer', 'prime-printing' ),
			'panel' => 'prime_panel',
		)
	);

	$wp_customize->add_setting(
		'prime_announcement',
		array(
			'default'           => __( 'Delivery within 48 hours across Kuwait', 'prime-printing' ),
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'refresh',
		)
	);

	$wp_customize->add_control(
		'prime_announcement',
		array(
			'label'       => __( 'Announcement bar', 'prime-printing' ),
			'description' => __( 'Leave empty to hide the bar entirely.', 'prime-printing' ),
			'section'     => 'prime_site',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'prime_footer_blurb',
		array(
			'default'           => __( 'A creative printing boutique in Kuwait producing stickers, stamps, calendars and packaging in-house.', 'prime-printing' ),
			'sanitize_callback' => 'sanitize_textarea_field',
		)
	);

	$wp_customize->add_control(
		'prime_footer_blurb',
		array(
			'label'   => __( 'Footer description', 'prime-printing' ),
			'section' => 'prime_site',
			'type'    => 'textarea',
		)
	);

	/* ---- Contact --------------------------------------------------------- */

	$wp_customize->add_section(
		'prime_contact',
		array(
			'title'       => __( 'Contact details', 'prime-printing' ),
			'description' => __( 'Used in the footer, the mobile menu, and the WhatsApp links on orders.', 'prime-printing' ),
			'panel'       => 'prime_panel',
		)
	);

	$contact_fields = array(
		'address'   => array(
			'label'       => __( 'Company address', 'prime-printing' ),
			'description' => __( 'Used on invoices (Phase 7) as the "from" address.', 'prime-printing' ),
			'default'     => prime_default_company_address(),
			'sanitize'    => 'sanitize_textarea_field',
			'type'        => 'textarea',
		),
		'phone'     => array(
			'label'    => __( 'Phone', 'prime-printing' ),
			'default'  => '+965 0000 0000',
			'sanitize' => 'sanitize_text_field',
		),
		'email'     => array(
			'label'    => __( 'Email', 'prime-printing' ),
			'default'  => 'hello@primeprint.com.kw',
			'sanitize' => 'sanitize_email',
		),
		'whatsapp'  => array(
			'label'       => __( 'WhatsApp link', 'prime-printing' ),
			'description' => __( 'Full wa.me link, for example https://wa.me/9650000000', 'prime-printing' ),
			'default'     => '',
			'sanitize'    => 'esc_url_raw',
		),
		'map_embed'    => array(
			'label'       => __( 'Pickup location — map embed URL', 'prime-printing' ),
			'description' => __( 'The src= URL from Google Maps\' own "Share > Embed a map" panel, not a regular maps.google.com link. Shown on the order confirmation page when a customer chooses local pickup.', 'prime-printing' ),
			'default'     => prime_default_pickup_map()['embed'],
			'sanitize'    => 'esc_url_raw',
		),
		'map_directions' => array(
			'label'       => __( 'Pickup location — "Get directions" link', 'prime-printing' ),
			'description' => __( 'A plain Google Maps link (not the embed one above) — used for the "Get directions" button, and as the fallback if the map embed doesn\'t load.', 'prime-printing' ),
			'default'     => prime_default_pickup_map()['directions'],
			'sanitize'    => 'esc_url_raw',
		),
		'instagram' => array(
			'label'    => __( 'Instagram link', 'prime-printing' ),
			'default'  => '',
			'sanitize' => 'esc_url_raw',
		),
		'facebook'  => array(
			'label'    => __( 'Facebook link', 'prime-printing' ),
			'default'  => '',
			'sanitize' => 'esc_url_raw',
		),
	);

	foreach ( $contact_fields as $key => $field ) {
		$wp_customize->add_setting(
			'prime_contact_' . $key,
			array(
				'default'           => $field['default'],
				'sanitize_callback' => $field['sanitize'],
			)
		);

		$wp_customize->add_control(
			'prime_contact_' . $key,
			array(
				'label'       => $field['label'],
				'description' => isset( $field['description'] ) ? $field['description'] : '',
				'section'     => 'prime_contact',
				'type'        => isset( $field['type'] ) ? $field['type'] : 'text',
			)
		);
	}

	/* ---- Homepage -------------------------------------------------------- */

	$wp_customize->add_section(
		'prime_home',
		array(
			'title' => __( 'Homepage', 'prime-printing' ),
			'panel' => 'prime_panel',
		)
	);

	$wp_customize->add_setting(
		'prime_hero_eyebrow',
		array(
			'default'           => __( 'Creative printing boutique — Kuwait', 'prime-printing' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	$wp_customize->add_control(
		'prime_hero_eyebrow',
		array(
			'label'   => __( 'Hero — small line above the headline', 'prime-printing' ),
			'section' => 'prime_home',
			'type'    => 'text',
		)
	);

	$wp_customize->add_setting(
		'prime_hero_heading',
		array(
			'default'           => __( 'If it can be printed, we <em>already</em> print it.', 'prime-printing' ),
			'sanitize_callback' => 'prime_sanitize_heading',
		)
	);

	$wp_customize->add_control(
		'prime_hero_heading',
		array(
			'label'       => __( 'Hero headline', 'prime-printing' ),
			'description' => __( 'Wrap words in &lt;em&gt; to colour them sky blue. Use &lt;br&gt; for a line break.', 'prime-printing' ),
			'section'     => 'prime_home',
			'type'        => 'textarea',
		)
	);

	$wp_customize->add_setting(
		'prime_hero_text',
		array(
			'default'           => __( 'Stickers, stamps, calendars, packaging, backdrops, catalogs. Everything in one place — scroll and it finds you.', 'prime-printing' ),
			'sanitize_callback' => 'sanitize_textarea_field',
		)
	);

	$wp_customize->add_control(
		'prime_hero_text',
		array(
			'label'   => __( 'Hero paragraph', 'prime-printing' ),
			'section' => 'prime_home',
			'type'    => 'textarea',
		)
	);

	$wp_customize->add_setting(
		'prime_services',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_textarea_field',
		)
	);

	$wp_customize->add_control(
		'prime_services',
		array(
			'label'       => __( 'Service ticker', 'prime-printing' ),
			'description' => __( 'One service per line. Leave empty to list your product categories automatically.', 'prime-printing' ),
			'section'     => 'prime_home',
			'type'        => 'textarea',
		)
	);

	$wp_customize->add_setting(
		'prime_wall_count',
		array(
			'default'           => 12,
			'sanitize_callback' => 'absint',
		)
	);

	$wp_customize->add_control(
		'prime_wall_count',
		array(
			'label'       => __( 'Products in the shuffling wall', 'prime-printing' ),
			'section'     => 'prime_home',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 4,
				'max'  => 24,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'prime_configurator_product',
		array(
			'default'           => 0,
			'sanitize_callback' => 'absint',
		)
	);

	$wp_customize->add_control(
		'prime_configurator_product',
		array(
			'label'       => __( 'Configurator product', 'prime-printing' ),
			'description' => __( 'The custom-priced product the homepage calculator links to. Leave unset to hide that section.', 'prime-printing' ),
			'section'     => 'prime_home',
			'type'        => 'select',
			'choices'     => prime_product_choices(),
		)
	);
}
add_action( 'customize_register', 'prime_customize_register' );

/**
 * Allow only the inline markup the hero headline is designed around.
 *
 * @param string $value Raw setting value.
 * @return string
 */
function prime_sanitize_heading( $value ) {
	return wp_kses(
		$value,
		array(
			'em'     => array(),
			'br'     => array(),
			'strong' => array(),
		)
	);
}

/**
 * Published products, as Customizer select choices.
 *
 * @return array<int|string, string>
 */
function prime_product_choices() {
	$choices = array( 0 => __( '— None —', 'prime-printing' ) );

	if ( ! prime_has_woocommerce() ) {
		return $choices;
	}

	$products = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => 200,
			'orderby' => 'title',
			'order'   => 'ASC',
		)
	);

	if ( ! is_array( $products ) ) {
		return $choices;
	}

	foreach ( $products as $product ) {
		$choices[ $product->get_id() ] = $product->get_name();
	}

	return $choices;
}

/**
 * Expose the free-text settings to Polylang so they can be translated.
 *
 * Registered strings appear under Languages → Translations in wp-admin, which is
 * where Phase 6 puts the Arabic copy for all of them.
 */
function prime_register_polylang_strings() {
	if ( ! function_exists( 'pll_register_string' ) ) {
		return;
	}

	$strings = array(
		'prime_announcement' => __( 'Announcement bar', 'prime-printing' ),
		'prime_footer_blurb' => __( 'Footer description', 'prime-printing' ),
		'prime_hero_eyebrow' => __( 'Hero — small line above the headline', 'prime-printing' ),
		'prime_hero_heading' => __( 'Hero headline', 'prime-printing' ),
		'prime_hero_text'    => __( 'Hero paragraph', 'prime-printing' ),
		'prime_services'     => __( 'Service ticker', 'prime-printing' ),
	);

	foreach ( $strings as $key => $label ) {
		$value = get_theme_mod( $key, '' );

		if ( ! $value ) {
			continue;
		}

		pll_register_string( $label, $value, 'Prime Printing', 'prime_services' === $key );
	}
}
add_action( 'init', 'prime_register_polylang_strings' );
