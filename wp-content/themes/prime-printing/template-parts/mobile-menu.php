<?php
/**
 * The full-screen mobile menu.
 *
 * Rendered on every page but hidden until opened. It is a real <dialog>-style
 * overlay rather than a slide-out panel, matching the shop-v3 reference: search,
 * numbered primary links, category shortcuts, language, contact.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_menu_items = array(
	array(
		'title' => __( 'Home', 'prime-printing' ),
		'url'   => home_url( '/' ),
	),
);

if ( prime_has_woocommerce() ) {
	$prime_shop_id = wc_get_page_id( 'shop' );

	if ( $prime_shop_id > 0 ) {
		$prime_menu_items[] = array(
			'title' => __( 'Shop', 'prime-printing' ),
			'url'   => get_permalink( $prime_shop_id ),
		);

		$prime_menu_items[] = array(
			'title' => __( 'All products', 'prime-printing' ),
			'url'   => add_query_arg( 'view', 'all', get_permalink( $prime_shop_id ) ),
		);
	}
}

foreach ( get_pages( array( 'parent' => 0, 'sort_column' => 'menu_order,post_title', 'number' => 2, 'exclude' => prime_system_page_ids() ) ) as $prime_page ) {
	$prime_menu_items[] = array(
		'title' => $prime_page->post_title,
		'url'   => get_permalink( $prime_page ),
	);
}

$prime_categories = prime_product_categories( 8 );
?>

<div class="prime-menu" id="prime-menu" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Menu', 'prime-printing' ); ?>" inert>
	<div class="prime-menu__top">
		<?php prime_logo( array( 'tag' => 'div' ) ); ?>
		<button
			class="prime-menu__close"
			type="button"
			aria-label="<?php esc_attr_e( 'Close menu', 'prime-printing' ); ?>"
			data-prime-menu-close
		>&times;</button>
	</div>

	<div class="prime-menu__body">
		<form class="prime-menu__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="prime-menu-search">
				<?php esc_html_e( 'Search products and categories', 'prime-printing' ); ?>
			</label>
			<input
				id="prime-menu-search"
				type="search"
				name="s"
				placeholder="<?php esc_attr_e( 'Search products and categories', 'prime-printing' ); ?>"
				value="<?php echo esc_attr( get_search_query() ); ?>"
			>
			<?php if ( prime_has_woocommerce() ) : ?>
				<input type="hidden" name="post_type" value="product">
			<?php endif; ?>
			<button type="submit" aria-label="<?php esc_attr_e( 'Search', 'prime-printing' ); ?>">&rarr;</button>
		</form>

		<ul class="prime-menu__links">
			<?php foreach ( $prime_menu_items as $prime_index => $prime_item ) : ?>
				<li>
					<a href="<?php echo esc_url( $prime_item['url'] ); ?>">
						<span><?php echo esc_html( $prime_item['title'] ); ?></span>
						<span class="prime-menu__num" aria-hidden="true">
							<?php echo esc_html( str_pad( (string) ( $prime_index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $prime_categories ) : ?>
			<h2 class="prime-menu__head">
				<?php prime_mark(); ?>
				<span><?php esc_html_e( 'Jump to a category', 'prime-printing' ); ?></span>
			</h2>

			<div class="prime-menu__cats">
				<?php foreach ( $prime_categories as $prime_category ) : ?>
					<a class="prime-menu__cat" href="<?php echo esc_url( get_term_link( $prime_category ) ); ?>">
						<span><?php echo esc_html( $prime_category->name ); ?></span>
						<b><?php echo esc_html( number_format_i18n( $prime_category->count ) ); ?></b>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( function_exists( 'pll_the_languages' ) ) : ?>
			<h2 class="prime-menu__head">
				<?php prime_mark(); ?>
				<span><?php esc_html_e( 'Language', 'prime-printing' ); ?></span>
			</h2>
			<?php prime_language_switcher( 'segmented' ); ?>
		<?php endif; ?>

		<div class="prime-menu__foot">
			<div>
				<?php if ( prime_contact( 'phone' ) ) : ?>
					<div style="margin-bottom:6px">
						<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', prime_contact( 'phone' ) ) ); ?>">
							<?php echo esc_html( prime_contact( 'phone' ) ); ?>
						</a>
					</div>
				<?php endif; ?>

				<?php if ( prime_contact( 'email' ) ) : ?>
					<div>
						<a href="mailto:<?php echo esc_attr( prime_contact( 'email' ) ); ?>">
							<?php echo esc_html( prime_contact( 'email' ) ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<div class="prime-menu__social">
				<?php
				$prime_social = array(
					'instagram' => __( 'Instagram', 'prime-printing' ),
					'whatsapp'  => __( 'WhatsApp', 'prime-printing' ),
					'facebook'  => __( 'Facebook', 'prime-printing' ),
				);

				foreach ( $prime_social as $prime_key => $prime_label ) :
					$prime_url = prime_contact( $prime_key );

					if ( ! $prime_url ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( $prime_url ); ?>" rel="noopener" target="_blank">
						<?php echo esc_html( $prime_label ); ?>
					</a>
					<?php
				endforeach;
				?>
			</div>
		</div>
	</div>
</div>
