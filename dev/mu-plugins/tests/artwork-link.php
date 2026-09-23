<?php
/** Fixture for the artwork-download test: a private customer upload, as product-addons.php makes one. */
require_once __DIR__ . '/lib.php';

$dir  = wp_upload_dir()['basedir'] . '/prime-private';
wp_mkdir_p( $dir );
$path = $dir . '/customer-artwork.txt';
file_put_contents( $path, "ORIGINAL ARTWORK BYTES\n" );

$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_prime_test_fixture', 'meta_value' => '1' ) );
foreach ( $existing as $old ) { wp_delete_post( $old, true ); }

$mk = static function ( $login, $pass ) {
	$u = get_user_by( 'login', $login );
	return $u ? $u : get_user_by( 'id', wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'role' => 'customer' ) ) );
};
$customer = $mk( 'prime_test_customer', 'customerpass' );
$mk( 'prime_other', 'otherpass' );

$id = wp_insert_post( array(
	'post_type'      => 'attachment',
	'post_status'    => 'private',
	'post_title'     => 'customer-artwork.txt',
	'post_mime_type' => 'text/plain',
) );
update_post_meta( $id, '_prime_private_path', $path );
update_post_meta( $id, '_prime_customer_id', $customer->ID );
update_post_meta( $id, '_prime_test_fixture', '1' );

// The link exactly as an order placed months ago would carry it: built in the
// customer's session, with a nonce that has long since died.
$stale = add_query_arg( array( 'prime_download_file' => $id, '_wpnonce' => 'deadbeef00' ), home_url( '/' ) );

echo "attachment_id=$id\ncustomer_id={$customer->ID}\nfresh_url=" . prime_file_download_url( $id ) . "\nstale_url=$stale\n";
