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
 * These cannot be merged into anything. The screen lists them, and step 3
 * drafts them with the duplicates (Reem, 2026-10-01: the English catalogue is
 * the only one), so none stays on sale under an Arabic title in English.
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

	// Arabic-only products go too. Once products stop being translated they
	// would show on the English shop under their Arabic titles; Reem's call,
	// 2026-10-01: the English catalogue is the original and the only one.
	// Drafted with the rest, so "Undo the switch" brings them back as well.
	$duplicates = array_merge( $duplicates, prime_find_orphan_arabic_products() );

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
 * Retired Arabic posts whose text no live product carries.
 *
 * Polylang had no English partner on file for these (an Arabic product made by
 * hand, or one whose link was lost), so step 2 had nothing to copy them onto:
 * their Arabic URL now finds only the draft. Reem hit this on PP Stickers,
 * 2026-10-01 — the Arabic page showed the old copy, its own price, and no
 * calculator. Each one is linked by hand below.
 *
 * @return int[]
 */
function prime_find_unlinked_retired() {
	$log = get_option( PRIME_RETIRED_OPTION, array() );
	$ids = isset( $log['ids'] ) ? array_map( 'intval', (array) $log['ids'] ) : array();

	return array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				$slug = (string) get_post_field( 'post_name', $id );

				if ( '' === $slug ) {
					return false;
				}

				$carrier = get_posts(
					array(
						'post_type'   => 'product',
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'exclude'     => array( $id ),
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen only.
						'meta_query'  => array( array( 'key' => PRIME_AR_SLUG, 'value' => prime_slug_spellings( $slug ), 'compare' => 'IN' ) ),
					)
				);

				return ! $carrier;
			}
		)
	);
}

/**
 * A likely English product for a retired Arabic post: a published product with
 * the same main image (the import and hand-made copies reused it).
 *
 * @param int   $arabic_id Retired Arabic post.
 * @param int[] $retired   All retired IDs, never suggested.
 * @return int 0 when there is no clear match.
 */
function prime_suggest_english_for( $arabic_id, $retired ) {
	$thumb = (int) get_post_meta( $arabic_id, '_thumbnail_id', true );

	if ( ! $thumb ) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'numberposts'  => 3,
			'fields'       => 'ids',
			'post__not_in' => array_merge( array( $arabic_id ), $retired ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen only.
			'meta_query'   => array( array( 'key' => '_thumbnail_id', 'value' => $thumb ) ),
		)
	);

	return 1 === count( $ids ) ? (int) $ids[0] : 0;
}

/**
 * The product on sale that a retired copy was folded into: the one carrying
 * its slug as the Arabic slug, else the one sharing its main image.
 *
 * @param int   $copy_id Retired copy.
 * @param int[] $retired All retired IDs.
 * @return int 0 when unknown.
 */
function prime_live_product_for_copy( $copy_id, $retired ) {
	$slug = (string) get_post_field( 'post_name', $copy_id );

	if ( '' !== $slug ) {
		$found = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin action.
				'meta_query'  => array( array( 'key' => PRIME_AR_SLUG, 'value' => prime_slug_spellings( $slug ), 'compare' => 'IN' ) ),
			)
		);

		if ( $found ) {
			return (int) $found[0];
		}
	}

	return prime_suggest_english_for( $copy_id, $retired );
}

/**
 * Does this text contain Arabic letters?
 *
 * @param string $text Text.
 * @return bool
 */
function prime_has_arabic( $text ) {
	return (bool) preg_match( '/\p{Arabic}/u', (string) $text );
}

/**
 * Undo what step 3 got backwards where Polylang had a pair filed the wrong way
 * round — the English original recorded as "Arabic", the Arabic copy as
 * "English". Step 3 then drafted the original and kept the copy on sale, with
 * its Arabic title showing in English. PP Stickers (#235, the product its
 * calculator is tied to) was one, 2026-10-01.
 *
 * For each such product on sale: the original is published again under its
 * own slug, with the Arabic text (already copied onto the wrong post) moved to
 * it, and the copy goes back to Draft. Also republishes any drafted product
 * whose title is plainly English and that nothing else on sale replaces.
 *
 * @return array{swapped: string[], restored: string[]}
 */
