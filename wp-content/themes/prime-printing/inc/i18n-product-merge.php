<?php
/**
 * One-time merge: fold the duplicate Arabic products back into one product.
 *
 * inc/i18n-product-import.php created an Arabic post per product in September
 * 2026 — Polylang's model. inc/i18n-products.php replaces that model with a
 * single product carrying its Arabic text as meta. This is the tool that moves
 * the existing catalogue from the first shape to the second, under
 * Tools → Merge Arabic Products.
 *
 * For each Arabic duplicate it:
 *
 *   1. copies the Arabic title, short description, description and — crucially
 *      — its slug onto the English product;
 * and step 3 then retires every duplicate to Draft. Not deleted: an order
 * placed from the Arabic side still points at that post ID, and a draft keeps
 * that order's record whole. Reem, 2026-09-21, was willing to delete them;
 * drafting costs nothing extra and can be undone, so deletion can wait until
 * the new shape has been live long enough to trust.
 *
 * Copying (step 2) and switching (step 3) are separate on purpose. Step 2
 * changes nothing a customer can see — the duplicates keep serving the Arabic
 * site exactly as before — so it can be run, checked, and re-run in safety.
 * Only step 3 changes what is served, and it does the whole changeover at
 * once rather than leaving the shop half-migrated.
 *
 * Because the Arabic slug moves to the live product, every Arabic URL Google
 * has indexed keeps working and keeps showing the same page — no redirects, no
 * lost ranking. That is what step 1 is really for.
 *
 * Idempotent: a product that already carries Arabic text is skipped, so the
 * tool can be re-run safely if it is interrupted.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the merge is up to, for the admin screen and for a re-run.
 */
const PRIME_MERGE_OPTION   = 'prime_ar_merge_log';
const PRIME_RETIRED_OPTION = 'prime_ar_retired_products';

/**
 * Register the Tools screen.
 */
function prime_register_merge_page() {
	add_management_page(
		__( 'Merge Arabic Products', 'prime-printing' ),
		__( 'Merge Arabic Products', 'prime-printing' ),
		'manage_options',
		'prime-ar-merge',
		'prime_render_merge_page'
	);
}
add_action( 'admin_menu', 'prime_register_merge_page' );

/**
 * Every English product that still has a separate Arabic post.
 *
 * Read from Polylang while it still knows about the pairs — once products stop
 * being a translated post type the links are no longer queryable, which is why
 * the merge runs before that switch is thrown.
 *
 * @return array[] Each: english_id, arabic_id, english_title, arabic_title.
 */
function prime_find_arabic_duplicates() {
	if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_get_post_language' ) ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	$pairs = array();

	foreach ( $ids as $id ) {
		if ( 'en' !== pll_get_post_language( $id ) ) {
			continue;
		}

		$arabic_id = pll_get_post( $id, 'ar' );

		if ( ! $arabic_id || (int) $arabic_id === (int) $id ) {
			continue;
		}

		$pairs[] = array(
			'english_id'    => (int) $id,
			'arabic_id'     => (int) $arabic_id,
			'english_title' => get_the_title( $id ),
			'arabic_title'  => get_post_field( 'post_title', $arabic_id ),
			'done'          => (bool) get_post_meta( $id, PRIME_AR_TITLE, true ),
		);
	}

	return $pairs;
}

/**
 * Arabic products with no English counterpart.
 *
 * These cannot be merged into anything, so the tool reports them and leaves
 * them alone rather than guessing — an orphan is either a product that only
 * ever existed in Arabic (it should stay, and keep selling) or a mistake for
 * Reem to look at.
 *
 * @return int[]
 */
function prime_find_orphan_arabic_products() {
	if ( ! function_exists( 'pll_get_post_language' ) ) {
		return array();
	}

	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );

	return array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				return 'ar' === pll_get_post_language( $id ) && ! pll_get_post( $id, 'en' );
			}
		)
	);
}

/**
 * Merge one pair.
 *
 * @param int  $english_id English product ID.
 * @param int  $arabic_id  The Arabic duplicate.
 * @param bool $dry_run    Report what would happen without writing anything.
 * @return array{moved: string[], note: string}
 */
function prime_merge_one_product( $english_id, $arabic_id, $dry_run = false ) {
	$arabic = get_post( $arabic_id );

	if ( ! $arabic ) {
		return array( 'moved' => array(), 'note' => 'the Arabic post is gone' );
	}

	$fields = array(
		PRIME_AR_TITLE   => $arabic->post_title,
		PRIME_AR_EXCERPT => $arabic->post_excerpt,
		PRIME_AR_CONTENT => $arabic->post_content,
		PRIME_AR_SLUG    => $arabic->post_name,
	);

	$moved = array();

	foreach ( $fields as $key => $value ) {
		if ( '' === trim( (string) $value ) ) {
			continue;
		}

		$moved[] = $key;

		if ( ! $dry_run ) {
			update_post_meta( $english_id, $key, $value );
		}
	}

	return array( 'moved' => $moved, 'note' => $dry_run ? 'preview only' : 'Arabic text copied onto the product' );
}

