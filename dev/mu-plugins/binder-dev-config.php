<?php
/**
 * DEV ONLY (mounted by dev/start.sh, never deployed): points the binder plugin
 * at the render service running on this machine.
 */
if ( ! defined( 'BINDER_RENDER_URL' ) ) {
	define( 'BINDER_RENDER_URL', 'http://127.0.0.1:8787' );
}
if ( ! defined( 'BINDER_RENDER_SECRET' ) ) {
	define( 'BINDER_RENDER_SECRET', 'dev-secret-binder' );
}

/**
 * DEV ONLY: /?binder_dev_assign=<product id>:<binder_outer|binder_inner|binder_set> assigns a template to a
 * product, so the browser tests can set one up without wp-admin. Lives in dev/, never deployed.
 */
add_action(
	'init',
	static function () {
		if ( empty( $_GET['binder_dev_assign'] ) || ! class_exists( 'Binder_Product_Meta' ) ) { // phpcs:ignore
			return;
		}
		list( $id, $template ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_GET['binder_dev_assign'] ) ) ), 2, '' ); // phpcs:ignore
		if ( $template ) {
			update_post_meta( (int) $id, Binder_Product_Meta::META, sanitize_key( $template ) );
		} else {
			delete_post_meta( (int) $id, Binder_Product_Meta::META );
		}
		wp_die( 'assigned ' . (int) $id . ' => ' . esc_html( $template ) );
	}
);
