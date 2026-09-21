<?php
/** Dev fixture: give the dev site the Arabic Cart/Checkout pages the live site already has. */
require_once __DIR__ . '/lib.php';

$pages = array(
	'woocommerce_cart_page_id'     => array( 'السلة', 'cart-ar' ),
	'woocommerce_checkout_page_id' => array( 'إتمام الطلب', 'checkout-ar' ),
	'woocommerce_myaccount_page_id' => array( 'حسابي', 'my-account-ar' ),
);

foreach ( $pages as $option => $info ) {
	list( $title, $slug ) = $info;
	$en_id = (int) get_option( $option );

	if ( ! $en_id ) {
		t_ok( false, "English page for $option exists" );
		continue;
	}

	pll_set_post_language( $en_id, 'en' );

	$existing = get_page_by_path( $slug );
	$ar_id    = $existing ? $existing->ID : wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => get_post_field( 'post_content', $en_id ),
		)
	);

	pll_set_post_language( $ar_id, 'ar' );
	pll_save_post_translations( array( 'en' => $en_id, 'ar' => $ar_id ) );
	t_ok( pll_get_post( $en_id, 'ar' ) === $ar_id, "Arabic page linked for $option", "en=$en_id ar=$ar_id" );
}

flush_rewrite_rules( false );
t_done();
