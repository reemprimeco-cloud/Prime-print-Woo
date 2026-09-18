<?php
/**
 * Phase 6 (continued) — one-time Arabic product import.
 *
 * Reem's call, 2026-09-05: Polylang + machine-translated product drafts she
 * corrects over time, rather than translating the catalogue by hand before
 * /ar/shop shows anything at all. This file is the one-time tool that does
 * that bulk creation — it is not part of the theme's ongoing behaviour, and
 * has nothing to do once every product has run through it once.
 *
 * The translations themselves were produced offline (not by any translation
 * API — this store has none configured, and Reem's call was specifically
 * against adding a third-party service for it) and shipped as a JSON file:
 * wp-content/uploads/prime-invoices/products_ar_import.json, keyed by the
 * English product's post ID, each entry: { slug, title, excerpt_html,
 * content_html }. Only SIMPLE products are covered by that file — the
 * catalogue's 26 variable products (size/colour options) need their child
 * variation posts duplicated too, a different and riskier operation
 * (mis-linking a variation loses real price/stock data), and were
 * deliberately left out of this pass. See the admin screen's own notice for
 * the exact list.
 *
 * Idempotent: any English product that already has an Arabic translation
 * (`pll_get_post( $id, 'ar' )`) is skipped, so this can be re-run safely if
 * it's interrupted partway, or run again later once the variable-product
 * question is settled.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the translations JSON was uploaded — see this file's own docblock.
 *
 * @return string Absolute path.
 */
function prime_ar_import_json_path() {
	$upload_dir = wp_upload_dir();
	return trailingslashit( $upload_dir['basedir'] ) . 'prime-invoices/products_ar_import.json';
}

/**
 * The one-time import screen, under Tools.
 */
function prime_register_ar_import_page() {
	add_management_page(
		__( 'Import Arabic Products', 'prime-printing' ),
		__( 'Import Arabic Products', 'prime-printing' ),
		'manage_options',
		'prime-ar-import',
		'prime_render_ar_import_page'
	);
}
add_action( 'admin_menu', 'prime_register_ar_import_page' );

/**
 * Duplicate one English product into its Arabic translation.
 *
 * A generic post+meta+taxonomy clone (the same shape WooCommerce's own core
 * "Duplicate" admin action uses) rather than reconstructing the product
 * through WC_Product setters — that keeps this correct for every simple
 * product's own mix of add-ons, custom pricing meta, and category/tag
 * assignments without this file needing to know what any of them are.
 *
 * @param int   $source_id English product's post ID.
 * @param array $translated { title, excerpt_html, content_html }.
 * @return int|WP_Error New post ID, or an error.
 */
function prime_create_arabic_product_translation( $source_id, array $translated ) {
	$source = get_post( $source_id );

	if ( ! $source || 'product' !== $source->post_type ) {
		return new WP_Error( 'prime_ar_import_missing', "Source product {$source_id} not found." );
	}

	$new_id = wp_insert_post(
		array(
			'post_type'    => 'product',
			'post_status'  => $source->post_status,
			'post_title'   => wp_strip_all_tags( $translated['title'] ),
			'post_content' => $translated['content_html'],
			'post_excerpt' => $translated['excerpt_html'],
			'post_author'  => $source->post_author,
			'menu_order'   => $source->menu_order,
			'ping_status'  => $source->ping_status,
			'comment_status' => $source->comment_status,
		),
		true
	);

	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	// Every piece of postmeta the English product has — pricing, SKU-adjacent
	// fields, stock, dimensions, gallery, this theme's own add-on/pricing
	// meta — except the handful that must not be duplicated verbatim.
	$skip_meta = array( '_sku', '_edit_lock', '_edit_last', '_thumbnail_id' );

	foreach ( get_post_meta( $source_id ) as $key => $values ) {
		if ( in_array( $key, $skip_meta, true ) ) {
			continue;
		}

		foreach ( $values as $value ) {
			// get_post_meta() with no single key returns every value already
			// serialized-decoded to a string; add_post_meta() re-serializes,
			// so a maybe_unserialize() round-trip keeps arrays (e.g.
			// _product_attributes) intact instead of doubly-encoding them.
			add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
		}
	}

	// The featured image is a plain attachment ID — copied on its own since
	// it was excluded above (wp_insert_post() does not set it for you).
	$thumbnail_id = get_post_thumbnail_id( $source_id );
	if ( $thumbnail_id ) {
		set_post_thumbnail( $new_id, $thumbnail_id );
	}

	// Every taxonomy the product uses (category, tag, shipping class, product
	// type, and any global attribute taxonomies) — except `language` and
	// `post_translations`, which are Polylang's own and are set explicitly
	// below instead of copied.
	foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
		if ( in_array( $taxonomy, array( 'language', 'post_translations' ), true ) ) {
			continue;
		}

		$term_ids = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( ! is_wp_error( $term_ids ) && $term_ids ) {
			wp_set_object_terms( $new_id, $term_ids, $taxonomy );
		}
	}

	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $new_id, 'ar' );
		pll_save_post_translations(
			array(
				'en' => $source_id,
				'ar' => $new_id,
			)
		);
	}

	return $new_id;
}

