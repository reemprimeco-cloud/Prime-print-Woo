<?php
/** Sets up a merged product so the old Arabic URL can be fetched over HTTP. ?step=cleanup removes it. */
require_once __DIR__ . '/lib.php';

$existing = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_prime_live_test', 'meta_value' => '1' ) );

if ( 'cleanup' === ( $_GET['step'] ?? '' ) ) {
	foreach ( $existing as $id ) { wp_delete_post( $id, true ); }
	echo "removed " . count( $existing ) . "\n";
	return;
}
foreach ( $existing as $id ) { wp_delete_post( $id, true ); }

$en = new WC_Product_Simple();
$en->set_name( 'Gergean Box' );
$en->set_slug( 'gergean-box-live' );
$en->set_regular_price( '7.500' );
$en->set_short_description( 'A festive box.' );
$en->set_stock_status( 'instock' );
$en->set_status( 'publish' );
$en_id = $en->save();
update_post_meta( $en_id, '_prime_live_test', '1' );

$ar_id = wp_insert_post( array(
	'post_type' => 'product', 'post_status' => 'publish',
	'post_title' => 'صندوق القرقيعان', 'post_name' => 'صندوق-القرقيعان',
	'post_excerpt' => 'صندوق للعيد.',
) );
update_post_meta( $ar_id, '_prime_live_test', '1' );
update_post_meta( $ar_id, '_price', '7.500' );
pll_set_post_language( $en_id, 'en' );
pll_set_post_language( $ar_id, 'ar' );
pll_save_post_translations( array( 'en' => $en_id, 'ar' => $ar_id ) );

$old_ar_url = get_permalink( $ar_id );
prime_merge_one_product( $en_id, $ar_id );

echo "english_id=$en_id\narabic_id=$ar_id\nold_arabic_url=$old_ar_url\nenglish_url=" . home_url( '/product/gergean-box-live/' ) . "\n";