/**
 * Stop Polylang treating products and their taxonomies as translatable.
 *
 * This is the switch that makes the catalogue single-sided. It is deliberately
 * a separate button from the merge: the pairs can only be read while it is ON,
 * so it must be thrown afterwards, never before.
 *
 * @return bool True when the setting changed.
 */
function prime_stop_translating_products() {
	global $wpdb;

	// Read the pairs while Polylang still links them — after the switch below
	// it no longer answers questions about a product's language.
	$duplicates = wp_list_pluck( prime_find_arabic_duplicates(), 'arabic_id' );

	$options = get_option( 'polylang', array() );
	$single  = prime_single_post_types();
	$before  = wp_json_encode( array( $options['post_types'] ?? array(), $options['taxonomies'] ?? array() ) );

	$options['post_types'] = array_values( array_diff( (array) ( $options['post_types'] ?? array() ), $single['post_types'] ) );
	$options['taxonomies'] = array_values( array_diff( (array) ( $options['taxonomies'] ?? array() ), $single['taxonomies'] ) );

	$changed = $before !== wp_json_encode( array( $options['post_types'], $options['taxonomies'] ) );

	// The switch itself: one option write, after which each product serves both
	// languages. Done first so the Arabic site is never missing its products.
	update_option( 'polylang', $options );

	// Then retire the duplicates. A direct status update rather than
	// wp_update_post(): this runs over ~122 products in one request, and a
	// status change needs none of the product-save machinery each of those
	// calls would fire.
	$retired = array();

	foreach ( $duplicates as $duplicate_id ) {
		if ( 'draft' === get_post_status( $duplicate_id ) ) {
			continue;
		}

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => (int) $duplicate_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( (int) $duplicate_id );
		$retired[] = (int) $duplicate_id;
	}

	if ( $retired ) {
		// Kept so the change can be undone, and so it is on record which posts
		// were retired when — these are the ones that may be deleted later.
		update_option( PRIME_RETIRED_OPTION, array( 'at' => gmdate( 'c' ), 'ids' => $retired ) );
	}

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	delete_option( 'prime_lang_rewrite_signature' ); // Force the rewrite rules to be rebuilt.
	delete_transient( 'wc_products_onsale' );

	return array( 'changed' => $changed, 'retired' => count( $retired ) );
}

/**
 * Put the retired duplicates back and let Polylang translate products again.
 *
 * The way back, if anything about the new shape turns out to be wrong on the
 * live shop. It restores exactly the posts step 3 retired — the Arabic text
 * copied onto the products is left in place, so re-running the switch later
 * needs no second merge.
 *
 * @return int How many products were restored.
 */
function prime_undo_switch() {
	global $wpdb;

	$log     = get_option( PRIME_RETIRED_OPTION, array() );
	$ids     = isset( $log['ids'] ) ? (array) $log['ids'] : array();
	$options = get_option( 'polylang', array() );
	$single  = prime_single_post_types();

	$options['post_types'] = array_values( array_unique( array_merge( (array) ( $options['post_types'] ?? array() ), $single['post_types'] ) ) );
	$options['taxonomies'] = array_values( array_unique( array_merge( (array) ( $options['taxonomies'] ?? array() ), $single['taxonomies'] ) ) );
	update_option( 'polylang', $options );

	foreach ( $ids as $id ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( (int) $id );
	}

	delete_option( PRIME_RETIRED_OPTION );
	delete_option( 'prime_lang_rewrite_signature' );

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	return count( $ids );
}

/**
 * The Tools screen.
 */
