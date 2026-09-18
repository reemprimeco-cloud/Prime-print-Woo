<?php
/**
 * Single product page — hook rearrangement and small display helpers.
 *
 * WooCommerce's default summary hook order is title, rating, price, excerpt,
 * add-to-cart, meta, sharing. The reference design keeps title/rating/price in
 * that same order (so it is left alone) but wants a category kicker above the
 * title, no separate excerpt block (the full description already lives in the
 * Description tab — repeating it as an excerpt is redundant), no meta/SKU
 * line, and a single "in stock" line instead of the default sharing buttons.
 *
 * Reordering hooks rather than replacing content-single-product.php's summary
 * loop wholesale keeps every filter a plugin (reviews, structured data,
 * MyFatoorah's "buy now" variants) attaches to those hooks still firing.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rearrange the summary hooks.
 */
function prime_reorder_product_summary() {
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );

	add_action( 'woocommerce_single_product_summary', 'prime_product_category_kicker', 4 );
	add_action( 'woocommerce_single_product_summary', 'prime_product_stock_line', 45 );
}
add_action( 'init', 'prime_reorder_product_summary' );

/**
 * The product's primary category, shown as a small kicker above the title.
 */
function prime_product_category_kicker() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$terms = get_the_terms( $product->get_id(), 'product_cat' );

	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}
	?>
	<p class="prime-kicker">
		<?php prime_mark(); ?>
		<a href="<?php echo esc_url( get_term_link( $terms[0] ) ); ?>"><?php echo esc_html( $terms[0]->name ); ?></a>
	</p>
	<?php
}

/**
 * A single stock line, replacing the default SKU/category/tag meta table.
 */
function prime_product_stock_line() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$in_stock = $product->is_in_stock();
	?>
	<p class="prime-stockline <?php echo $in_stock ? '' : 'is-out'; ?>">
		<?php prime_mark( $in_stock ? '' : 'navy' ); ?>
		<span>
			<?php
			if ( $in_stock ) {
				esc_html_e( 'In stock · ships within 48 hours after approval', 'prime-printing' );
			} else {
				esc_html_e( 'Currently unavailable', 'prime-printing' );
			}
			?>
		</span>
	</p>
	<?php
}

/**
 * "From X KWD" price display for custom-priced products.
 *
 * A custom-priced product's regular price field holds the *minimum* piece
 * price (set alongside the pricing-model meta in inc/product-admin.php), which
 * is meaningful as a floor but wrong to display unqualified — the actual price
 * depends on the size the customer enters. This prefixes it accordingly,
 * everywhere WooCommerce would otherwise print a bare price: the product page,
 * the shop grid, the homepage wall.
 *
 * @param string     $price_html Existing price HTML.
 * @param WC_Product $product    Product.
 * @return string
 */
function prime_custom_price_html( $price_html, $product ) {
	if ( ! prime_is_custom_priced( $product->get_id() ) ) {
		return $price_html;
	}

	$config = prime_custom_pricing_config( $product->get_id() );

	if ( $config['min_piece'] <= 0 ) {
		return $price_html;
	}

	return sprintf(
		/* translators: %s: minimum price, already formatted with currency. */
		esc_html__( 'From %s', 'prime-printing' ),
		wc_price( $config['min_piece'] )
	);
}
add_filter( 'woocommerce_get_price_html', 'prime_custom_price_html', 10, 2 );

/**
 * Product image sizes for the custom gallery template part.
 *
 * @return array{main: array, thumb: array} Attachment IDs for main + gallery.
 */
function prime_product_gallery_ids() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	$ids = array();

	if ( $product->get_image_id() ) {
		$ids[] = $product->get_image_id();
	}

	return array_merge( $ids, $product->get_gallery_image_ids() );
}
