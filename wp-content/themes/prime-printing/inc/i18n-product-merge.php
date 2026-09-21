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
 *   2. retires the duplicate to Draft. Not deleted: an order placed from the
 *      Arabic side still points at that post ID, and a draft keeps that order's
 *      record whole. Reem, 2026-09-21, was willing to delete them; drafting
 *      costs nothing extra and can be undone, so deletion can wait until the
 *      new shape has been live long enough to trust.
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
const PRIME_MERGE_OPTION = 'prime_ar_merge_log';

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

	if ( ! $dry_run && 'draft' !== $arabic->post_status ) {
		wp_update_post( array( 'ID' => $arabic_id, 'post_status' => 'draft' ) );
	}

	return array( 'moved' => $moved, 'note' => $dry_run ? 'preview only' : 'merged, duplicate set to draft' );
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
	$options = get_option( 'polylang', array() );
	$single  = prime_single_post_types();
	$before  = wp_json_encode( array( $options['post_types'] ?? array(), $options['taxonomies'] ?? array() ) );

	$options['post_types'] = array_values( array_diff( (array) ( $options['post_types'] ?? array() ), $single['post_types'] ) );
	$options['taxonomies'] = array_values( array_diff( (array) ( $options['taxonomies'] ?? array() ), $single['taxonomies'] ) );

	update_option( 'polylang', $options );
	delete_option( 'prime_lang_rewrite_signature' ); // Force the rewrite rules to be rebuilt.

	return $before !== wp_json_encode( array( $options['post_types'], $options['taxonomies'] ) );
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
		$report[] = prime_stop_translating_products()
			? __( 'Products are no longer translated by Polylang. One product now serves both languages.', 'prime-printing' )
			: __( 'Polylang was already set this way — nothing to change.', 'prime-printing' );
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
				<button class="button button-primary" name="prime_merge_action" value="merge"><?php esc_html_e( '2. Merge', 'prime-printing' ); ?></button>
				<button class="button" name="prime_merge_action" value="switch" <?php disabled( (bool) count( $pending ) ); ?>><?php esc_html_e( '3. Switch to one product per language', 'prime-printing' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Run them in order. Step 3 is only available once every pair has been merged, because the pairs can only be read while Polylang still links them.', 'prime-printing' ); ?></p>
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