function prime_repair_swapped_products() {
	global $wpdb;

	$log     = get_option( PRIME_RETIRED_OPTION, array() );
	$retired = isset( $log['ids'] ) ? array_map( 'intval', (array) $log['ids'] ) : array();
	$done    = array( 'swapped' => array(), 'restored' => array() );

	foreach ( $retired as $original_id ) {
		$original = get_post( $original_id );

		if ( ! $original || 'product' !== $original->post_type || 'publish' === $original->post_status || prime_has_arabic( $original->post_title ) ) {
			continue; // Only an English-titled post can be a wrongly drafted original.
		}

		$copy_id = prime_live_product_for_copy( $original_id, $retired );
		$copy    = $copy_id ? get_post( $copy_id ) : null;

		if ( $copy && ! prime_has_arabic( $copy->post_title ) ) {
			continue; // Its live partner is English already: a real duplicate.
		}

		// The Arabic text of the pair: the copy's own fields.
		if ( $copy ) {
			update_post_meta( $original_id, PRIME_AR_TITLE, $copy->post_title );
			update_post_meta( $original_id, PRIME_AR_EXCERPT, $copy->post_excerpt );
			update_post_meta( $original_id, PRIME_AR_CONTENT, $copy->post_content );
			update_post_meta( $original_id, PRIME_AR_SLUG, $copy->post_name );

			foreach ( array( PRIME_AR_TITLE, PRIME_AR_EXCERPT, PRIME_AR_CONTENT, PRIME_AR_SLUG ) as $key ) {
				delete_post_meta( $copy_id, $key );
			}

			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $copy_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $copy_id );
			$retired[] = $copy_id;
		}

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $original_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $original_id );
		$retired = array_values( array_diff( $retired, array( $original_id ) ) );

		$label = '#' . $original_id . ' ' . $original->post_title;

		if ( $copy ) {
			$done['swapped'][] = $label . '  (copy #' . $copy_id . ' set to Draft)';
		} else {
			$done['restored'][] = $label;
		}
	}

	$log['ids'] = array_values( array_unique( $retired ) );
	update_option( PRIME_RETIRED_OPTION, $log );

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	return $done;
}

/**
 * Turn round a product on sale whose own fields are Arabic and whose "Arabic"
 * meta is English — what is left of a backwards pair once its English
 * original has been deleted (step 6 run before step 4). The English text
 * becomes the product's own and the Arabic goes to the Arabic box, slug too.
 *
 * @return string[] One line per product turned round.
 */
function prime_flip_backwards_products() {
	global $wpdb;

	$done = array();
	$ids  = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => array( 'publish', 'private', 'pending' ),
			'numberposts' => -1,
			'fields'      => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin action.
			'meta_query'  => array( array( 'key' => PRIME_AR_TITLE, 'compare' => 'EXISTS' ) ),
		)
	);

	foreach ( $ids as $id ) {
		$post     = get_post( $id );
		$ar_title = (string) get_post_meta( $id, PRIME_AR_TITLE, true );

		if ( ! $post || ! prime_has_arabic( $post->post_title ) || '' === $ar_title || prime_has_arabic( $ar_title ) ) {
			continue;
		}

		$was     = $post->post_title;
		$en_slug = sanitize_title( rawurldecode( (string) get_post_meta( $id, PRIME_AR_SLUG, true ) ) );
		$fields  = array(
			'post_title'   => $ar_title,
			'post_excerpt' => (string) get_post_meta( $id, PRIME_AR_EXCERPT, true ),
			'post_content' => (string) get_post_meta( $id, PRIME_AR_CONTENT, true ),
		);

		// Only take the English slug when no other post holds it.
		if ( '' !== $en_slug && ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product' AND ID <> %d LIMIT 1", $en_slug, $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$fields['post_name'] = $en_slug;
		}

		update_post_meta( $id, PRIME_AR_TITLE, $post->post_title );
		update_post_meta( $id, PRIME_AR_EXCERPT, $post->post_excerpt );
		update_post_meta( $id, PRIME_AR_CONTENT, $post->post_content );
		update_post_meta( $id, PRIME_AR_SLUG, $post->post_name );

		$wpdb->update( $wpdb->posts, $fields, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );

		$done[] = '#' . $id . ' ' . $ar_title . '  ⇄  ' . $was;
	}

	if ( $done && function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	return $done;
}

