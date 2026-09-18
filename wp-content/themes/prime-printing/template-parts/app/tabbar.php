<?php
/**
 * App mode — the fixed bottom tab bar: Home, Shop, Cart (live count), Account.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_active = prime_app_active_tab();
$prime_tabs   = array(
	array(
		'key'   => 'home',
		'label' => __( 'Home', 'prime-printing' ),
		'url'   => home_url( '/' ),
	),
);

if ( prime_has_woocommerce() ) {
	$prime_shop_id = wc_get_page_id( 'shop' );

	$prime_tabs[] = array(
		'key'   => 'shop',
		'label' => __( 'Shop', 'prime-printing' ),
		'url'   => $prime_shop_id > 0 ? add_query_arg( 'view', 'all', get_permalink( $prime_shop_id ) ) : home_url( '/' ),
	);
	$prime_tabs[] = array(
		'key'   => 'cart',
		'label' => __( 'Cart', 'prime-printing' ),
		'url'   => wc_get_cart_url(),
	);
	$prime_tabs[] = array(
		'key'   => 'account',
		'label' => __( 'Account', 'prime-printing' ),
		'url'   => wc_get_page_permalink( 'myaccount' ),
	);
}
?>

<nav class="prime-app-tabs" aria-label="<?php esc_attr_e( 'App navigation', 'prime-printing' ); ?>">
	<?php foreach ( $prime_tabs as $prime_tab ) : ?>
		<a
			class="prime-app-tab <?php echo $prime_active === $prime_tab['key'] ? 'is-on' : ''; ?>"
			href="<?php echo esc_url( $prime_tab['url'] ); ?>"
			<?php echo $prime_active === $prime_tab['key'] ? 'aria-current="page"' : ''; ?>
		>
			<?php echo prime_app_icon( $prime_tab['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
			<span><?php echo esc_html( $prime_tab['label'] ); ?></span>
			<?php if ( 'cart' === $prime_tab['key'] ) : ?>
				<?php echo prime_app_cart_badge_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