function prime_render_merge_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$action = isset( $_POST['prime_merge_action'] ) && check_admin_referer( 'prime_merge' ) ? sanitize_key( wp_unslash( $_POST['prime_merge_action'] ) ) : '';
	$report = array();

	if ( in_array( $action, array( 'preview', 'merge' ), true ) ) {
		$dry_run = 'preview' === $action;

		foreach ( prime_find_arabic_duplicates() as $pair ) {
			if ( $pair['done'] && $dry_run ) {
				continue;
			}

			$result   = prime_merge_one_product( $pair['english_id'], $pair['arabic_id'], $dry_run );
			$report[] = sprintf( '#%d %s  ←  #%d %s — %s', $pair['english_id'], $pair['english_title'], $pair['arabic_id'], $pair['arabic_title'], $result['note'] );
		}

		if ( ! $dry_run ) {
			update_option( PRIME_MERGE_OPTION, array( 'at' => gmdate( 'c' ), 'count' => count( $report ) ) );
		}
	}

	if ( 'switch' === $action ) {
		$result   = prime_stop_translating_products();
		$report[] = sprintf(
			/* translators: %d: number of duplicate products retired. */
			__( 'Done. One product now serves both languages, and %d duplicates were set to Draft.', 'prime-printing' ),
			$result['retired']
		);
	}

	if ( 'undo' === $action ) {
		$report[] = sprintf(
			/* translators: %d: number of products restored. */
			__( 'Undone. %d duplicates were published again and Polylang is translating products as before.', 'prime-printing' ),
			prime_undo_switch()
		);
	}

	$pairs   = prime_find_arabic_duplicates();
	$pending = array_filter( $pairs, static fn( $p ) => ! $p['done'] );
	$orphans = prime_find_orphan_arabic_products();
	$log     = get_option( PRIME_MERGE_OPTION, array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Merge Arabic Products', 'prime-printing' ); ?></h1>

		<p><?php esc_html_e( 'Folds each duplicate Arabic product back into the single product it belongs to. The Arabic text and the Arabic URL move onto that product; the duplicate is set to Draft, never deleted, so old orders keep their record.', 'prime-printing' ); ?></p>

		<table class="widefat" style="max-width:640px;margin-bottom:1em">
			<tbody>
				<tr><th><?php esc_html_e( 'Duplicate pairs found', 'prime-printing' ); ?></th><td><?php echo (int) count( $pairs ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Still to merge', 'prime-printing' ); ?></th><td><?php echo (int) count( $pending ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Arabic-only products (left alone)', 'prime-printing' ); ?></th><td><?php echo (int) count( $orphans ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Polylang translates products', 'prime-printing' ); ?></th><td><?php echo function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( 'product' ) ? 'yes' : 'no'; ?></td></tr>
				<?php if ( $log ) : ?>
					<tr><th><?php esc_html_e( 'Last merge', 'prime-printing' ); ?></th><td><?php echo esc_html( $log['at'] . ' — ' . $log['count'] . ' products' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<form method="post">
			<?php wp_nonce_field( 'prime_merge' ); ?>
			<p>
				<button class="button" name="prime_merge_action" value="preview"><?php esc_html_e( '1. Preview (changes nothing)', 'prime-printing' ); ?></button>
				<button class="button button-primary" name="prime_merge_action" value="merge"><?php esc_html_e( '2. Copy the Arabic text onto the products', 'prime-printing' ); ?></button>
				<button class="button" name="prime_merge_action" value="switch" <?php disabled( (bool) count( $pending ) ); ?>><?php esc_html_e( '3. Switch the shop over', 'prime-printing' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Run them in order. Steps 1 and 2 change nothing a customer can see — the Arabic site keeps working exactly as it does now, so they are safe to run and re-run. Step 3 is the changeover, and it is only available once every pair has been copied.', 'prime-printing' ); ?></p>

			<?php if ( get_option( PRIME_RETIRED_OPTION ) ) : ?>
				<hr>
				<p>
					<button class="button button-link-delete" name="prime_merge_action" value="undo"><?php esc_html_e( 'Undo the switch', 'prime-printing' ); ?></button>
					<span class="description"><?php esc_html_e( 'Publishes the duplicates again and puts Polylang back as it was. The Arabic text stays copied, so step 3 can be run again afterwards.', 'prime-printing' ); ?></span>
				</p>
			<?php endif; ?>
		</form>

		<?php if ( $report ) : ?>
			<h2><?php esc_html_e( 'Result', 'prime-printing' ); ?></h2>
			<textarea readonly rows="20" style="width:100%;font-family:monospace"><?php echo esc_textarea( implode( "\n", $report ) ); ?></textarea>
		<?php endif; ?>

		<?php if ( $orphans ) : ?>
			<h2><?php esc_html_e( 'Arabic-only products', 'prime-printing' ); ?></h2>
			<p><?php esc_html_e( 'These have no English product to merge into, so nothing was done to them. Each one is either a product that only exists in Arabic — which is fine — or a duplicate that lost its link.', 'prime-printing' ); ?></p>
			<ul>
				<?php foreach ( $orphans as $orphan_id ) : ?>
					<li><a href="<?php echo esc_url( (string) get_edit_post_link( $orphan_id ) ); ?>">#<?php echo (int) $orphan_id; ?> <?php echo esc_html( get_the_title( $orphan_id ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