/**
 * Run the whole batch, and hand back a per-product report.
 *
 * @return array{created: array, skipped: array, failed: array}
 */
function prime_run_ar_product_import() {
	$path = prime_ar_import_json_path();

	if ( ! is_readable( $path ) ) {
		return array(
			'created' => array(),
			'skipped' => array(),
			'failed'  => array( 0 => 'Translations file not found at ' . $path ),
		);
	}

	$data = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

	if ( ! is_array( $data ) ) {
		return array(
			'created' => array(),
			'skipped' => array(),
			'failed'  => array( 0 => 'Translations file could not be parsed as JSON.' ),
		);
	}

	$created = array();
	$skipped = array();
	$failed  = array();

	foreach ( $data as $source_id => $translated ) {
		$source_id = (int) $source_id;

		if ( function_exists( 'pll_get_post' ) && pll_get_post( $source_id, 'ar' ) ) {
			$skipped[ $source_id ] = get_the_title( $source_id );
			continue;
		}

		$result = prime_create_arabic_product_translation( $source_id, $translated );

		if ( is_wp_error( $result ) ) {
			$failed[ $source_id ] = $result->get_error_message();
			continue;
		}

		$created[ $source_id ] = $translated['title'];
	}

	return array(
		'created' => $created,
		'skipped' => $skipped,
		'failed'  => $failed,
	);
}

/**
 * The admin screen: one button, then a report.
 */
function prime_render_ar_import_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$report = null;

	if (
		isset( $_POST['prime_ar_import_nonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_ar_import_nonce'] ) ), 'prime_run_ar_import' )
	) {
		$report = prime_run_ar_product_import();
	}

	$variable_ids = array( 5681, 5146, 5056, 4490, 4403, 4396, 4390, 4382, 3964, 3886, 3786, 3698, 3501, 2906, 2831, 2611, 2155, 2143, 2077, 2076, 1624, 1565, 1449, 1447, 645, 622 );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import Arabic Products', 'prime-printing' ); ?></h1>

		<p>
			<?php esc_html_e( 'Creates the Arabic translation of every simple product from wp-content/uploads/prime-invoices/products_ar_import.json. Safe to run more than once — any product that already has an Arabic translation is left alone.', 'prime-printing' ); ?>
		</p>

		<div class="notice notice-warning inline"><p>
			<?php
			printf(
				/* translators: %d: number of variable products excluded from this import. */
				esc_html__( '%d variable products (with size/colour options) are NOT covered — their variations need duplicating separately. Product IDs: ', 'prime-printing' ),
				count( $variable_ids )
			);
			echo esc_html( implode( ', ', $variable_ids ) );
			?>
		</p></div>

		<?php if ( $report ) : ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: created count, 2: skipped count, 3: failed count. */
					esc_html__( 'Created %1$d, skipped %2$d (already translated), failed %3$d.', 'prime-printing' ),
					count( $report['created'] ),
					count( $report['skipped'] ),
					count( $report['failed'] )
				);
				?>
			</p></div>

			<?php if ( $report['failed'] ) : ?>
				<h2><?php esc_html_e( 'Failed', 'prime-printing' ); ?></h2>
				<ul>
					<?php foreach ( $report['failed'] as $id => $msg ) : ?>
						<li><?php echo esc_html( "#{$id}: {$msg}" ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Created', 'prime-printing' ); ?></h2>
			<ul>
				<?php foreach ( $report['created'] as $id => $title ) : ?>
					<li><?php echo esc_html( "#{$id} → {$title}" ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'prime_run_ar_import', 'prime_ar_import_nonce' ); ?>
			<?php submit_button( __( 'Run import', 'prime-printing' ) ); ?>
		</form>
	</div>
	<?php
}