/**
 * Delete the retired Arabic copies for good — Reem, 2026-10-01: "نمسح العربي
 * كله و نعيد بناؤه من جديد". Any copy still unlinked is first linked to its
 * suggested English product, so its Arabic text and URL are not lost with it.
 * Orders keep their own line names and prices; only the link to the deleted
 * copy goes. Cannot be undone, so the switch's undo record is cleared too.
 *
 * @return array{deleted: int, linked: int}
 */
function prime_delete_retired_copies() {
	$log     = get_option( PRIME_RETIRED_OPTION, array() );
	$ids     = isset( $log['ids'] ) ? array_map( 'intval', (array) $log['ids'] ) : array();
	$linked  = 0;
	$deleted = 0;

	foreach ( prime_find_unlinked_retired() as $arabic_id ) {
		$english_id = prime_suggest_english_for( $arabic_id, $ids );

		if ( $english_id ) {
			prime_merge_one_product( $english_id, $arabic_id, false );
			++$linked;
		}
	}

	// A calculator is tied to its product's ID. When that product is one of the
	// copies about to go, pin the calculator onto the product now on sale
	// (Product data → General → "Price calculator"), or it would vanish.
	foreach ( array_keys( prime_calculator_choices() ) as $calculator_id ) {
		if ( in_array( $calculator_id, $ids, true ) ) {
			$carrier = prime_live_product_for_copy( $calculator_id, $ids );

			if ( $carrier ) {
				update_post_meta( $carrier, PRIME_CALCULATOR_META, $calculator_id );
			}
		}
	}

	foreach ( $ids as $id ) {
		if ( 'product' !== get_post_type( $id ) || 'publish' === get_post_status( $id ) ) {
			continue; // Gone already, or put back on sale by hand: leave it.
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( $product ) {
			$product->delete( true ); // Variations go with it.
		} else {
			wp_delete_post( $id, true );
		}

		++$deleted;
	}

	delete_option( PRIME_RETIRED_OPTION );

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	return array( 'deleted' => $deleted, 'linked' => $linked );
}

/**
 * Give every product that still has no Arabic title its Arabic text from the
 * translation file the September import used (prime_ar_import_json_path()).
 * A product that already has Arabic text is left exactly as it is.
 *
 * @return int|false Products filled, or false when the file is missing.
 */
function prime_fill_arabic_from_file() {
	$path = prime_ar_import_json_path();

	if ( ! is_readable( $path ) ) {
		return false;
	}

	$data   = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
	$filled = 0;

	foreach ( (array) $data as $product_id => $entry ) {
		$product_id = (int) $product_id;

		if ( ! is_array( $entry ) || 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			continue;
		}

		if ( '' !== trim( (string) get_post_meta( $product_id, PRIME_AR_TITLE, true ) ) ) {
			continue;
		}

		$title = trim( wp_strip_all_tags( (string) ( $entry['title'] ?? '' ) ) );

		if ( '' === $title ) {
			continue;
		}

		update_post_meta( $product_id, PRIME_AR_TITLE, $title );

		if ( ! empty( $entry['excerpt_html'] ) ) {
			update_post_meta( $product_id, PRIME_AR_EXCERPT, wp_kses_post( $entry['excerpt_html'] ) );
		}

		if ( ! empty( $entry['content_html'] ) ) {
			update_post_meta( $product_id, PRIME_AR_CONTENT, wp_kses_post( $entry['content_html'] ) );
		}

		// The Arabic URL, unless another product already answers to it.
		$slug = sanitize_title( (string) ( $entry['slug'] ?? '' ) );

		if ( '' !== $slug && ! get_post_meta( $product_id, PRIME_AR_SLUG, true ) ) {
			$taken = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'exclude'     => array( $product_id ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin action.
					'meta_query'  => array( array( 'key' => PRIME_AR_SLUG, 'value' => prime_slug_spellings( $slug ), 'compare' => 'IN' ) ),
				)
			);

			if ( ! $taken ) {
				update_post_meta( $product_id, PRIME_AR_SLUG, $slug );
			}
		}

		++$filled;
	}

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	return $filled;
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

	if ( 'link' === $action && isset( $_POST['prime_link_from'], $_POST['prime_link_to'] ) ) {
		$from    = absint( wp_unslash( $_POST['prime_link_from'] ) );
		$targets = (array) wp_unslash( $_POST['prime_link_to'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint below.
		$to      = isset( $targets[ $from ] ) ? absint( $targets[ $from ] ) : 0;

		if ( $from && $to && $from !== $to && 'product' === get_post_type( $to ) && 'publish' === get_post_status( $to ) ) {
			$result = prime_merge_one_product( $to, $from, false );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $to );
			}

			$report[] = sprintf( '#%d %s  ←  #%d %s — %s', $to, get_the_title( $to ), $from, get_the_title( $from ), $result['note'] );
		} else {
			$report[] = __( 'Nothing linked: enter the ID of a published English product.', 'prime-printing' );
		}
	}

	if ( 'repair' === $action ) {
		$result = prime_repair_swapped_products();
		$report[] = sprintf( 'Swapped back: %d   Republished: %d', count( $result['swapped'] ), count( $result['restored'] ) );
		$report   = array_merge( $report, $result['swapped'], $result['restored'] );
	}

	if ( 'flip' === $action ) {
		$flipped  = prime_flip_backwards_products();
		$report[] = sprintf( 'Turned round: %d', count( $flipped ) );
		$report   = array_merge( $report, $flipped );
	}

	if ( 'delete_copies' === $action ) {
		$result   = prime_delete_retired_copies();
		$report[] = sprintf(
			/* translators: 1: copies deleted, 2: copies linked first. */
			__( 'Deleted %1$d Arabic copies for good (%2$d of them were linked to their English product first).', 'prime-printing' ),
			$result['deleted'],
			$result['linked']
		);
	}

	if ( 'fill_arabic' === $action ) {
		$filled   = prime_fill_arabic_from_file();
		$report[] = false === $filled
			? __( 'The translation file was not found, so nothing was filled.', 'prime-printing' )
			/* translators: %d: products given Arabic text. */
			: sprintf( __( '%d products without Arabic text got it from the translation file.', 'prime-printing' ), $filled );
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
				<tr><th><?php esc_html_e( 'Arabic-only products (set to Draft in step 3)', 'prime-printing' ); ?></th><td><?php echo (int) count( $orphans ); ?></td></tr>
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

			<?php if ( function_exists( 'prime_single_product_mode' ) && prime_single_product_mode() ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Start the Arabic side clean', 'prime-printing' ); ?></h2>
				<?php if ( get_option( PRIME_RETIRED_OPTION ) ) : ?>
					<p>
						<button class="button button-primary" name="prime_merge_action" value="repair"><?php esc_html_e( '4. Repair products the switch got backwards', 'prime-printing' ); ?></button>
						<span class="description"><?php esc_html_e( 'Puts the English original back on sale where its Arabic copy was kept instead. Safe to run again.', 'prime-printing' ); ?></span>
					</p>
				<?php endif; ?>
				<p>
					<button class="button button-primary" name="prime_merge_action" value="flip"><?php esc_html_e( 'Fix products showing Arabic in English', 'prime-printing' ); ?></button>
					<span class="description"><?php esc_html_e( 'Swaps the English and Arabic text where they are the wrong way round. Safe to run again.', 'prime-printing' ); ?></span>
				</p>
				<p>
					<button class="button" name="prime_merge_action" value="fill_arabic"><?php esc_html_e( '5. Fill missing Arabic text from the translation file', 'prime-printing' ); ?></button>
					<span class="description"><?php esc_html_e( 'Only products with no Arabic title yet. Safe to run again.', 'prime-printing' ); ?></span>
				</p>
				<?php if ( get_option( PRIME_RETIRED_OPTION ) ) : ?>
					<p>
						<button class="button button-link-delete" name="prime_merge_action" value="delete_copies" onclick="return confirm('حذف كل النسخ العربية القديمة نهائياً؟ لا يمكن التراجع.');"><?php esc_html_e( '6. Delete the old Arabic copies for good', 'prime-printing' ); ?></button>
						<span class="description"><?php esc_html_e( 'The drafts made in step 3. Your products stay. Cannot be undone.', 'prime-printing' ); ?></span>
					</p>
				<?php endif; ?>
			<?php endif; ?>

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

		<?php
		$retired_log = get_option( PRIME_RETIRED_OPTION, array() );
		$retired_ids = isset( $retired_log['ids'] ) ? array_map( 'intval', (array) $retired_log['ids'] ) : array();
		$unlinked    = $retired_ids ? prime_find_unlinked_retired() : array();
		?>
		<?php
		// Products on sale whose own (English) title is Arabic: the English
		// original of a backwards pair was deleted before it could be repaired,
		// so there is no English text left to swap in. Fixed by hand.
		$arabic_titled = array_filter(
			get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ),
			static fn( $id ) => prime_has_arabic( get_post_field( 'post_title', $id ) )
		);
		?>
		<?php if ( $arabic_titled ) : ?>
			<h2><?php esc_html_e( 'Products with an Arabic title on the English site', 'prime-printing' ); ?></h2>
			<p><?php esc_html_e( 'For each: open it, cut the Arabic title into the Arabic box below the editor, type the English title at the top, set the permalink to an English slug, check Product data → General → Price calculator if it is a sticker, and Update.', 'prime-printing' ); ?></p>
			<ul>
				<?php foreach ( $arabic_titled as $product_id ) : ?>
					<li><a href="<?php echo esc_url( (string) get_edit_post_link( $product_id ) ); ?>">#<?php echo (int) $product_id; ?> <?php echo esc_html( get_post_field( 'post_title', $product_id ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $unlinked ) : ?>
			<h2><?php esc_html_e( 'Arabic products not linked to an English product', 'prime-printing' ); ?></h2>
			<p><?php esc_html_e( 'These Arabic copies had no English partner on file, so their Arabic text was not copied anywhere and their Arabic link shows nothing to customers. Enter the English product each one belongs to (its ID is in the address bar when you edit it, post=…) and press Link: the Arabic title, description and link move onto that product. A suggestion is filled in when exactly one product shares the same main image.', 'prime-printing' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'prime_merge' ); ?>
				<input type="hidden" name="prime_merge_action" value="link">
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th><?php esc_html_e( 'Arabic copy (Draft)', 'prime-printing' ); ?></th><th><?php esc_html_e( 'English product ID', 'prime-printing' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $unlinked as $arabic_id ) : ?>
							<?php $suggested = prime_suggest_english_for( $arabic_id, $retired_ids ); ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $arabic_id ) ); ?>">#<?php echo (int) $arabic_id; ?> <?php echo esc_html( get_the_title( $arabic_id ) ); ?></a></td>
								<td>
									<input type="number" min="1" name="prime_link_to[<?php echo (int) $arabic_id; ?>]" value="<?php echo $suggested ? (int) $suggested : ''; ?>" style="width:7em">
									<?php if ( $suggested ) : ?>
										<span class="description"><?php echo esc_html( get_the_title( $suggested ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><button class="button button-primary" name="prime_link_from" value="<?php echo (int) $arabic_id; ?>"><?php esc_html_e( 'Link', 'prime-printing' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>
		<?php endif; ?>

		<?php if ( $orphans ) : ?>
			<h2><?php esc_html_e( 'Arabic-only products', 'prime-printing' ); ?></h2>
			<p><?php esc_html_e( 'These have no English product to merge into. Step 3 sets them to Draft along with the duplicates, so only the English catalogue stays on sale; "Undo the switch" publishes them again. To keep one, give it an English product first.', 'prime-printing' ); ?></p>
			<ul>
				<?php foreach ( $orphans as $orphan_id ) : ?>
					<li><a href="<?php echo esc_url( (string) get_edit_post_link( $orphan_id ) ); ?>">#<?php echo (int) $orphan_id; ?> <?php echo esc_html( get_the_title( $orphan_id ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
